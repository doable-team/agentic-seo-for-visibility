<?php
/**
 * REST endpoints exposed BY the plugin TO Visibility's server.
 *
 * Visibility calls these to publish posts, check the plugin is alive,
 * etc. All endpoints are authenticated with the shared site token in
 * `Authorization: Bearer <token>`.
 *
 * Routes are namespaced under /wp-json/visibility/v1/* .
 */

if (!defined('ABSPATH')) {
  exit;
}

class Visibility_REST {

  public function __construct() {
    add_action('rest_api_init', [$this, 'register_routes']);
  }

  public function register_routes() {
    register_rest_route('visibility/v1', '/health', [
      'methods'             => 'GET',
      'callback'            => [$this, 'health'],
      'permission_callback' => [$this, 'authenticate'],
    ]);
    register_rest_route('visibility/v1', '/posts', [
      'methods'             => 'POST',
      'callback'            => [$this, 'create_post'],
      'permission_callback' => [$this, 'authenticate'],
    ]);
    register_rest_route('visibility/v1', '/posts/(?P<id>\d+)', [
      'methods'             => 'GET',
      'callback'            => [$this, 'get_post'],
      'permission_callback' => [$this, 'authenticate'],
      'args'                => [
        'id' => ['validate_callback' => fn($v) => is_numeric($v)],
      ],
    ]);
    register_rest_route('visibility/v1', '/posts/(?P<id>\d+)', [
      'methods'             => 'PATCH',
      'callback'            => [$this, 'update_post'],
      'permission_callback' => [$this, 'authenticate'],
      'args'                => [
        'id' => ['validate_callback' => fn($v) => is_numeric($v)],
      ],
    ]);
  }

  public function get_post(WP_REST_Request $request) {
    $id = (int) $request['id'];
    $post = get_post($id);
    if (!$post) {
      return new WP_Error('visibility_no_post', __('Post not found.', 'visibility'), ['status' => 404]);
    }
    return rest_ensure_response($this->serialize_post($post->ID));
  }

  /** Constant-time-ish comparison of the stored token with the bearer header. */
  public function authenticate($request) {
    $header = $request->get_header('authorization');
    if (!$header || stripos($header, 'Bearer ') !== 0) {
      return new WP_Error('visibility_no_auth', __('Missing bearer token.', 'visibility'), ['status' => 401]);
    }
    $token  = trim(substr($header, 7));
    $stored = Visibility_Client::site_token();
    if ($stored === '' || !hash_equals($stored, $token)) {
      return new WP_Error('visibility_bad_token', __('Invalid site token.', 'visibility'), ['status' => 401]);
    }
    return true;
  }

  public function health(WP_REST_Request $request) {
    return rest_ensure_response([
      'ok'             => true,
      'pluginVersion'  => VISIBILITY_PLUGIN_VERSION,
      'wpVersion'      => get_bloginfo('version'),
      'siteName'       => get_bloginfo('name'),
      'siteUrl'        => home_url('/'),
    ]);
  }

  public function create_post(WP_REST_Request $request) {
    $params = $request->get_json_params();
    $title  = isset($params['title']) ? wp_kses_post((string) $params['title']) : '';
    $body   = isset($params['content']) ? wp_kses_post((string) $params['content']) : '';
    $status = isset($params['status']) ? sanitize_key((string) $params['status']) : 'draft';
    $allowed_statuses = ['draft', 'publish', 'pending', 'future', 'private'];
    if (!in_array($status, $allowed_statuses, true)) {
      $status = 'draft';
    }

    $args = [
      'post_title'   => $title,
      'post_content' => $body,
      'post_status'  => $status,
      'post_type'    => 'post',
    ];
    if (!empty($params['excerpt'])) {
      $args['post_excerpt'] = wp_kses_post((string) $params['excerpt']);
    }
    if (!empty($params['slug'])) {
      $args['post_name'] = sanitize_title((string) $params['slug']);
    }

    $post_id = wp_insert_post($args, true);
    if (is_wp_error($post_id)) {
      return $post_id;
    }

    return rest_ensure_response($this->serialize_post($post_id));
  }

  public function update_post(WP_REST_Request $request) {
    $id = (int) $request['id'];
    if (!get_post($id)) {
      return new WP_Error('visibility_no_post', __('Post not found.', 'visibility'), ['status' => 404]);
    }
    $params = $request->get_json_params();
    $args   = ['ID' => $id];
    if (isset($params['title']))   $args['post_title']   = wp_kses_post((string) $params['title']);
    if (isset($params['content'])) $args['post_content'] = wp_kses_post((string) $params['content']);
    if (isset($params['excerpt'])) $args['post_excerpt'] = wp_kses_post((string) $params['excerpt']);
    if (isset($params['slug']))    $args['post_name']    = sanitize_title((string) $params['slug']);
    if (isset($params['status'])) {
      $status = sanitize_key((string) $params['status']);
      if (in_array($status, ['draft', 'publish', 'pending', 'future', 'private'], true)) {
        $args['post_status'] = $status;
      }
    }
    $updated = wp_update_post($args, true);
    if (is_wp_error($updated)) {
      return $updated;
    }
    return rest_ensure_response($this->serialize_post($id));
  }

  private function serialize_post($post_id) {
    $post = get_post($post_id);
    if (!$post) {
      return null;
    }
    // Fresh posts can have a zeroed post_modified_gmt before WP normalises
    // it — guard against the "-001-11-30T00:00:00+00:00" mysql2date returns
    // for an empty/zero input by falling back to current time.
    $modifiedGmt = $post->post_modified_gmt;
    if (empty($modifiedGmt) || $modifiedGmt === '0000-00-00 00:00:00') {
      $modifiedGmt = current_time('mysql', true);
    }
    return [
      'id'       => (int) $post->ID,
      'status'   => $post->post_status,
      'title'    => $post->post_title,
      'slug'     => $post->post_name,
      // get_permalink returns the public URL — for drafts this 404s
      // for anonymous visitors. Always include the wp-admin edit URL
      // too so the agent + user have something they can actually open.
      'link'     => get_permalink($post),
      'editUrl'  => admin_url('post.php?post=' . $post->ID . '&action=edit'),
      'modified' => mysql2date('c', $modifiedGmt, false),
    ];
  }
}
