<?php
/**
 * Every post meta key of a post (2.1.0): listed for agents, and changed by
 * `meta.update` only with the owner's permission.
 *
 * Read (GET /posts/{id}?include=meta, also in the default full read):
 *   [{key, value, type, registered, showInRest, protected, writable, exists,
 *     label?, truncated?, multiple?, via?}]
 *   - secrets, WordPress housekeeping and Visibility's own keys are never listed;
 *   - page builder data is listed with its value replaced by a size note;
 *   - SEO plugins' keys are listed read-only (seo_meta.update writes them);
 *   - registered keys the post has no value for are listed with exists:false.
 *
 * Write (meta.update {postId, meta:{key: value}, modifiedGmt?}):
 *   only keys the owner allows (fieldAllowlist "meta:<key>", or the owner's
 *   "any post meta" mode), never excluded or read-only keys, value checked
 *   against the registered type. A key the post doesn't have and that is not
 *   registered is only created when the allowlist names it exactly.
 */

if (!defined('ABSPATH')) {
  exit;
}

class Visibility_Meta {

  /** Max bytes of a listed value. */
  const MAX_VALUE = 4096;

  /** Words that mark a key as a secret: never listed, never written. */
  const SECRET_WORDS = ['password', 'passwd', 'token', 'secret', 'api_key', 'apikey', 'private_key', 'license', 'session', 'nonce', 'hash'];

  /** WordPress housekeeping keys: never listed. */
  const HOUSEKEEPING = ['_edit_lock', '_edit_last', '_wp_old_slug', '_encloseme', '_pingme'];

  /** Page builder data: listed with the value masked, never written here. */
  const BUILDER_PREFIXES = ['_et_pb_', '_wpb_', '_fl_builder_', '_bricks_', 'ct_builder_', '_breakdance_'];
  const BUILDER_KEYS = ['_elementor_data'];

  /** SEO plugins' keys: listed, written only through seo_meta.update. */
  const SEO_PREFIXES = ['_yoast_', 'rank_math_', '_aioseo_', '_seopress_', '_genesis_', 'slim_seo'];

  // ─── Classification ────────────────────────────────────────────────────

  /** Keys that are not listed at all. */
  public static function excluded($key) {
    $k = strtolower((string) $key);
    if ($k === '') return true;
    if (in_array($k, self::HOUSEKEEPING, true)) return true;
    if (strpos($k, '_wp_trash_') === 0 || strpos($k, '_visibility') === 0 || strpos($k, 'visibility_') === 0) return true;
    foreach (self::SECRET_WORDS as $w) {
      if (strpos($k, $w) !== false) return true;
    }
    return false;
  }

  public static function is_builder($key) {
    $k = strtolower((string) $key);
    if (in_array($k, self::BUILDER_KEYS, true)) return true;
    foreach (self::BUILDER_PREFIXES as $p) if (strpos($k, $p) === 0) return true;
    return false;
  }

  /** A known SEO plugin's key, or one of the detected custom SEO fields (seo_meta.update writes them). */
  public static function is_seo_plugin($key, $type = null) {
    $k = strtolower((string) $key);
    foreach (self::SEO_PREFIXES as $p) if (strpos($k, $p) === 0) return true;
    if ($type !== null && class_exists('Visibility_SEO_Adapter_Custom') && Visibility_SEO::adapter() instanceof Visibility_SEO_Adapter_Custom) {
      $fam = Visibility_SEO_Adapter_Custom::family($type);
      if ($fam && in_array((string) $key, $fam['fields'], true)) return true;
    }
    return false;
  }

  /** Registered meta args for this post type (or all post types), or null. */
  public static function registered($key, $type) {
    $all = array_merge(get_registered_meta_keys('post', ''), get_registered_meta_keys('post', $type));
    return isset($all[$key]) ? $all[$key] : null;
  }

