<?php
/**
 * Runs when the plugin is deleted from WP admin.
 *
 * Removes the connection key and every other `visibility_*` option, the
 * plugin's transients and its scheduled events. SEO fields saved on posts
 * (`_visibility_*` post meta) are kept unless the site opted in, because live
 * pages may rely on them: define VISIBILITY_UNINSTALL_DELETE_POST_META as true,
 * or set the option `visibility_uninstall_delete_postmeta` to '1'.
 *
 * On multisite, every site of the network is cleaned.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
  exit;
}

function visibility_uninstall_site() {
  global $wpdb;

  $delete_postmeta = (defined('VISIBILITY_UNINSTALL_DELETE_POST_META') && VISIBILITY_UNINSTALL_DELETE_POST_META)
    || get_option('visibility_uninstall_delete_postmeta') === '1';

  foreach (['visibility_daily_heartbeat', 'visibility_pull_approved_requests'] as $hook) {
    wp_clear_scheduled_hook($hook);
  }

  // Options (incl. per-request records) and transients.
  $like = [
    $wpdb->esc_like('visibility_') . '%',
    $wpdb->esc_like('_transient_visibility_') . '%',
    $wpdb->esc_like('_transient_timeout_visibility_') . '%',
    $wpdb->esc_like('_site_transient_visibility_') . '%',
    $wpdb->esc_like('_site_transient_timeout_visibility_') . '%',
  ];
  foreach ($like as $pattern) {
    $names = $wpdb->get_col($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $pattern));
    foreach ((array) $names as $name) {
      delete_option($name);
    }
  }

  if ($delete_postmeta) {
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $wpdb->esc_like('_visibility_') . '%'));
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->termmeta} WHERE meta_key LIKE %s", $wpdb->esc_like('_visibility_') . '%'));
    wp_cache_flush();
  }
}

if (is_multisite()) {
  $site_ids = get_sites(['fields' => 'ids', 'number' => 0]);
  foreach ($site_ids as $site_id) {
    switch_to_blog($site_id);
    visibility_uninstall_site();
    restore_current_blog();
  }
  // Network-wide copies, if any.
  global $wpdb;
  $names = $wpdb->get_col($wpdb->prepare("SELECT meta_key FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s", $wpdb->esc_like('visibility_') . '%'));
  foreach ((array) $names as $name) {
    delete_site_option($name);
  }
} else {
  visibility_uninstall_site();
}
