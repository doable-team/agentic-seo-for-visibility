<?php
/**
 * Custom fields agents may read and, if the owner allows, change (#288).
 *
 * Every field gets a namespaced key that the app sends back as is:
 *   acf:<field key>          Advanced Custom Fields (free + Pro), always by field key
 *   metabox:<field id>       Meta Box
 *   pods:<field name>        Pods
 *   theme:<theme>:<meta key> theme page options (Astra, GeneratePress, Kadence, OceanWP)
 *   meta:<meta key>          other post meta registered with show_in_rest
 *
 * Writes go through the field plugin's own API, only for keys in the owner's
 * fieldAllowlist (Visibility_Policy), never for denied keys, and every value
 * is validated by the field's type.
 */

if (!defined('ABSPATH')) {
  exit;
}

class Visibility_Fields {

  /** Field types that can be written, per source. Others are listed read-only. */
  const ACF_WRITABLE = ['text', 'textarea', 'wysiwyg', 'url', 'email', 'image', 'number', 'range', 'select', 'radio', 'button_group', 'true_false', 'checkbox', 'repeater', 'group'];
  const MB_WRITABLE  = ['text', 'textarea', 'wysiwyg', 'url', 'email', 'number', 'range', 'select', 'radio', 'checkbox', 'switch', 'image_advanced', 'single_image', 'image'];
  const PODS_WRITABLE = ['text', 'paragraph', 'wysiwyg', 'website', 'email', 'number', 'currency', 'pick', 'boolean', 'phone', 'file'];

  /**
   * Theme page options stored as post meta: theme => [meta key => [label, type, choices|null]].
   * Astra and Kadence register theirs with show_in_rest; listed here so they
   * are labelled and grouped as theme options, never as raw meta.
   */
  public static function theme_options() {
    return [
      'astra' => [
        'site-sidebar-layout'         => ['Sidebar layout', 'select', ['default', 'no-sidebar', 'left-sidebar', 'right-sidebar']],
        'site-content-layout'         => ['Content layout (legacy)', 'select', ['default', 'boxed-container', 'content-boxed-container', 'plain-container', 'page-builder', 'narrow-container']],
        'ast-site-content-layout'     => ['Container layout', 'select', ['default', 'normal-width-container', 'narrow-width-container', 'full-width-container']],
        'site-content-style'          => ['Container style', 'select', ['default', 'unboxed', 'boxed']],
        'site-sidebar-style'          => ['Sidebar style', 'select', ['default', 'unboxed', 'boxed']],
        'site-post-title'             => ['Hide title', 'select', ['', 'disabled']],
        'ast-breadcrumbs-content'     => ['Hide breadcrumbs', 'select', ['', 'disabled']],
        'ast-featured-img'            => ['Hide featured image', 'select', ['', 'disabled']],
        'ast-main-header-display'     => ['Hide primary header', 'select', ['', 'disabled']],
        'ast-global-header-display'   => ['Hide header', 'select', ['', 'disabled']],
        'footer-sml-layout'           => ['Hide footer', 'select', ['', 'disabled']],
        'theme-transparent-header-meta' => ['Transparent header', 'select', ['default', 'enabled', 'disabled']],
        'stick-header-meta'           => ['Sticky header', 'select', ['default', 'enabled', 'disabled']],
      ],
    ];
  }

  /** The active theme (or its parent) when it has page options we know. */
  public static function active_theme() {
    $opts = self::theme_options();
    foreach ([get_stylesheet(), get_template()] as $t) {
      if (isset($opts[$t])) return $t;
    }
    return null;
  }

  /** Keys that are never written, whatever the allowlist says. */
  public static function denied($meta_key) {
    $k = strtolower((string) $meta_key);
    if ($k === '') return true;
    foreach (['_wp_', '_edit_', '_elementor', '_visibility', 'visibility_', '_oembed', '_thumbnail_id', '_yoast', 'rank_math', '_aioseo', '_seopress', '_genesis', 'slim_seo', '_et_pb', '_wpb_', '_fl_builder', '_bricks', 'ct_builder', '_breakdance', '_menu_item', '_product', '_price', '_regular_price', '_sale_price', '_stock', '_sku'] as $p) {
      if (strpos($k, $p) === 0) return true;
    }
    foreach (['password', 'passwd', 'token', 'secret', 'api_key', 'apikey', 'private_key', 'license'] as $w) {
      if (strpos($k, $w) !== false) return true;
    }
    return false;
  }

  // ─── Schema ────────────────────────────────────────────────────────────

