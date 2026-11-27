<?php
/**
 * Settings page under Settings -> Agentic SEO.
 *
 * Two states:
 *   - Not paired: shows a single field to paste the pairing code.
 *   - Paired:     shows status + a Disconnect button.
 *
 * Status-redirect query args are nonce-protected so an attacker can't
 * craft `?visibility_status=error&visibility_msg=<phishing-text>` and
 * inject an arbitrary admin notice by tricking the user into clicking
 * a link. The nonce is minted at redirect time and verified on render.
 */

if (!defined('ABSPATH')) {
  exit;
}

class Visibility_Settings {

  public function __construct() {
    add_action('admin_menu', [$this, 'register_menu']);
    add_action('admin_post_visibility_pair', [$this, 'handle_pair']);
    add_action('admin_post_visibility_disconnect', [$this, 'handle_disconnect']);
    add_action('admin_post_visibility_author', [$this, 'handle_author']);
    add_action('admin_post_visibility_pause', [$this, 'handle_pause']);
    add_action('admin_post_visibility_signing', [$this, 'handle_signing']);
    add_action('admin_post_visibility_checkin', [$this, 'handle_checkin']);
    add_action('admin_post_visibility_profile', [$this, 'handle_profile']);
  }

  /** Build the nonce-protected query args for a redirect-after-action.
   *  $message is optional and only attached on the error path. */
  private function get_notice_query_args($status, $message = '') {
    $args = [
      'page'                    => 'visibility',
      'visibility_status'       => $status,
      'visibility_notice_nonce' => wp_create_nonce('visibility_notice'),
    ];

    if ($message !== '') {
      $args['visibility_msg'] = rawurlencode($message);
    }

    return $args;
  }

  /** Read the (nonce-verified) status + message from the current request.
   *  Returns empty strings when no nonce is present or it doesn't verify —
   *  silently ignoring spoofed notice URLs. */
  private function get_notice_state() {
    $notice_nonce = isset($_GET['visibility_notice_nonce']) ? sanitize_text_field(wp_unslash($_GET['visibility_notice_nonce'])) : '';

    if ($notice_nonce === '' || !wp_verify_nonce($notice_nonce, 'visibility_notice')) {
      return [
        'status'    => '',
        'error_msg' => '',
      ];
    }

    return [
      'status'    => isset($_GET['visibility_status']) ? sanitize_key(wp_unslash($_GET['visibility_status'])) : '',
      'error_msg' => isset($_GET['visibility_msg']) ? sanitize_text_field(wp_unslash($_GET['visibility_msg'])) : '',
    ];
  }

  public function register_menu() {
    // Dedicated top-level menu so all three plugin screens live together
    // under one clearly-labelled parent (instead of three lookalike
    // entries buried under Settings). The Approvals + Activity submenus
    // are added by Visibility_Requests against this same `visibility`
    // parent slug.
    add_menu_page(
      __('Agentic SEO for Visibility', 'agentic-seo-visibility'),
      __('Agentic SEO', 'agentic-seo-visibility'),
      'manage_options',
      'visibility',
      [$this, 'render'],
      'dashicons-admin-site-alt3',
      58
    );
    // First submenu shares the parent slug so the parent click lands on
    // the connection screen, and we get a readable "Connection" label
    // instead of WP duplicating the parent title.
    add_submenu_page(
      'visibility',
      __('Agentic SEO — Connection', 'agentic-seo-visibility'),
      __('Connection', 'agentic-seo-visibility'),
      'manage_options',
      'visibility',
      [$this, 'render']
    );
  }

  public function handle_pair() {
    if (!current_user_can('manage_options')) {
      wp_die(esc_html__('Permission denied.', 'agentic-seo-visibility'));
    }
    check_admin_referer('visibility_pair');
    $code = isset($_POST['visibility_pairing_code']) ? sanitize_text_field(wp_unslash($_POST['visibility_pairing_code'])) : '';
    $result = Visibility_Client::pair($code);

    if (is_wp_error($result)) {
      $message = $result->get_error_message();
      wp_safe_redirect(add_query_arg($this->get_notice_query_args('error', $message), admin_url('admin.php')));
      exit;
    }
    wp_safe_redirect(add_query_arg($this->get_notice_query_args('paired'), admin_url('admin.php')));
    exit;
  }

