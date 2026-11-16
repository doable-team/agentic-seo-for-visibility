<?php
/**
 * Test-only stand-in for Avada's SEO Page Options, as Avada's public code
 * shows them (includes/class-avada-head.php, Fusion_Data_PostMeta): the page
 * options array in post meta _fusion with seo_title, meta_description,
 * seo_index / seo_follow ("no"); the title through pre_get_document_title,
 * robots through wp_robots, and - while the Open Graph option
 * (fusion_options[status_opengraph]) is on - <meta name="description"> and
 * og:title / og:description from the same fields. Never shipped.
 */
define('AVADA_VERSION', '7.13-standin');
class Avada {}
add_theme_support('title-tag');
function vis_avada_opt($id, $k) { $m = get_post_meta($id, '_fusion', true); return is_array($m) && isset($m[$k]) ? $m[$k] : ''; }
add_filter('pre_get_document_title', function ($t) { if (is_singular() && ($v = vis_avada_opt(get_queried_object_id(), 'seo_title')) !== '') return esc_html($v); return $t; });
add_filter('wp_robots', function ($r) { if (is_singular()) { $id = get_queried_object_id(); if (vis_avada_opt($id, 'seo_index') === 'no') { $r['noindex'] = true; unset($r['index']); } if (vis_avada_opt($id, 'seo_follow') === 'no') { $r['nofollow'] = true; unset($r['follow']); } } return $r; });
add_action('wp_head', function () {
  $o = get_option('fusion_options', []);
  if (!is_singular() || empty($o['status_opengraph'])) return;
  $id = get_queried_object_id();
  $d = vis_avada_opt($id, 'meta_description');
  if ($d !== '') printf('<meta name="description" content="%s"/>' . "\n", esc_attr($d));
  printf('<meta property="og:title" content="%s"/>' . "\n", esc_attr(vis_avada_opt($id, 'seo_title') ?: get_the_title($id)));
  if ($d !== '') printf('<meta property="og:description" content="%s"/>' . "\n", esc_attr($d));
}, 5);
