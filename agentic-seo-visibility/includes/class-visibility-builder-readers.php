<?php
/**
 * Read-only builders: Bricks (C-38), Oxygen (C-39), Breakdance (C-40).
 *
 * These are paid builders with their own JSON/array layouts. Visibility
 * detects them, lists the text of a page's elements so agents can read the
 * page, and never writes them: editing is refused plainly ("built with X,
 * which Visibility can only read"), and agents change the page's SEO, custom
 * fields and image alt text instead.
 *
 * Storage, as their public code and documentation describe it:
 *   Bricks      post meta _bricks_page_content_2 (BRICKS_DB_PAGE_CONTENT): a
 *               flat array of elements {id, name, parent, children, settings};
 *               text in settings.text, image in settings.image {id, url, alt},
 *               link in settings.link.url, heading tag in settings.tag.
 *               _bricks_editor_mode = "bricks" marks a Bricks page.
 *   Oxygen      classic Oxygen (CT_VERSION, 4.x): post meta ct_builder_json
 *               (also _ct_builder_json): {children: [{id, name, options:
 *               {ct_content, ...}, children}]}; older pages only have
 *               ct_builder_shortcodes (listed as one text element).
 *   Breakdance  post meta _breakdance_data: {tree_json_string: "<JSON>"};
 *               the tree is {root: {id, data: {type, properties}, children}},
 *               element types like EssentialElements\Heading, text at
 *               properties.content.content.text, heading level in .tags.
 *               Oxygen 6 is built on Breakdance and uses the same tree
 *               (meta prefix _oxygen_ in Oxygen mode).
 */

if (!defined('ABSPATH')) {
  exit;
}

class Visibility_Builder_Readers {

  const READ_ONLY = ['bricks', 'oxygen', 'breakdance'];

  /** Is $post a page of read-only builder $slug? */
  public static function is_page($post, $slug) {
    switch ($slug) {
      case 'bricks':
        $mode = get_post_meta($post->ID, '_bricks_editor_mode', true);
        if ($mode === 'wordpress') return false;
        return $mode === 'bricks' || (bool) get_post_meta($post->ID, self::bricks_key(), true);
      case 'oxygen':
        foreach (['ct_builder_json', '_ct_builder_json', 'ct_builder_shortcodes', '_ct_builder_shortcodes', '_oxygen_data'] as $k) {
          if ($k === '_oxygen_data' && !self::oxygen6()) continue;
          if (get_post_meta($post->ID, $k, true)) return true;
        }
        return false;
      case 'breakdance':
        return (bool) get_post_meta($post->ID, self::bd_prefix() . 'data', true);
    }
    return false;
  }

  /** Oxygen 6 runs on Breakdance's engine with its own meta prefix. */
  public static function oxygen6() {
    return defined('__BREAKDANCE_VERSION') && defined('BREAKDANCE_MODE') && BREAKDANCE_MODE === 'oxygen';
  }

  private static function bd_prefix() {
    return self::oxygen6() ? '_oxygen_' : '_breakdance_';
  }

  /** Version constant of a read-only builder, or null when it is not active. */
  public static function version($slug) {
    switch ($slug) {
      case 'bricks':     return defined('BRICKS_VERSION') ? (string) BRICKS_VERSION : null;
      case 'oxygen':     return defined('CT_VERSION') ? (string) CT_VERSION : (self::oxygen6() ? (string) __BREAKDANCE_VERSION : null);
      case 'breakdance': return defined('__BREAKDANCE_VERSION') && !self::oxygen6() ? (string) __BREAKDANCE_VERSION : null;
    }
    return null;
  }

  private static function bricks_key() {
    return defined('BRICKS_DB_PAGE_CONTENT') ? BRICKS_DB_PAGE_CONTENT : '_bricks_page_content_2';
  }

  /** Elements of a read-only builder page: [{elementId, elType, widgetType, text, fields, attrs, editable:false}]. */
  public static function elements($post, $slug) {
    switch ($slug) {
      case 'bricks':     return self::bricks($post);
      case 'oxygen':     return self::oxygen6() && get_post_meta($post->ID, '_oxygen_data', true) ? self::breakdance($post) : self::oxygen($post);
      case 'breakdance': return self::breakdance($post);
    }
    return [];
  }

