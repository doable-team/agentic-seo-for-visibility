<?php
/**
 * Targeted content edits (#285): change one part of a page and leave every
 * other byte of post_content as it was.
 *
 * Gutenberg: blocks are located by their source offsets (same tokenizer rules
 * as WP_Block_Parser, so a path is the index into parse_blocks() at each
 * level, as in GET /posts/{id}?include=blocks). Only the target block's source
 * is rewritten; nothing is re-serialised.
 *
 * Classic: targets are "p.3" (4th paragraph), "h2.0", "li.1", "img.2", "a.0",
 * counted in document order. A paragraph is either an explicit <p> or a
 * blank-line separated chunk that wpautop turns into one. Shortcodes are kept
 * as text and never expanded; no <p> tags are added.
 *
 * Every path refers to the content as it was read: all targets are resolved
 * first, then applied from the end of the document to the start.
 */

if (!defined('ABSPATH')) {
  exit;
}

class Visibility_Editor {

  /** WP_Block_Parser's delimiter regex. */
  const BLOCK_RE = '/<!--[ \t\f\r\n]+(?P<closer>\/)?wp:(?P<namespace>[a-z][a-z0-9_-]*\/)?(?P<name>[a-z][a-z0-9_-]*)[ \t\f\r\n]+(?P<attrs>{(?:(?:[^}]+|}+(?=})|(?!}[ \t\f\r\n]+\/?-->).)*+)?}[ \t\f\r\n]+)?(?P<void>\/)?-->/s';

  const OPS = ['replace_text', 'set_attr', 'replace_block', 'insert_before', 'insert_after', 'remove'];

  /** Wrapper elements we look through to find the element that holds a block's text. */
  const CONTAINERS = ['div', 'figure', 'section', 'header', 'footer', 'article', 'aside', 'main', 'figcaption'];

  /** Inline tags a classic paragraph may start with. */
  const INLINE = ['a', 'abbr', 'b', 'br', 'cite', 'code', 'em', 'i', 'img', 'kbd', 'mark', 'q', 's', 'small', 'span', 'strong', 'sub', 'sup', 'u', 'del', 'ins', 'time'];

  /** Elements whose inside is never treated as paragraphs. */
  const OPAQUE = ['script', 'style', 'pre', 'textarea', 'iframe', 'svg', 'form', 'object', 'noscript', 'template', 'table', 'video', 'audio', 'canvas'];

  // ─── Entry point ───────────────────────────────────────────────────────

