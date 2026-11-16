<?php
/**
 * Shortcode page builders: Divi (#335) and WPBakery (#336).
 *
 * Both keep the whole layout in post_content as nested shortcodes
 * ([et_pb_section][et_pb_row][et_pb_column][et_pb_text]…[/et_pb_text]…, or
 * [vc_row][vc_column][vc_column_text]…). The builder renders those shortcodes
 * on the front end and opens the same text in its editor, so a change that
 * keeps the shortcode structure intact shows on the page and still opens in
 * the builder.
 *
 * What changes: the inner text of a module, or a text / title / alt / link
 * attribute of it, edited in place in the source: every other byte of the
 * page (the other modules, every other attribute, spacing, design settings)
 * stays exactly as it was. After the edit the page is parsed again and must
 * have the same modules in the same order with the other modules
 * byte-identical; otherwise nothing is saved.
 *
 * Element ids: "<shortcode>-<n>", the n-th module of that kind on the page
 * (e.g. "et_pb_text-2"). They come from the read, and the read's modifiedGmt
 * guards them.
 *
 * Divi 5 stores layouts as blocks (<!-- wp:divi/... -->) instead of
 * shortcodes: those pages are reported as not writable.
 */

if (!defined('ABSPATH')) {
  exit;
}

abstract class Visibility_Shortcode_Builder implements Visibility_Builder_Adapter {

  /** Builder name for messages. */
  abstract protected function name();
  /** Regex alternation of the builder's shortcode names (no delimiters). */
  abstract protected function tag_pattern();
  /**
   * tag => ['text' => [field => html?], 'image' => [alt attr|'@attachment:<attr>', title attr?] | null, 'link' => attr | null].
   * Field "content" is the text between the opening and closing shortcode.
   */
  abstract protected function modules();
  /** Encode a plain attribute value the way the builder stores it, or WP_Error. */
  abstract protected function encode_attr($value, $is_link = false);
  /** Decode a stored attribute value to plain text. */
  abstract protected function decode_attr($value);

  /** Page-specific: false when this page's layout isn't in shortcodes. */
  public function supports($post) {
    return true;
  }

  // ─── Parse ─────────────────────────────────────────────────────────────

