<?php
/**
 * Redirects (#292, #270, #271, C-31 to C-36).
 *
 * One interface over the redirect plugins a site may have, plus a small store
 * of the plugin's own for sites without one:
 *
 *   redirection  Redirection (free): Red_Item / Red_Group, in a group the site
 *                picks or a "Visibility" group the plugin finds or creates.
 *   rankmath     Rank Math redirections module: \RankMath\Redirections\DB.
 *   yoast        Yoast SEO Premium redirect manager: WPSEO_Redirect_Manager.
 *   aioseo       All in One SEO Pro, Redirection Manager add-on: its Redirect
 *                model (aioseo_redirects table).
 *   seopress     SEOPress Pro: its redirection post type seopress_404.
 *   srm          Safe Redirect Manager: srm_create_redirect(), redirect_rule posts.
 *   visibility   the plugin's own store (option visibility_redirects), served
 *                on 404s only, so it never hides a real page.
 *
 * Every redirect is described as {id, source, target, type, enabled, regex,
 * provider, group?}. `source` is a path from the domain root ("/old-page/"),
 * `target` a path on this site or an absolute URL elsewhere. Before a redirect
 * is created, every known rule is checked: an existing rule for the source is
 * a conflict, a chain back to the source is a loop.
 */

if (!defined('ABSPATH')) {
  exit;
}

class Visibility_Redirects {

  const STORE = 'visibility_redirects';
  const GROUP_OPTION = 'visibility_redirection_group';

  const PROVIDERS = [
    'redirection' => ['Redirection', [301, 302, 307, 308, 410]],
    'rankmath'    => ['Rank Math redirections', [301, 302, 307, 410, 451]],
    'yoast'       => ['Yoast SEO Premium redirects', [301, 302, 307, 410, 451]],
    'aioseo'      => ['All in One SEO redirects', [301, 302, 307, 308, 410]],
    'seopress'    => ['SEOPress Pro redirections', [301, 302, 307]],
    'srm'         => ['Safe Redirect Manager', [301, 302, 307, 410]],
    'visibility'  => ['Visibility (built in)', [301, 302, 307, 308, 410]],
  ];

  /** Links between a post change and the redirects it added or removed (for rollback). */
  public static $linked = [];

  public static function init() {
    add_action('template_redirect', [__CLASS__, 'serve_own'], 0);
    add_action('visibility_rollback_custom', [__CLASS__, 'rollback_custom'], 10, 3);
    add_action('visibility_after_rollback', [__CLASS__, 'after_post_rollback'], 10, 2);
  }

  // ─── Providers ─────────────────────────────────────────────────────────

  /** Slugs of the redirect plugins active on this site, in the order they are preferred. */
  public static function active_providers() {
    $out = [];
    if (defined('REDIRECTION_VERSION') && class_exists('Red_Item') && class_exists('Red_Group')) $out[] = 'redirection';
    if (defined('RANK_MATH_VERSION') && class_exists('RankMath\\Redirections\\DB') && self::rankmath_module_on()) $out[] = 'rankmath';
    if (class_exists('WPSEO_Redirect_Manager') && class_exists('WPSEO_Redirect')) $out[] = 'yoast';
    if (function_exists('aioseoRedirects') && class_exists('AIOSEO\\Plugin\\Addon\\Redirects\\Models\\Redirect')) $out[] = 'aioseo';
    if (defined('SEOPRESS_PRO_VERSION') && post_type_exists('seopress_404')) $out[] = 'seopress';
    if (function_exists('srm_create_redirect')) $out[] = 'srm';
    return $out;
  }

  /** Rank Math loads its redirect classes only while its Redirections module is on. */
  private static function rankmath_module_on() {
    if (class_exists('RankMath\\Helper') && method_exists('RankMath\\Helper', 'is_module_active')) {
      return (bool) \RankMath\Helper::is_module_active('redirections');
    }
    return in_array('redirections', (array) get_option('rank_math_modules', []), true);
  }

  /** The provider new redirects go to: the first redirect plugin, else the built-in store. */
  public static function provider() {
    $p = self::active_providers();
    return $p ? $p[0] : 'visibility';
  }

  public static function provider_version($slug) {
    switch ($slug) {
      case 'redirection': return defined('REDIRECTION_VERSION') ? (string) REDIRECTION_VERSION : null;
      case 'rankmath':    return defined('RANK_MATH_VERSION') ? (string) RANK_MATH_VERSION : null;
      case 'yoast':       return defined('WPSEO_PREMIUM_VERSION') ? (string) WPSEO_PREMIUM_VERSION : null;
      case 'aioseo':      return defined('AIOSEO_REDIRECTION_MANAGER_VERSION') ? (string) AIOSEO_REDIRECTION_MANAGER_VERSION : null;
      case 'seopress':    return defined('SEOPRESS_PRO_VERSION') ? (string) SEOPRESS_PRO_VERSION : null;
      case 'srm':         return defined('SRM_VERSION') ? (string) SRM_VERSION : null;
      case 'visibility':  return VISIBILITY_PLUGIN_VERSION;
    }
    return null;
  }