  /**
   * Apply $edits to $content.
   *
   * @return array|WP_Error ['content' => string, 'diff' => [...]] or an error
   *         whose code is unsupported / not_found / invalid.
   */
  public static function apply($content, array $edits) {
    $content = (string) $content;
    if (empty($edits)) return new WP_Error('invalid', 'edits required');
    $blocks = has_blocks($content);
    $nodes = $blocks ? self::flatten(self::block_tree($content)) : self::classic_nodes($content);

    // Resolve every target against the content as read.
    $groups = [];
    foreach (array_values($edits) as $i => $e) {
      if (!is_array($e)) return new WP_Error('invalid', sprintf('edit %d is not an object', $i));
      $op = isset($e['op']) ? (string) $e['op'] : '';
      if (!in_array($op, self::OPS, true)) return new WP_Error('unsupported', sprintf('edit %d: unknown op "%s" (use %s)', $i, $op, implode(', ', self::OPS)));
      $target = isset($e['target']) && is_array($e['target']) ? $e['target'] : [];
      $path = isset($target['path']) ? (string) $target['path'] : '';
      if ($path === '') return new WP_Error('invalid', sprintf('edit %d: target.path required', $i));
      if (!isset($nodes[$path])) return new WP_Error('not_found', sprintf('edit %d: no %s at path %s', $i, $blocks ? 'block' : 'element', $path));
      $groups[$path][] = $e;
    }
    // Different targets must not overlap (a block and its own inner block).
    $paths = array_keys($groups);
    foreach ($paths as $a) {
      foreach ($paths as $b) {
        if ($a === $b) continue;
        $na = $nodes[$a]; $nb = $nodes[$b];
        if ($na['start'] < $nb['end'] && $nb['start'] < $na['end']) {
          return new WP_Error('invalid', sprintf('edits on %s and %s overlap; send them in separate requests', $a, $b));
        }
      }
    }
    usort($paths, function ($a, $b) use ($nodes) { return $nodes[$b]['start'] - $nodes[$a]['start']; });

    $diff = [];
    foreach ($paths as $path) {
      $node = $nodes[$path];
      $before = substr($content, $node['start'], $node['end'] - $node['start']);
      $src = $before;
      $prefix = ''; $suffix = '';
      foreach ($groups[$path] as $e) {
        $r = $blocks ? self::edit_block($src, $e) : self::edit_classic($src, $node, $e);
        if (is_wp_error($r)) return new WP_Error($r->get_error_code(), $path . ': ' . $r->get_error_message());
        if ($e['op'] === 'insert_before') { $prefix = $r . $prefix; continue; }
        if ($e['op'] === 'insert_after')  { $suffix .= $r; continue; }
        $src = $r;
      }
      $start = $node['start']; $end = $node['end'];
      if ($src === '' && $prefix === '' && $suffix === '') {
        // Removal: take the separating blank lines that followed it too.
        if (preg_match('/\G[ \t]*(\r?\n[ \t]*)*/', $content, $m, 0, $end)) $end += strlen($m[0]);
      }
      $after = $prefix . $src . $suffix;
      $content = substr($content, 0, $start) . $after . substr($content, $end);
      $diff[] = [
        'target'    => ['path' => $path],
        'blockName' => $blocks ? $node['name'] : null,
        'tag'       => $blocks ? null : $node['tag'],
        'ops'       => array_map(function ($e) { return $e['op']; }, $groups[$path]),
        'before'    => $before,
        'after'     => $after,
      ];
    }
    // Report in document order.
    $diff = array_reverse($diff);
    return ['content' => $content, 'diff' => $diff];
  }

  /** Separator used around inserted markup. */
  private static function sep($blocks) { return "\n\n"; }

  // ─── Gutenberg ─────────────────────────────────────────────────────────

  /**
   * Block tree with source offsets. Mirrors WP_Block_Parser: at the top level
   * any HTML before a block is a freeform block and takes an index; inside a
   * block only blocks are counted.
   */
  public static function block_tree($content) {
    $root = []; $stack = []; $offset = 0; $top = 0;
    while (preg_match(self::BLOCK_RE, $content, $m, PREG_OFFSET_CAPTURE, $offset)) {
      $start = $m[0][1];
      $len = strlen($m[0][0]);
      $closer = isset($m['closer']) && $m['closer'][1] !== -1;
      $void = isset($m['void']) && $m['void'][1] !== -1;
      $ns = isset($m['namespace']) && $m['namespace'][1] !== -1 && $m['namespace'][0] !== '' ? $m['namespace'][0] : 'core/';
      $name = $ns . $m['name'][0];
      $attrs_src = isset($m['attrs']) && $m['attrs'][1] !== -1 && $m['attrs'][0] !== '' ? $m['attrs'][0] : null;
      if (empty($stack) && !$closer && $start > $offset) $top++; // leading freeform HTML
      if ($closer) {
        if (empty($stack)) break; // stray closer: WP treats the rest as freeform
        $node = array_pop($stack);
        $node['end'] = $start + $len;
        $node['close'] = [$start, $len];
        self::attach($node, $stack, $root, $top);
      } else {
        $node = [
          'name' => $name, 'attrs' => $attrs_src !== null ? json_decode($attrs_src, true) : [],
          'start' => $start, 'end' => $start + $len, 'open' => [$start, $len], 'close' => null,
          'children' => [], 'void' => $void,
        ];
        if ($void) self::attach($node, $stack, $root, $top);
        else $stack[] = $node;
      }
      $offset = $start + $len;
    }
    // Unclosed blocks: WP closes them at the end of the document.
    while (!empty($stack)) {
      $node = array_pop($stack);
      $node['end'] = strlen($content);
      self::attach($node, $stack, $root, $top);
    }
    return $root;
  }

