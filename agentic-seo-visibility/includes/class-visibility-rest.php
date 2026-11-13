<?php
/**
 * REST endpoints exposed BY the plugin TO Visibility's server.
 *
 * Visibility calls these to list/create/update/delete posts, manage
 * taxonomy, and upload media. All endpoints are authenticated with the
 * shared site token in `Authorization: Bearer <token>`.
 *
 * Routes are namespaced under /wp-json/visibility/v1/* . Surface intentionally
 * mirrors a subset of the standard WP REST API so callers map cleanly, but
 * the auth model is the shared token — never the WP user table.
 */

if (!defined('ABSPATH')) {
  exit;
}

class Visibility_REST {

  /** Statuses we'll honour on create/update. Anything else falls back to "draft". */
  const ALLOWED_STATUSES = ['draft', 'publish', 'pending', 'future', 'private'];

  public function __construct() {
    add_action('rest_api_init', [$this, 'register_routes']);
    // Force no-cache headers on every response under our namespace so a
    // page cache (LiteSpeed, WP Rocket, Cloudflare, etc.) can't serve a
    // stale unauthenticated body to a different caller.
    add_filter('rest_post_dispatch', [$this, 'no_cache_headers'], 10, 3);
  }

  public function no_cache_headers($response, $server, $request) {
    if (!$response instanceof WP_REST_Response) return $response;
    $route = $request->get_route();
    if (strpos($route, '/visibility/v1') !== 0) return $response;
    $response->header('Cache-Control', 'private, no-store, no-cache, must-revalidate, max-age=0');
    $response->header('Pragma', 'no-cache');
    $response->header('Expires', '0');
    return $response;
  }

  public function register_routes() {
    // Health / site info
    register_rest_route('visibility/v1', '/health', [
      'methods'             => 'GET',
      'callback'            => [$this, 'health'],
      'permission_callback' => [$this, 'authenticate'],
    ]);

    // What the site has: versions, theme, post types, SEO plugin, builders,
    // custom fields, caches, languages, WooCommerce, the owner's switches.
    register_rest_route('visibility/v1', '/capabilities', [
      'methods'             => 'GET',
      'callback'            => [$this, 'capabilities'],
      'permission_callback' => [$this, 'authenticate'],
    ]);

    // ─── Event receivers (server → plugin pushes) ──────────────────────
    //
    // The Visibility server pushes events here whenever something
    // happens that the plugin should react to NOW instead of waiting
    // on the 5-minute WP-Cron tick. All endpoints share the standard
    // bearer-token auth (the same site token used everywhere else).

    // Single-action push: server pushes a freshly approved request
    // with its full payload so the plugin can execute immediately.
    register_rest_route('visibility/v1', '/events/action-approved', [
      'methods'             => 'POST',
      'callback'            => [$this, 'event_action_approved'],
      'permission_callback' => [$this, 'authenticate'],
    ]);

    // Bulk catch-up: server (or anything else) tells the plugin to drain
    // every approved-but-not-yet-executed request from its inbox. Used
    // as a safety net — also called from the daily heartbeat.
    register_rest_route('visibility/v1', '/events/drain-inbox', [
      'methods'             => 'POST',
      'callback'            => [$this, 'event_drain_inbox'],
      'permission_callback' => [$this, 'authenticate'],
    ]);

    // Site-config nudge: server tells the plugin its allow_publish or
    // disabled flag changed. Plugin caches the new state so admin UI
    // (banners, badges) stays fresh without polling.
    register_rest_route('visibility/v1', '/events/site-config-changed', [
      'methods'             => 'POST',
      'callback'            => [$this, 'event_site_config_changed'],
      'permission_callback' => [$this, 'authenticate'],
    ]);

    // Posts
    register_rest_route('visibility/v1', '/posts', [
      [
        'methods'             => 'GET',
        'callback'            => [$this, 'list_posts'],
        'permission_callback' => [$this, 'authenticate'],
      ],
      [
        'methods'             => 'POST',
        'callback'            => [$this, 'create_post'],
        'permission_callback' => [$this, 'authenticate_write'],
      ],
    ]);
    register_rest_route('visibility/v1', '/posts/(?P<id>\d+)', [
      [
        'methods'             => 'GET',
        'callback'            => [$this, 'get_post'],
        'permission_callback' => [$this, 'authenticate'],
      ],
      [
        'methods'             => 'PATCH',
        'callback'            => [$this, 'update_post'],
        'permission_callback' => [$this, 'authenticate_write'],
      ],
      [
        'methods'             => 'DELETE',
        'callback'            => [$this, 'delete_post'],
        'permission_callback' => [$this, 'authenticate_write'],
      ],
    ]);

    // Categories
    register_rest_route('visibility/v1', '/categories', [
      [
        'methods'             => 'GET',
        'callback'            => $this->list_terms_factory('category'),
        'permission_callback' => [$this, 'authenticate'],
      ],
      [
        'methods'             => 'POST',
        'callback'            => $this->create_term_factory('category'),
        'permission_callback' => [$this, 'authenticate_write'],
      ],
    ]);

    // Tags
    register_rest_route('visibility/v1', '/tags', [
      [
        'methods'             => 'GET',
        'callback'            => $this->list_terms_factory('post_tag'),
        'permission_callback' => [$this, 'authenticate'],
      ],
      [
        'methods'             => 'POST',
        'callback'            => $this->create_term_factory('post_tag'),
        'permission_callback' => [$this, 'authenticate_write'],
      ],
    ]);

    // Media (list + upload-from-URL)
    register_rest_route('visibility/v1', '/media', [
      [
        'methods'             => 'GET',
        'callback'            => [$this, 'list_media'],
        'permission_callback' => [$this, 'authenticate'],
      ],
      [
        'methods'             => 'POST',
        'callback'            => [$this, 'upload_media_from_url'],
        'permission_callback' => [$this, 'authenticate_write'],
      ],
    ]);
  }

