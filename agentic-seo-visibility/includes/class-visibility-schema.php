<?php
/**
 * Structured data (JSON-LD) written by Visibility for a post.
 *
 * Stored in `_visibility_schema_jsonld`. When an SEO plugin is active the
 * nodes are merged into that plugin's own graph, so the page has one JSON-LD
 * block rather than two: Yoast `wpseo_schema_graph`, Rank Math
 * `rank_math/json_ld`, All in One SEO `aioseo_schema_output`. With no SEO
 * plugin, `Visibility_SEO_Head` prints its own <script> instead.
 */

if (!defined('ABSPATH')) {
  exit;
}

class Visibility_Schema {

  const META_KEY = '_visibility_schema_jsonld';

  /** Set once the nodes went into an SEO plugin's graph on this request. */
  private static $merged = false;

  public static function init() {
    add_filter('wpseo_schema_graph', [__CLASS__, 'yoast_graph'], 20, 2);
    add_filter('rank_math/json_ld', [__CLASS__, 'rankmath_json_ld'], 99, 2);
    add_filter('aioseo_schema_output', [__CLASS__, 'aioseo_graph'], 20, 1);
    // SEOPress (single @graph, when its graph setting is on), The SEO
    // Framework and Slim SEO: merge into their graph when they print one.
    add_filter('seopress_schemas_graph', [__CLASS__, 'append_nodes'], 20, 1);
    add_filter('the_seo_framework_schema_graph_data', [__CLASS__, 'append_nodes'], 20, 1);
    add_filter('slim_seo_schema_graph', [__CLASS__, 'append_nodes'], 20, 1);
    // Their graph can be turned off: then print ours once, at the very end.
    add_action('wp_footer', [__CLASS__, 'fallback_script'], 999);
  }

  /** A plain list of nodes (SEOPress, The SEO Framework, Slim SEO). */
  public static function append_nodes($graph) {
    $id = self::current_post_id();
    if (!$id || !is_array($graph) || self::$merged) return $graph;
    $nodes = self::nodes($id);
    if (!$nodes) return $graph;
    foreach ($nodes as $node) $graph[] = $node;
    self::$merged = true;
    return $graph;
  }

  /**
   * SEO plugins whose graph may be off: when no graph took our nodes, print
   * them as our own JSON-LD block. Yoast, Rank Math and AIOSEO always print a
   * graph; with no SEO plugin Visibility_SEO_Head prints it.
   */
  public static function fallback_script() {
    if (self::$merged || !class_exists('Visibility_SEO')) return;
    $slug = Visibility_SEO::adapter()->slug();
    if (!in_array($slug, ['seopress', 'tsf', 'slimseo'], true)) return;
    $id = self::current_post_id();
    if (!$id) return;
    $nodes = self::nodes($id);
    if (!$nodes) return;
    self::$merged = true;
    $doc = ['@context' => 'https://schema.org', '@graph' => $nodes];
    echo "<script type=\"application/ld+json\" class=\"visibility-schema\">" . wp_json_encode($doc, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) . "</script>\n";
  }

  /** Store (or clear, on null/empty) the schema for a post. */
  public static function store($post_id, $value) {
    if ($value === null || $value === '' || $value === []) {
      delete_post_meta($post_id, self::META_KEY);
      return;
    }
    if (is_string($value)) {
      $decoded = json_decode($value, true);
      if (is_array($decoded)) $value = $decoded;
    }
    update_post_meta($post_id, self::META_KEY, wp_slash(wp_json_encode($value)));
  }

  /** The stored value as written (object/array), or null. */
  public static function get($post_id) {
    $raw = get_post_meta($post_id, self::META_KEY, true);
    if (!is_string($raw) || trim($raw) === '') return null;
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
  }

  /**
   * The stored schema as a flat list of graph nodes, without @context:
   * accepts one node, a list of nodes, or a {"@graph": [...]} document.
   */
  public static function nodes($post_id) {
    $value = self::get($post_id);
    if (!$value) return [];
    if (isset($value['@graph']) && is_array($value['@graph'])) {
      $list = $value['@graph'];
    } elseif (array_keys($value) === range(0, count($value) - 1)) {
      $list = $value;
    } else {
      $list = [$value];
    }
    $out = [];
    foreach ($list as $node) {
      if (!is_array($node) || empty($node)) continue;
      unset($node['@context']);
      $out[] = $node;
    }
    return $out;
  }

  private static function current_post_id() {
    if (!is_singular()) return 0;
    return (int) get_queried_object_id();
  }

  public static function yoast_graph($graph, $context = null) {
    $id = self::current_post_id();
    if (!$id || !is_array($graph)) return $graph;
    foreach (self::nodes($id) as $node) $graph[] = $node;
    return $graph;
  }

  public static function rankmath_json_ld($data, $jsonld = null) {
    $id = self::current_post_id();
    if (!$id || !is_array($data)) return $data;
    foreach (self::nodes($id) as $i => $node) $data['visibility_' . $i] = $node;
    return $data;
  }

  public static function aioseo_graph($graph) {
    $id = self::current_post_id();
    if (!$id || !is_array($graph)) return $graph;
    foreach (self::nodes($id) as $node) $graph[] = $node;
    return $graph;
  }
}
