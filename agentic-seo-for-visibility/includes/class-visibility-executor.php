<?php
/**
 * Visibility action-request executor.
 *
 * Takes a server-validated action request and applies it locally via
 * standard WordPress APIs (wp_insert_post, media_handle_sideload, etc).
 *
 * Returns:
 *   [true,  $result_array, null]  on success
 *   [false, null,          $msg]  on failure
 *
 * The caller (Visibility_Requests) reports the outcome back to Visibility's
 * server, which marks the action_request as executed or failed.
 */

if (!defined('ABSPATH')) {
  exit;
}

class Visibility_Executor {

  public static function execute($action_type, $payload) {
    if (!is_array($payload)) {
      return [false, null, 'Payload is not an object'];
    }
    $method = self::method_for($action_type);
    if ($method === null) {
      return [false, null, sprintf('Unsupported action type: %s', $action_type)];
    }
    try {
      return self::{$method}($payload);
    } catch (\Throwable $e) {
      return [false, null, $e->getMessage()];
    }
  }

  private static function method_for($action_type) {
    static $map = [
      'post.create_draft'      => 'exec_post_create_draft',
      'post.update_content'    => 'exec_post_update_content',
      'post.update_meta'       => 'exec_post_update_meta',
      'post.publish'           => 'exec_post_publish',
      'post.schedule'          => 'exec_post_schedule',
      'post.unpublish'         => 'exec_post_unpublish',
      'post.trash'             => 'exec_post_trash',
      'post.restore'           => 'exec_post_restore',
      'post.delete_permanent'  => 'exec_post_delete_permanent',
      'internal_link.suggest'  => 'exec_internal_link_suggest',
      'page.create_draft'      => 'exec_page_create_draft',
      'page.update_content'    => 'exec_page_update_content',
      'page.publish'           => 'exec_page_publish',
      'media.upload_from_url'  => 'exec_media_upload_from_url',
      'media.update_meta'      => 'exec_media_update_meta',
      'media.delete'           => 'exec_media_delete',
      'category.create'        => 'exec_category_create',
      'category.update'        => 'exec_category_update',
      'category.delete'        => 'exec_category_delete',
      'tag.create'             => 'exec_tag_create',
      'tag.update'             => 'exec_tag_update',
      'tag.delete'             => 'exec_tag_delete',
      'seo_meta.update'        => 'exec_seo_meta_update',
      'redirect.create'        => 'exec_redirect_create',
      'redirect.delete'        => 'exec_redirect_delete',
    ];
    return isset($map[$action_type]) ? $map[$action_type] : null;
  }

  // ─── Posts / Pages ─────────────────────────────────────────────────────

  private static function insert_post_like($payload, $post_type) {
    $args = [
      'post_type'     => $post_type,
      'post_status'   => 'draft',
      'post_title'    => isset($payload['title']) ? wp_kses_post($payload['title']) : '',
      'post_content'  => isset($payload['content']) ? wp_kses_post($payload['content']) : '',
    ];
    if (!empty($payload['excerpt'])) {
      $args['post_excerpt'] = wp_kses_post($payload['excerpt']);
    }
    if (!empty($payload['slug'])) {
      $args['post_name'] = sanitize_title($payload['slug']);
    }
    $post_id = wp_insert_post($args, true);
    if (is_wp_error($post_id)) {
      return [false, null, $post_id->get_error_message()];
    }
    if (!empty($payload['categories']) && is_array($payload['categories'])) {
      wp_set_post_categories($post_id, array_map('intval', $payload['categories']), false);
    }
    if (!empty($payload['tags']) && is_array($payload['tags'])) {
      wp_set_post_tags($post_id, $payload['tags'], false);
    }
    if (!empty($payload['featuredMediaId'])) {
      set_post_thumbnail($post_id, intval($payload['featuredMediaId']));
    }
    return [true, self::post_result($post_id), null];
  }

  private static function exec_post_create_draft($payload) {
    return self::insert_post_like($payload, 'post');
  }
  private static function exec_page_create_draft($payload) {
    return self::insert_post_like($payload, 'page');
  }

