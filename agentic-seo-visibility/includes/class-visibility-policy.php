<?php
/**
 * The site owner's controls, enforced by the plugin itself (#273, #295).
 *
 * The controls are set in the Visibility app and pushed here
 * (`/events/site-config-changed`, heartbeat replies); the plugin keeps a copy
 * and checks it before every write, so a bug in the app can never make a
 * change the owner did not allow.
 *
 *   visibility_allow_publish  '0' = publishing off. Never set = allowed.
 *   visibility_disabled       '1' = paused from Visibility.
 *   visibility_paused_wp      '1' = paused with the Pause button in WordPress.
 *                             Only WordPress sets or clears it; Visibility's
 *                             `disabled` never touches it.
 *   visibility_controls       {actions, postTypes, lockedPostIds, fieldAllowlist, anyPostMeta}.
 *                             Never received = no limits beyond the two flags
 *                             above (0.10.0 behaviour), so older apps keep
 *                             working. Once received, it is enforced.
 *
 * Actions (owner's toggles): create_drafts, edit_content, edit_meta,
 * edit_fields, images, redirects, publish, delete.
 */

if (!defined('ABSPATH')) {
  exit;
}

class Visibility_Policy {

  const ACTIONS = ['create_drafts', 'edit_content', 'edit_meta', 'edit_fields', 'images', 'redirects', 'publish', 'delete'];

  /** Executor actions that make something public, remove it, or change when it goes live. */
  const PUBLISH_ACTIONS = [
    'post.publish', 'page.publish', 'post.schedule', 'post.unpublish',
    'post.trash', 'post.restore', 'post.delete_permanent', 'media.delete',
    'category.delete', 'tag.delete', 'redirect.create', 'redirect.delete',
  ];

  /** Post statuses that only a publish-allowed site may set. */
  const PUBLISH_STATUSES = ['publish', 'future', 'private'];

  /** Statuses in which a post Visibility created still counts as its draft. */
  const DRAFT_STATUSES = ['draft', 'pending', 'auto-draft'];

  /** Executor action => owner control. */
  const ACTION_CONTROL = [
    'post.create_draft'     => 'create_drafts',
    'page.create_draft'     => 'create_drafts',
    'post.update_content'   => 'edit_content',
    'page.update_content'   => 'edit_content',
    'post.edit_parts'       => 'edit_content',
    'page.edit_parts'       => 'edit_content',
    'internal_link.suggest' => 'edit_content',
    'builder.update'        => 'edit_content',
    'seo_meta.update'       => 'edit_meta',
    'post.update_meta'      => 'edit_meta',
    'category.create'       => 'edit_meta',
    'category.update'       => 'edit_meta',
    'tag.create'            => 'edit_meta',
    'tag.update'            => 'edit_meta',
    'fields.update'         => 'edit_fields',
    'meta.update'           => 'edit_fields',
    'product.update'        => 'edit_content',
    // A category's name, address and description are its content, like a product's.
    'term.update'           => 'edit_content',
    'media.upload_from_url' => 'images',
    'media.update_meta'     => 'images',
    'media.bulk_alt'        => 'images',
    'redirect.create'       => 'redirects',
    'redirect.delete'       => 'redirects',
    'post.publish'          => 'publish',
    'page.publish'          => 'publish',
    'post.schedule'         => 'publish',
    'post.unpublish'        => 'publish',
    'post.trash'            => 'delete',
    'post.restore'          => 'delete',
    'post.delete_permanent' => 'delete',
    'media.delete'          => 'delete',
    'category.delete'       => 'delete',
    'tag.delete'            => 'delete',
    // Change log entries written by the REST routes.
    'rest.create'           => 'create_drafts',
    'rest.update'           => 'edit_content',
    'rest.trash'            => 'delete',
  ];

  // ─── State ─────────────────────────────────────────────────────────────

  public static function publishing_allowed() {
    if (get_option('visibility_allow_publish', '') === '0') return false;
    $c = self::controls();
    return $c === null || in_array('publish', $c['actions'], true);
  }

  public static function paused() {
    return self::paused_by() !== null;
  }

  /** "wordpress" | "visibility" | null. WordPress wins when both are on. */
  public static function paused_by() {
    if (get_option('visibility_paused_wp', '') === '1') return 'wordpress';
    if (get_option('visibility_disabled', '') === '1') return 'visibility';
    return null;
  }

