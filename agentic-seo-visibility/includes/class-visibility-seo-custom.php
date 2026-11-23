<?php
/**
 * SEO plugins Visibility doesn't know (2.1.0), e.g. a site's own "Editorial
 * SEO" plugin keeping `_sse_seo_title`, `_sse_seo_description`,
 * `_sse_seo_keyword`, `_sse_seo_canonical`, `_sse_seo_robots_index`,
 * `_sse_seo_robots_follow` as registered post meta.
 *
 * When no known SEO plugin is active, the registered post meta is searched
 * for an "SEO family": keys sharing one prefix whose names end like
 *   title        (seo|meta)_?title
 *   description  (seo|meta)_?(desc|description)
 *   keyword      (seo|focus)_?(keyword|keywords|kw)
 *   canonical    canonical
 *   robotsIndex  robots_?(index|noindex)
 *   robotsFollow robots_?(follow|nofollow)
 * At least a title and a description are needed. Our fields are then written
 * into those keys, and Visibility's own head leaves those tags to the plugin
 * (no tag printed twice). Fields the family has no key for keep Visibility's
 * own keys, as on a site without an SEO plugin.
 */

if (!defined('ABSPATH')) {
  exit;
}

class Visibility_SEO_Adapter_Custom extends Visibility_SEO_Adapter_Theme {

  /** family part => [regex on the key's end, canonical field]. Order matters: first match wins. */
  const PATTERNS = [
    'robotsIndex'  => ['/robots_?(index|noindex)$/', 'robotsNoindex'],
    'robotsFollow' => ['/robots_?(follow|nofollow)$/', 'robotsNofollow'],
    'title'        => ['/(seo|meta)_?(title)$/', 'metaTitle'],
    'description'  => ['/(seo|meta)_?(desc|description)$/', 'metaDescription'],
    'keyword'      => ['/(seo|focus)_?(keyword|keywords|kw)$/', 'focusKeyword'],
    'canonical'    => ['/canonical$/', 'canonical'],
  ];

  /** What each family part makes the plugin print in the head. */
  const PRINTS = ['title' => 'title', 'description' => 'description', 'canonical' => 'canonical', 'robotsIndex' => 'robots', 'robotsFollow' => 'robots'];

  private static $cache = [];
  private $family;

  public function __construct(array $family) { $this->family = $family; }

  // ─── Detection ─────────────────────────────────────────────────────────

  /**
   * The SEO family registered for a post type ('' = registered for every
   * type), or null. {prefix, fields:{title, description, keyword?, canonical?,
   * robotsIndex?, robotsFollow?} (part => meta key), og: bool}
   */
  public static function family($type) {
    $type = (string) $type;
    if (!did_action('init')) return null;
    if (array_key_exists($type, self::$cache)) return self::$cache[$type];
    $keys = array_keys(array_merge(get_registered_meta_keys('post', ''), $type !== '' ? get_registered_meta_keys('post', $type) : []));
    $groups = [];
    foreach ($keys as $key) {
      $k = (string) $key;
      $lk = strtolower($k);
      if (class_exists('Visibility_Meta') && (Visibility_Meta::is_seo_plugin($k) || Visibility_Meta::excluded($k))) continue;
      foreach (self::PATTERNS as $part => $def) {
        if (!preg_match($def[0], $lk, $m, PREG_OFFSET_CAPTURE)) continue;
        // Prefix = the key without its last word (title, description, keyword, canonical, robots_index…).
        $tail = $part === 'canonical' ? 'canonical' : (in_array($part, ['robotsIndex', 'robotsFollow'], true) ? $m[0][0] : $m[count($m) - 1][0]);
        if ($part === 'keyword') $tail = $m[2][0];
        $prefix = substr($k, 0, strlen($k) - strlen($tail));
        if ($prefix === '' || isset($groups[$prefix][$part])) break;
        $groups[$prefix][$part] = $k;
        break;
      }
    }
    $best = null;
    foreach ($groups as $prefix => $fields) {
      if (!isset($fields['title'], $fields['description'])) continue;
      if ($best === null || count($fields) > count($best['fields'])) $best = ['prefix' => (string) $prefix, 'fields' => $fields];
    }
    if ($best) {
      $og = false;
      foreach ($keys as $key) {
        if (strpos((string) $key, $best['prefix']) === 0 && preg_match('/(^|_)(og|opengraph|open_graph|social)_/', strtolower(substr((string) $key, strlen($best['prefix']) - 1)))) $og = true;
      }
      $best['og'] = $og;
    }
    /** Lets a site turn the detection off or point it at its own keys. */
    $best = apply_filters('visibility_custom_seo_family', $best, $type);
    return self::$cache[$type] = (is_array($best) && !empty($best['prefix']) && !empty($best['fields'])) ? $best : null;
  }