  /** Constant-time bearer-token check against the option stored at pairing. */
  public function authenticate($request) {
    $header = $request->get_header('authorization');
    if (!$header || stripos($header, 'Bearer ') !== 0) {
      return new WP_Error('visibility_no_auth', __('Missing bearer token.', 'agentic-seo-visibility'), ['status' => 401]);
    }
    $token  = trim(substr($header, 7));
    $stored = Visibility_Client::site_token();
    if ($stored === '' || !hash_equals($stored, $token)) {
      return new WP_Error('visibility_bad_token', __('Invalid site token.', 'agentic-seo-visibility'), ['status' => 401]);
    }
    return true;
  }

  /** Token check plus the owner's pause / publishing switches, for write routes. */
  public function authenticate_write($request) {
    $auth = $this->authenticate($request);
    if ($auth !== true) return $auth;
    return Visibility_Policy::check_rest($request);
  }

  // ── /health ─────────────────────────────────────────────────────────

  /** Server pushes a single freshly-approved action with its full
   *  payload. The plugin executes it via Visibility_Executor and
   *  reports the outcome back to the server. */
  public function event_action_approved(WP_REST_Request $request) {
    if (!class_exists('Visibility_Executor') || !class_exists('Visibility_Client')) {
      return new WP_Error('visibility_internals_missing', 'Plugin internals not loaded.', ['status' => 500]);
    }
    $body = $request->get_json_params();
    if (!is_array($body)) {
      return new WP_Error('visibility_bad_body', 'JSON body required.', ['status' => 400]);
    }
    $request_id  = isset($body['requestId']) ? sanitize_text_field((string) $body['requestId']) : '';
    $action_type = isset($body['actionType']) ? sanitize_text_field((string) $body['actionType']) : '';
    $payload     = isset($body['actionPayload']) && is_array($body['actionPayload']) ? $body['actionPayload'] : [];
    if ($request_id === '' || $action_type === '') {
      return new WP_Error('visibility_bad_body', 'requestId and actionType are required.', ['status' => 400]);
    }
    // Runs once per requestId: a repeat (or a race with the cron tick) gets
    // the stored outcome back with duplicate=true instead of a second run.
    $run = Visibility_Requests::run($request_id, $action_type, $payload);
    $out = [
      'ok'        => (bool) $run['ok'],
      'requestId' => $request_id,
      'result'    => $run['result'],
      'error'     => $run['error'],
    ];
    if (!$run['ok'] && !empty($run['errorCode'])) $out['errorCode'] = $run['errorCode'];
    if ($run['duplicate']) $out['duplicate'] = true;
    return rest_ensure_response($out);
  }

