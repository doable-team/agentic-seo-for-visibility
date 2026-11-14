<?php
/**
 * Default author for content from Visibility (#275).
 *
 * Requests from Visibility run without a logged-in WordPress user, so a new
 * post would have no author. New posts and pages get this user instead:
 *   1. the user chosen on the settings page (option visibility_default_author),
 *   2. else the admin who connected the site (visibility_paired_by),
 *   3. else the first administrator.
 * A chosen user who no longer exists (or can no longer publish) falls back to
 * the first administrator, and the settings page says so. Edits never change
 * an existing post's author.
 */

if (!defined('ABSPATH')) {
  exit;
}

class Visibility_Author {

  const OPTION = 'visibility_default_author';
  const PAIRED_BY = 'visibility_paired_by';

  /** Users who can publish posts, for the dropdown. */
  public static function candidates() {
    $users = get_users(['capability' => 'publish_posts', 'orderby' => 'display_name', 'fields' => ['ID', 'display_name', 'user_login']]);
    return $users;
  }

  private static function usable($user_id) {
    $user_id = (int) $user_id;
    if ($user_id <= 0) return false;
    $u = get_userdata($user_id);
    return $u && user_can($u, 'publish_posts');
  }

  public static function first_admin() {
    $ids = get_users(['role' => 'administrator', 'orderby' => 'ID', 'order' => 'ASC', 'number' => 1, 'fields' => 'ID']);
    return $ids ? (int) $ids[0] : 0;
  }

  /** The chosen user was deleted (or lost publish rights): the fallback is in use. */
  public static function chosen_missing() {
    $chosen = (int) get_option(self::OPTION, 0);
    return $chosen > 0 && !self::usable($chosen);
  }

  /** Author id for new content from Visibility. */
  public static function id() {
    $chosen = (int) get_option(self::OPTION, 0);
    if ($chosen > 0) {
      return self::usable($chosen) ? $chosen : self::first_admin();
    }
    $paired_by = (int) get_option(self::PAIRED_BY, 0);
    if (self::usable($paired_by)) return $paired_by;
    return self::first_admin();
  }

  /** {id, name, source} for /capabilities. */
  public static function describe() {
    $id = self::id();
    $u = $id ? get_userdata($id) : null;
    $chosen = (int) get_option(self::OPTION, 0);
    $source = $chosen > 0 ? (self::usable($chosen) ? 'setting' : 'fallback') : (self::usable((int) get_option(self::PAIRED_BY, 0)) ? 'connected_by' : 'first_admin');
    return $u ? ['id' => (int) $u->ID, 'name' => $u->display_name, 'source' => $source] : null;
  }
}
