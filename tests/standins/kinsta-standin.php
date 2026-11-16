<?php
/**
 * Plugin Name: Kinsta must-use plugin STAND-IN (Visibility tests only, not Kinsta's)
 * Description: Test-only stand-in with the shape of Kinsta's must-use plugin
 * (kinsta-mu-plugins, cache/class-cache-purge.php) that Visibility and WP
 * Rocket's Kinsta integration use: KINSTAMU_VERSION, global $kinsta_cache with
 * ->config['immediate_path'|'throttled_path'] and ->kinsta_cache_purge, whose
 * initiate_purge($post_id) builds the post's purge list and POSTs it to the
 * cache server, returning ['requests' => ..., 'response' => ['immediate' =>
 * ['response_code' => ...], 'throttled' => ...]]. Here the "cache server" is
 * a log in the option vis_test_purges; option vis_test_kinsta_code sets the
 * response code (default 200). Never shipped in the plugin zip.
 */
define('KINSTAMU_VERSION', '3.2.0-standin');

class Vis_Standin_Kinsta_Cache_Purge {
  public $kinsta_cache;
  public function __construct($cache) { $this->kinsta_cache = $cache; }
  public function initiate_purge($post_id) {
    if ((defined('KINSTAMU_DISABLE_AUTOPURGE') && KINSTAMU_DISABLE_AUTOPURGE === true) || get_option('kinsta-autopurge-status') === 'disabled') return false;
    $post = get_post($post_id);
    if (false === is_post_type_viewable($post->post_type)) return;
    $req = ['immediate' => ['group|singular_post' => str_replace(['http://', 'https://'], '', get_permalink($post_id))], 'throttled' => []];
    $code = (int) get_option('vis_test_kinsta_code', 200);
    $log = get_option('vis_test_purges', []);
    $log[] = ['cache' => 'kinsta', 'post' => (int) $post_id, 'url' => get_permalink($post_id), 'endpoint' => $this->kinsta_cache->config['immediate_path']];
    update_option('vis_test_purges', $log, false);
    return ['requests' => $req, 'response' => ['immediate' => ['response_code' => $code, 'error_code' => 0, 'response_body' => '', 'error_message' => ''], 'throttled' => ['response_code' => $code]]];
  }
}
class Vis_Standin_Kinsta_Cache {
  public $config = ['immediate_path' => 'https://localhost/kinsta-clear-cache/v2/immediate', 'throttled_path' => 'https://localhost/kinsta-clear-cache/v2/throttled'];
  public $kinsta_cache_purge;
  public function __construct() { $this->kinsta_cache_purge = new Vis_Standin_Kinsta_Cache_Purge($this); }
}
$GLOBALS['kinsta_cache'] = new Vis_Standin_Kinsta_Cache();
