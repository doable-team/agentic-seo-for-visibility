<?php
/**
 * FAQ and How-to blocks built from questions and answers (#571).
 *
 * An agent that writes a Rank Math or Yoast FAQ block by hand has to keep the
 * JSON attributes in the block comment and the HTML in step; when they differ
 * the editor marks the block invalid. Here the block is built from structured
 * input and returns exactly the attributes and HTML each block's own save
 * function produces (checked against the editor's wp.blocks.serialize() and
 * isValid on real Rank Math and Yoast sites, tests/t-faq-blocks.sh).
 *
 * POST /blocks/faq {items:[{question, answer}], block?} or
 *                  {kind:"howto", steps:[{name, text}], title?, description?, block?}
 * Answers and step texts are HTML; they are reduced to inline HTML (paragraphs
 * joined with <br>), because both plugins keep them in one rich-text field.
 */

if (!defined('ABSPATH')) {
  exit;
}

class Visibility_FAQ {

  const FAQ_BLOCKS = ['rank-math/faq-block', 'yoast/faq-block', 'core/details'];
  const HOWTO_BLOCKS = ['rank-math/howto-block', 'yoast/how-to-block'];
  const MAX_ITEMS = 50;
  /** Inline HTML kept in an answer. */
  const INLINE = ['a' => ['href' => true, 'target' => true, 'rel' => true, 'title' => true], 'strong' => [], 'b' => [], 'em' => [], 'i' => [], 'u' => [], 's' => [], 'code' => [], 'br' => [], 'sup' => [], 'sub' => [], 'mark' => [], 'kbd' => [], 'span' => []];

  private static function registered($name) {
    return class_exists('WP_Block_Type_Registry') && WP_Block_Type_Registry::get_instance()->is_registered($name);
  }

  /** FAQ / How-to blocks the site's recent posts use, most used first (from the profile). */
  public static function used($kind = 'faq') {
    if (!class_exists('Visibility_Profile')) return [];
    $p = Visibility_Profile::get();
    return array_values(array_filter((array) ($p[$kind === 'howto' ? 'howtoBlocks' : 'faqBlocks'] ?? []), [__CLASS__, 'registered']));
  }

  /** Blocks this site can build, for the profile. */
  public static function available($kind = 'faq') {
    return array_values(array_filter($kind === 'howto' ? self::HOWTO_BLOCKS : self::FAQ_BLOCKS, [__CLASS__, 'registered']));
  }

  /** @return array|WP_Error ['ok', 'block', 'markup'] */
  public static function build(array $body) {
    $kind = isset($body['kind']) && $body['kind'] === 'howto' ? 'howto' : 'faq';
    $block = isset($body['block']) && is_string($body['block']) && $body['block'] !== '' ? $body['block'] : null;
    $allowed = $kind === 'howto' ? self::HOWTO_BLOCKS : self::FAQ_BLOCKS;
    if ($block === null) {
      $used = self::used($kind);
      $block = $used ? $used[0] : null;
      if ($block === null) {
        $avail = self::available($kind);
        $block = $kind === 'faq' ? (self::registered('core/details') ? 'core/details' : null) : ($avail ? $avail[0] : null);
      }
      if ($block === null) {
        return new WP_Error($kind === 'howto' ? 'visibility_no_howto_block' : 'visibility_bad_block', $kind === 'howto'
          ? __('This site has no How-to block (Rank Math or Yoast). Write the steps as a numbered list instead.', 'agentic-seo-visibility')
          : __('This site has no FAQ block (Rank Math, Yoast or the core Details block, WordPress 6.3+). Write the questions as headings and paragraphs instead.', 'agentic-seo-visibility'), ['status' => 400]);
      }
    }
    if (!in_array($block, $allowed, true)) {
      return new WP_Error('visibility_bad_block', sprintf(
        /* translators: 1: block name, 2: allowed names. */
        __('Visibility can build %1$s only as one of: %2$s.', 'agentic-seo-visibility'), $block, implode(', ', $allowed)
      ), ['status' => 400]);
    }
    if (!self::registered($block)) {
      return new WP_Error('visibility_bad_block', sprintf(
        /* translators: %s: block name. */
        __('The block %s is not available on this site (its plugin is missing or off).', 'agentic-seo-visibility'), $block
      ), ['status' => 400]);
    }
    $list = $kind === 'howto' ? ($body['steps'] ?? null) : ($body['items'] ?? null);
    if (!is_array($list) || !$list) {
      return new WP_Error('visibility_bad_body', $kind === 'howto' ? 'steps: [{name, text}] is required.' : 'items: [{question, answer}] is required.', ['status' => 400]);
    }
    if (count($list) > self::MAX_ITEMS) {
      return new WP_Error('visibility_too_large', sprintf('At most %d items in one block.', self::MAX_ITEMS), ['status' => 413]);
    }
    $rows = [];
    foreach (array_values($list) as $i => $row) {
      $q = is_array($row) ? (string) ($kind === 'howto' ? ($row['name'] ?? '') : ($row['question'] ?? '')) : '';
      $a = is_array($row) ? (string) ($kind === 'howto' ? ($row['text'] ?? '') : ($row['answer'] ?? '')) : '';
      $q = self::text($q);
      $a = self::inline($a);
      if ($q === '' || ($kind === 'faq' && $a === '')) {
        return new WP_Error('visibility_bad_body', sprintf($kind === 'howto' ? 'Step %d needs a name.' : 'Item %d needs a question and an answer.', $i + 1), ['status' => 400]);
      }
      $rows[] = [$q, $a];
    }
    $desc = isset($body['description']) ? self::inline((string) $body['description']) : '';
    $markup = self::markup($block, $rows, $desc);
    return ['ok' => true, 'block' => $block, 'markup' => $markup];
  }

