<?php
/**
 * Plugin Name: Yoast SEO Premium redirects STAND-IN (Visibility tests only, not Yoast SEO Premium)
 * Description: Test-only stand-in with the parts of Yoast SEO Premium's redirect
 * manager that Visibility uses, as Premium's classes/redirect/* show them:
 * WPSEO_Redirect($origin, $target, $type, $format) with get_origin/get_target/
 * get_type/get_format (plain origins and same-site targets stored relative to
 * home, without surrounding slashes), WPSEO_Redirect_Manager($format) with
 * get_all_redirects(), get_redirect($origin), create_redirect(),
 * delete_redirects([...]), and the option wpseo-premium-redirects-base holding
 * [{origin, url, type, format}]. Plain redirects are served on
 * template_redirect like Premium's PHP redirect mode. Never shipped.
 */
if (!defined('WPSEO_PREMIUM_VERSION')) define('WPSEO_PREMIUM_VERSION', '26.0-standin');

class WPSEO_Redirect {
  private $origin; private $target; private $type; private $format;
  public function __construct($origin, $target = '', $type = 301, $format = 'plain') {
    $this->origin = $format === 'plain' ? self::rel($origin) : $origin;
    $this->target = self::rel_target($target);
    $this->type = (int) $type;
    $this->format = $format;
  }
  private static function rel($url) {
    $home = untrailingslashit(home_url());
    if (stripos($url, $home) === 0) $url = substr($url, strlen($home));
    return $url === '/' ? $url : trim($url, '/');
  }
  private static function rel_target($url) {
    $home = untrailingslashit(home_url());
    if ($url !== '' && stripos($url, $home) === 0) { $url = substr($url, strlen($home)); return $url === '/' || $url === '' ? '/' : trim($url, '/'); }
    if ($url !== '' && $url !== '/' && !preg_match('#^https?://#i', $url)) return trim($url, '/');
    return $url;
  }
  public function get_origin() { return $this->origin; }
  public function get_target() { return $this->target; }
  public function get_type() { return $this->type; }
  public function get_format() { return $this->format; }
  public function origin_is($url) { return (string) $this->origin === (string) ($this->format === 'plain' ? self::rel($url) : $url); }
}

class WPSEO_Redirect_Option {
  const OPTION = 'wpseo-premium-redirects-base';
  public $redirects = [];
  public function __construct() { $this->redirects = $this->get_all(); }
  public function get_all() {
    $out = [];
    foreach ((array) get_option(self::OPTION, []) as $r) $out[] = new WPSEO_Redirect($r['origin'], $r['url'], $r['type'], $r['format']);
    return $out;
  }
  public function search($origin) {
    foreach ($this->redirects as $i => $r) if ($r->origin_is($origin)) return $i;
    return false;
  }
  public function add(WPSEO_Redirect $r) {
    if ($this->search($r->get_origin()) !== false) return false;
    $this->redirects[] = $r; return true;
  }
  public function delete(WPSEO_Redirect $r) {
    $i = $this->search($r->get_origin());
    if ($i === false) return false;
    unset($this->redirects[$i]); $this->redirects = array_values($this->redirects); return true;
  }
  public function get($origin) { $i = $this->search($origin); return $i === false ? false : $this->redirects[$i]; }
  public function save() {
    update_option(self::OPTION, array_map(function ($r) { return ['origin' => $r->get_origin(), 'url' => $r->get_target(), 'type' => $r->get_type(), 'format' => $r->get_format()]; }, $this->redirects), false);
  }
}

class WPSEO_Redirect_Manager {
  private $option; private $format;
  public function __construct($format = 'plain', $exporters = null, $option = null) { $this->format = $format; $this->option = $option ?: new WPSEO_Redirect_Option(); }
  public function get_redirects() { $f = $this->format; return array_filter($this->option->get_all(), function ($r) use ($f) { return $r->get_format() === $f; }); }
  public function get_all_redirects() { return $this->option->get_all(); }
  public function create_redirect(WPSEO_Redirect $r) { if ($this->option->add($r)) { $this->option->save(); return true; } return false; }
  public function delete_redirects($list) {
    $deleted = false;
    foreach ($list as $r) if ($this->option->delete($r)) $deleted = true;
    if ($deleted) $this->option->save();
    return $deleted;
  }
  public function get_redirect($origin) { return $this->option->get($origin); }
}

add_action('template_redirect', function () {
  $path = trim(explode('?', wp_unslash($_SERVER['REQUEST_URI']))[0], '/');
  foreach ((array) get_option(WPSEO_Redirect_Option::OPTION, []) as $r) {
    if ($r['format'] !== 'plain' || $r['origin'] !== $path) continue;
    if ((int) $r['type'] === 410) { status_header(410); exit; }
    $to = preg_match('#^https?://#i', $r['url']) ? $r['url'] : home_url('/' . ltrim($r['url'], '/') . ($r['url'] === '/' ? '' : '/'));
    wp_redirect($to, (int) $r['type'], 'Yoast SEO Premium');
    exit;
  }
}, 1);
