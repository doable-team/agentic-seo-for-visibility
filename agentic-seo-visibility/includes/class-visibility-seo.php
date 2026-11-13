<?php
/**
 * SEO plugin adapters: one way to read (and write) SEO fields whatever SEO
 * plugin the site runs.
 *
 * Field names are Visibility's canonical ones (the app's PluginSeoFields):
 *   metaTitle, metaDescription, focusKeyword, canonical,
 *   ogTitle, ogDescription, ogImage,
 *   twitterTitle, twitterDescription, twitterImage,
 *   robotsNoindex, robotsNofollow, primaryCategory, cornerstone, schemaJsonLd
 * (+ advancedRobots on Rank Math).
 *
 * Each adapter answers: detect, read (stored values), rendered (what the
 * front end outputs after templates like %%title%%), and write.
 */

if (!defined('ABSPATH')) {
  exit;
}

abstract class Visibility_SEO_Adapter {

  const TEXT_FIELDS = ['metaTitle', 'metaDescription', 'focusKeyword', 'canonical', 'ogTitle', 'ogDescription', 'ogImage', 'twitterTitle', 'twitterDescription', 'twitterImage'];
  const BOOL_FIELDS = ['robotsNoindex', 'robotsNofollow', 'cornerstone'];

  /** Slug used in results (`seoPlugin`). */
  abstract public function slug();
  abstract public function name();
  abstract public function version();
  /** Canonical fields this adapter can write for posts. */
  abstract public function fields();
  /** Stored per-post values (unset = '' / false / null). */
  abstract public function read($post_id);
  /** Whether this adapter writes. Detect-only adapters stand the fallback head down but write nothing. */
  public function writable() { return true; }
  /** Canonical fields this adapter can write for terms ([] = no term SEO). */
  public function term_fields() { return []; }
  public function read_term($term_id, $taxonomy) { return null; }

  /**
   * Write $fields for $post_id.
   * @return array|WP_Error ['written' => [...], 'unsupported' => [...]]
   */
  public function write($post_id, array $fields) {
    return new WP_Error('visibility_seo_unsupported', $this->name() . ' fields cannot be written yet.');
  }
  public function write_term($term_id, $taxonomy, array $fields) {
    return new WP_Error('visibility_seo_unsupported', $this->name() . ' term SEO cannot be written yet.');
  }

  /** What the front end outputs for the post (best effort through the plugin's own API). */
  public function rendered($post_id) {
    return null;
  }

  /** Empty values for every canonical field. */
  public static function blank() {
    $out = [];
    foreach (self::TEXT_FIELDS as $f) $out[$f] = '';
    foreach (self::BOOL_FIELDS as $f) $out[$f] = false;
    $out['primaryCategory'] = null;
    $out['schemaJsonLd'] = null;
    return $out;
  }

  /** Split fields into the ones this adapter takes and the rest. */
  protected function partition(array $fields, array $supported) {
    $take = [];
    $rest = [];
    foreach ($fields as $k => $v) {
      if ($k === 'schemaJsonLd') continue; // handled by Visibility_SEO for every adapter
      if (in_array($k, $supported, true)) $take[$k] = $v; else $rest[] = $k;
    }
    return [$take, $rest];
  }

  protected static function text($v) {
    return $v === null ? '' : sanitize_text_field((string) $v);
  }

  protected static function url($v) {
    return ($v === null || $v === '') ? '' : esc_url_raw((string) $v);
  }

  protected static function meta($post_id, $key) {
    $v = get_post_meta($post_id, $key, true);
    return is_scalar($v) ? (string) $v : '';
  }

  /** update_post_meta, or delete when the new value is empty. */
  protected static function set_meta($post_id, $key, $value) {
    if ($value === '' || $value === null || $value === false) {
      delete_post_meta($post_id, $key);
    } else {
      update_post_meta($post_id, $key, is_string($value) ? wp_slash($value) : $value);
    }
  }
}