  private static function attach($node, &$stack, &$root, &$top) {
    if (empty($stack)) {
      $node['index'] = $top++;
      $root[] = $node;
    } else {
      $p = count($stack) - 1;
      $node['index'] = count($stack[$p]['children']);
      $stack[$p]['children'][] = $node;
    }
  }

  /** path => node, for every block at every depth. */
  private static function flatten($tree, $prefix = '') {
    $out = [];
    foreach ($tree as $n) {
      $path = $prefix === '' ? (string) $n['index'] : $prefix . '.' . $n['index'];
      $n['path'] = $path;
      $out[$path] = $n;
      $out += self::flatten($n['children'], $path);
    }
    return $out;
  }

  /** The block at the start of $src (a block's own source). */
  private static function own_node($src) {
    $tree = self::block_tree($src);
    return isset($tree[0]) ? $tree[0] : null;
  }

  /**
   * Edit one block given its exact source.
   * @return string|WP_Error new source (or the markup to insert)
   */
  private static function edit_block($src, array $e) {
    $op = $e['op'];
    if ($op === 'insert_before' || $op === 'insert_after') {
      $html = self::new_markup($e);
      if (is_wp_error($html)) return $html;
      return $op === 'insert_before' ? $html . "\n\n" : "\n\n" . $html;
    }
    if ($op === 'remove') return '';
    if ($op === 'replace_block') return self::new_markup($e);

    $node = self::own_node($src);
    if (!$node) return new WP_Error('not_found', 'block not found');
    if ($node['name'] === 'core/block') {
      return new WP_Error('unsupported', sprintf('this is a synced pattern (wp_block %d) used on other pages too; edit that pattern instead (postId %d)', isset($node['attrs']['ref']) ? (int) $node['attrs']['ref'] : 0, isset($node['attrs']['ref']) ? (int) $node['attrs']['ref'] : 0));
    }
    $attrs = is_array($node['attrs']) ? $node['attrs'] : [];
    // Own HTML pieces: [start, end) ranges inside $src that are not inner blocks.
    $pieces = self::own_pieces($node);

    if ($op === 'replace_text') {
      if (!isset($e['text']) || !is_scalar($e['text'])) return new WP_Error('invalid', 'replace_text needs text');
      $text = wp_kses_post((string) $e['text']);
      $find = isset($e['find']) && is_scalar($e['find']) && (string) $e['find'] !== '' ? (string) $e['find'] : null;
      if ($find !== null) {
        $count = 0;
        $out = self::find_in_blocks($src, $find, $text, $count);
        if ($count === 0) return new WP_Error('not_found', sprintf('"%s" is not in this block', $find));
        return $out;
      }
      if (!empty($node['children'])) {
        return new WP_Error('unsupported', 'this block holds other blocks; target the inner block, or send "find" with the exact text to change');
      }
      $html = substr($src, $pieces[0][0], $pieces[0][1] - $pieces[0][0]);
      $span = self::text_element($html);
      if (is_wp_error($span)) return $span;
      $old_inner = substr($html, $span[0], $span[1] - $span[0]);
      $new_html = substr($html, 0, $span[0]) . $text . substr($html, $span[1]);
      $out = substr($src, 0, $pieces[0][0]) . $new_html . substr($src, $pieces[0][1]);
      // Blocks that also keep the text in a comment attribute.
      $attrs2 = $attrs;
      foreach ($attrs2 as $k => $v) {
        if (is_string($v) && $v !== '' && ($v === $old_inner || $v === trim(wp_strip_all_tags($old_inner)))) $attrs2[$k] = $v === $old_inner ? $text : wp_strip_all_tags($text);
      }
      return $attrs2 !== $attrs ? self::with_attrs($out, $attrs2) : $out;
    }

    if ($op === 'set_attr') {
      $set = isset($e['attrs']) && is_array($e['attrs']) ? $e['attrs'] : [];
      if (empty($set)) return new WP_Error('invalid', 'set_attr needs attrs');
      $out = $src;
      $attrs2 = $attrs;
      foreach ($set as $k => $v) {
        if (!is_scalar($v) && $v !== null) return new WP_Error('invalid', sprintf('attr %s must be a string or number', $k));
        $pieces = self::own_pieces(self::own_node($out));
        if ($k === 'alt') {
          $n = 0;
          $out = self::map_pieces($out, $pieces, function ($html) use ($v, &$n) { return self::set_tag_attr($html, 'img', 'alt', (string) $v, $n); });
          if (array_key_exists('alt', $attrs2)) { $attrs2['alt'] = sanitize_text_field((string) $v); $n++; }
          if ($n === 0) return new WP_Error('not_found', 'no image in this block');
        } elseif ($k === 'href' || $k === 'url') {
          $n = 0;
          $url = esc_url_raw((string) $v);
          if ($url === '') return new WP_Error('invalid', 'not a valid URL');
          $out = self::map_pieces($out, $pieces, function ($html) use ($url, &$n) { return self::set_tag_attr($html, 'a', 'href', $url, $n, true); });
          foreach (['url', 'href', 'link'] as $ak) if (array_key_exists($ak, $attrs2) && is_string($attrs2[$ak])) { $attrs2[$ak] = $url; $n++; }
          if ($n === 0) return new WP_Error('not_found', 'no link in this block');
        } elseif ($k === 'level') {
          $level = (int) $v;
          if ($level < 1 || $level > 6) return new WP_Error('invalid', 'level must be 1-6');
          $n = 0;
          $out = self::map_pieces($out, $pieces, function ($html) use ($level, &$n) {
            if ($n > 0 || !preg_match('/<h([1-6])\b/i', $html, $m, PREG_OFFSET_CAPTURE)) return $html;
            $old = $m[1][0];
            $close = '</h' . $old . '>';
            $cpos = stripos($html, $close, $m[0][1]);
            if ($cpos === false) return $html;
            $n++;
            $html = substr($html, 0, $cpos) . '</h' . $level . '>' . substr($html, $cpos + strlen($close));
            return substr($html, 0, $m[1][1]) . $level . substr($html, $m[1][1] + 1);
          });
          if ($n === 0) return new WP_Error('not_found', 'no heading in this block');
          if (isset($node['name']) && $node['name'] === 'core/heading') {
            if ($level === 2) unset($attrs2['level']); else $attrs2['level'] = $level;
          } elseif (array_key_exists('level', $attrs2)) {
            $attrs2['level'] = $level;
          }
        } elseif (array_key_exists($k, $attrs2) && (is_scalar($attrs2[$k]) || $attrs2[$k] === null)) {
          // Any other existing scalar block attribute (e.g. a Spectra or Kadence setting).
          $attrs2[$k] = is_string($v) ? sanitize_text_field($v) : $v;
        } else {
          return new WP_Error('unsupported', sprintf('attribute "%s" cannot be set on this block (use alt, href, level, or an attribute the block already has)', $k));
        }
      }
      return $attrs2 !== $attrs ? self::with_attrs($out, $attrs2) : $out;
    }
    return new WP_Error('unsupported', 'unknown op');
  }