  /**
   * Every field for a post type (or a single post, which narrows ACF/Meta
   * Box groups to the ones shown on that post).
   *
   * @return array[] [{key, label, type, group, source, choices?, subFields?, writable, metaKey?, ...}]
   */
  public static function schema($type, $post_id = 0) {
    $out = [];
    $seen_meta = [];
    foreach (['acf', 'metabox', 'pods'] as $src) {
      if (!method_exists(__CLASS__, 'schema_' . $src)) continue;
      foreach (call_user_func([__CLASS__, 'schema_' . $src], $type, $post_id) as $f) {
        if (!empty($f['metaKey'])) $seen_meta[$f['metaKey']] = true;
        $out[] = $f;
      }
    }
    $theme = self::active_theme();
    if ($theme && in_array($type, ['page', 'post'], true)) {
      foreach (self::theme_options()[$theme] as $meta => $def) {
        $seen_meta[$meta] = true;
        $f = ['key' => 'theme:' . $theme . ':' . $meta, 'label' => $def[0], 'type' => $def[1], 'group' => ucfirst($theme) . ' page options', 'source' => $theme, 'metaKey' => $meta];
        if ($def[2]) $f['choices'] = $def[2];
        $out[] = $f;
      }
    }
    // Native registered meta (show_in_rest), skipping what a source above owns.
    $registered = array_merge(get_registered_meta_keys('post', ''), get_registered_meta_keys('post', $type));
    foreach ($registered as $key => $args) {
      if (empty($args['show_in_rest']) || isset($seen_meta[$key]) || $key === '_acf_changed' || $key === 'footnotes') continue;
      if (is_protected_meta($key, 'post') && strpos($key, '_kad_') !== 0) continue;
      $out[] = ['key' => 'meta:' . $key, 'label' => !empty($args['description']) ? $args['description'] : $key, 'type' => isset($args['type']) ? $args['type'] : 'string', 'group' => 'Post meta', 'source' => 'meta', 'metaKey' => $key];
    }
    foreach ($out as &$f) {
      $meta = isset($f['metaKey']) ? $f['metaKey'] : '';
      $f['writable'] = empty($f['readOnly']) && !self::denied($meta === '' ? $f['key'] : $meta) && Visibility_Policy::field_allowed($f['key']);
      unset($f['readOnly']);
    }
    return $out;
  }

  private static function schema_acf($type, $post_id) {
    if (!function_exists('acf_get_field_groups') || !function_exists('acf_get_fields')) return [];
    $groups = $post_id ? acf_get_field_groups(['post_id' => (int) $post_id]) : acf_get_field_groups(['post_type' => $type]);
    $out = [];
    foreach ((array) $groups as $g) {
      foreach ((array) acf_get_fields($g) as $f) {
        $out[] = self::acf_field($f, $g['title']);
      }
    }
    return $out;
  }

  private static function acf_field($f, $group) {
    $type = (string) $f['type'];
    $o = ['key' => 'acf:' . $f['key'], 'label' => $f['label'] !== '' ? $f['label'] : $f['name'], 'type' => $type, 'group' => $group, 'source' => 'acf', 'metaKey' => $f['name'], 'name' => $f['name']];
    if (!empty($f['choices']) && is_array($f['choices'])) $o['choices'] = array_map('strval', array_keys($f['choices']));
    if (in_array($type, ['repeater', 'group'], true) && !empty($f['sub_fields'])) {
      $o['subFields'] = array_map(function ($s) {
        $x = ['key' => $s['key'], 'name' => $s['name'], 'label' => $s['label'], 'type' => $s['type']];
        if (!empty($s['choices']) && is_array($s['choices'])) $x['choices'] = array_map('strval', array_keys($s['choices']));
        return $x;
      }, $f['sub_fields']);
    }
    if (!in_array($type, self::ACF_WRITABLE, true)) $o['readOnly'] = true;
    return $o;
  }

  private static function schema_metabox($type, $post_id) {
    if (!function_exists('rwmb_get_registry')) return [];
    $out = [];
    $boxes = rwmb_get_registry('meta_box')->get_by(['object_type' => 'post']);
    foreach ((array) $boxes as $box) {
      $types = isset($box->meta_box['post_types']) ? (array) $box->meta_box['post_types'] : [];
      if ($types && !in_array($type, $types, true)) continue;
      foreach ((array) $box->meta_box['fields'] as $f) {
        if (empty($f['id'])) continue;
        $o = ['key' => 'metabox:' . $f['id'], 'label' => !empty($f['name']) ? $f['name'] : $f['id'], 'type' => $f['type'], 'group' => isset($box->meta_box['title']) ? $box->meta_box['title'] : 'Meta Box', 'source' => 'metabox', 'metaKey' => $f['id']];
        if (!empty($f['options']) && is_array($f['options'])) $o['choices'] = array_map('strval', array_keys($f['options']));
        if (!empty($f['clone']) || !in_array($f['type'], self::MB_WRITABLE, true)) $o['readOnly'] = true;
        $out[] = $o;
      }
    }
    return $out;
  }