  /**
   * Every builder shortcode in $content in document order:
   * [tag, open_start, open_end, close_start|null, close_end|null, raw_open, attrs].
   * Opening tags without a closing tag are self-closing.
   */
  protected function parse($content) {
    $re = '/\[(\/)?(' . $this->tag_pattern() . ')((?:[^\]"\']|"[^"]*"|\'[^\']*\')*?)(\/)?\]/s';
    if (!preg_match_all($re, $content, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) return [];
    $nodes = [];
    $stack = [];
    foreach ($m as $tok) {
      $start = $tok[0][1];
      $end = $start + strlen($tok[0][0]);
      $tag = $tok[2][0];
      if ($tok[1][0] === '/') {
        for ($i = count($stack) - 1; $i >= 0; $i--) {
          if ($nodes[$stack[$i]][0] === $tag) {
            $nodes[$stack[$i]][3] = $start;
            $nodes[$stack[$i]][4] = $end;
            array_splice($stack, $i);
            break;
          }
        }
        continue;
      }
      $self = isset($tok[4]) && $tok[4][0] === '/';
      $attrs = shortcode_parse_atts(trim($tok[3][0]));
      $nodes[] = [$tag, $start, $end, null, null, $tok[0][0], is_array($attrs) ? $attrs : []];
      if (!$self) $stack[] = count($nodes) - 1;
    }
    return $nodes;
  }

  /** elementId => node index. */
  protected function ids(array $nodes) {
    $seen = []; $out = [];
    foreach ($nodes as $i => $n) {
      $k = isset($seen[$n[0]]) ? $seen[$n[0]] : 0;
      $seen[$n[0]] = $k + 1;
      $out[$n[0] . '-' . $k] = $i;
    }
    return $out;
  }

  protected function inner($content, $n) {
    return $n[3] === null ? '' : substr($content, $n[2], $n[3] - $n[2]);
  }

  /** Attributes of a raw opening tag. */
  protected function raw_attrs($raw, $tag) {
    $a = shortcode_parse_atts(trim(rtrim(substr($raw, strlen($tag) + 1, -1), '/')));
    return is_array($a) ? $a : [];
  }

  protected function attr($n, $name) {
    return isset($n[6][$name]) ? $this->decode_attr((string) $n[6][$name]) : null;
  }

  // ─── Read ──────────────────────────────────────────────────────────────

  public function elements($post) {
    if (!$this->supports($post)) return [];
    $content = (string) $post->post_content;
    $nodes = $this->parse($content);
    $mods = $this->modules();
    $out = [];
    foreach ($this->ids($nodes) as $id => $i) {
      $n = $nodes[$i];
      if (!isset($mods[$n[0]])) continue;
      $def = $mods[$n[0]];
      $fields = [];
      foreach ($def['text'] as $f => $html) {
        $v = $f === 'content' ? $this->inner($content, $n) : $this->attr($n, $f);
        if ($v !== null) $fields[$f] = $v;
      }
      $attrs = [];
      if (!empty($def['image'])) {
        $alt = $this->image_alt($n, $def['image'][0]);
        $attrs['alt'] = $alt['alt'];
        if ($alt['imageId']) $attrs['imageId'] = $alt['imageId'];
        if (!empty($def['image'][1]) && $this->attr($n, $def['image'][1]) !== null) $attrs['title'] = $this->attr($n, $def['image'][1]);
      }
      if (!empty($def['link'])) $attrs['href'] = $this->link_url($this->attr($n, $def['link']));
      $main = $fields ? reset($fields) : '';
      $out[] = [
        'elementId'  => $id,
        'elType'     => 'module',
        'widgetType' => $n[0],
        'text'       => trim(preg_replace('/\s+/', ' ', wp_strip_all_tags(strip_shortcodes((string) $main)))),
        'fields'     => empty($fields) ? new stdClass() : $fields,
        'attrs'      => empty($attrs) ? new stdClass() : $attrs,
        'editable'   => !empty($def['text']) || !empty($def['image']) || !empty($def['link']),
      ];
    }
    return $out;
  }

  /** ['alt' => text, 'imageId' => id|null] for an image setting. */
  protected function image_alt($n, $spec) {
    if (strpos($spec, '@attachment:') === 0) {
      $id = (int) $this->attr($n, substr($spec, 12));
      return ['alt' => $id ? (string) get_post_meta($id, '_wp_attachment_image_alt', true) : '', 'imageId' => $id ?: null];
    }
    return ['alt' => (string) $this->attr($n, $spec), 'imageId' => null];
  }

  /** The URL inside a link setting (plain, or a builder-specific format). */
  protected function link_url($v) {
    return (string) $v;
  }

  /** The stored link setting for a new URL, keeping the rest of $old. */
  protected function link_value($old, $url) {
    return $url;
  }

  // ─── Write ─────────────────────────────────────────────────────────────

  /** Replace (or add) attribute $name in the raw opening tag, leaving every other byte. */
  protected function set_raw_attr($raw, $name, $stored) {
    $q = preg_quote($name, '/');
    if (preg_match('/(\s' . $q . '=)("[^"]*"|\'[^\']*\')/', $raw)) {
      return preg_replace_callback('/(\s' . $q . '=)("[^"]*"|\'[^\']*\')/', function ($m) use ($stored) { return $m[1] . '"' . $stored . '"'; }, $raw, 1);
    }
    return preg_replace('/\s*(\/?)\]$/', ' ' . $name . '="' . $stored . '"$1]', $raw, 1);
  }

  public function edit($post, array $edits) {
    if (!$this->supports($post)) {
      return new WP_Error('unsupported', sprintf('This %s page does not keep its layout in shortcodes, so Visibility cannot edit it. Change its SEO, custom fields or image alt text instead.', $this->name()));
    }
    $content = (string) $post->post_content;
    $nodes = $this->parse($content);
    if (!$nodes) return new WP_Error('unsupported', sprintf('No %s layout was found in this page, so nothing was changed.', $this->name()));
    $ids = $this->ids($nodes);
    $mods = $this->modules();
    // Planned replacements: [start, end, new text], by node.
    $repl = []; $diff = []; $notes = []; $alts = [];
    foreach ($edits as $i => $e) {
      $id = (string) $e['target']['elementId'];
      $op = isset($e['op']) ? (string) $e['op'] : '';
      if (!isset($ids[$id])) return new WP_Error('not_found', sprintf('edit %d: no element %s on this page', $i, $id));
      $ni = $ids[$id];
      $n = $nodes[$ni];
      if (!isset($mods[$n[0]])) return new WP_Error('unsupported', sprintf('edit %d: %s is a %s layout part, not a module with text; target a module inside it', $i, $id, $n[0]));
      $def = $mods[$n[0]];
      $raw_open = isset($repl[$ni]['open']) ? $repl[$ni]['open'] : $n[5];
      $inner = isset($repl[$ni]['inner']) ? $repl[$ni]['inner'] : $this->inner($content, $n);
      $before = ['open' => $raw_open, 'inner' => $inner];
      if ($op === 'replace_text') {
        if (!isset($e['text']) || !is_scalar($e['text'])) return new WP_Error('invalid', sprintf('edit %d: replace_text needs text', $i));
        $keys = array_keys($def['text']);
        $field = isset($e['field']) && is_string($e['field']) ? $e['field'] : ($keys ? $keys[0] : null);
        if ($field === null || !isset($def['text'][$field])) {
          return new WP_Error('unsupported', sprintf('edit %d: %s module "%s" has no text Visibility can change%s', $i, $n[0], $id, $keys ? ' (fields: ' . implode(', ', $keys) . ')' : ''));
        }
        $text = (string) $e['text'];
        if (strpos($text, '[') !== false || strpos($text, ']') !== false) {
          return new WP_Error('invalid', sprintf('edit %d: square brackets [ ] would start a new %s shortcode; leave them out', $i, $this->name()));
        }
        $text = $def['text'][$field] ? wp_kses_post($text) : sanitize_text_field($text);
        if ($field === 'content') {
          if ($n[3] === null) return new WP_Error('unsupported', sprintf('edit %d: %s has no inner text', $i, $id));
          $cur = $inner;
          if (isset($e['find']) && is_scalar($e['find']) && (string) $e['find'] !== '') {
            if (strpos($cur, (string) $e['find']) === false) return new WP_Error('not_found', sprintf('edit %d: "%s" is not in %s', $i, $e['find'], $id));
            $text = str_replace((string) $e['find'], $text, $cur);
          }
          $inner = $text;
        } else {
          $cur = (string) $this->attr(['', 0, 0, null, null, $raw_open, $this->raw_attrs($raw_open, $n[0])], $field);
          if (isset($e['find']) && is_scalar($e['find']) && (string) $e['find'] !== '') {
            if (strpos($cur, (string) $e['find']) === false) return new WP_Error('not_found', sprintf('edit %d: "%s" is not in %s.%s', $i, $e['find'], $id, $field));
            $text = str_replace((string) $e['find'], $text, $cur);
          }
          $enc = $this->encode_attr($text);
          if (is_wp_error($enc)) return new WP_Error('invalid', sprintf('edit %d: %s', $i, $enc->get_error_message()));
          $raw_open = $this->set_raw_attr($raw_open, $field, $enc);
        }
      } elseif ($op === 'set_attr') {
        $set = isset($e['attrs']) && is_array($e['attrs']) ? $e['attrs'] : [];
        if (empty($set)) return new WP_Error('invalid', sprintf('edit %d: set_attr needs attrs', $i));
        foreach ($set as $k => $v) {
          if ($k === 'alt' || ($k === 'title' && !empty($def['image'][1]))) {
            if (empty($def['image'])) return new WP_Error('not_found', sprintf('edit %d: module %s has no image', $i, $id));
            $val = sanitize_text_field((string) $v);
            $spec = $k === 'alt' ? $def['image'][0] : $def['image'][1];
            if (strpos($spec, '@attachment:') === 0) {
              // WPBakery shows the media library's alt text for the image id.
              $att = (int) $this->attr($n, substr($spec, 12));
              if (!$att || get_post_type($att) !== 'attachment') return new WP_Error('not_found', sprintf('edit %d: module %s has no image from the media library', $i, $id));
              $alts[$att] = $val;
              $notes[] = sprintf('The alt text is stored on image %d in the media library, so it shows wherever that image is used.', $att);
            } else {
              $enc = $this->encode_attr($val);
              if (is_wp_error($enc)) return new WP_Error('invalid', sprintf('edit %d: %s', $i, $enc->get_error_message()));
              $raw_open = $this->set_raw_attr($raw_open, $spec, $enc);
            }
          } elseif ($k === 'href' || $k === 'url') {
            if (empty($def['link'])) return new WP_Error('unsupported', sprintf('edit %d: module %s has no link', $i, $id));
            $url = esc_url_raw((string) $v);
            if ($url === '') return new WP_Error('invalid', sprintf('edit %d: not a valid URL', $i));
            $cur_attrs = $this->raw_attrs($raw_open, $n[0]);
            $stored = $this->link_value((string) ($cur_attrs[$def['link']] ?? ''), $url);
            $enc = $this->encode_attr($stored, true);
            if (is_wp_error($enc)) return new WP_Error('invalid', sprintf('edit %d: %s', $i, $enc->get_error_message()));
            $raw_open = $this->set_raw_attr($raw_open, $def['link'], $enc);
          } else {
            return new WP_Error('unsupported', sprintf('edit %d: attribute "%s" cannot be set on a %s module (use alt, href%s)', $i, $k, $n[0], !empty($def['image'][1]) ? ', title' : ''));
          }
        }
      } else {
        return new WP_Error('unsupported', sprintf('edit %d: "%s" is not supported on %s pages (use replace_text or set_attr)', $i, $op, $this->name()));
      }
      $repl[$ni] = ['open' => $raw_open, 'inner' => $inner];
      $diff[] = ['target' => ['elementId' => $id], 'blockName' => strtolower(str_replace(' ', '', $this->name())) . '/' . $n[0], 'ops' => [$op], 'before' => $before['open'] . ($n[3] !== null ? $before['inner'] : ''), 'after' => $raw_open . ($n[3] !== null ? $inner : '')];
    }
    // Rebuild back to front, so earlier offsets stay valid.
    $new = $content;
    krsort($repl);
    foreach ($repl as $ni => $r) {
      $n = $nodes[$ni];
      if ($n[3] !== null) $new = substr($new, 0, $n[2]) . $r['inner'] . substr($new, $n[3]);
      $new = substr($new, 0, $n[1]) . $r['open'] . substr($new, $n[2]);
    }
    $check = $this->verify($content, $new, array_keys($repl));
    if (is_wp_error($check)) return $check;
    foreach ($alts as $att => $alt) update_post_meta($att, '_wp_attachment_image_alt', $alt);
    if ($new !== $content) {
      $saved = Visibility_Content::save(['ID' => $post->ID, 'post_content' => $new]);
      if (is_wp_error($saved)) return new WP_Error('save_failed', $saved->get_error_message());
    }
    $this->after_save($post->ID);
    return ['diff' => $diff, 'notes' => array_values(array_unique($notes))];
  }

  /**
   * The edited page must have the same modules in the same order, and every
   * module that wasn't edited must be byte-identical (its tag and its text).
   */
  protected function verify($old, $new, array $edited) {
    $a = $this->parse($old);
    $b = $this->parse($new);
    $fail = new WP_Error('unsupported', sprintf('The change would have altered the %s layout around the edited part, so nothing was changed.', $this->name()));
    if (count($a) !== count($b)) return $fail;
    foreach ($a as $i => $n) {
      if ($n[0] !== $b[$i][0] || ($n[3] === null) !== ($b[$i][3] === null)) return $fail;
      if (in_array($i, $edited, true)) continue;
      $leaf = true;
      foreach ($edited as $e) if ($n[4] !== null && $a[$e][1] > $n[1] && $a[$e][1] < $n[4]) $leaf = false;
      if ($n[5] !== $b[$i][5]) return $fail;
      if ($leaf && $this->inner($old, $n) !== $this->inner($new, $b[$i])) return $fail;
    }
    return true;
  }

  /** After a save: drop the builder's generated CSS/caches for the page. */
  protected function after_save($post_id) {
  }
}

/**
 * Divi (theme or Divi Builder plugin), 4.x shortcode layouts (#335).
 * Built from Elegant Themes' developer docs and the module shortcodes Divi
 * saves (et_pb_* with attribute values whose quotes and brackets are stored
 * as %22, %91 and %93).
 */
class Visibility_Builder_Divi extends Visibility_Shortcode_Builder {