  /** Bulk catch-up — pulls every approved-not-yet-executed request from
   *  the server inbox and runs the executor over them. Same code path
   *  WP-Cron uses, just triggered on demand. */
  public function event_drain_inbox(WP_REST_Request $request) {
    if (!class_exists('Visibility_Requests')) {
      return new WP_Error('visibility_no_requests_class', 'Plugin internals not loaded.', ['status' => 500]);
    }
    Visibility_Requests::run_cron_tick();
    return rest_ensure_response(['ok' => true]);
  }

  /** Site-config push from server — cache the new state so admin UI
   *  banners/badges stay accurate without polling. */
  public function event_site_config_changed(WP_REST_Request $request) {
    $body = $request->get_json_params();
    if (!is_array($body)) {
      return new WP_Error('visibility_bad_body', 'JSON body required.', ['status' => 400]);
    }
    Visibility_Policy::apply_flags($body);
    return rest_ensure_response([
      'ok'           => true,
      'allowPublish' => Visibility_Policy::publishing_allowed(),
      'disabled'     => Visibility_Policy::paused(),
    ]);
  }

  public function capabilities(WP_REST_Request $request) {
    return rest_ensure_response(Visibility_Read::capabilities());
  }

  public function health(WP_REST_Request $request) {
    $counts = wp_count_posts('post');
    return rest_ensure_response([
      'ok'             => true,
      'pluginVersion'  => VISIBILITY_PLUGIN_VERSION,
      'wpVersion'      => get_bloginfo('version'),
      'siteName'       => get_bloginfo('name'),
      'siteUrl'        => home_url('/'),
      'adminUrl'       => admin_url('/'),
      'timezone'       => wp_timezone_string(),
      'counts'         => [
        'published' => isset($counts->publish) ? (int) $counts->publish : 0,
        'draft'     => isset($counts->draft) ? (int) $counts->draft : 0,
        'pending'   => isset($counts->pending) ? (int) $counts->pending : 0,
        'future'    => isset($counts->future) ? (int) $counts->future : 0,
        'private'   => isset($counts->private) ? (int) $counts->private : 0,
        'trash'     => isset($counts->trash) ? (int) $counts->trash : 0,
      ],
    ]);
  }

  // ── /posts ──────────────────────────────────────────────────────────

  public function list_posts(WP_REST_Request $request) {
    $perPage = max(1, min(100, (int) ($request->get_param('per_page') ?? 20)));
    $page    = max(1, (int) ($request->get_param('page') ?? 1));
    $search  = (string) ($request->get_param('search') ?? '');
    $statusParam = $request->get_param('status');
    $statuses = self::ALLOWED_STATUSES;
    // 'any' is WordPress's own spelling for "every status" — honour it rather
    // than letting it intersect to nothing and collapse to publish-only.
    if (is_string($statusParam) && $statusParam !== '' && $statusParam !== 'any') {
      $requested = array_map('sanitize_key', array_map('trim', explode(',', $statusParam)));
      $statuses  = array_values(array_intersect(self::ALLOWED_STATUSES, $requested));
      if (empty($statuses)) $statuses = ['publish'];
    }

    $types = $this->requested_types($request->get_param('type'));
    if (is_wp_error($types)) return $types;
    $args = [
      'post_type'      => $types,
      'post_status'    => $statuses,
      'posts_per_page' => $perPage,
      'paged'          => $page,
      's'              => $search,
      'orderby'        => 'modified',
      'order'          => 'DESC',
      'no_found_rows'  => false,
    ];
    $parent = $request->get_param('parent');
    if ($parent !== null && $parent !== '') $args['post_parent'] = (int) $parent;
    $query = new WP_Query($args);

    $items = [];
    foreach ($query->posts as $p) {
      $items[] = $this->serialize_post_summary($p);
    }

    return rest_ensure_response([
      'items'      => $items,
      'page'       => $page,
      'perPage'    => $perPage,
      'total'      => (int) $query->found_posts,
      'totalPages' => (int) $query->max_num_pages,
    ]);
  }

