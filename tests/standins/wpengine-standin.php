<?php
/**
 * Plugin Name: WP Engine platform plugin STAND-IN (Visibility tests only, not WP Engine's)
 * Description: Test-only stand-in with the shape of WP Engine's must-use plugin
 * (mu-plugins/wpengine-common/plugin.php): class WpeCommon with static
 * purge_varnish_cache($post_id = null) and purge_memcached(). As in the real
 * one, a post that is an attachment, a revision or not published is not purged
 * (returns null); a published post purges its own path and returns true.
 * Purges are logged to the option vis_test_purges. Never shipped in the plugin zip.
 */
class WpeCommon {
  public static function instance() { static $i; return $i ?: ($i = new self()); }
  public static function purge_memcached() { return true; }
  public static function purge_varnish_cache($post_id = null) {
    if (defined('WPE_DISABLE_CACHE_PURGING') && WPE_DISABLE_CACHE_PURGING) return false;
    if ($post_id && $post_id > 1 && !!($post = get_post($post_id))) {
      if ($post->post_type == 'attachment' || $post->post_type == 'revision') return;
      if ($post->post_status != 'publish') return;
      $path = rtrim((string) wp_parse_url(get_permalink($post_id), PHP_URL_PATH), '/') . '(.*)';
      $log = get_option('vis_test_purges', []);
      $log[] = ['cache' => 'wpengine', 'post' => (int) $post_id, 'path' => $path];
      update_option('vis_test_purges', $log, false);
      return true;
    }
    $log = get_option('vis_test_purges', []);
    $log[] = ['cache' => 'wpengine', 'post' => 0, 'path' => 'ALL'];
    update_option('vis_test_purges', $log, false);
    return true;
  }
}
