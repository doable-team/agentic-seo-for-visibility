<?php
/**
 * Emits SEO tags when no SEO plugin is installed (title, description,
 * robots, canonical, Open Graph, Twitter, JSON-LD) for posts and category/tag
 * archives. Stands down for every detected SEO plugin; the
 * `visibility_seo_head_enabled` filter can switch it off (or on).
 *
 * WordPress core has no meta description, no editable SEO title, and no Open
 * Graph tags at all — it only auto-emits `rel=canonical` (2.9+) and a
 * site-wide `robots` (5.7+), neither of which is per-post editable. So without
 * Yoast / Rank Math / AIO SEO, everything an agent writes for a post has
 * nowhere to land.
 *
 * Rather than refuse (the old `exec_seo_meta_update` behaviour) or write meta
 * that nothing reads, we store the fields under our own keys and render them
 * ourselves — but ONLY while no SEO plugin is active. The moment one is
 * installed it takes over, and this stays silent so no tag is ever emitted
 * twice. Nothing here is authoritative: the SEO plugin always wins.
 *
 * This is also where `_visibility_schema_jsonld` finally gets used — the
 * executor has been writing it since 0.7.x and nothing ever rendered it.
 */

if (!defined('ABSPATH')) {
  exit;
}

class Visibility_SEO_Head {

  /** Our own post meta keys (kept for callers of 0.9.x). */
  const KEYS = Visibility_SEO_Adapter_None::KEYS;

  public static function init() {
    // Priority 5: ahead of core's rel_canonical (10) so our canonical wins
    // when one was set explicitly for the post.
    add_action('wp_head', [__CLASS__, 'render'], 5);
    add_filter('pre_get_document_title', [__CLASS__, 'document_title'], 20);
  }

  /**
   * True when we are the only thing that would emit these tags: no supported
   * or detected SEO plugin (Yoast, Rank Math, AIOSEO, SEOPress, The SEO
   * Framework, Slim SEO), and the `visibility_seo_head_enabled` filter allows it.
   */
  public static function no_seo_plugin_active() {
    return !Visibility_SEO::plugin_active();
  }

  public static function enabled() {
    return (bool) apply_filters('visibility_seo_head_enabled', self::no_seo_plugin_active());
  }

  /** The SEO title set for the queried post or term, if any. */
  public static function document_title($title) {
    if (!self::enabled()) return $title;
    $v = self::current_values();
    return ($v && $v['title'] !== '') ? $v['title'] : $title;
  }

  /** Values for the current request: a singular post or a category/tag archive. */
  private static function current_values() {
    if (is_singular()) {
      $id = get_queried_object_id();
      return $id ? self::values_for_post($id) : null;
    }
    if (is_category() || is_tag() || is_tax()) {
      $term = get_queried_object();
      return ($term && isset($term->term_id)) ? self::values_for_term($term) : null;
    }
    return null;
  }

  /**
   * What the fallback renderer outputs for a post (also the read endpoint's
   * `seo.rendered` on sites without an SEO plugin).
   */
  public static function values_for_post($post_id) {
    $a = new Visibility_SEO_Adapter_None();
    $v = $a->read($post_id);
    $description = $v['metaDescription'];
    if ($description === '') {
      $post = get_post($post_id);
      $excerpt = $post ? ($post->post_excerpt !== '' ? $post->post_excerpt : wp_trim_words(strip_shortcodes(wp_strip_all_tags(excerpt_remove_blocks($post->post_content))), 55, '')) : '';
      $description = trim(wp_strip_all_tags((string) $excerpt));
    }
    $og_title = $v['ogTitle'] !== '' ? $v['ogTitle'] : ($v['metaTitle'] !== '' ? $v['metaTitle'] : get_the_title($post_id));
    $og_desc  = $v['ogDescription'] !== '' ? $v['ogDescription'] : $description;
    $og_image = $v['ogImage'] !== '' ? $v['ogImage'] : (string) get_the_post_thumbnail_url($post_id, 'full');
    $canonical = $v['canonical'];
    return [
      'title'              => $v['metaTitle'],
      'description'        => self::clamp($description, 320),
      'canonical'          => $canonical !== '' ? $canonical : (string) get_permalink($post_id),
      'canonicalSet'       => $canonical !== '',
      'robots'             => ($v['robotsNoindex'] || $v['robotsNofollow']) ? implode(',', [$v['robotsNoindex'] ? 'noindex' : 'index', $v['robotsNofollow'] ? 'nofollow' : 'follow']) : '',
      'ogTitle'            => self::clamp($og_title, 200),
      'ogDescription'      => self::clamp($og_desc, 320),
      'ogImage'            => $og_image,
      'ogType'             => 'article',
      'twitterTitle'       => $v['twitterTitle'],
      'twitterDescription' => $v['twitterDescription'],
      'twitterImage'       => $v['twitterImage'],
    ];
  }

