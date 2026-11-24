<?php
/**
 * Site profile (#471): how this site is built, so agents can write the way its
 * own posts are written. GET /profile returns it; /capabilities carries a short
 * summary with a hash so Visibility only fetches it again when it changes.
 *
 * Read-only and cached. Never includes secrets, user data, drafts or private
 * (underscore) meta keys; options come from a fixed allowlist only. The owner
 * can turn it off on the plugin's settings page.
 */

if (!defined('ABSPATH')) {
  exit;
}

class Visibility_Profile {

  const CACHE = 'visibility_site_profile';
  const OFF_OPTION = 'visibility_profile_off';
  /** Recent published posts / pages looked at. */
  const RECENT = 20;
  const MAX_TERMS = 200;
  /** Site options that matter for content; nothing else is ever read. */
  const OPTIONS = ['permalink_structure', 'show_on_front', 'page_on_front', 'page_for_posts', 'default_category', 'blogname', 'blogdescription', 'timezone_string', 'posts_per_page', 'WPLANG'];

  public static function init() {
    foreach (['save_post', 'deleted_post', 'created_term', 'edited_term', 'delete_term', 'switch_theme', 'activated_plugin', 'deactivated_plugin'] as $hook) {
      add_action($hook, [__CLASS__, 'forget']);
    }
  }

  public static function forget() {
    delete_transient(self::CACHE);
  }

  public static function enabled() {
    return get_option(self::OFF_OPTION, '') !== '1';
  }

  /** The summary in /capabilities: on/off, hash and when it was built. */
  public static function summary() {
    if (!self::enabled()) return ['enabled' => false];
    $p = self::get();
    return ['enabled' => true, 'hash' => $p['hash'], 'builtAt' => $p['builtAt']];
  }

  /** The profile, from cache (an hour) or built now. */
  public static function get() {
    $cached = get_transient(self::CACHE);
    if (is_array($cached) && isset($cached['hash'])) return $cached;
    $p = self::build();
    $p['hash'] = md5(wp_json_encode($p));
    $p['builtAt'] = gmdate('c');
    set_transient(self::CACHE, $p, HOUR_IN_SECONDS);
    return $p;
  }

  public static function build() {
    $posts = self::recent('post');
    $pages = self::recent('page');
    return [
      'editor'       => Visibility_Read::type_editor('post'),
      'blockTheme'   => function_exists('wp_is_block_theme') ? (bool) wp_is_block_theme() : false,
      'seoPlugin'    => class_exists('Visibility_SEO') ? Visibility_SEO::adapter()->name() : null,
      'blocks'       => self::blocks(array_merge($posts, $pages)),
      'taxonomies'   => self::taxonomies(),
      'postMeta'     => self::post_meta(array_merge($posts, $pages)),
      'options'      => self::options(),
      'postPatterns' => self::patterns($posts),
      'templates'    => self::templates(),
    ];
  }

  /** @return WP_Post[] recent published posts of a type */
  private static function recent($type) {
    if (!post_type_exists($type)) return [];
    return get_posts(['post_type' => $type, 'post_status' => 'publish', 'numberposts' => self::RECENT, 'orderby' => 'date', 'order' => 'DESC', 'suppress_filters' => true]);
  }

  /** Every block in a post, nested ones included, as [name, block]. */
  private static function walk(array $blocks, array &$out) {
    foreach ($blocks as $b) {
      if (!empty($b['blockName'])) $out[] = $b;
      if (!empty($b['innerBlocks'])) self::walk($b['innerBlocks'], $out);
    }
  }

  private static function blocks(array $posts) {
    $registered = [];
    if (class_exists('WP_Block_Type_Registry')) {
      foreach (WP_Block_Type_Registry::get_instance()->get_all_registered() as $name => $t) {
        $registered[] = ['name' => $name, 'title' => isset($t->title) ? (string) $t->title : '', 'category' => isset($t->category) ? (string) $t->category : null];
      }
    }
    $count = []; $in = []; $sample = [];
    foreach ($posts as $post) {
      if (!has_blocks($post->post_content)) continue;
      $all = [];
      self::walk(parse_blocks($post->post_content), $all);
      $seen = [];
      foreach ($all as $b) {
        $n = $b['blockName'];
        $count[$n] = ($count[$n] ?? 0) + 1;
        if (!isset($seen[$n])) { $in[$n] = ($in[$n] ?? 0) + 1; $seen[$n] = true; }
        // A short sample of the site's own markup for blocks that aren't plain core text.
        if (!isset($sample[$n]) && !in_array($n, ['core/paragraph', 'core/heading', 'core/list', 'core/list-item'], true)) {
          $sample[$n] = mb_substr(serialize_block($b), 0, 800);
        }
      }
    }
    arsort($count);
    $used = [];
    foreach ($count as $n => $c) {
      $used[] = ['name' => $n, 'count' => $c, 'posts' => $in[$n]] + (isset($sample[$n]) ? ['sample' => $sample[$n]] : []);
      if (count($used) >= 40) break;
    }
    return ['registered' => $registered, 'used' => $used, 'postsRead' => count($posts)];
  }

