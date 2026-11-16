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

  /** Posts WP Rocket purged in this request (its after_rocket_clean_post action). */
  public static $rocket_cleaned = [];

  public static function watch_rocket($post) {
    if (is_object($post) && isset($post->ID)) self::$rocket_cleaned[(int) $post->ID] = true;
  }

  /**
   * slug => [name, detect, purge]. detect() says the cache is active; purge($post_id, $url)
   * clears it for that post and returns true when it did.
   */
  public static function purgers() {
    $p = [
      // WP Rocket (#338): rocket_clean_post() removes the post's cache files
      // (and what WP Rocket ties to it: its archives, feeds, parents). WP
      // Rocket also runs it on clean_post_cache, once per post per request,
      // so the save itself may already have purged it; it skips drafts and
      // autosaves. Purged = after_rocket_clean_post fired for the post.
      'wp-rocket' => [
        'WP Rocket',
        function () { return function_exists('rocket_clean_post'); },
        function ($id, $url) {
          if (rocket_clean_post($id) === true) return true;
          return isset(Visibility_Cache::$rocket_cleaned[(int) $id]);
        },
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
      // W3 Total Cache: page cache (and CDN) for this post only.
      'w3-total-cache' => [
        'W3 Total Cache',
        function () { return function_exists('w3tc_flush_post'); },
        function ($id, $url) {
          w3tc_flush_post($id, true);
          // W3TC queues the purge until shutdown; run it now so the page is
          // fresh when Visibility reports the change as done.
          if (class_exists('\\W3TC\\Dispatcher')) {
            $flush = \W3TC\Dispatcher::component('CacheFlush');
            if ($flush && method_exists($flush, 'execute_delayed_operations')) $flush->execute_delayed_operations();
          }
          return true;
        },
      ],
      // LiteSpeed Cache: purge tag for this post, sent with the response.
      'litespeed-cache' => [
        'LiteSpeed Cache',
        function () { return defined('LSCWP_V') && has_action('litespeed_purge_post'); },
        function ($id, $url) { do_action('litespeed_purge_post', $id); return true; },
      ],
      // WP Fastest Cache: the post's cache file (desktop + mobile).
      'wp-fastest-cache' => [
        'WP Fastest Cache',
        function () { return has_action('wpfc_clear_post_cache_by_id'); },
        function ($id, $url) { do_action('wpfc_clear_post_cache_by_id', false, $id); return true; },
      ],
      // SiteGround Speed Optimizer: dynamic cache for the post's URL.
      'sg-cachepress' => [
        'SiteGround Speed Optimizer',
        function () {
          // Only when one of its page caches is on (dynamic cache on SiteGround
          // hosting, or file-based caching on any host).
          return function_exists('sg_cachepress_purge_cache')
            && (get_option('siteground_optimizer_enable_cache') || get_option('siteground_optimizer_file_caching'));
        },
        function ($id, $url) {
          if ($url === '' || untrailingslashit($url) === untrailingslashit(home_url('/'))) return false; // never the whole site
          return sg_cachepress_purge_cache($url) !== false;
        },
      ],
      // Kinsta (#339): the host's must-use plugin purges the post's URLs on
      // its cache server through $kinsta_cache->kinsta_cache_purge->initiate_purge()
      // (the call WP Rocket's own Kinsta integration makes after rocket_clean_post).
      'kinsta' => [
        'Kinsta cache',
        function () { return Visibility_Cache::kinsta_purger() !== null; },
        function ($id, $url) {
          // Kinsta only caches public pages; its purge doesn't check the status itself.
          if (get_post_status($id) !== 'publish') return false;
          $r = Visibility_Cache::kinsta_purger()->initiate_purge($id);
          $code = is_array($r) && isset($r['response']['immediate']['response_code']) ? (int) $r['response']['immediate']['response_code'] : 0;
          return $code >= 200 && $code < 300;
        },
      ],
      // WP Engine (#339): the platform plugin's WpeCommon::purge_varnish_cache($post_id)
      // purges the post's own paths on the page cache; true when it purged.
      'wpengine' => [
        'WP Engine cache',
        function () { return class_exists('WpeCommon') && method_exists('WpeCommon', 'purge_varnish_cache'); },
        function ($id, $url) { return \WpeCommon::purge_varnish_cache((int) $id) === true; },
      ],
      // Cloudflare (official plugin, incl. APO): purge the post's URLs through
      // the plugin's own API client, only when the plugin is connected.
      'cloudflare' => [
        'Cloudflare',
        function () { return Visibility_Cache::cloudflare_ready(); },
        function ($id, $url) { return Visibility_Cache::cloudflare_purge($id); },
      ],
    ];
    /**
     * Add a purger: $purgers['slug'] = [name, detect callable, purge callable($post_id, $url)].
     */
    return apply_filters('visibility_cache_purgers', $p);
  }

  /** Kinsta's purge object when the Kinsta must-use plugin is loaded with selective purging. */
  public static function kinsta_purger() {
    if (!defined('KINSTAMU_VERSION') || empty($GLOBALS['kinsta_cache']) || !is_object($GLOBALS['kinsta_cache'])) return null;
    $p = isset($GLOBALS['kinsta_cache']->kinsta_cache_purge) ? $GLOBALS['kinsta_cache']->kinsta_cache_purge : null;
    return (is_object($p) && method_exists($p, 'initiate_purge')) ? $p : null;
  }

  // ─── Cloudflare ────────────────────────────────────────────────────────

  /**
   * The official Cloudflare plugin is active and connected (API key or token
   * and the site's zone name saved in it). Without that it purges nothing.
   */
  public static function cloudflare_ready() {
    if (!defined('CLOUDFLARE_PLUGIN_DIR') || !class_exists('\\Cloudflare\\APO\\WordPress\\Hooks')) return false;
    $key = defined('CLOUDFLARE_API_KEY') && CLOUDFLARE_API_KEY !== '' ? CLOUDFLARE_API_KEY : get_option('cloudflare_api_key');
    $zone = defined('CLOUDFLARE_DOMAIN_NAME') && CLOUDFLARE_DOMAIN_NAME !== '' ? CLOUDFLARE_DOMAIN_NAME : get_option('cloudflare_cached_domain_name');
    return !empty($key) && !empty($zone);
  }

  /**
   * Purge the post's URLs (the page plus what the plugin ties to it: home,
   * archives, feeds) through the plugin's own API client, so its zone lookup,
   * credentials (key or token) and APO handling apply. True only when
   * Cloudflare answered success.
   */
  public static function cloudflare_purge($post_id) {
    $ok = null;
    // The plugin reports each purge call through its logger only; watch the
    // HTTP answer of the purge request instead.
    // http_api_debug fires for every finished request, including answers
    // short-circuited by pre_http_request.
    $watch = function ($response, $context, $class, $args, $url) use (&$ok) {
      if ($context !== 'response' || strpos((string) $url, '/purge_cache') === false) return;
      $body = is_array($response) ? json_decode((string) wp_remote_retrieve_body($response), true) : null;
      $good = is_array($body) && !empty($body['success']);
      $ok = ($ok === null ? true : $ok) && $good;
    };
    add_action('http_api_debug', $watch, 10, 5);
    try {
      $hooks = new \Cloudflare\APO\WordPress\Hooks();
      $hooks->purgeCacheByRelevantURLs([(int) $post_id]);
    } catch (\Throwable $e) {
      $ok = false;
    }
    remove_action('http_api_debug', $watch, 10);
    return $ok === true;
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

add_action('after_rocket_clean_post', ['Visibility_Cache', 'watch_rocket']);