  /** The Pause button in WP admin. Tells Visibility at once (heartbeat). */
  public static function set_wp_pause($on) {
    update_option('visibility_paused_wp', $on ? '1' : '0', false);
    if (class_exists('Visibility_Client')) Visibility_Client::heartbeat();
  }

  /** Controls from Visibility, or null when never received. */
  public static function controls() {
    $c = get_option('visibility_controls', null);
    if (!is_array($c)) return null;
    return $c + ['actions' => [], 'postTypes' => [], 'lockedPostIds' => [], 'fieldAllowlist' => [], 'anyPostMeta' => false];
  }

  /** Store flags and controls from a server message (absent keys are left alone). */
  public static function apply_flags($body) {
    if (!is_array($body)) return;
    if (array_key_exists('allowPublish', $body) && $body['allowPublish'] !== null) {
      update_option('visibility_allow_publish', $body['allowPublish'] ? '1' : '0', false);
    }
    if (array_key_exists('disabled', $body) && $body['disabled'] !== null) {
      update_option('visibility_disabled', $body['disabled'] ? '1' : '0', false);
    }
    if (array_key_exists('controls', $body)) {
      // When Visibility last sent them, shown next to the controls (#474).
      update_option('visibility_controls_at', time(), false);
      if ($body['controls'] === null) {
        delete_option('visibility_controls');
      } elseif (is_array($body['controls'])) {
        update_option('visibility_controls', self::clean_controls($body['controls']), false);
      }
    }
  }

  private static function clean_controls(array $c) {
    $list = function ($v) { return is_array($v) ? array_values($v) : []; };
    return [
      'actions'        => array_values(array_intersect(self::ACTIONS, array_map('strval', $list($c['actions'] ?? [])))),
      'postTypes'      => array_values(array_unique(array_filter(array_map('sanitize_key', array_map('strval', $list($c['postTypes'] ?? [])))))),
      'lockedPostIds'  => array_values(array_unique(array_filter(array_map('intval', $list($c['lockedPostIds'] ?? []))))),
      'fieldAllowlist' => array_values(array_unique(array_filter(array_map(function ($k) { return is_scalar($k) ? substr(trim((string) $k), 0, 200) : ''; }, $list($c['fieldAllowlist'] ?? []))))),
      // 2.1.0: "any post meta except the protected list" (meta.update). Off unless sent as true.
      'anyPostMeta'    => !empty($c['anyPostMeta']) && $c['anyPostMeta'] !== 'false',
    ];
  }

  /** True when $key may be written by fields.update (strict: no list, no writes). */
  public static function field_allowed($key) {
    $c = self::controls();
    if ($c === null) return false;
    return in_array((string) $key, $c['fieldAllowlist'], true);
  }

  /** The owner's "any post meta" mode (controls.anyPostMeta, or "meta:*" in the allowlist). Off by default. */
  public static function any_post_meta() {
    $c = self::controls();
    if ($c === null) return false;
    return !empty($c['anyPostMeta']) || in_array('meta:*', $c['fieldAllowlist'], true);
  }

  /**
   * True when meta.update may write post meta $meta_key: the allowlist names
   * "meta:<key>", or the owner turned on "any post meta" and the key already
   * exists on the post or is registered ($known). Keys that can never be
   * written (secrets, SEO plugins', builders') are refused before this.
   */
  public static function meta_allowed($meta_key, $known = true) {
    if (self::field_allowed('meta:' . $meta_key)) return true;
    return $known && self::any_post_meta();
  }

  // ─── Helpers ───────────────────────────────────────────────────────────

  /** A post Visibility created that is still a draft: edits to it count as creating the draft. */
  public static function is_visibility_draft($post_id) {
    $post = $post_id ? get_post((int) $post_id) : null;
    return $post && in_array($post->post_status, self::DRAFT_STATUSES, true) && get_post_meta($post->ID, '_visibility_created', true) === '1';
  }

  public static function mark_created($post_id) {
    update_post_meta((int) $post_id, '_visibility_created', '1');
  }

  public static function created_by_visibility($post_id) {
    return get_post_meta((int) $post_id, '_visibility_created', true) === '1';
  }

