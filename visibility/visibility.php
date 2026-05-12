<?php
/**
 * Plugin Name: Visibility
 * Plugin URI: https://app.visibility.so
 * Description: Connect your WordPress site to Visibility so your agents can audit and publish content directly. Pair once with a short code — no app passwords required.
 * Version: 0.3.0
 * Author: Visibility Team
 * Author URI: https://app.visibility.so
 * License: GPL v2 or later
 * Text Domain: visibility
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
  exit;
}

define('VISIBILITY_PLUGIN_VERSION', '0.3.0');
define('VISIBILITY_PLUGIN_FILE', __FILE__);
define('VISIBILITY_PLUGIN_DIR', plugin_dir_path(__FILE__));

// Server URL is filterable so we can point a staging plugin install at a
// non-prod backend without forking the code. Defaults to production.
function visibility_api_base_url() {
  return apply_filters('visibility_api_base_url', 'https://florence-derived-soldiers-cardiovascular.trycloudflare.com'); // https://app.visibility.so
}

require_once VISIBILITY_PLUGIN_DIR . 'includes/class-visibility-client.php';
require_once VISIBILITY_PLUGIN_DIR . 'includes/class-visibility-settings.php';
require_once VISIBILITY_PLUGIN_DIR . 'includes/class-visibility-rest.php';

add_action('plugins_loaded', function () {
  new Visibility_Settings();
  new Visibility_REST();
});

// Heartbeat: ping Visibility once a day so the dashboard can show a
// fresh "Last seen" timestamp + the installed plugin version. WP-Cron
// runs on page loads, so this is best-effort, not guaranteed.
register_activation_hook(__FILE__, function () {
  if (!wp_next_scheduled('visibility_daily_heartbeat')) {
    wp_schedule_event(time() + 60, 'daily', 'visibility_daily_heartbeat');
  }
});
register_deactivation_hook(__FILE__, function () {
  $ts = wp_next_scheduled('visibility_daily_heartbeat');
  if ($ts) {
    wp_unschedule_event($ts, 'visibility_daily_heartbeat');
  }
});
add_action('visibility_daily_heartbeat', function () {
  Visibility_Client::heartbeat();
});
