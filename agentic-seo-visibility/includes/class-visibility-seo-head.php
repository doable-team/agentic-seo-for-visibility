<?php
/**
 * Emits SEO tags when no SEO plugin is installed.
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

  /** Our own postmeta keys — namespaced so they can never collide with a plugin's. */
  const KEYS = [
    'metaTitle'       => '_visibility_seo_title',
    'metaDescription' => '_visibility_seo_description',
    'focusKeyword'    => '_visibility_seo_focus_keyword',
    'canonical'       => '_visibility_seo_canonical',
    'ogTitle'         => '_visibility_seo_og_title',
    'ogDescription'   => '_visibility_seo_og_description',
    'ogImage'         => '_visibility_seo_og_image',
    'robotsNoindex'   => '_visibility_seo_noindex',
    'robotsNofollow'  => '_visibility_seo_nofollow',
  ];

  public static function init() {
    // Priority 5: ahead of core's rel_canonical (10) so our canonical wins
    // when one was set explicitly for the post.
    add_action('wp_head', [__CLASS__, 'render'], 5);
  }

  /**
   * True when we are the only thing that would emit these tags.
   *
   * Mirrors `Visibility_Executor::detect_seo_plugin()`. Kept as its own check
   * so the renderer has no load-order dependency on the executor class.
   */
  public static function no_seo_plugin_active() {
    if (defined('WPSEO_VERSION'))     return false;   // Yoast
    if (defined('RANK_MATH_VERSION')) return false;   // Rank Math
    if (defined('AIOSEO_VERSION') || class_exists('AIOSEO\\Plugin\\AIOSEO')) return false;
    return true;
  }

  private static function meta($post_id, $field) {
    $key = self::KEYS[$field] ?? null;
    if (!$key) return '';
    $value = get_post_meta($post_id, $key, true);
    return is_string($value) ? trim($value) : '';
  }

  public static function render() {
    // An active SEO plugin owns the head. Never double-emit.
    if (!self::no_seo_plugin_active()) return;
    if (!is_singular()) return;

    $post_id = get_queried_object_id();
    if (!$post_id) return;

    $description = self::meta($post_id, 'metaDescription');
    $canonical   = self::meta($post_id, 'canonical');
    $og_title    = self::meta($post_id, 'ogTitle');
    $og_desc     = self::meta($post_id, 'ogDescription');
    $og_image    = self::meta($post_id, 'ogImage');
    $noindex     = self::meta($post_id, 'robotsNoindex') === '1';
    $nofollow    = self::meta($post_id, 'robotsNofollow') === '1';

    // Fall back to the post's own excerpt/featured image before emitting
    // nothing — a description is the single highest-value tag here.
    if ($description === '') {
      $excerpt = get_the_excerpt($post_id);
      if (is_string($excerpt)) $description = trim(wp_strip_all_tags($excerpt));
    }
    if ($og_title === '')  $og_title = get_the_title($post_id);
    if ($og_desc === '')   $og_desc  = $description;
    if ($og_image === '')  $og_image = (string) get_the_post_thumbnail_url($post_id, 'full');

    echo "\n<!-- Agentic SEO for Visibility -->\n";

    if ($description !== '') {
      printf("<meta name=\"description\" content=\"%s\" />\n", esc_attr(self::clamp($description, 320)));
    }
    if ($noindex || $nofollow) {
      $directives = [$noindex ? 'noindex' : 'index', $nofollow ? 'nofollow' : 'follow'];
      printf("<meta name=\"robots\" content=\"%s\" />\n", esc_attr(implode(',', $directives)));
    }
    if ($canonical !== '') {
      // Ours wins: core's rel_canonical runs later at priority 10, so remove it.
      remove_action('wp_head', 'rel_canonical');
      printf("<link rel=\"canonical\" href=\"%s\" />\n", esc_url($canonical));
    }

    // Open Graph — core emits none of this.
    if ($og_title !== '') printf("<meta property=\"og:title\" content=\"%s\" />\n", esc_attr(self::clamp($og_title, 200)));
    if ($og_desc !== '')  printf("<meta property=\"og:description\" content=\"%s\" />\n", esc_attr(self::clamp($og_desc, 320)));
    if ($og_image !== '') printf("<meta property=\"og:image\" content=\"%s\" />\n", esc_url($og_image));
    printf("<meta property=\"og:type\" content=\"article\" />\n");
    printf("<meta property=\"og:url\" content=\"%s\" />\n", esc_url($canonical !== '' ? $canonical : get_permalink($post_id)));

    // Twitter reads og:* for the rest; only the card type is its own.
    printf("<meta name=\"twitter:card\" content=\"%s\" />\n", $og_image !== '' ? 'summary_large_image' : 'summary');

    // Structured data the executor has been storing since 0.7.x, finally rendered.
    $schema = get_post_meta($post_id, '_visibility_schema_jsonld', true);
    if (is_string($schema) && trim($schema) !== '') {
      $decoded = json_decode($schema, true);
      if (is_array($decoded)) {
        printf("<script type=\"application/ld+json\">%s</script>\n", wp_json_encode($decoded));
      }
    }

    echo "<!-- /Agentic SEO for Visibility -->\n";
  }

  private static function clamp($text, $max) {
    $text = preg_replace('/\s+/', ' ', (string) $text);
    if (function_exists('mb_strlen') && mb_strlen($text) > $max) {
      return rtrim(mb_substr($text, 0, $max - 1)) . '…';
    }
    if (strlen($text) > $max) return rtrim(substr($text, 0, $max - 1)) . '…';
    return $text;
  }
}
