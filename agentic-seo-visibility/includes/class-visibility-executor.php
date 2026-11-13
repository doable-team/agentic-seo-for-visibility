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

  /** Machine-readable reason for the last failure (conflict, unsupported, …). */
  private static $last_error_code = null;

  public static function last_error_code() {
    return self::$last_error_code;
  }

  /** Fail with a code the app can branch on, plus a plain message. */
  private static function fail($code, $message) {
    self::$last_error_code = $code;
    return [false, null, $message];
  }

  public static function execute($action_type, $payload) {
    self::$last_error_code = null;
    if (!is_array($payload)) {
      return [false, null, 'Payload is not an object'];
    }
    $method = self::method_for($action_type);
    if ($method === null) {
      return self::fail('unsupported', sprintf('Unsupported action type: %s', $action_type));
    }
    // The owner's switches are checked here, not only on the server.
    $refused = Visibility_Policy::check_action($action_type, $payload);
    if ($refused) {
      return self::fail($refused[0], $refused[1]);
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
      'post_title'    => isset($payload['title']) ? wp_kses_post((string) $payload['title']) : '',
      'post_content'  => isset($payload['content']) ? wp_kses_post((string) $payload['content']) : '',
    ];
    if (!empty($payload['excerpt'])) {
      $args['post_excerpt'] = wp_kses_post((string) $payload['excerpt']);
    }
    if (!empty($payload['slug'])) {
      $args['post_name'] = sanitize_title($payload['slug']);
    }
    // Sanitised above; save() keeps WordPress from running kses a second time
    // and from unslashing block attributes.
    $post_id = Visibility_Content::save($args);
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
    $post = get_post($post_id);
    if (!$post) return self::fail('not_found', 'post not found');
    if (Visibility_Content::is_stale($post_id, $payload['modifiedGmt'] ?? null)) {
      return self::fail('conflict', 'The post was changed after it was read. Read it again and retry.');
    }
    $fields = $payload['fields'] ?? [];
    $args = ['ID' => $post_id];
    // Only markup the agent added is sanitised. Re-filtering the whole page
    // used to strip iframes, forms and scripts the owner had on it.
    if (array_key_exists('title', $fields))   $args['post_title'] = Visibility_Content::sanitize_update((string) $fields['title'], $post->post_title);
    if (array_key_exists('content', $fields)) $args['post_content'] = Visibility_Content::sanitize_update((string) $fields['content'], $post->post_content);
    if (array_key_exists('excerpt', $fields)) $args['post_excerpt'] = Visibility_Content::sanitize_update((string) $fields['excerpt'], $post->post_excerpt);
    if (array_key_exists('slug', $fields))    $args['post_name'] = sanitize_title((string) $fields['slug']);
    $result = Visibility_Content::save($args);
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
    if (!$post_id) return self::fail('invalid', 'postId required');
    $suggestions = isset($payload['suggestions']) && is_array($payload['suggestions']) ? $payload['suggestions'] : [];
    if (empty($suggestions)) return self::fail('invalid', 'suggestions required');

    $post = get_post($post_id);
    if (!$post) return self::fail('not_found', 'post not found');
    if (Visibility_Content::is_stale($post_id, $payload['modifiedGmt'] ?? null)) {
      return self::fail('conflict', 'The post was changed after it was read. Read it again and retry.');
    }
    $content = $post->post_content;
    $applied = [];
    $not_found = [];

    foreach ($suggestions as $sug) {
      $phrase = isset($sug['phrase']) ? (string) $sug['phrase'] : '';
      $target = isset($sug['targetPostId']) ? intval($sug['targetPostId']) : 0;
      if ($phrase === '' || $target <= 0 || $target === $post_id) {
        $not_found[] = ['phrase' => $phrase, 'targetPostId' => $target, 'reason' => 'invalid suggestion'];
        continue;
      }
      $target_url = get_permalink($target);
      if (!$target_url || get_post_status($target) !== 'publish') {
        $not_found[] = ['phrase' => $phrase, 'targetPostId' => $target, 'reason' => 'target is not a published post'];
        continue;
      }
      if (Visibility_Links::links_to($content, $target_url)) {
        $not_found[] = ['phrase' => $phrase, 'targetPostId' => $target, 'reason' => 'already linked'];
        continue;
      }
      // Walks text nodes only: never inside a link, heading, code or block comment.
      list($new, $done) = Visibility_Links::link_phrase($content, $phrase, $target_url);
      if (!$done) {
        $not_found[] = ['phrase' => $phrase, 'targetPostId' => $target, 'reason' => 'phrase not found in linkable text'];
        continue;
      }
      $content = $new;
      $applied[] = ['phrase' => $phrase, 'targetPostId' => $target, 'url' => $target_url];
    }
    if (empty($applied)) {
      // Nothing changed: say so, and don't create an empty revision.
      self::$last_error_code = 'not_applied';
      return [false, ['postId' => $post_id, 'linksApplied' => 0, 'applied' => [], 'notFound' => $not_found], 'No link could be added: ' . implode('; ', array_map(function ($n) { return '"' . $n['phrase'] . '" (' . $n['reason'] . ')'; }, $not_found))];
    }
    $saved = Visibility_Content::save(['ID' => $post_id, 'post_content' => $content]);
    if (is_wp_error($saved)) return self::fail('save_failed', $saved->get_error_message());
    return [true, [
      'postId'       => $post_id,
      'linksApplied' => count($applied),
      'applied'      => $applied,
      'notFound'     => $not_found,
      'modifiedGmt'  => Visibility_Content::modified_gmt($post_id),
    ], null];
  }

  // ─── Media ─────────────────────────────────────────────────────────────

  private static function exec_media_upload_from_url($payload) {
    $args = ['source_url' => isset($payload['sourceUrl']) ? (string) $payload['sourceUrl'] : ''];
    if (!empty($payload['postId'])) $args['post_id'] = (int) $payload['postId'];
    foreach (['title' => 'title', 'caption' => 'caption', 'description' => 'description', 'filename' => 'filename', 'altText' => 'alt'] as $in => $out) {
      if (isset($payload[$in]) && is_scalar($payload[$in])) $args[$out] = (string) $payload[$in];
    }
    $res = Visibility_Media::upload($args);
    if (is_wp_error($res)) return self::fail('invalid', $res->get_error_message());
    return [true, $res + ['mediaId' => $res['id']], null];
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

  /**
   * Write SEO fields through the active SEO plugin's adapter (Visibility_SEO).
   *
   * Payload: {postId, fields, modifiedGmt?} or {termId, taxonomy, fields}.
   * Result: written fields, unsupportedFields (never silently skipped), the
   * stored values before and after, and what the page renders afterwards.
   * Fails when nothing could be written or a value did not stick.
   */
  private static function exec_seo_meta_update($payload) {
    $fields = isset($payload['fields']) && is_array($payload['fields']) ? $payload['fields'] : [];
    if (empty($fields)) return self::fail('invalid', 'fields required');
    if (!empty($payload['termId'])) {
      return self::seo_meta_update_term($payload, $fields);
    }
    $post_id = intval($payload['postId'] ?? 0);
    if (!$post_id) return self::fail('invalid', 'postId required');
    if (!get_post($post_id)) return self::fail('not_found', 'post not found');
    if (Visibility_Content::is_stale($post_id, $payload['modifiedGmt'] ?? null)) {
      return self::fail('conflict', 'The post was changed after it was read. Read it again and retry.');
    }

    $adapter = Visibility_SEO::adapter();
    if (!$adapter->writable()) {
      return self::fail('unsupported', sprintf('%s is active on this site; Visibility cannot write its fields yet.', $adapter->name()));
    }
    $before = Visibility_SEO::read_post($post_id, false)['values'];

    $res = $adapter->write($post_id, $fields);
    if (is_wp_error($res)) return self::fail('save_failed', $res->get_error_message());
    $written = $res['written'];
    if (array_key_exists('schemaJsonLd', $fields)) {
      Visibility_Schema::store($post_id, $fields['schemaJsonLd']);
      $written[] = 'schemaJsonLd';
    }
    $unsupported = array_values(array_unique(array_diff($res['unsupported'], ['schemaJsonLd'])));
    $notes = isset($res['notes']) ? $res['notes'] : [];
    if (empty($written)) {
      return self::fail('unsupported', sprintf('%s cannot take these fields: %s.', $adapter->name(), implode(', ', $unsupported)));
    }

    $read = Visibility_SEO::read_post($post_id);
    $after = $read['values'];
    // Read back: every written field must now hold what was asked.
    foreach ($written as $f) {
      if (!self::seo_value_matches($f, $fields[$f], $after[$f] ?? null)) {
        return self::fail('not_applied', sprintf('%s did not keep %s (asked %s, stored %s).', $adapter->name(), $f, wp_json_encode($fields[$f]), wp_json_encode($after[$f] ?? null)));
      }
    }
    $result = [
      'postId'            => $post_id,
      'seoPlugin'         => $adapter->slug(),
      'fields'            => $written,
      'unsupportedFields' => $unsupported,
      'before'            => array_intersect_key($before, array_flip($written)),
      'after'             => array_intersect_key($after, array_flip($written)),
      'rendered'          => $read['rendered'],
    ];
    if ($notes) $result['notes'] = $notes;
    if ($adapter->slug() === null) $result['renderedBy'] = 'visibility';
    return [true, $result, null];
  }

  private static function seo_meta_update_term($payload, $fields) {
    $term_id  = intval($payload['termId']);
    $taxonomy = isset($payload['taxonomy']) ? sanitize_key((string) $payload['taxonomy']) : '';
    $term = $taxonomy !== '' ? get_term($term_id, $taxonomy) : get_term($term_id);
    if (!$term || is_wp_error($term)) return self::fail('not_found', 'term not found');
    $taxonomy = $term->taxonomy;
    $adapter = Visibility_SEO::adapter();
    if (!$adapter->writable() || empty($adapter->term_fields())) {
      return self::fail('unsupported', sprintf('Category and tag SEO is not supported for %s.', $adapter->name()));
    }
    $before = $adapter->read_term($term_id, $taxonomy);
    $res = $adapter->write_term($term_id, $taxonomy, $fields);
    if (is_wp_error($res)) return self::fail('save_failed', $res->get_error_message());
    if (empty($res['written'])) {
      return self::fail('unsupported', sprintf('%s cannot take these fields for terms: %s.', $adapter->name(), implode(', ', $res['unsupported'])));
    }
    $after = $adapter->read_term($term_id, $taxonomy);
    foreach ($res['written'] as $f) {
      if (!self::seo_value_matches($f, $fields[$f], $after[$f] ?? null)) {
        return self::fail('not_applied', sprintf('%s did not keep %s for the term.', $adapter->name(), $f));
      }
    }
    return [true, [
      'termId'            => $term_id,
      'taxonomy'          => $taxonomy,
      'seoPlugin'         => $adapter->slug(),
      'fields'            => $res['written'],
      'unsupportedFields' => array_values($res['unsupported']),
      'before'            => array_intersect_key($before, array_flip($res['written'])),
      'after'             => array_intersect_key($after, array_flip($res['written'])),
    ], null];
  }

  /** Compare an asked value with the stored one, after the same sanitising. */
  private static function seo_value_matches($field, $asked, $stored) {
    if (in_array($field, ['robotsNoindex', 'robotsNofollow', 'cornerstone'], true)) return (bool) $asked === (bool) $stored;
    if ($field === 'primaryCategory') return (int) $asked === (int) $stored;
    if ($field === 'schemaJsonLd' || $field === 'advancedRobots') return true;
    $want = in_array($field, ['canonical', 'ogImage', 'twitterImage'], true)
      ? (($asked === null || $asked === '') ? '' : esc_url_raw((string) $asked))
      : ($asked === null ? '' : sanitize_text_field((string) $asked));
    return $want === (string) $stored;
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
      'postType'    => $post ? $post->post_type : null,
      'modifiedGmt' => $post ? Visibility_Content::modified_gmt($post) : null,
    ];
  }
}