  private static function schema_pods($type, $post_id) {
    if (!function_exists('pods_api')) return [];
    $pod = pods_api()->load_pod(['name' => $type], false);
    if (!$pod || empty($pod['fields'])) return [];
    $out = [];
    foreach ($pod['fields'] as $name => $f) {
      $f = is_object($f) && method_exists($f, 'get_args') ? $f->get_args() : (array) $f;
      $ftype = isset($f['type']) ? $f['type'] : 'text';
      $o = ['key' => 'pods:' . $name, 'label' => !empty($f['label']) ? $f['label'] : $name, 'type' => $ftype, 'group' => isset($pod['label']) ? $pod['label'] . ' (Pods)' : 'Pods', 'source' => 'pods', 'metaKey' => $name];
      if ($ftype === 'pick' && (($f['pick_object'] ?? '') === 'custom-simple')) {
        $raw = isset($f['pick_custom']) ? (string) $f['pick_custom'] : '';
        $choices = [];
        foreach (preg_split('/\r\n|\n/', $raw) as $line) { if (trim($line) === '') continue; $choices[] = trim(explode('|', $line)[0]); }
        $o['choices'] = $choices;
      } elseif ($ftype === 'pick') {
        $o['readOnly'] = true; // relationships: read-only
      }
      if (!in_array($ftype, self::PODS_WRITABLE, true)) $o['readOnly'] = true;
      $out[] = $o;
    }
    return $out;
  }

  // ─── Values ────────────────────────────────────────────────────────────

  /** Fields of a post with their current values (the read endpoint's `fields`). */
  public static function for_post($post) {
    $out = [];
    foreach (self::schema($post->post_type, $post->ID) as $f) {
      $f['value'] = self::get_value($post->ID, $f);
      $out[] = $f;
    }
    return $out;
  }

  public static function get_value($post_id, array $f) {
    switch ($f['source']) {
      case 'acf':
        $v = get_field(substr($f['key'], 4), $post_id, false);
        if ($f['type'] === 'image' && $v) return self::image_value((int) $v);
        return $v;
      case 'metabox':
        $v = rwmb_get_value($f['metaKey'], [], $post_id);
        if (in_array($f['type'], ['single_image', 'image', 'image_advanced'], true)) {
          if (is_array($v) && isset($v['ID'])) return self::image_value((int) $v['ID']);
          if (is_array($v)) return array_values(array_map(function ($i) { return self::image_value((int) (is_array($i) ? $i['ID'] : $i)); }, $v));
        }
        return $v;
      case 'pods':
        $v = pods(get_post_type($post_id), $post_id)->field($f['metaKey'], ['raw' => true]);
        if ($f['type'] === 'file') {
          if (is_array($v) && isset($v['ID'])) return self::image_value((int) $v['ID']);
          if (is_numeric($v)) return self::image_value((int) $v);
        }
        return $v;
      default:
        $v = get_post_meta($post_id, $f['metaKey'], true);
        return $v;
    }
  }

  private static function image_value($id) {
    if (!$id || get_post_type($id) !== 'attachment') return null;
    return ['id' => $id, 'url' => wp_get_attachment_url($id), 'alt' => (string) get_post_meta($id, '_wp_attachment_image_alt', true)];
  }

  // ─── Write ─────────────────────────────────────────────────────────────

