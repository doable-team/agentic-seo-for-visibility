<?php
/** Visibility — Approval Inbox admin page. */
if (!defined('ABSPATH')) {
  exit;
}
?>
<div class="wrap visibility-admin">
  <h1><?php echo esc_html__('Agentic SEO — Approval Inbox', 'agentic-seo-for-visibility'); ?></h1>
  <p class="description">
    <?php echo esc_html__('AI agents from your Visibility project request changes here. Review and approve, or reject with a note that the agent will see in its next run.', 'agentic-seo-for-visibility'); ?>
  </p>

  <div id="visibility-inbox-root" class="visibility-inbox-root">
    <div class="visibility-inbox-toolbar">
      <button type="button" class="button" id="visibility-inbox-refresh">
        <?php echo esc_html__('Refresh', 'agentic-seo-for-visibility'); ?>
      </button>
      <span id="visibility-inbox-status" class="visibility-inbox-status"></span>
    </div>
    <div id="visibility-inbox-list" class="visibility-inbox-list" data-empty-text="<?php echo esc_attr__('No pending requests. The inbox refreshes automatically every 30 seconds.', 'agentic-seo-for-visibility'); ?>">
      <p><?php echo esc_html__('Loading…', 'agentic-seo-for-visibility'); ?></p>
    </div>
  </div>

  <template id="visibility-inbox-card-template">
    <div class="visibility-card" data-id="">
      <div class="visibility-card-head">
        <span class="visibility-pill" data-role="risk"></span>
        <span class="visibility-pill" data-role="status"></span>
        <span class="visibility-pill visibility-pill-auto" data-role="auto" hidden><?php echo esc_html__('Auto-approved', 'agentic-seo-for-visibility'); ?></span>
        <span class="visibility-group" data-role="group"></span>
      </div>
      <h3 class="visibility-card-title" data-role="title"></h3>
      <p class="visibility-card-subtitle" data-role="subtitle"></p>
      <pre class="visibility-card-detail" data-role="detail"></pre>
      <div class="visibility-card-actions">
        <button type="button" class="button button-primary" data-action="approve">
          <?php echo esc_html__('Approve', 'agentic-seo-for-visibility'); ?>
        </button>
        <button type="button" class="button" data-action="reject">
          <?php echo esc_html__('Reject', 'agentic-seo-for-visibility'); ?>
        </button>
      </div>
      <div class="visibility-reject-form" hidden>
        <textarea rows="3" placeholder="<?php echo esc_attr__('Why are you rejecting? The agent will see this note.', 'agentic-seo-for-visibility'); ?>"></textarea>
        <button type="button" class="button button-secondary" data-action="reject-confirm">
          <?php echo esc_html__('Confirm reject', 'agentic-seo-for-visibility'); ?>
        </button>
        <button type="button" class="button-link" data-action="reject-cancel">
          <?php echo esc_html__('Cancel', 'agentic-seo-for-visibility'); ?>
        </button>
      </div>
    </div>
  </template>
</div>
