<?php
// wp eval-file bb-page.php <title> <image id>: a Beaver Builder page built
// through Beaver Builder's own model (as its editor does), then published
// with save_layout(). Prints the post id and the module node ids as JSON.
list($title, $img) = $args;
wp_set_current_user(1);
$pid = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => $title, 'post_content' => '']);
FLBuilderModel::set_post_id($pid);
$_POST['fl_builder_data'] = ['post_id' => $pid];
FLBuilderModel::enable();
$row = FLBuilderModel::add_row('1-col');
$col = null;
foreach (FLBuilderModel::get_layout_data(null, $pid) as $n) if ($n->type === 'column') $col = $n->node;
$mk = function ($type, $settings) use ($col) {
  $defaults = FLBuilderModel::get_module_defaults($type);
  foreach ($settings as $k => $v) $defaults->$k = $v;
  $m = FLBuilderModel::add_module($type, $defaults, $col);
  return $m->node;
};
$ids = [];
$ids['heading'] = $mk('heading', ['heading' => 'Original heading', 'tag' => 'h2', 'link' => '']);
$ids['text'] = $mk('rich-text', ['text' => '<p>First paragraph with <strong>bold</strong> words.</p><p>Second paragraph.</p>']);
$ids['photo'] = $mk('photo', ['photo_source' => 'library', 'photo' => (int) $img, 'photo_src' => wp_get_attachment_url((int) $img), 'caption' => 'Photo caption', 'show_caption' => 'below']);
$ids['button'] = $mk('button', ['text' => 'Click me', 'link' => 'https://example.com/old']);
$ids['callout'] = $mk('callout', ['title' => 'Callout title', 'text' => '<p>Callout text</p>', 'btn_text' => 'Go', 'link' => '/old-link/', 'title_tag' => 'h3']);
// Outside the editor nodes are added to the current (published) layout; the
// editor works on the draft, so copy it there and publish as the editor does.
FLBuilderModel::update_layout_data(FLBuilderModel::get_layout_data('published', $pid), 'draft', $pid);
FLBuilderModel::save_layout(true);
echo wp_json_encode(['postId' => $pid, 'ids' => $ids]);
