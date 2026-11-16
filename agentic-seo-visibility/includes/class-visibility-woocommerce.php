<?php
/**
 * WooCommerce products and product categories (#289).
 *
 * Agents may change a product's words and how it is found: name, long and
 * short description, slug, image alt text (through media.update_meta) and its
 * SEO fields (through the SEO adapters, like any post). Product categories:
 * name, slug and description (term.update) and their SEO (seo_meta.update with
 * termId + taxonomy product_cat).
 *
 * Never price, stock, SKU, tax, shipping or variation data: those are only
 * shown read-only, and any request that names one is refused with
 * `not_allowed`, before anything is written.
 *
 * Writes go through WooCommerce's own CRUD (wc_get_product()->set_*()->save()),
 * so its lookup tables, transients and hooks stay right.
 */

if (!defined('ABSPATH')) {
  exit;
}

class Visibility_WooCommerce {

  /** Product fields agents may change => WC_Product setter. */
  const WRITABLE = [
    'name'             => 'set_name',
    'description'      => 'set_description',
    'shortDescription' => 'set_short_description',
    'slug'             => 'set_slug',
  ];

  /** Commercial data Visibility never changes (request keys, any spelling). */
  const COMMERCIAL = [
    'price', 'regular_price', 'regularprice', 'sale_price', 'saleprice', 'date_on_sale_from', 'date_on_sale_to',
    'sku', 'global_unique_id', 'stock', 'stock_quantity', 'stockquantity', 'stock_status', 'stockstatus',
    'manage_stock', 'managestock', 'backorders', 'low_stock_amount', 'sold_individually',
    'tax_status', 'taxstatus', 'tax_class', 'taxclass', 'shipping_class', 'shipping_class_id', 'shippingclass',
    'weight', 'length', 'width', 'height', 'dimensions', 'virtual', 'downloadable', 'downloads',
    'variations', 'attributes', 'default_attributes', 'type', 'status', 'catalog_visibility', 'featured',
    'purchase_note', 'menu_order', 'reviews_allowed', 'upsell_ids', 'cross_sell_ids', 'parent_id',
    'grouped_products', 'product_url', 'button_text',
  ];

  /** Product meta that holds commercial data (blocked for every write path). */
  const COMMERCIAL_META_PREFIXES = ['_price', '_regular_price', '_sale_price', '_sku', '_stock', '_manage_stock', '_backorders', '_tax_', '_weight', '_length', '_width', '_height', '_product_attributes', '_default_attributes', '_virtual', '_downloadable', '_sold_individually', '_low_stock_amount', 'total_sales', '_global_unique_id', '_wc_'];

  /** Taxonomies whose name, slug and description term.update may change. */
  const TERM_TAXONOMIES = ['category', 'post_tag', 'product_cat', 'product_tag'];

  public static function active() {
    return defined('WC_VERSION') && function_exists('wc_get_product');
  }

  public static function is_product($post) {
    $post = get_post($post);
    return $post && $post->post_type === 'product';
  }

  /** The first commercial key in $keys, or null. */
  public static function commercial_key(array $keys) {
    foreach ($keys as $k) {
      $n = strtolower(str_replace(['-', ' '], '_', (string) $k));
      $flat = str_replace('_', '', $n);
      foreach (self::COMMERCIAL as $c) {
        if ($n === $c || $flat === str_replace('_', '', $c)) return (string) $k;
      }
      foreach (self::COMMERCIAL_META_PREFIXES as $p) {
        if (strpos($n, $p) === 0) return (string) $k;
      }
    }
    return null;
  }

  public static function commercial_refusal($key) {
    return sprintf('"%s" is price, stock, tax, shipping or variation data. Visibility never changes those on a shop; the shop owner changes them in WooCommerce.', $key);
  }

  // ─── Read ──────────────────────────────────────────────────────────────