  /** /capabilities `redirectStore`. */
  public static function describe() {
    $p = self::provider();
    $out = [
      'provider' => $p,
      'name'     => self::PROVIDERS[$p][0],
      'version'  => self::provider_version($p),
      'builtIn'  => $p === 'visibility',
      'types'    => self::PROVIDERS[$p][1],
      'plugins'  => self::active_providers(),
      'auto'     => true,
      'loopCheck' => true,
    ];
    if ($p === 'redirection') $out['groups'] = self::redirection_groups();
    return $out;
  }

  /** The 0.10.0 `redirects` entry: the redirect plugin, or null without one (unchanged shape). */
  public static function describe_legacy() {
    $p = self::active_providers();
    if (!$p) return null;
    return ['slug' => $p[0], 'name' => self::PROVIDERS[$p[0]][0], 'version' => self::provider_version($p[0])];
  }

  // ─── Paths ─────────────────────────────────────────────────────────────

  private static function home_parts() {
    $h = wp_parse_url(home_url('/'));
    return [isset($h['host']) ? strtolower($h['host']) : '', isset($h['path']) ? $h['path'] : '/'];
  }

  /** Path from the domain root for a path or same-site URL; null for another site. */
  public static function to_path($url) {
    $url = trim((string) $url);
    if ($url === '') return null;
    if (preg_match('#^(https?:)?//#i', $url)) {
      $u = wp_parse_url($url);
      list($host) = self::home_parts();
      if (empty($u['host']) || strtolower($u['host']) !== $host) return null;
      $path = isset($u['path']) ? $u['path'] : '/';
      if (!empty($u['query'])) $path .= '?' . $u['query'];
      return $path;
    }
    $url = preg_replace('/#.*$/', '', $url);
    return '/' . ltrim($url, '/');
  }

  /** Comparison key: decoded, lower case, no trailing slash, no query. */
  public static function key($path) {
    $path = (string) $path;
    $path = explode('?', $path)[0];
    $path = strtolower(rawurldecode($path));
    $path = '/' . trim($path, '/');
    return $path;
  }

  /** Path relative to the home URL (no slashes around), as Rank Math, Yoast and SEOPress store it. */
  private static function relative($path) {
    list(, $home) = self::home_parts();
    $home = '/' . trim($home, '/');
    if ($home !== '/' && stripos($path, $home . '/') === 0) $path = substr($path, strlen($home));
    elseif ($home !== '/' && strtolower(rtrim($path, '/')) === strtolower($home)) $path = '/';
    return trim($path, '/');
  }

  /** Back from a home-relative path to a path from the domain root. */
  private static function from_relative($rel) {
    list(, $home) = self::home_parts();
    $rel = (string) $rel;
    if (preg_match('#^https?://#i', $rel)) {
      $p = self::to_path($rel);
      return $p === null ? $rel : $p;
    }
    return rtrim($home, '/') . '/' . ltrim($rel, '/');
  }

  /** Same, with the trailing slash the site's permalinks use (plugins that store paths trimmed). */
  private static function from_relative_slash($rel) {
    $p = self::from_relative($rel);
    if (preg_match('#^https?://#i', $p) || $p === '/' || strpos($p, '?') !== false || preg_match('#\.[a-z0-9]{2,5}$#i', $p)) return $p;
    return user_trailingslashit(rtrim($p, '/'));
  }

  /** Absolute URL for an internal path (for plugins that want one). */
  private static function absolute($target) {
    if (preg_match('#^https?://#i', $target)) return $target;
    $h = wp_parse_url(home_url('/'));
    return (isset($h['scheme']) ? $h['scheme'] : 'http') . '://' . $h['host'] . (isset($h['port']) ? ':' . $h['port'] : '') . $target;
  }

