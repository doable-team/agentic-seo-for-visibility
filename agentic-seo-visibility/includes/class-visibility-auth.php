<?php
/**
 * How Visibility's calls to this site are authenticated (#296).
 *
 * The site token from pairing is the shared secret. Visibility sends it as
 * `Authorization: Bearer <token>` (every version) and, from plugin 2.0.0 on,
 * also signs every request ("signing v1"):
 *
 *   X-Visibility-Timestamp: unix seconds
 *   X-Visibility-Nonce:     16-64 chars of [A-Za-z0-9_-], new for every request
 *   X-Visibility-Signature: v1=<hex HMAC-SHA256(token, canonical)>
 *
 *   canonical = "v1\n" METHOD "\n" ROUTE "\n" QUERY "\n" TIMESTAMP "\n" NONCE "\n" BODYHASH
 *     METHOD   the logical method (after X-HTTP-Method-Override), upper case
 *     ROUTE    "/visibility/v1/…" without the query, the same for pretty URLs
 *              and ?rest_route=
 *     QUERY    the query parameters except rest_route and _method, sorted by
 *              key, rawurlencode(key)=rawurlencode(value) joined with "&"
 *     BODYHASH hex SHA-256 of the exact body bytes
 *
 * A signed request is accepted within 300 seconds of its timestamp and only
 * once (nonces are remembered for 600 seconds). Bearer-only requests keep
 * working until signatures are required: by Visibility when it rotates the
 * key with requireSignature, or by the owner in WP admin.
 *
 * Key rotation: POST /auth/rotate returns a new token. Until Visibility uses
 * the new token once, both work (asking again returns the same new token, so
 * a lost answer never locks Visibility out); the first request made with the
 * new token makes it the only one.
 */

if (!defined('ABSPATH')) {
  exit;
}

class Visibility_Auth {

  const PENDING = 'visibility_site_token_pending';
  const REQUIRE = 'visibility_require_signature';
  const ROTATED = 'visibility_token_rotated_at';
  const NONCE_PREFIX = 'visibility_nonce_';
  const WINDOW = 300;
  const NONCE_TTL = 600;

  /** @return true|WP_Error */
  public static function check($request) {
    $current = (string) Visibility_Client::site_token();
    $pending = (string) get_option(self::PENDING, '');
    if ($current === '') {
      return new WP_Error('visibility_bad_token', __('Invalid site token.', 'agentic-seo-visibility'), ['status' => 401]);
    }
    $header = (string) $request->get_header('authorization');
    $bearer = stripos($header, 'Bearer ') === 0 ? trim(substr($header, 7)) : '';
    $sig = (string) $request->get_header('x_visibility_signature');

    if ($sig === '') {
      if ($bearer === '') {
        return new WP_Error('visibility_no_auth', __('Missing bearer token.', 'agentic-seo-visibility'), ['status' => 401]);
      }
      $used = self::which($bearer, $current, $pending);
      if ($used === null) {
        return new WP_Error('visibility_bad_token', __('Invalid site token.', 'agentic-seo-visibility'), ['status' => 401]);
      }
      if (self::required()) {
        return new WP_Error('visibility_signature_required', __('This site only accepts signed requests from Visibility.', 'agentic-seo-visibility'), ['status' => 401]);
      }
      if ($used === 'pending') self::promote();
      return true;
    }

    $ts = (string) $request->get_header('x_visibility_timestamp');
    $nonce = (string) $request->get_header('x_visibility_nonce');
    if (!preg_match('/^\d{9,11}$/', $ts) || !preg_match('/^[A-Za-z0-9_-]{16,64}$/', $nonce) || strpos($sig, 'v1=') !== 0) {
      return new WP_Error('visibility_bad_signature', __('The request signature is malformed.', 'agentic-seo-visibility'), ['status' => 401]);
    }
    if (abs(time() - (int) $ts) > self::WINDOW) {
      return new WP_Error('visibility_stale_request', __('The request is too old or the clock is off by more than 5 minutes.', 'agentic-seo-visibility'), ['status' => 401]);
    }
    $canonical = self::canonical($request, $ts, $nonce);
    $given = strtolower(substr($sig, 3));
    $used = null;
    foreach (['current' => $current, 'pending' => $pending] as $name => $key) {
      if ($key !== '' && hash_equals(hash_hmac('sha256', $canonical, $key), $given)) { $used = $name; break; }
    }
    if ($used === null) {
      return new WP_Error('visibility_bad_signature', __('The request signature does not match.', 'agentic-seo-visibility'), ['status' => 401]);
    }
    // A bearer sent along must be the same key.
    if ($bearer !== '' && !hash_equals($used === 'current' ? $current : $pending, $bearer)) {
      return new WP_Error('visibility_bad_token', __('Invalid site token.', 'agentic-seo-visibility'), ['status' => 401]);
    }
    if (!self::remember_nonce($nonce)) {
      return new WP_Error('visibility_replayed', __('This request was already received once.', 'agentic-seo-visibility'), ['status' => 401]);
    }
    if ($used === 'pending') self::promote();
    return true;
  }