  protected function name() { return 'Divi'; }
  protected function tag_pattern() { return 'et_pb_[a-z0-9_]+'; }

  public function available() {
    return defined('ET_BUILDER_VERSION');
  }

  /** Divi 5 keeps layouts as blocks: read-only for Visibility. */
  public function supports($post) {
    $c = (string) $post->post_content;
    return strpos($c, '<!-- wp:divi/') === false && strpos($c, '[et_pb_') !== false;
  }

  protected function modules() {
    return [
      'et_pb_text'            => ['text' => ['content' => true], 'image' => null, 'link' => null],
      'et_pb_heading'         => ['text' => ['title' => false], 'image' => null, 'link' => null],
      'et_pb_blurb'           => ['text' => ['title' => false, 'content' => true], 'image' => ['alt'], 'link' => 'url'],
      'et_pb_image'           => ['text' => [], 'image' => ['alt', 'title_text'], 'link' => 'url'],
      'et_pb_button'          => ['text' => ['button_text' => false], 'image' => null, 'link' => 'button_url'],
      'et_pb_cta'             => ['text' => ['title' => false, 'button_text' => false, 'content' => true], 'image' => null, 'link' => 'button_url'],
      'et_pb_toggle'          => ['text' => ['title' => false, 'content' => true], 'image' => null, 'link' => null],
      'et_pb_accordion_item'  => ['text' => ['title' => false, 'content' => true], 'image' => null, 'link' => null],
      'et_pb_tab'             => ['text' => ['title' => false, 'content' => true], 'image' => null, 'link' => null],
      'et_pb_slide'           => ['text' => ['heading' => false, 'button_text' => false, 'content' => true], 'image' => ['image_alt'], 'link' => 'button_link'],
      'et_pb_testimonial'     => ['text' => ['content' => true, 'author' => false, 'job_title' => false, 'company_name' => false], 'image' => null, 'link' => null],
      'et_pb_fullwidth_header' => ['text' => ['title' => false, 'subhead' => false, 'button_one_text' => false, 'button_two_text' => false, 'content' => true], 'image' => null, 'link' => 'button_one_url'],
      'et_pb_number_counter'  => ['text' => ['title' => false], 'image' => null, 'link' => null],
      'et_pb_circle_counter'  => ['text' => ['title' => false], 'image' => null, 'link' => null],
      'et_pb_pricing_table'   => ['text' => ['title' => false, 'subtitle' => false, 'content' => true], 'image' => null, 'link' => null],
      'et_pb_team_member'     => ['text' => ['name' => false, 'position' => false, 'content' => true], 'image' => null, 'link' => null],
    ];
  }