  /**
   * `?type=` → list of post types to query. Default `post` (unchanged);
   * `any` = every readable type; comma lists allowed. Unknown or non-public
   * types are refused with a 400.
   */
  private function requested_types($param) {
    $param = is_string($param) ? trim($param) : '';
    if ($param === '') return ['post'];
    $readable = array_keys(Visibility_Read::readable_types());
    if ($param === 'any') return $readable;
    $types = array_values(array_filter(array_map('sanitize_key', explode(',', $param))));
    $bad = array_diff($types, $readable);
    if (!empty($bad) || empty($types)) {
      return new WP_Error('visibility_bad_type', sprintf(
        /* translators: 1: requested post types, 2: allowed post types. */
        __('Post type not available to Visibility: %1$s. Available: %2$s.', 'agentic-seo-visibility'),
        implode(', ', $bad ?: [$param]), implode(', ', $readable)
      ), ['status' => 400]);
    }
    return $types;
  }

  /** A post Visibility may read, or a WP_Error (404 for missing and for hidden types). */
  private function readable_post($id) {
    $post = get_post((int) $id);
    if (!$post || !Visibility_Read::type_allowed($post->post_type)) {
      return new WP_Error('visibility_no_post', __('Post not found.', 'agentic-seo-visibility'), ['status' => 404]);
    }
    return $post;
  }

  /**
   * GET /posts/{id}?include=seo,fields,blocks,builder,terms (or include=all).
   * Without `include` everything except `blocks` is returned.
   */
  public function get_post(WP_REST_Request $request) {
    $post = $this->readable_post($request['id']);
    if (is_wp_error($post)) return $post;
    $inc = $request->get_param('include');
    $parts = ['seo', 'fields', 'builder', 'terms', 'images'];
    if (is_string($inc) && $inc !== '') {
      $parts = $inc === 'all' ? ['seo', 'fields', 'builder', 'terms', 'images', 'blocks'] : array_map('trim', explode(',', $inc));
    }
    return rest_ensure_response($this->serialize_post_full($post->ID, $parts));
  }

  public function create_post(WP_REST_Request $request) {
    $params = $request->get_json_params() ?: [];
    $type = isset($params['type']) ? sanitize_key((string) $params['type']) : 'post';
    if (!in_array($type, ['post', 'page'], true) || !Visibility_Read::type_allowed($type)) {
      return new WP_Error('visibility_bad_type', __('Only posts and pages can be created.', 'agentic-seo-visibility'), ['status' => 400]);
    }
    $args = $this->build_post_args($params, ['post_status' => 'draft', 'post_type' => $type]);
    $post_id = Visibility_Content::save($args);
    if (is_wp_error($post_id)) {
      return $post_id;
    }
    $this->apply_term_assignments($post_id, $params);
    $this->apply_featured_media($post_id, $params);
    return rest_ensure_response($this->serialize_post_full($post_id));
  }

  public function update_post(WP_REST_Request $request) {
    $id = (int) $request['id'];
    $existing = $this->readable_post($id);
    if (is_wp_error($existing)) return $existing;
    $params = $request->get_json_params() ?: [];
    // Optimistic lock: refuse when the post changed since the caller read it.
    if (Visibility_Content::is_stale($id, $params['modifiedGmt'] ?? null)) {
      return new WP_Error('visibility_conflict', __('The post was changed after it was read. Read it again and retry.', 'agentic-seo-visibility'), [
        'status' => 409, 'modifiedGmt' => Visibility_Content::modified_gmt($id),
      ]);
    }
    $args   = $this->build_post_args($params, ['ID' => $id], $existing);
    $args['ID'] = $id;
    $updated = Visibility_Content::save($args);
    if (is_wp_error($updated)) {
      return $updated;
    }
    $this->apply_term_assignments($id, $params);
    $this->apply_featured_media($id, $params);
    return rest_ensure_response($this->serialize_post_full($id));
  }