  /** The `product` part of GET /posts/{id} for a product. */
  public static function read($post_id) {
    if (!self::active()) return null;
    $p = wc_get_product((int) $post_id);
    if (!$p) return null;
    $img = function ($id) {
      $id = (int) $id;
      if (!$id || get_post_type($id) !== 'attachment') return null;
      return ['id' => $id, 'url' => wp_get_attachment_url($id), 'alt' => (string) get_post_meta($id, '_wp_attachment_image_alt', true), 'title' => get_the_title($id)];
    };
    $cats = [];
    foreach ((array) $p->get_category_ids() as $cid) {
      $t = get_term((int) $cid, 'product_cat');
      if ($t && !is_wp_error($t)) $cats[] = ['id' => (int) $t->term_id, 'name' => $t->name, 'slug' => $t->slug];
    }
    return [
      'type'             => $p->get_type(),
      'name'             => $p->get_name(),
      'slug'             => $p->get_slug(),
      'description'      => $p->get_description(),
      'shortDescription' => $p->get_short_description(),
      'image'            => $img($p->get_image_id()),
      'gallery'          => array_values(array_filter(array_map($img, (array) $p->get_gallery_image_ids()))),
      'categories'       => $cats,
      'writableFields'   => array_keys(self::WRITABLE),
      // Shown so agents can write about the product correctly; never written.
      'readOnly'         => [
        'sku'         => $p->get_sku(),
        'price'       => $p->get_price(),
        'regularPrice' => $p->get_regular_price(),
        'salePrice'   => $p->get_sale_price(),
        'stockStatus' => $p->get_stock_status(),
        'stockQuantity' => $p->get_stock_quantity(),
        'variations'  => $p->is_type('variable') ? count((array) $p->get_children()) : 0,
      ],
    ];
  }

  /** A product category (or other term) with its SEO, for GET /terms/{id}. */
  public static function read_term($term) {
    $seo = Visibility_SEO::adapter();
    return [
      'id'          => (int) $term->term_id,
      'taxonomy'    => $term->taxonomy,
      'name'        => $term->name,
      'slug'        => $term->slug,
      'description' => $term->description,
      'parent'      => (int) $term->parent,
      'count'       => (int) $term->count,
      'link'        => get_term_link($term),
      'seo'         => ($seo->writable() && $seo->term_fields()) ? ['plugin' => $seo->slug(), 'values' => $seo->read_term($term->term_id, $term->taxonomy), 'supportedFields' => $seo->term_fields()] : null,
      'writableFields' => ['name', 'slug', 'description'],
    ];
  }

  // ─── Write ─────────────────────────────────────────────────────────────

  /**
   * product.update {productId|postId, fields:{name?, description?, shortDescription?, slug?}, modifiedGmt?}.
   * @return array|WP_Error
   */
  public static function update_product($post_id, array $fields) {
    if (!self::active()) return new WP_Error('unsupported', 'WooCommerce is not active on this site.');
    $bad = self::commercial_key(array_keys($fields));
    if ($bad !== null) return new WP_Error('not_allowed', self::commercial_refusal($bad));
    $p = wc_get_product((int) $post_id);
    if (!$p || $p->is_type('variation')) return new WP_Error('not_found', 'product not found');
    if (empty($fields)) return new WP_Error('invalid', 'fields required');
    foreach (array_keys($fields) as $k) {
      if (!isset(self::WRITABLE[$k])) return new WP_Error('invalid', sprintf('"%s" cannot be changed on a product. Visibility changes: %s.', $k, implode(', ', array_keys(self::WRITABLE))));
    }
    $post = get_post((int) $post_id);
    $before = [];
    foreach (array_keys($fields) as $k) $before[$k] = self::get($p, $k);
    foreach ($fields as $k => $v) {
      if (!is_scalar($v) && $v !== null) return new WP_Error('invalid', sprintf('%s must be text', $k));
      $v = (string) $v;
      if ($k === 'name') $v = sanitize_text_field($v);
      elseif ($k === 'slug') $v = sanitize_title($v);
      elseif ($k === 'description') $v = Visibility_Content::sanitize_update($v, $post->post_content);
      elseif ($k === 'shortDescription') $v = Visibility_Content::sanitize_update($v, $post->post_excerpt);
      if ($k === 'name' && $v === '') return new WP_Error('invalid', 'name cannot be empty');
      call_user_func([$p, self::WRITABLE[$k]], $v);
    }
    // Saved by WooCommerce; kses off for the save so embeds already in the
    // description survive (only new markup was sanitised above).
    $had_kses = has_filter('content_save_pre', 'wp_filter_post_kses') !== false;
    if ($had_kses) kses_remove_filters();
    try {
      $p->save();
    } finally {
      if ($had_kses) kses_init_filters();
    }
    clean_post_cache((int) $post_id);
    $p = wc_get_product((int) $post_id);
    $after = [];
    foreach (array_keys($fields) as $k) $after[$k] = self::get($p, $k);
    return ['fields' => array_keys($fields), 'before' => $before, 'after' => $after];
  }

