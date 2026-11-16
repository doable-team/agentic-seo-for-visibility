<?php
/**
 * Plugin Name: Bricks / Oxygen / Breakdance detection STAND-IN (Visibility tests only, not the real builders)
 * Description: Test-only. Defines the version constant of ONE paid builder,
 * chosen by the option vis_standin_builder (bricks | oxygen | breakdance |
 * oxygen6), the way the real plugins/theme define them (BRICKS_VERSION,
 * CT_VERSION, const __BREAKDANCE_VERSION + BREAKDANCE_MODE). Page data is
 * written by the test in each builder's documented storage format. Nothing
 * is rendered: Visibility only reads these builders. Never shipped.
 */
$vis_b = get_option('vis_standin_builder', '');
if ($vis_b === 'bricks') { define('BRICKS_VERSION', '2.0-standin'); define('BRICKS_DB_PAGE_CONTENT', '_bricks_page_content_2'); }
if ($vis_b === 'oxygen') define('CT_VERSION', '4.9-standin');
if ($vis_b === 'breakdance') { define('__BREAKDANCE_VERSION', '2.6-standin'); define('BREAKDANCE_MODE', 'breakdance'); }
if ($vis_b === 'oxygen6') { define('__BREAKDANCE_VERSION', '6.1-standin'); define('BREAKDANCE_MODE', 'oxygen'); }