  /**
   * Meta keys a field source (ACF, Meta Box, Pods, theme options…) owns:
   * meta key => field key. Those are changed with fields.update.
   */
  private static function field_owned($post) {
    $out = [];
    if (!class_exists('Visibility_Fields')) return $out;
    foreach (Visibility_Fields::schema($post->post_type, $post->ID) as $f) {
      if (empty($f['metaKey']) || $f['source'] === 'meta') continue;
      $out[$f['metaKey']] = $f['key'];
      // ACF keeps the field key reference next to the value.
      if ($f['source'] === 'acf') $out['_' . $f['metaKey']] = $f['key'];
    }
    return $out;
  }

  /** Why a key can never be written here (null = it can, if the owner allows). */
  private static function read_only_reason($key, $post, $owned) {
    if (self::is_builder($key)) return 'page builder data';
    if (self::is_seo_plugin($key, $post->post_type)) return 'seo_meta.update';
    if (isset($owned[$key])) return 'fields.update';
    if (class_exists('Visibility_Fields') && Visibility_Fields::denied($key)) return 'denied';
    if ($post->post_type === 'product' && class_exists('Visibility_WooCommerce') && Visibility_WooCommerce::commercial_key([$key]) !== null) return 'denied';
    return null;
  }

  // ─── Read ──────────────────────────────────────────────────────────────

  /** The inventory of a post's meta. */
  public static function for_post($post) {
    $post = get_post($post);
    if (!$post) return [];
    $rows = get_post_meta($post->ID); // key => [raw values]
    $rows = is_array($rows) ? $rows : [];
    $registered = array_merge(get_registered_meta_keys('post', ''), get_registered_meta_keys('post', $post->post_type));
    $owned = self::field_owned($post);
    $keys = array_unique(array_merge(array_keys($rows), array_keys($registered)));
    sort($keys, SORT_STRING);
    $out = [];
    foreach ($keys as $key) {
      $key = (string) $key;
      if (self::excluded($key)) continue;
      $args = isset($registered[$key]) ? $registered[$key] : null;
      $exists = isset($rows[$key]);
      $values = $exists ? array_map([__CLASS__, 'decode'], (array) $rows[$key]) : [];
      $multiple = count($values) > 1;
      if (!$exists) $value = ($args && array_key_exists('default', $args)) ? $args['default'] : null;
      else $value = $multiple ? $values : self::typed($values[0], $args);

      $item = [
        'key'        => $key,
        'value'      => null,
        'type'       => $args && !empty($args['type']) ? (string) $args['type'] : self::type_of($value),
        'registered' => (bool) $args,
        'showInRest' => $args ? !empty($args['show_in_rest']) : false,
        'protected'  => is_protected_meta($key, 'post'),
        'writable'   => false,
        'exists'     => $exists,
      ];
      if ($args && !empty($args['description'])) $item['label'] = (string) $args['description'];
      if ($multiple) $item['multiple'] = true;

      if (self::is_builder($key)) {
        $len = 0;
        foreach ((array) ($exists ? $rows[$key] : []) as $raw) $len += strlen((string) $raw);
        $item['value'] = sprintf('(page builder data, %d chars)', $len);
        $item['via'] = 'builder';
        $out[] = $item;
        continue;
      }
      list($item['value'], $truncated) = self::json_safe($value);
      if ($truncated) $item['truncated'] = true;

      $reason = self::read_only_reason($key, $post, $owned);
      if ($reason === 'seo_meta.update') $item['via'] = 'seo_meta.update';
      elseif ($reason === 'fields.update') { $item['via'] = 'fields.update'; $item['fieldKey'] = $owned[$key]; }
      $item['writable'] = $reason === null && !$multiple && (!$args || !empty($args['single']))
        && Visibility_Policy::meta_allowed($key, $exists || $args);
      $out[] = $item;
    }
    return $out;
  }

  /** A raw meta value as stored: unserialized without creating objects. */
  private static function decode($raw) {
    if (!is_string($raw) || !is_serialized($raw)) return $raw;
    // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- classes disabled: a meta value never builds an object.
    $v = @unserialize(trim($raw), ['allowed_classes' => false]);
    return ($v === false && $raw !== 'b:0;') ? $raw : $v;
  }

