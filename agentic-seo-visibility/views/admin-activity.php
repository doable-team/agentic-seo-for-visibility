<?php
/** Visibility — Activity (history) page. */
if (!defined('ABSPATH')) {
  exit;
}
?>
<div class="wrap visibility-admin">
  <h1><?php echo esc_html__('Agentic SEO — Activity', 'agentic-seo-visibility'); ?></h1>
  <p class="description">
    <?php echo esc_html__('Every approved, rejected, executed, and expired AI action against this site.', 'agentic-seo-visibility'); ?>
  </p>
  <div id="visibility-activity-root" class="visibility-inbox-root">
    <div class="visibility-inbox-toolbar">
      <select id="visibility-activity-filter">
        <option value="any"><?php echo esc_html__('All statuses', 'agentic-seo-visibility'); ?></option>
        <option value="executed"><?php echo esc_html__('Executed', 'agentic-seo-visibility'); ?></option>
        <option value="rejected"><?php echo esc_html__('Rejected', 'agentic-seo-visibility'); ?></option>
        <option value="failed"><?php echo esc_html__('Failed', 'agentic-seo-visibility'); ?></option>
        <option value="expired"><?php echo esc_html__('Expired', 'agentic-seo-visibility'); ?></option>
        <option value="approved"><?php echo esc_html__('Approved (pending execution)', 'agentic-seo-visibility'); ?></option>
      </select>
      <button type="button" class="button" id="visibility-activity-refresh">
        <?php echo esc_html__('Refresh', 'agentic-seo-visibility'); ?>
      </button>
    </div>
    <div id="visibility-activity-list" class="visibility-inbox-list">
      <p><?php echo esc_html__('Loading…', 'agentic-seo-visibility'); ?></p>
    </div>
  </div>
</div>