  private static function control_label($control) {
    $labels = [
      'create_drafts' => 'creating drafts', 'edit_content' => 'editing existing content', 'edit_meta' => 'editing meta tags',
      'edit_fields' => 'editing custom fields', 'images' => 'images and alt text', 'redirects' => 'redirects',
      'publish' => 'publishing', 'delete' => 'deleting',
    ];
    return isset($labels[$control]) ? $labels[$control] : $control;
  }

  /**
   * Check a set of controls for a post. $post_id 0 = no specific post; $type
   * is the post type being created when there is no post yet.
   *
   * @return array|null [code, message]
   */
  public static function check_controls(array $needed, $post_id = 0, $type = null) {
    $c = self::controls();
    if ($c === null) return null;
    foreach (array_unique($needed) as $ctl) {
      if (!in_array($ctl, $c['actions'], true)) {
        return ['not_allowed', sprintf('The site owner has not allowed %s on this site, so the plugin refused it.', self::control_label($ctl))];
      }
    }
    $post = $post_id ? get_post((int) $post_id) : null;
    if ($post) {
      if (in_array((int) $post->ID, $c['lockedPostIds'], true) || ($post->post_type === 'attachment' && $post->post_parent && in_array((int) $post->post_parent, $c['lockedPostIds'], true))) {
        return ['locked', sprintf('"%s" is locked by the site owner; agents may not change it.', $post->post_title !== '' ? $post->post_title : '#' . $post->ID)];
      }
      $type = $post->post_type === 'attachment' ? null : $post->post_type;
    }
    if ($type !== null && !in_array($type, $c['postTypes'], true)) {
      return ['not_allowed', sprintf('The site owner has not allowed agents to work on content of type "%s".', $type)];
    }
    return null;
  }

  // ─── Executor ──────────────────────────────────────────────────────────

  /**
   * Why an executor action may not run, or null when it may.
   *
   * @return array|null [code, message]
   */
  public static function check_action($action_type, $payload) {
    if (self::paused()) {
      return ['paused', self::paused_by() === 'wordpress'
        ? 'Visibility is paused in WordPress for this site, so the plugin refused the change.'
        : 'Visibility is paused for this site, so the plugin refused the change.'];
    }
    $payload = is_array($payload) ? $payload : [];
    if (get_option('visibility_allow_publish', '') === '0') {
      if (in_array($action_type, self::PUBLISH_ACTIONS, true)) {
        return ['publishing_off', 'Publishing is turned off for this site, so the plugin refused this action.'];
      }
      $status = isset($payload['status']) ? (string) $payload['status'] : '';
      if (in_array($status, self::PUBLISH_STATUSES, true)) {
        return ['publishing_off', 'Publishing is turned off for this site, so the plugin refused to set the status to ' . $status . '.'];
      }
    }
    if (self::controls() === null) return null;
    list($needed, $post_id, $type) = self::needs_for_action($action_type, $payload);
    if ($needed === null) return null;
    $refused = self::check_controls($needed, $post_id, $type);
    if ($refused) return $refused;
    // A redirect from a locked page's address changes that page for visitors (#464).
    if ($action_type === 'redirect.create' || $action_type === 'redirect.delete') {
      $source = $action_type === 'redirect.create'
        ? (isset($payload['source']) ? $payload['source'] : (isset($payload['fromPath']) ? $payload['fromPath'] : ''))
        : (class_exists('Visibility_Redirects') ? Visibility_Redirects::source_of(isset($payload['redirectId']) ? (string) $payload['redirectId'] : '') : '');
      $locked = self::locked_at_address($source);
      if ($locked) {
        return ['locked', sprintf('"%s" is locked by the site owner; agents may not redirect its address.', $locked->post_title !== '' ? $locked->post_title : '#' . $locked->ID)];
      }
    }
    return null;
  }

  /**
   * The locked post whose address (current, or an old slug WordPress still
   * sends to it) is $address, or null.
   */
  public static function locked_at_address($address) {
    $c = self::controls();
    if ($c === null || empty($c['lockedPostIds']) || !class_exists('Visibility_Redirects')) return null;
    $path = Visibility_Redirects::to_path((string) $address);
    if ($path === null || $path === '') return null;
    $key = Visibility_Redirects::key($path);
    $h = wp_parse_url(home_url('/'));
    $id = (int) url_to_postid((isset($h['scheme']) ? $h['scheme'] : 'http') . '://' . $h['host'] . (isset($h['port']) ? ':' . $h['port'] : '') . $path);
    $last = basename($key);
    foreach ($c['lockedPostIds'] as $locked_id) {
      $post = get_post((int) $locked_id);
      if (!$post) continue;
      if ($id === (int) $post->ID) return $post;
      $link = get_permalink($post);
      $own = $link ? Visibility_Redirects::to_path($link) : null;
      if ($own !== null && Visibility_Redirects::key($own) === $key) return $post;
      foreach ((array) get_post_meta($post->ID, '_wp_old_slug') as $old) {
        if ($old !== '' && $last === strtolower((string) $old)) return $post;
      }
    }
    return null;
  }