  private static function exec_post_update_content($payload) {
    $post_id = intval($payload['postId'] ?? 0);
    if (!$post_id) return [false, null, 'postId required'];
    $fields = $payload['fields'] ?? [];
    $args = ['ID' => $post_id];
    if (array_key_exists('title', $fields))   $args['post_title'] = wp_kses_post((string) $fields['title']);
    if (array_key_exists('content', $fields)) $args['post_content'] = wp_kses_post((string) $fields['content']);
    if (array_key_exists('excerpt', $fields)) $args['post_excerpt'] = wp_kses_post((string) $fields['excerpt']);
    if (array_key_exists('slug', $fields))    $args['post_name'] = sanitize_title((string) $fields['slug']);
    $result = wp_update_post($args, true);
    if (is_wp_error($result)) return [false, null, $result->get_error_message()];
    return [true, self::post_result($post_id), null];
  }
  private static function exec_page_update_content($payload) {
    return self::exec_post_update_content($payload);
  }

  private static function exec_post_update_meta($payload) {
    $post_id = intval($payload['postId'] ?? 0);
    if (!$post_id) return [false, null, 'postId required'];
    $fields = $payload['fields'] ?? [];
    if (array_key_exists('categories', $fields) && is_array($fields['categories'])) {
      wp_set_post_categories($post_id, array_map('intval', $fields['categories']), false);
    }
    if (array_key_exists('tags', $fields) && is_array($fields['tags'])) {
      wp_set_post_tags($post_id, $fields['tags'], false);
    }
    if (array_key_exists('featuredMediaId', $fields)) {
      if ($fields['featuredMediaId'] === null) {
        delete_post_thumbnail($post_id);
      } else {
        set_post_thumbnail($post_id, intval($fields['featuredMediaId']));
      }
    }
    if (array_key_exists('author', $fields)) {
      wp_update_post(['ID' => $post_id, 'post_author' => intval($fields['author'])], true);
    }
    if (array_key_exists('parent', $fields)) {
      wp_update_post(['ID' => $post_id, 'post_parent' => $fields['parent'] === null ? 0 : intval($fields['parent'])], true);
    }
    return [true, self::post_result($post_id), null];
  }

  private static function exec_post_publish($payload) {
    $post_id = intval($payload['postId'] ?? $payload['pageId'] ?? 0);
    if (!$post_id) return [false, null, 'postId required'];
    $result = wp_update_post(['ID' => $post_id, 'post_status' => 'publish'], true);
    if (is_wp_error($result)) return [false, null, $result->get_error_message()];
    return [true, self::post_result($post_id), null];
  }
  private static function exec_page_publish($payload) {
    return self::exec_post_publish($payload);
  }

  private static function exec_post_schedule($payload) {
    $post_id = intval($payload['postId'] ?? 0);
    if (!$post_id) return [false, null, 'postId required'];
    $publish_at = isset($payload['publishAt']) ? strtotime((string) $payload['publishAt']) : false;
    if (!$publish_at) return [false, null, 'publishAt is required (ISO8601)'];
    $result = wp_update_post([
      'ID'            => $post_id,
      'post_status'   => 'future',
      'post_date_gmt' => gmdate('Y-m-d H:i:s', $publish_at),
      'post_date'     => get_date_from_gmt(gmdate('Y-m-d H:i:s', $publish_at)),
    ], true);
    if (is_wp_error($result)) return [false, null, $result->get_error_message()];
    return [true, self::post_result($post_id), null];
  }

  private static function exec_post_unpublish($payload) {
    $post_id = intval($payload['postId'] ?? 0);
    $target = $payload['target'] ?? 'draft';
    if (!$post_id) return [false, null, 'postId required'];
    if ($target !== 'draft' && $target !== 'private') $target = 'draft';
    $result = wp_update_post(['ID' => $post_id, 'post_status' => $target], true);
    if (is_wp_error($result)) return [false, null, $result->get_error_message()];
    return [true, self::post_result($post_id), null];
  }

