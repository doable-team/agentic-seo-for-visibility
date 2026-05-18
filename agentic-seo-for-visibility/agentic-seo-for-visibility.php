<?php
/**
 * Plugin Name: Agentic SEO for Visibility
 * Plugin URI: https://app.visibility.so
 * Description: Connect your WordPress site to the Visibility service at https://app.visibility.so so AI agents in your Visibility project can audit and publish content directly. Every write action is gated by an approval inbox inside WP admin — pair once with a short code, then review what the AI wants to do before it happens.
 * Version: 0.7.0
 * Author: rankth
 * Author URI: https://visibility.so
 * License: GPL v2 or later
 * Text Domain: agentic-seo-for-visibility
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
  exit;
}

define('VISIBILITY_PLUGIN_VERSION', '0.7.0');
define('VISIBILITY_PLUGIN_FILE', __FILE__);
define('VISIBILITY_PLUGIN_DIR', plugin_dir_path(__FILE__));

// Server URL is filterable so we can point a staging plugin install at a
// non-prod backend without forking the code. Defaults to production.
function visibility_api_base_url() {
  return apply_filters('visibility_api_base_url', 'https://l63oxw4o9ghf.shares.zrok.io');
}

require_once VISIBILITY_PLUGIN_DIR . 'includes/class-visibility-client.php';
require_once VISIBILITY_PLUGIN_DIR . 'includes/class-visibility-settings.php';
require_once VISIBILITY_PLUGIN_DIR . 'includes/class-visibility-rest.php';
require_once VISIBILITY_PLUGIN_DIR . 'includes/class-visibility-executor.php';
require_once VISIBILITY_PLUGIN_DIR . 'includes/class-visibility-requests.php';

add_action('plugins_loaded', function () {
  new Visibility_Settings();
  new Visibility_REST();
  Visibility_Requests::init();
});

// Register the 5-minute schedule the action-request cron uses.
add_filter('cron_schedules', ['Visibility_Requests', 'register_5min_schedule']);

// Heartbeat: ping Visibility once a day so the dashboard can show a
// fresh "Last seen" timestamp + the installed plugin version. WP-Cron
// runs on page loads, so this is best-effort, not guaranteed.
register_activation_hook(__FILE__, function () {
  if (!wp_next_scheduled('visibility_daily_heartbeat')) {
    wp_schedule_event(time() + 60, 'daily', 'visibility_daily_heartbeat');
  }
  Visibility_Requests::activate_cron();
});
register_deactivation_hook(__FILE__, function () {
  $ts = wp_next_scheduled('visibility_daily_heartbeat');
  if ($ts) {
    wp_unschedule_event($ts, 'visibility_daily_heartbeat');
  }
  Visibility_Requests::deactivate_cron();
});
add_action('visibility_daily_heartbeat', function () {
  Visibility_Client::heartbeat();
});
