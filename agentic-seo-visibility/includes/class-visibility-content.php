<?php
/**
 * Safe content writes.
 *
 * Requests from Visibility arrive with no logged-in WordPress user, so nobody
 * holds `unfiltered_html`. Two things follow from that, and both used to damage
 * existing pages:
 *
 * 1. WordPress attaches its kses filters to `content_save_pre` for such
 *    requests, so `wp_update_post()` re-filters the WHOLE page on every save
 *    and strips iframes, forms, scripts, inline SVG and similar markup that a
 *    site owner put there on purpose.
 * 2. `wp_insert_post()` unslashes its input. Unslashed content loses every
 *    backslash, which breaks block attributes such as "\u003c" in block JSON.
 *
 * So: sanitise only what is new (markup Visibility wrote), keep everything that
 * already existed on the page byte-identical, and save with the kses filters
 * off and the data slashed.
 */

if (!defined('ABSPATH')) {
  exit;
}

class Visibility_Content {

  /** Raw-HTML elements kses removes but a site may rely on (embeds, forms, tracking). */
  const PROTECTED_ELEMENTS = ['iframe', 'script', 'style', 'form', 'svg', 'object', 'embed', 'video', 'audio', 'noscript', 'canvas', 'template'];

  /**
   * Sanitise $new for saving over $old.
   *
   * Any block (at any depth), raw-HTML element or classic paragraph of $new that
   * appears verbatim in $old is kept as it is; the rest goes through
   * wp_kses_post(). With an empty $old (new posts) everything is sanitised.
   */
  public static function sanitize_update($new, $old = '') {
    $new = (string) $new;
    $old = (string) $old;
    if ($new === '') return '';
    if ($old === '' || $new === $old) {
      return $new === $old ? $new : wp_kses_post($new);
    }

    $keep = [];
    foreach (self::block_segments($new) as $seg) $keep[] = $seg;
    foreach (self::element_segments($new) as $seg) $keep[] = $seg;
    if (!has_blocks($new)) {
      foreach (preg_split("/\n\s*\n/", $new) as $para) {
        if (trim($para) !== '') $keep[] = $para;
      }
    }
    // Only what really existed before is trusted; longest first so an outer
    // segment wins over the pieces inside it.
    $keep = array_values(array_unique(array_filter($keep, function ($seg) use ($old) {
      return $seg !== '' && strpos($old, $seg) !== false;
    })));
    usort($keep, function ($a, $b) { return strlen($b) - strlen($a); });

    $nonce = 'visibilitykeep' . wp_generate_password(12, false, false);
    $map = [];
    $text = $new;
    foreach ($keep as $i => $seg) {
      if (strpos($text, $seg) === false) continue; // swallowed by a longer one
      $token = $nonce . 'x' . $i . 'x';
      $text = str_replace($seg, $token, $text);
      $map[$token] = $seg;
    }
    $text = wp_kses_post($text);
    return strtr($text, $map);
  }