  /** The family for the site: posts first, then pages, then other types. */
  public static function site_family() {
    // Plugins register their meta on init: before that nothing is known (and nothing is cached).
    if (!did_action('init')) return null;
    if (array_key_exists("\0site", self::$cache)) return self::$cache["\0site"];
    $types = ['post', 'page'];
    if (class_exists('Visibility_Read')) $types = array_unique(array_merge($types, array_keys(Visibility_Read::readable_types())));
    $found = null;
    foreach ($types as $t) {
      $found = self::family($t);
      if ($found) break;
    }
    return self::$cache["\0site"] = $found;
  }

  public static function reset() { self::$cache = []; }

  /** capabilities.seoPlugin for this site. */
  public function describe() {
    return [
      'slug'   => 'custom',
      'name'   => $this->name(),
      'prefix' => $this->family['prefix'],
      'fields' => (object) $this->family['fields'],
      'prints' => $this->printed_parts($this->family),
    ];
  }

  // ─── Adapter ───────────────────────────────────────────────────────────

  public function slug() { return 'custom:' . $this->family['prefix']; }
  public function prefix() { return $this->family['prefix']; }
  public function name() { return 'SEO fields ' . $this->family['prefix'] . '*'; }
  protected function theme_name() { return 'Custom SEO plugin'; }
  public function version() { return null; }
  public function rendered_by() { return $this->family['prefix'] . ' plugin'; }

  /** The family registered for the post's type (null: Visibility's own keys there). */
  private function family_for($post_id) {
    $type = get_post_type($post_id);
    return $type ? self::family($type) : null;
  }

  private function printed_parts($fam) {
    $p = [];
    if (!$fam) return $p;
    foreach (array_keys($fam['fields']) as $part) if (isset(self::PRINTS[$part])) $p[] = self::PRINTS[$part];
    if (!empty($fam['og'])) { $p[] = 'og'; $p[] = 'twitter'; }
    return array_values(array_unique($p));
  }

  /**
   * Head parts the plugin prints for the current request: on a post of a type
   * the family is registered for. Archives keep Visibility's own tags (the
   * plugin has no term fields Visibility knows of).
   */
  public function printed() {
    if (function_exists('is_singular') && is_singular()) {
      $id = get_queried_object_id();
      return $this->printed_parts($id ? $this->family_for($id) : null);
    }
    return [];
  }

  /** Fields the plugin prints itself from its own data (OG when it has OG fields). */
  protected function excluded() {
    return !empty($this->family['og']) ? ['ogTitle', 'ogDescription', 'ogImage', 'twitterTitle', 'twitterDescription', 'twitterImage'] : [];
  }

  /** Canonical fields kept in the plugin's keys. */
  protected function owned() { return $this->owned_by($this->family); }

  private function owned_by($fam) {
    if (!$fam) return [];
    $out = [];
    foreach (array_keys($fam['fields']) as $part) $out[] = self::PATTERNS[$part][1];
    if (isset($fam['fields']['keyword'])) $out[] = 'secondaryKeywords';
    return array_values(array_unique($out));
  }

  public function fields() { return array_values(array_unique(array_merge(parent::fields(), $this->owned()))); }

