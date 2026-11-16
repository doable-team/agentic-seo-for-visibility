<?php
/**
 * Media uploads for agents: copy an image from a URL into the media library
 * with alt text, title, caption and a readable file name, optionally attached
 * to a post. The same source URL is uploaded once and re-used afterwards.
 */

if (!defined('ABSPATH')) {
  exit;
}

class Visibility_Media {

  /** Post meta holding the URL an attachment was copied from (re-use key). */
  const SOURCE_META = '_visibility_source_url';

  /**
   * @param array $args source_url (required), title, alt, caption, description, filename, post_id
   * @return array|WP_Error serialized attachment (+ reused flag)
   */
  public static function upload(array $args) {
    $source = isset($args['source_url']) ? esc_url_raw(trim((string) $args['source_url'])) : '';
    if ($source === '' || !preg_match('#^https?://#i', $source)) {
      return new WP_Error('visibility_no_url', __('source_url must be an http(s) URL.', 'agentic-seo-visibility'), ['status' => 400]);
    }
    $post_id = isset($args['post_id']) ? (int) $args['post_id'] : 0;
    if ($post_id && !get_post($post_id)) {
      return new WP_Error('visibility_no_post', __('post_id does not exist.', 'agentic-seo-visibility'), ['status' => 400]);
    }

    $existing = self::find_by_source($source);
    if ($existing) {
      self::apply_meta($existing, $args, $post_id);
      return self::serialize($existing, true);
    }

    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    $tmp = download_url($source, 30);
    if (is_wp_error($tmp)) {
      /* translators: %s: the download error message. */
      return new WP_Error('visibility_download_failed', sprintf(__('Could not download the image: %s', 'agentic-seo-visibility'), $tmp->get_error_message()), ['status' => 400]);
    }

    // The real type decides the extension; never trust the URL or the caller.
    $type = wp_get_image_mime($tmp);
    $ext_by_type = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp', 'image/avif' => 'avif'];
    if (!$type || !isset($ext_by_type[$type])) {
      wp_delete_file($tmp);
      return new WP_Error('visibility_not_an_image', __('The URL is not a JPEG, PNG, GIF, WebP or AVIF image.', 'agentic-seo-visibility'), ['status' => 400]);
    }
    $name = self::file_name($args, $source, $ext_by_type[$type]);

    $attachment_id = media_handle_sideload(['name' => $name, 'tmp_name' => $tmp], $post_id, null);
    if (is_wp_error($attachment_id)) {
      if (file_exists($tmp)) wp_delete_file($tmp);
      return new WP_Error('visibility_upload_failed', $attachment_id->get_error_message(), ['status' => 400]);
    }
    update_post_meta($attachment_id, self::SOURCE_META, $source);
    self::apply_meta($attachment_id, $args, $post_id);
    return self::serialize($attachment_id, false);
  }

  /** "SEO Audit Checklist!.PNG" → "seo-audit-checklist.png", extension from the real type. */
  private static function file_name($args, $source, $ext) {
    $base = isset($args['filename']) ? (string) $args['filename'] : '';
    if ($base === '' && !empty($args['title'])) $base = (string) $args['title'];
    if ($base === '') {
      $path = (string) wp_parse_url($source, PHP_URL_PATH);
      $base = $path !== '' ? wp_basename($path) : 'image';
    }
    $base = preg_replace('/\.(jpe?g|png|gif|webp|avif|svg|bmp|tiff?)$/i', '', $base);
    $base = sanitize_title(remove_accents($base));
    if ($base === '') $base = 'image';
    return substr($base, 0, 80) . '.' . $ext;
  }

  /** Title, alt, caption, description; attach to the post if not attached yet. */
  private static function apply_meta($id, $args, $post_id) {
    $update = ['ID' => $id];
    if (isset($args['title']) && $args['title'] !== '')             $update['post_title']   = sanitize_text_field((string) $args['title']);
    if (isset($args['caption']))                                     $update['post_excerpt'] = wp_kses_post((string) $args['caption']);
    if (isset($args['description']))                                 $update['post_content'] = wp_kses_post((string) $args['description']);
    if ($post_id && (int) get_post_field('post_parent', $id) === 0)  $update['post_parent']  = $post_id;
    if (count($update) > 1) wp_update_post(wp_slash($update));
    if (isset($args['alt'])) {
      $alt = sanitize_text_field((string) $args['alt']);
      if ($alt === '') delete_post_meta($id, '_wp_attachment_image_alt'); else update_post_meta($id, '_wp_attachment_image_alt', wp_slash($alt));
    }
  }