  private static function taxonomies() {
    $out = [];
    foreach (get_taxonomies(['public' => true], 'objects') as $name => $tax) {
      if ($name === 'post_format') continue;
      $terms = get_terms(['taxonomy' => $name, 'hide_empty' => false, 'number' => self::MAX_TERMS + 1, 'orderby' => 'count', 'order' => 'DESC']);
      if (is_wp_error($terms)) continue;
      $more = count($terms) > self::MAX_TERMS;
      $out[] = [
        'name' => $name, 'label' => (string) $tax->label, 'hierarchical' => (bool) $tax->hierarchical,
        'postTypes' => array_values((array) $tax->object_type),
        'terms' => array_map(function ($t) { return ['id' => (int) $t->term_id, 'name' => $t->name, 'slug' => $t->slug, 'parent' => (int) $t->parent, 'count' => (int) $t->count]; }, array_slice($terms, 0, self::MAX_TERMS)),
        'more' => $more,
      ];
    }
    return $out;
  }

  /** Meta keys seen on recent posts and pages: never private (_) keys or secret-looking names; short scalar samples. */
  private static function post_meta(array $posts) {
    $keys = [];
    foreach ($posts as $post) {
      foreach (get_post_meta($post->ID) as $key => $values) {
        if ($key === '' || $key[0] === '_' || Visibility_Meta::excluded($key)) continue;
        if (!isset($keys[$key])) $keys[$key] = ['key' => $key, 'posts' => 0, 'types' => [], 'sample' => null];
        $keys[$key]['posts']++;
        $keys[$key]['types'][$post->post_type] = true;
        $v = isset($values[0]) ? maybe_unserialize($values[0]) : null;
        if ($keys[$key]['sample'] === null && is_scalar($v) && (string) $v !== '') $keys[$key]['sample'] = mb_substr((string) $v, 0, 100);
      }
    }
    uasort($keys, function ($a, $b) { return $b['posts'] - $a['posts']; });
    return array_values(array_map(function ($k) { $k['types'] = array_keys($k['types']); return $k; }, array_slice($keys, 0, 100)));
  }

  private static function options() {
    $out = [];
    foreach (self::OPTIONS as $o) $out[$o] = get_option($o, null);
    return $out;
  }

  /** How recent blog posts are built: their top-level block order, and the blocks most of them share. */
  private static function patterns(array $posts) {
    $orders = []; $share = []; $n = 0;
    foreach ($posts as $post) {
      if (!has_blocks($post->post_content)) continue;
      $n++;
      $seq = [];
      foreach (parse_blocks($post->post_content) as $b) {
        if (empty($b['blockName'])) continue;
        $name = $b['blockName'] === 'core/heading' ? 'core/heading:h' . (isset($b['attrs']['level']) ? (int) $b['attrs']['level'] : 2) : $b['blockName'];
        if (end($seq) !== $name) $seq[] = $name;
      }
      $orders[] = $seq;
      foreach (array_unique($seq) as $name) $share[$name] = ($share[$name] ?? 0) + 1;
    }
    $common = [];
    foreach ($share as $name => $c) if ($n && $c * 2 >= $n) $common[] = ['block' => $name, 'posts' => $c];
    usort($common, function ($a, $b) { return $b['posts'] - $a['posts']; });
    return ['postsRead' => $n, 'shared' => $common, 'examples' => array_slice($orders, 0, 3)];
  }

  private static function templates() {
    if (function_exists('wp_is_block_theme') && wp_is_block_theme() && function_exists('get_block_template')) {
      $t = get_block_template(get_stylesheet() . '//single', 'wp_template');
      $parts = [];
      if ($t && !empty($t->content)) {
        $all = [];
        self::walk(parse_blocks($t->content), $all);
        foreach ($all as $b) if ($b['blockName'] === 'core/template-part' && !empty($b['attrs']['slug'])) $parts[] = $b['attrs']['slug'];
      }
      return ['kind' => 'block', 'single' => $t ? $t->slug : null, 'parts' => array_values(array_unique($parts)),
        'blocks' => $t && !empty($t->content) ? array_values(array_unique(array_map(function ($b) { return $b['blockName']; }, array_filter(parse_blocks($t->content), function ($b) { return !empty($b['blockName']); })))) : []];
    }
    $file = locate_template(['single-post.php', 'single.php', 'singular.php', 'index.php']);
    return ['kind' => 'classic', 'single' => $file ? basename($file) : null];
  }
}