  /** New markup from replace_block / insert_*: sanitised, never trusted. */
  private static function new_markup(array $e) {
    if (!isset($e['html']) || !is_string($e['html']) || trim($e['html']) === '') return new WP_Error('invalid', $e['op'] . ' needs html');
    return wp_kses_post($e['html']);
  }

  private static function own_pieces($node) {
    if (!$node || $node['void']) return [];
    $base = $node['start'];
    $from = $node['open'][0] + $node['open'][1] - $base;
    $pieces = [];
    foreach ($node['children'] as $c) {
      $pieces[] = [$from, $c['start'] - $base];
      $from = $c['end'] - $base;
    }
    $to = $node['close'] ? $node['close'][0] - $base : $node['end'] - $base;
    $pieces[] = [$from, $to];
    return $pieces;
  }

  /** Apply $fn to each own-HTML piece of $src (last first, so offsets hold). */
  private static function map_pieces($src, array $pieces, $fn) {
    for ($i = count($pieces) - 1; $i >= 0; $i--) {
      list($a, $b) = $pieces[$i];
      $src = substr($src, 0, $a) . $fn(substr($src, $a, $b - $a)) . substr($src, $b);
    }
    return $src;
  }

  /**
   * Replace $find in the text of a block and all its inner blocks, and in
   * their comment attributes; delimiters without a match stay byte-identical.
   */
  private static function find_in_blocks($src, $find, $text, &$count) {
    $out = ''; $offset = 0; $plain = wp_strip_all_tags($text);
    while (preg_match(self::BLOCK_RE, $src, $m, PREG_OFFSET_CAPTURE, $offset)) {
      $out .= self::replace_in_text(substr($src, $offset, $m[0][1] - $offset), $find, $text, $count);
      $delim = $m[0][0];
      if (isset($m['attrs']) && $m['attrs'][1] !== -1 && $m['attrs'][0] !== '') {
        $attrs = json_decode($m['attrs'][0], true);
        $n = 0;
        $new = is_array($attrs) ? self::replace_in_attrs($attrs, $find, $plain, $n) : $attrs;
        if ($n > 0) { $count += $n; $delim = self::with_attrs($delim, $new); }
      }
      $out .= $delim;
      $offset = $m[0][1] + strlen($m[0][0]);
    }
    return $out . self::replace_in_text(substr($src, $offset), $find, $text, $count);
  }