  public function delete_post(WP_REST_Request $request) {
    $id = (int) $request['id'];
    $force = filter_var($request->get_param('force'), FILTER_VALIDATE_BOOLEAN);
    $post = $this->readable_post($id);
    if (is_wp_error($post)) return $post;
    $result = wp_delete_post($id, $force);
    if (!$result) {
      return new WP_Error('visibility_delete_failed', __('Could not delete post.', 'agentic-seo-visibility'), ['status' => 500]);
    }
    return rest_ensure_response([
      'id'      => $id,
      'deleted' => true,
      'force'   => (bool) $force,
    ]);
  }

  /**
   * Shared arg-building helper for create + update.
   *
   * On update only the markup that is new gets sanitised; everything already
   * on the page (embeds, forms, scripts in Custom HTML blocks) is kept as is.
   */
  private function build_post_args(array $params, array $base, $existing = null): array {
    $args = $base;
    $old  = $existing ? $existing : null;
    if (isset($params['title']))   $args['post_title']   = Visibility_Content::sanitize_update((string) $params['title'], $old ? $old->post_title : '');
    if (isset($params['content'])) $args['post_content'] = Visibility_Content::sanitize_update((string) $params['content'], $old ? $old->post_content : '');
    if (isset($params['excerpt'])) $args['post_excerpt'] = Visibility_Content::sanitize_update((string) $params['excerpt'], $old ? $old->post_excerpt : '');
    if (isset($params['slug']))    $args['post_name']    = sanitize_title((string) $params['slug']);
    if (isset($params['status'])) {
      $status = sanitize_key((string) $params['status']);
      if (in_array($status, self::ALLOWED_STATUSES, true)) {
        $args['post_status'] = $status;
      }
    }
    if (isset($params['date']) && is_string($params['date']) && $params['date'] !== '') {
      $ts = strtotime($params['date']);
      if ($ts !== false) {
        $args['post_date_gmt'] = gmdate('Y-m-d H:i:s', $ts);
        $args['post_date']     = get_date_from_gmt($args['post_date_gmt']);
      }
    }
    if (isset($params['parent'])) $args['post_parent'] = max(0, (int) $params['parent']);
    if (isset($params['menu_order'])) $args['menu_order'] = (int) $params['menu_order'];
    if (!array_key_exists('post_type', $args) && empty($args['ID'])) {
      $args['post_type'] = 'post';
    }
    return $args;
  }

  private function apply_term_assignments($post_id, array $params): void {
    if (isset($params['categories']) && is_array($params['categories'])) {
      $ids = array_values(array_filter(array_map('intval', $params['categories'])));
      wp_set_post_categories($post_id, $ids, false);
    }
    if (isset($params['tags']) && is_array($params['tags'])) {
      // Accept either numeric IDs or string slugs; wp_set_post_tags handles both.
      wp_set_post_tags($post_id, $params['tags'], false);
    }
  }

  private function apply_featured_media($post_id, array $params): void {
    if (isset($params['featured_media']) && (int) $params['featured_media'] > 0) {
      set_post_thumbnail($post_id, (int) $params['featured_media']);
    }
  }

  // ── Terms (categories + tags) ──────────────────────────────────────

