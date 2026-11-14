<?php
/**
 * Elementor (free + Pro) builder adapter (#320).
 *
 * Content lives in the _elementor_data JSON tree, not in post_content. Edits
 * change one widget's settings by element id and are saved through
 * Elementor's own Document::save(), so its CSS is cleared and rebuilt, its
 * caches are dropped and the page still opens in Elementor. Pro widgets live
 * in the same tree; Theme Builder templates (elementor_library) are not
 * edited.
 */

if (!defined('ABSPATH')) {
  exit;
}

class Visibility_Builder_Elementor implements Visibility_Builder_Adapter {

  /** widgetType => text settings, the first one is the main text. */
  const TEXT_KEYS = [
    'heading'         => ['title'],
    'text-editor'     => ['editor'],
    'button'          => ['text'],
    'icon-box'        => ['title_text', 'description_text'],
    'image-box'       => ['title_text', 'description_text'],
    'image'           => ['caption'],
    'testimonial'     => ['testimonial_content', 'testimonial_name', 'testimonial_job'],
    'alert'           => ['alert_title', 'alert_description'],
    'counter'         => ['title'],
    'progress'        => ['title'],
    'call-to-action'  => ['title', 'description', 'button'],
    'animated-headline' => ['before_text', 'highlighted_text', 'after_text'],
    'price-table'     => ['heading', 'sub_heading', 'footer_additional_info'],
    'flip-box'        => ['title_text_a', 'description_text_a', 'title_text_b', 'description_text_b', 'button_text'],
  ];
  /** Settings that hold HTML (rich text) rather than plain text. */
  const HTML_KEYS = ['editor', 'description_text', 'testimonial_content', 'alert_description', 'description', 'description_text_a', 'description_text_b'];
  /** widgetType => media setting whose alt is the image's alt. */
  const IMAGE_KEYS = ['image' => 'image', 'image-box' => 'image', 'testimonial' => 'testimonial_image', 'call-to-action' => 'bg_image'];

  public function available() {
    return defined('ELEMENTOR_VERSION') && class_exists('\Elementor\Plugin') && isset(\Elementor\Plugin::$instance->documents);
  }

  private function document($post) {
    $doc = \Elementor\Plugin::$instance->documents->get($post->ID, false);
    return ($doc && $doc->is_built_with_elementor()) ? $doc : null;
  }

  private function data($post) {
    $doc = $this->document($post);
    if ($doc) return (array) $doc->get_elements_data();
    $raw = get_post_meta($post->ID, '_elementor_data', true);
    $d = is_string($raw) ? json_decode($raw, true) : $raw;
    return is_array($d) ? $d : [];
  }

  public function elements($post) {
    $out = [];
    $walk = function ($els) use (&$walk, &$out) {
      foreach ((array) $els as $el) {
        if (!is_array($el)) continue;
        if (($el['elType'] ?? '') === 'widget') {
          $type = (string) ($el['widgetType'] ?? '');
          $set = isset($el['settings']) && is_array($el['settings']) ? $el['settings'] : [];
          $texts = [];
          foreach (isset(self::TEXT_KEYS[$type]) ? self::TEXT_KEYS[$type] : [] as $k) {
            if (isset($set[$k]) && is_string($set[$k])) $texts[$k] = $set[$k];
          }
          $attrs = [];
          if (isset(self::IMAGE_KEYS[$type]) && !empty($set[self::IMAGE_KEYS[$type]]) && is_array($set[self::IMAGE_KEYS[$type]])) {
            $m = $set[self::IMAGE_KEYS[$type]];
            $attrs['imageId'] = !empty($m['id']) ? (int) $m['id'] : null;
            $attrs['url'] = isset($m['url']) ? (string) $m['url'] : '';
            $attrs['alt'] = !empty($m['id']) ? (string) get_post_meta((int) $m['id'], '_wp_attachment_image_alt', true) : (string) ($m['alt'] ?? '');
          }
          if (isset($set['link']['url'])) $attrs['href'] = (string) $set['link']['url'];
          if ($type === 'heading') $attrs['level'] = isset($set['header_size']) ? (int) substr((string) $set['header_size'], 1) : 2;
          $main = $texts ? reset($texts) : '';
          $out[] = [
            'elementId'  => (string) ($el['id'] ?? ''),
            'elType'     => 'widget',
            'widgetType' => $type,
            'text'       => trim(preg_replace('/\s+/', ' ', wp_strip_all_tags((string) $main))),
            'fields'     => empty($texts) ? new stdClass() : $texts,
            'attrs'      => empty($attrs) ? new stdClass() : $attrs,
            'editable'   => !empty($texts) || isset(self::IMAGE_KEYS[$type]) || $type === 'heading',
          ];
        }
        if (!empty($el['elements'])) $walk($el['elements']);
      }
    };
    $walk($this->data($post));
    return $out;
  }

  /** Find the element by id and apply $fn to it in place. */
  private static function with_element(array &$els, $id, $fn) {
    foreach ($els as &$el) {
      if (!is_array($el)) continue;
      if ((string) ($el['id'] ?? '') === (string) $id) return $fn($el);
      if (!empty($el['elements']) && is_array($el['elements'])) {
        $r = self::with_element($el['elements'], $id, $fn);
        if ($r !== null) return $r;
      }
    }
    return null;
  }