  /** [controls needed, post id, post type for creates]. */
  private static function needs_for_action($action_type, array $payload) {
    $post_id = (int) ($payload['postId'] ?? $payload['pageId'] ?? $payload['productId'] ?? 0);
    $type = null;
    if ($action_type === 'change.rollback') {
      $row = class_exists('Visibility_Changes') ? Visibility_Changes::get((int) ($payload['changeId'] ?? 0)) : null;
      if (!$row) return [null, 0, null];
      return [self::needs_for_rollback($row), (int) $row->post_id, null];
    }
    $ctl = isset(self::ACTION_CONTROL[$action_type]) ? self::ACTION_CONTROL[$action_type] : null;
    if ($ctl === null) return [null, 0, null];
    $needed = [$ctl];
    if ($action_type === 'post.create_draft') $type = 'post';
    if ($action_type === 'page.create_draft') $type = 'page';
    if (strpos($action_type, 'media.') === 0) {
      if ($action_type !== 'media.upload_from_url') $post_id = (int) ($payload['mediaId'] ?? 0);
      // Bulk alt: each image and page is checked by the action itself.
      if ($action_type === 'media.bulk_alt') $post_id = 0;
      // An image for a draft Visibility is still building is part of the draft.
      if ($action_type === 'media.upload_from_url' && self::is_visibility_draft($post_id)) $needed = ['create_drafts'];
    }
    if (in_array($action_type, ['category.create', 'category.update', 'category.delete', 'tag.create', 'tag.update', 'tag.delete', 'redirect.create', 'redirect.delete', 'term.update'], true)) {
      $post_id = 0;
    }
    if ($action_type === 'post.update_meta') {
      $fields = isset($payload['fields']) && is_array($payload['fields']) ? array_keys($payload['fields']) : [];
      $needed = [];
      if (in_array('featuredMediaId', $fields, true)) $needed[] = 'images';
      if (array_diff($fields, ['featuredMediaId'])) $needed[] = 'edit_meta';
      if (empty($needed)) $needed = ['edit_meta'];
    }
    if (in_array($ctl, ['edit_content'], true) || $action_type === 'post.update_meta' && $needed === ['images']) {
      if (self::is_visibility_draft($post_id)) $needed = ['create_drafts'];
    }
    if (isset($payload['status']) && in_array((string) $payload['status'], self::PUBLISH_STATUSES, true)) $needed[] = 'publish';
    return [$needed, $post_id, $type];
  }

  private static function needs_for_rollback($row) {
    $ctl = isset(self::ACTION_CONTROL[$row->action]) ? self::ACTION_CONTROL[$row->action] : 'edit_content';
    if ($ctl === 'edit_content' && self::is_visibility_draft((int) $row->post_id)) $ctl = 'create_drafts';
    return [$ctl];
  }

  // ─── REST ──────────────────────────────────────────────────────────────

  /**
   * Same check for the REST write routes.
   *
   * @return true|WP_Error
   */
  public static function check_rest($request) {
    $method = strtoupper($request->get_method());
    if ($method === 'GET' || $method === 'HEAD') return true;
    if (self::paused()) {
      return new WP_Error('visibility_paused', self::paused_by() === 'wordpress'
        ? __('Visibility is paused in WordPress for this site, so the plugin refused the change.', 'agentic-seo-visibility')
        : __('Visibility is paused for this site, so the plugin refused the change.', 'agentic-seo-visibility'), ['status' => 403]);
    }
    $route = $request->get_route();
    $params = $request->get_json_params();
    if (!is_array($params)) $params = [];
    $status = isset($params['status']) ? sanitize_key((string) $params['status']) : '';
    $is_post_route = (bool) preg_match('#^/visibility/v1/posts#', $route);
    if (get_option('visibility_allow_publish', '') === '0') {
      if ($is_post_route && ($method === 'DELETE' || in_array($status, self::PUBLISH_STATUSES, true))) {
        return new WP_Error('visibility_publishing_off', __('Publishing is turned off for this site, so the plugin refused this change.', 'agentic-seo-visibility'), ['status' => 403]);
      }
    }
    if (self::controls() === null) return true;
    list($needed, $post_id, $type) = self::needs_for_rest($method, $route, $params, $request);
    if ($needed === null) return true;
    $refused = self::check_controls($needed, $post_id, $type);
    if ($refused) return new WP_Error('visibility_' . $refused[0], $refused[1], ['status' => 403]);
    return true;
  }

