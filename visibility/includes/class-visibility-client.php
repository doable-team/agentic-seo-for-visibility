<?php
/**
 * Talks to the Visibility backend.
 *
 * Storage:
 *   visibility_site_token  — long-lived shared secret returned from /pair
 *   visibility_project_id  — project this site is paired to
 *   visibility_company_id  — company that owns the project
 *   visibility_paired_at   — unix timestamp of pairing
 */

if (!defined('ABSPATH')) {
  exit;
}

class Visibility_Client {

  /** Exchange a pairing code for a long-lived site token. */
  public static function pair($code) {
    $code = trim((string) $code);
    if ($code === '') {
      return new WP_Error('visibility_no_code', __('Please enter a pairing code.', 'visibility'));
    }

    $resp = wp_remote_post(visibility_api_base_url() . '/api/wordpress/plugin/pair', [
      'timeout' => 20,
      'headers' => [
        'Content-Type' => 'application/json',
        'Accept'       => 'application/json',
        'User-Agent'   => 'Visibility-WP-Plugin/' . VISIBILITY_PLUGIN_VERSION,
      ],
      'body' => wp_json_encode([
        'code'          => $code,
        'siteUrl'       => home_url('/'),
        'siteName'      => get_bloginfo('name'),
        'wpVersion'     => get_bloginfo('version'),
        'pluginVersion' => VISIBILITY_PLUGIN_VERSION,
      ]),
    ]);

    if (is_wp_error($resp)) {
      return $resp;
    }

    $status = wp_remote_retrieve_response_code($resp);
    $body   = json_decode(wp_remote_retrieve_body($resp), true);

    if ($status < 200 || $status >= 300) {
      $msg = isset($body['error']['message']) ? $body['error']['message'] : __('Pairing failed.', 'visibility');
      return new WP_Error('visibility_pair_failed', $msg);
    }

    if (empty($body['siteToken']) || empty($body['projectId'])) {
      return new WP_Error('visibility_pair_bad_response', __('Unexpected response from Visibility.', 'visibility'));
    }

    update_option('visibility_site_token', $body['siteToken'], false);
    update_option('visibility_project_id', $body['projectId'], false);
    update_option('visibility_company_id', $body['companyId'] ?? '', false);
    update_option('visibility_paired_at', time(), false);

    return [
      'projectId' => $body['projectId'],
      'companyId' => $body['companyId'] ?? null,
    ];
  }

  /** Forget the current pairing. The server-side site row is unaffected
   *  — operator should also disconnect from the Visibility dashboard. */
  public static function disconnect() {
    delete_option('visibility_site_token');
    delete_option('visibility_project_id');
    delete_option('visibility_company_id');
    delete_option('visibility_paired_at');
  }

  public static function is_paired() {
    $token = get_option('visibility_site_token');
    return !empty($token);
  }

  public static function site_token() {
    return get_option('visibility_site_token', '');
  }

  /** Daily ping so Visibility can show "last seen" + the installed
   *  plugin version. Silently no-ops when the plugin isn't paired. */
  public static function heartbeat() {
    if (!self::is_paired()) {
      return;
    }
    wp_remote_post(visibility_api_base_url() . '/api/wordpress/plugin/heartbeat', [
      'timeout' => 10,
      'headers' => [
        'Authorization' => 'Bearer ' . self::site_token(),
        'Content-Type'  => 'application/json',
        'Accept'        => 'application/json',
        'User-Agent'    => 'Visibility-WP-Plugin/' . VISIBILITY_PLUGIN_VERSION,
      ],
      'body' => wp_json_encode([
        'pluginVersion' => VISIBILITY_PLUGIN_VERSION,
      ]),
      'blocking' => false,
    ]);
  }
}
