<?php
/**
 * Theme SEO fields (C-41): Genesis, Divi and Avada have their own per-page
 * SEO fields. When no SEO plugin is active, Visibility reads and writes the
 * title and description (and what else the theme keeps) in the theme's own
 * fields, so the owner sees them where the theme shows them, and prints only
 * what the theme does not print itself, so every tag appears once.
 *
 *   Genesis  post meta _genesis_title, _genesis_description,
 *            _genesis_canonical_uri, _genesis_noindex, _genesis_nofollow
 *            (the same keys The SEO Framework took over); Genesis prints the
 *            title, description, canonical and robots itself.
 *   Divi     ePanel SEO: the custom fields named in Theme Options › SEO
 *            (divi_seo_single_field_title / _description, default
 *            "seo_title" / "seo_description"); Divi prints them only while
 *            "Enable custom titles" / "Enable custom description" are on,
 *            otherwise Visibility prints them.
 *   Avada    Page Options in the _fusion post meta array: seo_title,
 *            meta_description, seo_index / seo_follow ("no" = off), read and
 *            written through fusion_data()->post_meta() when available. Avada
 *            prints the title and robots, and the description and Open Graph
 *            tags while its Open Graph option is on.
 *
 * Other fields (Open Graph, Twitter, canonical where the theme has none) keep
 * Visibility's own keys and rendering, as on a site without any SEO fields.
 */

if (!defined('ABSPATH')) {
  exit;
}

abstract class Visibility_SEO_Adapter_Theme extends Visibility_SEO_Adapter_None {

  /** Fields kept in the theme's own storage. */
  abstract protected function owned();
  /** Stored theme value of an owned field ('' / false when empty). */
  abstract protected function theme_get($post_id, $field);
  abstract protected function theme_set($post_id, $field, $value);
  /** Head parts the theme prints itself: title, description, canonical, robots, og. */
  abstract public function printed();
  /** Fields that cannot be used on this site (the theme prints its own version). */
  protected function excluded() { return []; }

  public function name() { return $this->theme_name() . ' (theme SEO fields)'; }
  abstract protected function theme_name();
  public function fields() { return array_values(array_diff(parent::fields(), $this->excluded())); }

  public function read($post_id) {
    $out = parent::read($post_id);
    foreach ($this->owned() as $f) {
      $v = $this->theme_get($post_id, $f);
      if (in_array($f, self::BOOL_FIELDS, true)) $out[$f] = (bool) $v || $out[$f];
      elseif ((string) $v !== '') $out[$f] = (string) $v;
    }
    foreach ($this->excluded() as $f) $out[$f] = in_array($f, self::BOOL_FIELDS, true) ? false : '';
    return $out;
  }

  public function write($post_id, array $fields) {
    $bad = array_values(array_intersect(array_keys($fields), $this->excluded()));
    foreach ($bad as $f) unset($fields[$f]);
    $own = []; $rest = [];
    foreach ($fields as $f => $v) {
      if (in_array($f, $this->owned(), true)) $own[$f] = $v; else $rest[$f] = $v;
    }
    foreach ($own as $f => $v) {
      if (in_array($f, self::BOOL_FIELDS, true)) $v = (bool) $v;
      elseif ($f === 'canonical') $v = self::url($v);
      else $v = self::text($v);
      $this->theme_set($post_id, $f, $v);
      // One place per field: drop a value Visibility kept before (no theme fields).
      if (isset(self::KEYS[$f])) delete_post_meta($post_id, self::KEYS[$f]);
    }
    $r = $rest ? parent::write($post_id, $rest) : ['written' => [], 'unsupported' => []];
    clean_post_cache($post_id);
    return ['written' => array_merge(array_keys($own), $r['written']), 'unsupported' => array_merge($r['unsupported'], $bad)];
  }
}

/** Genesis Framework (StudioPress): its In-Post SEO Settings. */
class Visibility_SEO_Adapter_Genesis extends Visibility_SEO_Adapter_Theme {
  const THEME_KEYS = [
    'metaTitle'       => '_genesis_title',
    'metaDescription' => '_genesis_description',
    'canonical'       => '_genesis_canonical_uri',
    'robotsNoindex'   => '_genesis_noindex',
    'robotsNofollow'  => '_genesis_nofollow',
  ];
  public static function active() {
    return get_template() === 'genesis' && (function_exists('genesis') || defined('PARENT_THEME_VERSION'));
  }
  public function slug() { return 'genesis'; }
  protected function theme_name() { return 'Genesis'; }
  public function version() { return defined('PARENT_THEME_VERSION') ? (string) PARENT_THEME_VERSION : wp_get_theme('genesis')->get('Version'); }
  protected function owned() { return array_keys(self::THEME_KEYS); }
  public function printed() { return ['title', 'description', 'canonical', 'robots']; }
  protected function theme_get($post_id, $f) {
    $v = self::meta($post_id, self::THEME_KEYS[$f]);
    return in_array($f, self::BOOL_FIELDS, true) ? $v === '1' : $v;
  }
  protected function theme_set($post_id, $f, $v) {
    self::set_meta($post_id, self::THEME_KEYS[$f], is_bool($v) ? ($v ? '1' : '') : $v);
  }
}