/** No SEO plugin: our own keys, rendered by Visibility_SEO_Head. */
class Visibility_SEO_Adapter_None extends Visibility_SEO_Adapter {
  const KEYS = [
    'metaTitle'          => '_visibility_seo_title',
    'metaDescription'    => '_visibility_seo_description',
    'focusKeyword'       => '_visibility_seo_focus_keyword',
    'canonical'          => '_visibility_seo_canonical',
    'ogTitle'            => '_visibility_seo_og_title',
    'ogDescription'      => '_visibility_seo_og_description',
    'ogImage'            => '_visibility_seo_og_image',
    'twitterTitle'       => '_visibility_seo_twitter_title',
    'twitterDescription' => '_visibility_seo_twitter_description',
    'twitterImage'       => '_visibility_seo_twitter_image',
    'robotsNoindex'      => '_visibility_seo_noindex',
    'robotsNofollow'     => '_visibility_seo_nofollow',
  ];

  public function slug() { return null; }
  public function name() { return 'Visibility (no SEO plugin)'; }
  public function version() { return defined('VISIBILITY_PLUGIN_VERSION') ? VISIBILITY_PLUGIN_VERSION : null; }
  public function fields() { return array_merge(array_keys(self::KEYS), ['schemaJsonLd']); }
  public function term_fields() { return ['metaTitle', 'metaDescription', 'canonical', 'ogTitle', 'ogDescription', 'ogImage', 'robotsNoindex', 'robotsNofollow']; }

  public function read($post_id) {
    $out = self::blank();
    foreach (self::KEYS as $f => $key) {
      $v = self::meta($post_id, $key);
      $out[$f] = in_array($f, self::BOOL_FIELDS, true) ? $v === '1' : $v;
    }
    return $out;
  }

  public function read_term($term_id, $taxonomy) {
    $out = self::blank();
    foreach ($this->term_fields() as $f) {
      $v = get_term_meta($term_id, self::KEYS[$f], true);
      $v = is_scalar($v) ? (string) $v : '';
      $out[$f] = in_array($f, self::BOOL_FIELDS, true) ? $v === '1' : $v;
    }
    return $out;
  }

  public function write($post_id, array $fields) {
    list($take, $rest) = $this->partition($fields, array_keys(self::KEYS));
    foreach ($take as $f => $v) {
      if (in_array($f, self::BOOL_FIELDS, true)) {
        self::set_meta($post_id, self::KEYS[$f], $v ? '1' : '');
      } elseif (in_array($f, ['canonical', 'ogImage', 'twitterImage'], true)) {
        self::set_meta($post_id, self::KEYS[$f], self::url($v));
      } else {
        self::set_meta($post_id, self::KEYS[$f], self::text($v));
      }
    }
    return ['written' => array_keys($take), 'unsupported' => $rest];
  }

  public function write_term($term_id, $taxonomy, array $fields) {
    list($take, $rest) = $this->partition($fields, $this->term_fields());
    foreach ($take as $f => $v) {
      $key = self::KEYS[$f];
      if (in_array($f, self::BOOL_FIELDS, true)) $v = $v ? '1' : '';
      elseif (in_array($f, ['canonical', 'ogImage'], true)) $v = self::url($v);
      else $v = self::text($v);
      if ($v === '') delete_term_meta($term_id, $key); else update_term_meta($term_id, $key, wp_slash($v));
    }
    return ['written' => array_keys($take), 'unsupported' => $rest];
  }

  public function rendered($post_id) {
    return method_exists('Visibility_SEO_Head', 'values_for_post') ? Visibility_SEO_Head::values_for_post($post_id) : null;
  }
}

/** Yoast SEO (free + Premium: Premium stores its SEO data through the free plugin). */
class Visibility_SEO_Adapter_Yoast extends Visibility_SEO_Adapter {
  const KEYS = [
    'metaTitle'          => '_yoast_wpseo_title',
    'metaDescription'    => '_yoast_wpseo_metadesc',
    'focusKeyword'       => '_yoast_wpseo_focuskw',
    'canonical'          => '_yoast_wpseo_canonical',
    'ogTitle'            => '_yoast_wpseo_opengraph-title',
    'ogDescription'      => '_yoast_wpseo_opengraph-description',
    'ogImage'            => '_yoast_wpseo_opengraph-image',
    'twitterTitle'       => '_yoast_wpseo_twitter-title',
    'twitterDescription' => '_yoast_wpseo_twitter-description',
    'twitterImage'       => '_yoast_wpseo_twitter-image',
    'robotsNoindex'      => '_yoast_wpseo_meta-robots-noindex',
    'robotsNofollow'     => '_yoast_wpseo_meta-robots-nofollow',
    'primaryCategory'    => '_yoast_wpseo_primary_category',
    'cornerstone'        => '_yoast_wpseo_is_cornerstone',
  ];
  /** Term keys inside the `wpseo_taxonomy_meta` option. */
  const TERM_KEYS = [
    'metaTitle'          => 'wpseo_title',
    'metaDescription'    => 'wpseo_desc',
    'focusKeyword'       => 'wpseo_focuskw',
    'canonical'          => 'wpseo_canonical',
    'ogTitle'            => 'wpseo_opengraph-title',
    'ogDescription'      => 'wpseo_opengraph-description',
    'ogImage'            => 'wpseo_opengraph-image',
    'twitterTitle'       => 'wpseo_twitter-title',
    'twitterDescription' => 'wpseo_twitter-description',
    'twitterImage'       => 'wpseo_twitter-image',
    'robotsNoindex'      => 'wpseo_noindex',
    'cornerstone'        => 'wpseo_is_cornerstone',
  ];