  /** Factory: returns a closure that lists terms of the given taxonomy. */
  public function list_terms_factory($taxonomy) {
    return function (WP_REST_Request $request) use ($taxonomy) {
      $perPage = max(1, min(100, (int) ($request->get_param('per_page') ?? 50)));
      $search  = (string) ($request->get_param('search') ?? '');
      $terms = get_terms([
        'taxonomy'   => $taxonomy,
        'hide_empty' => false,
        'number'     => $perPage,
        'search'     => $search,
      ]);
      if (is_wp_error($terms)) return $terms;
      $items = array_map(fn($t) => [
        'id'    => (int) $t->term_id,
        'name'  => $t->name,
        'slug'  => $t->slug,
        'count' => (int) $t->count,
      ], $terms);
      return rest_ensure_response(['items' => $items]);
    };
  }

  /** Factory: returns a closure that creates a term in the given taxonomy. */
  public function create_term_factory($taxonomy) {
    return function (WP_REST_Request $request) use ($taxonomy) {
      $params = $request->get_json_params() ?: [];
      $name   = isset($params['name']) ? sanitize_text_field((string) $params['name']) : '';
      if ($name === '') {
        return new WP_Error('visibility_term_no_name', __('Term name required.', 'agentic-seo-visibility'), ['status' => 400]);
      }
      $args = [];
      if (isset($params['slug']))        $args['slug']        = sanitize_title((string) $params['slug']);
      if (isset($params['description'])) $args['description'] = wp_kses_post((string) $params['description']);
      $created = wp_insert_term($name, $taxonomy, $args);
      if (is_wp_error($created)) return $created;
      $term = get_term((int) $created['term_id'], $taxonomy);
      return rest_ensure_response([
        'id'    => (int) $term->term_id,
        'name'  => $term->name,
        'slug'  => $term->slug,
        'count' => (int) $term->count,
      ]);
    };
  }

  // ── Media ──────────────────────────────────────────────────────────

  /** GET /media?search=&page=&per_page=, or ?id=12,13 to fetch by id (for re-use). */
  public function list_media(WP_REST_Request $request) {
    $ids = $request->get_param('id');
    if ($ids !== null && $ids !== '') {
      $ids = array_values(array_filter(array_map('intval', explode(',', (string) $ids))));
      $items = array_values(array_filter(array_map(function ($id) { return Visibility_Media::serialize($id); }, $ids)));
      return rest_ensure_response(['items' => $items, 'page' => 1, 'perPage' => count($ids), 'total' => count($items), 'totalPages' => 1]);
    }
    $perPage = max(1, min(100, (int) ($request->get_param('per_page') ?? 20)));
    $page    = max(1, (int) ($request->get_param('page') ?? 1));
    $search  = (string) ($request->get_param('search') ?? '');
    $query = new WP_Query([
      'post_type'      => 'attachment',
      'post_status'    => 'inherit',
      'posts_per_page' => $perPage,
      'paged'          => $page,
      's'              => $search,
      'orderby'        => 'date',
      'order'          => 'DESC',
    ]);
    $items = array_map(fn($p) => Visibility_Media::serialize($p->ID), $query->posts);
    return rest_ensure_response([
      'items'      => $items,
      'page'       => $page,
      'perPage'    => $perPage,
      'total'      => (int) $query->found_posts,
      'totalPages' => (int) $query->max_num_pages,
    ]);
  }

  /**
   * POST /media {source_url, title?, alt_text? (or alt), caption?, description?,
   * filename?, post_id?}: copy an image into the media library (never
   * hot-linked), attached to post_id. The same source_url is re-used.
   */
  public function upload_media_from_url(WP_REST_Request $request) {
    $p = $request->get_json_params() ?: [];
    $args = [
      'source_url' => isset($p['source_url']) ? $p['source_url'] : '',
      'post_id'    => isset($p['post_id']) ? $p['post_id'] : 0,
    ];
    foreach (['title', 'caption', 'description', 'filename'] as $k) {
      if (isset($p[$k]) && is_scalar($p[$k])) $args[$k] = (string) $p[$k];
    }
    if (isset($p['alt_text']) && is_scalar($p['alt_text'])) $args['alt'] = (string) $p['alt_text'];
    elseif (isset($p['alt']) && is_scalar($p['alt'])) $args['alt'] = (string) $p['alt'];
    $res = Visibility_Media::upload($args);
    if (is_wp_error($res)) return $res;
    return rest_ensure_response($res);
  }