  private static function exec_post_trash($payload) {
    $post_id = intval($payload['postId'] ?? 0);
    if (!$post_id) return [false, null, 'postId required'];
    $trashed = wp_trash_post($post_id);
    if (!$trashed) return [false, null, 'wp_trash_post failed'];
    return [true, ['postId' => $post_id, 'status' => 'trash'], null];
  }

  private static function exec_post_restore($payload) {
    $post_id = intval($payload['postId'] ?? 0);
    if (!$post_id) return [false, null, 'postId required'];
    $restored = wp_untrash_post($post_id);
    if (!$restored) return [false, null, 'wp_untrash_post failed'];
    return [true, self::post_result($post_id), null];
  }

  private static function exec_post_delete_permanent($payload) {
    $post_id = intval($payload['postId'] ?? 0);
    if (!$post_id) return [false, null, 'postId required'];
    $deleted = wp_delete_post($post_id, true);
    if (!$deleted) return [false, null, 'wp_delete_post failed'];
    return [true, ['postId' => $post_id, 'deleted' => true], null];
  }

  private static function exec_internal_link_suggest($payload) {
    $post_id = intval($payload['postId'] ?? 0);
    if (!$post_id) return [false, null, 'postId required'];
    $suggestions = isset($payload['suggestions']) && is_array($payload['suggestions']) ? $payload['suggestions'] : [];
    if (empty($suggestions)) return [false, null, 'suggestions required'];

    $post = get_post($post_id);
    if (!$post) return [false, null, 'post not found'];
    $content = $post->post_content;
    $applied = 0;

    foreach ($suggestions as $sug) {
      $phrase = isset($sug['phrase']) ? (string) $sug['phrase'] : '';
      $target = isset($sug['targetPostId']) ? intval($sug['targetPostId']) : 0;
      if ($phrase === '' || $target <= 0) continue;
      $target_url = get_permalink($target);
      if (!$target_url) continue;
      // Single replacement per phrase, only if not already linked.
      $pattern = '/(?<!<a[^>]*>)\b' . preg_quote($phrase, '/') . '\b(?![^<]*<\/a>)/u';
      $replacement = '<a href="' . esc_url($target_url) . '">' . esc_html($phrase) . '</a>';
      $new = preg_replace($pattern, $replacement, $content, 1, $count);
      if ($count > 0 && is_string($new)) {
        $content = $new;
        $applied += 1;
      }
    }
    wp_update_post(['ID' => $post_id, 'post_content' => $content], true);
    return [true, ['postId' => $post_id, 'linksApplied' => $applied], null];
  }

  // ─── Media ─────────────────────────────────────────────────────────────

  private static function exec_media_upload_from_url($payload) {
    $url = $payload['sourceUrl'] ?? '';
    if (!$url) return [false, null, 'sourceUrl required'];
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    $tmp = download_url(esc_url_raw($url), 60);
    if (is_wp_error($tmp)) return [false, null, $tmp->get_error_message()];

    $file_array = [
      'name'     => basename(wp_parse_url($url, PHP_URL_PATH) ?: 'visibility-upload'),
      'tmp_name' => $tmp,
    ];
    $attachment_id = media_handle_sideload(
      $file_array,
      0,
      isset($payload['title']) ? sanitize_text_field((string) $payload['title']) : null
    );
    if (file_exists($tmp)) {
      wp_delete_file($tmp);
    }
    if (is_wp_error($attachment_id)) return [false, null, $attachment_id->get_error_message()];

    if (!empty($payload['altText'])) {
      update_post_meta($attachment_id, '_wp_attachment_image_alt', sanitize_text_field((string) $payload['altText']));
    }
    if (!empty($payload['caption'])) {
      wp_update_post(['ID' => $attachment_id, 'post_excerpt' => sanitize_text_field((string) $payload['caption'])], true);
    }
    return [true, [
      'mediaId' => $attachment_id,
      'url' => wp_get_attachment_url($attachment_id),
    ], null];
  }