  public function handle_disconnect() {
    if (!current_user_can('manage_options')) {
      wp_die(esc_html__('Permission denied.', 'agentic-seo-visibility'));
    }
    check_admin_referer('visibility_disconnect');
    Visibility_Client::disconnect();
    wp_safe_redirect(add_query_arg($this->get_notice_query_args('disconnected'), admin_url('admin.php')));
    exit;
  }

  /** Save the default author for content from Visibility (#275). */
  public function handle_author() {
    if (!current_user_can('manage_options')) {
      wp_die(esc_html__('Permission denied.', 'agentic-seo-visibility'));
    }
    check_admin_referer('visibility_author');
    $id = isset($_POST['visibility_default_author']) ? absint($_POST['visibility_default_author']) : 0;
    $u = $id ? get_userdata($id) : null;
    if (!$u || !user_can($u, 'publish_posts')) {
      wp_safe_redirect(add_query_arg($this->get_notice_query_args('error', __('Pick a user who can publish posts.', 'agentic-seo-visibility')), admin_url('admin.php')));
      exit;
    }
    update_option(Visibility_Author::OPTION, $id, false);
    wp_safe_redirect(add_query_arg($this->get_notice_query_args('author_saved'), admin_url('admin.php')));
    exit;
  }

  /** Pause / resume every write from Visibility (#295). */
  public function handle_pause() {
    if (!current_user_can('manage_options')) {
      wp_die(esc_html__('Permission denied.', 'agentic-seo-visibility'));
    }
    check_admin_referer('visibility_pause');
    $on = isset($_POST['visibility_pause']) && sanitize_key(wp_unslash($_POST['visibility_pause'])) === 'on';
    Visibility_Policy::set_wp_pause($on);
    wp_safe_redirect(add_query_arg($this->get_notice_query_args($on ? 'paused' : 'resumed'), admin_url('admin.php')));
    exit;
  }

  /** Check in with Visibility now: sends the version and pause state, takes the latest settings (#474). */
  public function handle_checkin() {
    if (!current_user_can('manage_options')) {
      wp_die(esc_html__('Permission denied.', 'agentic-seo-visibility'));
    }
    check_admin_referer('visibility_checkin');
    $r = Visibility_Client::heartbeat();
    $args = is_wp_error($r)
      ? $this->get_notice_query_args('error', __('Check-in failed:', 'agentic-seo-visibility') . ' ' . $r->get_error_message())
      : $this->get_notice_query_args('checked_in');
    wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
    exit;
  }

  /** Share the site profile with Visibility, or not (#471). */
  public function handle_profile() {
    if (!current_user_can('manage_options')) {
      wp_die(esc_html__('Permission denied.', 'agentic-seo-visibility'));
    }
    check_admin_referer('visibility_profile');
    $on = isset($_POST['visibility_profile']) && sanitize_key(wp_unslash($_POST['visibility_profile'])) === '1';
    update_option(Visibility_Profile::OFF_OPTION, $on ? '0' : '1', false);
    Visibility_Profile::forget();
    wp_safe_redirect(add_query_arg($this->get_notice_query_args('profile_saved'), admin_url('admin.php')));
    exit;
  }

  /** Require signed requests from Visibility (#296). */
  public function handle_signing() {
    if (!current_user_can('manage_options')) {
      wp_die(esc_html__('Permission denied.', 'agentic-seo-visibility'));
    }
    check_admin_referer('visibility_signing');
    $on = isset($_POST['visibility_require_signature']) && sanitize_key(wp_unslash($_POST['visibility_require_signature'])) === '1';
    Visibility_Auth::set_required($on);
    wp_safe_redirect(add_query_arg($this->get_notice_query_args('signing_saved'), admin_url('admin.php')));
    exit;
  }