  /** Swap the block comment's attribute JSON (only this block's opener changes). */
  private static function with_attrs($src, array $attrs) {
    if (!preg_match(self::BLOCK_RE, $src, $m, PREG_OFFSET_CAPTURE) || $m[0][1] !== 0) return $src;
    $ns = isset($m['namespace']) && $m['namespace'][1] !== -1 ? $m['namespace'][0] : '';
    $void = isset($m['void']) && $m['void'][1] !== -1;
    $json = empty($attrs) ? '' : serialize_block_attributes($attrs) . ' ';
    $opener = '<!-- wp:' . $ns . $m['name'][0] . ' ' . $json . ($void ? '/' : '') . '-->';
    return $opener . substr($src, strlen($m[0][0]));
  }

  /**
   * [start, end) of the content of the element that holds the text: the first
   * element, looking through wrapper elements that hold exactly one element.
   */
  private static function text_element($html) {
    $from = 0; $to = strlen($html);
    for ($depth = 0; $depth < 8; $depth++) {
      $inner = substr($html, $from, $to - $from);
      if (!preg_match('/^\s*<([a-z][a-z0-9-]*)\b[^>]*>/i', $inner, $m)) {
        return $depth === 0 ? (trim($inner) === '' ? new WP_Error('unsupported', 'this block has no text') : [$from, $to]) : [$from, $to];
      }
      $tag = strtolower($m[1]);
      if ($depth > 0 && !in_array($tag, self::CONTAINERS, true) && $tag !== 'a' && $tag !== 'span') {
        // A single inline/formatting element inside the text element: that is text.
        return [$from, $to];
      }
      $open_end = $from + strlen($m[0]);
      $close = self::matching_close($html, $tag, $open_end);
      if ($close === null) return new WP_Error('unsupported', 'could not find where the text ends; send "find"');
      if (trim(substr($html, $close[1], $to - $close[1])) !== '') {
        return $depth === 0 ? new WP_Error('unsupported', 'this block has more than one text part; send "find" with the exact text to change') : [$from, $to];
      }
      $from = $open_end; $to = $close[0];
      if (!in_array($tag, self::CONTAINERS, true)) {
        // Look one level further only through a lone link/span wrapper (buttons).
        $rest = trim(substr($html, $from, $to - $from));
        if (!preg_match('/^<(a|span)\b[^>]*>/i', $rest)) return [$from, $to];
        $inner2 = self::text_element(substr($html, 0, $to));
        return $inner2;
      }
    }
    return [$from, $to];
  }

