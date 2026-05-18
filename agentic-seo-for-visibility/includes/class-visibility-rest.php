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
        'permission_callback' => [$this, 'authenticate'],
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
        'permission_callback' => [$this, 'authenticate'],
      ],
      [
        'methods'             => 'DELETE',
        'callback'            => [$this, 'delete_post'],
        'permission_callback' => [$this, 'authenticate'],
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
        'permission_callback' => [$this, 'authenticate'],
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
        'permission_callback' => [$this, 'authenticate'],
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
        'permission_callback' => [$this, 'authenticate'],
      ],
    ]);
  }

  /** Constant-time bearer-token check against the option stored at pairing. */
  public function authenticate($request) {
    $header = $request->get_header('authorization');
    if (!$header || stripos($header, 'Bearer ') !== 0) {
      return new WP_Error('visibility_no_auth', __('Missing bearer token.', 'agentic-seo-for-visibility'), ['status' => 401]);
    }
    $token  = trim(substr($header, 7));
    $stored = Visibility_Client::site_token();
    if ($stored === '' || !hash_equals($stored, $token)) {
      return new WP_Error('visibility_bad_token', __('Invalid site token.', 'agentic-seo-for-visibility'), ['status' => 401]);
    }
    return true;
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
    list($ok, $result, $err) = Visibility_Executor::execute($action_type, $payload);
    Visibility_Client::report_execution($request_id, $ok, $result, $err);
    return rest_ensure_response([
      'ok'        => (bool) $ok,
      'requestId' => $request_id,
      'result'    => $result,
      'error'     => $err,
    ]);
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
    if (array_key_exists('allowPublish', $body)) {
      update_option('visibility_allow_publish', (bool) $body['allowPublish'] ? '1' : '0', false);
    }
    if (array_key_exists('disabled', $body)) {
      update_option('visibility_disabled', (bool) $body['disabled'] ? '1' : '0', false);
    }
    return rest_ensure_response(['ok' => true]);
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
    if (is_string($statusParam) && $statusParam !== '') {
      $requested = array_map('sanitize_key', array_map('trim', explode(',', $statusParam)));
      $statuses  = array_values(array_intersect(self::ALLOWED_STATUSES, $requested));
      if (empty($statuses)) $statuses = ['publish'];
    }

    $query = new WP_Query([
      'post_type'      => 'post',
      'post_status'    => $statuses,
      'posts_per_page' => $perPage,
      'paged'          => $page,
      's'              => $search,
      'orderby'        => 'modified',
      'order'          => 'DESC',
      'no_found_rows'  => false,
    ]);

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

  public function get_post(WP_REST_Request $request) {
    $id = (int) $request['id'];
    $post = get_post($id);
    if (!$post) {
      return new WP_Error('visibility_no_post', __('Post not found.', 'agentic-seo-for-visibility'), ['status' => 404]);
    }
    return rest_ensure_response($this->serialize_post_full($post->ID));
  }

  public function create_post(WP_REST_Request $request) {
    $params = $request->get_json_params() ?: [];
    $args = $this->build_post_args($params, ['post_status' => 'draft']);
    $post_id = wp_insert_post($args, true);
    if (is_wp_error($post_id)) {
      return $post_id;
    }
    $this->apply_term_assignments($post_id, $params);
    $this->apply_featured_media($post_id, $params);
    return rest_ensure_response($this->serialize_post_full($post_id));
  }

  public function update_post(WP_REST_Request $request) {
    $id = (int) $request['id'];
    if (!get_post($id)) {
      return new WP_Error('visibility_no_post', __('Post not found.', 'agentic-seo-for-visibility'), ['status' => 404]);
    }
    $params = $request->get_json_params() ?: [];
    $args   = $this->build_post_args($params, ['ID' => $id]);
    $args['ID'] = $id;
    $updated = wp_update_post($args, true);
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
    $post = get_post($id);
    if (!$post) {
      return new WP_Error('visibility_no_post', __('Post not found.', 'agentic-seo-for-visibility'), ['status' => 404]);
    }
    $result = wp_delete_post($id, $force);
    if (!$result) {
      return new WP_Error('visibility_delete_failed', __('Could not delete post.', 'agentic-seo-for-visibility'), ['status' => 500]);
    }
    return rest_ensure_response([
      'id'      => $id,
      'deleted' => true,
      'force'   => (bool) $force,
    ]);
  }

  /** Shared arg-building helper for create + update. */
  private function build_post_args(array $params, array $base): array {
    $args = $base;
    if (isset($params['title']))   $args['post_title']   = wp_kses_post((string) $params['title']);
    if (isset($params['content'])) $args['post_content'] = wp_kses_post((string) $params['content']);
    if (isset($params['excerpt'])) $args['post_excerpt'] = wp_kses_post((string) $params['excerpt']);
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
    if (!array_key_exists('post_type', $args)) {
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
        return new WP_Error('visibility_term_no_name', __('Term name required.', 'agentic-seo-for-visibility'), ['status' => 400]);
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

  public function list_media(WP_REST_Request $request) {
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
    $items = array_map(fn($p) => $this->serialize_media($p->ID), $query->posts);
    return rest_ensure_response([
      'items'      => $items,
      'page'       => $page,
      'perPage'    => $perPage,
      'total'      => (int) $query->found_posts,
      'totalPages' => (int) $query->max_num_pages,
    ]);
  }

  /** Upload from a remote URL — simpler than multipart and what most
   *  agents already produce (images from upstream tools). */
  public function upload_media_from_url(WP_REST_Request $request) {
    $params = $request->get_json_params() ?: [];
    $sourceUrl = isset($params['source_url']) ? esc_url_raw((string) $params['source_url']) : '';
    if ($sourceUrl === '') {
      return new WP_Error('visibility_no_url', __('source_url is required.', 'agentic-seo-for-visibility'), ['status' => 400]);
    }
    $title = isset($params['title']) ? sanitize_text_field((string) $params['title']) : '';
    $alt   = isset($params['alt_text']) ? sanitize_text_field((string) $params['alt_text']) : '';

    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    $tmp = download_url($sourceUrl, 30);
    if (is_wp_error($tmp)) return $tmp;

    $parsed_url = wp_parse_url($sourceUrl);
    $filename = wp_basename($parsed_url['path'] ?? 'upload.bin');
    $file_array = [
      'name'     => $filename,
      'tmp_name' => $tmp,
    ];
    $attachment_id = media_handle_sideload($file_array, 0, $title);
    if (is_wp_error($attachment_id)) {
      wp_delete_file($tmp);
      return $attachment_id;
    }
    if ($alt !== '') {
      update_post_meta($attachment_id, '_wp_attachment_image_alt', $alt);
    }
    return rest_ensure_response($this->serialize_media($attachment_id));
  }

  // ── Serializers ────────────────────────────────────────────────────

  private function serialize_post_summary($post) {
    return [
      'id'       => (int) $post->ID,
      'status'   => $post->post_status,
      'title'    => $post->post_title,
      'slug'     => $post->post_name,
      'excerpt'  => wp_strip_all_tags($post->post_excerpt),
      'link'     => get_permalink($post),
      'editUrl'  => admin_url('post.php?post=' . $post->ID . '&action=edit'),
      'date'     => $this->safe_iso($post->post_date_gmt),
      'modified' => $this->safe_iso($post->post_modified_gmt),
    ];
  }

  private function serialize_post_full($post_id) {
    $post = get_post($post_id);
    if (!$post) return null;
    $categories = wp_get_post_categories($post_id, ['fields' => 'all']);
    $tags       = wp_get_post_tags($post_id);
    $featuredId = (int) get_post_thumbnail_id($post_id);

    return [
      'id'           => (int) $post->ID,
      'status'       => $post->post_status,
      'title'        => $post->post_title,
      'slug'         => $post->post_name,
      'content'      => $post->post_content,
      'excerpt'      => $post->post_excerpt,
      'link'         => get_permalink($post),
      'editUrl'      => admin_url('post.php?post=' . $post->ID . '&action=edit'),
      'date'         => $this->safe_iso($post->post_date_gmt),
      'modified'     => $this->safe_iso($post->post_modified_gmt),
      'categories'   => array_map(fn($c) => ['id' => (int) $c->term_id, 'name' => $c->name, 'slug' => $c->slug], $categories),
      'tags'         => array_map(fn($t) => ['id' => (int) $t->term_id, 'name' => $t->name, 'slug' => $t->slug], $tags),
      'featuredMedia' => $featuredId > 0 ? $this->serialize_media($featuredId) : null,
    ];
  }

  private function serialize_media($attachment_id) {
    $post = get_post($attachment_id);
    if (!$post) return null;
    return [
      'id'        => (int) $post->ID,
      'title'     => $post->post_title,
      'slug'      => $post->post_name,
      'mimeType'  => $post->post_mime_type,
      'url'       => wp_get_attachment_url($post->ID),
      'altText'   => (string) get_post_meta($post->ID, '_wp_attachment_image_alt', true),
      'date'      => $this->safe_iso($post->post_date_gmt),
    ];
  }

  private function safe_iso($mysql_gmt) {
    if (empty($mysql_gmt) || $mysql_gmt === '0000-00-00 00:00:00') {
      $mysql_gmt = current_time('mysql', true);
    }
    return mysql2date('c', $mysql_gmt, false);
  }
}
