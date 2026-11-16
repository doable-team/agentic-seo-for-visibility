<?php
/**
 * Beaver Builder (Lite and paid) adapter (C-37).
 *
 * A Beaver Builder page keeps its layout in post meta _fl_builder_data: an
 * array of node objects (rows, column groups, columns, modules) keyed by node
 * id, each module with a `settings` object whose `type` is the module slug
 * (heading, rich-text, photo, button, callout, cta, …). The builder also keeps
 * a working copy in _fl_builder_draft and a plain rendering in post_content
 * (for search and the classic editor).
 *
 * Edits go through Beaver Builder's own model: FLBuilderModel::get_layout_data()
 * to read and update_layout_data() to write the published layout (and the
 * draft when it is the same, so the next save in the builder keeps the
 * change), then delete_all_asset_cache() so the page CSS/JS is rebuilt, and
 * post_content is re-rendered with FLBuilder::render_editor_content(). A page
 * with unpublished changes open in the builder (draft different from the
 * published layout) is refused, so nobody's work is overwritten.
 *
 * Element ids are Beaver Builder's node ids.
 */

if (!defined('ABSPATH')) {
  exit;
}

class Visibility_Builder_Beaver implements Visibility_Builder_Adapter {

  /** module type => text settings (first = main text). */
  const TEXT_KEYS = [
    'heading'   => ['heading'],
    'rich-text' => ['text'],
    'button'    => ['text'],
    'callout'   => ['title', 'text', 'btn_text'],
    'cta'       => ['title', 'text', 'btn_text'],
    'photo'     => ['caption'],
  ];
  const HTML_KEYS = ['text'];
  /** module type => link setting. */
  const LINK_KEYS = ['heading' => 'link', 'button' => 'link', 'callout' => 'link', 'cta' => 'btn_link', 'photo' => 'link_url'];
  /** module type => photo setting (attachment id). */
  const IMAGE_KEYS = ['photo' => 'photo', 'callout' => 'photo'];
  /** module type => heading level setting. */
  const LEVEL_KEYS = ['heading' => 'tag', 'callout' => 'title_tag', 'cta' => 'title_tag'];

  public function available() {
    return defined('FL_BUILDER_VERSION') && class_exists('FLBuilderModel') && method_exists('FLBuilderModel', 'update_layout_data') && method_exists('FLBuilderModel', 'get_layout_data');
  }

  private function data($post_id, $status = 'published') {
    $d = FLBuilderModel::get_layout_data($status, $post_id);
    return is_array($d) ? $d : [];
  }

  public function elements($post) {
    $out = [];
    foreach ($this->ordered($this->data($post->ID)) as $node) {
      if (!is_object($node) || ($node->type ?? '') !== 'module' || !isset($node->settings) || !is_object($node->settings)) continue;
      $s = $node->settings;
      $type = isset($s->type) ? (string) $s->type : '';
      $texts = [];
      foreach (isset(self::TEXT_KEYS[$type]) ? self::TEXT_KEYS[$type] : [] as $k) {
        if (isset($s->$k) && is_string($s->$k)) $texts[$k] = $s->$k;
      }
      $attrs = [];
      if (isset(self::IMAGE_KEYS[$type])) {
        $ik = self::IMAGE_KEYS[$type];
        $att = isset($s->$ik) ? (int) $s->$ik : 0;
        if ($att) {
          $attrs['imageId'] = $att;
          $attrs['url'] = (string) wp_get_attachment_url($att);
          $attrs['alt'] = (string) get_post_meta($att, '_wp_attachment_image_alt', true);
        } elseif (!empty($s->photo_url)) {
          $attrs['url'] = (string) $s->photo_url;
        }
      }
      if (isset(self::LINK_KEYS[$type])) { $lk = self::LINK_KEYS[$type]; if (isset($s->$lk) && is_string($s->$lk) && $s->$lk !== '') $attrs['href'] = $s->$lk; }
      if (isset(self::LEVEL_KEYS[$type])) { $lv = self::LEVEL_KEYS[$type]; if (isset($s->$lv) && preg_match('/^h([1-6])$/', (string) $s->$lv, $m)) $attrs['level'] = (int) $m[1]; }
      $main = $texts ? reset($texts) : '';
      $out[] = [
        'elementId'  => (string) $node->node,
        'elType'     => 'module',
        'widgetType' => $type,
        'text'       => trim(preg_replace('/\s+/', ' ', wp_strip_all_tags((string) $main))),
        'fields'     => empty($texts) ? new stdClass() : $texts,
        'attrs'      => empty($attrs) ? new stdClass() : $attrs,
        'editable'   => !empty($texts) || isset(self::IMAGE_KEYS[$type]) || isset(self::LINK_KEYS[$type]),
        'global'     => !empty($node->template_id),
      ];
    }
    return $out;
  }