  protected function encode_attr($value, $is_link = false) {
    // How Divi stores quotes and square brackets inside attribute values.
    return strtr((string) $value, ['"' => '%22', '[' => '%91', ']' => '%93']);
  }

  protected function decode_attr($value) {
    return strtr((string) $value, ['%22' => '"', '%91' => '[', '%93' => ']']);
  }

  /** Divi caches generated CSS per page; drop it so the page is rebuilt. */
  protected function after_save($post_id) {
    if (class_exists('ET_Core_PageResource') && method_exists('ET_Core_PageResource', 'remove_static_resources')) {
      ET_Core_PageResource::remove_static_resources($post_id, 'all');
    }
  }
}

/**
 * WPBakery Page Builder (#336). Built from WPBakery's developer docs (vc_map
 * shortcode parameters) and the shortcodes it saves: text inside
 * [vc_column_text], heading text in attributes, image ids whose alt text is
 * the media library's, links as "url:…|title:…|target:…" with the URL
 * URL-encoded.
 */
class Visibility_Builder_WPBakery extends Visibility_Shortcode_Builder {

  protected function name() { return 'WPBakery'; }
  protected function tag_pattern() { return 'vc_[a-z0-9_]+'; }

  public function available() {
    return defined('WPB_VC_VERSION');
  }

