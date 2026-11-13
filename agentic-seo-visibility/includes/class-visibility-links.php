<?php
/**
 * Insert internal links into post HTML without running a regex over the HTML.
 *
 * The content is split into tags/comments and text; only text nodes are
 * searched, and never text inside an existing link, a heading, code/pre,
 * script/style, a button, or a form control. Block delimiters are HTML
 * comments, so they are never touched. Works on WordPress 6.0 and PHP 7.4.
 */

if (!defined('ABSPATH')) {
  exit;
}

class Visibility_Links {

  /** Elements whose text must never get a link. */
  const SKIP = ['a', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'code', 'pre', 'kbd', 'samp', 'script', 'style', 'textarea', 'button', 'select', 'option', 'label', 'svg', 'math', 'iframe', 'noscript', 'template'];

  /**
   * Link the first eligible occurrence of $phrase to $url.
   *
   * @return array [string $html, bool $applied]
   */
  public static function link_phrase($html, $phrase, $url) {
    $phrase = trim((string) $phrase);
    if ($phrase === '') return [$html, false];
    $parts = preg_split('/(<!--.*?-->|<[^>]*>)/s', (string) $html, -1, PREG_SPLIT_DELIM_CAPTURE);
    if (!is_array($parts)) return [$html, false];
    $pattern = '/(?<![\p{L}\p{N}_])' . preg_quote(esc_html($phrase), '/') . '(?![\p{L}\p{N}_])/iu';
    $raw_pattern = '/(?<![\p{L}\p{N}_])' . preg_quote($phrase, '/') . '(?![\p{L}\p{N}_])/iu';
    $depth = [];
    $done = false;
    foreach ($parts as $i => $part) {
      if ($part === '') continue;
      if ($part[0] === '<') {
        if (strpos($part, '<!--') === 0) continue;
        if (preg_match('#^<\s*(/)?\s*([a-zA-Z][a-zA-Z0-9-]*)#', $part, $m)) {
          $tag = strtolower($m[2]);
          if (!in_array($tag, self::SKIP, true)) continue;
          $self_closing = substr(rtrim($part), -2) === '/>';
          if ($m[1] === '/') {
            if (!empty($depth[$tag])) $depth[$tag]--;
          } elseif (!$self_closing) {
            $depth[$tag] = (isset($depth[$tag]) ? $depth[$tag] : 0) + 1;
          }
        }
        continue;
      }
      if ($done || array_sum($depth) > 0) continue;
      foreach ([$pattern, $raw_pattern] as $p) {
        if (preg_match($p, $part, $mm, PREG_OFFSET_CAPTURE)) {
          $found = $mm[0][0];
          $off = $mm[0][1];
          $parts[$i] = substr($part, 0, $off)
            . '<a href="' . esc_url($url) . '">' . $found . '</a>'
            . substr($part, $off + strlen($found));
          $done = true;
          break;
        }
      }
    }
    return [$done ? implode('', $parts) : $html, $done];
  }

  /** True when $html already links to $url (ignoring a trailing slash). */
  public static function links_to($html, $url) {
    $u = untrailingslashit((string) $url);
    if ($u === '') return false;
    return (bool) preg_match('#<a\s[^>]*href=["\']' . preg_quote($u, '#') . '/?["\']#i', (string) $html);
  }
}