  /**
   * Write $values ({key: value}) on $post_id. All-or-nothing on validation:
   * nothing is written when any key is unknown, denied, not allowlisted or
   * has a bad value.
   *
   * @return array|WP_Error ['fields' => [...], 'before' => {...}, 'after' => {...}]
   *         WP_Error codes: not_allowed, invalid, not_found
   */
  public static function update($post_id, array $values) {
    $post = get_post($post_id);
    if (!$post) return new WP_Error('not_found', 'post not found');
    if (empty($values)) return new WP_Error('invalid', 'fields required');
    $schema = [];
    foreach (self::schema($post->post_type, $post->ID) as $f) $schema[$f['key']] = $f;
    $plan = [];
    foreach ($values as $key => $value) {
      $key = (string) $key;
      $parts = explode(':', $key);
      if (self::denied(end($parts))) return new WP_Error('not_allowed', sprintf('Field %s can never be changed by Visibility.', $key));
      if (!isset($schema[$key])) {
        // Unknown on this post. Never write arbitrary keys.
        if (!Visibility_Policy::field_allowed($key)) return new WP_Error('not_allowed', sprintf('Field %s is not in the owner\'s allowlist.', $key));
        return new WP_Error('not_found', sprintf('Field %s does not exist on this %s.', $key, $post->post_type));
      }
      $f = $schema[$key];
      if (self::denied(isset($f['metaKey']) ? $f['metaKey'] : $key)) return new WP_Error('not_allowed', sprintf('Field %s can never be changed by Visibility.', $key));
      if (!Visibility_Policy::field_allowed($key)) return new WP_Error('not_allowed', sprintf('Field %s is not in the owner\'s allowlist.', $key));
      if (!$f['writable']) return new WP_Error('not_allowed', sprintf('Field %s (%s) cannot be written.', $key, $f['type']));
      $clean = self::validate($f, $value);
      if (is_wp_error($clean)) return new WP_Error('invalid', $key . ': ' . $clean->get_error_message());
      $plan[$key] = [$f, $clean];
    }
    $before = []; $after = [];
    foreach ($plan as $key => $p) $before[$key] = self::get_value($post_id, $p[0]);
    foreach ($plan as $key => $p) {
      $r = self::write($post_id, $p[0], $p[1]);
      if (is_wp_error($r)) return $r;
    }
    clean_post_cache($post_id);
    foreach ($plan as $key => $p) $after[$key] = self::get_value($post_id, $p[0]);
    return ['fields' => array_keys($plan), 'before' => $before, 'after' => $after];
  }

  /** @return mixed|WP_Error the value to store */
  public static function validate(array $f, $v) {
    $type = $f['type'];
    if ($v === null) return null; // clear
    $text = function ($v) { return is_scalar($v) ? sanitize_text_field((string) $v) : new WP_Error('invalid', 'expected text'); };
    switch ($type) {
      case 'text': case 'phone': case 'string':
        return $text($v);
      case 'textarea': case 'paragraph':
        return is_scalar($v) ? sanitize_textarea_field((string) $v) : new WP_Error('invalid', 'expected text');
      case 'wysiwyg':
        return is_scalar($v) ? wp_kses_post((string) $v) : new WP_Error('invalid', 'expected HTML text');
      case 'url': case 'website':
        $raw = is_scalar($v) ? trim((string) $v) : '';
        if ($raw === '') return '';
        if (!preg_match('#^(https?://|/)#i', $raw) || preg_match('/\s/', $raw) || ($raw[0] !== '/' && !filter_var($raw, FILTER_VALIDATE_URL))) {
          return new WP_Error('invalid', 'not a valid URL');
        }
        return esc_url_raw($raw);
      case 'email':
        $e = is_scalar($v) ? sanitize_email((string) $v) : '';
        return ($e === '' && (string) $v !== '') ? new WP_Error('invalid', 'not a valid email') : $e;
      case 'number': case 'range': case 'currency': case 'integer':
        if (!is_numeric($v)) return new WP_Error('invalid', 'expected a number');
        return $v + 0;
      case 'true_false': case 'switch': case 'boolean':
        if (!is_bool($v) && !in_array($v, [0, 1, '0', '1'], true)) return new WP_Error('invalid', 'expected true or false');
        return (bool) $v;
      case 'select': case 'radio': case 'button_group': case 'pick':
        if (!is_scalar($v) && !is_array($v)) return new WP_Error('invalid', 'expected one of the choices');
        $vals = is_array($v) ? array_map('strval', $v) : [(string) $v];
        if (isset($f['choices'])) {
          foreach ($vals as $x) if (!in_array($x, $f['choices'], true)) return new WP_Error('invalid', sprintf('"%s" is not one of: %s', $x, implode(', ', $f['choices'])));
        }
        return is_array($v) ? $vals : $vals[0];
      case 'checkbox':
        $vals = is_array($v) ? array_map('strval', $v) : [(string) $v];
        if (isset($f['choices'])) {
          foreach ($vals as $x) if (!in_array($x, $f['choices'], true)) return new WP_Error('invalid', sprintf('"%s" is not one of: %s', $x, implode(', ', $f['choices'])));
        } elseif ($f['source'] === 'metabox') {
          return (bool) $v;
        }
        return $vals;
      case 'image': case 'single_image': case 'file': case 'image_advanced':
        $ids = is_array($v) && !isset($v['id']) ? $v : [$v];
        $out = [];
        foreach ($ids as $one) {
          $id = is_array($one) && isset($one['id']) ? (int) $one['id'] : (is_numeric($one) ? (int) $one : 0);
          if ($id <= 0 || get_post_type($id) !== 'attachment' || strpos((string) get_post_mime_type($id), 'image/') !== 0) {
            return new WP_Error('invalid', 'expected the id of an image in the media library (upload it with POST /media first)');
          }
          $out[] = $id;
        }
        return $type === 'image_advanced' ? $out : $out[0];
      case 'repeater':
        if (!is_array($v) || ($v && array_keys($v) !== range(0, count($v) - 1))) return new WP_Error('invalid', 'expected a list of rows');
        $rows = [];
        foreach ($v as $i => $row) {
          $r = self::validate_row($f, $row);
          if (is_wp_error($r)) return new WP_Error('invalid', sprintf('row %d: %s', $i, $r->get_error_message()));
          $rows[] = $r;
        }
        return $rows;
      case 'group':
        return self::validate_row($f, $v);
    }
    return new WP_Error('invalid', sprintf('type %s cannot be written', $type));
  }