  public static function values_for_term($term) {
    $a = new Visibility_SEO_Adapter_None();
    $v = $a->read_term($term->term_id, $term->taxonomy);
    $description = $v['metaDescription'] !== '' ? $v['metaDescription'] : trim(wp_strip_all_tags(term_description($term)));
    $canonical = $v['canonical'];
    $link = get_term_link($term);
    return [
      'title'              => $v['metaTitle'],
      'description'        => self::clamp($description, 320),
      'canonical'          => $canonical !== '' ? $canonical : (is_wp_error($link) ? '' : $link),
      'canonicalSet'       => $canonical !== '',
      'robots'             => ($v['robotsNoindex'] || $v['robotsNofollow']) ? implode(',', [$v['robotsNoindex'] ? 'noindex' : 'index', $v['robotsNofollow'] ? 'nofollow' : 'follow']) : '',
      'ogTitle'            => self::clamp($v['ogTitle'] !== '' ? $v['ogTitle'] : ($v['metaTitle'] !== '' ? $v['metaTitle'] : $term->name), 200),
      'ogDescription'      => self::clamp($v['ogDescription'] !== '' ? $v['ogDescription'] : $description, 320),
      'ogImage'            => $v['ogImage'],
      'ogType'             => 'website',
      'twitterTitle'       => '',
      'twitterDescription' => '',
      'twitterImage'       => '',
    ];
  }

  public static function render() {
    // An active SEO plugin owns the head. Never double-emit.
    if (!self::enabled()) return;
    $v = self::current_values();
    if (!$v) return;

    echo "\n<!-- Agentic SEO for Visibility -->\n";
    if ($v['description'] !== '') {
      printf("<meta name=\"description\" content=\"%s\" />\n", esc_attr($v['description']));
    }
    if ($v['robots'] !== '') {
      // Core prints its own robots tag only for noindex sites; ours is per page.
      printf("<meta name=\"robots\" content=\"%s\" />\n", esc_attr($v['robots']));
    }
    if ($v['canonicalSet']) {
      // Ours wins: core's rel_canonical runs later at priority 10, so remove it.
      remove_action('wp_head', 'rel_canonical');
      printf("<link rel=\"canonical\" href=\"%s\" />\n", esc_url($v['canonical']));
    }

    // Open Graph — core emits none of this.
    if ($v['ogTitle'] !== '') printf("<meta property=\"og:title\" content=\"%s\" />\n", esc_attr($v['ogTitle']));
    if ($v['ogDescription'] !== '') printf("<meta property=\"og:description\" content=\"%s\" />\n", esc_attr($v['ogDescription']));
    if ($v['ogImage'] !== '') printf("<meta property=\"og:image\" content=\"%s\" />\n", esc_url($v['ogImage']));
    printf("<meta property=\"og:type\" content=\"%s\" />\n", esc_attr($v['ogType']));
    if ($v['canonical'] !== '') printf("<meta property=\"og:url\" content=\"%s\" />\n", esc_url($v['canonical']));

    // Twitter reads og:* for anything not set here.
    $tw_image = $v['twitterImage'] !== '' ? $v['twitterImage'] : $v['ogImage'];
    printf("<meta name=\"twitter:card\" content=\"%s\" />\n", $tw_image !== '' ? 'summary_large_image' : 'summary');
    if ($v['twitterTitle'] !== '') printf("<meta name=\"twitter:title\" content=\"%s\" />\n", esc_attr(self::clamp($v['twitterTitle'], 200)));
    if ($v['twitterDescription'] !== '') printf("<meta name=\"twitter:description\" content=\"%s\" />\n", esc_attr(self::clamp($v['twitterDescription'], 320)));
    if ($v['twitterImage'] !== '') printf("<meta name=\"twitter:image\" content=\"%s\" />\n", esc_url($v['twitterImage']));

    // Structured data written for the post.
    if (is_singular()) {
      $post_id = get_queried_object_id();
      $schema = class_exists('Visibility_Schema') ? Visibility_Schema::get($post_id) : null;
      if (is_array($schema)) {
        if (!isset($schema['@context']) && !isset($schema[0])) $schema = ['@context' => 'https://schema.org'] + $schema;
        if (isset($schema[0])) $schema = ['@context' => 'https://schema.org', '@graph' => Visibility_Schema::nodes($post_id)];
        printf("<script type=\"application/ld+json\">%s</script>\n", wp_json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG));
      }
    }

    echo "<!-- /Agentic SEO for Visibility -->\n";
  }

  private static function clamp($text, $max) {
    $text = trim((string) preg_replace('/\s+/', ' ', (string) $text));
    if (function_exists('mb_strlen') && mb_strlen($text) > $max) {
      return rtrim(mb_substr($text, 0, $max - 1)) . '…';
    }
    if (strlen($text) > $max) return rtrim(substr($text, 0, $max - 1)) . '…';
    return $text;
  }
}