  private static function find_by_source($source) {
    $ids = get_posts([
      'post_type'      => 'attachment',
      'post_status'    => 'inherit',
      'posts_per_page' => 1,
      'fields'         => 'ids',
      // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one-off lookup.
      'meta_key'       => self::SOURCE_META,
      // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- one-off lookup.
      'meta_value'     => $source,
    ]);
    if ($ids) return (int) $ids[0];
    // A URL that already points at this site's own media library.
    $local = attachment_url_to_postid($source);
    return $local ? (int) $local : 0;
  }

  public static function serialize($attachment_id, $reused = null) {
    $post = get_post($attachment_id);
    if (!$post || $post->post_type !== 'attachment') return null;
    $meta = wp_get_attachment_metadata($post->ID);
    $file = get_attached_file($post->ID);
    $out = [
      'id'          => (int) $post->ID,
      'mediaId'     => (int) $post->ID,
      'title'       => $post->post_title,
      'slug'        => $post->post_name,
      'mimeType'    => $post->post_mime_type,
      'url'         => wp_get_attachment_url($post->ID),
      'altText'     => (string) get_post_meta($post->ID, '_wp_attachment_image_alt', true),
      'caption'     => $post->post_excerpt,
      'description' => $post->post_content,
      'postId'      => (int) $post->post_parent,
      'width'       => isset($meta['width']) ? (int) $meta['width'] : null,
      'height'      => isset($meta['height']) ? (int) $meta['height'] : null,
      'filename'    => $file ? wp_basename($file) : null,
      'sourceUrl'   => (string) get_post_meta($post->ID, self::SOURCE_META, true) ?: null,
      'date'        => mysql2date('c', $post->post_date_gmt && $post->post_date_gmt !== '0000-00-00 00:00:00' ? $post->post_date_gmt : current_time('mysql', true), false),
    ];
    if ($reused !== null) $out['reused'] = (bool) $reused;
    return $out;
  }

  // ─── Alt text in bulk (#297) ───────────────────────────────────────────

  const BULK_MAX = 50;

  /** GET /media?missingAlt=1: images without alt text, newest first. */
  public static function missing_alt($page, $per) {
    $per = max(1, min(100, (int) $per));
    $page = max(1, (int) $page);
    $q = new WP_Query([
      'post_type'      => 'attachment',
      'post_status'    => 'inherit',
      'post_mime_type' => 'image',
      'posts_per_page' => $per,
      'paged'          => $page,
      'orderby'        => 'date',
      'order'          => 'DESC',
      'meta_query'     => ['relation' => 'OR', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- the only way to find images without alt text.
        ['key' => '_wp_attachment_image_alt', 'compare' => 'NOT EXISTS'],
        ['key' => '_wp_attachment_image_alt', 'value' => '', 'compare' => '='],
      ],
    ]);
    $items = [];
    foreach ($q->posts as $p) {
      $m = self::serialize($p->ID);
      $m['usedIn'] = self::used_in($p->ID, 5);
      $items[] = $m;
    }
    return ['items' => $items, 'page' => $page, 'perPage' => $per, 'total' => (int) $q->found_posts, 'totalPages' => (int) $q->max_num_pages];
  }