  // ── Serializers ────────────────────────────────────────────────────

  private function serialize_post_summary($post) {
    return [
      'id'          => (int) $post->ID,
      'type'        => $post->post_type,
      'status'      => $post->post_status,
      'title'       => $post->post_title,
      'slug'        => $post->post_name,
      'parent'      => (int) $post->post_parent,
      'excerpt'     => wp_strip_all_tags($post->post_excerpt),
      'link'        => get_permalink($post),
      'editUrl'     => admin_url('post.php?post=' . $post->ID . '&action=edit'),
      'date'        => $this->safe_iso($post->post_date_gmt),
      'modified'    => $this->safe_iso($post->post_modified_gmt),
      'modifiedGmt' => Visibility_Content::modified_gmt($post),
    ];
  }

  /**
   * Full post. $parts picks the optional sections (seo, fields, builder,
   * terms, images, blocks); the basic fields are always there.
   */
  private function serialize_post_full($post_id, array $parts = ['seo', 'fields', 'builder', 'terms', 'images']) {
    $post = get_post($post_id);
    if (!$post) return null;
    $categories = is_object_in_taxonomy($post->post_type, 'category') ? wp_get_post_categories($post_id, ['fields' => 'all']) : [];
    $tags       = is_object_in_taxonomy($post->post_type, 'post_tag') ? wp_get_post_tags($post_id) : [];
    $featuredId = (int) get_post_thumbnail_id($post_id);
    $author     = get_userdata((int) $post->post_author);

    $out = [
      'id'           => (int) $post->ID,
      'type'         => $post->post_type,
      'status'       => $post->post_status,
      'title'        => $post->post_title,
      'slug'         => $post->post_name,
      'content'      => $post->post_content,
      'excerpt'      => $post->post_excerpt,
      'link'         => get_permalink($post),
      'editUrl'      => admin_url('post.php?post=' . $post->ID . '&action=edit'),
      'date'         => $this->safe_iso($post->post_date_gmt),
      'modified'     => $this->safe_iso($post->post_modified_gmt),
      'modifiedGmt'  => Visibility_Content::modified_gmt($post),
      'parent'       => (int) $post->post_parent,
      'menuOrder'    => (int) $post->menu_order,
      'template'     => (string) get_page_template_slug($post),
      'author'       => $author ? ['id' => (int) $author->ID, 'name' => $author->display_name] : null,
      'editor'       => Visibility_Read::editor_of($post),
      'categories'   => array_map(fn($c) => ['id' => (int) $c->term_id, 'name' => $c->name, 'slug' => $c->slug], $categories),
      'tags'         => array_map(fn($t) => ['id' => (int) $t->term_id, 'name' => $t->name, 'slug' => $t->slug], $tags),
      'featuredMedia' => $featuredId > 0 ? $this->serialize_media($featuredId) : null,
    ];
    if (in_array('builder', $parts, true)) $out['builder'] = Visibility_Read::builder_of($post);
    if (in_array('seo', $parts, true))     $out['seo'] = Visibility_SEO::read_post($post->ID);
    if (in_array('fields', $parts, true))  $out['fields'] = Visibility_Read::fields($post);
    if (in_array('terms', $parts, true))   $out['terms'] = Visibility_Read::terms($post);
    if (in_array('images', $parts, true))  $out['images'] = Visibility_Read::images($post->post_content);
    if (in_array('blocks', $parts, true))  $out['blocks'] = Visibility_Read::blocks($post->post_content);
    return $out;
  }

  private function serialize_media($attachment_id) {
    return Visibility_Media::serialize($attachment_id);
  }

  private function safe_iso($mysql_gmt) {
    if (empty($mysql_gmt) || $mysql_gmt === '0000-00-00 00:00:00') {
      $mysql_gmt = current_time('mysql', true);
    }
    return mysql2date('c', $mysql_gmt, false);
  }
}