/** Divi (Elegant Themes): ePanel's per-post SEO custom fields. */
class Visibility_SEO_Adapter_Divi extends Visibility_SEO_Adapter_Theme {
  public static function active() {
    return in_array(get_template(), ['Divi', 'Extra'], true) && function_exists('et_get_option');
  }
  private static function opt($name, $default = '') {
    $v = et_get_option('divi_' . $name, $default);
    return $v === false || $v === null ? $default : $v;
  }
  private static function field_name($f) {
    $n = $f === 'metaTitle' ? self::opt('seo_single_field_title', 'seo_title') : self::opt('seo_single_field_description', 'seo_description');
    $n = sanitize_key((string) $n);
    return $n !== '' ? $n : ($f === 'metaTitle' ? 'seo_title' : 'seo_description');
  }
  public function slug() { return 'divi'; }
  protected function theme_name() { return 'Divi'; }
  public function version() { return defined('ET_CORE_VERSION') ? (string) ET_CORE_VERSION : wp_get_theme(get_template())->get('Version'); }
  protected function owned() { return ['metaTitle', 'metaDescription']; }
  public function printed() {
    $p = [];
    // Divi reads the field names from its options; until ePanel was saved once
    // they are empty and Divi prints nothing useful, so Visibility keeps printing.
    if (self::opt('seo_single_title') === 'on' && (string) self::opt('seo_single_field_title') !== '') $p[] = 'title';
    if (self::opt('seo_single_description') === 'on' && (string) self::opt('seo_single_field_description') !== '') $p[] = 'description';
    if (self::opt('seo_single_canonical') === 'on') $p[] = 'canonical';
    return $p;
  }
  /** Divi's own canonical (when on) is the permalink: a custom one would print twice. */
  protected function excluded() { return in_array('canonical', $this->printed(), true) ? ['canonical'] : []; }
  protected function theme_get($post_id, $f) { return self::meta($post_id, self::field_name($f)); }
  protected function theme_set($post_id, $f, $v) { self::set_meta($post_id, self::field_name($f), $v); }
}

/** Avada (ThemeFusion): SEO in Page Options. */
class Visibility_SEO_Adapter_Avada extends Visibility_SEO_Adapter_Theme {
  const THEME_KEYS = ['metaTitle' => 'seo_title', 'metaDescription' => 'meta_description', 'robotsNoindex' => 'seo_index', 'robotsNofollow' => 'seo_follow'];
  public static function active() {
    return get_template() === 'Avada' || class_exists('Avada');
  }
  private static function og_on() {
    if (function_exists('Avada') && is_object(Avada()) && isset(Avada()->settings) && is_object(Avada()->settings) && method_exists(Avada()->settings, 'get')) {
      return (bool) Avada()->settings->get('status_opengraph');
    }
    $o = get_option('fusion_options', []);
    return is_array($o) && !empty($o['status_opengraph']) && $o['status_opengraph'] !== '0';
  }
  public function slug() { return 'avada'; }
  protected function theme_name() { return 'Avada'; }
  public function version() { return defined('AVADA_VERSION') ? (string) AVADA_VERSION : wp_get_theme(get_template())->get('Version'); }
  protected function owned() { return array_keys(self::THEME_KEYS); }
  public function printed() { return self::og_on() ? ['title', 'robots', 'description', 'og'] : ['title', 'robots']; }
  /** With Avada's Open Graph on, Avada prints og:title/description/image from the page's own SEO title, description and featured image. */
  protected function excluded() { return self::og_on() ? ['ogTitle', 'ogDescription', 'ogImage'] : []; }
  private static function all($post_id) {
    $m = get_post_meta($post_id, '_fusion', true);
    return is_array($m) ? $m : [];
  }
  protected function theme_get($post_id, $f) {
    $k = self::THEME_KEYS[$f];
    if (function_exists('fusion_data') && is_object(fusion_data()) && method_exists(fusion_data(), 'post_meta')) {
      $v = fusion_data()->post_meta($post_id)->get($k);
    } else {
      $m = self::all($post_id);
      $v = isset($m[$k]) ? $m[$k] : '';
    }
    if (in_array($f, self::BOOL_FIELDS, true)) return $v === 'no';
    return is_scalar($v) ? (string) $v : '';
  }
  protected function theme_set($post_id, $f, $v) {
    $k = self::THEME_KEYS[$f];
    if (is_bool($v)) $v = $v ? 'no' : '';
    if (function_exists('fusion_data') && is_object(fusion_data()) && method_exists(fusion_data(), 'post_meta')) {
      fusion_data()->post_meta($post_id)->set($k, $v);
      return;
    }
    // Same as Avada's Fusion_Data_PostMeta::set(): empty / "default" removes the option.
    $m = self::all($post_id);
    if ($v === '' || $v === 'default') unset($m[$k]); else $m[$k] = $v;
    if ($m) update_post_meta($post_id, '_fusion', $m); else delete_post_meta($post_id, '_fusion');
  }
}
