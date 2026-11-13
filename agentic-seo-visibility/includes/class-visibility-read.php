<?php
/**
 * Read side for agents: what a post looks like (rich read, #284) and what the
 * site has (capabilities, #294).
 */

if (!defined('ABSPATH')) {
  exit;
}

class Visibility_Read {

  /** Builders we recognise: slug => [name, version constant, post meta that marks a builder page]. */
  const BUILDERS = [
    'elementor'      => ['Elementor', 'ELEMENTOR_VERSION', '_elementor_edit_mode'],
    'divi'           => ['Divi Builder', 'ET_BUILDER_VERSION', '_et_pb_use_builder'],
    'wpbakery'       => ['WPBakery Page Builder', 'WPB_VC_VERSION', '_wpb_vc_js_status'],
    'beaver-builder' => ['Beaver Builder', 'FL_BUILDER_VERSION', '_fl_builder_enabled'],
    'bricks'         => ['Bricks', 'BRICKS_VERSION', '_bricks_editor_mode'],
    'oxygen'         => ['Oxygen', 'CT_VERSION', 'ct_builder_shortcodes'],
    'breakdance'     => ['Breakdance', '__BREAKDANCE_VERSION', '_breakdance_data'],
  ];

  // ─── Post types ────────────────────────────────────────────────────────

  /** Post types agents may see: public ones with a UI, never attachments. */
  public static function readable_types() {
    $types = get_post_types(['public' => true], 'objects');
    $out = [];
    foreach ($types as $name => $obj) {
      if ($name === 'attachment' || empty($obj->show_ui)) continue;
      $out[$name] = $obj;
    }
    /** Lets a site hide post types from Visibility. */
    $allowed = apply_filters('visibility_post_types', array_keys($out));
    return array_intersect_key($out, array_flip((array) $allowed));
  }

  public static function type_allowed($type) {
    return isset(self::readable_types()[$type]);
  }

  /** "block" or "classic" for new content of a post type. */
  public static function type_editor($type) {
    if (!function_exists('use_block_editor_for_post_type')) {
      require_once ABSPATH . 'wp-admin/includes/post.php';
    }
    return use_block_editor_for_post_type($type) ? 'block' : 'classic';
  }

  // ─── One post ──────────────────────────────────────────────────────────

  /** "gutenberg" | "classic" | "builder:<slug>" for an existing post. */
  public static function editor_of($post) {
    $b = self::builder_of($post);
    if ($b) return 'builder:' . $b['slug'];
    return has_blocks($post->post_content) ? 'gutenberg' : 'classic';
  }

  public static function builder_of($post) {
    foreach (self::BUILDERS as $slug => $def) {
      if (!defined($def[1])) continue;
      $v = get_post_meta($post->ID, $def[2], true);
      if (!empty($v) && $v !== 'off') {
        return ['slug' => $slug, 'name' => $def[0], 'writable' => false];
      }
    }
    return null;
  }

  /**
   * Block tree with stable paths ("3", "3.0", "3.0.1"). Whitespace-only
   * "freeform" blocks between blocks are skipped but still counted, so a path
   * is the index into parse_blocks() at each level.
   */
  public static function blocks($content) {
    if (!has_blocks($content)) return [];
    return self::block_nodes(parse_blocks($content), '');
  }

  private static function block_nodes($blocks, $prefix) {
    $out = [];
    foreach ($blocks as $i => $b) {
      $path = $prefix === '' ? (string) $i : $prefix . '.' . $i;
      if ($b['blockName'] === null && trim((string) $b['innerHTML']) === '') continue;
      $out[] = [
        'path'        => $path,
        'blockName'   => $b['blockName'],
        'attrs'       => empty($b['attrs']) ? new stdClass() : $b['attrs'],
        'text'        => trim(preg_replace('/\s+/', ' ', wp_strip_all_tags((string) $b['innerHTML']))),
        'innerBlocks' => self::block_nodes($b['innerBlocks'], $path),
      ];
    }
    return $out;
  }

  /** Images used in the content: [{id, url, alt}]. */
  public static function images($content) {
    $out = [];
    if (!preg_match_all('/<img\b[^>]*>/i', (string) $content, $m)) return $out;
    foreach ($m[0] as $tag) {
      $url = preg_match('/\ssrc=["\']([^"\']+)["\']/i', $tag, $mm) ? html_entity_decode($mm[1]) : '';
      $alt = preg_match('/\salt=["\']([^"\']*)["\']/i', $tag, $mm) ? html_entity_decode($mm[1]) : '';
      $id  = preg_match('/wp-image-(\d+)/', $tag, $mm) ? (int) $mm[1] : 0;
      if (!$id && $url !== '') $id = (int) attachment_url_to_postid($url);
      $out[] = ['id' => $id ?: null, 'url' => $url, 'alt' => $alt];
    }
    return $out;
  }

  /**
   * Custom fields agents may read: post meta registered with show_in_rest for
   * this post type (core's own opt-in for "public" meta), never protected (_)
   * keys. Read-only in this version.
   */
  public static function fields($post) {
    $out = [];
    $registered = get_registered_meta_keys('post', $post->post_type);
    $registered = array_merge(get_registered_meta_keys('post', ''), $registered);
    foreach ($registered as $key => $args) {
      if (empty($args['show_in_rest']) || is_protected_meta($key, 'post')) continue;
      $single = !empty($args['single']);
      $value = get_post_meta($post->ID, $key, $single);
      $out[] = [
        'key'      => $key,
        'label'    => !empty($args['description']) ? $args['description'] : $key,
        'type'     => isset($args['type']) ? $args['type'] : 'string',
        'value'    => $value,
        'writable' => false,
      ];
    }
    return $out;
  }

  public static function terms($post) {
    $out = [];
    foreach (get_object_taxonomies($post->post_type, 'objects') as $tax) {
      if (empty($tax->public) && $tax->name !== 'category' && $tax->name !== 'post_tag') continue;
      $terms = get_the_terms($post->ID, $tax->name);
      if (is_wp_error($terms) || !$terms) {
        $out[$tax->name] = [];
        continue;
      }
      $out[$tax->name] = array_map(function ($t) {
        return ['id' => (int) $t->term_id, 'name' => $t->name, 'slug' => $t->slug];
      }, array_values($terms));
    }
    return (object) $out;
  }
}
