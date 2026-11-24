<?php
/**
 * "Update available" notice (owner, 2.1.1): when wordpress.org has a newer
 * version of this plugin, WordPress admin says so with a link straight to the
 * update, so updating is two clicks. Uses WordPress's own update data (the
 * `update_plugins` site transient), shown to people who can update plugins,
 * dismissible per version, and always on the plugin's own settings page.
 */

if (!defined('ABSPATH')) {
  exit;
}

class Visibility_Update_Notice {

  /** Per user: the version whose notice they dismissed. */
  const DISMISSED = 'visibility_update_dismissed';

  public static function init() {
    add_action('admin_notices', [__CLASS__, 'render']);
    add_action('network_admin_notices', [__CLASS__, 'render']);
    add_action('admin_post_visibility_dismiss_update', [__CLASS__, 'dismiss']);
  }

  /** The newer version on wordpress.org, or null. */
  public static function newer_version() {
    $updates = get_site_transient('update_plugins');
    $file = plugin_basename(VISIBILITY_PLUGIN_FILE);
    if (!is_object($updates) || empty($updates->response[$file])) return null;
    $new = isset($updates->response[$file]->new_version) ? (string) $updates->response[$file]->new_version : '';
    if ($new === '' || version_compare($new, VISIBILITY_PLUGIN_VERSION, '<=')) return null;
    return $new;
  }

  /** The one-click update link (nonce'd like WordPress's own). */
  public static function update_url() {
    $file = plugin_basename(VISIBILITY_PLUGIN_FILE);
    return wp_nonce_url(self_admin_url('update.php?action=upgrade-plugin&plugin=' . rawurlencode($file)), 'upgrade-plugin_' . $file);
  }

  private static function on_own_page() {
    return isset($_GET['page']) && sanitize_key(wp_unslash($_GET['page'])) === 'visibility'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- which screen this is, no action taken.
  }

  public static function render() {
    if (!current_user_can('update_plugins')) return;
    $new = self::newer_version();
    if ($new === null) return;
    $own = self::on_own_page();
    // Dismissed for this version: hidden elsewhere, still shown on the plugin's own page.
    if (!$own && get_user_meta(get_current_user_id(), self::DISMISSED, true) === $new) return;
    $dismiss = wp_nonce_url(admin_url('admin-post.php?action=visibility_dismiss_update&version=' . rawurlencode($new)), 'visibility_dismiss_update');
    ?>
    <div class="notice notice-warning" id="visibility-update-notice">
      <p>
        <strong><?php echo esc_html__('Agentic SEO for Visibility: Update available — update now to avoid errors.', 'agentic-seo-visibility'); ?></strong>
        <?php
        /* translators: 1: new version, 2: installed version */
        echo esc_html(sprintf(__('Version %1$s is out (you have %2$s).', 'agentic-seo-visibility'), $new, VISIBILITY_PLUGIN_VERSION));
        ?>
      </p>
      <p>
        <a class="button button-primary" id="visibility-update-now" href="<?php echo esc_url(self::update_url()); ?>"><?php echo esc_html__('Update now', 'agentic-seo-visibility'); ?></a>
        <?php if (!$own) : ?>
          <a class="button-link" id="visibility-update-dismiss" href="<?php echo esc_url($dismiss); ?>" style="margin-left:10px"><?php echo esc_html__('Dismiss', 'agentic-seo-visibility'); ?></a>
        <?php endif; ?>
      </p>
    </div>
    <?php
  }

  /** Hide the notice for this user until the next version. */
  public static function dismiss() {
    if (!current_user_can('update_plugins')) wp_die(esc_html__('Permission denied.', 'agentic-seo-visibility'));
    check_admin_referer('visibility_dismiss_update');
    $v = isset($_GET['version']) ? sanitize_text_field(wp_unslash($_GET['version'])) : '';
    if ($v !== '') update_user_meta(get_current_user_id(), self::DISMISSED, $v);
    wp_safe_redirect(wp_get_referer() ?: admin_url());
    exit;
  }
}