  public function slug() { return 'yoast'; }
  public function name() { return 'Yoast SEO'; }
  public function version() { return defined('WPSEO_VERSION') ? WPSEO_VERSION : null; }
  public function fields() { return array_merge(array_keys(self::KEYS), ['schemaJsonLd']); }
  public function term_fields() { return array_keys(self::TERM_KEYS); }

  public function read($post_id) {
    $out = self::blank();
    foreach (self::KEYS as $f => $key) {
      $v = self::meta($post_id, $key);
      if ($f === 'robotsNoindex') $out[$f] = $v === '1';
      elseif ($f === 'robotsNofollow' || $f === 'cornerstone') $out[$f] = $v === '1';
      elseif ($f === 'primaryCategory') $out[$f] = $v !== '' ? (int) $v : null;
      else $out[$f] = $v;
    }
    return $out;
  }

  public function read_term($term_id, $taxonomy) {
    $all = get_option('wpseo_taxonomy_meta');
    $row = (is_array($all) && isset($all[$taxonomy][$term_id]) && is_array($all[$taxonomy][$term_id])) ? $all[$taxonomy][$term_id] : [];
    $out = self::blank();
    foreach (self::TERM_KEYS as $f => $key) {
      $v = isset($row[$key]) ? (string) $row[$key] : '';
      if ($f === 'robotsNoindex') $out[$f] = $v === 'noindex';
      elseif ($f === 'cornerstone') $out[$f] = $v === '1';
      else $out[$f] = $v;
    }
    return $out;
  }

  public function write($post_id, array $fields) {
    list($take, $rest) = $this->partition($fields, array_keys(self::KEYS));
    foreach ($take as $f => $v) {
      $key = self::KEYS[$f];
      if ($f === 'robotsNoindex') self::set_meta($post_id, $key, $v ? '1' : '');
      elseif ($f === 'robotsNofollow' || $f === 'cornerstone') self::set_meta($post_id, $key, $v ? '1' : '');
      elseif ($f === 'primaryCategory') self::set_meta($post_id, $key, $v ? (string) (int) $v : '');
      elseif (in_array($f, ['canonical', 'ogImage', 'twitterImage'], true)) {
        self::set_meta($post_id, $key, self::url($v));
        // Yoast keeps the image id next to the URL; a stale id would win.
        if ($f === 'ogImage') delete_post_meta($post_id, '_yoast_wpseo_opengraph-image-id');
        if ($f === 'twitterImage') delete_post_meta($post_id, '_yoast_wpseo_twitter-image-id');
      } else self::set_meta($post_id, $key, self::text($v));
    }
    $this->rebuild_post($post_id);
    return ['written' => array_keys($take), 'unsupported' => $rest];
  }

  public function write_term($term_id, $taxonomy, array $fields) {
    list($take, $rest) = $this->partition($fields, array_keys(self::TERM_KEYS));
    $all = get_option('wpseo_taxonomy_meta');
    if (!is_array($all)) $all = [];
    $row = isset($all[$taxonomy][$term_id]) && is_array($all[$taxonomy][$term_id]) ? $all[$taxonomy][$term_id] : [];
    foreach ($take as $f => $v) {
      $key = self::TERM_KEYS[$f];
      if ($f === 'robotsNoindex') $v = $v ? 'noindex' : '';
      elseif ($f === 'cornerstone') $v = $v ? '1' : '';
      elseif (in_array($f, ['canonical', 'ogImage', 'twitterImage'], true)) $v = self::url($v);
      else $v = self::text($v);
      if ($v === '') unset($row[$key]); else $row[$key] = $v;
    }
    $all[$taxonomy][$term_id] = $row;
    update_option('wpseo_taxonomy_meta', $all);
    $this->rebuild_term($term_id);
    return ['written' => array_keys($take), 'unsupported' => $rest];
  }

