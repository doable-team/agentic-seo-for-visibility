<?php
/**
 * The site owner's switches, enforced by the plugin itself.
 *
 * Visibility sends them (`/events/site-config-changed`, heartbeat replies);
 * the plugin checks them before every write so a server bug can never
 * publish on a site where publishing is off, or write while it is paused.
 *
 *   visibility_allow_publish  '0' = publishing off. Never set = allowed.
 *   visibility_disabled       '1' = paused: every write refused.
 */

if (!defined('ABSPATH')) {
  exit;
}

class Visibility_Policy {

  /** Executor actions that make something public, remove it, or change when it goes live. */
  const PUBLISH_ACTIONS = [
    'post.publish', 'page.publish', 'post.schedule', 'post.unpublish',
    'post.trash', 'post.restore', 'post.delete_permanent', 'media.delete',
    'category.delete', 'tag.delete', 'redirect.create', 'redirect.delete',
  ];

  /** Post statuses that only a publish-allowed site may set. */
  const PUBLISH_STATUSES = ['publish', 'future', 'private'];

  public static function publishing_allowed() {
    return get_option('visibility_allow_publish', '') !== '0';
  }

  public static function paused() {
    return get_option('visibility_disabled', '') === '1';
  }

  /** Store flags from a server message (absent keys are left alone). */
  public static function apply_flags($body) {
    if (!is_array($body)) return;
    if (array_key_exists('allowPublish', $body) && $body['allowPublish'] !== null) {
      update_option('visibility_allow_publish', $body['allowPublish'] ? '1' : '0', false);
    }
    if (array_key_exists('disabled', $body) && $body['disabled'] !== null) {
      update_option('visibility_disabled', $body['disabled'] ? '1' : '0', false);
    }
  }

  /**
   * Why an executor action may not run, or null when it may.
   *
   * @return array|null [code, message]
   */
  public static function check_action($action_type, $payload) {
    if (self::paused()) {
      return ['paused', 'Visibility is paused for this site, so the plugin refused the change.'];
    }
    if (!self::publishing_allowed()) {
      if (in_array($action_type, self::PUBLISH_ACTIONS, true)) {
        return ['publishing_off', 'Publishing is turned off for this site, so the plugin refused this action.'];
      }
      $status = is_array($payload) && isset($payload['status']) ? (string) $payload['status'] : '';
      if (in_array($status, self::PUBLISH_STATUSES, true)) {
        return ['publishing_off', 'Publishing is turned off for this site, so the plugin refused to set the status to ' . $status . '.'];
      }
    }
    return null;
  }

  /**
   * Same check for the REST write routes.
   *
   * @return true|WP_Error
   */
  public static function check_rest($request) {
    $method = strtoupper($request->get_method());
    if ($method === 'GET' || $method === 'HEAD') return true;
    if (self::paused()) {
      return new WP_Error('visibility_paused', __('Visibility is paused for this site, so the plugin refused the change.', 'agentic-seo-visibility'), ['status' => 403]);
    }
    if (self::publishing_allowed()) return true;
    $route = $request->get_route();
    $params = $request->get_json_params();
    $status = is_array($params) && isset($params['status']) ? sanitize_key((string) $params['status']) : '';
    $is_post_route = (bool) preg_match('#^/visibility/v1/posts#', $route);
    if ($is_post_route && ($method === 'DELETE' || in_array($status, self::PUBLISH_STATUSES, true))) {
      return new WP_Error('visibility_publishing_off', __('Publishing is turned off for this site, so the plugin refused this change.', 'agentic-seo-visibility'), ['status' => 403]);
    }
    return true;
  }
}
