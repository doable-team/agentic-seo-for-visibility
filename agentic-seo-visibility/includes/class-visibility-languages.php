<?php
/**
 * Multilingual sites (#290 shared part, #334 Polylang).
 *
 * - Every post reports its language and its translation group
 *   ({lang: post id}).
 * - Edits on a multilingual site must name the language of the post they
 *   change: missing → language_required, different → language_mismatch.
 *   That keeps an agent from editing the wrong language by mistake.
 * - Editing a post only ever changes that post; Polylang's own sync of
 *   taxonomies/meta between translations is left as the owner configured it.
 * - A new draft can be created in a language and linked to its source post as
 *   its translation.
 */

if (!defined('ABSPATH')) {
  exit;
}

class Visibility_Languages {

  /** Executor actions that change an existing post and so need its language. */
  const EDIT_ACTIONS = [
    'post.update_content', 'page.update_content', 'post.edit_parts', 'page.edit_parts',
    'seo_meta.update', 'fields.update', 'post.update_meta', 'internal_link.suggest',
    'post.publish', 'page.publish', 'post.schedule', 'post.unpublish', 'post.trash',
  ];

  public static function active() {
    return function_exists('pll_get_post_language') && function_exists('pll_languages_list') && count((array) pll_languages_list()) > 0;
  }

  public static function languages() {
    return self::active() ? array_values((array) pll_languages_list()) : [];
  }

  /** Language slug of a post, or null. */
  public static function of($post_id) {
    if (!self::active()) return null;
    $l = pll_get_post_language((int) $post_id);
    return $l ? (string) $l : null;
  }

  /** {lang: post id} for the post's translation group (itself included). */
  public static function translations($post_id) {
    if (!self::active() || !function_exists('pll_get_post_translations')) return null;
    $t = pll_get_post_translations((int) $post_id);
    return (object) array_map('intval', (array) $t);
  }

  /** Whether Polylang manages this post type. */
  public static function translated_type($type) {
    return self::active() && function_exists('pll_is_translated_post_type') && pll_is_translated_post_type($type);
  }

  /**
   * Why an edit may not run on this post, or null.
   * @return array|null [code, message]
   */
  public static function check_edit($post_id, $language) {
    if (!self::active()) return null;
    $post = get_post((int) $post_id);
    if (!$post || !self::translated_type($post->post_type)) return null;
    $has = self::of($post->ID);
    if ($has === null) return null; // no language assigned: nothing to mix up
    $language = is_string($language) ? sanitize_key($language) : '';
    if ($language === '') {
      return ['language_required', sprintf('This site has more than one language. Say which language you are editing ("language": "%s" for this post).', $has)];
    }
    if ($language !== $has) {
      $t = (array) self::translations($post->ID);
      $hint = isset($t[$language]) ? sprintf(' The %s version is post %d.', $language, $t[$language]) : '';
      return ['language_mismatch', sprintf('Post %d is in %s, not %s.%s', $post->ID, $has, $language, $hint)];
    }
    return null;
  }

  /** Same for executor actions. */
  public static function check_action($action_type, $payload) {
    if (!self::active() || !in_array($action_type, self::EDIT_ACTIONS, true)) return null;
    if ($action_type === 'seo_meta.update' && !empty($payload['termId'])) return null;
    $post_id = (int) ($payload['postId'] ?? $payload['pageId'] ?? 0);
    if (!$post_id) return null;
    return self::check_edit($post_id, $payload['language'] ?? null);
  }

  /**
   * Put a new post in $language and, with $source_id, into the source's
   * translation group. Never changes the source or its other translations.
   * @return true|WP_Error
   */
  public static function assign($post_id, $language, $source_id = 0) {
    if (!self::active()) return true;
    $language = is_string($language) ? sanitize_key($language) : '';
    $source_id = (int) $source_id;
    if ($language === '' && $source_id) return new WP_Error('language_required', 'translationOf needs a language.');
    if ($language === '') return true;
    if (!in_array($language, self::languages(), true)) {
      return new WP_Error('invalid', sprintf('Language "%s" is not on this site (languages: %s).', $language, implode(', ', self::languages())));
    }
    if ($source_id) {
      $src = get_post($source_id);
      if (!$src) return new WP_Error('not_found', 'translationOf post not found.');
      $group = (array) pll_get_post_translations($source_id);
      if (!$group && ($sl = self::of($source_id))) $group = [$sl => $source_id];
      if (isset($group[$language]) && (int) $group[$language] !== (int) $post_id) {
        return new WP_Error('conflict', sprintf('Post %d already has a %s translation (post %d).', $source_id, $language, $group[$language]));
      }
      if (self::of($source_id) === $language) {
        return new WP_Error('invalid', sprintf('Post %d is already in %s.', $source_id, $language));
      }
    }
    pll_set_post_language((int) $post_id, $language);
    if ($source_id) {
      $group[$language] = (int) $post_id;
      pll_save_post_translations($group);
    }
    return true;
  }

  /** The language part of /capabilities' multilingual entry. */
  public static function describe() {
    if (!self::active()) return null;
    return [
      'slug'      => 'polylang',
      'name'      => 'Polylang',
      'version'   => defined('POLYLANG_VERSION') ? POLYLANG_VERSION : null,
      'languages' => self::languages(),
      'default'   => function_exists('pll_default_language') ? pll_default_language() : null,
      'writable'  => true,
      'languageRequired' => true,
    ];
  }
}