  /** Posts that show an image: in their content (wp-image-ID), as featured image, or as its parent. Best effort. */
  public static function used_in($id, $limit = 5) {
    $ids = self::content_posts($id, $limit);
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $thumbs = $wpdb->get_col($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value = %s LIMIT %d", (string) $id, $limit));
    $ids = array_merge($ids, array_map('intval', $thumbs));
    $parent = (int) wp_get_post_parent_id($id);
    if ($parent) $ids[] = $parent;
    $out = [];
    foreach (array_unique($ids) as $pid) {
      $p = get_post($pid);
      if (!$p || in_array($p->post_status, ['trash', 'auto-draft', 'inherit'], true)) continue;
      $out[] = ['postId' => (int) $pid, 'title' => $p->post_title];
      if (count($out) >= $limit) break;
    }
    return $out;
  }

  /** Ids of posts whose content has the image (class wp-image-ID). */
  private static function content_posts($id, $limit = 200) {
    global $wpdb;
    $types = array_keys(Visibility_Read::readable_types());
    if (!$types) return [];
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a one-off lookup of posts containing an image class; no API covers a content search by class.
    $rows = $wpdb->get_col($wpdb->prepare("SELECT ID, post_type FROM {$wpdb->posts} WHERE post_status NOT IN ('trash','auto-draft','inherit') AND post_content LIKE %s ORDER BY ID LIMIT %d", '%' . $wpdb->esc_like('wp-image-' . (int) $id) . '%', (int) $limit * 4));
    // Readable content types only.
    $rows = array_slice(array_values(array_filter($rows, function ($pid) use ($types) { return in_array(get_post_type((int) $pid), $types, true); })), 0, (int) $limit);
    // The LIKE also matches wp-image-12 for 1; keep exact ids only.
    return array_values(array_filter(array_map('intval', $rows), function ($pid) use ($id) {
      return (bool) preg_match('/\bwp-image-' . (int) $id . '\b/', (string) get_post_field('post_content', $pid, 'raw'));
    }));
  }

  /**
   * Set the alt of every <img class="wp-image-ID"> in $content whose alt is
   * empty or still the image's old library alt (an alt someone wrote for this
   * page on purpose is kept). Covers core/image blocks (their alt lives in the
   * HTML) and classic content. @return [content, count]
   */
  public static function set_alt_in_content($content, $id, $old_alt, $new_alt) {
    $n = 0;
    $out = preg_replace_callback('#<img\b[^>]*>#i', function ($m) use ($id, $old_alt, $new_alt, &$n) {
      $tag = $m[0];
      if (!preg_match('/\bwp-image-' . (int) $id . '\b/', $tag)) return $tag;
      $cur = preg_match('/\salt\s*=\s*("([^"]*)"|\'([^\']*)\')/i', $tag, $a) ? html_entity_decode(isset($a[3]) && $a[3] !== '' ? $a[3] : $a[2], ENT_QUOTES) : '';
      if ($cur !== '' && $cur !== $old_alt) return $tag;
      if ($cur === $new_alt && preg_match('/\salt\s*=/i', $tag)) return $tag;
      $val = 'alt="' . esc_attr($new_alt) . '"';
      $n++;
      if (preg_match('/\salt\s*=\s*("[^"]*"|\'[^\']*\')/i', $tag)) return preg_replace('/(\s)alt\s*=\s*("[^"]*"|\'[^\']*\')/i', '${1}' . str_replace(['\\', '$'], ['\\\\', '\$'], $val), $tag, 1);
      return preg_replace('#\s*(/?)>$#', ' ' . $val . '$1>', $tag, 1);
    }, (string) $content);
    return [$out, $n];
  }

  /**
   * media.bulk_alt {items:[{mediaId, altText, title?, caption?}]} (max 50).
   * Each image: library fields, then its alt inside the posts that show it.
   * One change for the whole batch; rolling it back puts every old value back.
   */
  public static function bulk_alt($items) {
    if (!is_array($items) || !$items) return new WP_Error('invalid', 'items required: [{mediaId, altText, title?, caption?}]');
    if (count($items) > self::BULK_MAX) return new WP_Error('invalid', sprintf('At most %d images per request.', self::BULK_MAX));
    $updated = []; $failed = [];
    $before_state = ['media' => [], 'posts' => []];
    $after_state = ['media' => [], 'posts' => []];
    $touched_posts = [];
    foreach (array_values($items) as $i => $it) {
      $id = is_array($it) ? (int) ($it['mediaId'] ?? 0) : 0;
      $p = $id ? get_post($id) : null;
      if (!$p || $p->post_type !== 'attachment' || strpos((string) $p->post_mime_type, 'image/') !== 0) { $failed[] = ['mediaId' => $id, 'reason' => 'not an image in the media library']; continue; }
      if (!array_key_exists('altText', $it) || !is_scalar($it['altText'])) { $failed[] = ['mediaId' => $id, 'reason' => 'altText required']; continue; }
      $refused = Visibility_Policy::check_controls(['images'], $id);
      if ($refused) { $failed[] = ['mediaId' => $id, 'reason' => $refused[1]]; continue; }
      $alt = sanitize_text_field((string) $it['altText']);
      $before = ['altText' => (string) get_post_meta($id, '_wp_attachment_image_alt', true), 'title' => $p->post_title, 'caption' => $p->post_excerpt];
      update_post_meta($id, '_wp_attachment_image_alt', $alt);
      $up = ['ID' => $id];
      if (isset($it['title']) && is_scalar($it['title'])) $up['post_title'] = sanitize_text_field((string) $it['title']);
      if (isset($it['caption']) && is_scalar($it['caption'])) $up['post_excerpt'] = sanitize_text_field((string) $it['caption']);
      if (count($up) > 1) wp_update_post($up, true);
      clean_post_cache($id);
      $p = get_post($id);
      $after = ['altText' => (string) get_post_meta($id, '_wp_attachment_image_alt', true), 'title' => $p->post_title, 'caption' => $p->post_excerpt];
      $before_state['media'][$id] = $before;
      $after_state['media'][$id] = $after;
      // The same image inside posts and pages.
      $blocks = 0; $skipped = [];
      if ($alt !== '') {
        foreach (self::content_posts($id) as $pid) {
          if (Visibility_Policy::check_controls(['images'], $pid)) { $skipped[] = $pid; continue; }
          $content = isset($after_state['posts'][$pid]) ? $after_state['posts'][$pid] : (string) get_post_field('post_content', $pid, 'raw');
          list($new, $n) = self::set_alt_in_content($content, $id, $before['altText'], $alt);
          if (!$n) continue;
          if (!isset($before_state['posts'][$pid])) $before_state['posts'][$pid] = $content;
          $after_state['posts'][$pid] = $new;
          $touched_posts[$pid] = true;
          $blocks++;
        }
      }
      $row = ['mediaId' => $id, 'before' => $before, 'after' => $after, 'blocksUpdated' => $blocks];
      if ($skipped) $row['postsSkipped'] = $skipped;
      $updated[] = $row;
    }
    foreach (array_keys($touched_posts) as $pid) {
      Visibility_Content::save(['ID' => $pid, 'post_content' => $after_state['posts'][$pid]]);
      $after_state['posts'][$pid] = (string) get_post_field('post_content', $pid, 'raw');
    }
    $change = null;
    if ($updated) {
      $changed = [];
      foreach (array_keys($after_state['media']) as $id) $changed[] = 'media:' . $id;
      foreach (array_keys($after_state['posts']) as $pid) $changed[] = 'posts:' . $pid;
      $change = Visibility_Changes::record_custom('media.bulk_alt', $changed, $before_state, $after_state);
    }
    $purged = [];
    foreach (array_keys($touched_posts) as $pid) $purged = array_merge($purged, Visibility_Cache::purge($pid));
    return ['updated' => $updated, 'failed' => $failed, 'changeId' => $change, 'postsUpdated' => array_map('intval', array_keys($touched_posts)), 'cachePurged' => array_values(array_unique($purged))];
  }

  /** Undo a media.bulk_alt change, only while nothing was changed since. */
  public static function rollback_custom($result, $row, $states) {
    if ($row->action !== 'media.bulk_alt') return;
    list($before, $after) = $states;
    $drift = [];
    foreach ((array) $after['media'] as $id => $a) {
      $p = get_post($id);
      if (!$p) { $drift[] = 'media:' . $id; continue; }
      $now = ['altText' => (string) get_post_meta($id, '_wp_attachment_image_alt', true), 'title' => $p->post_title, 'caption' => $p->post_excerpt];
      if ($now !== $a) $drift[] = 'media:' . $id;
    }
    foreach ((array) $after['posts'] as $pid => $content) {
      if ((string) get_post_field('post_content', $pid, 'raw') !== $content) $drift[] = 'posts:' . $pid;
    }
    if ($drift) {
      $result->error = new WP_Error('conflict', 'Some images or pages were changed after this change, so it was not rolled back: ' . implode(', ', $drift) . '.', ['changedSince' => $drift]);
      return;
    }
    foreach ((array) $before['media'] as $id => $b) {
      update_post_meta($id, '_wp_attachment_image_alt', $b['altText']);
      wp_update_post(['ID' => $id, 'post_title' => $b['title'], 'post_excerpt' => $b['caption']]);
    }
    foreach ((array) $before['posts'] as $pid => $content) {
      Visibility_Content::save(['ID' => $pid, 'post_content' => $content]);
      Visibility_Cache::purge($pid);
    }
    $result->data = ['media' => array_map('intval', array_keys((array) $before['media'])), 'posts' => array_map('intval', array_keys((array) $before['posts']))];
  }
}