  /**
   * Yoast serves <head> from its indexables table, refreshed on its own save
   * path. Run that path so the change shows without someone re-saving.
   */
  private function rebuild_post($post_id) {
    clean_post_cache($post_id);
    if (!function_exists('YoastSEO')) return;
    try {
      $watcher = YoastSEO()->classes->get('Yoast\\WP\\SEO\\Integrations\\Watchers\\Indexable_Post_Watcher');
      if ($watcher && method_exists($watcher, 'build_indexable')) $watcher->build_indexable($post_id);
    } catch (\Throwable $e) {
      // Fall back to Yoast's own save hook.
      do_action('wpseo_saved_postdata');
    }
  }

  private function rebuild_term($term_id) {
    if (!function_exists('YoastSEO')) return;
    try {
      $watcher = YoastSEO()->classes->get('Yoast\\WP\\SEO\\Integrations\\Watchers\\Indexable_Term_Watcher');
      if ($watcher && method_exists($watcher, 'build_indexable')) $watcher->build_indexable($term_id);
    } catch (\Throwable $e) {
      // Nothing else to do: the indexable refreshes on Yoast's next term save.
    }
  }

  public function rendered($post_id) {
    if (!function_exists('YoastSEO')) return null;
    try {
      $m = YoastSEO()->meta->for_post($post_id);
      if (!$m) return null;
      $robots = is_array($m->robots) ? array_values(array_filter($m->robots)) : [];
      $og_image = '';
      if (is_array($m->open_graph_images) && !empty($m->open_graph_images)) {
        $first = reset($m->open_graph_images);
        $og_image = is_array($first) && isset($first['url']) ? (string) $first['url'] : '';
      }
      return [
        'title'              => (string) $m->title,
        'description'        => (string) $m->description,
        'canonical'          => (string) $m->canonical,
        'robots'             => implode(', ', $robots),
        'ogTitle'            => (string) $m->open_graph_title,
        'ogDescription'      => (string) $m->open_graph_description,
        'ogImage'            => $og_image,
        'twitterTitle'       => (string) $m->twitter_title,
        'twitterDescription' => (string) $m->twitter_description,
        'twitterImage'       => (string) $m->twitter_image,
      ];
    } catch (\Throwable $e) {
      return null;
    }
  }
}

/** Rank Math (free + Pro: same storage). */
class Visibility_SEO_Adapter_RankMath extends Visibility_SEO_Adapter {
  const KEYS = [
    'metaTitle'          => 'rank_math_title',
    'metaDescription'    => 'rank_math_description',
    'focusKeyword'       => 'rank_math_focus_keyword',
    'canonical'          => 'rank_math_canonical_url',
    'ogTitle'            => 'rank_math_facebook_title',
    'ogDescription'      => 'rank_math_facebook_description',
    'ogImage'            => 'rank_math_facebook_image',
    'twitterTitle'       => 'rank_math_twitter_title',
    'twitterDescription' => 'rank_math_twitter_description',
    'twitterImage'       => 'rank_math_twitter_image',
    'primaryCategory'    => 'rank_math_primary_category',
    'cornerstone'        => 'rank_math_pillar_content',
  ];

  public function slug() { return 'rankmath'; }
  public function name() { return 'Rank Math'; }
  public function version() { return defined('RANK_MATH_VERSION') ? RANK_MATH_VERSION : null; }
  public function fields() { return array_merge(array_keys(self::KEYS), ['robotsNoindex', 'robotsNofollow', 'advancedRobots', 'schemaJsonLd']); }
  public function term_fields() { return array_merge(array_diff(array_keys(self::KEYS), ['primaryCategory', 'cornerstone']), ['robotsNoindex', 'robotsNofollow', 'advancedRobots']); }

  private static function robots_of($list) {
    return is_array($list) ? array_values(array_filter(array_map('strval', $list))) : [];
  }