  private static function exec_media_update_meta($payload) {
    $id = intval($payload['mediaId'] ?? 0);
    if (!$id) return [false, null, 'mediaId required'];
    $fields = $payload['fields'] ?? [];
    if (array_key_exists('altText', $fields)) {
      update_post_meta($id, '_wp_attachment_image_alt', sanitize_text_field((string) $fields['altText']));
    }
    $update = ['ID' => $id];
    if (array_key_exists('caption', $fields))     $update['post_excerpt'] = sanitize_text_field((string) $fields['caption']);
    if (array_key_exists('title', $fields))       $update['post_title'] = sanitize_text_field((string) $fields['title']);
    if (array_key_exists('description', $fields)) $update['post_content'] = wp_kses_post((string) $fields['description']);
    if (count($update) > 1) {
      wp_update_post($update, true);
    }
    return [true, ['mediaId' => $id], null];
  }

  private static function exec_media_delete($payload) {
    $id = intval($payload['mediaId'] ?? 0);
    if (!$id) return [false, null, 'mediaId required'];
    $r = wp_delete_attachment($id, true);
    if (!$r) return [false, null, 'wp_delete_attachment failed'];
    return [true, ['mediaId' => $id, 'deleted' => true], null];
  }

  // ─── Taxonomy ──────────────────────────────────────────────────────────

  private static function exec_category_create($payload) {
    $name = $payload['name'] ?? '';
    if ($name === '') return [false, null, 'name required'];
    $args = [];
    if (!empty($payload['slug']))        $args['slug'] = sanitize_title((string) $payload['slug']);
    if (!empty($payload['description'])) $args['description'] = sanitize_text_field((string) $payload['description']);
    if (!empty($payload['parentId']))    $args['parent'] = intval($payload['parentId']);
    $term = wp_insert_term((string) $name, 'category', $args);
    if (is_wp_error($term)) return [false, null, $term->get_error_message()];
    return [true, ['categoryId' => intval($term['term_id'])], null];
  }

  private static function exec_category_update($payload) {
    $id = intval($payload['categoryId'] ?? 0);
    if (!$id) return [false, null, 'categoryId required'];
    $fields = $payload['fields'] ?? [];
    $args = [];
    if (array_key_exists('name', $fields))        $args['name'] = sanitize_text_field((string) $fields['name']);
    if (array_key_exists('slug', $fields))        $args['slug'] = sanitize_title((string) $fields['slug']);
    if (array_key_exists('description', $fields)) $args['description'] = sanitize_text_field((string) $fields['description']);
    if (array_key_exists('parentId', $fields))    $args['parent'] = $fields['parentId'] === null ? 0 : intval($fields['parentId']);
    $r = wp_update_term($id, 'category', $args);
    if (is_wp_error($r)) return [false, null, $r->get_error_message()];
    return [true, ['categoryId' => $id], null];
  }

  private static function exec_category_delete($payload) {
    $id = intval($payload['categoryId'] ?? 0);
    if (!$id) return [false, null, 'categoryId required'];
    $reassign = isset($payload['reassignToCategoryId']) ? intval($payload['reassignToCategoryId']) : null;
    $args = $reassign ? ['default' => $reassign, 'force_default' => true] : [];
    $r = wp_delete_term($id, 'category', $args);
    if (is_wp_error($r)) return [false, null, $r->get_error_message()];
    return [true, ['categoryId' => $id, 'deleted' => (bool) $r], null];
  }

  private static function exec_tag_create($payload) {
    $name = $payload['name'] ?? '';
    if ($name === '') return [false, null, 'name required'];
    $args = [];
    if (!empty($payload['slug']))        $args['slug'] = sanitize_title((string) $payload['slug']);
    if (!empty($payload['description'])) $args['description'] = sanitize_text_field((string) $payload['description']);
    $term = wp_insert_term((string) $name, 'post_tag', $args);
    if (is_wp_error($term)) return [false, null, $term->get_error_message()];
    return [true, ['tagId' => intval($term['term_id'])], null];
  }

  private static function exec_tag_update($payload) {
    $id = intval($payload['tagId'] ?? 0);
    if (!$id) return [false, null, 'tagId required'];
    $fields = $payload['fields'] ?? [];
    $args = [];
    if (array_key_exists('name', $fields))        $args['name'] = sanitize_text_field((string) $fields['name']);
    if (array_key_exists('slug', $fields))        $args['slug'] = sanitize_title((string) $fields['slug']);
    if (array_key_exists('description', $fields)) $args['description'] = sanitize_text_field((string) $fields['description']);
    $r = wp_update_term($id, 'post_tag', $args);
    if (is_wp_error($r)) return [false, null, $r->get_error_message()];
    return [true, ['tagId' => $id], null];
  }

