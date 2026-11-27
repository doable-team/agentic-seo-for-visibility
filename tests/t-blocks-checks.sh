#!/bin/bash
# #567: blockSummary reports what the block editor and the browser trip over:
# a page-head tag written as words in the text (a <title> hides the rest of the
# live page), HTML that doesn't close inside a block, and (t-faq-blocks.sh) a FAQ
# block whose attributes and HTML differ. The four posts from the issue: three
# broken ones are reported, the good one (tags in backticks) is not; the editor
# result is read in headless Chrome as a control. Needs NODE_PATH + Chrome.
cd "$(dirname "$0")"; . ./lib.sh
S=${SITE:-1}; BASE="http://localhost:$((PORT_BASE+S))"
editor() { NODE_PATH=${NODE_PATH:-/tmp/pw/node_modules} node editor-check.js "$BASE" "$@"; }
post() { python3 - "$1" "$2" <<'PY' | api $S POST /posts "$(cat)" | jget "['id']"
import json,sys; print(json.dumps({"title":sys.argv[1],"status":"draft","content":sys.argv[2]}))
PY
}
P1=$(post "tags-in-prose" '<!-- wp:paragraph -->
<p>Always add a <title> tag and a description, then one <h1> per page.</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>More text after it.</p>
<!-- /wp:paragraph -->')
P2=$(post "tags-in-table" '<!-- wp:table -->
<figure class="wp-block-table"><table><tbody><tr><td>Title</td><td>Use one <title> per page</td></tr></tbody></table></figure>
<!-- /wp:table -->')
P3=$(post "tags-in-list" '<!-- wp:list -->
<ul class="wp-block-list"><!-- wp:list-item -->
<li>One <h1> per page</li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li>A <title> in the head</li>
<!-- /wp:list-item --></ul>
<!-- /wp:list -->')
P4=$(post "tags-in-backticks" '<!-- wp:paragraph -->
<p>Always add a <code>&lt;title&gt;</code> tag and a description, then one <code>&lt;h1&gt;</code> per page.</p>
<!-- /wp:paragraph -->')
sum() { api $S GET "/posts/$1?include=blocks" | jget "str(sorted(set(p['kind'] for p in d['blockSummary']['problems'])))"; }
check "$(sum $P1)" "['head_tag', 'unbalanced']" "prose with <title>/<h1> as words: head tag + unclosed HTML reported"
check "$(sum $P2)" "['head_tag', 'unbalanced']" "table: reported"
check "$(sum $P3)" "['head_tag', 'unbalanced']" "list: reported (in the list items)"
check "$(sum $P4)" "[]" "the same text in backticks: nothing reported"
check "$(api $S GET "/posts/$P1?include=blocks" | jget "len(d['blockSummary']['invalid']) >= 2 and '<title>' in ' '.join(d['blockSummary']['invalid'])")" "True" "blockSummary.invalid explains it in words"
check "$(api $S GET "/posts/$P3?include=blocks" | jget "str(sorted(set(p['path'] for p in d['blockSummary']['problems'])))")" "['0.0', '0.1']" "problems point at the nested blocks"
# Control: what the editor says about the same four posts.
E=$(editor $P1 $P2 $P3 $P4)
check "$(echo "$E" | python3 -c "import sys,json; print([bool(json.loads(l)['invalid']) for l in sys.stdin])")" "[True, True, True, False]" "editor (control): the first three have invalid blocks, the fourth is fine"
# A good page with HTML blocks, embeds and a self-closed <br/> is not flagged.
P5=$(post "fine" '<!-- wp:html -->
<div><link rel="stylesheet" href="x.css"><p>raw <b>html</b></div>
<!-- /wp:html -->

<!-- wp:paragraph -->
<p>Line<br/>break and <a href="/x">a link</a>.</p>
<!-- /wp:paragraph -->

<!-- wp:list -->
<ul class="wp-block-list"><!-- wp:list-item -->
<li>One</li>
<!-- /wp:list-item --></ul>
<!-- /wp:list -->')
check "$(sum $P5)" "[]" "custom HTML block, <br/>, nested list: nothing reported"
for p in $P1 $P2 $P3 $P4 $P5; do $WP $S post delete $p --force >/dev/null; done
summary
