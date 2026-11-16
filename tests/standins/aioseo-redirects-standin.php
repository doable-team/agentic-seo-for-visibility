<?php
/**
 * Plugin Name: AIOSEO Redirection Manager STAND-IN (Visibility tests only, not All in One SEO Pro)
 * Description: Test-only stand-in with the parts of AIOSEO Pro's Redirection
 * Manager add-on (aioseo-redirects) that Visibility uses, as its public code
 * shows them: the {prefix}aioseo_redirects table (source_url, source_url_hash =
 * sha1(source), source_url_match, target_url, type, query_param, group, regex,
 * ignore_slash, ignore_case, enabled), the model
 * AIOSEO\Plugin\Addon\Redirects\Models\Redirect with set()/save()/delete()/
 * exists(), getRedirectBySourceUrl(), getRedirectById(), and aioseoRedirects()
 * with ->server->rewrite() and ->cache->clearRedirects(). Redirects are served
 * on template_redirect like the add-on's PHP mode. Never shipped.
 */
namespace AIOSEO\Plugin\Addon\Redirects\Models {
  class Redirect {
    public $id; public $source_url = ''; public $target_url = ''; public $type = 301; public $group = 'manual'; public $regex = 0; public $enabled = 1;
    public $query_param = 'ignore'; public $ignore_slash = 1; public $ignore_case = 1; public $custom_rules = null; public $post_id = null;
    public function __construct($row = null) { if (is_object($row)) foreach ($row as $k => $v) $this->$k = $v; }
    public static function table() { global $wpdb; return $wpdb->prefix . 'aioseo_redirects'; }
    public static function norm($u) { return preg_replace('/\s/', '', (string) $u); }
    public function exists() { return !empty($this->id); }
    public function set($data) { foreach ($data as $k => $v) $this->$k = $v; }
    public function save() {
      global $wpdb;
      $src = $this->regex ? $this->source_url : self::norm($this->source_url);
      $match = $this->regex ? 'regex' : untrailingslashit(explode('?', $src)[0]);
      $row = ['source_url' => $src, 'source_url_hash' => sha1($src), 'source_url_match' => $match, 'source_url_match_hash' => sha1($match),
        'target_url' => (string) $this->target_url, 'target_url_hash' => sha1((string) $this->target_url), 'type' => (int) $this->type,
        'query_param' => $this->query_param, 'group' => $this->group, 'regex' => (int) (bool) $this->regex, 'ignore_slash' => (int) (bool) $this->ignore_slash,
        'ignore_case' => (int) (bool) $this->ignore_case, 'enabled' => (int) (bool) $this->enabled, 'custom_rules' => $this->custom_rules ? wp_json_encode($this->custom_rules) : null,
        'updated' => current_time('mysql', true)];
      if ($this->id) $wpdb->update(self::table(), $row, ['id' => $this->id]);
      else { $row['created'] = $row['updated']; $wpdb->insert(self::table(), $row); $this->id = $wpdb->insert_id; }
    }
    public function delete() { global $wpdb; $wpdb->delete(self::table(), ['id' => $this->id]); $this->id = null; }
    public static function getRedirectBySourceUrl($url, $exclude = null) {
      global $wpdb;
      $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE source_url_hash = %s' . ($exclude ? ' AND id != ' . (int) $exclude : ''), sha1(self::norm($url))));
      return new self($row);
    }
    public static function getRedirectById($id) {
      global $wpdb;
      return new self($wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE id = %d', $id)));
    }
  }
}
namespace {
  define('AIOSEO_REDIRECTION_MANAGER_VERSION', '1.4.0-standin');
  class Vis_Standin_Aioseo_Server { public $rewrites = 0; public function rewrite() { $this->rewrites++; } }
  class Vis_Standin_Aioseo_Cache { public function clearRedirects() {} }
  function aioseoRedirects() {
    static $o; if (!$o) { $o = new stdClass(); $o->server = new Vis_Standin_Aioseo_Server(); $o->cache = new Vis_Standin_Aioseo_Cache(); }
    return $o;
  }
  add_action('init', function () {
    global $wpdb;
    $t = $wpdb->prefix . 'aioseo_redirects';
    if ($wpdb->get_var("SHOW TABLES LIKE '$t'") === $t) return;
    $wpdb->query("CREATE TABLE $t (id bigint(20) unsigned NOT NULL AUTO_INCREMENT, source_url text NOT NULL, source_url_hash varchar(40) NOT NULL,
      source_url_match text NOT NULL, source_url_match_hash varchar(40) NOT NULL, target_url text NOT NULL, target_url_hash varchar(40) NOT NULL,
      type int(11) unsigned NOT NULL DEFAULT 301, query_param varchar(40) NOT NULL DEFAULT 'ignore', `group` varchar(256) NOT NULL DEFAULT 'manual',
      regex tinyint(1) unsigned NOT NULL DEFAULT 0, ignore_slash tinyint(1) unsigned NOT NULL DEFAULT 1, ignore_case tinyint(1) unsigned NOT NULL DEFAULT 1,
      enabled tinyint(1) unsigned NOT NULL DEFAULT 1, custom_rules text DEFAULT NULL, created datetime NOT NULL, updated datetime NOT NULL,
      PRIMARY KEY (id), UNIQUE KEY ndx_aioseo_redirects_source_url_hash (source_url_hash))");
    $wpdb->query("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}aioseo_redirects_hits (id bigint(20) unsigned NOT NULL AUTO_INCREMENT, redirect_id bigint(20) unsigned NOT NULL, count bigint(20) unsigned NOT NULL DEFAULT 0, PRIMARY KEY (id))");
  });
  add_action('template_redirect', function () {
    global $wpdb;
    $path = untrailingslashit(explode('?', wp_unslash($_SERVER['REQUEST_URI']))[0]);
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}aioseo_redirects WHERE source_url_match_hash = %s AND enabled = 1", sha1($path)));
    if (!$row) return;
    if ((int) $row->type === 410) { status_header(410); exit; }
    wp_redirect(preg_match('#^https?://#', $row->target_url) ? $row->target_url : home_url($row->target_url), (int) $row->type, 'AIOSEO');
    exit;
  }, 1);
}