  /** Clean an asked target: same-site → path, other site → absolute URL, or WP_Error. */
  private static function clean_target($target, $type) {
    if ((int) $type === 410 || (int) $type === 451) return '';
    $target = trim((string) $target);
    if ($target === '') return new WP_Error('invalid', 'target is required (a path on this site such as /new-page/ or a full URL).');
    if (preg_match('#^https?://#i', $target)) {
      $clean = esc_url_raw($target, ['http', 'https']);
      if ($clean === '' || !wp_http_validate_url($clean) && !preg_match('#^https?://[^/\s]+#i', $clean)) return new WP_Error('invalid', 'target is not a valid URL.');
      $p = self::to_path($clean);
      return $p === null ? $clean : $p;
    }
    if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $target) || strpos($target, '//') === 0) return new WP_Error('invalid', 'target must be a path on this site or an http(s) URL.');
    $path = self::to_path($target);
    if (preg_match('/[\s<>"\']/', $path)) return new WP_Error('invalid', 'target must not contain spaces, quotes or angle brackets.');
    return $path;
  }

  private static function clean_source($source) {
    $source = trim((string) $source);
    if ($source === '') return new WP_Error('invalid', 'source is required (the old address, for example /old-page/).');
    $path = self::to_path($source);
    if ($path === null) return new WP_Error('invalid', 'source must be an address on this site.');
    if (preg_match('/[\s<>"\']/', $path)) return new WP_Error('invalid', 'source must not contain spaces, quotes or angle brackets.');
    if (self::key($path) === '/') return new WP_Error('invalid', 'The home page cannot be redirected by Visibility.');
    if (strpos(self::key($path), '/wp-admin') === 0 || strpos(self::key($path), '/wp-json') === 0 || strpos(self::key($path), '/wp-login') === 0) {
      return new WP_Error('invalid', 'WordPress system addresses cannot be redirected.');
    }
    return $path;
  }

  // ─── Read ──────────────────────────────────────────────────────────────

  /** Every rule of the provider plus the built-in store, normalised. */
  public static function all() {
    $out = [];
    foreach (array_unique(array_merge(self::active_providers(), ['visibility'])) as $p) {
      foreach (call_user_func([__CLASS__, 'list_' . $p]) as $r) $out[] = $r;
    }
    return $out;
  }

  /** GET /redirects?source=&search=&page=&per_page= */
  public static function query($source = '', $search = '', $page = 1, $per = 50) {
    $items = self::all();
    if ($source !== '') {
      $want = self::to_path($source);
      $k = $want === null ? '' : self::key($want);
      $items = array_values(array_filter($items, function ($r) use ($k) { return !$r['regex'] && self::key($r['source']) === $k; }));
    }
    if ($search !== '') {
      $s = strtolower($search);
      $items = array_values(array_filter($items, function ($r) use ($s) { return strpos(strtolower($r['source'] . ' ' . $r['target']), $s) !== false; }));
    }
    $total = count($items);
    $per = max(1, min(100, (int) $per));
    $page = max(1, (int) $page);
    $p = self::provider();
    $out = [
      'provider'   => ['slug' => $p, 'name' => self::PROVIDERS[$p][0]],
      'items'      => array_slice($items, ($page - 1) * $per, $per),
      'total'      => $total,
      'page'       => $page,
      'perPage'    => $per,
      'totalPages' => (int) ceil($total / $per),
    ];
    if ($p === 'redirection') $out['groups'] = self::redirection_groups();
    return $out;
  }

  private static function item($provider, $id, $source, $target, $type, $enabled, $regex, $extra = []) {
    return [
      'id'       => (string) $id,
      'source'   => (string) $source,
      'target'   => (string) $target,
      'type'     => (int) $type,
      'enabled'  => (bool) $enabled,
      'regex'    => (bool) $regex,
      'provider' => $provider,
    ] + $extra;
  }

  /** The rule for a source, or null. */
  public static function find_source($path) {
    $k = self::key($path);
    foreach (self::all() as $r) {
      if (!$r['regex'] && self::key($r['source']) === $k) return $r;
    }
    return null;
  }

  public static function find_id($provider, $id) {
    foreach (call_user_func([__CLASS__, 'list_' . $provider]) as $r) {
      if ($r['id'] === (string) $id) return $r;
    }
    return null;
  }

  /**
   * Loop check for a new source → target. Returns [error|null, warning|null].
   * A chain that comes back to the source is an error; a target that itself
   * redirects on is only a warning.
   */
  public static function loop_check($source, $target, $ignore_id = null) {
    $sk = self::key($source);
    if ($target === '') return [null, null];
    if (preg_match('#^https?://#i', $target)) return [null, null]; // another site
    $tk = self::key($target);
    if ($tk === $sk) return ['The target is the same address as the source, so the redirect would loop.', null];
    $map = [];
    foreach (self::all() as $r) {
      if ($r['regex'] || !$r['enabled'] || ($ignore_id !== null && $r['provider'] . ':' . $r['id'] === $ignore_id)) continue;
      if ($r['target'] === '' || preg_match('#^https?://#i', $r['target'])) continue;
      $map[self::key($r['source'])] = self::key($r['target']);
    }
    $seen = [$sk => true];
    $chain = [$source, $target];
    $at = $tk;
    for ($i = 0; $i < 20 && isset($map[$at]); $i++) {
      $at = $map[$at];
      $chain[] = $at;
      if (isset($seen[$at]) || $at === $tk) {
        return [sprintf('This redirect would make a loop: %s.', implode(' → ', $chain)), null];
      }
      $seen[$at] = true;
    }
    if (isset($map[$tk])) {
      return [null, sprintf('%s already redirects on to %s, so visitors take two steps. Consider pointing straight at the final address.', $target, $map[$tk])];
    }
    return [null, null];
  }

  // ─── Write ─────────────────────────────────────────────────────────────

  /**
   * Create a redirect after the conflict and loop checks.
   * @return array|WP_Error the redirect + loopWarning?
   */
  public static function create($source, $target, $type = 301, $group = null, $provider = null) {
    $type = (int) ($type ?: 301);
    $provider = $provider ?: self::provider();
    if (!in_array($type, self::PROVIDERS[$provider][1], true)) {
      return new WP_Error('invalid', sprintf('%s cannot make a %d redirect. Types it supports: %s.', self::PROVIDERS[$provider][0], $type, implode(', ', self::PROVIDERS[$provider][1])));
    }
    $source = self::clean_source($source);
    if (is_wp_error($source)) return $source;
    $target = self::clean_target($target, $type);
    if (is_wp_error($target)) return $target;
    $existing = self::find_source($source);
    if ($existing) {
      return new WP_Error('conflict', sprintf('%s already redirects to %s (%s, id %s). Delete that redirect first or choose another address.', $existing['source'], $existing['target'] !== '' ? $existing['target'] : $existing['type'], self::PROVIDERS[$existing['provider']][0], $existing['id']), ['existing' => $existing]);
    }
    list($err, $warn) = self::loop_check($source, $target);
    if ($err) return new WP_Error('invalid', $err);
    $r = call_user_func([__CLASS__, 'create_' . $provider], $source, $target, $type, $group);
    if (is_wp_error($r)) return $r;
    if ($warn) $r['loopWarning'] = $warn;
    return $r;
  }

  /** @return array|WP_Error the deleted redirect */
  public static function delete($id, $provider = null) {
    $id = (string) $id;
    if ($id === '') return new WP_Error('invalid', 'redirectId required');
    $providers = $provider ? [$provider] : array_unique(array_merge(self::active_providers(), ['visibility']));
    // Ids of the built-in store start with "v"; others are the plugin's own ids.
    if (!$provider && preg_match('/^v\d+$/', $id)) $providers = ['visibility'];
    foreach ($providers as $p) {
      $r = self::find_id($p, $id);
      if (!$r) continue;
      $ok = call_user_func([__CLASS__, 'delete_' . $p], $id);
      if (is_wp_error($ok)) return $ok;
      if (self::find_id($p, $id)) return new WP_Error('not_applied', sprintf('%s did not delete redirect %s.', self::PROVIDERS[$p][0], $id));
      return $r;
    }
    return new WP_Error('not_found', sprintf('No redirect with id %s on this site.', $id));
  }

  // ─── Executor actions ─────────────────────────────────────────────────

  /** redirect.create {source|fromPath, target|toPath, type?, group?} */
  public static function action_create($payload) {
    $source = isset($payload['source']) ? $payload['source'] : (isset($payload['fromPath']) ? $payload['fromPath'] : '');
    $target = isset($payload['target']) ? $payload['target'] : (isset($payload['toPath']) ? $payload['toPath'] : '');
    $r = self::create($source, $target, isset($payload['type']) ? (int) $payload['type'] : 301, isset($payload['group']) ? $payload['group'] : null);
    if (is_wp_error($r)) return $r;
    $change = self::record('redirect.create', null, $r);
    return ['redirectId' => $r['id'], 'source' => $r['source'], 'target' => $r['target'], 'type' => $r['type'], 'provider' => $r['provider'], 'plugin' => $r['provider'], 'redirect' => $r, 'changeId' => $change] + (isset($r['loopWarning']) ? ['loopWarning' => $r['loopWarning']] : []);
  }

  /** redirect.delete {redirectId} */
  public static function action_delete($payload) {
    $id = isset($payload['redirectId']) ? (string) $payload['redirectId'] : '';
    $r = self::delete($id);
    if (is_wp_error($r)) return $r;
    $change = self::record('redirect.delete', $r, null);
    return ['redirectId' => $r['id'], 'deleted' => ['source' => $r['source'], 'target' => $r['target'], 'type' => $r['type']], 'provider' => $r['provider'], 'changeId' => $change];
  }

  private static function record($action, $before, $after) {
    if (!class_exists('Visibility_Changes')) return null;
    $r = $after ?: $before;
    return Visibility_Changes::record_custom($action, ['redirect:' . $r['source']], ['redirect' => $before ? [$r['source'] => $before] : []], ['redirect' => $after ? [$r['source'] => $after] : []]);
  }

  /** Undo a redirect.create / redirect.delete change. */
  public static function rollback_custom($result, $row, $states) {
    if (!in_array($row->action, ['redirect.create', 'redirect.delete'], true)) return;
    list($before, $after) = $states;
    if ($row->action === 'redirect.create') {
      $a = reset($after['redirect']);
      $now = self::find_id($a['provider'], $a['id']);
      if (!$now || self::key($now['source']) !== self::key($a['source']) || $now['target'] !== $a['target'] || $now['type'] !== $a['type']) {
        $result->error = new WP_Error('conflict', 'The redirect was changed or removed after this change, so it was not rolled back.');
        return;
      }
      $d = self::delete($a['id'], $a['provider']);
      if (is_wp_error($d)) { $result->error = $d; return; }
      $result->data = ['redirectId' => $a['id'], 'deleted' => true];
    } else {
      $b = reset($before['redirect']);
      if (self::find_source($b['source'])) {
        $result->error = new WP_Error('conflict', sprintf('%s has a redirect again, so the deleted one was not put back.', $b['source']));
        return;
      }
      $provider = in_array($b['provider'], array_merge(self::active_providers(), ['visibility']), true) ? $b['provider'] : null;
      $c = self::create($b['source'], $b['target'], $b['type'], isset($b['groupId']) ? $b['groupId'] : null, $provider);
      if (is_wp_error($c)) { $result->error = $c; return; }
      $result->data = ['redirectId' => $c['id'], 'restored' => $c];
    }
  }

  // ─── Automatic redirect when a published address changes ─────────────

  /**
   * Add a 301 from the old address to the new one. Called after the slug
   * changed. Returns the keys for the action result: redirectAdded, or
   * redirectSkipped with the reason; redirectRemoved when a rule on the new
   * address had to go (renaming back) so it would not loop.
   */
  public static function auto($old_url, $new_url) {
    $from = self::to_path($old_url);
    $to = self::to_path($new_url);
    if ($from === null || $to === null || self::key($from) === self::key($to)) return [];
    if (Visibility_Policy::controls() !== null && Visibility_Policy::check_controls(['redirects'])) {
      return ['redirectSkipped' => 'The site owner has not allowed redirects, so no redirect was added for the old address.'];
    }
    $out = [];
    $self = self::find_source($to);
    if ($self && !$self['regex']) {
      // The new address redirected away (to the old one, when renaming back): remove it.
      $d = self::delete($self['id'], $self['provider']);
      if (!is_wp_error($d)) {
        $out['redirectRemoved'] = ['redirectId' => $d['id'], 'source' => $d['source'], 'target' => $d['target'], 'type' => $d['type']];
        self::$linked[] = ['op' => 'removed', 'redirect' => $d];
      }
    }
    $existing = self::find_source($from);
    if ($existing) {
      return $out + ['redirectSkipped' => sprintf('%s already has a redirect (to %s), so it was left as it is.', $existing['source'], $existing['target'])];
    }
    $r = self::create($from, $to, 301);
    if (is_wp_error($r)) return $out + ['redirectSkipped' => $r->get_error_message()];
    self::$linked[] = ['op' => 'added', 'redirect' => $r];
    return $out + ['redirectAdded' => ['redirectId' => $r['id'], 'source' => $r['source'], 'target' => $r['target'], 'type' => 301, 'provider' => $r['provider']]];
  }

  /** Rolling back a post change also undoes the redirects it added or removed. */
  public static function after_post_rollback($post_id, $row) {
    $after = $row->after_state ? maybe_unserialize($row->after_state) : null;
    if (!is_array($after) || empty($after['linkedRedirects'])) return;
    foreach (array_reverse($after['linkedRedirects']) as $l) {
      $r = $l['redirect'];
      if ($l['op'] === 'added') {
        $now = self::find_id($r['provider'], $r['id']);
        if ($now && $now['target'] === $r['target']) self::delete($r['id'], $r['provider']);
      } elseif ($l['op'] === 'removed' && !self::find_source($r['source'])) {
        $provider = in_array($r['provider'], array_merge(self::active_providers(), ['visibility']), true) ? $r['provider'] : null;
        self::create($r['source'], $r['target'], $r['type'], null, $provider);
      }
    }
  }

  // ─── Built-in store ────────────────────────────────────────────────────

  private static function store() {
    $s = get_option(self::STORE, []);
    if (!is_array($s)) $s = [];
    return $s + ['next' => 1, 'items' => []];
  }

  private static function list_visibility() {
    $out = [];
    foreach (self::store()['items'] as $id => $r) {
      $out[] = self::item('visibility', $id, $r['source'], $r['target'], $r['type'], true, false);
    }
    return $out;
  }

  private static function create_visibility($source, $target, $type, $group) {
    $s = self::store();
    $id = 'v' . (int) $s['next'];
    $s['next'] = (int) $s['next'] + 1;
    $s['items'][$id] = ['source' => $source, 'target' => $target, 'type' => (int) $type, 'created' => gmdate('c')];
    update_option(self::STORE, $s, true);
    return self::item('visibility', $id, $source, $target, $type, true, false);
  }

  private static function delete_visibility($id) {
    $s = self::store();
    unset($s['items'][$id]);
    update_option(self::STORE, $s, true);
    return true;
  }

  /** Serve the built-in redirects, only where WordPress has no page (404). */
  public static function serve_own() {
    if (!is_404()) return;
    $s = get_option(self::STORE, []);
    if (empty($s['items']) || !isset($_SERVER['REQUEST_URI'])) return;
    $path = self::key(esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])));
    foreach ($s['items'] as $r) {
      if (self::key($r['source']) !== $path) continue;
      if ((int) $r['type'] === 410) {
        status_header(410);
        nocache_headers();
        return;
      }
      $to = preg_match('#^https?://#i', $r['target']) ? $r['target'] : self::absolute($r['target']);
      // Same-site targets go through wp_safe_redirect; other sites were checked when the redirect was approved.
      // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
      wp_redirect($to, (int) $r['type'], 'Visibility');
      exit;
    }
  }

  // ─── Redirection (C-31) ────────────────────────────────────────────────

  private static function redirection_groups() {
    global $wpdb;
    $rows = $wpdb->get_results("SELECT id, name, module_id FROM {$wpdb->prefix}redirection_groups ORDER BY id"); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    return array_map(function ($g) { return ['id' => (int) $g->id, 'name' => $g->name, 'module' => (int) $g->module_id]; }, (array) $rows);
  }

  /** The group to use: the one asked (id or name), else the "Visibility" group, found or created (#270). */
  private static function redirection_group($want) {
    $groups = self::redirection_groups();
    if ($want !== null && $want !== '') {
      foreach ($groups as $g) {
        if ((is_numeric($want) && (int) $want === $g['id']) || (!is_numeric($want) && strcasecmp((string) $want, $g['name']) === 0)) return $g['id'];
      }
      if (is_numeric($want)) return new WP_Error('invalid', sprintf('Redirection has no group %d. Groups: %s.', (int) $want, implode(', ', array_map(function ($g) { return $g['id'] . ' ' . $g['name']; }, $groups))));
      $made = Red_Group::create(sanitize_text_field((string) $want), 1);
      return $made ? (int) $made->get_id() : new WP_Error('save_failed', 'Redirection could not create the group.');
    }
    $saved = (int) get_option(self::GROUP_OPTION, 0);
    foreach ($groups as $g) if ($g['id'] === $saved && $g['module'] === 1) return $saved;
    foreach ($groups as $g) if ($g['name'] === 'Visibility' && $g['module'] === 1) { update_option(self::GROUP_OPTION, $g['id'], false); return $g['id']; }
    $made = Red_Group::create('Visibility', 1);
    if (!$made) return new WP_Error('save_failed', 'Redirection could not create the Visibility group.');
    update_option(self::GROUP_OPTION, (int) $made->get_id(), false);
    return (int) $made->get_id();
  }

  private static function list_redirection() {
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $rows = $wpdb->get_results("SELECT i.id, i.url, i.regex, i.status, i.action_type, i.action_code, i.action_data, i.match_type, i.group_id, g.name AS group_name FROM {$wpdb->prefix}redirection_items i LEFT JOIN {$wpdb->prefix}redirection_groups g ON g.id = i.group_id ORDER BY i.id");
    $out = [];
    foreach ((array) $rows as $r) {
      $target = '';
      if ($r->action_type === 'url' && $r->match_type === 'url') {
        $target = (string) $r->action_data;
        $p = self::to_path($target);
        if ($p !== null) $target = $p;
      }
      $regex = (bool) $r->regex || $r->match_type !== 'url';
      $out[] = self::item('redirection', $r->id, $r->url, $target, $r->action_type === 'error' ? (int) $r->action_code : (int) $r->action_code, $r->status === 'enabled', $regex, ['group' => (string) $r->group_name, 'groupId' => (int) $r->group_id]);
    }
    return $out;
  }

  private static function create_redirection($source, $target, $type, $group) {
    $gid = self::redirection_group($group);
    if (is_wp_error($gid)) return $gid;
    $item = Red_Item::create([
      'url'         => $source,
      'action_data' => ['url' => $target],
      'group_id'    => $gid,
      'match_type'  => 'url',
      'action_type' => $type === 410 ? 'error' : 'url',
      'action_code' => $type,
      'regex'       => 0,
    ]);
    if (is_wp_error($item)) return new WP_Error('save_failed', 'Redirection: ' . $item->get_error_message());
    $r = self::find_id('redirection', $item->get_id());
    return $r ?: new WP_Error('not_applied', 'Redirection did not keep the redirect.');
  }

  private static function delete_redirection($id) {
    $item = Red_Item::get_by_id((int) $id);
    if (!$item) return new WP_Error('not_found', 'redirect not found');
    $item->delete();
    return true;
  }

  // ─── Rank Math (C-32) ──────────────────────────────────────────────────

  private static function list_rankmath() {
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $rows = $wpdb->get_results("SELECT id, sources, url_to, header_code, status FROM {$wpdb->prefix}rank_math_redirections WHERE status != 'trashed' ORDER BY id");
    $out = [];
    foreach ((array) $rows as $r) {
      $sources = maybe_unserialize($r->sources);
      if (!is_array($sources)) continue;
      $target = (string) $r->url_to;
      $tp = self::to_path($target);
      if ($tp !== null) $target = $tp;
      foreach ($sources as $s) {
        if (!is_array($s) || !isset($s['pattern'])) continue;
        $exact = ($s['comparison'] ?? 'exact') === 'exact';
        $out[] = self::item('rankmath', $r->id, self::from_relative_slash($s['pattern']), $target, (int) $r->header_code, $r->status === 'active', !$exact);
      }
    }
    return $out;
  }

  private static function create_rankmath($source, $target, $type, $group) {
    $id = \RankMath\Redirections\DB::add([
      'sources'     => [['pattern' => self::relative($source), 'comparison' => 'exact', 'ignore' => '']],
      'url_to'      => $target === '' ? '' : self::absolute($target),
      'header_code' => $type,
      'status'      => 'active',
    ]);
    if (!$id) return new WP_Error('save_failed', 'Rank Math did not save the redirect.');
    $r = self::find_id('rankmath', $id);
    return $r ?: new WP_Error('not_applied', 'Rank Math did not keep the redirect.');
  }

  private static function delete_rankmath($id) {
    \RankMath\Redirections\DB::delete([(int) $id]);
    return true;
  }

  // ─── Yoast SEO Premium (C-34) ──────────────────────────────────────────
  // Built from Yoast SEO Premium's redirect classes (classes/redirect/*:
  // WPSEO_Redirect, WPSEO_Redirect_Manager, option wpseo-premium-redirects-base)
  // and its documented `wp yoast redirect` commands, which use the same calls.


  private static function list_yoast() {
    $m = new WPSEO_Redirect_Manager();
    $out = [];
    foreach ((array) $m->get_all_redirects() as $r) {
      if (!is_object($r)) continue;
      $origin = (string) $r->get_origin();
      $regex = $r->get_format() !== 'plain';
      $target = (string) $r->get_target();
      $out[] = self::item('yoast', $origin, $regex ? $origin : self::from_relative_slash($origin), $target === '' ? '' : self::from_relative_slash($target), (int) $r->get_type(), true, $regex);
    }
    return $out;
  }

  private static function create_yoast($source, $target, $type, $group) {
    $m = new WPSEO_Redirect_Manager('plain');
    $rel_target = $target === '' ? '' : (preg_match('#^https?://#i', $target) ? $target : self::relative($target));
    if ($rel_target === '' && $target !== '') $rel_target = '/';
    $redirect = new WPSEO_Redirect(self::relative($source), $rel_target, $type, 'plain');
    if (!$m->create_redirect($redirect)) return new WP_Error('save_failed', 'Yoast SEO Premium did not save the redirect.');
    $r = self::find_id('yoast', $redirect->get_origin());
    return $r ?: new WP_Error('not_applied', 'Yoast SEO Premium did not keep the redirect.');
  }

  private static function delete_yoast($id) {
    $m = new WPSEO_Redirect_Manager();
    $cur = $m->get_redirect((string) $id);
    if (!$cur) return new WP_Error('not_found', 'redirect not found');
    $m->delete_redirects([$cur]);
    return true;
  }

  // ─── All in One SEO Pro (C-35) ─────────────────────────────────────────
  // Built from the Redirection Manager add-on's public code (aioseo-redirects:
  // Models\Redirect set()/save()/delete(), getRedirectBySourceUrl(), the
  // aioseo_redirects table, aioseoRedirects()->server->rewrite()) as its own
  // REST create/delete routes use them.


  private static function list_aioseo() {
    global $wpdb;
    $t = $wpdb->prefix . 'aioseo_redirects';
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $t)) !== $t) return []; // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $rows = $wpdb->get_results("SELECT * FROM $t ORDER BY id");
    $out = [];
    foreach ((array) $rows as $r) {
      $target = (string) $r->target_url;
      $tp = self::to_path($target);
      if ($tp !== null) $target = $tp;
      $rules = isset($r->custom_rules) ? (string) $r->custom_rules : '';
      // Rules with conditions (login state, schedule…) are not plain address rules.
      $regex = (bool) $r->regex || ($rules !== '' && $rules !== 'null' && $rules !== '[]');
      $out[] = self::item('aioseo', $r->id, $r->source_url, $target, (int) $r->type, (bool) $r->enabled, $regex, ['group' => (string) $r->group]);
    }
    return $out;
  }

  private static function create_aioseo($source, $target, $type, $group) {
    $cls = 'AIOSEO\\Plugin\\Addon\\Redirects\\Models\\Redirect';
    $existing = $cls::getRedirectBySourceUrl($source);
    if ($existing && $existing->exists()) return new WP_Error('conflict', sprintf('%s already has an All in One SEO redirect.', $source));
    $r = new $cls();
    $r->set([
      'source_url'   => $source,
      'target_url'   => $target,
      'type'         => $type,
      'query_param'  => 'ignore',
      'group'        => 'manual',
      'regex'        => false,
      'ignore_slash' => true,
      'ignore_case'  => true,
      'enabled'      => true,
    ]);
    $r->save();
    if (isset(aioseoRedirects()->server) && is_object(aioseoRedirects()->server) && method_exists(aioseoRedirects()->server, 'rewrite')) aioseoRedirects()->server->rewrite();
    $saved = $cls::getRedirectBySourceUrl($source);
    if (!$saved || !$saved->exists()) return new WP_Error('not_applied', 'All in One SEO did not keep the redirect.');
    $item = self::find_id('aioseo', $saved->id);
    return $item ?: new WP_Error('not_applied', 'All in One SEO did not keep the redirect.');
  }

  private static function delete_aioseo($id) {
    global $wpdb;
    $cls = 'AIOSEO\\Plugin\\Addon\\Redirects\\Models\\Redirect';
    $r = $cls::getRedirectById((int) $id);
    if (!$r || !$r->exists()) return new WP_Error('not_found', 'redirect not found');
    $wpdb->delete($wpdb->prefix . 'aioseo_redirects_hits', ['redirect_id' => (int) $id]); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $r->delete();
    if (isset(aioseoRedirects()->server) && is_object(aioseoRedirects()->server) && method_exists(aioseoRedirects()->server, 'rewrite')) aioseoRedirects()->server->rewrite();
    return true;
  }

  // ─── SEOPress Pro (C-36) ───────────────────────────────────────────────
  // Built from SEOPress's redirections guide (origin = relative path in the
  // title of a seopress_404 post, absolute destination) and the meta keys the
  // free SEOPress reads: _seopress_redirections_enabled / _type / _value /
  // _logged_status.


  private static function list_seopress() {
    $ids = get_posts(['post_type' => 'seopress_404', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'orderby' => 'ID', 'order' => 'ASC']);
    $out = [];
    foreach ($ids as $id) {
      $value = (string) get_post_meta($id, '_seopress_redirections_value', true);
      $type = (int) get_post_meta($id, '_seopress_redirections_type', true);
      if ($value === '' && !$type) continue; // a logged 404 without a redirect
      $tp = self::to_path($value);
      $out[] = self::item('seopress', $id, self::from_relative_slash(get_post_field('post_title', $id, 'raw')), $tp !== null ? $tp : $value, $type ?: 301, get_post_meta($id, '_seopress_redirections_enabled', true) === 'yes' && get_post_status($id) === 'publish', get_post_meta($id, '_seopress_redirections_enabled_regex', true) === 'yes');
    }
    return $out;
  }

  private static function create_seopress($source, $target, $type, $group) {
    $id = wp_insert_post(['post_type' => 'seopress_404', 'post_status' => 'publish', 'post_title' => self::relative($source), 'post_author' => Visibility_Author::id()], true);
    if (is_wp_error($id)) return new WP_Error('save_failed', 'SEOPress: ' . $id->get_error_message());
    update_post_meta($id, '_seopress_redirections_enabled', 'yes');
    update_post_meta($id, '_seopress_redirections_type', (int) $type);
    update_post_meta($id, '_seopress_redirections_value', esc_url_raw(self::absolute($target)));
    update_post_meta($id, '_seopress_redirections_logged_status', 'both');
    $r = self::find_id('seopress', $id);
    return $r ?: new WP_Error('not_applied', 'SEOPress did not keep the redirect.');
  }

  private static function delete_seopress($id) {
    if (get_post_type((int) $id) !== 'seopress_404') return new WP_Error('not_found', 'redirect not found');
    wp_delete_post((int) $id, true);
    return true;
  }

  // ─── Safe Redirect Manager (C-33) ──────────────────────────────────────

  private static function list_srm() {
    $ids = get_posts(['post_type' => 'redirect_rule', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'orderby' => 'menu_order ID', 'order' => 'ASC']);
    $out = [];
    foreach ($ids as $id) {
      $to = (string) get_post_meta($id, '_redirect_rule_to', true);
      if (in_array((int) get_post_meta($id, '_redirect_rule_status_code', true), [403, 404, 410], true)) $to = '';
      $tp = self::to_path($to);
      $out[] = self::item('srm', $id, (string) get_post_meta($id, '_redirect_rule_from', true), $tp !== null ? $tp : $to, (int) get_post_meta($id, '_redirect_rule_status_code', true), get_post_status($id) === 'publish', (bool) get_post_meta($id, '_redirect_rule_from_regex', true));
    }
    return $out;
  }

  private static function create_srm($source, $target, $type, $group) {
    // 410 needs no target, but Safe Redirect Manager refuses an empty one.
    $id = srm_create_redirect($source, $target === '' ? '/' : $target, $type, false, 'publish', 0, 'Added by Visibility', Visibility_Author::id());
    if (is_wp_error($id)) return new WP_Error('save_failed', 'Safe Redirect Manager: ' . $id->get_error_message());
    $r = self::find_id('srm', $id);
    return $r ?: new WP_Error('not_applied', 'Safe Redirect Manager did not keep the redirect.');
  }

  private static function delete_srm($id) {
    if (get_post_type((int) $id) !== 'redirect_rule') return new WP_Error('not_found', 'redirect not found');
    wp_delete_post((int) $id, true);
    if (function_exists('srm_flush_cache')) srm_flush_cache();
    return true;
  }
}