  /** Plain text for rich text: only &, < and > escaped, as the editor stores them. */
  private static function text($s) {
    $s = trim(wp_strip_all_tags(html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    $s = preg_replace('/\s+/u', ' ', $s);
    return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $s);
  }

  /** HTML → inline HTML: paragraphs and list items become lines joined with <br>. */
  public static function inline($html) {
    $html = trim((string) $html);
    if ($html === '') return '';
    if (strpos($html, '<') === false) {
      // Plain text: blank lines are paragraphs.
      $parts = array_filter(array_map('trim', preg_split('/\n\s*\n/', $html)), 'strlen');
      return implode('<br><br>', array_map(function ($p) { return str_replace(["\n"], '<br>', str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $p)); }, $parts));
    }
    $html = preg_replace('/<!--.*?-->/s', '', $html);
    $html = preg_replace('#<li\b[^>]*>#i', '• ', $html);
    $html = preg_replace('#</li>#i', '<br>', $html);
    $html = preg_replace('#</(p|div|h[1-6]|blockquote|pre|figure|figcaption|tr|ul|ol|details|summary)>#i', '<br><br>', $html);
    $html = wp_kses($html, self::INLINE);
    $html = preg_replace('#\s*<br\s*/?>\s*#i', '<br>', $html);
    $html = preg_replace('#(<br>){3,}#', '<br><br>', $html);
    $html = preg_replace('#^(<br>)+|(<br>)+$#', '', trim($html));
    return trim(preg_replace('/[ \t\r\n]+/', ' ', $html));
  }

  private static function id($prefix, $i, $q) {
    return $prefix . '-' . ($i + 1) . '-' . substr(md5($q), 0, 6);
  }

  /** The block as its own save function writes it. */
  private static function markup($block, array $rows, $desc) {
    switch ($block) {
      case 'rank-math/faq-block':
        $attrs = ['questions' => []]; $html = '';
        foreach ($rows as $i => list($q, $a)) {
          $attrs['questions'][] = ['id' => self::id('faq-question', $i, $q), 'title' => $q, 'content' => $a, 'visible' => true];
          $html .= '<div class="rank-math-faq-item"><h3 class="rank-math-question">' . $q . '</h3><div class="rank-math-answer">' . $a . '</div></div>';
        }
        return self::comment($block, $attrs, '<div class="wp-block-rank-math-faq-block">' . $html . '</div>');
      case 'rank-math/howto-block':
        $attrs = $desc !== '' ? ['description' => $desc] : [];
        $attrs['steps'] = []; $html = '';
        foreach ($rows as $i => list($q, $a)) {
          $attrs['steps'][] = ['id' => self::id('howto-step', $i, $q), 'title' => $q, 'content' => $a, 'visible' => true];
          $html .= '<div class="rank-math-howto-step"><h3 class="rank-math-howto-title">' . $q . '</h3>' . ($a !== '' ? '<div class="rank-math-howto-content">' . $a . '</div>' : '') . '</div>';
        }
        return self::comment($block, $attrs, '<div class="wp-block-rank-math-howto-block">' . $html . '</div>');
      case 'yoast/faq-block':
        $attrs = ['questions' => []]; $html = '';
        foreach ($rows as $i => list($q, $a)) {
          $id = self::id('faq-question', $i, $q);
          $attrs['questions'][] = ['id' => $id, 'question' => $q, 'answer' => $a, 'jsonQuestion' => $q, 'jsonAnswer' => $a];
          $html .= '<div class="schema-faq-section" id="' . esc_attr($id) . '"><strong class="schema-faq-question">' . $q . '</strong> <p class="schema-faq-answer">' . $a . '</p> </div> ';
        }
        return self::comment($block, $attrs, '<div class="schema-faq wp-block-yoast-faq-block">' . $html . '</div>');
      case 'yoast/how-to-block':
        $attrs = $desc !== '' ? ['jsonDescription' => $desc] : [];
        $attrs['steps'] = []; $html = '';
        foreach ($rows as $i => list($q, $a)) {
          $id = self::id('how-to-step', $i, $q);
          $attrs['steps'][] = ['id' => $id, 'name' => $q, 'text' => $a, 'jsonName' => $q, 'jsonText' => $a];
          $html .= '<li class="schema-how-to-step" id="' . esc_attr($id) . '"><strong class="schema-how-to-step-name">' . $q . '</strong> <p class="schema-how-to-step-text">' . $a . '</p> </li>';
        }
        return self::comment($block, $attrs, '<div class="schema-how-to wp-block-yoast-how-to-block"><p class="schema-how-to-description">' . $desc . '</p> <ol class="schema-how-to-steps">' . $html . '</ol></div>');
      default: // core/details, one block per question, the answer as paragraphs
        $out = [];
        foreach ($rows as list($q, $a)) {
          $paras = array_filter(array_map('trim', explode('<br><br>', $a)), 'strlen');
          $inner = implode("\n\n", array_map(function ($p) { return "<!-- wp:paragraph -->\n<p>" . $p . "</p>\n<!-- /wp:paragraph -->"; }, $paras));
          $out[] = "<!-- wp:details -->\n<details class=\"wp-block-details\"><summary>" . $q . "</summary>" . ($inner !== '' ? "\n" . $inner . "\n" : '') . "</details>\n<!-- /wp:details -->";
        }
        return implode("\n\n", $out);
    }
  }

