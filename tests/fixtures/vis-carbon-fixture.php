<?php
/**
 * Plugin Name: Carbon Fields test fixture (Visibility tests only)
 * Description: Loads the real Carbon Fields library (from GitHub
 * htmlburger/carbon-fields, copied to wp-content/carbon-fields by the test,
 * PSR-4 autoload as its composer.json declares) and registers one post meta
 * container for pages, the way a theme or plugin using Carbon Fields does.
 * Never shipped.
 */
spl_autoload_register(function ($c) {
  if (strpos($c, 'Carbon_Fields\\') !== 0) return;
  $f = WP_CONTENT_DIR . '/carbon-fields/core/' . str_replace('\\', '/', substr($c, 14)) . '.php';
  if (is_file($f)) require $f;
});
// As Carbon Fields' own docs: boot first (it defines VERSION and loads config/functions itself).
add_action('after_setup_theme', function () {
  \Carbon_Fields\Carbon_Fields::boot();
});
add_action('carbon_fields_register_fields', function () {
  \Carbon_Fields\Container::make('post_meta', 'Page extras')
    ->where('post_type', '=', 'page')
    ->add_fields([
      \Carbon_Fields\Field::make('text', 'crb_tagline', 'Tagline'),
      \Carbon_Fields\Field::make('textarea', 'crb_intro', 'Intro'),
      \Carbon_Fields\Field::make('select', 'crb_level', 'Level')->set_options(['basic' => 'Basic', 'pro' => 'Pro']),
      \Carbon_Fields\Field::make('checkbox', 'crb_featured', 'Featured'),
      \Carbon_Fields\Field::make('image', 'crb_badge', 'Badge'),
      \Carbon_Fields\Field::make('complex', 'crb_rows', 'Rows')->add_fields([\Carbon_Fields\Field::make('text', 'label', 'Label')]),
      \Carbon_Fields\Field::make('text', 'crb_api_token', 'API token'),
    ]);
  \Carbon_Fields\Container::make('post_meta', 'Post only')->where('post_type', '=', 'post')->add_fields([\Carbon_Fields\Field::make('text', 'crb_post_only', 'Post only')]);
});
