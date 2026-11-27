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
    if (!$refused) $refused = Visibility_Languages::check_action($action_type, $payload);
    if ($refused) {
      return self::fail($refused[0], $refused[1]);
    }
    $target = self::target_post($action_type, $payload);
    // Shops (#289): price, stock, tax, shipping and variation data are never written.
    if ($target && Visibility_WooCommerce::is_product($target) && isset($payload['fields']) && is_array($payload['fields'])) {
      $bad = Visibility_WooCommerce::commercial_key(array_keys($payload['fields']));
      if ($bad !== null) return self::fail('not_allowed', Visibility_WooCommerce::commercial_refusal($bad));
    }
    // One writer at a time per post (#568): check modifiedGmt and write under a lock.
    if ($target && !Visibility_Content::lock($target)) {
      return self::fail('conflict', 'Another change to this post is being saved right now. Read it again and retry.');
    }
    try {
      $before_gmt = $target ? Visibility_Content::modified_gmt($target) : null;
      if ($target) Visibility_Changes::begin($target);
      try {
        $res = self::{$method}($payload);
      } catch (\Throwable $e) {
        if ($target) Visibility_Changes::abort($target);
        Visibility_Redirects::$linked = [];
        return [false, null, $e->getMessage()];
      }
      if ($target && $res[0] && $before_gmt && Visibility_Changes::changed_since_begin($target)) Visibility_Content::advance_modified($target, $before_gmt);
      return self::after_write($action_type, $target, $res, $payload);
    } finally {
      if ($target) Visibility_Content::unlock($target);
    }
  }

  /** Actions that create a post (snapshot after, rollback = trash). */
  const CREATE_ACTIONS = ['post.create_draft', 'page.create_draft'];
  /** Actions that cannot be undone from a snapshot. */
  const NO_SNAPSHOT = ['post.delete_permanent', 'media.delete', 'media.upload_from_url', 'media.bulk_alt', 'change.rollback', 'redirect.create', 'redirect.delete', 'category.create', 'category.update', 'category.delete', 'tag.create', 'tag.update', 'tag.delete', 'term.update', 'term.create'];

  /** The post (or attachment) an action writes to, for the snapshot. */
  private static function target_post($action_type, $payload) {
    if (in_array($action_type, self::CREATE_ACTIONS, true) || in_array($action_type, self::NO_SNAPSHOT, true)) return 0;
    if ($action_type === 'seo_meta.update' && !empty($payload['termId'])) return 0;
    if (strpos($action_type, 'media.') === 0) return (int) ($payload['mediaId'] ?? 0);
    return (int) ($payload['postId'] ?? $payload['pageId'] ?? $payload['productId'] ?? 0);
  }

  /** Record the change (changeId) and run the after-write hooks on success. */
  private static function after_write($action_type, $target, $res, $payload = []) {
    list($ok, $result) = $res;
    $post_id = $target;
    if (in_array($action_type, self::CREATE_ACTIONS, true) && $ok && is_array($result)) $post_id = (int) ($result['postId'] ?? 0);
    if (!$ok) {
      if ($target) Visibility_Changes::abort($target);
      Visibility_Redirects::$linked = [];
      return $res;
    }
    if ($post_id && is_array($result)) {
      $change = Visibility_Changes::commit($post_id, $action_type, 'action', in_array($action_type, self::CREATE_ACTIONS, true));
      $result['changeId'] = $change;
      Visibility_Redirects::$linked = [];
      if (isset($result['modifiedGmt'])) $result['modifiedGmt'] = Visibility_Content::modified_gmt($post_id);
    }
    // Clear the changed page's cache so the live page shows the change.
    $purge = self::purge_target($action_type, $post_id, $payload ?? [], $result);
    if (is_array($result)) {
      if ($purge || !isset($result['cachePurged'])) $result['cachePurged'] = $purge ? Visibility_Cache::purge($purge) : [];
      $res[1] = $result;
    }
    return $res;
  }

  /** Which post's cache an action affects (0 = none). */
  private static function purge_target($action_type, $post_id, $payload, $result) {
    if ($action_type === 'media.bulk_alt') return 0; // purged per page by the action
    if (strpos($action_type, 'media.') === 0) {
      $media = (int) ($payload['mediaId'] ?? ($result['mediaId'] ?? 0));
      $parent = $media ? (int) wp_get_post_parent_id($media) : 0;
      if (!$parent && !empty($payload['postId'])) $parent = (int) $payload['postId'];
      return $parent;
    }
    if ($action_type === 'change.rollback') return 0; // purged by the rollback itself
    if ($action_type === 'post.delete_permanent') return 0;
    return (int) $post_id;
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
      'post.edit_parts'        => 'exec_post_edit_parts',
      'page.edit_parts'        => 'exec_post_edit_parts',
      'page.publish'           => 'exec_page_publish',
      'media.upload_from_url'  => 'exec_media_upload_from_url',
      'media.update_meta'      => 'exec_media_update_meta',
      'media.delete'           => 'exec_media_delete',
      'media.bulk_alt'         => 'exec_media_bulk_alt',
      'category.create'        => 'exec_category_create',
      'category.update'        => 'exec_category_update',
      'category.delete'        => 'exec_category_delete',
      'tag.create'             => 'exec_tag_create',
      'tag.update'             => 'exec_tag_update',
      'tag.delete'             => 'exec_tag_delete',
      'seo_meta.update'        => 'exec_seo_meta_update',
      'redirect.create'        => 'exec_redirect_create',
      'redirect.delete'        => 'exec_redirect_delete',
      'change.rollback'        => 'exec_change_rollback',
      'fields.update'          => 'exec_fields_update',
      'meta.update'            => 'exec_meta_update',
      'product.update'         => 'exec_product_update',
      'term.update'            => 'exec_term_update',
      'post.update_terms'      => 'exec_post_update_terms',
      'term.create'            => 'exec_term_create',
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
      'post_author'   => Visibility_Author::id(),
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
    Visibility_Policy::mark_created($post_id);
    if (!empty($payload['language']) || !empty($payload['translationOf'])) {
      $lang = Visibility_Languages::assign($post_id, $payload['language'] ?? '', (int) ($payload['translationOf'] ?? 0));
      if (is_wp_error($lang)) {
        wp_delete_post($post_id, true);
        return self::fail($lang->get_error_code(), $lang->get_error_message());
      }
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
    $extra = self::page_attributes($fields);
    if (is_wp_error($extra)) return self::fail('invalid', $extra->get_error_message());
    $args += $extra;
    $old_slug = $post->post_name;
    $guard = self::slug_guard($post, $args);
    if ($guard) return self::fail('unsupported', $guard);
    $old_url = get_permalink($post);
    $result = Visibility_Content::save($args);
    if (is_wp_error($result)) return [false, null, $result->get_error_message()];
    if (array_key_exists('featuredMediaId', $fields)) self::set_featured($post_id, $fields['featuredMediaId']);
    if (array_key_exists('template', $fields)) update_post_meta($post_id, '_wp_page_template', $fields['template'] === '' || $fields['template'] === null ? 'default' : sanitize_text_field((string) $fields['template']));
    $out = self::post_result($post_id);
    $out += self::slug_note($post, $old_slug, $old_url);
    return [true, $out, null];
  }

  /** menuOrder / parent / template from an update payload (validated). */
  private static function page_attributes($fields) {
    $args = [];
    if (array_key_exists('menuOrder', $fields)) $args['menu_order'] = (int) $fields['menuOrder'];
    if (array_key_exists('parent', $fields)) {
      $parent = $fields['parent'] === null ? 0 : (int) $fields['parent'];
      if ($parent > 0 && !get_post($parent)) return new WP_Error('invalid', 'parent not found');
      $args['post_parent'] = $parent;
    }
    if (array_key_exists('template', $fields) && $fields['template'] !== '' && $fields['template'] !== null) {
      $tpl = sanitize_text_field((string) $fields['template']);
      $available = array_keys(wp_get_theme()->get_page_templates(null, 'page'));
      if (function_exists('wp_is_block_theme') && wp_is_block_theme() && function_exists('get_block_templates')) {
        foreach (get_block_templates([], 'wp_template') as $t) $available[] = $t->slug;
      }
      if (!in_array($tpl, $available, true)) return new WP_Error('invalid', sprintf('template "%s" is not available in this theme', $tpl));
    }
    return $args;
  }

  private static function set_featured($post_id, $media_id) {
    if ($media_id === null || (int) $media_id <= 0) delete_post_thumbnail($post_id);
    else set_post_thumbnail($post_id, (int) $media_id);
  }

  /**
   * A published post whose address changed: a 301 from the old address to the
   * new one is added in the redirect plugin (or the plugin's own store) and
   * reported as redirectAdded / redirectSkipped (#292). Posts also keep
   * WordPress's own old-slug redirect.
   */
  public static function slug_note($before, $old_slug, $old_url) {
    $now = get_post($before->ID);
    if (!$now || $old_slug === '' || $now->post_name === $old_slug) return [];
    if ($before->post_status !== 'publish') {
      return ['redirectSkipped' => 'The page was not published, so its old address had no visitors to redirect.'];
    }
    $note = ['from' => $old_slug, 'to' => $now->post_name, 'redirect' => null];
    $auto = Visibility_Redirects::auto($old_url, get_permalink($now));
    if (isset($auto['redirectAdded'])) {
      $note['redirect'] = $auto['redirectAdded']['provider'];
    } elseif (!is_post_type_hierarchical($now->post_type) && in_array($old_slug, (array) get_post_meta($before->ID, '_wp_old_slug'), true)) {
      $note['redirect'] = 'wordpress';
    }
    if (isset($auto['redirectSkipped']) && $note['redirect'] === null) $note['redirectError'] = $auto['redirectSkipped'];
    return ['slugChanged' => $note] + $auto;
  }

  /**
   * Kept for 0.10.0 callers. Since 2.0.0 the plugin keeps its own redirects
   * when no redirect plugin is installed, so a slug change is never refused.
   * @return string|null why it is refused
   */
  public static function slug_guard($post, $args) {
    return null;
  }

  /**
   * Change only the targeted parts of a post (#285).
   * Payload: {postId, modifiedGmt?, edits:[{target:{path}, op, text?, find?, html?, attrs?}]}.
   */
  private static function exec_post_edit_parts($payload) {
    $post_id = intval($payload['postId'] ?? $payload['pageId'] ?? 0);
    if (!$post_id) return self::fail('invalid', 'postId required');
    $post = get_post($post_id);
    if (!$post) return self::fail('not_found', 'post not found');
    if (Visibility_Content::is_stale($post_id, $payload['modifiedGmt'] ?? null)) {
      return self::fail('conflict', 'The post was changed after it was read. Read it again and retry.');
    }
    $edits = isset($payload['edits']) && is_array($payload['edits']) ? array_values($payload['edits']) : [];
    if (empty($edits)) return self::fail('invalid', 'edits required');
    $builder = Visibility_Read::builder_of($post);
    if ($builder) {
      if (empty($builder['writable'])) {
        return self::fail('unsupported', !empty($builder['readable'])
          ? sprintf('This page is built with %s, which Visibility can only read. Change its SEO, custom fields or image alt text instead.', $builder['name'])
          : sprintf('This page is built with %s, which Visibility cannot edit yet. Change its SEO, custom fields or image alt text instead.', $builder['name']));
      }
      list($ok, $res, $err) = Visibility_Builders::edit($post, $builder['slug'], $edits);
      if (!$ok) return is_wp_error($err) ? self::fail($err->get_error_code(), $err->get_error_message()) : self::fail('unsupported', 'builder not available');
      $out = self::post_result($post_id);
      $out['builder'] = $builder['slug'];
      return [true, $out + $res, null];
    }
    $r = Visibility_Editor::apply($post->post_content, $edits);
    if (is_wp_error($r)) return self::fail($r->get_error_code(), $r->get_error_message());
    if ($r['content'] !== $post->post_content) {
      $saved = Visibility_Content::save(['ID' => $post_id, 'post_content' => $r['content']]);
      if (is_wp_error($saved)) return self::fail('save_failed', $saved->get_error_message());
    }
    $out = self::post_result($post_id);
    $out['diff'] = $r['diff'];
    return [true, $out, null];
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

  /** {items:[{mediaId, altText, title?, caption?}]} (#297) */
  private static function exec_media_bulk_alt($payload) {
    $r = Visibility_Media::bulk_alt(isset($payload['items']) ? $payload['items'] : null);
    if (is_wp_error($r)) return self::fail($r->get_error_code(), $r->get_error_message());
    if (empty($r['updated'])) {
      self::$last_error_code = 'not_applied';
      return [false, $r, 'No image was updated: ' . implode('; ', array_map(function ($f) { return $f['mediaId'] . ' (' . $f['reason'] . ')'; }, $r['failed']))];
    }
    return [true, $r, null];
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
    // "primary, second" in focusKeyword -> primary + secondaryKeywords, where the adapter keeps a keyword list.
    if (method_exists($adapter, 'normalize')) $fields = $adapter->normalize($fields);

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
    if ($adapter instanceof Visibility_SEO_Adapter_Custom) $result['renderedBy'] = $adapter->rendered_by();
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
    if ($field === 'secondaryKeywords') {
      $want = array_values(array_filter(array_map(function ($k) { return sanitize_text_field(str_replace(',', ' ', (string) $k)); }, is_array($asked) ? $asked : explode(',', (string) $asked)), 'strlen'));
      return $want === array_values((array) $stored);
    }
    $want = in_array($field, ['canonical', 'ogImage', 'twitterImage'], true)
      ? (($asked === null || $asked === '') ? '' : esc_url_raw((string) $asked))
      : ($asked === null ? '' : sanitize_text_field((string) $asked));
    return $want === (string) $stored;
  }

  // ─── Redirects (#292) ──────────────────────────────────────────────────

  private static function exec_redirect_create($payload) {
    $r = Visibility_Redirects::action_create($payload);
    if (is_wp_error($r)) return self::fail($r->get_error_code(), $r->get_error_message());
    return [true, $r, null];
  }

  private static function exec_redirect_delete($payload) {
    $r = Visibility_Redirects::action_delete($payload);
    if (is_wp_error($r)) return self::fail($r->get_error_code(), $r->get_error_message());
    return [true, $r, null];
  }

  // ─── Custom fields (#288) ──────────────────────────────────────────────

  /** {postId, fields:{key: value}, modifiedGmt?}: only allowlisted keys, validated by type. */
  private static function exec_fields_update($payload) {
    $post_id = intval($payload['postId'] ?? 0);
    if (!$post_id) return self::fail('invalid', 'postId required');
    if (!get_post($post_id)) return self::fail('not_found', 'post not found');
    if (Visibility_Content::is_stale($post_id, $payload['modifiedGmt'] ?? null)) {
      return self::fail('conflict', 'The post was changed after it was read. Read it again and retry.');
    }
    $values = isset($payload['fields']) && is_array($payload['fields']) ? $payload['fields'] : [];
    $r = Visibility_Fields::update($post_id, $values);
    if (is_wp_error($r)) return self::fail($r->get_error_code(), $r->get_error_message());
    return [true, ['postId' => $post_id, 'fields' => $r['fields'], 'before' => (object) $r['before'], 'after' => (object) $r['after'], 'modifiedGmt' => Visibility_Content::modified_gmt($post_id)], null];
  }

  /**
   * {postId, meta:{key: value}, modifiedGmt?} (2.1.0): any post meta the
   * owner allows (fieldAllowlist "meta:<key>", or "any post meta"), never
   * secrets, SEO plugins' or builders' keys; value checked against the
   * registered type; null deletes the key. All or nothing.
   */
  private static function exec_meta_update($payload) {
    $post_id = intval($payload['postId'] ?? 0);
    if (!$post_id) return self::fail('invalid', 'postId required');
    if (!get_post($post_id)) return self::fail('not_found', 'post not found');
    if (Visibility_Content::is_stale($post_id, $payload['modifiedGmt'] ?? null)) {
      return self::fail('conflict', 'The post was changed after it was read. Read it again and retry.');
    }
    $values = isset($payload['meta']) && is_array($payload['meta']) ? $payload['meta'] : [];
    $r = Visibility_Meta::update($post_id, $values);
    if (is_wp_error($r)) return self::fail($r->get_error_code(), $r->get_error_message());
    return [true, ['postId' => $post_id, 'meta' => $r['meta'], 'before' => (object) $r['before'], 'after' => (object) $r['after'], 'modifiedGmt' => Visibility_Content::modified_gmt($post_id)], null];
  }

  // ─── WooCommerce (#289) ────────────────────────────────────────────────

  /** {productId|postId, fields:{name?, description?, shortDescription?, slug?}, modifiedGmt?}: never price or stock. */
  private static function exec_product_update($payload) {
    $post_id = intval($payload['productId'] ?? $payload['postId'] ?? 0);
    if (!$post_id) return self::fail('invalid', 'productId required');
    if (!Visibility_WooCommerce::is_product($post_id)) return self::fail('not_found', 'product not found');
    if (Visibility_Content::is_stale($post_id, $payload['modifiedGmt'] ?? null)) {
      return self::fail('conflict', 'The product was changed after it was read. Read it again and retry.');
    }
    $fields = isset($payload['fields']) && is_array($payload['fields']) ? $payload['fields'] : [];
    $post = get_post($post_id);
    $old_slug = $post->post_name;
    $old_url = get_permalink($post);
    $r = Visibility_WooCommerce::update_product($post_id, $fields);
    if (is_wp_error($r)) return self::fail($r->get_error_code(), $r->get_error_message());
    $out = self::post_result($post_id) + ['fields' => $r['fields'], 'before' => (object) $r['before'], 'after' => (object) $r['after']];
    $out += self::slug_note($post, $old_slug, $old_url);
    return [true, $out, null];
  }

  /** {termId, taxonomy, fields:{name?, slug?, description?}}: product categories, tags and blog categories. */
  private static function exec_term_update($payload) {
    $term_id = intval($payload['termId'] ?? 0);
    if (!$term_id) return self::fail('invalid', 'termId required');
    $fields = isset($payload['fields']) && is_array($payload['fields']) ? $payload['fields'] : [];
    $old = get_term($term_id);
    $old_link = ($old && !is_wp_error($old)) ? get_term_link($old) : '';
    $r = Visibility_WooCommerce::update_term($term_id, $payload['taxonomy'] ?? '', $fields);
    if (is_wp_error($r)) return self::fail($r->get_error_code(), $r->get_error_message());
    if (is_string($old_link) && $old_link !== '' && is_string($r['link']) && $old->slug !== get_term($term_id)->slug) {
      $r += Visibility_Redirects::auto($old_link, $r['link']);
      // A term has no change snapshot, so its redirect is reported but not linked to a rollback.
      Visibility_Redirects::$linked = [];
    }
    return [true, $r, null];
  }

  /** Taxonomies agents may assign and create terms in (#470). */
  const ASSIGN_TAXONOMIES = ['category', 'post_tag', 'product_cat', 'product_tag'];

  private static function term_taxonomy($payload, $post_type = null) {
    $tax = sanitize_key((string) ($payload['taxonomy'] ?? ''));
    if (!in_array($tax, self::ASSIGN_TAXONOMIES, true) || !taxonomy_exists($tax)) {
      return new WP_Error('invalid', sprintf('taxonomy must be one of: %s', implode(', ', array_filter(self::ASSIGN_TAXONOMIES, 'taxonomy_exists'))));
    }
    if ($post_type !== null && !is_object_in_taxonomy($post_type, $tax)) {
      return new WP_Error('invalid', sprintf('A %s has no %s.', $post_type, $tax));
    }
    return $tax;
  }

  /**
   * post.update_terms {postId, taxonomy, add?:[ids], remove?:[ids], set?:[ids]} (#470):
   * add or remove categories / tags on a post, page or product. Only existing
   * terms; the post's change snapshot covers its terms, so it rolls back.
   */
  private static function exec_post_update_terms($payload) {
    $post_id = (int) ($payload['postId'] ?? 0);
    $post = $post_id ? get_post($post_id) : null;
    if (!$post) return self::fail('not_found', 'post not found');
    if (Visibility_Content::is_stale($post_id, $payload['modifiedGmt'] ?? null)) {
      return self::fail('conflict', 'The post was changed after it was read. Read it again and retry.');
    }
    $tax = self::term_taxonomy($payload, $post->post_type);
    if (is_wp_error($tax)) return self::fail('invalid', $tax->get_error_message());
    $ids = function ($k) use ($payload) { return isset($payload[$k]) && is_array($payload[$k]) ? array_values(array_unique(array_filter(array_map('intval', $payload[$k])))) : null; };
    $add = $ids('add'); $remove = $ids('remove'); $set = $ids('set');
    if ($add === null && $remove === null && $set === null) return self::fail('invalid', 'Give add, remove or set (term ids).');
    $missing = [];
    foreach (array_merge((array) $add, (array) $set) as $t) {
      $term = get_term($t, $tax);
      if (!$term || is_wp_error($term)) $missing[] = $t;
    }
    if ($missing) return self::fail('not_found', sprintf('No %s with id %s on this site.', $tax, implode(', ', $missing)));
    $before = wp_get_object_terms($post_id, $tax, ['fields' => 'ids']);
    $before = is_wp_error($before) ? [] : array_map('intval', $before);
    $next = $set !== null ? $set : $before;
    if ($add) $next = array_values(array_unique(array_merge($next, $add)));
    if ($remove) $next = array_values(array_diff($next, $remove));
    $r = wp_set_object_terms($post_id, $next, $tax, false);
    if (is_wp_error($r)) return self::fail('invalid', $r->get_error_message());
    clean_post_cache($post_id);
    $names = function ($list) use ($tax) { return array_values(array_map(function ($id) use ($tax) { $t = get_term($id, $tax); return ['id' => (int) $id, 'name' => ($t && !is_wp_error($t)) ? $t->name : '#' . $id]; }, $list)); };
    $after = wp_get_object_terms($post_id, $tax, ['fields' => 'ids']);
    $after = is_wp_error($after) ? [] : array_map('intval', $after);
    return [true, self::post_result($post_id) + ['taxonomy' => $tax, 'before' => $names($before), 'after' => $names($after)], null];
  }

  /**
   * term.create {taxonomy, name, slug?, description?, parentId?} (#470): a new
   * category or tag. Recorded so it can be undone (deleted while nothing uses it).
   */
  private static function exec_term_create($payload) {
    $tax = self::term_taxonomy($payload);
    if (is_wp_error($tax)) return self::fail('invalid', $tax->get_error_message());
    $name = trim(sanitize_text_field((string) ($payload['name'] ?? '')));
    if ($name === '') return self::fail('invalid', 'name required');
    $args = [];
    if (!empty($payload['slug']))        $args['slug'] = sanitize_title((string) $payload['slug']);
    if (!empty($payload['description'])) $args['description'] = sanitize_textarea_field((string) $payload['description']);
    if (!empty($payload['parentId'])) {
      if (!is_taxonomy_hierarchical($tax)) return self::fail('invalid', sprintf('%s has no parents.', $tax));
      $parent = get_term((int) $payload['parentId'], $tax);
      if (!$parent || is_wp_error($parent)) return self::fail('not_found', 'parent not found');
      $args['parent'] = (int) $payload['parentId'];
    }
    $existing = term_exists($name, $tax, isset($args['parent']) ? $args['parent'] : null);
    if ($existing) return self::fail('exists', sprintf('A %s named "%s" already exists (id %d). Use it instead.', $tax === 'post_tag' ? 'tag' : 'category', $name, (int) $existing['term_id']));
    $term = wp_insert_term($name, $tax, $args);
    if (is_wp_error($term)) return self::fail('invalid', $term->get_error_message());
    $id = (int) $term['term_id'];
    $t = get_term($id, $tax);
    $change = Visibility_Changes::record_custom('term.create', ['term:' . $id], [], ['taxonomy' => $tax, 'termId' => $id]);
    return [true, ['termId' => $id, 'taxonomy' => $tax, 'name' => $t->name, 'slug' => $t->slug, 'link' => get_term_link($t), 'changeId' => $change], null];
  }

  /** Undo term.create: delete the term while no post uses it. */
  public static function rollback_custom($result, $row, $states) {
    if ($row->action !== 'term.create') return;
    list(, $after) = $states;
    $id = (int) ($after['termId'] ?? 0); $tax = (string) ($after['taxonomy'] ?? '');
    $t = $id ? get_term($id, $tax) : null;
    if (!$t || is_wp_error($t)) { $result->data = ['termId' => $id, 'deleted' => false]; return; }
    if ((int) $t->count > 0) {
      $result->error = new WP_Error('conflict', sprintf('"%s" is used by %d post(s) now, so it was not deleted.', $t->name, (int) $t->count), ['changedSince' => ['term:' . $id]]);
      return;
    }
    wp_delete_term($id, $tax);
    $result->data = ['termId' => $id, 'deleted' => true];
  }

  // ─── Rollback ──────────────────────────────────────────────────────────

  private static function exec_change_rollback($payload) {
    $id = (int) ($payload['changeId'] ?? 0);
    if (!$id) return self::fail('invalid', 'changeId required');
    $r = Visibility_Changes::rollback($id, 'action');
    if (is_wp_error($r)) {
      $code = $r->get_error_code();
      self::$last_error_code = $code;
      $data = $r->get_error_data();
      return [false, is_array($data) ? $data : null, $r->get_error_message()];
    }
    return [true, $r + ['ok' => true], null];
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
    ] + (Visibility_Languages::active() ? ['language' => Visibility_Languages::of($post_id), 'translations' => Visibility_Languages::translations($post_id)] : []);
  }
}