  public function edit($post, array $edits) {
    $doc = $this->document($post);
    if (!$doc) return new WP_Error('unsupported', 'This page is not built with Elementor.');
    $data = $this->data($post);
    $diff = []; $notes = []; $alts = [];
    foreach ($edits as $i => $e) {
      $id = (string) $e['target']['elementId'];
      $op = isset($e['op']) ? (string) $e['op'] : '';
      $r = self::with_element($data, $id, function (&$el) use ($e, $op, $id, &$diff, &$notes, &$alts) {
        if (($el['elType'] ?? '') !== 'widget') return new WP_Error('unsupported', sprintf('%s is a %s, not a widget; target a widget inside it', $id, $el['elType'] ?? 'element'));
        $type = (string) ($el['widgetType'] ?? '');
        if (!isset($el['settings']) || !is_array($el['settings'])) $el['settings'] = [];
        $before = $el['settings'];
        if ($op === 'replace_text') {
          if (!isset($e['text']) || !is_scalar($e['text'])) return new WP_Error('invalid', 'replace_text needs text');
          $keys = isset(self::TEXT_KEYS[$type]) ? self::TEXT_KEYS[$type] : [];
          $field = isset($e['field']) && is_string($e['field']) ? $e['field'] : ($keys ? $keys[0] : null);
          if ($field === null || !in_array($field, $keys, true)) {
            return new WP_Error('unsupported', sprintf('%s widget "%s" has no text Visibility can change%s', $type, $id, $keys ? ' (fields: ' . implode(', ', $keys) . ')' : ''));
          }
          $text = in_array($field, self::HTML_KEYS, true) ? wp_kses_post((string) $e['text']) : sanitize_text_field((string) $e['text']);
          $cur = isset($el['settings'][$field]) ? (string) $el['settings'][$field] : '';
          if (isset($e['find']) && is_scalar($e['find']) && (string) $e['find'] !== '') {
            if (strpos($cur, (string) $e['find']) === false) return new WP_Error('not_found', sprintf('"%s" is not in %s.%s', $e['find'], $id, $field));
            $text = str_replace((string) $e['find'], $text, $cur);
          }
          $el['settings'][$field] = $text;
        } elseif ($op === 'set_attr') {
          $set = isset($e['attrs']) && is_array($e['attrs']) ? $e['attrs'] : [];
          if (empty($set)) return new WP_Error('invalid', 'set_attr needs attrs');
          foreach ($set as $k => $v) {
            if ($k === 'alt') {
              $mk = isset(self::IMAGE_KEYS[$type]) ? self::IMAGE_KEYS[$type] : null;
              if (!$mk || empty($el['settings'][$mk]) || !is_array($el['settings'][$mk])) return new WP_Error('not_found', sprintf('widget %s has no image', $id));
              $alt = sanitize_text_field((string) $v);
              $att = (int) ($el['settings'][$mk]['id'] ?? 0);
              $el['settings'][$mk]['alt'] = $alt;
              if ($att) {
                // Elementor shows the media library's alt for library images.
                $alts[$att] = $alt;
                $notes[] = sprintf('The alt text is stored on image %d in the media library, so it shows wherever that image is used.', $att);
              }
            } elseif ($k === 'href' || $k === 'url') {
              $url = esc_url_raw((string) $v);
              if ($url === '') return new WP_Error('invalid', 'not a valid URL');
              if (!isset($el['settings']['link']) || !is_array($el['settings']['link'])) $el['settings']['link'] = ['url' => '', 'is_external' => '', 'nofollow' => ''];
              $el['settings']['link']['url'] = $url;
            } elseif ($k === 'level' && $type === 'heading') {
              $l = (int) $v;
              if ($l < 1 || $l > 6) return new WP_Error('invalid', 'level must be 1-6');
              $el['settings']['header_size'] = 'h' . $l;
            } else {
              return new WP_Error('unsupported', sprintf('attribute "%s" cannot be set on a %s widget (use alt, href, level)', $k, $type));
            }
          }
        } else {
          return new WP_Error('unsupported', sprintf('"%s" is not supported on Elementor pages yet (use replace_text or set_attr)', $op));
        }
        $diff[] = ['target' => ['elementId' => $id], 'blockName' => 'elementor/' . $type, 'ops' => [$op], 'before' => $before, 'after' => $el['settings']];
        return true;
      });
      if ($r === null) return new WP_Error('not_found', sprintf('edit %d: no element %s on this page', $i, $id));
      if (is_wp_error($r)) return new WP_Error($r->get_error_code(), sprintf('edit %d: %s', $i, $r->get_error_message()));
    }
    foreach ($alts as $att => $alt) update_post_meta($att, '_wp_attachment_image_alt', $alt);
    $saved = Visibility_Builders::as_editor($post->ID, function () use ($doc, $data) {
      return $doc->save(['elements' => $data]);
    });
    if (is_wp_error($saved)) return $saved;
    if (!$saved) return new WP_Error('save_failed', 'Elementor did not save the page.');
    return ['diff' => $diff, 'notes' => array_values(array_unique($notes)), 'cssRegenerated' => true];
  }

  /** After a rollback restored _elementor_data, drop Elementor's CSS and caches for the page. */
  public static function after_rollback($post_id) {
    if (!class_exists('\Elementor\Core\Files\CSS\Post')) return;
    $post = get_post($post_id);
    if (!$post || get_post_meta($post_id, '_elementor_edit_mode', true) !== 'builder') return;
    \Elementor\Core\Files\CSS\Post::create($post_id)->delete();
    // Elementor's element cache is post meta; drop it so the page renders from the restored data.
    delete_post_meta($post_id, '_elementor_element_cache');
  }
}

add_filter('visibility_builder_adapters', function ($a) {
  $a['elementor'] = new Visibility_Builder_Elementor();
  return $a;
});
add_action('visibility_after_rollback', ['Visibility_Builder_Elementor', 'after_rollback']);