  /** Pause button + the owner's controls as Visibility last sent them (read-only). */
  private function render_controls_box() {
    $by = Visibility_Policy::paused_by();
    $c = Visibility_Policy::controls();
    $labels = [
      'create_drafts' => __('Create drafts', 'agentic-seo-visibility'),
      'edit_content'  => __('Edit existing content', 'agentic-seo-visibility'),
      'edit_meta'     => __('Edit meta tags', 'agentic-seo-visibility'),
      'edit_fields'   => __('Edit custom fields', 'agentic-seo-visibility'),
      'images'        => __('Images and alt text', 'agentic-seo-visibility'),
      'redirects'     => __('Redirects', 'agentic-seo-visibility'),
      'publish'       => __('Publish', 'agentic-seo-visibility'),
      'delete'        => __('Delete', 'agentic-seo-visibility'),
    ];
    ?>
    <div style="background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:20px 28px;margin-top:20px">
      <h2 style="margin-top:0;font-size:15px"><?php echo esc_html__('What agents may do on this site', 'agentic-seo-visibility'); ?></h2>
      <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:14px">
        <?php wp_nonce_field('visibility_pause'); ?>
        <input type="hidden" name="action" value="visibility_pause" />
        <?php if ($by === 'wordpress') : ?>
          <p><strong style="color:#b32d2e"><?php echo esc_html__('Paused in WordPress.', 'agentic-seo-visibility'); ?></strong> <?php echo esc_html__('Every change from Visibility is refused until you resume.', 'agentic-seo-visibility'); ?></p>
          <input type="hidden" name="visibility_pause" value="off" />
          <button type="submit" class="button button-primary" id="visibility-resume"><?php echo esc_html__('Resume', 'agentic-seo-visibility'); ?></button>
        <?php else : ?>
          <?php if ($by === 'visibility') : ?>
            <p><strong style="color:#b32d2e"><?php echo esc_html__('Paused in Visibility.', 'agentic-seo-visibility'); ?></strong></p>
          <?php endif; ?>
          <p class="description"><?php echo esc_html__('Pause stops every change from Visibility at once (reading still works). Visibility is told right away.', 'agentic-seo-visibility'); ?></p>
          <input type="hidden" name="visibility_pause" value="on" />
          <button type="submit" class="button" id="visibility-pause"><?php echo esc_html__('Pause all', 'agentic-seo-visibility'); ?></button>
        <?php endif; ?>
      </form>
      <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:14px">
        <?php wp_nonce_field('visibility_checkin'); ?>
        <input type="hidden" name="action" value="visibility_checkin" />
        <button type="submit" class="button" id="visibility-checkin"><?php echo esc_html__('Check in now', 'agentic-seo-visibility'); ?></button>
        <span class="description" id="visibility-controls-at"><?php
          $at = (int) get_option('visibility_controls_at', 0);
          echo esc_html($at
            /* translators: %s: how long ago, e.g. "5 minutes" */
            ? sprintf(__('Last updated from Visibility %s ago.', 'agentic-seo-visibility'), human_time_diff($at))
            : __('Not updated from Visibility yet.', 'agentic-seo-visibility'));
        ?></span>
      </form>
      <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:14px">
        <?php wp_nonce_field('visibility_profile'); ?>
        <input type="hidden" name="action" value="visibility_profile" />
        <input type="hidden" name="visibility_profile" value="0" />
        <label><input type="checkbox" name="visibility_profile" value="1" id="visibility-profile" <?php checked(Visibility_Profile::enabled()); ?> onchange="this.form.submit()" />
          <?php echo esc_html__('Share how this site is built with Visibility (blocks, categories and tags, post settings in use, how recent posts are laid out), so agents write the way your posts are written. Never drafts, users or passwords.', 'agentic-seo-visibility'); ?></label>
        <noscript><button type="submit" class="button"><?php echo esc_html__('Save', 'agentic-seo-visibility'); ?></button></noscript>
      </form>
      <?php if ($c === null) : ?>
        <p class="description"><?php echo esc_html__('No limits have been set in Visibility yet.', 'agentic-seo-visibility'); ?>
          <?php if (!Visibility_Policy::publishing_allowed()) echo esc_html__('Publishing is turned off.', 'agentic-seo-visibility'); ?></p>
      <?php else : ?>
        <table class="form-table" role="presentation" id="visibility-controls">
          <tr><th scope="row"><?php echo esc_html__('Allowed', 'agentic-seo-visibility'); ?></th>
            <td><?php foreach ($labels as $k => $l) : $on = in_array($k, $c['actions'], true); ?>
              <span style="display:inline-block;margin:0 10px 4px 0"><?php echo $on ? '&#10003;' : '&#10007;'; ?> <?php echo esc_html($l); ?></span>
            <?php endforeach; ?></td></tr>
          <tr><th scope="row"><?php echo esc_html__('Content types', 'agentic-seo-visibility'); ?></th>
            <td><?php echo esc_html($c['postTypes'] ? implode(', ', $c['postTypes']) : __('none', 'agentic-seo-visibility')); ?></td></tr>
          <tr><th scope="row"><?php echo esc_html__('Locked pages', 'agentic-seo-visibility'); ?></th>
            <td><?php
              $names = array_map(function ($id) { $t = get_the_title($id); return $t !== '' ? $t : '#' . $id; }, $c['lockedPostIds']);
              echo esc_html($names ? implode(', ', $names) : __('none', 'agentic-seo-visibility'));
            ?></td></tr>
          <tr><th scope="row"><?php echo esc_html__('Custom fields agents may change', 'agentic-seo-visibility'); ?></th>
            <td><?php
              // "Any post setting" (#581): say so instead of "none".
              $fields = array_values(array_diff($c['fieldAllowlist'], ['meta:*']));
              $parts = Visibility_Policy::any_post_meta() ? [__('any post setting (except protected ones)', 'agentic-seo-visibility')] : [];
              echo esc_html(($parts || $fields) ? implode(', ', array_merge($parts, $fields)) : __('none', 'agentic-seo-visibility'));
            ?></td></tr>
        </table>
      <?php endif; ?>
      <p><a href="<?php echo esc_url(trailingslashit(visibility_api_base_url())); ?>" target="_blank" rel="noopener"><?php echo esc_html__('Change these in Visibility (Settings › Integrations › your website › WordPress)', 'agentic-seo-visibility'); ?></a></p>
    </div>
    <?php
  }

