<?php
/**
 * Plugin Name: JetEngine STAND-IN (Visibility tests only, not Crocoblock's JetEngine)
 * Description: Test-only stand-in with the parts of JetEngine that Visibility
 * reads: class Jet_Engine, jet_engine() with get_version() and
 * ->meta_boxes->get_meta_fields_for_object($post_type), and the meta box
 * config JetEngine saves in the option jet_engine_meta_boxes:
 * [{args:{object_type:"post", allowed_post_type:[...], name}, meta_fields:[{name,
 * title, type, object_type:"field", options:[{key, value}], is_multiple,
 * value_format}]}]. Values are plain post meta under each field name (switcher
 * "true"/"false", media id), as JetEngine stores them (see its
 * includes/components/meta-boxes in public copies, and Crocoblock's docs).
 * Never shipped in the plugin zip.
 */
class Jet_Engine {
  public $meta_boxes;
  public function __construct() { $this->meta_boxes = new Vis_Standin_Jet_Meta_Boxes(); }
  public function get_version() { return '3.8.0-standin'; }
}
class Vis_Standin_Jet_Meta_Boxes {
  public $meta_fields = [];
  public function get_meta_fields_for_object($object = 'post') {
    if (!$this->meta_fields) {
      foreach ((array) get_option('jet_engine_meta_boxes', []) as $box) {
        $args = $box['args'] ?? [];
        if (($args['object_type'] ?? 'post') !== 'post') continue;
        foreach ((array) ($args['allowed_post_type'] ?? []) as $pt) {
          $this->meta_fields[$pt] = array_merge($this->meta_fields[$pt] ?? [], array_values((array) ($box['meta_fields'] ?? [])));
        }
      }
    }
    return isset($this->meta_fields[$object]) ? $this->meta_fields[$object] : [];
  }
}
function jet_engine() { static $i; return $i ?: ($i = new Jet_Engine()); }
add_action('init', function () { jet_engine(); });