  private static function get($p, $k) {
    switch ($k) {
      case 'name': return $p->get_name();
      case 'description': return $p->get_description();
      case 'shortDescription': return $p->get_short_description();
      case 'slug': return $p->get_slug();
    }
    return null;
  }

  /**
   * term.update {termId, taxonomy, fields:{name?, slug?, description?}}.
   * @return array|WP_Error
   */
  public static function update_term($term_id, $taxonomy, array $fields) {
    $taxonomy = sanitize_key((string) $taxonomy);
    if (!in_array($taxonomy, self::TERM_TAXONOMIES, true) || !taxonomy_exists($taxonomy)) {
      return new WP_Error('invalid', sprintf('taxonomy must be one of: %s', implode(', ', array_filter(self::TERM_TAXONOMIES, 'taxonomy_exists'))));
    }
    $term = get_term((int) $term_id, $taxonomy);
    if (!$term || is_wp_error($term)) return new WP_Error('not_found', 'term not found');
    if (empty($fields)) return new WP_Error('invalid', 'fields required');
    $args = [];
    foreach ($fields as $k => $v) {
      if (!in_array($k, ['name', 'slug', 'description'], true)) return new WP_Error('invalid', sprintf('"%s" cannot be changed on a %s. Visibility changes: name, slug, description.', $k, $taxonomy));
      if (!is_scalar($v) && $v !== null) return new WP_Error('invalid', sprintf('%s must be text', $k));
      $v = (string) $v;
      if ($k === 'name') { $v = sanitize_text_field($v); if ($v === '') return new WP_Error('invalid', 'name cannot be empty'); }
      if ($k === 'slug') $v = sanitize_title($v);
      if ($k === 'description') $v = Visibility_Content::sanitize_update($v, $term->description);
      $args[$k] = $v;
    }
    $before = [];
    foreach (array_keys($args) as $k) $before[$k] = $term->$k;
    $had_kses = has_filter('pre_term_description', 'wp_filter_kses') !== false;
    if ($had_kses) remove_filter('pre_term_description', 'wp_filter_kses');
    try {
      $r = wp_update_term((int) $term_id, $taxonomy, $args);
    } finally {
      if ($had_kses) add_filter('pre_term_description', 'wp_filter_kses');
    }
    if (is_wp_error($r)) return $r;
    clean_term_cache((int) $term_id, $taxonomy);
    $term = get_term((int) $term_id, $taxonomy);
    $after = [];
    foreach (array_keys($args) as $k) $after[$k] = $term->$k;
    return ['termId' => (int) $term_id, 'taxonomy' => $taxonomy, 'fields' => array_keys($args), 'before' => (object) $before, 'after' => (object) $after, 'link' => get_term_link($term)];
  }

  /** /capabilities' woocommerce entry. */
  public static function describe() {
    if (!defined('WC_VERSION')) return null;
    return [
      'version'  => WC_VERSION,
      'writable' => self::active(),
      'productFields' => array_keys(self::WRITABLE),
      'termTaxonomies' => array_values(array_filter(['product_cat', 'product_tag'], 'taxonomy_exists')),
      'neverChanged' => ['price', 'stock', 'sku', 'tax', 'shipping', 'variations'],
    ];
  }
}