  private function read_from($get) {
    $out = self::blank();
    foreach (self::KEYS as $f => $key) {
      $v = $get($key);
      $v = is_scalar($v) ? (string) $v : '';
      if ($f === 'cornerstone') $out[$f] = $v === 'on';
      elseif ($f === 'primaryCategory') $out[$f] = $v !== '' ? (int) $v : null;
      else $out[$f] = $v;
    }
    $robots = self::robots_of($get('rank_math_robots'));
    $out['robotsNoindex']  = in_array('noindex', $robots, true);
    $out['robotsNofollow'] = in_array('nofollow', $robots, true);
    $adv = $get('rank_math_advanced_robots');
    $out['advancedRobots'] = is_array($adv) ? $adv : null;
    return $out;
  }

  public function read($post_id) {
    return $this->read_from(function ($k) use ($post_id) { return get_post_meta($post_id, $k, true); });
  }

  public function read_term($term_id, $taxonomy) {
    return $this->read_from(function ($k) use ($term_id) { return get_term_meta($term_id, $k, true); });
  }

  /** @param callable $get, $set, $del  meta accessors for a post or a term */
  private function write_to(array $fields, array $supported, $get, $set, $del) {
    list($take, $rest) = $this->partition($fields, $supported);
    foreach ($take as $f => $v) {
      if (isset(self::KEYS[$f])) {
        $key = self::KEYS[$f];
        if ($f === 'cornerstone') $v = $v ? 'on' : '';
        elseif ($f === 'primaryCategory') $v = $v ? (string) (int) $v : '';
        elseif (in_array($f, ['canonical', 'ogImage', 'twitterImage'], true)) $v = self::url($v);
        else $v = self::text($v);
        if ($v === '') $del($key); else $set($key, wp_slash($v));
        // Rank Math reads the image id first; drop a stale one.
        if ($f === 'ogImage') $del('rank_math_facebook_image_id');
        if ($f === 'twitterImage') $del('rank_math_twitter_image_id');
        if (in_array($f, ['twitterTitle', 'twitterDescription', 'twitterImage'], true) && $v !== '') {
          // Otherwise Rank Math copies the Facebook values over the Twitter ones.
          $set('rank_math_twitter_use_facebook', 'off');
        }
      }
    }
    if (array_key_exists('robotsNoindex', $take) || array_key_exists('robotsNofollow', $take)) {
      $robots = self::robots_of($get('rank_math_robots'));
      foreach (['robotsNoindex' => ['noindex', 'index'], 'robotsNofollow' => ['nofollow', 'follow']] as $f => $pair) {
        if (!array_key_exists($f, $take)) continue;
        $robots = array_values(array_diff($robots, $pair));
        $robots[] = $take[$f] ? $pair[0] : $pair[1];
      }
      $set('rank_math_robots', array_values(array_unique($robots)));
    }
    if (array_key_exists('advancedRobots', $take)) {
      $adv = is_array($take['advancedRobots']) ? array_map('sanitize_text_field', $take['advancedRobots']) : [];
      if (empty($adv)) $del('rank_math_advanced_robots'); else $set('rank_math_advanced_robots', $adv);
    }
    return ['written' => array_keys($take), 'unsupported' => $rest];
  }

  public function write($post_id, array $fields) {
    $r = $this->write_to($fields, $this->fields(),
      function ($k) use ($post_id) { return get_post_meta($post_id, $k, true); },
      function ($k, $v) use ($post_id) { update_post_meta($post_id, $k, $v); },
      function ($k) use ($post_id) { delete_post_meta($post_id, $k); });
    clean_post_cache($post_id);
    return $r;
  }

  public function write_term($term_id, $taxonomy, array $fields) {
    return $this->write_to($fields, $this->term_fields(),
      function ($k) use ($term_id) { return get_term_meta($term_id, $k, true); },
      function ($k, $v) use ($term_id) { update_term_meta($term_id, $k, $v); },
      function ($k) use ($term_id) { delete_term_meta($term_id, $k); });
  }