  /** The "Default author" box. */
  private function render_author_box() {
    $current = Visibility_Author::id();
    ?>
    <div style="background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:20px 28px;margin-top:20px">
      <h2 style="margin-top:0;font-size:15px"><?php echo esc_html__('Default author for content from Visibility', 'agentic-seo-visibility'); ?></h2>
      <?php if (Visibility_Author::chosen_missing()) : ?>
        <div class="notice notice-warning inline"><p><?php echo esc_html__('The user you chose no longer exists or can no longer publish. New content goes to the first administrator until you pick someone else.', 'agentic-seo-visibility'); ?></p></div>
      <?php endif; ?>
      <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('visibility_author'); ?>
        <input type="hidden" name="action" value="visibility_author" />
        <p class="description" style="margin-bottom:10px"><?php echo esc_html__('New posts and pages created by Visibility are published under this user. Editing existing content never changes its author.', 'agentic-seo-visibility'); ?></p>
        <select name="visibility_default_author" id="visibility_default_author">
          <?php foreach (Visibility_Author::candidates() as $u) : ?>
            <option value="<?php echo (int) $u->ID; ?>" <?php selected((int) $u->ID, $current); ?>><?php echo esc_html($u->display_name . ' (' . $u->user_login . ')'); ?></option>
          <?php endforeach; ?>
        </select>
        <button type="submit" class="button"><?php echo esc_html__('Save', 'agentic-seo-visibility'); ?></button>
      </form>
    </div>
    <?php
  }

