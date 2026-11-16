<?php
/**
 * Plugin Name: WP Rocket STAND-IN (Visibility tests only, not WP Rocket)
 * Description: Test-only stand-in. Defines what the real WP Rocket defines and
 * Visibility uses: WP_ROCKET_VERSION and rocket_clean_post($post_id, $post = null),
 * with WP Rocket's documented behaviour (inc/common/purge.php in
 * github.com/wp-media/wp-rocket): returns false and purges nothing for drafts,
 * auto-drafts, attachments and nav menu items, otherwise fires
 * before_rocket_clean_post / after_rocket_clean_post and returns true. Each purge
 * is logged to the option vis_test_purges. Never shipped in the plugin zip.
 */
define('WP_ROCKET_VERSION', '3.19.0-standin');
function rocket_clean_post($post_id, $post = null) {
  static $done = [];
  if (isset($done[$post_id])) return false;
  $done[$post_id] = 1;
  if (is_null($post)) $post = get_post($post_id);
  if (!is_object($post)) return false;
  if (in_array($post->post_status, ['auto-draft', 'draft'], true) || empty($post->post_type) || in_array($post->post_type, ['nav_menu_item', 'attachment'], true)) return false;
  $urls = [get_permalink($post_id)];
  do_action('before_rocket_clean_post', $post, $urls, '');
  $log = get_option('vis_test_purges', []);
  $log[] = ['cache' => 'wp-rocket', 'post' => (int) $post_id, 'type' => $post->post_type, 'url' => get_permalink($post_id)];
  update_option('vis_test_purges', $log, false);
  do_action('after_rocket_clean_post', $post, $urls, '');
  return true;
}
// As WP Rocket does (inc/common/purge.php): purge on clean_post_cache, trash and delete.
add_action('wp_trash_post', 'rocket_clean_post');
add_action('delete_post', 'rocket_clean_post');
add_action('clean_post_cache', 'rocket_clean_post');