  public function rendered($post_id) {
    if (!class_exists('RankMath\\Helper')) return null;
    $post = get_post($post_id);
    if (!$post) return null;
    try {
      // Rank Math only registers its %variables% on the front end ('wp').
      if (function_exists('rank_math') && isset(rank_math()->variables) && method_exists(rank_math()->variables, 'setup')) {
        rank_math()->variables->setup();
      }
      $type = $post->post_type;
      $tpl = function ($meta, $setting, $fallback) use ($post) {
        $v = get_post_meta($post->ID, $meta, true);
        if (!is_string($v) || $v === '') $v = \RankMath\Helper::get_settings($setting, $fallback);
        return trim(wp_strip_all_tags(\RankMath\Helper::replace_vars((string) $v, $post)));
      };
      $title = $tpl('rank_math_title', "titles.pt_{$type}_title", '%title% %sep% %sitename%');
      $desc  = $tpl('rank_math_description', "titles.pt_{$type}_description", '%excerpt%');
      $og_t  = get_post_meta($post->ID, 'rank_math_facebook_title', true) ?: $title;
      $og_d  = get_post_meta($post->ID, 'rank_math_facebook_description', true) ?: $desc;
      $robots = self::robots_of(get_post_meta($post->ID, 'rank_math_robots', true));
      if (empty($robots)) $robots = self::robots_of(\RankMath\Helper::get_settings("titles.pt_{$type}_robots", []));
      if (!in_array('noindex', $robots, true) && !in_array('index', $robots, true)) array_unshift($robots, 'index');
      if (!in_array('nofollow', $robots, true) && !in_array('follow', $robots, true)) $robots[] = 'follow';
      $canonical = get_post_meta($post->ID, 'rank_math_canonical_url', true) ?: get_permalink($post);
      $use_fb = get_post_meta($post->ID, 'rank_math_twitter_use_facebook', true) !== 'off';
      $og_img = (string) get_post_meta($post->ID, 'rank_math_facebook_image', true);
      if ($og_img === '') $og_img = (string) get_the_post_thumbnail_url($post, 'full');
      return [
        'title'              => $title,
        'description'        => $desc,
        'canonical'          => (string) $canonical,
        'robots'             => implode(', ', $robots),
        'ogTitle'            => trim(wp_strip_all_tags(\RankMath\Helper::replace_vars((string) $og_t, $post))),
        'ogDescription'      => trim(wp_strip_all_tags(\RankMath\Helper::replace_vars((string) $og_d, $post))),
        'ogImage'            => $og_img,
        'twitterTitle'       => $use_fb ? trim(wp_strip_all_tags(\RankMath\Helper::replace_vars((string) $og_t, $post))) : (string) get_post_meta($post->ID, 'rank_math_twitter_title', true),
        'twitterDescription' => $use_fb ? trim(wp_strip_all_tags(\RankMath\Helper::replace_vars((string) $og_d, $post))) : (string) get_post_meta($post->ID, 'rank_math_twitter_description', true),
        'twitterImage'       => $use_fb ? $og_img : (string) get_post_meta($post->ID, 'rank_math_twitter_image', true),
      ];
    } catch (\Throwable $e) {
      return null;
    }
  }
}

/** All in One SEO 4.x/5.x (Lite + Pro read the same aioseo_posts table). */
class Visibility_SEO_Adapter_AIOSEO extends Visibility_SEO_Adapter {
  const MODEL = 'AIOSEO\\Plugin\\Common\\Models\\Post';

  public function slug() { return 'aioseo'; }
  public function name() { return 'All in One SEO'; }
  public function version() { return defined('AIOSEO_VERSION') ? AIOSEO_VERSION : null; }
  public function fields() {
    return ['metaTitle', 'metaDescription', 'focusKeyword', 'canonical', 'ogTitle', 'ogDescription', 'ogImage', 'twitterTitle', 'twitterDescription', 'twitterImage', 'robotsNoindex', 'robotsNofollow', 'primaryCategory', 'cornerstone', 'schemaJsonLd'];
  }
  /** Term SEO is a Pro feature with its own table; not written in this version. */
  public function term_fields() { return []; }

  private function model($post_id) {
    $class = self::MODEL;
    if (!class_exists($class) || !function_exists('aioseo')) return null;
    return $class::getPost($post_id);
  }