  /** [close start, close end] of the element <$tag> whose content starts at $pos. */
  private static function matching_close($html, $tag, $pos) {
    $depth = 1;
    $re = '#<(/?)' . preg_quote($tag, '#') . '\b[^>]*>#i';
    while (preg_match($re, $html, $m, PREG_OFFSET_CAPTURE, $pos)) {
      $pos = $m[0][1] + strlen($m[0][0]);
      if ($m[1][0] === '/') {
        if (--$depth === 0) return [$m[0][1], $pos];
      } elseif (substr($m[0][0], -2) !== '/>') {
        $depth++;
      }
    }
    return null;
  }

  /** Replace $find in text nodes only (never inside tags), raw or HTML-escaped. */
  private static function replace_in_text($html, $find, $text, &$count) {
    $parts = preg_split('/(<[^>]*>)/', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
    $variants = array_unique([$find, esc_html($find), htmlspecialchars($find, ENT_NOQUOTES)]);
    foreach ($parts as $i => $p) {
      if ($p === '' || $p[0] === '<') continue;
      foreach ($variants as $v) {
        $n = 0;
        $p = str_replace($v, $text, $p, $n);
        $count += $n;
      }
      $parts[$i] = $p;
    }
    return implode('', $parts);
  }

  /** Same in block comment attributes (string values, any depth). */
  private static function replace_in_attrs($attrs, $find, $text, &$count) {
    if (!is_array($attrs)) return $attrs;
    foreach ($attrs as $k => $v) {
      if (is_string($v) && strpos($v, $find) !== false) {
        $n = 0;
        $attrs[$k] = str_replace($find, $text, $v, $n);
        $count += $n;
      } elseif (is_array($v)) {
        $attrs[$k] = self::replace_in_attrs($v, $find, $text, $count);
      }
    }
    return $attrs;
  }

  /** Set attribute $name on every <$tag> (or only the first with $first_only). */
  private static function set_tag_attr($html, $tag, $name, $value, &$n, $first_only = false) {
    return preg_replace_callback('#<' . $tag . '\b[^>]*>#i', function ($m) use ($name, $value, &$n, $first_only) {
      if ($first_only && $n > 0) return $m[0];
      $n++;
      $val = $name . '="' . esc_attr($value) . '"';
      $re = '#(\s)' . $name . '\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i';
      if (preg_match($re, $m[0])) return preg_replace($re, '${1}' . str_replace(['\\', '$'], ['\\\\', '\$'], $val), $m[0], 1);
      return preg_replace('#\s*(/?)>$#', ' ' . $val . '$1>', $m[0], 1);
    }, $html);
  }

  // ─── Classic ───────────────────────────────────────────────────────────

  /**
   * path => node for classic content: explicit h1-h6/p/li/a/img elements and
   * bare (blank-line) paragraphs, each counted per tag in document order.
   */
  public static function classic_nodes($content) {
    $found = [];
    $opaque = [];
    foreach (self::OPAQUE as $t) {
      if (stripos($content, '<' . $t) === false) continue;
      if (preg_match_all('#<' . $t . '\b[^>]*>#i', $content, $mm, PREG_OFFSET_CAPTURE)) {
        foreach ($mm[0] as $o) {
          $close = self::matching_close($content, $t, $o[1] + strlen($o[0]));
          $opaque[] = [$o[1], $close ? $close[1] : strlen($content)];
        }
      }
    }
    $inside = function ($pos, $ranges) {
      foreach ($ranges as $r) if ($pos > $r[0] && $pos < $r[1]) return true;
      return false;
    };
    if (preg_match_all('#<(h[1-6]|p|li|a)\b[^>]*>#i', $content, $mm, PREG_OFFSET_CAPTURE)) {
      foreach ($mm[0] as $i => $o) {
        if ($inside($o[1], $opaque)) continue;
        $tag = strtolower($mm[1][$i][0]);
        $close = self::matching_close($content, $tag, $o[1] + strlen($o[0]));
        if (!$close) continue;
        $found[] = ['tag' => $tag, 'kind' => 'element', 'start' => $o[1], 'end' => $close[1]];
      }
    }
    if (preg_match_all('#<img\b[^>]*>#i', $content, $mm, PREG_OFFSET_CAPTURE)) {
      foreach ($mm[0] as $o) {
        if ($inside($o[1], $opaque)) continue;
        $found[] = ['tag' => 'img', 'kind' => 'void', 'start' => $o[1], 'end' => $o[1] + strlen($o[0])];
      }
    }
    // Bare paragraphs: blank-line chunks wpautop wraps in <p>.
    $explicit = array_values(array_filter($found, function ($n) { return in_array($n['tag'], ['p', 'li', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6'], true); }));
    $ranges = array_map(function ($n) { return [$n['start'], $n['end']]; }, $explicit);
    foreach (preg_split('/\n[ \t]*\r?\n\s*/', $content, -1, PREG_SPLIT_OFFSET_CAPTURE) as $chunk) {
      list($text, $pos) = $chunk;
      $lead = strlen($text) - strlen(ltrim($text));
      $trimmed = trim($text);
      if ($trimmed === '') continue;
      $start = $pos + $lead;
      if ($inside($start, $opaque) || $inside($start + 1, $ranges)) continue;
      $covered = false;
      foreach (array_merge($ranges, $opaque) as $r) if ($start >= $r[0] && $start < $r[1]) { $covered = true; break; }
      if ($covered) continue;
      if ($trimmed[0] === '<') {
        if (!preg_match('/^<([a-z][a-z0-9]*)/i', $trimmed, $m) || !in_array(strtolower($m[1]), self::INLINE, true)) continue;
      }
      if (preg_match('/^\[[^\]]+\]$/', $trimmed) || preg_match('/^\[([a-z0-9_-]+)[^\]]*\].*\[\/\1\]$/is', $trimmed)) continue; // shortcode on its own
      $found[] = ['tag' => 'p', 'kind' => 'bare', 'start' => $start, 'end' => $start + strlen($trimmed)];
    }
    usort($found, function ($a, $b) { return $a['start'] - $b['start']; });
    $out = []; $count = [];
    foreach ($found as $n) {
      $i = isset($count[$n['tag']]) ? $count[$n['tag']] : 0;
      $count[$n['tag']] = $i + 1;
      $n['path'] = $n['tag'] . '.' . $i;
      $n['name'] = null;
      $out[$n['path']] = $n;
    }
    return $out;
  }

  /** Classic nodes for the read endpoint (same paths the edits take). */
  public static function classic_read($content) {
    $out = [];
    foreach (self::classic_nodes((string) $content) as $path => $n) {
      $src = substr($content, $n['start'], $n['end'] - $n['start']);
      $attrs = [];
      if ($n['tag'] === 'img') {
        if (preg_match('/\salt=["\']([^"\']*)["\']/i', $src, $m)) $attrs['alt'] = html_entity_decode($m[1]);
        if (preg_match('/\ssrc=["\']([^"\']*)["\']/i', $src, $m)) $attrs['src'] = html_entity_decode($m[1]);
      } elseif ($n['tag'] === 'a' && preg_match('/\shref=["\']([^"\']*)["\']/i', $src, $m)) {
        $attrs['href'] = html_entity_decode($m[1]);
      } elseif ($n['tag'][0] === 'h' && $n['tag'] !== 'h') {
        $attrs['level'] = (int) substr($n['tag'], 1);
      }
      $out[] = [
        'path'        => $path,
        'blockName'   => null,
        'tag'         => $n['tag'],
        'attrs'       => empty($attrs) ? new stdClass() : $attrs,
        'text'        => trim(preg_replace('/\s+/', ' ', wp_strip_all_tags($src))),
        'innerBlocks' => [],
      ];
    }
    return $out;
  }

  private static function edit_classic($src, array $node, array $e) {
    $op = $e['op'];
    if ($op === 'insert_before' || $op === 'insert_after') {
      $html = self::new_markup($e);
      if (is_wp_error($html)) return $html;
      $sep = in_array($node['tag'], ['li', 'a', 'img'], true) ? '' : "\n\n";
      return $op === 'insert_before' ? $html . $sep : $sep . $html;
    }
    if ($op === 'remove') return '';
    if ($op === 'replace_block') return self::new_markup($e);
    if ($op === 'replace_text') {
      if (!isset($e['text']) || !is_scalar($e['text'])) return new WP_Error('invalid', 'replace_text needs text');
      $text = wp_kses_post((string) $e['text']);
      if (isset($e['find']) && is_scalar($e['find']) && (string) $e['find'] !== '') {
        $count = 0;
        $out = self::replace_in_text($src, (string) $e['find'], $text, $count);
        if ($count === 0) return new WP_Error('not_found', sprintf('"%s" is not in this element', $e['find']));
        return $out;
      }
      if ($node['kind'] === 'bare') return $text;
      if ($node['kind'] === 'void') return new WP_Error('unsupported', 'an image has no text; use set_attr alt');
      if (!preg_match('/^<[^>]*>/', $src, $m)) return new WP_Error('not_found', 'element not found');
      $close = strrpos($src, '</');
      return $m[0] . $text . substr($src, $close);
    }
    if ($op === 'set_attr') {
      $set = isset($e['attrs']) && is_array($e['attrs']) ? $e['attrs'] : [];
      if (empty($set)) return new WP_Error('invalid', 'set_attr needs attrs');
      $out = $src;
      foreach ($set as $k => $v) {
        $n = 0;
        if ($k === 'alt' && $node['tag'] === 'img') {
          $out = self::set_tag_attr($out, 'img', 'alt', (string) $v, $n, true);
        } elseif (($k === 'href' || $k === 'url') && $node['tag'] === 'a') {
          $url = esc_url_raw((string) $v);
          if ($url === '') return new WP_Error('invalid', 'not a valid URL');
          $out = self::set_tag_attr($out, 'a', 'href', $url, $n, true);
        } elseif ($k === 'level' && $node['tag'][0] === 'h') {
          $level = (int) $v;
          if ($level < 1 || $level > 6) return new WP_Error('invalid', 'level must be 1-6');
          $old = substr($node['tag'], 1);
          $out = preg_replace('/^<h' . $old . '\b/i', '<h' . $level, $out, 1);
          $out = preg_replace('#</h' . $old . '>$#i', '</h' . $level . '>', $out, 1);
          $n = 1;
        }
        if ($n === 0) return new WP_Error('unsupported', sprintf('attribute "%s" cannot be set on a %s', $k, $node['tag']));
      }
      return $out;
    }
    return new WP_Error('unsupported', 'unknown op');
  }
}