  /**
   * Every block in $content, at every depth, as its exact source text.
   *
   * Walks the block delimiters itself rather than re-serialising
   * parse_blocks() output, which does not reproduce the source byte for byte.
   */
  public static function block_segments($content) {
    $out = [];
    if (strpos($content, '<!-- wp:') === false) return $out;
    $re = '/<!--\s+(\/)?wp:([a-z][a-z0-9_-]*\/)?([a-z][a-z0-9_-]*)\s+((?:\{.*?\}\s+)?)(\/)?-->/s';
    if (!preg_match_all($re, $content, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) return $out;
    $stack = [];
    foreach ($m as $tag) {
      $start  = $tag[0][1];
      $end    = $start + strlen($tag[0][0]);
      $name   = $tag[2][0] . $tag[3][0];
      $closer = isset($tag[1]) && $tag[1][0] === '/';
      $void   = isset($tag[5]) && $tag[5][0] === '/';
      if ($void) {
        $out[] = substr($content, $start, $end - $start);
      } elseif ($closer) {
        // Pop to the matching opener (tolerates broken nesting).
        for ($i = count($stack) - 1; $i >= 0; $i--) {
          if ($stack[$i][0] === $name) {
            $out[] = substr($content, $stack[$i][1], $end - $stack[$i][1]);
            array_splice($stack, $i);
            break;
          }
        }
      } else {
        $stack[] = [$name, $start];
      }
    }
    return $out;
  }

  /** Raw-HTML elements kses would strip (iframe, script, form…), as source text. */
  public static function element_segments($content) {
    $out = [];
    foreach (self::PROTECTED_ELEMENTS as $tag) {
      if (stripos($content, '<' . $tag) === false) continue;
      if (preg_match_all('#<' . $tag . '\b[^>]*>.*?</' . $tag . '\s*>#is', $content, $m)) {
        foreach ($m[0] as $seg) $out[] = $seg;
      }
      if ($tag === 'embed' && preg_match_all('#<embed\b[^>]*/?>#i', $content, $m)) {
        foreach ($m[0] as $seg) $out[] = $seg;
      }
    }
    return $out;
  }

  /**
   * wp_insert_post / wp_update_post without the kses round-trip and with the
   * data slashed, so saved content is exactly what we pass in.
   *
   * @return int|WP_Error
   */
  public static function save(array $args) {
    $had_kses = has_filter('content_save_pre', 'wp_filter_post_kses') !== false;
    if ($had_kses) kses_remove_filters();
    try {
      $slashed = wp_slash($args);
      $result = empty($args['ID']) ? wp_insert_post($slashed, true) : wp_update_post($slashed, true);
    } finally {
      if ($had_kses) kses_init_filters();
    }
    return $result;
  }

  /** Locks held by this request: name => depth. */
  private static $locks = [];

  /**
   * One writer at a time per post (#568): the modifiedGmt check and the write
   * happen under a MySQL named lock, so requests sent at the same moment with
   * the same modifiedGmt are serialised and all but the first see a conflict.
   * Re-entrant within a request. False when the lock could not be taken.
   */
  public static function lock($key, $wait = 10) {
    global $wpdb;
    $name = 'vis_' . md5(DB_NAME . '|' . $wpdb->prefix . '|' . $key);
    if (isset(self::$locks[$name])) { self::$locks[$name]++; return true; }
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a named lock serialises writes to one post across processes.
    $got = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $name, (int) $wait));
    if ((string) $got !== '1') return false;
    self::$locks[$name] = 1;
    if (is_numeric($key)) clean_post_cache((int) $key);
    return true;
  }

  public static function unlock($key) {
    global $wpdb;
    $name = 'vis_' . md5(DB_NAME . '|' . $wpdb->prefix . '|' . $key);
    if (!isset(self::$locks[$name])) return;
    if (--self::$locks[$name] > 0) return;
    unset(self::$locks[$name]);
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- releases the lock taken in lock().
    $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name));
  }

  /**
   * modifiedGmt has one-second resolution, so two writes in the same second
   * left the same token and a second caller holding the old one passed the
   * check (#568). After a change, the post's modified time is moved on to at
   * least one second after the one the change started from.
   */
  public static function advance_modified($post_id, $before_gmt) {
    global $wpdb;
    $before = strtotime((string) $before_gmt);
    $now = strtotime((string) self::modified_gmt($post_id));
    if ($before === false || $now === false || $now > $before) return;
    $gmt = gmdate('Y-m-d H:i:s', $before + 1);
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- only the modified time, without running save_post again.
    $wpdb->update($wpdb->posts, ['post_modified_gmt' => $gmt, 'post_modified' => get_date_from_gmt($gmt)], ['ID' => (int) $post_id]);
    clean_post_cache((int) $post_id);
  }

  /** post_modified_gmt as ISO 8601 UTC ("2026-11-13T18:55:14Z"), the optimistic-lock token. */
  public static function modified_gmt($post) {
    $post = get_post($post);
    if (!$post) return null;
    $gmt = $post->post_modified_gmt;
    if (empty($gmt) || $gmt === '0000-00-00 00:00:00') {
      $gmt = get_gmt_from_date($post->post_modified);
    }
    return gmdate('Y-m-d\TH:i:s\Z', strtotime($gmt . ' UTC'));
  }

  /**
   * True when the caller's `modifiedGmt` no longer matches the post — someone
   * changed it after it was read. A missing value is not a conflict (older
   * clients don't send one).
   */
  public static function is_stale($post_id, $modified_gmt) {
    if (!is_string($modified_gmt) || $modified_gmt === '') return false;
    $sent = strtotime($modified_gmt);
    $now  = strtotime((string) self::modified_gmt($post_id));
    if ($sent === false || $now === false) return false;
    return $sent !== $now;
  }
}
