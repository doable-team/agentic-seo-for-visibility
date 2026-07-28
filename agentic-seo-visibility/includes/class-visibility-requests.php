<?php
/**
 * Visibility action-request execution.
 *
 * Schedules the cron tick that pulls approved requests from Visibility and
 * executes them locally (also reachable via the plugin's REST `pull` route).
 *
 * There is deliberately NO approvals UI here. Requests are reviewed and
 * decided in Visibility's own Inbox → Approvals, which is the single place a
 * decision is ever made; duplicating that screen in WP admin only created a
 * second place to look and a second thing to keep correct.
 */

if (!defined('ABSPATH')) {
  exit;
}

class Visibility_Requests {

  const CRON_HOOK = 'visibility_pull_approved_requests';

  public static function init() {
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
        'display'  => __('Every 5 minutes (Visibility)', 'agentic-seo-visibility'),
      ];
    }
    return $schedules;
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

}
