<?php
/**
 * Page builders (#286): read a builder page's elements and change one of
 * them by its id, through the builder's own save path so its CSS and caches
 * are rebuilt and the page still opens in the builder.
 *
 * One adapter per builder. A builder without a writing adapter is reported
 * as `writable: false`: agents then only change SEO, custom fields and alt
 * text on that page.
 */

if (!defined('ABSPATH')) {
  exit;
}

interface Visibility_Builder_Adapter {
  /** True when the builder plugin is active and able to save. */
  public function available();
  /** Widgets of the page: [{elementId, elType, widgetType, text, attrs}]. */
  public function elements($post);
  /**
   * Apply edits [{target:{elementId}, op, text?, find?, attrs?}].
   * @return array|WP_Error ['diff' => [...], 'notes' => [...]]
   */
  public function edit($post, array $edits);
}

class Visibility_Builders {

  /** slug => adapter, filterable so more builders can be added. */
  public static function adapters() {
    $a = [];
    return apply_filters('visibility_builder_adapters', $a);
  }

  /** The writing adapter for a builder slug, if it is available on this site. */
  public static function adapter($slug) {
    $all = self::adapters();
    if (!isset($all[$slug])) return null;
    $ad = $all[$slug];
    return $ad->available() ? $ad : null;
  }

  public static function writable($slug) {
    return self::adapter($slug) !== null;
  }

  public static function elements($post, $slug) {
    $ad = self::adapter($slug);
    return $ad ? $ad->elements($post) : null;
  }

  /**
   * Edit a builder page.
   * @return array [ok, result, error] like an executor method
   */
  public static function edit($post, $slug, array $edits) {
    $ad = self::adapter($slug);
    if (!$ad) return [false, null, 'unsupported'];
    foreach ($edits as $i => $e) {
      if (!is_array($e) || empty($e['target']['elementId'])) {
        return [false, null, new WP_Error('invalid', sprintf('edit %d: target.elementId required on a builder page', $i))];
      }
    }
    $r = $ad->edit($post, $edits);
    if (is_wp_error($r)) return [false, null, $r];
    return [true, $r, null];
  }

  /**
   * Run $fn as a user who may edit $post_id (the default author, else the
   * first administrator), then switch back. Builders' own save paths check
   * the current user; Visibility's requests have none.
   */
  public static function as_editor($post_id, $fn) {
    $prev = get_current_user_id();
    $uid = 0;
    foreach ([Visibility_Author::id(), Visibility_Author::first_admin()] as $cand) {
      if ($cand && user_can($cand, 'edit_post', $post_id)) { $uid = $cand; break; }
    }
    if (!$uid) return new WP_Error('unsupported', 'No user on this site can edit this page, so the builder cannot save it.');
    wp_set_current_user($uid);
    try {
      return $fn();
    } finally {
      wp_set_current_user($prev);
    }
  }
}