  /** Nodes in page order (by parent, then position). */
  private function ordered(array $data) {
    $kids = [];
    foreach ($data as $id => $n) {
      if (!is_object($n)) continue;
      $p = isset($n->parent) && $n->parent ? (string) $n->parent : '';
      $kids[$p][] = $n;
    }
    foreach ($kids as &$list) usort($list, function ($a, $b) { return (int) ($a->position ?? 0) <=> (int) ($b->position ?? 0); });
    unset($list);
    $out = [];
    $walk = function ($parent) use (&$walk, &$kids, &$out) {
      foreach (isset($kids[$parent]) ? $kids[$parent] : [] as $n) {
        $out[] = $n;
        $walk((string) $n->node);
      }
    };
    $walk('');
    return $out;
  }

  public function edit($post, array $edits) {
    $pid = (int) $post->ID;
    // The stored layout as is: get_layout_data() adds settings from Beaver
    // Builder's compatibility filters, which would rewrite every node.
    $data = get_post_meta($pid, '_fl_builder_data', true);
    $data = is_array($data) ? $data : [];
    if (!$data) return new WP_Error('unsupported', 'This page has no Beaver Builder layout.');
    $raw_pub = get_post_meta($pid, '_fl_builder_data', true);
    $raw_draft = get_post_meta($pid, '_fl_builder_draft', true);
    $draft_same = $raw_draft === '' || $raw_draft === false || maybe_serialize($raw_draft) === maybe_serialize($raw_pub);
    if (!$draft_same) {
      return new WP_Error('conflict', 'This page has changes in Beaver Builder that are not published yet. Publish or discard them in Beaver Builder first, so they are not overwritten.');
    }
    $diff = []; $notes = []; $alts = [];
    foreach ($edits as $i => $e) {
      $id = (string) $e['target']['elementId'];
      $op = isset($e['op']) ? (string) $e['op'] : '';
      if (!isset($data[$id]) || !is_object($data[$id])) return new WP_Error('not_found', sprintf('edit %d: no element %s on this page', $i, $id));
      $node = $data[$id];
      if (($node->type ?? '') !== 'module') return new WP_Error('unsupported', sprintf('edit %d: %s is a %s, not a module; target a module inside it', $i, $id, $node->type ?? 'node'));
      if (!empty($node->template_id)) return new WP_Error('unsupported', sprintf('edit %d: %s is a saved (global) module shared with other pages; Visibility does not change those', $i, $id));
      if (!isset($node->settings) || !is_object($node->settings)) return new WP_Error('unsupported', sprintf('edit %d: %s has no settings', $i, $id));
      $s = clone $node->settings;
      $type = (string) ($s->type ?? '');
      $before = (array) $node->settings;
      if ($op === 'replace_text') {
        if (!isset($e['text']) || !is_scalar($e['text'])) return new WP_Error('invalid', sprintf('edit %d: replace_text needs text', $i));
        $keys = isset(self::TEXT_KEYS[$type]) ? self::TEXT_KEYS[$type] : [];
        $field = isset($e['field']) && is_string($e['field']) ? $e['field'] : ($keys ? $keys[0] : null);
        if ($field === null || !in_array($field, $keys, true)) {
          return new WP_Error('unsupported', sprintf('edit %d: %s module "%s" has no text Visibility can change%s', $i, $type, $id, $keys ? ' (fields: ' . implode(', ', $keys) . ')' : ''));
        }
        $text = in_array($field, self::HTML_KEYS, true) ? wp_kses_post((string) $e['text']) : sanitize_text_field((string) $e['text']);
        $cur = isset($s->$field) ? (string) $s->$field : '';
        if (isset($e['find']) && is_scalar($e['find']) && (string) $e['find'] !== '') {
          if (strpos($cur, (string) $e['find']) === false) return new WP_Error('not_found', sprintf('edit %d: "%s" is not in %s.%s', $i, $e['find'], $id, $field));
          $text = str_replace((string) $e['find'], $text, $cur);
        }
        $s->$field = $text;
      } elseif ($op === 'set_attr') {
        $set = isset($e['attrs']) && is_array($e['attrs']) ? $e['attrs'] : [];
        if (!$set) return new WP_Error('invalid', sprintf('edit %d: set_attr needs attrs', $i));
        foreach ($set as $k => $v) {
          if ($k === 'alt') {
            $ik = isset(self::IMAGE_KEYS[$type]) ? self::IMAGE_KEYS[$type] : null;
            $att = $ik && isset($s->$ik) ? (int) $s->$ik : 0;
            if (!$att) return new WP_Error('not_found', sprintf('edit %d: module %s has no media library image (an image by URL has no alt setting in Beaver Builder)', $i, $id));
            $alts[$att] = sanitize_text_field((string) $v);
            $notes[] = sprintf('The alt text is stored on image %d in the media library (Beaver Builder shows that alt), so it shows wherever that image is used.', $att);
          } elseif ($k === 'href' || $k === 'url') {
            $lk = isset(self::LINK_KEYS[$type]) ? self::LINK_KEYS[$type] : null;
            if (!$lk) return new WP_Error('unsupported', sprintf('edit %d: a %s module has no link', $i, $type));
            $url = esc_url_raw((string) $v);
            if ($url === '') return new WP_Error('invalid', sprintf('edit %d: not a valid URL', $i));
            $s->$lk = $url;
            if ($type === 'photo') $s->link_type = 'url';
          } elseif ($k === 'level' && isset(self::LEVEL_KEYS[$type])) {
            $l = (int) $v;
            if ($l < 1 || $l > 6) return new WP_Error('invalid', sprintf('edit %d: level must be 1-6', $i));
            $lv = self::LEVEL_KEYS[$type];
            $s->$lv = 'h' . $l;
          } else {
            return new WP_Error('unsupported', sprintf('edit %d: attribute "%s" cannot be set on a %s module (use alt, href, level)', $i, $k, $type));
          }
        }
      } else {
        return new WP_Error('unsupported', sprintf('edit %d: "%s" is not supported on Beaver Builder pages (use replace_text or set_attr)', $i, $op));
      }
      $node = clone $node;
      $node->settings = $s;
      // Later edits of the same node build on this one.
      $data[$id] = $node;
      $diff[] = ['target' => ['elementId' => $id], 'blockName' => 'beaver-builder/' . $type, 'ops' => [$op], 'before' => $before, 'after' => (array) $s];
    }
    foreach ($alts as $att => $alt) update_post_meta($att, '_wp_attachment_image_alt', $alt);
    $saved = Visibility_Builders::as_editor($pid, function () use ($pid, $data, $raw_draft) {
      FLBuilderModel::update_layout_data($data, 'published', $pid);
      if ($raw_draft !== '' && $raw_draft !== false) FLBuilderModel::update_layout_data($data, 'draft', $pid);
      self::rebuild($pid, true);
      return true;
    });
    if (is_wp_error($saved)) return $saved;
    return ['diff' => $diff, 'notes' => array_values(array_unique($notes)), 'cssRegenerated' => true];
  }

