<?php
/**
 * Change snapshots and one-click rollback (#291).
 *
 * Before every write Visibility makes to a post, page or image, the plugin
 * saves a core revision and a snapshot of everything it could touch: the post
 * fields, ALL post meta (SEO plugin fields, custom fields, builder data,
 * featured image), the post's terms, and the SEO values as the SEO plugin
 * reports them (All in One SEO keeps them in its own table). After the write
 * it saves the same snapshot again. Both go into {prefix}visibility_changes.
 *
 * Rollback puts back the "before" value of every item that changed, but only
 * while the post still looks exactly like the "after" snapshot: if anyone
 * changed the post since (a person in WP admin, or a later agent change), it
 * is refused with a conflict.
 */

if (!defined('ABSPATH')) {
  exit;
}

class Visibility_Changes {

  const DB_VERSION = '1';
  const DB_OPTION  = 'visibility_changes_db';

  /** Meta that WordPress rewrites on its own and that says nothing about the page. */
  const IGNORED_META = ['_edit_lock', '_edit_last', '_encloseme', '_pingme', '_wp_old_date', '_elementor_css', '_elementor_page_assets', '_elementor_element_cache', '_elementor_controls_usage'];

  /**
   * Caches that plugins rebuild on their own (often when the page is viewed):
   * never part of a change, never a reason to refuse a rollback.
   */
  const IGNORED_META_PREFIXES = ['_uag_', '_uagb_', '_generateblocks_dynamic_css', '_kad_blocks_', '_kadence_blocks_', '_kb_', '_elementor_page_assets', '_elementor_css', '_elementor_element_cache', '_elementor_migrations_state', '_elementor_global_class_usage', 'rank_math_internal_links_processed', 'rank_math_og_content_image', 'rank_math_analytic_object_id', '_yoast_indexable', '_oembed_', '_yoast_wpseo_estimated-reading-time', '_wp_old_slug_cache', '_litespeed', '_wpfc_', '_sg_', '_et_dynamic_cached_', '_et_builder_dynamic_assets_', '_et_pb_ab_', '_et_builder_module_features_cache'];

  public static function ignored_meta($key) {
    if (in_array($key, self::IGNORED_META, true)) return true;
    foreach (self::IGNORED_META_PREFIXES as $p) if (strpos($key, $p) === 0) return true;
    return (bool) apply_filters('visibility_change_ignored_meta', false, $key);
  }

  /** Request id of the action being executed (set by Visibility_Requests::run). */
  public static $request_id = null;

  /** Open snapshots: post id => before state, for the write in progress. */
  private static $open = [];

  public static function init() {
    if (is_admin()) {
      add_action('admin_menu', [__CLASS__, 'admin_menu'], 20);
      add_action('admin_post_visibility_rollback', [__CLASS__, 'handle_admin_rollback']);
    }
  }

  // ─── WP admin › Agentic SEO › Activity ─────────────────────────────────

  public static function admin_menu() {
    add_submenu_page('visibility', __('Agentic SEO — Activity', 'agentic-seo-visibility'), __('Activity', 'agentic-seo-visibility'), 'manage_options', 'visibility-activity', [__CLASS__, 'render_admin']);
  }

  public static function handle_admin_rollback() {
    if (!current_user_can('manage_options')) wp_die(esc_html__('Permission denied.', 'agentic-seo-visibility'));
    $id = isset($_POST['change_id']) ? (int) $_POST['change_id'] : 0;
    check_admin_referer('visibility_rollback_' . $id);
    $r = self::rollback($id, 'admin');
    $args = ['page' => 'visibility-activity', 'visibility_rb' => is_wp_error($r) ? $r->get_error_code() : 'ok', 'visibility_rb_nonce' => wp_create_nonce('visibility_rb')];
    wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
    exit;
  }