  public function supports($post) {
    return strpos((string) $post->post_content, '[vc_') !== false;
  }

  protected function modules() {
    return [
      'vc_column_text'    => ['text' => ['content' => true], 'image' => null, 'link' => null],
      'vc_custom_heading' => ['text' => ['text' => false], 'image' => null, 'link' => 'link'],
      'vc_single_image'   => ['text' => ['title' => false], 'image' => ['@attachment:image'], 'link' => null],
      'vc_btn'            => ['text' => ['title' => false], 'image' => null, 'link' => 'link'],
      'vc_cta'            => ['text' => ['h2' => false, 'h4' => false, 'content' => true], 'image' => null, 'link' => null],
      'vc_toggle'         => ['text' => ['title' => false, 'content' => true], 'image' => null, 'link' => null],
      'vc_tta_section'    => ['text' => ['title' => false], 'image' => null, 'link' => null],
      'vc_message'        => ['text' => ['content' => true], 'image' => null, 'link' => null],
      'vc_text_separator' => ['text' => ['title' => false], 'image' => null, 'link' => null],
    ];
  }

  protected function encode_attr($value, $is_link = false) {
    $value = (string) $value;
    if (preg_match('/["\[\]]/', $value)) return new WP_Error('invalid', 'quotes and square brackets cannot be stored in a WPBakery setting; leave them out');
    return $value;
  }

  protected function decode_attr($value) {
    return (string) $value;
  }

  /** "url:https%3A%2F%2F…|title:…" → the URL. */
  protected function link_url($v) {
    foreach (explode('|', (string) $v) as $part) {
      if (strpos($part, 'url:') === 0) return rawurldecode(substr($part, 4));
    }
    return '';
  }

  protected function link_value($old, $url) {
    $parts = $old === '' ? [] : explode('|', $old);
    $done = false;
    foreach ($parts as &$p) {
      if (strpos($p, 'url:') === 0) { $p = 'url:' . rawurlencode($url); $done = true; }
    }
    unset($p);
    if (!$done) array_unshift($parts, 'url:' . rawurlencode($url));
    return implode('|', $parts);
  }
}

add_filter('visibility_builder_adapters', function ($a) {
  $a['divi'] = new Visibility_Builder_Divi();
  $a['wpbakery'] = new Visibility_Builder_WPBakery();
  return $a;
});
