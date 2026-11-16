<?php
/**
 * Plugin Name: WPBakery Page Builder STAND-IN (Visibility tests only, not WPBakery)
 * Description: Test-only stand-in. Defines WPB_VC_VERSION and renders the
 * WPBakery shortcodes Visibility edits (vc_row, vc_column, vc_column_text,
 * vc_custom_heading, vc_single_image, vc_btn) roughly the way WPBakery does,
 * following its developer docs (vc_map parameters: text in the shortcode body
 * for vc_column_text; heading text in `text`; image by attachment id in
 * `image`, alt from the media library; links as "url:<urlencoded>|title:…").
 * Pages built with it carry _wpb_vc_js_status = "true". Never shipped in the
 * plugin zip.
 */
define('WPB_VC_VERSION', '8.0-standin');
add_action('init', function () {
  add_shortcode('vc_row', function ($a, $c = '') { return '<div class="vc_row wpb_row">' . do_shortcode($c) . '</div>'; });
  add_shortcode('vc_column', function ($a, $c = '') { return '<div class="wpb_column vc_column_container">' . do_shortcode($c) . '</div>'; });
  add_shortcode('vc_column_text', function ($a, $c = '') { return '<div class="wpb_text_column wpb_content_element"><div class="wpb_wrapper">' . wpautop(do_shortcode($c)) . '</div></div>'; });
  add_shortcode('vc_custom_heading', function ($a) {
    $a = shortcode_atts(['text' => '', 'font_container' => 'tag:h2', 'link' => ''], $a);
    $tag = preg_match('/tag:(h[1-6]|p|div)/', $a['font_container'], $m) ? $m[1] : 'h2';
    $url = '';
    foreach (explode('|', $a['link']) as $p) if (strpos($p, 'url:') === 0) $url = rawurldecode(substr($p, 4));
    $t = esc_html($a['text']);
    return '<' . $tag . ' class="vc_custom_heading">' . ($url ? '<a href="' . esc_url($url) . '">' . $t . '</a>' : $t) . '</' . $tag . '>';
  });
  add_shortcode('vc_single_image', function ($a) {
    $a = shortcode_atts(['image' => '', 'title' => '', 'img_size' => 'full'], $a);
    $img = wp_get_attachment_image((int) $a['image'], $a['img_size']);
    return '<div class="wpb_single_image">' . ($a['title'] ? '<h2 class="wpb_heading">' . esc_html($a['title']) . '</h2>' : '') . $img . '</div>';
  });
  add_shortcode('vc_btn', function ($a) {
    $a = shortcode_atts(['title' => '', 'link' => ''], $a);
    $url = '';
    foreach (explode('|', $a['link']) as $p) if (strpos($p, 'url:') === 0) $url = rawurldecode(substr($p, 4));
    return '<a class="vc_btn3" href="' . esc_url($url) . '">' . esc_html($a['title']) . '</a>';
  });
});