  public function read($post_id) {
    $out = self::blank();
    $m = $this->model($post_id);
    if (!$m) return $out;
    $out['metaTitle']          = (string) $m->title;
    $out['metaDescription']    = (string) $m->description;
    $out['canonical']          = (string) $m->canonical_url;
    $out['ogTitle']            = (string) $m->og_title;
    $out['ogDescription']      = (string) $m->og_description;
    $out['ogImage']            = in_array($m->og_image_type, ['custom_image'], true) ? (string) $m->og_image_custom_url : '';
    $out['twitterTitle']       = (string) $m->twitter_title;
    $out['twitterDescription'] = (string) $m->twitter_description;
    $out['twitterImage']       = in_array($m->twitter_image_type, ['custom_image'], true) ? (string) $m->twitter_image_custom_url : '';
    $out['robotsNoindex']      = !$m->robots_default && (bool) $m->robots_noindex;
    $out['robotsNofollow']     = !$m->robots_default && (bool) $m->robots_nofollow;
    $out['cornerstone']        = (bool) $m->pillar_content;
    $kw = '';
    if (!empty($m->focus_keyword) && is_string($m->focus_keyword)) $kw = $m->focus_keyword;
    elseif (is_object($m->keyphrases) && isset($m->keyphrases->focus->keyphrase)) $kw = (string) $m->keyphrases->focus->keyphrase;
    $out['focusKeyword'] = $kw;
    $pt = is_object($m->primary_term) ? $m->primary_term : null;
    $out['primaryCategory'] = ($pt && !empty($pt->category)) ? (int) $pt->category : null;
    return $out;
  }

  public function write($post_id, array $fields) {
    $class = self::MODEL;
    $current = $this->model($post_id);
    if (!$current) return new WP_Error('visibility_seo_aioseo', 'All in One SEO is active but its Post model is not available.');
    list($take, $rest) = $this->partition($fields, $this->fields());
    $data = [];
    foreach ($take as $key => $value) {
      switch ($key) {
        case 'metaTitle':       $data['title'] = self::text($value); break;
        case 'metaDescription': $data['description'] = self::text($value); break;
        case 'canonical':       $data['canonicalUrl'] = self::url($value); break;
        case 'ogTitle':         $data['og_title'] = self::text($value); break;
        case 'ogDescription':   $data['og_description'] = self::text($value); break;
        case 'ogImage':
          $data['og_image_type']       = $value ? 'custom_image' : 'default';
          $data['og_image_custom_url'] = self::url($value);
          break;
        case 'twitterTitle':       $data['twitter_title'] = self::text($value); $data['twitter_use_og'] = false; break;
        case 'twitterDescription': $data['twitter_description'] = self::text($value); $data['twitter_use_og'] = false; break;
        case 'twitterImage':
          $data['twitter_use_og']           = false;
          $data['twitter_image_type']       = $value ? 'custom_image' : 'default';
          $data['twitter_image_custom_url'] = self::url($value);
          break;
        case 'focusKeyword':
          $kw = self::text($value);
          $data['focus_keyword'] = $kw;
          $kp = json_decode(wp_json_encode($current->keyphrases ?: new \stdClass()), true);
          if (!is_array($kp)) $kp = [];
          if (!isset($kp['focus']) || !is_array($kp['focus'])) $kp['focus'] = [];
          $kp['focus']['keyphrase'] = $kw;
          if (!isset($kp['additional'])) $kp['additional'] = [];
          $data['keyphrases'] = $kp;
          break;
        case 'robotsNoindex':
        case 'robotsNofollow':
          // Per-post robots only apply when "use default settings" is off.
          $noindex  = array_key_exists('robotsNoindex', $take) ? (bool) $take['robotsNoindex'] : (!$current->robots_default && (bool) $current->robots_noindex);
          $nofollow = array_key_exists('robotsNofollow', $take) ? (bool) $take['robotsNofollow'] : (!$current->robots_default && (bool) $current->robots_nofollow);
          $data['default']  = !$noindex && !$nofollow;
          $data['noindex']  = $noindex;
          $data['nofollow'] = $nofollow;
          break;
        case 'cornerstone':
          $data['pillar_content'] = (bool) $value;
          break;
        case 'primaryCategory':
          $pt = json_decode(wp_json_encode($current->primary_term ?: new \stdClass()), true);
          if (!is_array($pt)) $pt = [];
          if ($value) $pt['category'] = (int) $value; else unset($pt['category']);
          $data['primary_term'] = empty($pt) ? null : $pt;
          break;
      }
    }
    if (!empty($data)) {
      $err = $class::savePost($post_id, $data);
      if (is_string($err) && $err !== '') return new WP_Error('visibility_seo_aioseo', 'All in One SEO could not save: ' . $err);
      if (isset(aioseo()->meta->metaData) && method_exists(aioseo()->meta->metaData, 'bustPostCache')) {
        aioseo()->meta->metaData->bustPostCache($post_id);
      }
      clean_post_cache($post_id);
    }
    return ['written' => array_keys($take), 'unsupported' => $rest];
  }

