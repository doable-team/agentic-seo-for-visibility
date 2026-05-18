<?php
/** Visibility — Activity (history) page. */
if (!defined('ABSPATH')) {
  exit;
}
?>
<div class="wrap visibility-admin">
  <h1><?php echo esc_html__('Agentic SEO — Activity', 'agentic-seo-for-visibility'); ?></h1>
  <p class="description">
    <?php echo esc_html__('Every approved, rejected, executed, and expired AI action against this site.', 'agentic-seo-for-visibility'); ?>
  </p>
  <div id="visibility-activity-root" class="visibility-inbox-root">
    <div class="visibility-inbox-toolbar">
      <select id="visibility-activity-filter">
        <option value="any"><?php echo esc_html__('All statuses', 'agentic-seo-for-visibility'); ?></option>
        <option value="executed"><?php echo esc_html__('Executed', 'agentic-seo-for-visibility'); ?></option>
        <option value="rejected"><?php echo esc_html__('Rejected', 'agentic-seo-for-visibility'); ?></option>
        <option value="failed"><?php echo esc_html__('Failed', 'agentic-seo-for-visibility'); ?></option>
        <option value="expired"><?php echo esc_html__('Expired', 'agentic-seo-for-visibility'); ?></option>
        <option value="approved"><?php echo esc_html__('Approved (pending execution)', 'agentic-seo-for-visibility'); ?></option>
      </select>
      <button type="button" class="button" id="visibility-activity-refresh">
        <?php echo esc_html__('Refresh', 'agentic-seo-for-visibility'); ?>
      </button>
    </div>
    <div id="visibility-activity-list" class="visibility-inbox-list">
      <p><?php echo esc_html__('Loading…', 'agentic-seo-for-visibility'); ?></p>
    </div>
  </div>
</div>
