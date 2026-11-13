<?php
// Standalone test of Visibility_Links (no WordPress): php unit/links-test.php
define('ABSPATH', __DIR__);
function esc_html($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function esc_url($s) { return $s; }
function untrailingslashit($s) { return rtrim($s, '/\\'); }
require __DIR__ . '/../../agentic-seo-visibility/includes/class-visibility-links.php';

$fail = 0;
function t($name, $cond) { global $fail; echo ($cond ? 'PASS  ' : 'FAIL  ') . $name . "\n"; if (!$cond) $fail++; }
$u = 'https://ex.com/seo-audit/';
$a = '<a href="' . $u . '">';

$html = "<!-- wp:heading -->\n<h2>SEO audit basics</h2>\n<!-- /wp:heading -->\n<!-- wp:paragraph -->\n<p>Read our <a href=\"/x\">SEO audit guide</a>. Run <code>seo audit</code>. A good SEO audit finds issues. Another SEO audit later.</p>\n<!-- /wp:paragraph -->";
list($out, $ok) = Visibility_Links::link_phrase($html, 'SEO audit', $u);
t('applied', $ok);
t('linked exactly once', substr_count($out, $a) === 1);
t('first eligible occurrence (paragraph text, not heading/link/code)', strpos($out, 'A good ' . $a . 'SEO audit</a> finds') !== false);
t('heading untouched', strpos($out, '<h2>SEO audit basics</h2>') !== false);
t('existing link untouched', strpos($out, '<a href="/x">SEO audit guide</a>') !== false);
t('code untouched', strpos($out, '<code>seo audit</code>') !== false);
t('block comments untouched', substr_count($out, '<!-- wp:') === 2 && substr_count($out, '<!-- /wp:') === 2);
t('only change is the link', str_replace([$a, '</a> finds'], ['', ' finds'], $out) === $html);

list($out, $ok) = Visibility_Links::link_phrase('<p>auditing is not an audit</p>', 'audit', $u);
t('whole words only', $ok && strpos($out, 'auditing is not an ' . $a . 'audit</a>') !== false);
list($out, $ok) = Visibility_Links::link_phrase('<h3>only heading audit</h3><pre>audit</pre>', 'audit', $u);
t('nothing linkable -> not applied, html unchanged', !$ok && $out === '<h3>only heading audit</h3><pre>audit</pre>');
list($out, $ok) = Visibility_Links::link_phrase('<p>Tom &amp; Jerry show</p>', 'Tom & Jerry', $u);
t('phrase with entity', $ok && strpos($out, $a . 'Tom &amp; Jerry</a>') !== false);
list($out, $ok) = Visibility_Links::link_phrase('<p>Café crème guide</p>', 'café crème', $u);
t('unicode, case-insensitive, keeps original text', $ok && strpos($out, $a . 'Café crème</a>') !== false);
t('links_to detects existing link', Visibility_Links::links_to('<a href="https://ex.com/seo-audit">x</a>', $u));
echo "---- PHP " . PHP_VERSION . ": " . ($fail ? "$fail failed" : "all passed") . "\n";
exit($fail ? 1 : 0);