  public function rendered($post_id) {
    if (!function_exists('aioseo')) return null;
    $post = get_post($post_id);
    if (!$post) return null;
    try {
      $a = aioseo();
      $robots = '';
      $m = $this->model($post_id);
      if ($m && !$m->robots_default) {
        $robots = implode(', ', [$m->robots_noindex ? 'noindex' : 'index', $m->robots_nofollow ? 'nofollow' : 'follow']);
      }
      $img = $a->social->facebook->getImage($post_id);
      $tw  = $a->social->twitter->getImage($post_id);
      $canonical = ($m && $m->canonical_url) ? (string) $m->canonical_url : (string) get_permalink($post);
      return [
        'title'              => (string) $a->meta->title->getPostTitle($post),
        'description'        => (string) $a->meta->description->getPostDescription($post),
        'canonical'          => $canonical,
        'robots'             => $robots,
        'ogTitle'            => (string) $a->social->facebook->getTitle($post),
        'ogDescription'      => (string) $a->social->facebook->getDescription($post),
        'ogImage'            => is_array($img) ? (string) $img[0] : (string) $img,
        'twitterTitle'       => (string) $a->social->twitter->getTitle($post),
        'twitterDescription' => (string) $a->social->twitter->getDescription($post),
        'twitterImage'       => is_array($tw) ? (string) $tw[0] : (string) $tw,
      ];
    } catch (\Throwable $e) {
      return null;
    }
  }
}

/** Detected but not written yet (phase 2: #314–#316). Present so the fallback head stands down. */
class Visibility_SEO_Adapter_DetectOnly extends Visibility_SEO_Adapter {
  private $slug; private $name; private $version;
  public function __construct($slug, $name, $version) { $this->slug = $slug; $this->name = $name; $this->version = $version; }
  public function slug() { return $this->slug; }
  public function name() { return $this->name; }
  public function version() { return $this->version; }
  public function fields() { return []; }
  public function writable() { return false; }
  public function read($post_id) { return self::blank(); }
}

/** Entry point: detection, read and write through the active adapter. */
class Visibility_SEO {

  /** The active SEO plugin's adapter, or the no-plugin one. */
  public static function adapter() {
    if (defined('WPSEO_VERSION')) return new Visibility_SEO_Adapter_Yoast();
    if (defined('RANK_MATH_VERSION')) return new Visibility_SEO_Adapter_RankMath();
    if (defined('AIOSEO_VERSION') || class_exists('AIOSEO\\Plugin\\AIOSEO')) return new Visibility_SEO_Adapter_AIOSEO();
    if (defined('SEOPRESS_VERSION')) return new Visibility_SEO_Adapter_DetectOnly('seopress', 'SEOPress', SEOPRESS_VERSION);
    if (defined('THE_SEO_FRAMEWORK_VERSION') || function_exists('the_seo_framework')) return new Visibility_SEO_Adapter_DetectOnly('tsf', 'The SEO Framework', defined('THE_SEO_FRAMEWORK_VERSION') ? THE_SEO_FRAMEWORK_VERSION : null);
    if (defined('SLIM_SEO_VER')) return new Visibility_SEO_Adapter_DetectOnly('slimseo', 'Slim SEO', SLIM_SEO_VER);
    return new Visibility_SEO_Adapter_None();
  }

  /** True when an SEO plugin (any detected one) owns the <head>. */
  public static function plugin_active() {
    return self::adapter()->slug() !== null;
  }

  /** Stored values + what the front end renders, for the read endpoint. */
  public static function read_post($post_id) {
    $a = self::adapter();
    $values = $a->read($post_id);
    if (class_exists('Visibility_Schema')) $values['schemaJsonLd'] = Visibility_Schema::get($post_id);
    return [
      'plugin'          => $a->slug(),
      'values'          => $values,
      'rendered'        => $a->rendered($post_id),
      'supportedFields' => $a->writable() ? $a->fields() : [],
    ];
  }
}
