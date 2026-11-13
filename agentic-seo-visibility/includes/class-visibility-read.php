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

  /** Builder/theme internals that are "public" but are not site content. */
  const INTERNAL_TYPES = ['attachment', 'elementor_library', 'e-floating-buttons', 'e-landing-page', 'et_pb_layout', 'fl-builder-template', 'bricks_template', 'ct_template', 'breakdance_template', 'wp_block', 'wp_template', 'wp_template_part', 'wp_navigation'];

  /** Post types agents may see: public ones with a UI, never attachments or builder templates. */
  public static function readable_types() {
    $types = get_post_types(['public' => true], 'objects');
    $out = [];
    foreach ($types as $name => $obj) {
      if (in_array($name, self::INTERNAL_TYPES, true) || empty($obj->show_ui)) continue;
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

  // ─── Capabilities ──────────────────────────────────────────────────────

  public static function capabilities() {
    if (!function_exists('get_plugins')) require_once ABSPATH . 'wp-admin/includes/plugin.php';
    $theme = wp_get_theme();
    $seo = Visibility_SEO::adapter();

    $post_types = [];
    foreach (self::readable_types() as $name => $obj) {
      $counts = wp_count_posts($name);
      $c = [];
      foreach (['publish', 'draft', 'pending', 'future', 'private'] as $st) $c[$st] = isset($counts->$st) ? (int) $counts->$st : 0;
      $supports = array_keys(array_filter((array) get_all_post_type_supports($name)));
      $post_types[] = [
        'name'         => $name,
        'label'        => $obj->labels->name,
        'hierarchical' => (bool) $obj->hierarchical,
        'supports'     => $supports,
        'editor'       => self::type_editor($name),
        'writable'     => in_array($name, ['post', 'page'], true),
        'counts'       => $c,
      ];
    }

    $builders = [];
    foreach (self::BUILDERS as $slug => $def) {
      if (defined($def[1])) $builders[] = ['slug' => $slug, 'name' => $def[0], 'version' => (string) constant($def[1]), 'writable' => false];
    }

    $custom_fields = [];
    if (class_exists('ACF')) $custom_fields[] = ['slug' => 'acf', 'name' => defined('ACF_PRO') ? 'Advanced Custom Fields Pro' : 'Advanced Custom Fields', 'version' => defined('ACF_VERSION') ? ACF_VERSION : null, 'writable' => false];
    if (defined('RWMB_VER')) $custom_fields[] = ['slug' => 'metabox', 'name' => 'Meta Box', 'version' => RWMB_VER, 'writable' => false];
    if (defined('PODS_VERSION')) $custom_fields[] = ['slug' => 'pods', 'name' => 'Pods', 'version' => PODS_VERSION, 'writable' => false];
    if (class_exists('Jet_Engine')) $custom_fields[] = ['slug' => 'jetengine', 'name' => 'JetEngine', 'version' => defined('JET_ENGINE_VERSION') ? JET_ENGINE_VERSION : null, 'writable' => false];

    $redirects = null;
    if (defined('REDIRECTION_VERSION')) $redirects = ['slug' => 'redirection', 'name' => 'Redirection', 'version' => REDIRECTION_VERSION];
    elseif (defined('RANK_MATH_VERSION') && class_exists('RankMath\\Redirections\\DB')) $redirects = ['slug' => 'rankmath', 'name' => 'Rank Math redirections', 'version' => RANK_MATH_VERSION];

    $caches = [];
    $cache_defs = [
      'wp-rocket'        => ['WP Rocket', 'WP_ROCKET_VERSION'],
      'litespeed-cache'  => ['LiteSpeed Cache', 'LSCWP_V'],
      'w3-total-cache'   => ['W3 Total Cache', 'W3TC_VERSION'],
      'wp-super-cache'   => ['WP Super Cache', 'WPCACHEHOME'],
      'wp-fastest-cache' => ['WP Fastest Cache', 'WPFC_WP_PLUGIN_DIR'],
      'sg-cachepress'    => ['SiteGround Speed Optimizer', 'SiteGround_Optimizer\\VERSION'],
      'cloudflare'       => ['Cloudflare', 'CLOUDFLARE_PLUGIN_DIR'],
      'breeze'           => ['Breeze', 'BREEZE_VERSION'],
    ];
    foreach ($cache_defs as $slug => $def) {
      if (defined($def[1])) {
        $v = constant($def[1]);
        $caches[] = ['slug' => $slug, 'name' => $def[0], 'version' => preg_match('/^\d/', (string) $v) ? (string) $v : null];
      }
    }
    if (class_exists('WpeCommon')) $caches[] = ['slug' => 'wpengine', 'name' => 'WP Engine cache', 'version' => null];
    if (defined('KINSTAMU_VERSION')) $caches[] = ['slug' => 'kinsta', 'name' => 'Kinsta cache', 'version' => KINSTAMU_VERSION];

    $multilingual = null;
    if (function_exists('pll_languages_list')) {
      $multilingual = ['slug' => 'polylang', 'name' => 'Polylang', 'version' => defined('POLYLANG_VERSION') ? POLYLANG_VERSION : null, 'languages' => array_values((array) pll_languages_list()), 'default' => function_exists('pll_default_language') ? pll_default_language() : null];
    } elseif (defined('ICL_SITEPRESS_VERSION')) {
      $langs = apply_filters('wpml_active_languages', null, ['skip_missing' => 0]);
      $multilingual = ['slug' => 'wpml', 'name' => 'WPML', 'version' => ICL_SITEPRESS_VERSION, 'languages' => is_array($langs) ? array_keys($langs) : [], 'default' => apply_filters('wpml_default_language', null)];
    } elseif (class_exists('TRP_Translate_Press')) {
      $multilingual = ['slug' => 'translatepress', 'name' => 'TranslatePress', 'version' => defined('TRP_PLUGIN_VERSION') ? TRP_PLUGIN_VERSION : null, 'languages' => [], 'default' => null];
    }

    $next = function ($hook) {
      $ts = wp_next_scheduled($hook);
      return $ts ? gmdate('Y-m-d\TH:i:s\Z', $ts) : null;
    };

    return [
      'pluginVersion' => VISIBILITY_PLUGIN_VERSION,
      'wpVersion'     => get_bloginfo('version'),
      'phpVersion'    => PHP_VERSION,
      'multisite'     => is_multisite(),
      'siteUrl'       => home_url('/'),
      'locale'        => get_locale(),
      'theme'         => [
        'slug'       => $theme->get_stylesheet(),
        'name'       => $theme->get('Name'),
        'version'    => $theme->get('Version'),
        'blockTheme' => function_exists('wp_is_block_theme') ? (bool) wp_is_block_theme() : false,
        'childTheme' => is_child_theme(),
        'parent'     => is_child_theme() ? $theme->get_template() : null,
      ],
      'editor'        => self::type_editor('post'),
      'postTypes'     => $post_types,
      'seo'           => [
        'plugin'     => $seo->slug(),
        'name'       => $seo->name(),
        'version'    => $seo->version(),
        'writable'   => $seo->writable(),
        'fields'     => $seo->writable() ? $seo->fields() : [],
        'termSeo'    => $seo->writable() && !empty($seo->term_fields()),
        'termFields' => $seo->writable() ? $seo->term_fields() : [],
        'schema'     => $seo->writable(),
        'renderedBy' => $seo->slug() === null ? 'visibility' : $seo->slug(),
      ],
      'builders'      => $builders,
      'customFields'  => $custom_fields,
      'redirects'     => $redirects,
      'caches'        => $caches,
      'multilingual'  => $multilingual,
      'woocommerce'   => defined('WC_VERSION') ? ['version' => WC_VERSION] : null,
      'permissions'   => [
        'allowPublish' => Visibility_Policy::publishing_allowed(),
        'paused'       => Visibility_Policy::paused(),
      ],
      'cron'          => [
        'wpCronDisabled' => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON,
        'nextPull'       => $next('visibility_pull_approved_requests'),
        'nextHeartbeat'  => $next('visibility_daily_heartbeat'),
      ],
      'features'      => ['read.rich', 'posts.types', 'seo.write', 'seo.terms', 'content.modifiedGmt', 'media.attach', 'links.internal', 'requests.dedupe', 'policy.enforced'],
    ];
  }
}