  private static function exec_tag_delete($payload) {
    $id = intval($payload['tagId'] ?? 0);
    if (!$id) return [false, null, 'tagId required'];
    $r = wp_delete_term($id, 'post_tag');
    if (is_wp_error($r)) return [false, null, $r->get_error_message()];
    return [true, ['tagId' => $id, 'deleted' => (bool) $r], null];
  }

  // ─── SEO meta ──────────────────────────────────────────────────────────

  /** Detect which SEO plugin is active and route the field to its meta key. */
  private static function detect_seo_plugin() {
    if (defined('WPSEO_VERSION'))            return 'yoast';
    if (defined('RANK_MATH_VERSION'))        return 'rankmath';
    if (defined('AIOSEO_VERSION') || class_exists('AIOSEO\\Plugin\\AIOSEO')) return 'aioseo';
    return null;
  }

  private static function seo_meta_keys($plugin) {
    switch ($plugin) {
      case 'yoast':
        return [
          'metaTitle'       => '_yoast_wpseo_title',
          'metaDescription' => '_yoast_wpseo_metadesc',
          'focusKeyword'    => '_yoast_wpseo_focuskw',
          'canonical'       => '_yoast_wpseo_canonical',
          'ogTitle'         => '_yoast_wpseo_opengraph-title',
          'ogDescription'   => '_yoast_wpseo_opengraph-description',
          'ogImage'         => '_yoast_wpseo_opengraph-image',
          'robotsNoindex'   => '_yoast_wpseo_meta-robots-noindex',
          'robotsNofollow'  => '_yoast_wpseo_meta-robots-nofollow',
        ];
      case 'rankmath':
        return [
          'metaTitle'       => 'rank_math_title',
          'metaDescription' => 'rank_math_description',
          'focusKeyword'    => 'rank_math_focus_keyword',
          'canonical'       => 'rank_math_canonical_url',
          'ogTitle'         => 'rank_math_facebook_title',
          'ogDescription'   => 'rank_math_facebook_description',
          'ogImage'         => 'rank_math_facebook_image',
          'robotsNoindex'   => 'rank_math_robots',  // value is array; serialized below
          'robotsNofollow'  => 'rank_math_robots',
        ];
      case 'aioseo':
        // AIOSEO stores everything in its own custom table; we fall back to
        // standard postmeta keys that AIOSEO also writes on save_post.
        return [
          'metaTitle'       => '_aioseo_title',
          'metaDescription' => '_aioseo_description',
          'focusKeyword'    => '_aioseo_keyphrases',
          'canonical'       => '_aioseo_canonical_url',
        ];
      default:
        return [];
    }
  }

  private static function exec_seo_meta_update($payload) {
    $plugin = self::detect_seo_plugin();
    if (!$plugin) {
      return [false, null, 'No supported SEO plugin (Yoast/Rank Math/AIO SEO) is active. Install one to use SEO actions.'];
    }
    $post_id = intval($payload['postId'] ?? 0);
    if (!$post_id) return [false, null, 'postId required'];
    $fields = $payload['fields'] ?? [];
    $keys = self::seo_meta_keys($plugin);
    foreach ($fields as $key => $value) {
      if (!isset($keys[$key])) continue;
      $meta_key = $keys[$key];
      if ($key === 'schemaJsonLd') {
        update_post_meta($post_id, '_visibility_schema_jsonld', wp_json_encode($value));
        continue;
      }
      if ($key === 'robotsNoindex' || $key === 'robotsNofollow') {
        if ($plugin === 'yoast') {
          update_post_meta($post_id, $meta_key, $value ? '1' : '');
        } elseif ($plugin === 'rankmath') {
          $existing = (array) get_post_meta($post_id, $meta_key, true);
          $flag = $key === 'robotsNoindex' ? 'noindex' : 'nofollow';
          if ($value) {
            if (!in_array($flag, $existing, true)) $existing[] = $flag;
          } else {
            $existing = array_diff($existing, [$flag]);
          }
          update_post_meta($post_id, $meta_key, array_values($existing));
        }
        continue;
      }
      update_post_meta($post_id, $meta_key, is_scalar($value) ? sanitize_text_field((string) $value) : $value);
    }
    return [true, ['postId' => $post_id, 'seoPlugin' => $plugin], null];
  }

