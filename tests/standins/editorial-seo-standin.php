<?php
/**
 * Plugin Name: Custom SEO STAND-IN (Visibility tests only)
 * Description: Test-only stand-in for a site's own SEO plugin that Visibility
 * does not know (like the "Editorial SEO" plugin on a customer site). It
 * registers its fields as post meta with show_in_rest, as that plugin does:
 * _sse_seo_title, _sse_seo_description, _sse_seo_keyword (comma list, first =
 * primary), _sse_seo_robots_index / _sse_seo_robots_follow ("" = default,
 * "noindex" / "nofollow"), _sse_seo_canonical, _sse_seo_og_image_id (int),
 * _sse_seo_schema_type, _sse_seo_howto, _sse_seo_score (int); and prints the
 * title, description, robots, canonical, Open Graph and Twitter tags from them.
 * Never shipped in the plugin zip.
 */

add_action('init', function () {
  $strings = ['_sse_seo_title', '_sse_seo_description', '_sse_seo_keyword', '_sse_seo_robots_index', '_sse_seo_robots_follow', '_sse_seo_canonical', '_sse_seo_schema_type', '_sse_seo_howto'];
  foreach (['post', 'page'] as $type) {
    foreach ($strings as $k) {
      register_post_meta($type, $k, ['type' => 'string', 'single' => true, 'show_in_rest' => true, 'default' => '', 'auth_callback' => function () { return current_user_can('edit_posts'); }]);
    }
    foreach (['_sse_seo_og_image_id', '_sse_seo_score'] as $k) {
      register_post_meta($type, $k, ['type' => 'integer', 'single' => true, 'show_in_rest' => true, 'default' => 0, 'auth_callback' => function () { return current_user_can('edit_posts'); }]);
    }
  }
});

function vis_sse_get($k) {
  if (!is_singular()) return '';
  return (string) get_post_meta(get_queried_object_id(), $k, true);
}

add_filter('pre_get_document_title', function ($t) {
  $v = vis_sse_get('_sse_seo_title');
  return $v !== '' ? $v : $t;
}, 30);

add_filter('wp_robots', function ($r) {
  if (!is_singular()) return $r;
  $i = vis_sse_get('_sse_seo_robots_index');
  $f = vis_sse_get('_sse_seo_robots_follow');
  $r[$i === 'noindex' ? 'noindex' : 'index'] = true;
  $r[$f === 'nofollow' ? 'nofollow' : 'follow'] = true;
  return $r;
}, 30);

add_action('wp_head', function () {
  if (!is_singular()) return;
  $id = get_queried_object_id();
  $title = vis_sse_get('_sse_seo_title');
  if ($title === '') $title = get_the_title($id);
  $desc = vis_sse_get('_sse_seo_description');
  $canon = vis_sse_get('_sse_seo_canonical');
  echo "<!-- custom seo standin -->\n";
  if ($desc !== '') printf("<meta name=\"description\" content=\"%s\">\n", esc_attr($desc));
  if ($canon !== '') {
    remove_action('wp_head', 'rel_canonical');
    printf("<link rel=\"canonical\" href=\"%s\">\n", esc_url($canon));
  }
  printf("<meta property=\"og:title\" content=\"%s\">\n", esc_attr($title));
  if ($desc !== '') printf("<meta property=\"og:description\" content=\"%s\">\n", esc_attr($desc));
  printf("<meta property=\"og:type\" content=\"article\">\n");
  printf("<meta name=\"twitter:card\" content=\"summary_large_image\">\n");
  printf("<meta name=\"twitter:title\" content=\"%s\">\n", esc_attr($title));
}, 1);