  public function render() {
    if (!current_user_can('manage_options')) {
      return;
    }

    $paired       = Visibility_Client::is_paired();
    $project_id   = get_option('visibility_project_id', '');
    $project_name = get_option('visibility_project_name', '');
    $company_name = get_option('visibility_company_name', '');
    $paired_at    = (int) get_option('visibility_paired_at', 0);
    $last_seen_at = (int) get_option('visibility_last_seen_at', 0);
    $notice_data  = $this->get_notice_state();
    $status      = $notice_data['status'];
    $error_msg   = $notice_data['error_msg'];
    $action_url  = esc_url(admin_url('admin-post.php'));
    $dashboard   = esc_url(trailingslashit(visibility_api_base_url()));
    $logo_url    = esc_url(plugins_url('assets/icon.svg', VISIBILITY_PLUGIN_FILE));
    ?>
    <div class="wrap" style="max-width:640px">
      <div style="display:flex;align-items:center;gap:14px;margin-bottom:8px">
        <img src="<?php echo esc_url($logo_url); ?>" alt="" width="42" height="42" style="display:block;border-radius:10px"/>
        <h1 style="margin:0;line-height:1.2"><?php echo esc_html__('Agentic SEO for Visibility', 'agentic-seo-visibility'); ?></h1>
      </div>
      <p class="description" style="margin-bottom:24px">
        <?php echo esc_html__('Connect this WordPress site to your Visibility project so your agents can publish here.', 'agentic-seo-visibility'); ?>
      </p>
      <?php
      // Admin JS relocates every `.notice` to sit directly after the first
      // <h1> unless it finds `wp-header-end` — and our <h1> lives inside a
      // flex row, so without this the notice becomes a flex sibling of the
      // logo and squeezes the title into a narrow column. Core styles this
      // element invisible; it exists purely to mark where notices belong.
      ?>
      <hr class="wp-header-end"/>

      <?php if ($status === 'paired') : ?>
        <div class="notice notice-success"><p><?php echo esc_html__('Connected to Visibility.', 'agentic-seo-visibility'); ?></p></div>
      <?php elseif ($status === 'disconnected') : ?>
        <div class="notice notice-info"><p><?php echo esc_html__('Disconnected. Generate a fresh pairing code in Visibility to reconnect.', 'agentic-seo-visibility'); ?></p></div>
      <?php endif; ?>
      <?php if (!$paired && $status !== 'disconnected' && (int) get_option('visibility_disconnected_by_app', 0)) : ?>
        <div class="notice notice-info"><p><?php
          /* translators: %s: date and time. */
          echo esc_html(sprintf(__('Disconnected from Visibility on %s. Generate a fresh pairing code in Visibility to reconnect.', 'agentic-seo-visibility'), date_i18n(get_option('date_format') . ' ' . get_option('time_format'), (int) get_option('visibility_disconnected_by_app'))));
        ?></p></div>
      <?php endif; ?>
      <?php if ($status === 'paused') : ?>
        <div class="notice notice-warning"><p><?php echo esc_html__('Paused. Visibility can no longer change this site.', 'agentic-seo-visibility'); ?></p></div>
      <?php elseif ($status === 'resumed') : ?>
        <div class="notice notice-success"><p><?php echo esc_html__('Resumed.', 'agentic-seo-visibility'); ?></p></div>
      <?php elseif ($status === 'signing_saved') : ?>
        <div class="notice notice-success"><p><?php echo esc_html__('Connection security saved.', 'agentic-seo-visibility'); ?></p></div>
      <?php elseif ($status === 'profile_saved') : ?>
        <div class="notice notice-success"><p><?php echo esc_html__('Site profile setting saved.', 'agentic-seo-visibility'); ?></p></div>
      <?php elseif ($status === 'checked_in') : ?>
        <div class="notice notice-success"><p><?php echo esc_html__('Checked in with Visibility. The settings below are the latest.', 'agentic-seo-visibility'); ?></p></div>
      <?php elseif ($status === 'author_saved') : ?>
        <div class="notice notice-success"><p><?php echo esc_html__('Default author saved.', 'agentic-seo-visibility'); ?></p></div>
      <?php elseif ($status === 'error') : ?>
        <div class="notice notice-error"><p><?php echo esc_html($error_msg ?: __('Pairing failed.', 'agentic-seo-visibility')); ?></p></div>
      <?php endif; ?>

      <?php
      // Where this site's SEO tags come from. WordPress core has no meta
      // description and no Open Graph at all, so without an SEO plugin the
      // fields agents write would have nowhere to go — we render them
      // ourselves instead, and stand down the moment a real one is installed.
      $seo_plugin = Visibility_SEO_Head::no_seo_plugin_active() ? null : Visibility_SEO::adapter()->name();
      ?>
      <?php if ($seo_plugin === null) : ?>
        <div class="notice notice-warning">
          <p>
            <strong><?php echo esc_html__('No SEO plugin detected.', 'agentic-seo-visibility'); ?></strong>
            <?php echo esc_html__('Visibility is emitting the SEO tags for your posts directly — meta description, Open Graph, canonical and structured data.', 'agentic-seo-visibility'); ?>
          </p>
          <p>
            <?php echo esc_html__('That covers the basics, but a dedicated SEO plugin gives you sitemaps, redirects, schema controls and the ability to edit these fields alongside your other posts. Install Yoast SEO, Rank Math or All in One SEO and Visibility will hand the tags over automatically — nothing you have written is lost.', 'agentic-seo-visibility'); ?>
          </p>
        </div>
      <?php else : ?>
        <div class="notice notice-info">
          <p>
            <?php
            printf(
              /* translators: %s: the active SEO plugin's name. */
              esc_html__('SEO tags are managed by %s. Visibility writes each post\'s title, description, focus keyword and social tags into it, and does not emit any tags of its own.', 'agentic-seo-visibility'),
              '<strong>' . esc_html($seo_plugin) . '</strong>'
            );
            ?>
          </p>
        </div>
      <?php endif; ?>

      <div style="background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:24px 28px">
        <?php if ($paired) : ?>
          <h2 style="margin-top:0;display:flex;align-items:center;gap:8px;font-size:16px">
            <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#00a32a"></span>
            <?php echo esc_html__('Connected', 'agentic-seo-visibility'); ?>
          </h2>
          <table class="form-table" role="presentation">
            <tr>
              <th scope="row"><?php echo esc_html__('Company', 'agentic-seo-visibility'); ?></th>
              <td>
                <?php if ($company_name !== '') : ?>
                  <strong><?php echo esc_html($company_name); ?></strong>
                <?php else : ?>
                  <em style="color:#646970"><?php echo esc_html__('—', 'agentic-seo-visibility'); ?></em>
                <?php endif; ?>
              </td>
            </tr>
            <tr>
              <th scope="row"><?php echo esc_html__('Project', 'agentic-seo-visibility'); ?></th>
              <td>
                <?php if ($project_name !== '') : ?>
                  <strong><?php echo esc_html($project_name); ?></strong>
                  <br/><span style="color:#646970;font-size:11px;font-family:ui-monospace,Menlo,Consolas,monospace"><?php echo esc_html($project_id); ?></span>
                <?php else : ?>
                  <code><?php echo esc_html($project_id); ?></code>
                <?php endif; ?>
              </td>
            </tr>
            <tr>
              <th scope="row"><?php echo esc_html__('Paired at', 'agentic-seo-visibility'); ?></th>
              <td><?php echo $paired_at ? esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $paired_at)) : '—'; ?></td>
            </tr>
            <tr>
              <th scope="row"><?php echo esc_html__('Last seen', 'agentic-seo-visibility'); ?></th>
              <td><?php echo $last_seen_at ? esc_html(human_time_diff($last_seen_at, time())) . ' ' . esc_html__('ago', 'agentic-seo-visibility') : esc_html__('Never', 'agentic-seo-visibility'); ?></td>
            </tr>
            <tr>
              <th scope="row"><?php echo esc_html__('Last check for approved changes', 'agentic-seo-visibility'); ?></th>
              <td id="visibility-last-tick"><?php
                $tick = Visibility_Requests::last_tick();
                if ($tick) {
                  echo esc_html(human_time_diff((int) $tick['at'], time())) . ' ' . esc_html__('ago', 'agentic-seo-visibility');
                  if (Visibility_Requests::system_cron()) echo ' &middot; ' . esc_html__('by a system cron job', 'agentic-seo-visibility');
                } else {
                  echo esc_html__('Not yet', 'agentic-seo-visibility');
                }
              ?>
                <p class="description"><?php echo esc_html__('Approved changes are sent to this site and applied at once. As a backup the plugin also checks every 5 minutes, but WordPress only runs that check when someone visits the site. On a quiet site, add a real cron job that runs "wp cron event run --due-now" every 5 minutes (or calls wp-cron.php) so the backup never waits.', 'agentic-seo-visibility'); ?></p>
              </td>
            </tr>
            <tr>
              <th scope="row"><?php echo esc_html__('Plugin version', 'agentic-seo-visibility'); ?></th>
              <td><?php echo esc_html(VISIBILITY_PLUGIN_VERSION); ?></td>
            </tr>
          </table>

          <p style="margin-top:24px">
            <a href="<?php echo esc_url($dashboard); ?>" target="_blank" rel="noopener" class="button button-primary">
              <?php echo esc_html__('Open Visibility dashboard', 'agentic-seo-visibility'); ?>
            </a>
          </p>

          <hr style="margin:28px 0" />
          <form method="post" action="<?php echo esc_url($action_url); ?>" id="visibility-signing">
            <?php wp_nonce_field('visibility_signing'); ?>
            <input type="hidden" name="action" value="visibility_signing" />
            <label>
              <input type="checkbox" name="visibility_require_signature" value="1" <?php checked(Visibility_Auth::required()); ?> />
              <?php echo esc_html__('Only accept signed requests from Visibility', 'agentic-seo-visibility'); ?>
            </label>
            <p class="description"><?php echo esc_html__('Visibility signs every request with the connection key, a timestamp and a one-time number, so a copied request cannot be replayed. Turn this on once your Visibility app signs requests; requests with only the key are then refused. Visibility can also rotate the key from its settings.', 'agentic-seo-visibility'); ?></p>
            <p><button type="submit" class="button"><?php echo esc_html__('Save', 'agentic-seo-visibility'); ?></button></p>
          </form>

          <hr style="margin:28px 0" />
          <form method="post" action="<?php echo esc_url($action_url); ?>" onsubmit="return confirm('<?php echo esc_js(__('Disconnect this site from Visibility?', 'agentic-seo-visibility')); ?>')">
            <?php wp_nonce_field('visibility_disconnect'); ?>
            <input type="hidden" name="action" value="visibility_disconnect" />
            <p class="description" style="margin-bottom:12px">
              <?php echo esc_html__('Disconnecting removes the saved token from this site. Your data on Visibility stays intact.', 'agentic-seo-visibility'); ?>
            </p>
            <button type="submit" class="button button-link-delete"><?php echo esc_html__('Disconnect', 'agentic-seo-visibility'); ?></button>
          </form>

        <?php else : ?>
          <h2 style="margin-top:0;font-size:16px"><?php echo esc_html__('Connect this site', 'agentic-seo-visibility'); ?></h2>
          <ol style="margin-left:18px;line-height:1.6">
            <li>
              <?php
              echo wp_kses(
                sprintf(
                  /* translators: %s: link to Visibility dashboard */
                  __('In <a href="%s" target="_blank" rel="noopener">Visibility</a>, open <strong>Settings › Integrations</strong>, pick your website, click <strong>Connect</strong> and choose <strong>WordPress</strong> to get a pairing code.', 'agentic-seo-visibility'),
                  $dashboard
                ),
                ['a' => ['href' => [], 'target' => [], 'rel' => []], 'strong' => []]
              );
              ?>
            </li>
            <li><?php echo esc_html__('Paste the code below and click Connect.', 'agentic-seo-visibility'); ?></li>
          </ol>

          <?php if (!Visibility_Client::https_ok()) : ?>
            <div class="notice notice-warning inline"><p><?php echo esc_html__('This site\'s address starts with http://, so it can\'t be paired: the key Visibility uses would travel unencrypted. Switch the site to https:// first. For a local test site, add define(\'VISIBILITY_ALLOW_HTTP\', true); to wp-config.php.', 'agentic-seo-visibility'); ?></p></div>
          <?php endif; ?>
          <form method="post" action="<?php echo esc_url($action_url); ?>" style="margin-top:20px">
            <?php wp_nonce_field('visibility_pair'); ?>
            <input type="hidden" name="action" value="visibility_pair" />
            <label for="visibility_pairing_code" style="display:block;font-weight:600;margin-bottom:6px">
              <?php echo esc_html__('Pairing code', 'agentic-seo-visibility'); ?>
            </label>
            <div style="position:relative;max-width:25em">
              <input
                type="text"
                id="visibility_pairing_code"
                name="visibility_pairing_code"
                placeholder="VIS-XXXX-XX"
                autocomplete="off"
                autocapitalize="characters"
                spellcheck="false"
                class="regular-text code"
                style="width:100%;padding-right:38px;font-family:ui-monospace,Menlo,Consolas,monospace;letter-spacing:0.15em;text-transform:uppercase;font-size:15px"
                required
              />
              <button
                type="button"
                id="visibility_paste_btn"
                title="<?php echo esc_attr__('Paste from clipboard', 'agentic-seo-visibility'); ?>"
                aria-label="<?php echo esc_attr__('Paste from clipboard', 'agentic-seo-visibility'); ?>"
                style="position:absolute;top:50%;right:4px;transform:translateY(-50%);width:30px;height:30px;display:flex;align-items:center;justify-content:center;border:none;background:transparent;cursor:pointer;color:#646970;border-radius:4px"
                onmouseover="this.style.background='#f0f0f1';this.style.color='#1d2327'"
                onmouseout="this.style.background='transparent';this.style.color='#646970'"
              >
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                  <rect x="8" y="2" width="8" height="4" rx="1" ry="1"/>
                  <path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/>
                </svg>
              </button>
            </div>
            <p class="description"><?php echo esc_html__('Codes expire after 10 minutes.', 'agentic-seo-visibility'); ?></p>
            <script>
              (function () {
                var btn = document.getElementById('visibility_paste_btn');
                var input = document.getElementById('visibility_pairing_code');
                if (!btn || !input) return;
                btn.addEventListener('click', async function () {
                  try {
                    if (!navigator.clipboard || !navigator.clipboard.readText) {
                      // No clipboard API — fall back to focusing the input.
                      input.focus();
                      return;
                    }
                    var text = await navigator.clipboard.readText();
                    if (typeof text === 'string' && text.length) {
                      input.value = text.trim().toUpperCase();
                      input.dispatchEvent(new Event('input', { bubbles: true }));
                      input.focus();
                    }
                  } catch (err) {
                    // Likely a permission rejection — focus the input so the user can paste manually.
                    input.focus();
                  }
                });
              })();
            </script>
            <p style="margin-top:20px">
              <button type="submit" class="button button-primary"><?php echo esc_html__('Connect', 'agentic-seo-visibility'); ?></button>
            </p>
          </form>
        <?php endif; ?>
      </div>
      <?php if ($paired) $this->render_controls_box(); ?>
      <?php if ($paired) $this->render_author_box(); ?>
    </div>
    <?php
  }
}