  // ─── Redirects ─────────────────────────────────────────────────────────

  private static function detect_redirect_plugin() {
    if (defined('REDIRECTION_VERSION'))            return 'redirection';
    if (defined('RANK_MATH_VERSION') && class_exists('RankMath\\Redirections\\DB')) return 'rankmath';
    if (defined('WPSEO_VERSION') && file_exists(WP_PLUGIN_DIR . '/wordpress-seo-premium/wpseo-premium.php')) return 'yoast_premium';
    return null;
  }

  private static function exec_redirect_create($payload) {
    $plugin = self::detect_redirect_plugin();
    if (!$plugin) {
      return [false, null, 'No redirect plugin installed (Redirection / Rank Math / Yoast Premium).'];
    }
    if ($plugin === 'rankmath') {
      // Rank Math: insert via its DB class.
      try {
        $id = \RankMath\Redirections\DB::add([
          'sources'      => [['pattern' => (string) $payload['fromPath'], 'comparison' => 'exact']],
          'url_to'       => (string) $payload['toPath'],
          'header_code'  => intval($payload['type'] ?? 301),
          'status'       => 'active',
        ]);
        return [true, ['redirectId' => (int) $id, 'plugin' => 'rankmath'], null];
      } catch (\Throwable $e) {
        return [false, null, $e->getMessage()];
      }
    }
    if ($plugin === 'redirection') {
      // Redirection plugin: hook into its REST surface via internal call.
      if (!class_exists('Red_Item')) {
        return [false, null, 'Redirection plugin classes not available.'];
      }
      $item = \Red_Item::create([
        'url'        => (string) $payload['fromPath'],
        'action_data'=> ['url' => (string) $payload['toPath']],
        'group_id'   => 1,
        'match_type' => 'url',
        'action_type'=> 'url',
        'action_code'=> intval($payload['type'] ?? 301),
      ]);
      if (is_wp_error($item)) return [false, null, $item->get_error_message()];
      return [true, ['redirectId' => is_object($item) ? $item->get_id() : null, 'plugin' => 'redirection'], null];
    }
    return [false, null, 'Yoast Premium redirect API not implemented in this build.'];
  }

  private static function exec_redirect_delete($payload) {
    $plugin = self::detect_redirect_plugin();
    if (!$plugin) {
      return [false, null, 'No redirect plugin installed.'];
    }
    $id = $payload['redirectId'] ?? null;
    if ($id === null) return [false, null, 'redirectId required'];
    if ($plugin === 'rankmath' && class_exists('RankMath\\Redirections\\DB')) {
      $deleted = \RankMath\Redirections\DB::delete([(int) $id]);
      return [true, ['redirectId' => $id, 'deleted' => (bool) $deleted], null];
    }
    if ($plugin === 'redirection' && class_exists('Red_Item')) {
      $item = \Red_Item::get_by_id((int) $id);
      if (!$item) return [false, null, 'redirect not found'];
      $item->delete();
      return [true, ['redirectId' => $id, 'deleted' => true], null];
    }
    return [false, null, 'Delete not implemented for ' . $plugin];
  }

  // ─── Helpers ───────────────────────────────────────────────────────────

  private static function post_result($post_id) {
    $post = get_post($post_id);
    return [
      'postId'      => (int) $post_id,
      'postUrl'     => get_permalink($post_id) ?: null,
      'postEditUrl' => get_edit_post_link($post_id, 'raw') ?: null,
      'postStatus'  => $post ? $post->post_status : null,
      'postTitle'   => $post ? $post->post_title : null,
      'postSlug'    => $post ? $post->post_name : null,
    ];
  }
}
