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
      'meta_key'       => self::SOURCE_META,
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
}
