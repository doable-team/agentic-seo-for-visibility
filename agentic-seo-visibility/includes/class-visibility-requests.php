<?php
/**
 * Visibility action-request execution.
 *
 * Schedules the cron tick that pulls approved requests from Visibility and
 * executes them locally (also reachable via the plugin's REST `pull` route).
 *
 * There is deliberately NO approvals UI here. Requests are reviewed and
 * decided in Visibility (Notifications › Approvals), which is the single place a
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
    // Results that ran but could not be reported last time: report them now.
    self::retry_unreported();
    $rows = Visibility_Client::inbox(true);
    if (is_wp_error($rows) || !is_array($rows)) return;
    foreach ($rows as $row) {
      Visibility_Client::remember_site_id_from($row);
      if (!isset($row['status']) || $row['status'] !== 'approved') continue;
      if (!empty($row['executedAt'])) continue;
      if (empty($row['id'])) continue;
      $action_type = isset($row['actionType']) ? $row['actionType'] : '';
      $payload = isset($row['actionPayload']) && is_array($row['actionPayload']) ? $row['actionPayload'] : [];
      self::run((string) $row['id'], $action_type, $payload);
    }
    self::prune();
  }

  // ─── Run each approved request exactly once ────────────────────────────
  //
  // Every request id is claimed in wp_options with INSERT IGNORE BEFORE the
  // action runs. The unique option_name makes the claim atomic across PHP
  // processes, so the push (/events/action-approved) and the cron tick can
  // race and only one of them runs the action. The outcome is stored on the
  // same row; if reporting it to Visibility fails, only the report is retried
  // (next tick), never the action.

  const RECORD_PREFIX = 'visibility_req_';
  /** A claim older than this that never finished is treated as interrupted. */
  const STALE_AFTER = 900;
  /** Finished records are kept this long so a late duplicate is still caught. */
  const KEEP_FOR = 30 * DAY_IN_SECONDS;

  /**
   * Execute $request_id once, then report it.
   *
   * @return array ['ok' => bool, 'result' => mixed, 'error' => ?string,
   *                'errorCode' => ?string, 'duplicate' => bool, 'reported' => bool]
   */
  public static function run($request_id, $action_type, $payload) {
    $request_id = (string) $request_id;
    if (!self::claim($request_id, $action_type)) {
      $rec = self::get_record($request_id);
      // Another process is running it right now (push racing the cron tick):
      // wait a little for its outcome rather than answer "failed".
      $waited = 0;
      while ($rec && $rec['state'] === 'running' && $waited < 15000 && time() - (int) $rec['claimedAt'] < self::STALE_AFTER) {
        usleep(250000);
        $waited += 250;
        $rec = self::get_record($request_id);
      }
      if ($rec && $rec['state'] === 'running') {
        if (time() - (int) $rec['claimedAt'] < self::STALE_AFTER) {
          return ['ok' => false, 'result' => null, 'error' => 'This request is already running.', 'errorCode' => 'in_progress', 'duplicate' => true, 'reported' => false];
        }
        // The process that claimed it died mid-run. Running it again could
        // double-apply, so report it as failed instead and let a person retry.
        $rec = array_merge($rec, [
          'state' => 'done', 'ok' => false, 'result' => null,
          'error' => 'The action was interrupted on the site and was not run again, to avoid running it twice. Check the site, then retry if needed.',
          'errorCode' => 'interrupted', 'finishedAt' => time(),
        ]);
        self::put_record($request_id, $rec);
      }
      if (!$rec) {
        return ['ok' => false, 'result' => null, 'error' => 'Could not record the request on the site.', 'errorCode' => 'storage', 'duplicate' => false, 'reported' => false];
      }
      // Already ran: make sure Visibility has the outcome, never run it again.
      $reported = !empty($rec['reported']) || self::report($request_id, $rec);
      return ['ok' => (bool) $rec['ok'], 'result' => $rec['result'], 'error' => $rec['error'], 'errorCode' => $rec['errorCode'] ?? null, 'duplicate' => true, 'reported' => $reported];
    }

    list($ok, $result, $err) = Visibility_Executor::execute($action_type, $payload);
    $rec = self::get_record($request_id) ?: [];
    $rec = array_merge($rec, [
      'state'      => 'done',
      'ok'         => (bool) $ok,
      'result'     => $result,
      'error'      => $err,
      'errorCode'  => $ok ? null : Visibility_Executor::last_error_code(),
      'finishedAt' => time(),
      'reported'   => false,
    ]);
    self::put_record($request_id, $rec);
    $reported = self::report($request_id, $rec);
    return ['ok' => (bool) $ok, 'result' => $result, 'error' => $err, 'errorCode' => $rec['errorCode'], 'duplicate' => false, 'reported' => $reported];
  }

  /** Send the stored outcome to Visibility; marks the record reported on success. */
  private static function report($request_id, array $rec) {
    $resp = Visibility_Client::report_execution($request_id, (bool) $rec['ok'], $rec['result'], $rec['error']);
    if (is_wp_error($resp)) {
      $rec['reportAttempts'] = (int) ($rec['reportAttempts'] ?? 0) + 1;
      self::put_record($request_id, $rec);
      return false;
    }
    $rec['reported'] = true;
    self::put_record($request_id, $rec);
    return true;
  }

  public static function retry_unreported() {
    foreach (self::all_records() as $id => $rec) {
      if ($rec['state'] === 'done' && empty($rec['reported'])) {
        self::report($id, $rec);
      }
    }
  }

  private static function option_name($request_id) {
    return self::RECORD_PREFIX . md5($request_id);
  }

  /** Atomic claim. True only for the one caller that inserted the row. */
  private static function claim($request_id, $action_type) {
    global $wpdb;
    $value = maybe_serialize([
      'id' => $request_id, 'actionType' => (string) $action_type,
      'state' => 'running', 'claimedAt' => time(),
    ]);
    $inserted = $wpdb->query($wpdb->prepare(
      "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
      self::option_name($request_id), $value
    ));
    wp_cache_delete('notoptions', 'options');
    return $inserted === 1;
  }

  // Records are read and written with $wpdb directly: two processes race on
  // them, so a persistent object cache must not serve a stale copy.

  public static function get_record($request_id) {
    global $wpdb;
    $raw = $wpdb->get_var($wpdb->prepare(
      "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::option_name($request_id)
    ));
    if ($raw === null) return null;
    $rec = maybe_unserialize($raw);
    return is_array($rec) ? $rec : null;
  }

  private static function put_record($request_id, array $rec) {
    global $wpdb;
    $wpdb->update($wpdb->options, ['option_value' => maybe_serialize($rec)], ['option_name' => self::option_name($request_id)]);
    wp_cache_delete(self::option_name($request_id), 'options');
  }

  private static function all_records() {
    global $wpdb;
    $rows = $wpdb->get_results($wpdb->prepare(
      "SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
      $wpdb->esc_like(self::RECORD_PREFIX) . '%'
    ));
    $out = [];
    foreach ((array) $rows as $row) {
      $rec = maybe_unserialize($row->option_value);
      if (is_array($rec) && isset($rec['id'])) $out[$rec['id']] = $rec;
    }
    return $out;
  }

  /** Forget finished, reported records after KEEP_FOR. */
  public static function prune() {
    global $wpdb;
    foreach (self::all_records() as $id => $rec) {
      $at = (int) ($rec['finishedAt'] ?? $rec['claimedAt'] ?? 0);
      if (!empty($rec['reported']) && $at > 0 && time() - $at > self::KEEP_FOR) {
        $wpdb->delete($wpdb->options, ['option_name' => self::option_name($id)]);
      }
    }
  }

}
