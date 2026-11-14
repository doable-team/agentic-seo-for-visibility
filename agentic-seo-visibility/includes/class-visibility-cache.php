<?php
/**
 * Purge the page cache of the page Visibility just changed (#293).
 *
 * One purger per cache plugin. Each one only clears the changed post's URL
 * (plus what that plugin itself ties to the post, such as its feed), never
 * the whole site. The result lists the caches that were cleared by name, so
 * the owner can see that the live page is fresh:  cachePurged: ["WP Rocket"].
 */

if (!defined('ABSPATH')) {
  exit;
}

class Visibility_Cache {

  /**
   * slug => [name, detect, purge]. detect() says the cache is active; purge($post_id, $url)
   * clears it for that post and returns true when it did.
   */
  public static function purgers() {
    $p = [
      'wp-rocket' => [
        'WP Rocket',
        function () { return function_exists('rocket_clean_post'); },
        function ($id, $url) { rocket_clean_post($id); return true; },
      ],
      // WP Super Cache: the post's own page (and what WPSC ties to it).
      'wp-super-cache' => [
        'WP Super Cache',
        function () { return function_exists('wp_cache_post_change'); },
        function ($id, $url) {
          wp_cache_post_change($id);
          if ($url !== '' && function_exists('wpsc_delete_url_cache')) wpsc_delete_url_cache($url);
          return true;
        },
      ],
      // Cloudflare (official plugin, incl. APO): purge the post's URLs through
    ];
    /**
     * Add a purger: $purgers['slug'] = [name, detect callable, purge callable($post_id, $url)].
     */
    return apply_filters('visibility_cache_purgers', $p);
  }

  /** Slugs of the caches the plugin can purge on this site right now. */
  public static function active() {
    $out = [];
    foreach (self::purgers() as $slug => $def) {
      try {
        if (call_user_func($def[1])) $out[] = $slug;
      } catch (\Throwable $e) {
        // A broken cache plugin must never break the change itself.
      }
    }
    return $out;
  }

  /**
   * Purge every active cache for $post_id.
   *
   * @return string[] names of the caches that were purged ([] = none found)
   */
  public static function purge($post_id) {
    $post_id = (int) $post_id;
    if ($post_id <= 0) return [];
    clean_post_cache($post_id);
    $url = get_permalink($post_id);
    $done = [];
    foreach (self::purgers() as $slug => $def) {
      try {
        if (!call_user_func($def[1])) continue;
        if (call_user_func($def[2], $post_id, $url ?: '')) $done[] = $def[0];
      } catch (\Throwable $e) {
        // Reported as not purged; the change itself already succeeded.
      }
    }
    /** After the purge, for host caches or custom setups. */
    do_action('visibility_cache_purged', $post_id, $done);
    return array_values(array_unique($done));
  }
}