  /** focusKeyword "a, b" without secondaryKeywords: primary "a", secondaries ["b"]. */
  public function normalize(array $fields) {
    if (isset($fields['focusKeyword']) && is_string($fields['focusKeyword']) && strpos($fields['focusKeyword'], ',') !== false && !array_key_exists('secondaryKeywords', $fields)) {
      $list = self::split_keywords($fields['focusKeyword']);
      $fields['focusKeyword'] = (string) array_shift($list);
      $fields['secondaryKeywords'] = $list;
    }
    return $fields;
  }

  private static function split_keywords($s) {
    $out = [];
    foreach (explode(',', (string) $s) as $k) {
      $k = trim(sanitize_text_field($k));
      if ($k !== '') $out[] = $k;
    }
    return $out;
  }

  /** Part => meta key for a canonical field. */
  private static function part_of($field) {
    foreach (self::PATTERNS as $part => $def) if ($def[1] === $field) return $part;
    return $field === 'secondaryKeywords' ? 'keyword' : null;
  }

  // Robots values: the vocabulary is learned from what the site already stores.

  private static function seen_values($key) {
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- a few distinct values of one key, to store robots the way the plugin does.
    $v = $wpdb->get_col($wpdb->prepare("SELECT DISTINCT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value <> '' LIMIT 10", $key));
    return array_map('strtolower', (array) $v);
  }

  /** Is the "no" directive (noindex / nofollow) on? */
  private static function robots_on($key, $v, $word) {
    $v = strtolower(trim(is_scalar($v) ? (string) $v : ''));
    if ($v === '') return false;
    $negative_key = (bool) preg_match('/robots_?no' . $word . '$/', strtolower($key));
    if ($negative_key) return in_array($v, ['1', 'yes', 'true', 'on', 'no' . $word], true);
    return in_array($v, ['no' . $word, '0', 'no', 'false', 'off'], true);
  }

  /** Value to store; '' = delete (the plugin's default: index / follow). */
  private static function robots_value($key, $on, $type, $word) {
    if (!$on) return '';
    $negative_key = (bool) preg_match('/robots_?no' . $word . '$/', strtolower($key));
    if ($type === 'boolean') return $negative_key ? '1' : '0';
    if ($type === 'integer' || $type === 'number') return $negative_key ? 1 : 0;
    $seen = self::seen_values($key);
    if ($negative_key) {
      foreach (['1', 'yes', 'true', 'on', 'no' . $word] as $c) if (in_array($c, $seen, true)) return $c;
      return '1';
    }
    foreach (['no' . $word, '0', 'no', 'false', 'off'] as $c) if (in_array($c, $seen, true)) return $c;
    return 'no' . $word;
  }

  protected function theme_get($post_id, $f) {
    $fam = $this->family_for($post_id);
    $part = self::part_of($f);
    if (!$fam || !$part || !isset($fam['fields'][$part])) return in_array($f, self::BOOL_FIELDS, true) ? false : '';
    $key = $fam['fields'][$part];
    $raw = get_post_meta($post_id, $key, true);
    if ($f === 'robotsNoindex') return self::robots_on($key, $raw, 'index');
    if ($f === 'robotsNofollow') return self::robots_on($key, $raw, 'follow');
    if ($f === 'focusKeyword' || $f === 'secondaryKeywords') {
      $list = self::split_keywords(is_scalar($raw) ? (string) $raw : '');
      return $f === 'focusKeyword' ? (string) ($list[0] ?? '') : array_slice($list, 1);
    }
    return is_scalar($raw) ? (string) $raw : '';
  }

  protected function theme_set($post_id, $f, $v) {
    $fam = $this->family_for($post_id);
    $part = self::part_of($f);
    if (!$fam || !$part || !isset($fam['fields'][$part])) return;
    $key = $fam['fields'][$part];
    $args = class_exists('Visibility_Meta') ? Visibility_Meta::registered($key, get_post_type($post_id)) : null;
    $type = $args && !empty($args['type']) ? $args['type'] : 'string';
    if ($f === 'robotsNoindex' || $f === 'robotsNofollow') {
      $v = self::robots_value($key, (bool) $v, $type, $f === 'robotsNoindex' ? 'index' : 'follow');
    }
    self::set_meta($post_id, $key, $v);
  }

