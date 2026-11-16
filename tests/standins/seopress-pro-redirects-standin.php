<?php
/**
 * Plugin Name: SEOPress Pro redirections STAND-IN (Visibility tests only, not SEOPress Pro)
 * Description: Test-only stand-in with the parts of SEOPress Pro's
 * redirections that Visibility uses, as SEOPress documents them ("Enable 301
 * redirections and 404 monitoring": origin URL = relative path without the
 * domain, absolute destination) and as the free SEOPress code reads them:
 * SEOPRESS_PRO_VERSION, the post type seopress_404 whose post_title is the
 * origin path, meta _seopress_redirections_enabled ('yes'),
 * _seopress_redirections_type (301/302/307), _seopress_redirections_value
 * (absolute URL), _seopress_redirections_logged_status ('both'). Redirects are
 * served on template_redirect. Never shipped.
 */
define('SEOPRESS_PRO_VERSION', '9.0-standin');
add_action('init', function () {
  register_post_type('seopress_404', ['public' => false, 'show_ui' => true, 'label' => 'Redirections', 'supports' => ['title']]);
}, 0);
add_action('template_redirect', function () {
  $path = trim(explode('?', wp_unslash($_SERVER['REQUEST_URI']))[0], '/');
  $q = get_posts(['post_type' => 'seopress_404', 'post_status' => 'publish', 'title' => $path, 'numberposts' => 1]);
  if (!$q) return;
  $id = $q[0]->ID;
  if (get_post_meta($id, '_seopress_redirections_enabled', true) !== 'yes') return;
  wp_redirect(get_post_meta($id, '_seopress_redirections_value', true), (int) get_post_meta($id, '_seopress_redirections_type', true), 'SEOPress');
  exit;
}, 1);
