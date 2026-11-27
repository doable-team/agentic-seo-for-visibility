<?php
/**
 * Request limits (#565): the largest body Visibility may send, how many blocks
 * one post write may hold, how many edits one edit_parts may make, and how
 * many requests a minute the site answers. Over a limit the answer is a clear
 * JSON error: 413 visibility_too_large or 429 visibility_rate_limited (with a
 * Retry-After header). Each limit is filterable for unusual sites.
 */

if (!defined('ABSPATH')) {
  exit;
}

class Visibility_Limits {

  const MAX_BODY_BYTES = 2097152; // 2 MB
  const MAX_BLOCKS = 2000;
  const MAX_EDITS = 100;
  const PER_MINUTE = 300;
  const RATE_PREFIX = 'visibility_rate_';

  public static function max_body() {
    return (int) apply_filters('visibility_max_body_bytes', self::MAX_BODY_BYTES);
  }
  public static function max_blocks() {
    return (int) apply_filters('visibility_max_blocks', self::MAX_BLOCKS);
  }
  public static function max_edits() {
    return (int) apply_filters('visibility_max_edits', self::MAX_EDITS);
  }
  public static function per_minute() {
    return (int) apply_filters('visibility_rate_limit', self::PER_MINUTE);
  }

  public static function init() {
    add_filter('rest_post_dispatch', [__CLASS__, 'retry_after_header'], 10, 3);
  }

  /** Body size and rate, for a request that passed authentication. true or WP_Error. */
  public static function check($request) {
    $len = strlen((string) $request->get_body());
    $max = self::max_body();
    if ($max > 0 && $len > $max) {
      return new WP_Error('visibility_too_large', sprintf(
        /* translators: 1: body size in MB, 2: limit in MB. */
        __('The request is %1$s MB; this site accepts at most %2$s MB from Visibility in one request. Send less at once (fewer or smaller parts).', 'agentic-seo-visibility'),
        number_format_i18n($len / 1048576, 1), number_format_i18n($max / 1048576, 1)
      ), ['status' => 413, 'limit' => $max]);
    }
    return self::count_request();
  }

  /** Counts this request in the current minute; WP_Error 429 over the limit. */
  private static function count_request() {
    global $wpdb;
    $per = self::per_minute();
    if ($per <= 0) return true;
    $minute = (int) floor(time() / 60);
    $name = self::RATE_PREFIX . $minute;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- an atomic counter shared by every PHP process.
    $wpdb->query($wpdb->prepare(
      "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, '1', 'no') ON DUPLICATE KEY UPDATE option_value = option_value + 1",
      $name
    ));
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- read back the shared counter.
    $n = (int) $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name));
    if ($n === 1) self::prune($minute);
    if ($n > $per) {
      $retry = max(1, 60 - (time() % 60));
      return new WP_Error('visibility_rate_limited', sprintf(
        /* translators: 1: requests per minute, 2: seconds. */
        __('Too many requests: this site answers at most %1$d requests a minute from Visibility. Try again in %2$d seconds.', 'agentic-seo-visibility'),
        $per, $retry
      ), ['status' => 429, 'retryAfter' => $retry, 'limit' => $per]);
    }
    return true;
  }

  private static function prune($minute) {
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- remove old minute counters.
    $rows = $wpdb->get_col($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like(self::RATE_PREFIX) . '%'));
    foreach ((array) $rows as $o) {
      if ((int) substr($o, strlen(self::RATE_PREFIX)) < $minute - 1) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- remove old minute counters.
        $wpdb->delete($wpdb->options, ['option_name' => $o]);
      }
    }
  }

  public static function retry_after_header($response, $server, $request) {
    if ($response instanceof WP_REST_Response && $response->get_status() === 429) {
      $data = $response->get_data();
      if (is_array($data) && ($data['code'] ?? '') === 'visibility_rate_limited' && !empty($data['data']['retryAfter'])) {
        $response->header('Retry-After', (string) (int) $data['data']['retryAfter']);
      }
    }
    return $response;
  }

  /** Block count of content (every depth); WP_Error 413 when over the limit, else null. */
  public static function check_blocks($content) {
    $content = (string) $content;
    if (strpos($content, '<!-- wp:') === false) return null;
    $n = preg_match_all('/<!--\s+wp:[a-z]/', $content);
    $max = self::max_blocks();
    if ($max <= 0 || $n <= $max) return null;
    return new WP_Error('visibility_too_large', sprintf(
      /* translators: 1: number of blocks, 2: limit. */
      __('The content has %1$d blocks; one post written by Visibility may have at most %2$d.', 'agentic-seo-visibility'),
      $n, $max
    ), ['status' => 413, 'limit' => $max]);
  }

  /** WP_Error 413 when one edit_parts asks too many edits, else null. */
  public static function check_edits($edits) {
    $max = self::max_edits();
    $n = is_array($edits) ? count($edits) : 0;
    if ($max <= 0 || $n <= $max) return null;
    return new WP_Error('visibility_too_large', sprintf(
      /* translators: 1: number of edits, 2: limit. */
      __('%1$d edits were sent at once; one change may make at most %2$d. Split it into several changes.', 'agentic-seo-visibility'),
      $n, $max
    ), ['status' => 413, 'limit' => $max]);
  }

  /** /capabilities `limits`. */
  public static function describe() {
    return ['maxBodyBytes' => self::max_body(), 'maxBlocks' => self::max_blocks(), 'maxEdits' => self::max_edits(), 'perMinute' => self::per_minute()];
  }
}