  /** A repeater row / group: {sub field name or key: value}, stored by sub-field key. */
  private static function validate_row(array $f, $row) {
    if (!is_array($row)) return new WP_Error('invalid', 'expected an object');
    $subs = [];
    foreach ((array) ($f['subFields'] ?? []) as $s) { $subs[$s['name']] = $s; $subs[$s['key']] = $s; }
    $out = [];
    foreach ($row as $k => $val) {
      if (!isset($subs[$k])) return new WP_Error('invalid', sprintf('unknown sub field %s', $k));
      $s = $subs[$k];
      $sf = ['type' => $s['type'], 'source' => 'acf'];
      if (isset($s['choices'])) $sf['choices'] = $s['choices'];
      $c = self::validate($sf, $val);
      if (is_wp_error($c)) return new WP_Error('invalid', $s['name'] . ': ' . $c->get_error_message());
      $out[$s['key']] = $c;
    }
    return $out;
  }

  private static function write($post_id, array $f, $value) {
    switch ($f['source']) {
      case 'acf':
        $ok = update_field(substr($f['key'], 4), $value, $post_id);
        return $ok === false && self::get_value($post_id, $f) !== $value ? new WP_Error('save_failed', sprintf('ACF did not save %s', $f['key'])) : true;
      case 'metabox':
        rwmb_set_meta($post_id, $f['metaKey'], $value);
        return true;
      case 'pods':
        $r = pods(get_post_type($post_id), $post_id)->save([$f['metaKey'] => $value]);
        return $r ? true : new WP_Error('save_failed', sprintf('Pods did not save %s', $f['key']));
      default:
        if ($value === null || $value === '') delete_post_meta($post_id, $f['metaKey']);
        else update_post_meta($post_id, $f['metaKey'], is_string($value) ? wp_slash($value) : $value);
        return true;
    }
  }

  /** Field plugins and theme options this site has, for /capabilities. */
  public static function sources() {
    $out = [];
    if (class_exists('ACF')) $out[] = ['slug' => 'acf', 'name' => defined('ACF_PRO') ? 'Advanced Custom Fields Pro' : 'Advanced Custom Fields', 'version' => defined('ACF_VERSION') ? ACF_VERSION : null, 'writable' => true];
    if (defined('RWMB_VER')) $out[] = ['slug' => 'metabox', 'name' => 'Meta Box', 'version' => RWMB_VER, 'writable' => true];
    if (defined('PODS_VERSION')) $out[] = ['slug' => 'pods', 'name' => 'Pods', 'version' => PODS_VERSION, 'writable' => true];
    if (class_exists('Jet_Engine')) $out[] = ['slug' => 'jetengine', 'name' => 'JetEngine', 'version' => defined('JET_ENGINE_VERSION') ? JET_ENGINE_VERSION : null, 'writable' => false];
    $theme = self::active_theme();
    if ($theme) $out[] = ['slug' => $theme, 'name' => wp_get_theme($theme)->get('Name') . ' page options', 'version' => wp_get_theme($theme)->get('Version'), 'writable' => true, 'theme' => true];
    return $out;
  }
}
