<?php
/**
 * Visibility action-request admin surface.
 *
 *  - Registers the "Inbox" and "Activity" submenu pages under the plugin's
 *    top-level menu.
 *  - Exposes AJAX endpoints for the inbox JS to call (fetch pending,
 *    approve, reject).
 *  - Schedules the cron tick that pulls auto-approved requests from
 *    Visibility and executes them locally.
 */

if (!defined('ABSPATH')) {
  exit;
}

class Visibility_Requests {

  const CRON_HOOK = 'visibility_pull_approved_requests';

  public static function init() {
    add_action('admin_menu', [__CLASS__, 'register_menus'], 20);
    add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);

    add_action('wp_ajax_visibility_inbox_fetch',   [__CLASS__, 'ajax_fetch']);
    add_action('wp_ajax_visibility_inbox_approve', [__CLASS__, 'ajax_approve']);
    add_action('wp_ajax_visibility_inbox_reject',  [__CLASS__, 'ajax_reject']);
    add_action('wp_ajax_visibility_inbox_history', [__CLASS__, 'ajax_history']);

    add_action(self::CRON_HOOK, [__CLASS__, 'run_cron_tick']);
  }

  public static function activate_cron() {
    if (!wp_next_scheduled(self::CRON_HOOK)) {
      // 5-minute interval — registered via cron_schedules filter below.
      wp_schedule_event(time() + 60, 'visibility_every_5_min', self::CRON_HOOK);
    }
  }

  public static function deactivate_cron() {
    $ts = wp_next_scheduled(self::CRON_HOOK);
    if ($ts) {
      wp_unschedule_event($ts, self::CRON_HOOK);
    }
  }

  public static function register_5min_schedule($schedules) {
    if (!isset($schedules['visibility_every_5_min'])) {
      $schedules['visibility_every_5_min'] = [
        'interval' => 5 * MINUTE_IN_SECONDS,
        'display'  => __('Every 5 minutes (Visibility)', 'agentic-seo-for-visibility'),
      ];
    }
    return $schedules;
  }

  public static function register_menus() {
    if (!Visibility_Client::is_paired()) {
      return; // Pairing screen lives on the main settings page.
    }
    $pending = self::cached_pending_count();
    $label = $pending > 0
      ? sprintf(
          /* translators: %s: pending count badge */
          __('Approvals %s', 'agentic-seo-for-visibility'),
          '<span class="awaiting-mod">' . number_format_i18n($pending) . '</span>'
        )
      : __('Approvals', 'agentic-seo-for-visibility');

    add_submenu_page(
      'options-general.php',
      __('Agentic SEO — Approvals', 'agentic-seo-for-visibility'),
      $label,
      'manage_options',
      'visibility-inbox',
      [__CLASS__, 'render_inbox_page']
    );

    add_submenu_page(
      'options-general.php',
      __('Agentic SEO — Activity', 'agentic-seo-for-visibility'),
      __('Activity', 'agentic-seo-for-visibility'),
      'manage_options',
      'visibility-activity',
      [__CLASS__, 'render_activity_page']
    );
  }

  public static function enqueue_assets($hook) {
    if (!in_array($hook, [
      'settings_page_visibility-inbox',
      'settings_page_visibility-activity',
    ], true)) {
      return;
    }
    wp_enqueue_style(
      'visibility-admin',
      plugins_url('assets/css/visibility-admin.css', VISIBILITY_PLUGIN_FILE),
      [],
      VISIBILITY_PLUGIN_VERSION
    );
    wp_enqueue_script(
      'visibility-inbox',
      plugins_url('assets/js/visibility-inbox.js', VISIBILITY_PLUGIN_FILE),
      ['wp-i18n'],
      VISIBILITY_PLUGIN_VERSION,
      true
    );
    wp_localize_script('visibility-inbox', 'VisibilityInbox', [
      'ajaxUrl' => admin_url('admin-ajax.php'),
      'nonce'   => wp_create_nonce('visibility_inbox'),
    ]);
  }

  // ─── Page renderers ────────────────────────────────────────────────────

  public static function render_inbox_page() {
    if (!current_user_can('manage_options')) {
      wp_die(esc_html__('Insufficient permissions.', 'agentic-seo-for-visibility'));
    }
    require VISIBILITY_PLUGIN_DIR . 'views/admin-inbox.php';
  }

  public static function render_activity_page() {
    if (!current_user_can('manage_options')) {
      wp_die(esc_html__('Insufficient permissions.', 'agentic-seo-for-visibility'));
    }
    require VISIBILITY_PLUGIN_DIR . 'views/admin-activity.php';
  }

  // ─── AJAX handlers ─────────────────────────────────────────────────────

  private static function check_ajax() {
    if (!current_user_can('manage_options')) {
      wp_send_json_error(['message' => __('Insufficient permissions.', 'agentic-seo-for-visibility')], 403);
    }
    check_ajax_referer('visibility_inbox');
  }

  public static function ajax_fetch() {
    self::check_ajax();
    $rows = Visibility_Client::inbox();
    if (is_wp_error($rows)) {
      wp_send_json_error(['message' => $rows->get_error_message()], 502);
    }
    if (!is_array($rows)) {
      $rows = [];
    }
    foreach ($rows as $row) {
      Visibility_Client::remember_site_id_from($row);
    }
    set_transient('visibility_pending_count', count(array_filter($rows, function ($r) {
      return isset($r['status']) && $r['status'] === 'pending';
    })), 5 * MINUTE_IN_SECONDS);
    wp_send_json_success(['rows' => $rows]);
  }

  public static function ajax_history() {
    self::check_ajax();
    $status = isset($_POST['status']) ? sanitize_text_field(wp_unslash($_POST['status'])) : 'any';
    $rows = Visibility_Client::history($status, 100);
    if (is_wp_error($rows)) {
      wp_send_json_error(['message' => $rows->get_error_message()], 502);
    }
    wp_send_json_success(['rows' => is_array($rows) ? $rows : []]);
  }

  public static function ajax_approve() {
    self::check_ajax();
    $id   = isset($_POST['requestId']) ? sanitize_text_field(wp_unslash($_POST['requestId'])) : '';
    $note = isset($_POST['note']) ? sanitize_textarea_field(wp_unslash($_POST['note'])) : null;
    if ($id === '') {
      wp_send_json_error(['message' => __('Missing request ID.', 'agentic-seo-for-visibility')], 400);
    }
    $row = Visibility_Client::approve($id, $note);
    if (is_wp_error($row)) {
      wp_send_json_error(['message' => $row->get_error_message()], 502);
    }
    // Execute locally now that the request is approved.
    $action_type = isset($row['actionType']) ? $row['actionType'] : '';
    $payload = isset($row['actionPayload']) && is_array($row['actionPayload']) ? $row['actionPayload'] : [];
    list($ok, $result, $err) = Visibility_Executor::execute($action_type, $payload);
    Visibility_Client::report_execution($id, $ok, $result, $err);
    wp_send_json_success(['ok' => $ok, 'result' => $result, 'error' => $err]);
  }

  public static function ajax_reject() {
    self::check_ajax();
    $id   = isset($_POST['requestId']) ? sanitize_text_field(wp_unslash($_POST['requestId'])) : '';
    $note = isset($_POST['note']) ? sanitize_textarea_field(wp_unslash($_POST['note'])) : '';
    if ($id === '' || $note === '') {
      wp_send_json_error(['message' => __('Request ID and rejection note are required.', 'agentic-seo-for-visibility')], 400);
    }
    $row = Visibility_Client::reject($id, $note);
    if (is_wp_error($row)) {
      wp_send_json_error(['message' => $row->get_error_message()], 502);
    }
    wp_send_json_success(['row' => $row]);
  }

  // ─── Cron tick ─────────────────────────────────────────────────────────

  public static function run_cron_tick() {
    if (!Visibility_Client::is_paired()) return;
    $rows = Visibility_Client::inbox(true);
    if (is_wp_error($rows) || !is_array($rows)) return;
    foreach ($rows as $row) {
      Visibility_Client::remember_site_id_from($row);
      if (!isset($row['status']) || $row['status'] !== 'approved') continue;
      if (!empty($row['executedAt'])) continue;
      $action_type = isset($row['actionType']) ? $row['actionType'] : '';
      $payload = isset($row['actionPayload']) && is_array($row['actionPayload']) ? $row['actionPayload'] : [];
      list($ok, $result, $err) = Visibility_Executor::execute($action_type, $payload);
      Visibility_Client::report_execution($row['id'], $ok, $result, $err);
    }
  }

  // ─── Pending-count cache for the menu badge ────────────────────────────

  public static function cached_pending_count() {
    $cached = get_transient('visibility_pending_count');
    if ($cached === false) return 0;
    return (int) $cached;
  }
}
