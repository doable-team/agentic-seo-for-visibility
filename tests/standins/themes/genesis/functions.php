<?php
/**
 * Test-only stand-in for the Genesis Framework's In-Post SEO Settings, as
 * Genesis documents and stores them: post meta _genesis_title,
 * _genesis_description, _genesis_canonical_uri, _genesis_noindex,
 * _genesis_nofollow ("1"). Genesis prints the document title, the meta
 * description, the canonical and the robots directives from them; this
 * stand-in does the same. Never shipped.
 */
define('PARENT_THEME_VERSION', '3.6.0-standin');
function genesis() {}
add_theme_support('title-tag');
add_filter('pre_get_document_title', function ($t) { if (is_singular() && ($v = get_post_meta(get_queried_object_id(), '_genesis_title', true)) !== '') return $v; return $t; }, 15);
add_action('wp_head', function () {
  if (!is_singular()) return;
  $id = get_queried_object_id();
  if (($d = get_post_meta($id, '_genesis_description', true)) !== '') printf('<meta name="description" content="%s" />' . "\n", esc_attr($d));
  if (($c = get_post_meta($id, '_genesis_canonical_uri', true)) !== '') { remove_action('wp_head', 'rel_canonical'); printf('<link rel="canonical" href="%s" />' . "\n", esc_url($c)); }
}, 1);
add_filter('wp_robots', function ($r) { if (is_singular()) { $id = get_queried_object_id(); if (get_post_meta($id, '_genesis_noindex', true)) { $r['noindex'] = true; unset($r['index']); } if (get_post_meta($id, '_genesis_nofollow', true)) { $r['nofollow'] = true; unset($r['follow']); } } return $r; });
