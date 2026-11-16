<?php
/**
 * Multilingual sites (#290 shared part, #334 Polylang, #340 WPML).
 *
 * - Every post reports its language and its translation group
 *   ({lang: post id}).
 * - Edits on a multilingual site must name the language of the post they
 *   change: missing → language_required, different → language_mismatch.
 *   That keeps an agent from editing the wrong language by mistake.
 * - Editing a post only ever changes that post; the multilingual plugin's own
 *   sync of taxonomies/meta between translations is left as the owner
 *   configured it.
 * - A new draft can be created in a language and linked to its source post as
 *   its translation.
 *
 * Two providers, picked by what is active:
 *   polylang  pll_get_post_language(), pll_set_post_language(),
 *             pll_save_post_translations(), the `lang` query var.
 *   wpml      WPML's documented hooks only (wpml.org › WPML Hooks Reference):
 *             wpml_active_languages, wpml_default_language,
 *             wpml_element_language_details (trid + language),
 *             wpml_get_element_translations (the group by trid),
 *             wpml_object_id (a translation by language, drafts included),
 *             wpml_is_translated_post_type, wpml_set_element_language_details
 *             (language + link to the source), wpml_switch_language (scoped
 *             query language, closed with null).
 *   translatepress  TranslatePress keeps one post per page and stores the
 *             translated text in its own tables, so a post has no separate
 *             translations to edit. Read-only (C-42): the page language is
 *             the default language, `translations` lists each published
 *             language with its URL (get_url_for_language), edits name the
 *             default language or none; any other language is refused with
 *             language_unsupported. Visibility never changes translated text.
 * WPML found but one of those hooks missing (an old or changed WPML): the
 * site is reported as multilingual but not writable, and creating content in
 * a language is refused instead of guessed.
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
    'product.update', 'term.update',
  ];

  /** WPML hooks the adapter relies on: [type, name]. */
  const WPML_HOOKS = [
    ['filter', 'wpml_active_languages'],
    ['filter', 'wpml_default_language'],
    ['filter', 'wpml_element_language_details'],
    ['filter', 'wpml_get_element_translations'],
    ['filter', 'wpml_is_translated_post_type'],
    ['filter', 'wpml_object_id'],
    ['action', 'wpml_set_element_language_details'],
    ['action', 'wpml_switch_language'],
  ];

  // ─── Provider ──────────────────────────────────────────────────────────

  /** "polylang" | "wpml" | null: the multilingual plugin Visibility can work with. */
  public static function provider() {
    if (function_exists('pll_get_post_language') && function_exists('pll_languages_list') && count((array) pll_languages_list()) > 0) return 'polylang';
    if (self::wpml_ready() && count(self::wpml_languages()) > 0) return 'wpml';
    if (self::trp_settings()) return 'translatepress';
    return null;
  }

  // ─── TranslatePress (C-42) ─────────────────────────────────────────────

  /** TranslatePress settings with at least one extra language, else null. */
  private static function trp_settings() {
    if (!class_exists('TRP_Translate_Press')) return null;
    $s = null;
    $trp = TRP_Translate_Press::get_trp_instance();
    if (is_object($trp) && method_exists($trp, 'get_component')) {
      $c = $trp->get_component('settings');
      if (is_object($c) && method_exists($c, 'get_settings')) $s = $c->get_settings();
    }
    if (!is_array($s)) $s = get_option('trp_settings', null);
    if (!is_array($s) || empty($s['default-language'])) return null;
    $langs = !empty($s['publish-languages']) ? (array) $s['publish-languages'] : (array) ($s['translation-languages'] ?? []);
    return count($langs) > 1 ? $s : null;
  }

  /** TranslatePress language codes are locales (en_US); its URL slugs are short (en). */
  private static function trp_slug($s, $code) {
    return isset($s['url-slugs'][$code]) && $s['url-slugs'][$code] !== '' ? (string) $s['url-slugs'][$code] : strtolower(substr($code, 0, 2));
  }

  private static function trp_languages() {
    $s = self::trp_settings();
    if (!$s) return [];
    $langs = !empty($s['publish-languages']) ? (array) $s['publish-languages'] : (array) $s['translation-languages'];
    return array_values(array_map(function ($c) use ($s) { return self::trp_slug($s, $c); }, $langs));
  }

  /** [{language, url}] for a post on a TranslatePress site. */
  private static function trp_translations($post_id) {
    $s = self::trp_settings();
    $url = get_permalink((int) $post_id);
    if (!$s || !$url) return [];
    $conv = null;
    $trp = TRP_Translate_Press::get_trp_instance();
    if (is_object($trp) && method_exists($trp, 'get_component')) $conv = $trp->get_component('url_converter');
    $out = [];
    $langs = !empty($s['publish-languages']) ? (array) $s['publish-languages'] : (array) $s['translation-languages'];
    foreach ($langs as $code) {
      $u = $url;
      if ($code !== $s['default-language'] && is_object($conv) && method_exists($conv, 'get_url_for_language')) {
        $u = (string) $conv->get_url_for_language($code, $url, '');
      }
      $out[] = ['language' => self::trp_slug($s, $code), 'locale' => (string) $code, 'url' => $u];
    }
    return $out;
  }

  /** WPML is installed (it defines ICL_SITEPRESS_VERSION). */
  public static function wpml_present() {
    return defined('ICL_SITEPRESS_VERSION');
  }

  /** WPML is installed and every hook we use is registered. */
  public static function wpml_ready() {
    return self::wpml_present() && empty(self::wpml_missing_hooks());
  }

  /** Hooks from WPML_HOOKS that are not registered on this site. */
  public static function wpml_missing_hooks() {
    $missing = [];
    foreach (self::WPML_HOOKS as $h) {
      if (!has_filter($h[1])) $missing[] = $h[1];
    }
    return $missing;
  }

  private static function wpml_languages() {
    $langs = apply_filters('wpml_active_languages', null, ['skip_missing' => 0]);
    return is_array($langs) ? array_values(array_map('strval', array_keys($langs))) : [];
  }

  /** WPML's element type for a post type ("post_page"). */
  private static function wpml_type($post_type) {
    return 'post_' . $post_type;
  }

  /** {trid, language_code, source_language_code} or null. */
  private static function wpml_details($post_id) {
    $post = get_post((int) $post_id);
    if (!$post) return null;
    $d = apply_filters('wpml_element_language_details', null, ['element_id' => (int) $post->ID, 'element_type' => $post->post_type]);
    if (!is_object($d) || empty($d->language_code)) return null;
    return $d;
  }

  public static function active() {
    return self::provider() !== null;
  }

  public static function languages() {
    $p = self::provider();
    if ($p === 'polylang') return array_values((array) pll_languages_list());
    if ($p === 'wpml') return self::wpml_languages();
    if ($p === 'translatepress') return self::trp_languages();
    return [];
  }

  public static function default_language() {
    $p = self::provider();
    if ($p === 'polylang') return function_exists('pll_default_language') ? pll_default_language() : null;
    if ($p === 'wpml') {
      $d = apply_filters('wpml_default_language', null);
      return is_string($d) && $d !== '' ? $d : null;
    }
    if ($p === 'translatepress') {
      $s = self::trp_settings();
      return self::trp_slug($s, $s['default-language']);
    }
    return null;
  }

  /** Language slug of a post, or null. */
  public static function of($post_id) {
    $p = self::provider();
    if ($p === 'polylang') {
      $l = pll_get_post_language((int) $post_id);
      return $l ? (string) $l : null;
    }
    if ($p === 'wpml') {
      $d = self::wpml_details($post_id);
      return $d ? (string) $d->language_code : null;
    }
    // One post for every language; what is stored is the default language.
    if ($p === 'translatepress') return get_post((int) $post_id) ? self::default_language() : null;
    return null;
  }

  /** {lang: post id} for the post's translation group (itself included); TranslatePress: [{language, locale, url}]. */
  public static function translations($post_id) {
    $p = self::provider();
    if ($p === 'translatepress') return self::trp_translations($post_id);
    if ($p === 'polylang') {
      if (!function_exists('pll_get_post_translations')) return null;
      return (object) array_map('intval', (array) pll_get_post_translations((int) $post_id));
    }
    if ($p === 'wpml') {
      $d = self::wpml_details($post_id);
      if (!$d || empty($d->trid)) return null;
      $rows = apply_filters('wpml_get_element_translations', null, (int) $d->trid, self::wpml_type(get_post_type((int) $post_id)));
      $out = [];
      foreach ((array) $rows as $lang => $row) {
        $row = (object) $row;
        if (!empty($row->element_id)) $out[(string) $lang] = (int) $row->element_id;
      }
      // Outside wp-admin WPML leaves out translations the (anonymous) caller
      // can't read, such as drafts; Visibility must see them, so ask per
      // language too (wpml_object_id, no fallback to the original).
      $type = get_post_type((int) $post_id);
      foreach (self::languages() as $lang) {
        if (isset($out[$lang])) continue;
        $id = (int) apply_filters('wpml_object_id', (int) $post_id, $type, false, $lang);
        if ($id > 0) $out[$lang] = $id;
      }
      return (object) $out;
    }
    return null;
  }

  /** Whether the multilingual plugin manages this post type. */
  public static function translated_type($type) {
    $p = self::provider();
    if ($p === 'polylang') return function_exists('pll_is_translated_post_type') && pll_is_translated_post_type($type);
    if ($p === 'wpml') return (bool) apply_filters('wpml_is_translated_post_type', null, $type);
    if ($p === 'translatepress') return true;
    return false;
  }

  // ─── Queries ───────────────────────────────────────────────────────────

  /**
   * Run a post list in one language ($lang) or in every language (''), then
   * put the query language back. Returns $fn's WP_Query.
   */
  public static function query(array $args, $lang, $fn) {
    $p = self::provider();
    if ($p === 'translatepress') return $fn($args);
    $lang = ($lang !== '' && in_array($lang, self::languages(), true)) ? $lang : '';
    if ($p === 'polylang') {
      $args['lang'] = $lang;
      return $fn($args);
    }
    if ($p === 'wpml') {
      if ($lang === '') {
        // WPML filters queries by the current language through query
        // filters; without them the list has every language.
        $args['suppress_filters'] = true;
        return $fn($args);
      }
      // A scope: a language opens it, null closes it (WPML 5 keeps a stack).
      do_action('wpml_switch_language', $lang);
      try {
        return $fn($args);
      } finally {
        do_action('wpml_switch_language', null);
      }
    }
    return $fn($args);
  }

  // ─── Checks ────────────────────────────────────────────────────────────

  /**
   * Why an edit may not run on this post, or null.
   * @return array|null [code, message]
   */
  public static function check_edit($post_id, $language) {
    if (!self::active()) return null;
    if (self::provider() === 'translatepress') {
      // Only the stored (default-language) text can change; translated text lives in TranslatePress.
      $language = is_string($language) ? sanitize_key(str_replace('-', '_', $language)) : '';
      $def = self::default_language();
      $s = self::trp_settings();
      if ($language === '' || $language === $def || strtolower($language) === strtolower((string) $s['default-language'])) return null;
      return ['language_unsupported', sprintf('This site translates pages with TranslatePress, which keeps translations in its own tables. Visibility only changes the %s text; edit the %s translation in TranslatePress.', $def, $language)];
    }
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
    if ($action_type === 'term.update') return null;
    $post_id = (int) ($payload['postId'] ?? $payload['pageId'] ?? $payload['productId'] ?? 0);
    if (!$post_id) return null;
    return self::check_edit($post_id, $payload['language'] ?? null);
  }

  /**
   * Put a new post in $language and, with $source_id, into the source's
   * translation group. Never changes the source or its other translations.
   * @return true|WP_Error
   */
  public static function assign($post_id, $language, $source_id = 0) {
    $language = is_string($language) ? sanitize_key($language) : '';
    $source_id = (int) $source_id;
    if (self::provider() === 'translatepress') {
      if ($source_id || ($language !== '' && $language !== self::default_language())) {
        return new WP_Error('language_unsupported', sprintf('This site translates pages with TranslatePress: there is one page for every language. Create the page in %s and translate it in TranslatePress.', self::default_language()));
      }
      return true;
    }
    if (!self::active()) {
      if (($language !== '' || $source_id) && self::wpml_present()) {
        return new WP_Error('unsupported', sprintf('WPML is active but does not offer the hooks Visibility uses (%s), so the language was not set. Set it in WordPress.', implode(', ', self::wpml_missing_hooks())));
      }
      return true;
    }
    if ($language === '' && $source_id) return new WP_Error('language_required', 'translationOf needs a language.');
    if ($language === '') return true;
    if (!in_array($language, self::languages(), true)) {
      return new WP_Error('invalid', sprintf('Language "%s" is not on this site (languages: %s).', $language, implode(', ', self::languages())));
    }
    if ($source_id) {
      $src = get_post($source_id);
      if (!$src) return new WP_Error('not_found', 'translationOf post not found.');
      $group = (array) self::translations($source_id);
      if (!$group && ($sl = self::of($source_id))) $group = [$sl => $source_id];
      if (isset($group[$language]) && (int) $group[$language] !== (int) $post_id) {
        return new WP_Error('conflict', sprintf('Post %d already has a %s translation (post %d).', $source_id, $language, $group[$language]));
      }
      if (self::of($source_id) === $language) {
        return new WP_Error('invalid', sprintf('Post %d is already in %s.', $source_id, $language));
      }
    }
    if (self::provider() === 'polylang') {
      pll_set_post_language((int) $post_id, $language);
      if ($source_id) {
        $group[$language] = (int) $post_id;
        pll_save_post_translations($group);
      }
      return true;
    }
    // WPML: language and, for a translation, the source's trid and language.
    $src = $source_id ? self::wpml_details($source_id) : null;
    if ($source_id && (!$src || empty($src->trid))) {
      return new WP_Error('invalid', sprintf('Post %d has no language in WPML, so a translation cannot be linked to it.', $source_id));
    }
    do_action('wpml_set_element_language_details', [
      'element_id'           => (int) $post_id,
      'element_type'         => self::wpml_type(get_post_type((int) $post_id)),
      'trid'                 => $src ? (int) $src->trid : false,
      'language_code'        => $language,
      'source_language_code' => $src ? (string) $src->language_code : null,
    ]);
    // Read back: refuse rather than report a language WPML didn't store.
    if (self::of($post_id) !== $language) {
      return new WP_Error('save_failed', sprintf('WPML did not keep language %s for the new post.', $language));
    }
    return true;
  }

  /** The language part of /capabilities' multilingual entry. */
  public static function describe() {
    $p = self::provider();
    if ($p === 'polylang') {
      return [
        'slug'      => 'polylang',
        'name'      => 'Polylang',
        'version'   => defined('POLYLANG_VERSION') ? POLYLANG_VERSION : null,
        'languages' => self::languages(),
        'default'   => self::default_language(),
        'writable'  => true,
        'languageRequired' => true,
      ];
    }
    if ($p === 'translatepress') {
      return [
        'slug'      => 'translatepress',
        'name'      => 'TranslatePress',
        'version'   => defined('TRP_PLUGIN_VERSION') ? TRP_PLUGIN_VERSION : null,
        'languages' => self::languages(),
        'default'   => self::default_language(),
        'writable'  => false,
        'languageRequired' => false,
        'note'      => 'TranslatePress keeps one page for every language and stores translations in its own tables. Visibility reads and changes the default-language text only; translations are edited in TranslatePress.',
      ];
    }
    if (self::wpml_present()) {
      $ready = $p === 'wpml';
      $out = [
        'slug'      => 'wpml',
        'name'      => 'WPML',
        'version'   => (string) ICL_SITEPRESS_VERSION,
        'languages' => $ready ? self::languages() : (has_filter('wpml_active_languages') ? self::wpml_languages() : []),
        'default'   => $ready ? self::default_language() : null,
        'writable'  => $ready,
        'languageRequired' => $ready,
      ];
      if (!$ready) $out['note'] = sprintf('WPML is active but does not offer the hooks Visibility uses (%s); languages are read-only.', implode(', ', self::wpml_missing_hooks()) ?: 'no languages');
      return $out;
    }
    return null;
  }
}
