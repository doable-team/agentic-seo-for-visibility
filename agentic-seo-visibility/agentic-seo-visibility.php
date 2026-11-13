<?php
/**
 * Plugin Name: Agentic SEO for Visibility
 * Plugin URI: https://visibility.so
 * Description: Connect your WordPress site to Visibility (https://app.visibility.so) so the AI agents in your Visibility project can read, draft and improve your content. Pair once with a short code; every publish and every change to a live page is approved by a person in Visibility first.
 * Version: 0.9.1
 * Author: rankth
 * Author URI: https://visibility.so
 * License: GPL v2 or later
 * Text Domain: agentic-seo-visibility
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
  exit;
}

define('VISIBILITY_PLUGIN_VERSION', '0.9.1');
define('VISIBILITY_PLUGIN_FILE', __FILE__);
define('VISIBILITY_PLUGIN_DIR', plugin_dir_path(__FILE__));

// Server URL is filterable so we can point a staging plugin install at a
// non-prod backend without forking the code. Defaults to production. This must
// be the final address: WordPress's HTTP client can turn a POST into a GET when
// it follows a 301/302, so a redirecting base silently breaks pairing and
// result reports.
function visibility_api_base_url() {
  return untrailingslashit(apply_filters('visibility_api_base_url', 'https://app.visibility.so'));
}

require_once VISIBILITY_PLUGIN_DIR . 'includes/class-visibility-client.php';
require_once VISIBILITY_PLUGIN_DIR . 'includes/class-visibility-content.php';
require_once VISIBILITY_PLUGIN_DIR . 'includes/class-visibility-policy.php';
require_once VISIBILITY_PLUGIN_DIR . 'includes/class-visibility-links.php';
require_once VISIBILITY_PLUGIN_DIR . 'includes/class-visibility-settings.php';
require_once VISIBILITY_PLUGIN_DIR . 'includes/class-visibility-rest.php';
require_once VISIBILITY_PLUGIN_DIR . 'includes/class-visibility-executor.php';
require_once VISIBILITY_PLUGIN_DIR . 'includes/class-visibility-requests.php';
require_once VISIBILITY_PLUGIN_DIR . 'includes/class-visibility-seo.php';
require_once VISIBILITY_PLUGIN_DIR . 'includes/class-visibility-read.php';
require_once VISIBILITY_PLUGIN_DIR . 'includes/class-visibility-seo-head.php';
require_once VISIBILITY_PLUGIN_DIR . 'includes/class-visibility-schema.php';

add_action('plugins_loaded', function () {
  new Visibility_Settings();
  new Visibility_REST();
  Visibility_Requests::init();
  // Emits SEO tags itself while no SEO plugin is installed; silent once one is.
  Visibility_SEO_Head::init();
  // Merges stored JSON-LD into the active SEO plugin's own schema graph.
  Visibility_Schema::init();
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
  // Every occurrence, not just the next one.
  wp_clear_scheduled_hook('visibility_daily_heartbeat');
  wp_clear_scheduled_hook(Visibility_Requests::CRON_HOOK);
});
add_action('visibility_daily_heartbeat', function () {
  Visibility_Client::heartbeat();
});