  /** A stored scalar as its registered type (meta is stored as text). */
  private static function typed($v, $args) {
    if (!$args || empty($args['type']) || !is_scalar($v)) return $v;
    switch ($args['type']) {
      case 'integer': return is_numeric($v) ? (int) $v : $v;
      case 'number':  return is_numeric($v) ? $v + 0 : $v;
      case 'boolean': return $v === '' ? false : (bool) $v;
    }
    return $v;
  }

  private static function type_of($v) {
    if ($v === null) return 'null';
    if (is_bool($v)) return 'boolean';
    if (is_int($v)) return 'integer';
    if (is_float($v)) return 'number';
    if (is_array($v)) return ($v === [] || array_keys($v) === range(0, count($v) - 1)) ? 'array' : 'object';
    if (is_object($v)) return 'object';
    return 'string';
  }

  /**
   * A JSON-safe value, cut to MAX_VALUE bytes. @return [value, truncated]
   * A cut string is its first 4 KB; a cut array/object is the first 4 KB of its JSON text.
   */
  private static function json_safe($v) {
    if (is_object($v) || is_array($v)) {
      $json = wp_json_encode($v, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
      if (!is_string($json)) return ['(value could not be read)', false];
      if (strlen($json) > self::MAX_VALUE) return [self::cut($json), true];
      return [json_decode($json, true), false];
    }
    if (is_string($v)) {
      $v = wp_check_invalid_utf8($v, true);
      if (strlen($v) > self::MAX_VALUE) return [self::cut($v), true];
    }
    return [$v, false];
  }

  private static function cut($s) {
    $s = function_exists('mb_strcut') ? mb_strcut($s, 0, self::MAX_VALUE, 'UTF-8') : substr($s, 0, self::MAX_VALUE);
    return wp_check_invalid_utf8($s, true);
  }

  // ─── Write ─────────────────────────────────────────────────────────────

  /**
   * Check one key/value for $post. @return array|WP_Error
   *   ['key', 'clean' => value to store (null = delete), 'type']
   * $create: whether a key the post doesn't have and that isn't registered
   * may be created when the allowlist names it exactly (meta.update: yes;
   * fields.update keeps its 2.0 rule: never).
   */
  public static function prepare($post, $key, $value, $create = true) {
    $key = (string) $key;
    if ($key === '' || self::excluded($key)) return new WP_Error('not_allowed', sprintf('Meta %s can never be changed by Visibility.', $key));
    $reason = self::read_only_reason($key, $post, self::field_owned($post));
    if ($reason === 'seo_meta.update') return new WP_Error('not_allowed', sprintf('Meta %s belongs to the SEO plugin; change it with seo_meta.update.', $key));
    if ($reason === 'fields.update') return new WP_Error('not_allowed', sprintf('Meta %s is a custom field; change it with fields.update.', $key));
    if ($reason !== null) return new WP_Error('not_allowed', sprintf('Meta %s can never be changed by Visibility.', $key));

    $args = self::registered($key, $post->post_type);
    $rows = get_post_meta($post->ID, $key, false);
    $exists = !empty($rows);
    $named = Visibility_Policy::field_allowed('meta:' . $key);
    if (!Visibility_Policy::meta_allowed($key, $exists || $args)) {
      if (!$exists && !$args && !$named && Visibility_Policy::meta_allowed($key, true)) {
        return new WP_Error('not_found', sprintf('Meta %s does not exist on this %s; only a key the owner names in the allowlist can be added.', $key, $post->post_type));
      }
      return new WP_Error('not_allowed', sprintf('Meta %s is not in the owner\'s allowlist.', $key));
    }
    if (!$exists && !$args && !$create) {
      return new WP_Error('not_found', sprintf('Field meta:%s does not exist on this %s.', $key, $post->post_type));
    }
    if (count($rows) > 1 || ($args && empty($args['single']))) {
      return new WP_Error('unsupported', sprintf('Meta %s holds several values; Visibility only changes single values.', $key));
    }
    if ($value === null) return ['key' => $key, 'clean' => null, 'type' => $args ? $args['type'] : 'string'];

    if ($args) {
      $type = !empty($args['type']) ? (string) $args['type'] : 'string';
      $clean = self::check_type($type, $value, $args);
    } else {
      // Not registered: keep the kind of value already stored.
      $current = $exists ? self::decode($rows[0]) : null;
      $type = is_array($current) ? 'array' : 'string';
      if (is_array($value) || is_object($value)) {
        $clean = $exists && !is_array($current) ? new WP_Error('invalid', 'expected a single value (text or number), not a list') : self::clean_deep(json_decode(wp_json_encode($value), true));
        $type = 'array';
      } elseif ($exists && is_array($current)) {
        $clean = new WP_Error('invalid', 'expected a list or object like the stored value');
      } elseif (is_bool($value)) {
        $clean = $value ? '1' : '0';
      } elseif (is_scalar($value)) {
        $clean = self::clean_text((string) $value);
      } else {
        $clean = new WP_Error('invalid', 'unsupported value');
      }
    }
    if (is_wp_error($clean)) return new WP_Error('invalid', $key . ': ' . $clean->get_error_message());
    return ['key' => $key, 'clean' => $clean, 'type' => $type];
  }

  /** Value against a registered meta type (and its REST schema when given). */
  private static function check_type($type, $v, $args) {
    switch ($type) {
      case 'string':
        if (!is_scalar($v) || is_bool($v)) return new WP_Error('invalid', 'expected text');
        return self::clean_text((string) $v);
      case 'integer':
        if (is_bool($v) || !is_numeric($v) || (float) $v != (int) $v) return new WP_Error('invalid', 'expected a whole number');
        return (int) $v;
      case 'number':
        if (is_bool($v) || !is_numeric($v)) return new WP_Error('invalid', 'expected a number');
        return $v + 0;
      case 'boolean':
        if (is_bool($v)) return $v;
        if (in_array($v, [0, 1, '0', '1', 'true', 'false'], true)) return in_array($v, [1, '1', 'true'], true);
        return new WP_Error('invalid', 'expected true or false');
      case 'array':
      case 'object':
        if (!is_array($v) && !is_object($v)) return new WP_Error('invalid', sprintf('expected %s', $type === 'array' ? 'a list' : 'an object'));
        $v = json_decode(wp_json_encode($v), true);
        $is_list = $v === [] || array_keys($v) === range(0, count($v) - 1);
        if ($type === 'array' && !$is_list) return new WP_Error('invalid', 'expected a list');
        $schema = isset($args['show_in_rest']['schema']) && is_array($args['show_in_rest']['schema']) ? $args['show_in_rest']['schema'] + ['type' => $type] : null;
        if ($schema && function_exists('rest_validate_value_from_schema')) {
          $ok = rest_validate_value_from_schema($v, $schema, 'value');
          if (is_wp_error($ok)) return new WP_Error('invalid', $ok->get_error_message());
        }
        return self::clean_deep($v);
    }
    return new WP_Error('invalid', sprintf('type %s cannot be written', $type));
  }

  /** Text: HTML kept as safe post HTML, plain text cleaned. */
  private static function clean_text($s) {
    if ($s !== wp_strip_all_tags($s)) return wp_kses_post($s);
    return sanitize_textarea_field($s);
  }

  private static function clean_deep($v) {
    if (is_array($v)) {
      $out = [];
      foreach ($v as $k => $x) $out[is_int($k) ? $k : sanitize_text_field((string) $k)] = self::clean_deep($x);
      return $out;
    }
    return is_string($v) ? self::clean_text($v) : $v;
  }

  /** Store a prepared value (null = delete). */
  public static function apply($post_id, array $p) {
    if ($p['clean'] === null) {
      delete_post_meta($post_id, $p['key']);
      return true;
    }
    $v = $p['clean'];
    // Booleans stored as WordPress stores them through register_meta / REST.
    if (is_bool($v)) $v = $v ? '1' : '';
    update_post_meta($post_id, $p['key'], wp_slash($v));
    return true;
  }

  /** The value as listed in the inventory (decoded, not cut). */
  public static function value($post_id, $key) {
    $rows = get_post_meta($post_id, $key, false);
    if (empty($rows)) return null;
    $v = $rows[0];
    if (is_array($v) || is_object($v)) return json_decode(wp_json_encode($v, JSON_PARTIAL_OUTPUT_ON_ERROR), true);
    $post = get_post($post_id);
    return self::typed($v, $post ? self::registered($key, $post->post_type) : null);
  }

  /**
   * meta.update. All-or-nothing: nothing is written when any key is refused.
   * @return array|WP_Error ['meta' => keys, 'before' => {key: value}, 'after' => {key: value}]
   */
  public static function update($post_id, array $values) {
    $post = get_post($post_id);
    if (!$post) return new WP_Error('not_found', 'post not found');
    if (empty($values)) return new WP_Error('invalid', 'meta required');
    $plan = [];
    foreach ($values as $key => $value) {
      $p = self::prepare($post, (string) $key, $value, true);
      if (is_wp_error($p)) return $p;
      $plan[(string) $key] = $p;
    }
    $before = []; $after = [];
    foreach ($plan as $key => $p) $before[$key] = self::value($post_id, $key);
    foreach ($plan as $key => $p) self::apply($post_id, $p);
    clean_post_cache($post_id);
    wp_cache_delete($post_id, 'post_meta');
    foreach ($plan as $key => $p) $after[$key] = self::value($post_id, $key);
    return ['meta' => array_keys($plan), 'before' => $before, 'after' => $after];
  }

  // ─── Block editor fidelity (read-only) ────────────────────────────────

  /**
   * parse_blocks() summary of post_content:
   *   {hasBlocks, total, classicBlocks, items:[{name, attrs, innerBlocks, htmlLength}], invalid:[...]}
   * classicBlocks = blocks without a name that hold more than whitespace
   * (content outside blocks: "Classic" in the editor), counted at every level.
   */
  public static function block_summary($content) {
    $content = (string) $content;
    $blocks = function_exists('parse_blocks') ? parse_blocks($content) : [];
    $items = [];
    $classic = 0;
    $total = 0;
    $unknown = [];
    $registry = class_exists('WP_Block_Type_Registry') ? WP_Block_Type_Registry::get_instance() : null;
    $walk = function ($list) use (&$walk, &$classic, &$total, &$unknown, $registry) {
      foreach ($list as $b) {
        if ($b['blockName'] === null) {
          if (trim((string) $b['innerHTML']) !== '') $classic++;
          continue;
        }
        $total++;
        if ($registry && !$registry->is_registered($b['blockName']) && $b['blockName'] !== 'core/freeform') $unknown[$b['blockName']] = true;
        if (!empty($b['innerBlocks'])) $walk($b['innerBlocks']);
      }
    };
    $walk($blocks);
    foreach ($blocks as $b) {
      if ($b['blockName'] === null && trim((string) $b['innerHTML']) === '') continue;
      $items[] = [
        'name'        => $b['blockName'],
        'attrs'       => self::small_attrs(is_array($b['attrs']) ? $b['attrs'] : []),
        'innerBlocks' => count((array) $b['innerBlocks']),
        'htmlLength'  => strlen((string) $b['innerHTML']),
      ];
    }
    $has = function_exists('has_blocks') ? has_blocks($content) : strpos($content, '<!-- wp:') !== false;
    $invalid = [];
    if (trim($content) !== '' && !$has) $invalid[] = 'The content is not in blocks: the block editor opens it as one Classic block.';
    elseif ($classic) $invalid[] = sprintf('%d part(s) of the content sit outside any block (shown as Classic blocks).', $classic);
    foreach (array_keys($unknown) as $name) $invalid[] = sprintf('Block type %s is not registered on this site (its plugin may be missing or off).', $name);
    $open = preg_match_all('/<!--\s+wp:[a-z0-9\/_-]+(\s+\{.*?\})?\s+-->/s', $content);
    $close = preg_match_all('/<!--\s+\/wp:[a-z0-9\/_-]+\s+-->/', $content);
    if ($open !== $close) $invalid[] = sprintf('Block comments do not match: %d opening, %d closing.', $open, $close);
    // 2.1.2 (#567): what the editor and the browser trip over, block by block.
    $problems = self::block_problems($blocks);
    foreach ($problems as $p) $invalid[] = $p['detail'];
    return ['hasBlocks' => (bool) $has, 'total' => $total, 'classicBlocks' => $classic, 'items' => $items, 'invalid' => $invalid, 'problems' => $problems];
  }

  /** Tags that belong in the page head; in the body they break the page (a <title> swallows the rest of it). */
  const HEAD_TAGS = ['title', 'head', 'html', 'body', 'base', 'meta', 'link'];
  const VOID_TAGS = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr'];
  /** End tag may be left out in HTML. */
  const OPTIONAL_END = ['p', 'li', 'dt', 'dd', 'tr', 'td', 'th', 'thead', 'tbody', 'tfoot', 'option', 'optgroup', 'colgroup', 'caption', 'rt', 'rp'];
  /** Blocks whose HTML is the owner's own and may be split across blocks. */
  const RAW_BLOCKS = ['core/html', 'core/freeform', 'core/shortcode'];

  /**
   * Problems in the blocks' own HTML (#567), as
   * [{block, path, kind: unbalanced|head_tag|faq_mismatch, detail}]:
   * - unbalanced: an element opened and not closed (or closed and never
   *   opened) inside one block, which the editor reports as invalid content;
   * - head_tag: <title>, <meta>, <link>… in the body (a stray <title> hides
   *   everything after it on the live page);
   * - faq_mismatch: a Rank Math / Yoast FAQ or How-to block whose attributes
   *   and HTML say different things.
   */
  public static function block_problems(array $blocks, $prefix = '') {
    $out = [];
    foreach (array_values(array_filter($blocks, function ($b) { return $b['blockName'] !== null; })) as $i => $b) {
      $path = $prefix === '' ? (string) $i : $prefix . '.' . $i;
      $name = $b['blockName'];
      $own = implode('', array_filter((array) $b['innerContent'], 'is_string'));
      $own = preg_replace('/<!--.*?-->/s', '', $own);
      $scan = preg_replace('#<(script|style|textarea|code)\b[^>]*>.*?</\1>#is', '', $own);
      $head = [];
      if (preg_match_all('#<(title|head|html|body|base|meta|link)\b#i', $scan, $m)) {
        foreach ($m[1] as $t) {
          $t = strtolower($t);
          if (in_array($t, ['meta', 'link'], true) && in_array($name, self::RAW_BLOCKS, true)) continue;
          $head[$t] = true;
        }
      }
      if ($head) {
        $out[] = ['block' => $name, 'path' => $path, 'kind' => 'head_tag', 'detail' => sprintf('Block %s (%s) has %s in its text: a page-head tag in the body breaks the page (a <title> hides everything after it). Write the tag as text, for example in backticks, so it is shown as characters.', $path, $name, implode(', ', array_map(function ($t) { return '<' . $t . '>'; }, array_keys($head))))];
      }
      if (!in_array($name, self::RAW_BLOCKS, true)) {
        $bad = self::unbalanced($scan);
        if ($bad !== null) $out[] = ['block' => $name, 'path' => $path, 'kind' => 'unbalanced', 'detail' => sprintf('Block %s (%s) has HTML that does not close properly (%s); the block editor marks it invalid.', $path, $name, $bad)];
      }
      $core = self::core_mismatch($b, $own);
      if ($core !== null) $out[] = ['block' => $name, 'path' => $path, 'kind' => 'block_attrs', 'detail' => sprintf('Block %s (%s): %s; the block editor marks it invalid.', $path, $name, $core)];
      if (class_exists('Visibility_FAQ')) {
        $mm = Visibility_FAQ::mismatch($b);
        if ($mm !== null) $out[] = ['block' => $name, 'path' => $path, 'kind' => 'faq_mismatch', 'detail' => sprintf('Block %s (%s): %s; the block editor marks it invalid. Build it with POST /blocks/faq instead of by hand.', $path, $name, $mm)];
      }
      if (!empty($b['innerBlocks'])) $out = array_merge($out, self::block_problems($b['innerBlocks'], $path));
    }
    return $out;
  }

  /**
   * Core blocks whose HTML says something their attributes don't (#573): the
   * editor rebuilds the HTML from the attributes, sees a difference and marks
   * the block invalid.
   */
  private static function core_mismatch(array $b, $own) {
    $a = is_array($b['attrs']) ? $b['attrs'] : [];
    switch ($b['blockName']) {
      case 'core/image':
        if (preg_match('/\bwp-image-(\d+)\b/', $own, $m) && (int) ($a['id'] ?? 0) !== (int) $m[1]) {
          return sprintf('the image has class wp-image-%d but the block\'s "id" is %s (add "id":%d to the block comment)', $m[1], isset($a['id']) ? (int) $a['id'] : 'missing', $m[1]);
        }
        return null;
      case 'core/list':
        if (preg_match('/^\s*<ol\b[^>]*\bstart="(-?\d+)"/i', $own, $m) && (int) ($a['start'] ?? 0) !== (int) $m[1]) return sprintf('the list starts at %d but the block has no "start":%d', $m[1], $m[1]);
        if (preg_match('/^\s*<ol\b[^>]*\breversed\b/i', $own) && empty($a['reversed'])) return 'the list is reversed but the block has no "reversed":true';
        if (preg_match('/^\s*<ol\b/i', $own) && empty($a['ordered'])) return 'the list is numbered (<ol>) but the block has no "ordered":true';
        return null;
      case 'core/quote':
        if (empty($b['innerBlocks']) && trim(wp_strip_all_tags(preg_replace('#<cite\b.*?</cite>#is', '', $own))) !== '') return 'the quote\'s text sits directly in <blockquote> instead of in paragraph blocks inside it';
        return null;
      case 'core/code':
        if (!preg_match('#<pre\b[^>]*>\s*<code\b#i', $own)) return 'a code block needs <pre><code>…</code></pre> (use core/preformatted for <pre> alone)';
        return null;
    }
    return null;
  }

  /** The first nesting error in an HTML fragment, or null. */
  private static function unbalanced($html) {
    if (!preg_match_all('#<(/?)([a-zA-Z][a-zA-Z0-9-]*)\b[^>]*?(/?)>#', $html, $m, PREG_SET_ORDER)) return null;
    $stack = [];
    foreach ($m as $t) {
      $tag = strtolower($t[2]);
      if (in_array($tag, self::VOID_TAGS, true) || $t[3] === '/') continue;
      if ($t[1] === '') { $stack[] = $tag; continue; }
      $at = array_search($tag, array_reverse($stack, true), true);
      if ($at === false) return sprintf('</%s> without <%s>', $tag, $tag);
      while (count($stack) - 1 > $at) {
        $open = array_pop($stack);
        if (!in_array($open, self::OPTIONAL_END, true)) return sprintf('<%s> is not closed before </%s>', $open, $tag);
      }
      array_pop($stack);
    }
    foreach ($stack as $open) if (!in_array($open, self::OPTIONAL_END, true)) return sprintf('<%s> is never closed', $open);
    return null;
  }

  /** Block attributes with long values replaced by a size note. */
  private static function small_attrs(array $attrs) {
    $out = [];
    foreach ($attrs as $k => $v) {
      $json = wp_json_encode($v);
      $out[$k] = (is_string($json) && strlen($json) > 200) ? sprintf('(%d chars)', strlen($json)) : $v;
      if (count($out) >= 20) break;
    }
    return empty($out) ? new stdClass() : $out;
  }
}
