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
      <?php elseif ($status === 'error') : ?>
        <div class="notice notice-error"><p><?php echo esc_html($error_msg ?: __('Pairing failed.', 'agentic-seo-visibility')); ?></p></div>
      <?php endif; ?>

      <?php
      // Where this site's SEO tags come from. WordPress core has no meta
      // description and no Open Graph at all, so without an SEO plugin the
      // fields agents write would have nowhere to go — we render them
      // ourselves instead, and stand down the moment a real one is installed.
      $seo_plugin = Visibility_SEO_Head::no_seo_plugin_active() ? null
        : (defined('WPSEO_VERSION') ? 'Yoast SEO'
          : (defined('RANK_MATH_VERSION') ? 'Rank Math' : 'All in One SEO'));
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
                  __('Open the WordPress integration in <a href="%s" target="_blank" rel="noopener">your Visibility project</a> and click <strong>Connect via plugin</strong> to generate a code.', 'agentic-seo-visibility'),
                  $dashboard
                ),
                ['a' => ['href' => [], 'target' => [], 'rel' => []], 'strong' => []]
              );
              ?>
            </li>
            <li><?php echo esc_html__('Paste the code below and click Connect.', 'agentic-seo-visibility'); ?></li>
          </ol>

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
    </div>
    <?php
  }
}