  private static function needs_for_rest($method, $route, array $params, $request) {
    $status = isset($params['status']) ? sanitize_key((string) $params['status']) : '';
    if (preg_match('#^/visibility/v1/posts$#', $route) && $method === 'POST') {
      $needed = ['create_drafts'];
      if (in_array($status, self::PUBLISH_STATUSES, true)) $needed[] = 'publish';
      return [$needed, 0, isset($params['type']) ? sanitize_key((string) $params['type']) : 'post'];
    }
    if (preg_match('#^/visibility/v1/posts/(\d+)$#', $route, $m)) {
      $id = (int) $m[1];
      if ($method === 'DELETE') return [['delete'], $id, null];
      $draft = self::is_visibility_draft($id);
      $needed = [];
      foreach (['title', 'content', 'excerpt', 'slug', 'parent', 'menu_order', 'date'] as $k) {
        if (array_key_exists($k, $params)) { $needed[] = $draft ? 'create_drafts' : 'edit_content'; break; }
      }
      if (array_key_exists('categories', $params) || array_key_exists('tags', $params)) $needed[] = 'edit_meta';
      if (array_key_exists('featured_media', $params)) $needed[] = $draft ? 'create_drafts' : 'images';
      if ($status !== '') {
        $post = get_post($id);
        $was_live = $post && in_array($post->post_status, self::PUBLISH_STATUSES, true);
        if (in_array($status, self::PUBLISH_STATUSES, true) || $was_live) $needed[] = 'publish';
        elseif (!$needed) $needed[] = $draft ? 'create_drafts' : 'edit_content';
      }
      if (array_key_exists('language', $params) || array_key_exists('translationOf', $params)) $needed[] = $draft ? 'create_drafts' : 'edit_content';
      return [$needed ?: ['edit_content'], $id, null];
    }
    if (preg_match('#^/visibility/v1/media$#', $route)) {
      $pid = (int) ($params['post_id'] ?? 0);
      return [self::is_visibility_draft($pid) ? ['create_drafts'] : ['images'], $pid, null];
    }
    if (preg_match('#^/visibility/v1/(categories|tags)$#', $route)) return [['edit_meta'], 0, null];
    if (preg_match('#^/visibility/v1/changes/(\d+)/rollback$#', $route, $m)) {
      $row = class_exists('Visibility_Changes') ? Visibility_Changes::get((int) $m[1]) : null;
      if (!$row) return [null, 0, null];
      return [self::needs_for_rollback($row), (int) $row->post_id, null];
    }
    if (preg_match('#^/visibility/v1/fields#', $route)) return [['edit_fields'], (int) ($params['postId'] ?? 0), null];
    return [null, 0, null];
  }

  /** Kept for REST rollback (the check runs in authenticate_write). */
  public static function check_rollback($change_id) {
    return true;
  }

  // ─── Special pages (defaults for locked pages in the app) ─────────────

  public static function special_pages() {
    $out = [];
    if (get_option('show_on_front') === 'page') {
      $front = (int) get_option('page_on_front');
      $posts = (int) get_option('page_for_posts');
      if ($front && get_post($front)) $out['front'] = $front;
      if ($posts && get_post($posts)) $out['posts'] = $posts;
    }
    $privacy = (int) get_option('wp_page_for_privacy_policy');
    if ($privacy && get_post($privacy)) $out['privacy'] = $privacy;
    if (function_exists('wc_get_page_id')) {
      foreach (['cart' => 'cart', 'checkout' => 'checkout', 'myaccount' => 'myaccount', 'shop' => 'shop'] as $k => $wc) {
        $id = (int) wc_get_page_id($wc);
        if ($id > 0 && get_post($id)) $out[$k] = $id;
      }
    }
    return (object) $out;
  }
}