  /** "current", "pending" or null. */
  private static function which($token, $current, $pending) {
    if (hash_equals($current, $token)) return 'current';
    if ($pending !== '' && hash_equals($pending, $token)) return 'pending';
    return null;
  }

  public static function canonical($request, $ts, $nonce) {
    $params = (array) $request->get_query_params();
    unset($params['rest_route'], $params['_method']);
    ksort($params, SORT_STRING);
    $q = [];
    foreach ($params as $k => $v) {
      if (is_array($v)) $v = implode(',', array_map('strval', $v));
      $q[] = rawurlencode((string) $k) . '=' . rawurlencode((string) $v);
    }
    return implode("\n", [
      'v1',
      strtoupper((string) $request->get_method()),
      (string) $request->get_route(),
      implode('&', $q),
      $ts,
      $nonce,
      hash('sha256', (string) $request->get_body()),
    ]);
  }

  /** Atomic: true only the first time a nonce is seen. */
  private static function remember_nonce($nonce) {
    global $wpdb;
    $name = self::NONCE_PREFIX . md5($nonce);
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $inserted = $wpdb->query($wpdb->prepare(
      "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
      $name, (string) (time() + self::NONCE_TTL)
    ));
    if ($inserted !== 1) {
      // Seen before; an expired record is reused once.
      // phpcs:ignore WordPress.DB.DirectDatabaseQuery
      $exp = (int) $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name));
      if ($exp > time()) return false;
      // phpcs:ignore WordPress.DB.DirectDatabaseQuery
      $n = $wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", (string) (time() + self::NONCE_TTL), $name, (string) $exp));
      if ($n !== 1) return false;
    }
    if (wp_rand(1, 50) === 1) self::prune_nonces();
    return true;
  }

  public static function prune_nonces() {
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $wpdb->query($wpdb->prepare(
      "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) < %d",
      $wpdb->esc_like(self::NONCE_PREFIX) . '%', time()
    ));
  }

  public static function required() {
    return get_option(self::REQUIRE, '') === '1';
  }

  public static function set_required($on) {
    update_option(self::REQUIRE, $on ? '1' : '0', false);
  }

  /** POST /auth/rotate {requireSignature?}: the new token (the same one until it is used). */
  public static function rotate($require = null) {
    $pending = (string) get_option(self::PENDING, '');
    if ($pending === '') {
      $pending = wp_generate_password(64, false, false);
      update_option(self::PENDING, $pending, false);
      update_option(self::ROTATED, time(), false);
    }
    if ($require !== null) self::set_required((bool) $require);
    return [
      'ok'               => true,
      'siteToken'        => $pending,
      'requireSignature' => self::required(),
      'rotatedAt'        => gmdate('Y-m-d\TH:i:s\Z', (int) get_option(self::ROTATED, time())),
    ];
  }

  /** The new token was used: it becomes the only one; the plugin's own calls switch to it. */
  private static function promote() {
    $pending = (string) get_option(self::PENDING, '');
    if ($pending === '') return;
    update_option('visibility_site_token', $pending, false);
    delete_option(self::PENDING);
    update_option(self::ROTATED, time(), false);
    // Tell Visibility with the new token, after this request is answered.
    Visibility_Client::after_response(['Visibility_Client', 'heartbeat']);
  }

  /** /capabilities `auth`. */
  public static function describe() {
    $at = (int) get_option(self::ROTATED, 0);
    return [
      'signing'          => 'v1',
      'requireSignature' => self::required(),
      'rotatedAt'        => $at ? gmdate('Y-m-d\TH:i:s\Z', $at) : null,
      'pendingRotation'  => get_option(self::PENDING, '') !== '',
      'window'           => self::WINDOW,
    ];
  }

  /** Forget everything on disconnect. */
  public static function forget() {
    delete_option(self::PENDING);
    delete_option(self::REQUIRE);
    delete_option(self::ROTATED);
    self::prune_nonces();
  }
}