  private static function el($id, $type, $text, $fields = [], $attrs = []) {
    $plain = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags((string) $text)));
    return [
      'elementId'  => (string) $id,
      'elType'     => 'element',
      'widgetType' => (string) $type,
      'text'       => $plain,
      'fields'     => empty($fields) ? new stdClass() : $fields,
      'attrs'      => empty($attrs) ? new stdClass() : $attrs,
      'editable'   => false,
    ];
  }

  private static function image_attrs($id, $url, $alt) {
    $id = (int) $id;
    $a = [];
    if ($id) {
      $a['imageId'] = $id;
      $a['url'] = (string) wp_get_attachment_url($id);
      $a['alt'] = (string) get_post_meta($id, '_wp_attachment_image_alt', true);
    } elseif ($url) {
      $a['url'] = (string) $url;
      if ($alt !== null) $a['alt'] = (string) $alt;
    }
    return $a;
  }

  // ─── Bricks ────────────────────────────────────────────────────────────

  private static function bricks($post) {
    $els = get_post_meta($post->ID, self::bricks_key(), true);
    if (is_string($els)) $els = json_decode($els, true);
    if (!is_array($els)) return [];
    $by_id = [];
    foreach ($els as $e) if (is_array($e) && isset($e['id'])) $by_id[(string) $e['id']] = $e;
    $out = [];
    $walk = function ($e) use (&$walk, &$out, $by_id) {
      $s = isset($e['settings']) && is_array($e['settings']) ? $e['settings'] : [];
      $fields = [];
      foreach (['text', 'title', 'content', 'subtitle'] as $k) if (isset($s[$k]) && is_string($s[$k]) && $s[$k] !== '') $fields[$k] = $s[$k];
      $attrs = [];
      if (isset($s['image']) && is_array($s['image'])) $attrs = self::image_attrs($s['image']['id'] ?? 0, $s['image']['url'] ?? '', $s['altText'] ?? ($s['image']['alt'] ?? null));
      if (isset($s['link']['url']) && is_string($s['link']['url'])) $attrs['href'] = $s['link']['url'];
      if (isset($s['tag']) && preg_match('/^h([1-6])$/', (string) $s['tag'], $m)) $attrs['level'] = (int) $m[1];
      if ($fields || $attrs) $out[] = self::el($e['id'], $e['name'] ?? '', $fields ? reset($fields) : '', $fields, $attrs);
      foreach ((array) ($e['children'] ?? []) as $cid) if (isset($by_id[(string) $cid])) $walk($by_id[(string) $cid]);
    };
    foreach ($els as $e) {
      if (is_array($e) && (empty($e['parent']) || $e['parent'] === 0 || $e['parent'] === '0')) $walk($e);
    }
    return $out;
  }

  // ─── Oxygen (classic) ──────────────────────────────────────────────────

  private static function oxygen($post) {
    $json = get_post_meta($post->ID, 'ct_builder_json', true);
    if (!$json) $json = get_post_meta($post->ID, '_ct_builder_json', true);
    $tree = is_string($json) ? json_decode($json, true) : (is_array($json) ? $json : null);
    if (is_array($tree)) {
      $out = [];
      $walk = function ($node) use (&$walk, &$out) {
        foreach ((array) ($node['children'] ?? []) as $c) {
          if (!is_array($c)) continue;
          $o = isset($c['options']) && is_array($c['options']) ? $c['options'] : [];
          $fields = [];
          if (isset($o['ct_content']) && is_string($o['ct_content']) && trim($o['ct_content']) !== '') $fields['ct_content'] = $o['ct_content'];
          $attrs = [];
          $orig = isset($o['original']) && is_array($o['original']) ? $o['original'] : [];
          if (!empty($orig['image_ids']) || !empty($orig['src']) || !empty($orig['attachment_id'])) {
            $attrs = self::image_attrs($orig['attachment_id'] ?? 0, $orig['src'] ?? '', $orig['alt'] ?? null);
          }
          if (!empty($orig['url']) && is_string($orig['url'])) $attrs['href'] = $orig['url'];
          if (!empty($orig['tag']) && preg_match('/^h([1-6])$/', (string) $orig['tag'], $m)) $attrs['level'] = (int) $m[1];
          if ($fields || $attrs) $out[] = self::el($c['id'] ?? '', $c['name'] ?? '', $fields['ct_content'] ?? '', $fields, $attrs);
          $walk($c);
        }
      };
      $walk($tree);
      return $out;
    }
    $sc = get_post_meta($post->ID, 'ct_builder_shortcodes', true);
    if (!$sc) $sc = get_post_meta($post->ID, '_ct_builder_shortcodes', true);
    if (is_string($sc) && $sc !== '') {
      $text = preg_replace(['/\[\/?(ct_|oxy_|oxygen)[^\]]*\]/'], ' ', $sc);
      return [self::el('shortcodes', 'oxygen-shortcodes', $text)];
    }
    return [];
  }

  // ─── Breakdance (and Oxygen 6) ─────────────────────────────────────────

  private static function breakdance($post) {
    $data = get_post_meta($post->ID, self::bd_prefix() . 'data', true);
    if (is_string($data)) $data = json_decode($data, true);
    $tree = is_array($data) && isset($data['tree_json_string']) && is_string($data['tree_json_string']) ? json_decode($data['tree_json_string'], true) : null;
    if (!is_array($tree) || !isset($tree['root']) || !is_array($tree['root'])) return [];
    $out = [];
    $walk = function ($node) use (&$walk, &$out) {
      foreach ((array) ($node['children'] ?? []) as $c) {
        if (!is_array($c)) continue;
        $type = (string) ($c['data']['type'] ?? '');
        $p = isset($c['data']['properties']['content']) && is_array($c['data']['properties']['content']) ? $c['data']['properties']['content'] : [];
        $fields = [];
        foreach ($p as $section => $vals) {
          if (!is_array($vals)) continue;
          foreach (['text', 'title', 'subtitle', 'content'] as $k) {
            if (isset($vals[$k]) && is_string($vals[$k]) && trim($vals[$k]) !== '') $fields[$section === 'content' ? $k : $section . '.' . $k] = $vals[$k];
          }
        }
        $attrs = [];
        $cc = isset($p['content']) && is_array($p['content']) ? $p['content'] : [];
        if (isset($cc['image']) && is_array($cc['image'])) $attrs = self::image_attrs($cc['image']['id'] ?? 0, $cc['image']['url'] ?? '', $cc['image']['alt'] ?? null);
        if (isset($cc['link']['url']) && is_string($cc['link']['url'])) $attrs['href'] = $cc['link']['url'];
        if (isset($cc['tags']) && preg_match('/^h([1-6])$/', (string) $cc['tags'], $m)) $attrs['level'] = (int) $m[1];
        $short = strpos($type, '\\') !== false ? substr($type, strrpos($type, '\\') + 1) : $type;
        if ($fields || $attrs) $out[] = self::el($c['id'] ?? '', $short, $fields ? reset($fields) : '', $fields, $attrs);
        $walk($c);
      }
    };
    $walk($tree['root']);
    return $out;
  }
}
