<?php
/**
 * Talks to the Visibility backend.
 *
 * Storage:
 *   visibility_site_token   — long-lived shared secret returned from /pair
 *   visibility_project_id   — project this site is paired to
 *   visibility_project_name — human-readable project name (refreshed on heartbeat)
 *   visibility_company_id   — company that owns the project
 *   visibility_company_name — human-readable company name (refreshed on heartbeat)
 *   visibility_paired_at    — unix timestamp of pairing
 *   visibility_last_seen_at — unix timestamp of the last successful heartbeat
 */

if (!defined('ABSPATH')) {
  exit;
}

class Visibility_Client {

  /** Exchange a pairing code for a long-lived site token. */
  public static function pair($code) {
    $code = trim((string) $code);
    if ($code === '') {
      return new WP_Error('visibility_no_code', __('Please enter a pairing code.', 'agentic-seo-for-visibility'));
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
      $msg = isset($body['error']['message']) ? $body['error']['message'] : __('Pairing failed.', 'agentic-seo-for-visibility');
      return new WP_Error('visibility_pair_failed', $msg);
    }

    if (empty($body['siteToken']) || empty($body['projectId'])) {
      return new WP_Error('visibility_pair_bad_response', __('Unexpected response from Visibility.', 'agentic-seo-for-visibility'));
    }

    update_option('visibility_site_token', $body['siteToken'], false);
    update_option('visibility_project_id', $body['projectId'], false);
    update_option('visibility_project_name', isset($body['projectName']) ? (string) $body['projectName'] : '', false);
    update_option('visibility_company_id', $body['companyId'] ?? '', false);
    update_option('visibility_company_name', isset($body['companyName']) ? (string) $body['companyName'] : '', false);
    update_option('visibility_paired_at', time(), false);
    update_option('visibility_last_seen_at', time(), false);

    return [
      'projectId'   => $body['projectId'],
      'projectName' => $body['projectName'] ?? null,
      'companyId'   => $body['companyId'] ?? null,
      'companyName' => $body['companyName'] ?? null,
    ];
  }

  /** Forget the current pairing.
   *
   *  We notify Visibility's server first (so it removes the project's
   *  site row and stops dispatching to us) and then wipe local options.
   *  The server call is best-effort: even if Visibility is unreachable
   *  the local teardown still completes — the user can always re-pair
   *  later, and a stale row on the server gets cleaned up the next
   *  time they pair (which replaces the existing row). */
  public static function disconnect() {
    $token = self::site_token();
    if ($token !== '') {
      wp_remote_post(visibility_api_base_url() . '/api/wordpress/plugin/disconnect', [
        'timeout' => 10,
        'headers' => [
          'Authorization' => 'Bearer ' . $token,
          'Content-Type'  => 'application/json',
          'Accept'        => 'application/json',
          'User-Agent'    => 'Visibility-WP-Plugin/' . VISIBILITY_PLUGIN_VERSION,
        ],
        'body' => wp_json_encode([
          'pluginVersion' => VISIBILITY_PLUGIN_VERSION,
        ]),
        // Block briefly so the user sees an immediate "Disconnected"
        // confirmation, but never let server downtime stop the local
        // disconnect — any non-2xx is treated as success here.
      ]);
    }
    delete_option('visibility_site_token');
    delete_option('visibility_project_id');
    delete_option('visibility_project_name');
    delete_option('visibility_company_id');
    delete_option('visibility_company_name');
    delete_option('visibility_paired_at');
    delete_option('visibility_last_seen_at');
  }

  public static function is_paired() {
    $token = get_option('visibility_site_token');
    return !empty($token);
  }

  public static function site_token() {
    return get_option('visibility_site_token', '');
  }

  /** Daily ping so Visibility can show "last seen" + the installed
   *  plugin version. Also refreshes the cached company + project
   *  names so the settings page stays in sync with renames upstream.
   *  Silently no-ops when the plugin isn't paired. */
  public static function heartbeat() {
    if (!self::is_paired()) {
      return;
    }
    $resp = wp_remote_post(visibility_api_base_url() . '/api/wordpress/plugin/heartbeat', [
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
    ]);
    if (is_wp_error($resp)) {
      return;
    }
    $status = wp_remote_retrieve_response_code($resp);
    if ($status < 200 || $status >= 300) {
      return;
    }
    $body = json_decode(wp_remote_retrieve_body($resp), true);
    if (!is_array($body)) {
      return;
    }
    if (isset($body['projectName'])) {
      update_option('visibility_project_name', (string) $body['projectName'], false);
    }
    if (isset($body['companyName'])) {
      update_option('visibility_company_name', (string) $body['companyName'], false);
    }
    update_option('visibility_last_seen_at', time(), false);
  }
}