  public function read($post_id) {
    $out = Visibility_SEO_Adapter_None::read($post_id);
    $fam = $this->family_for($post_id);
    foreach ($this->owned_by($fam) as $f) {
      $v = $this->theme_get($post_id, $f);
      if (is_bool($v)) $out[$f] = $v || (!empty($out[$f]) && $out[$f] === true);
      elseif (is_array($v)) $out[$f] = $v;
      elseif ($v !== '') $out[$f] = $v;
    }
    if ($fam && isset($fam['fields']['keyword']) && !isset($out['secondaryKeywords'])) $out['secondaryKeywords'] = [];
    if ($fam) foreach ($this->excluded() as $f) $out[$f] = in_array($f, self::BOOL_FIELDS, true) ? false : '';
    return $out;
  }

  public function write($post_id, array $fields) {
    $fam = $this->family_for($post_id);
    if (!$fam) {
      // Not registered for this post type: Visibility's own keys, as without an SEO plugin.
      unset($fields['secondaryKeywords']);
      return Visibility_SEO_Adapter_None::write($post_id, $fields);
    }
    $fields = $this->normalize($fields);
    $bad = array_values(array_intersect(array_keys($fields), $this->excluded()));
    foreach ($bad as $f) unset($fields[$f]);
    $owned = $this->owned_by($fam);
    $own = []; $rest = [];
    foreach ($fields as $f => $v) {
      if (in_array($f, $owned, true)) $own[$f] = $v; else $rest[$f] = $v;
    }
    // Keywords: one comma list in the plugin's key, primary first.
    if (array_key_exists('focusKeyword', $own) || array_key_exists('secondaryKeywords', $own)) {
      $primary = array_key_exists('focusKeyword', $own) ? self::text($own['focusKeyword']) : (string) $this->theme_get($post_id, 'focusKeyword');
      $sec = array_key_exists('secondaryKeywords', $own) ? $own['secondaryKeywords'] : $this->theme_get($post_id, 'secondaryKeywords');
      $sec = is_array($sec) ? $sec : self::split_keywords((string) $sec);
      $sec = array_values(array_filter(array_map(function ($k) { return sanitize_text_field(str_replace(',', ' ', (string) $k)); }, $sec), 'strlen'));
      $list = array_merge($primary !== '' ? [sanitize_text_field(str_replace(',', ' ', $primary))] : [], $sec);
      self::set_meta($post_id, $fam['fields']['keyword'], implode(', ', $list));
      delete_post_meta($post_id, self::KEYS['focusKeyword']);
      unset($own['focusKeyword'], $own['secondaryKeywords']);
      $kw_written = array_values(array_intersect(['focusKeyword', 'secondaryKeywords'], array_keys($fields)));
    } else {
      $kw_written = [];
    }
    foreach ($own as $f => $v) {
      if (in_array($f, self::BOOL_FIELDS, true)) $v = (bool) $v;
      elseif ($f === 'canonical') $v = self::url($v);
      else $v = self::text($v);
      $this->theme_set($post_id, $f, $v);
      if (isset(self::KEYS[$f])) delete_post_meta($post_id, self::KEYS[$f]);
    }
    $r = $rest ? Visibility_SEO_Adapter_None::write($post_id, $rest) : ['written' => [], 'unsupported' => []];
    clean_post_cache($post_id);
    return ['written' => array_merge($kw_written, array_keys($own), $r['written']), 'unsupported' => array_merge($r['unsupported'], $bad)];
  }

  /** Current values of the family's keys for a post: {part: {key, value}}. */
  public function raw($post_id) {
    $fam = $this->family_for($post_id);
    if (!$fam) return null;
    $out = [];
    foreach ($fam['fields'] as $part => $key) {
      $v = get_post_meta($post_id, $key, true);
      $out[$part] = ['key' => $key, 'value' => is_scalar($v) ? $v : null];
    }
    return ['prefix' => $fam['prefix'], 'fields' => $out];
  }
}