  /** Drop the page's CSS/JS cache and re-render the plain copy in post_content. */
  public static function rebuild($post_id, $content = false) {
    if (!class_exists('FLBuilderModel')) return;
    FLBuilderModel::delete_all_asset_cache($post_id);
    if (method_exists('FLBuilderModel', 'delete_node_template_asset_cache')) FLBuilderModel::delete_node_template_asset_cache($post_id);
    if (!$content || !class_exists('FLBuilder') || !method_exists('FLBuilder', 'render_editor_content')) return;
    FLBuilderModel::set_post_id($post_id);
    try {
      $html = FLBuilder::render_editor_content();
    } finally {
      FLBuilderModel::reset_post_id();
    }
    if (is_string($html) && $html !== '' && $html !== get_post_field('post_content', $post_id, 'raw')) {
      Visibility_Content::save(['ID' => $post_id, 'post_content' => $html]);
    }
  }

  /** After a rollback restored _fl_builder_data, rebuild the page's assets. */
  public static function after_rollback($post_id) {
    if (get_post_meta($post_id, '_fl_builder_enabled', true)) self::rebuild($post_id);
  }
}

add_filter('visibility_builder_adapters', function ($a) {
  $a['beaver-builder'] = new Visibility_Builder_Beaver();
  return $a;
});
add_action('visibility_after_rollback', ['Visibility_Builder_Beaver', 'after_rollback']);