  private static function comment($name, array $attrs, $html) {
    $short = strpos($name, 'core/') === 0 ? substr($name, 5) : $name;
    $json = $attrs ? ' ' . serialize_block_attributes($attrs) : '';
    return '<!-- wp:' . $short . $json . " -->\n" . $html . "\n<!-- /wp:" . $short . ' -->';
  }

  /**
   * For blockSummary (#567): a Rank Math / Yoast FAQ or How-to block whose
   * HTML no longer matches its attributes (the editor shows it as invalid).
   * @return string|null what differs
   */
  public static function mismatch(array $b) {
    $name = $b['blockName'];
    $a = is_array($b['attrs']) ? $b['attrs'] : [];
    $html = (string) $b['innerHTML'];
    $norm = function ($s) { return preg_replace('/\s+/', ' ', trim(html_entity_decode(wp_strip_all_tags(str_replace('<', ' <', $s)), ENT_QUOTES | ENT_HTML5, 'UTF-8'))); };
    $pairs = [];
    if ($name === 'rank-math/faq-block') foreach ((array) ($a['questions'] ?? []) as $q) { if (is_array($q) && ($q['visible'] ?? true) !== false && !empty($q['title']) && !empty($q['content'])) $pairs[] = [(string) $q['title'], (string) $q['content']]; }
    elseif ($name === 'rank-math/howto-block') foreach ((array) ($a['steps'] ?? []) as $q) { if (is_array($q) && ($q['visible'] ?? true) !== false) $pairs[] = [(string) ($q['title'] ?? ''), (string) ($q['content'] ?? '')]; }
    elseif ($name === 'yoast/faq-block') foreach ((array) ($a['questions'] ?? []) as $q) { if (is_array($q)) $pairs[] = [is_string($q['question'] ?? null) ? $q['question'] : '', is_string($q['answer'] ?? null) ? $q['answer'] : '']; }
    elseif ($name === 'yoast/how-to-block') foreach ((array) ($a['steps'] ?? []) as $q) { if (is_array($q)) $pairs[] = [is_string($q['name'] ?? null) ? $q['name'] : '', is_string($q['text'] ?? null) ? $q['text'] : '']; }
    else return null;
    $want = $norm(implode(' ', array_map(function ($p) { return $p[0] . ' ' . $p[1]; }, $pairs)));
    $have = $norm($html);
    if ($name === 'yoast/how-to-block' && !empty($a['jsonDescription'])) $want = $norm($a['jsonDescription'] . ' ' . $want);
    if ($want === $have) return null;
    // First text that differs, for the message.
    $wa = explode(' ', $want); $ha = explode(' ', $have);
    for ($i = 0; $i < max(count($wa), count($ha)); $i++) {
      if (($wa[$i] ?? '') !== ($ha[$i] ?? '')) {
        return sprintf('its attributes say "%s" where its HTML says "%s"', implode(' ', array_slice($wa, $i, 6)), implode(' ', array_slice($ha, $i, 6)));
      }
    }
    return 'its attributes and HTML differ';
  }
}