  public static function render_admin() {
    if (!current_user_can('manage_options')) return;
    $notice = '';
    if (isset($_GET['visibility_rb'], $_GET['visibility_rb_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['visibility_rb_nonce'])), 'visibility_rb')) {
      $notice = sanitize_key(wp_unslash($_GET['visibility_rb']));
    }
    $rows = self::list_rows(0, 100);
    ?>
    <div class="wrap">
      <h1><?php echo esc_html__('Activity', 'agentic-seo-visibility'); ?></h1>
      <p class="description"><?php echo esc_html__('Every change Visibility made on this site. Rolling back puts the page, its SEO fields and custom fields back as they were before that change. If the page was edited after the change, rollback is refused so nothing newer is lost.', 'agentic-seo-visibility'); ?></p>
      <hr class="wp-header-end"/>
      <?php if ($notice === 'ok') : ?>
        <div class="notice notice-success"><p><?php echo esc_html__('Rolled back.', 'agentic-seo-visibility'); ?></p></div>
      <?php elseif ($notice === 'conflict') : ?>
        <div class="notice notice-error"><p><?php echo esc_html__('Not rolled back: the page was changed after this change was made.', 'agentic-seo-visibility'); ?></p></div>
      <?php elseif ($notice !== '') : ?>
        <div class="notice notice-error"><p><?php echo esc_html__('Not rolled back.', 'agentic-seo-visibility') . ' (' . esc_html($notice) . ')'; ?></p></div>
      <?php endif; ?>
      <table class="widefat striped" style="margin-top:12px">
        <thead><tr>
          <th><?php echo esc_html__('When', 'agentic-seo-visibility'); ?></th>
          <th><?php echo esc_html__('Page', 'agentic-seo-visibility'); ?></th>
          <th><?php echo esc_html__('Change', 'agentic-seo-visibility'); ?></th>
          <th><?php echo esc_html__('What changed', 'agentic-seo-visibility'); ?></th>
          <th></th>
        </tr></thead>
        <tbody>
        <?php if (empty($rows)) : ?>
          <tr><td colspan="5"><?php echo esc_html__('No changes yet.', 'agentic-seo-visibility'); ?></td></tr>
        <?php endif; ?>
        <?php foreach ((array) $rows as $row) :
          $c = self::serialize($row);
          $title = get_the_title($c['postId']);
          ?>
          <tr>
            <td><?php echo esc_html(get_date_from_gmt($row->created_at, get_option('date_format') . ' ' . get_option('time_format'))); ?></td>
            <td><?php if (!$c['postId']) : ?>&mdash;<?php elseif (get_post($c['postId'])) : ?><a href="<?php echo esc_url(get_edit_post_link($c['postId'])); ?>"><?php echo esc_html($title !== '' ? $title : '#' . $c['postId']); ?></a><?php else : ?>#<?php echo (int) $c['postId']; ?><?php endif; ?></td>
            <td><code><?php echo esc_html($c['action']); ?></code></td>
            <td><?php echo esc_html(implode(', ', array_slice($c['changed'], 0, 6)) . (count($c['changed']) > 6 ? ' …' : '')); ?></td>
            <td>
              <?php if ($c['rolledBackAt']) : ?>
                <em><?php echo esc_html__('Rolled back', 'agentic-seo-visibility'); ?></em>
              <?php else : ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('<?php echo esc_js(__('Roll back this change?', 'agentic-seo-visibility')); ?>')">
                  <?php wp_nonce_field('visibility_rollback_' . $c['id']); ?>
                  <input type="hidden" name="action" value="visibility_rollback"/>
                  <input type="hidden" name="change_id" value="<?php echo esc_attr($c['id']); ?>"/>
                  <button class="button"><?php echo esc_html__('Roll back', 'agentic-seo-visibility'); ?></button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php
  }

  public static function table() {
    global $wpdb;
    return $wpdb->prefix . 'visibility_changes';
  }

  public static function install() {
    global $wpdb;
    if (get_option(self::DB_OPTION) === self::DB_VERSION) return;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $t = self::table();
    $charset = $wpdb->get_charset_collate();
    dbDelta("CREATE TABLE $t (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  request_id varchar(191) NOT NULL DEFAULT '',
  post_id bigint(20) unsigned NOT NULL DEFAULT 0,
  action varchar(64) NOT NULL DEFAULT '',
  source varchar(20) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  changed longtext NOT NULL,
  before_state longtext NULL,
  after_state longtext NULL,
  rolled_back_at datetime NULL,
  rolled_back_by bigint(20) unsigned NULL,
  rollback_of bigint(20) unsigned NULL,
  PRIMARY KEY  (id),
  KEY post_id (post_id),
  KEY request_id (request_id)
) $charset;");
    update_option(self::DB_OPTION, self::DB_VERSION, false);
  }

  // ─── Snapshots ─────────────────────────────────────────────────────────

  /** Everything about a post that a Visibility write can change. */
  public static function state($post_id) {
    global $wpdb;
    $post = get_post($post_id);
    if (!$post) return null;
    clean_post_cache($post_id);
    $post = get_post($post_id);
    $fields = [];
    foreach (['post_title', 'post_content', 'post_excerpt', 'post_name', 'post_status', 'post_parent', 'menu_order', 'post_author', 'post_date', 'post_date_gmt', 'post_password'] as $f) {
      $fields[$f] = (string) $post->$f;
    }
    // Raw rows, so a restore writes back exactly the bytes that were there.
    $meta = [];
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own change log and exact meta snapshots for rollback.
    $rows = $wpdb->get_results($wpdb->prepare("SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d ORDER BY meta_id", $post_id));
    foreach ((array) $rows as $r) {
      if (self::ignored_meta($r->meta_key)) continue;
      $meta[$r->meta_key][] = $r->meta_value;
    }
    $terms = [];
    foreach (get_object_taxonomies($post->post_type) as $tax) {
      $ids = wp_get_object_terms($post_id, $tax, ['fields' => 'ids', 'orderby' => 'term_id']);
      $terms[$tax] = is_wp_error($ids) ? [] : array_map('intval', $ids);
    }
    $seo = null;
    if ($post->post_type !== 'attachment' && class_exists('Visibility_SEO')) {
      $seo = Visibility_SEO::adapter()->read($post_id);
    }
    return [
      'postType'    => $post->post_type,
      'modifiedGmt' => Visibility_Content::modified_gmt($post),
      'post'        => $fields,
      'meta'        => $meta,
      'terms'       => $terms,
      'seo'         => $seo,
    ];
  }

  /** Items that differ between two states: ["post:post_content", "meta:_yoast_wpseo_title", "terms:category", "seo:metaTitle"]. */
  public static function diff($before, $after) {
    $out = [];
    if (!$before || !$after) return $out;
    foreach (['post', 'meta', 'terms', 'seo'] as $group) {
      $a = is_array($before[$group]) ? $before[$group] : [];
      $b = is_array($after[$group]) ? $after[$group] : [];
      foreach (array_unique(array_merge(array_keys($a), array_keys($b))) as $k) {
        $va = array_key_exists($k, $a) ? $a[$k] : null;
        $vb = array_key_exists($k, $b) ? $b[$k] : null;
        if ($va !== $vb) $out[] = $group . ':' . $k;
      }
    }
    return $out;
  }

  /** Call before writing to $post_id; saves a core revision of the current content. */
  public static function begin($post_id) {
    $post_id = (int) $post_id;
    if ($post_id <= 0 || !get_post($post_id)) return;
    if (isset(self::$open[$post_id])) return;
    if (post_type_supports(get_post_type($post_id), 'revisions') && function_exists('wp_save_post_revision')) {
      wp_save_post_revision($post_id);
    }
    self::$open[$post_id] = self::state($post_id);
  }

  /**
   * Call after the write. Records the change and returns its id (as a
   * string), or null when nothing changed.
   */
  public static function commit($post_id, $action, $source = 'action', $created = false, $rollback_of = null) {
    global $wpdb;
    $post_id = (int) $post_id;
    $before = $created ? null : (isset(self::$open[$post_id]) ? self::$open[$post_id] : null);
    unset(self::$open[$post_id]);
    if (!$created && !$before) return null;
    $after = self::state($post_id);
    if (!$after) return null;
    $changed = $created ? ['created'] : self::diff($before, $after);
    if (empty($changed)) return null;
    // Redirects added or removed by this change (slug change), undone with it.
    if (class_exists('Visibility_Redirects') && Visibility_Redirects::$linked) {
      $after['linkedRedirects'] = Visibility_Redirects::$linked;
      Visibility_Redirects::$linked = [];
      $changed[] = 'redirects:linked';
    }
    self::install();
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- the plugin's own change log and exact meta snapshots for rollback.
    $wpdb->insert(self::table(), [
      'request_id'   => (string) (self::$request_id ?: ''),
      'post_id'      => $post_id,
      'action'       => substr((string) $action, 0, 64),
      'source'       => substr((string) $source, 0, 20),
      'created_at'   => current_time('mysql', true),
      'changed'      => wp_json_encode($changed),
      'before_state' => $before === null ? null : maybe_serialize($before),
      'after_state'  => maybe_serialize($after),
      'rollback_of'  => $rollback_of,
    ]);
    return $wpdb->insert_id ? (string) $wpdb->insert_id : null;
  }

  /**
   * A change that is not about one post (redirects, several images at once).
   * $before / $after are ['group' => [key => value]]; $changed lists
   * "group:key" items. Rolled back by the `visibility_rollback_custom` hook.
   */
  public static function record_custom($action, array $changed, array $before, array $after, $rollback_of = null) {
    global $wpdb;
    self::install();
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- the plugin's own change log and exact meta snapshots for rollback.
    $wpdb->insert(self::table(), [
      'request_id'   => (string) (self::$request_id ?: ''),
      'post_id'      => 0,
      'action'       => substr((string) $action, 0, 64),
      'source'       => 'action',
      'created_at'   => current_time('mysql', true),
      'changed'      => wp_json_encode(array_values($changed)),
      'before_state' => maybe_serialize($before),
      'after_state'  => maybe_serialize($after),
      'rollback_of'  => $rollback_of,
    ]);
    return $wpdb->insert_id ? (string) $wpdb->insert_id : null;
  }

  /** True when the post differs from the snapshot taken by begin(). */
  public static function changed_since_begin($post_id) {
    $post_id = (int) $post_id;
    if (!isset(self::$open[$post_id])) return false;
    return (bool) self::diff(self::$open[$post_id], self::state($post_id));
  }

  /** Forget an open snapshot (the write failed). */
  public static function abort($post_id) {
    unset(self::$open[(int) $post_id]);
  }

  // ─── Read ──────────────────────────────────────────────────────────────

  public static function get($id) {
    global $wpdb;
    self::install();
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own change log table.
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}visibility_changes WHERE id = %d", (int) $id));
    return $row ?: null;
  }

  public static function list_rows($post_id = 0, $limit = 50, $offset = 0) {
    global $wpdb;
    self::install();
    if ($post_id) {
      // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own change log table.
      return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}visibility_changes WHERE post_id = %d ORDER BY id DESC LIMIT %d OFFSET %d", $post_id, $limit, $offset));
    }
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own change log table.
    return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}visibility_changes ORDER BY id DESC LIMIT %d OFFSET %d", $limit, $offset));
  }

  /** API shape of a change. $full adds before/after values of the changed items. */
  public static function serialize($row, $full = false) {
    $changed = json_decode($row->changed, true) ?: [];
    $out = [
      'id'           => (string) $row->id,
      'postId'       => (int) $row->post_id,
      'action'       => $row->action,
      'source'       => $row->source,
      'requestId'    => $row->request_id !== '' ? $row->request_id : null,
      'createdAt'    => gmdate('Y-m-d\TH:i:s\Z', strtotime($row->created_at . ' UTC')),
      'changed'      => $changed,
      'rolledBackAt' => $row->rolled_back_at ? gmdate('Y-m-d\TH:i:s\Z', strtotime($row->rolled_back_at . ' UTC')) : null,
      'rolledBackBy' => $row->rolled_back_by ? (string) $row->rolled_back_by : null,
      'rollbackOf'   => $row->rollback_of ? (string) $row->rollback_of : null,
      'canRollBack'  => !$row->rolled_back_at,
    ];
    if ($full) {
      $before = $row->before_state ? maybe_unserialize($row->before_state) : null;
      $after  = $row->after_state ? maybe_unserialize($row->after_state) : null;
      $out['before'] = self::pick($before, $changed);
      $out['after']  = self::pick($after, $changed);
    }
    return $out;
  }

  private static function pick($state, $items) {
    if (!$state) return null;
    $out = [];
    foreach ($items as $item) {
      if ($item === 'created') continue;
      list($group, $key) = explode(':', $item, 2);
      $v = isset($state[$group]) && is_array($state[$group]) && array_key_exists($key, $state[$group]) ? $state[$group][$key] : null;
      if ($group === 'meta' && is_array($v)) {
        $v = array_map('maybe_unserialize', $v);
        if (count($v) === 1) $v = $v[0];
      }
      $out[$item] = $v;
    }
    return (object) $out;
  }

  // ─── Rollback ──────────────────────────────────────────────────────────

  /**
   * Undo change $id.
   *
   * @return array|WP_Error ['postId', 'rolledBack', 'changeId', 'modifiedGmt'] or
   *         WP_Error with code conflict / not_found / already_rolled_back.
   */
  public static function rollback($id, $source = 'action') {
    // One rollback of a change at a time, and one writer per post (#568).
    if (!Visibility_Content::lock('change:' . (int) $id)) return new WP_Error('conflict', 'This change is being rolled back right now.');
    try {
      $row = self::get($id);
      $post_id = $row ? (int) $row->post_id : 0;
      if ($post_id && !Visibility_Content::lock($post_id)) return new WP_Error('conflict', 'Another change to this post is being saved right now. Read it again and retry.');
      try {
        return self::rollback_locked($id, $source);
      } finally {
        if ($post_id) Visibility_Content::unlock($post_id);
      }
    } finally {
      Visibility_Content::unlock('change:' . (int) $id);
    }
  }

  private static function rollback_locked($id, $source) {
    global $wpdb;
    $row = self::get($id);
    if (!$row) return new WP_Error('not_found', 'Change not found.');
    if ($row->rolled_back_at) return new WP_Error('already_rolled_back', 'This change was already rolled back.');
    $post_id = (int) $row->post_id;
    if ($post_id === 0) return self::rollback_custom($row, $source);
    $after  = maybe_unserialize($row->after_state);
    $before = $row->before_state ? maybe_unserialize($row->before_state) : null;
    $now = self::state($post_id);
    if (!$now) return new WP_Error('not_found', 'The post no longer exists.');

    // The post must still be exactly as this change left it: any difference
    // anywhere (content, any meta, terms, SEO values), not only in the items
    // this change touched. modifiedGmt alone is not enough: it has one-second
    // resolution and meta-only writes don't move it.
    $changed = json_decode($row->changed, true) ?: [];
    $drift = self::diff($after, $now);
    if ($now['modifiedGmt'] !== $after['modifiedGmt']) $drift[] = 'post:modified';
    if ($drift) {
      return new WP_Error('conflict', 'The page was changed after this change was made, so it was not rolled back: ' . implode(', ', $drift) . '.', ['modifiedGmt' => $now['modifiedGmt'], 'changedSince' => $drift]);
    }

    $before_gmt = $now['modifiedGmt'];
    self::begin($post_id);
    if ($before === null) {
      // The change created this post: undo = move it to the trash.
      wp_trash_post($post_id);
    } else {
      self::restore($post_id, $before, $changed);
    }
    if (self::changed_since_begin($post_id)) Visibility_Content::advance_modified($post_id, $before_gmt);
    $new_id = self::commit($post_id, 'change.rollback', $source, false, (int) $row->id);
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own change log and exact meta snapshots for rollback.
    $wpdb->update(self::table(), ['rolled_back_at' => current_time('mysql', true), 'rolled_back_by' => $new_id ? (int) $new_id : null], ['id' => (int) $row->id]);
    /** After a rollback, so builders can rebuild their CSS and caches purge. */
    try {
      do_action('visibility_after_rollback', $post_id, $row);
    } catch (\Throwable $e) {
      // A builder or cache hook failing must not undo a finished rollback.
    }
    return [
      'postId'      => $post_id,
      'rolledBack'  => (string) $row->id,
      'changeId'    => $new_id,
      'modifiedGmt' => Visibility_Content::modified_gmt($post_id),
      'postStatus'  => get_post_status($post_id),
      'cachePurged' => class_exists('Visibility_Cache') ? Visibility_Cache::purge($post_id) : [],
    ];
  }

  /** Undo a change recorded with record_custom(). */
  private static function rollback_custom($row, $source) {
    global $wpdb;
    $before = $row->before_state ? maybe_unserialize($row->before_state) : [];
    $after  = $row->after_state ? maybe_unserialize($row->after_state) : [];
    $result = (object) ['error' => null, 'data' => null];
    do_action('visibility_rollback_custom', $result, $row, [$before, $after]);
    if ($result->error) return $result->error;
    if ($result->data === null) return new WP_Error('unsupported', 'This change cannot be rolled back.');
    $changed = json_decode($row->changed, true) ?: [];
    $new_id = self::record_custom('change.rollback', $changed, (array) $after, (array) $before, (int) $row->id);
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own change log and exact meta snapshots for rollback.
    $wpdb->update(self::table(), ['rolled_back_at' => current_time('mysql', true), 'rolled_back_by' => $new_id ? (int) $new_id : null], ['id' => (int) $row->id]);
    $out = ['postId' => 0, 'rolledBack' => (string) $row->id, 'changeId' => $new_id, 'cachePurged' => []];
    if (is_array($result->data)) $out += $result->data;
    return $out;
  }

  /** Write the "before" value of every changed item back. */
  private static function restore($post_id, array $before, array $changed) {
    global $wpdb;
    $post_args = ['ID' => $post_id];
    $meta_keys = []; $taxes = []; $seo = [];
    foreach ($changed as $item) {
      list($group, $key) = explode(':', $item, 2);
      if ($group === 'post') $post_args[$key] = $before['post'][$key];
      elseif ($group === 'meta') $meta_keys[] = $key;
      elseif ($group === 'terms') $taxes[] = $key;
      elseif ($group === 'redirects') continue;
      elseif ($group === 'seo' && is_array($before['seo']) && array_key_exists($key, $before['seo'])) $seo[$key] = $before['seo'][$key];
    }
    if (isset($post_args['post_status']) && get_post_status($post_id) === 'trash') {
      wp_untrash_post($post_id);
    }
    if (count($post_args) > 1) {
      if (isset($post_args['post_date']) || isset($post_args['post_date_gmt'])) $post_args['edit_date'] = true;
      Visibility_Content::save($post_args);
    }
    // Meta last: save_post hooks above must not leave their own values behind.
    foreach ($meta_keys as $key) {
      // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- the plugin's own change log and exact meta snapshots for rollback.
      $wpdb->delete($wpdb->postmeta, ['post_id' => $post_id, 'meta_key' => $key]);
      if (isset($before['meta'][$key])) {
        foreach ($before['meta'][$key] as $raw) {
          // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- the plugin's own change log and exact meta snapshots for rollback.
          $wpdb->insert($wpdb->postmeta, ['post_id' => $post_id, 'meta_key' => $key, 'meta_value' => $raw]);
        }
      }
    }
    wp_cache_delete($post_id, 'post_meta');
    foreach ($taxes as $tax) {
      wp_set_object_terms($post_id, isset($before['terms'][$tax]) ? $before['terms'][$tax] : [], $tax, false);
    }
    // SEO plugins with their own storage (All in One SEO), and the ones that
    // rebuild a cache on their own save path (Yoast indexables).
    if ($seo && class_exists('Visibility_SEO')) {
      $adapter = Visibility_SEO::adapter();
      // A custom SEO plugin keeps everything in post meta, restored exactly above.
      if ($adapter->writable() && !($adapter instanceof Visibility_SEO_Adapter_Custom)) {
        unset($seo['schemaJsonLd']);
        if ($seo) $adapter->write($post_id, $seo);
      }
    }
    clean_post_cache($post_id);
  }
}
