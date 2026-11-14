#!/bin/bash
# T1 / T11 / #285: post.edit_parts changes only the targeted block; every
# other byte of post_content stays as it was. Block theme -> template reported.
cd "$(dirname "$0")"; . ./lib.sh
S=${SITE:-1}
C='<!-- wp:heading {"className":"hero-title"} -->
<h2 class="wp-block-heading hero-title" id="intro">Old heading</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>First paragraph with <a href="https://example.com/a">a link</a>.</p>
<!-- /wp:paragraph -->

<!-- wp:html -->
<iframe src="https://www.youtube.com/embed/abc123" width="560" height="315"></iframe>
<script>window.x={"a":"\u003cb\u003e"};</script>
<!-- /wp:html -->

<!-- wp:group {"layout":{"type":"constrained"}} -->
<div class="wp-block-group"><!-- wp:paragraph {"fontSize":"large"} -->
<p class="has-large-font-size">Inner paragraph to edit.</p>
<!-- /wp:paragraph -->

<!-- wp:image {"id":5,"sizeSlug":"large"} -->
<figure class="wp-block-image size-large"><img src="https://example.com/x.png" alt="old alt" class="wp-image-5"/></figure>
<!-- /wp:image --></div>
<!-- /wp:group -->

<!-- wp:paragraph -->
<p>Last paragraph.</p>
<!-- /wp:paragraph -->'
ID=$(printf '%s' "$C" | $WP $S post create - --post_type=page --post_status=publish --post_title="T1 blocks $RANDOM" --user=admin --porcelain | tr -d '\r' | tail -1)
check "$($WP $S post get $ID --field=post_content)" "$C" "fixture saved verbatim"

R=$(api $S GET "/posts/$ID?include=blocks")
check "$(echo "$R" | jget "list(b['path'] for b in d['blocks'])")" "['0', '2', '4', '6', '8']" "block paths match parse_blocks indexes"
check "$(echo "$R" | jget "list(b['path'] for b in d['blocks'][3]['innerBlocks'])")" "['6.0', '6.1']" "inner block paths"
check "$(echo "$R" | jget "d['blockTemplate']['slug'] if d['blockTemplate'] else None")" "page" "block theme: template reported"
check "$(echo "$R" | jget "'header' in [p['slug'] for p in d['blockTemplate']['parts']]")" "True" "template parts (header) reported"
MOD=$(echo "$R" | jget "['modifiedGmt']")

# 1. One inner paragraph: everything else byte-identical.
R=$(action $S page.edit_parts "{\"postId\":$ID,\"modifiedGmt\":\"$MOD\",\"edits\":[{\"target\":{\"path\":\"6.0\"},\"op\":\"replace_text\",\"text\":\"Inner paragraph, <strong>edited</strong>.\"}]}")
check "$(echo "$R" | jget "['ok']")" "True" "edit_parts ok"
check "$(echo "$R" | jget "d['result']['diff'][0]['blockName']")" "core/paragraph" "diff names the block"
check "$(echo "$R" | jget "d['result']['diff'][0]['before']")" '<!-- wp:paragraph {"fontSize":"large"} -->
<p class="has-large-font-size">Inner paragraph to edit.</p>
<!-- /wp:paragraph -->' "diff before = only that block"
EXP=${C/Inner paragraph to edit./Inner paragraph, <strong>edited</strong>.}
check "$($WP $S post get $ID --field=post_content)" "$EXP" "only the target block changed, every other byte identical"

# 2. Heading text (attrs, class, anchor kept) + level, image alt, link href in one call.
R=$(action $S page.edit_parts "{\"postId\":$ID,\"edits\":[{\"target\":{\"path\":\"0\"},\"op\":\"replace_text\",\"text\":\"New heading\"},{\"target\":{\"path\":\"0\"},\"op\":\"set_attr\",\"attrs\":{\"level\":3}},{\"target\":{\"path\":\"6.1\"},\"op\":\"set_attr\",\"attrs\":{\"alt\":\"A clear chart of rankings\"}},{\"target\":{\"path\":\"2\"},\"op\":\"set_attr\",\"attrs\":{\"href\":\"https://example.com/b\"}}]}")
check "$(echo "$R" | jget "['ok']")" "True" "heading + alt + href edit ok"
EXP2=${EXP/'<!-- wp:heading {"className":"hero-title"} -->
<h2 class="wp-block-heading hero-title" id="intro">Old heading</h2>'/'<!-- wp:heading {"className":"hero-title","level":3} -->
<h3 class="wp-block-heading hero-title" id="intro">New heading</h3>'}
EXP2=${EXP2/alt=\"old alt\"/alt=\"A clear chart of rankings\"}
EXP2=${EXP2/example.com\/a\"/example.com\/b\"}
check "$($WP $S post get $ID --field=post_content)" "$EXP2" "heading level/text, alt, href changed; rest identical"
PAGE=$(curl -sL "http://localhost:818$S/?page_id=$ID")
grep -q '<h3 class="wp-block-heading hero-title" id="intro">New heading</h3>' <<<"$PAGE" && ok "front end shows new h3" || bad "front end heading"
grep -q 'alt="A clear chart of rankings"' <<<"$PAGE" && ok "front end alt" || bad "front end alt"
grep -q 'youtube.com/embed/abc123' <<<"$PAGE" && ok "custom HTML embed still on page" || bad "embed missing"

# 3. insert_after / remove / replace_block; new markup sanitised.
R=$(action $S page.edit_parts "{\"postId\":$ID,\"edits\":[{\"target\":{\"path\":\"8\"},\"op\":\"insert_after\",\"html\":\"<!-- wp:paragraph -->\\n<p>Added <img src=x onerror=alert(1)>.</p>\\n<!-- /wp:paragraph -->\"}]}")
check "$(echo "$R" | jget "['ok']")" "True" "insert_after ok"
AFTER=$($WP $S post get $ID --field=post_content)
case "$AFTER" in *onerror*) bad "inserted markup not sanitised";; *"<p>Added"*) ok "inserted + sanitised";; *) bad "insert missing";; esac
check "$(api $S GET "/posts/$ID?include=blocks" | jget "len(d['blocks'])")" "6" "6 top-level blocks after insert"
R=$(action $S page.edit_parts "{\"postId\":$ID,\"edits\":[{\"target\":{\"path\":\"10\"},\"op\":\"remove\"}]}")
check "$(echo "$R" | jget "['ok']")" "True" "remove ok"
check "$($WP $S post get $ID --field=post_content)" "$EXP2" "remove restores the exact previous content"

# 4. Refusals.
R=$(action $S page.edit_parts "{\"postId\":$ID,\"edits\":[{\"target\":{\"path\":\"42\"},\"op\":\"replace_text\",\"text\":\"x\"}]}")
check "$(echo "$R" | jget "['errorCode']")" "not_found" "missing path -> not_found"
R=$(action $S page.edit_parts "{\"postId\":$ID,\"modifiedGmt\":\"2001-01-01T00:00:00Z\",\"edits\":[{\"target\":{\"path\":\"0\"},\"op\":\"replace_text\",\"text\":\"x\"}]}")
check "$(echo "$R" | jget "['errorCode']")" "conflict" "stale modifiedGmt -> conflict"
R=$(action $S page.edit_parts "{\"postId\":$ID,\"edits\":[{\"target\":{\"path\":\"6\"},\"op\":\"replace_text\",\"text\":\"x\"}]}")
check "$(echo "$R" | jget "['errorCode']")" "unsupported" "text of a container block -> unsupported (target the inner block)"
R=$(action $S page.edit_parts "{\"postId\":$ID,\"edits\":[{\"target\":{\"path\":\"6\"},\"op\":\"replace_text\",\"find\":\"edited\",\"text\":\"changed\"}]}")
check "$(echo "$R" | jget "['ok']")" "True" "container block with find -> ok"
grep -q 'Inner paragraph, <strong>changed</strong>.' <<<"$($WP $S post get $ID --field=post_content)" && ok "find/replace landed in the inner block" || bad "find/replace"

# Slug change on published content: posts get WordPress's own 301, pages
# without a redirect plugin are refused.
P=$($WP $S post create --post_status=publish --post_title="T1 slug $RANDOM" --post_name=t1-old-slug-$RANDOM --porcelain | tr -d '\r' | tail -1)
OLD=$($WP $S post get $P --field=post_name)
R=$(action $S post.update_content "{\"postId\":$P,\"fields\":{\"slug\":\"$OLD-new\"}}")
check "$(echo "$R" | jget "d['result']['slugChanged']['redirect']")" "wordpress" "post slug change -> WordPress old-slug redirect"
check "$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "http://localhost:818$S/$OLD/")" "301 http://localhost:818$S/$OLD-new/" "old post URL 301s to the new one"
R=$(action $S page.update_content "{\"postId\":$ID,\"fields\":{\"slug\":\"t1-moved-$RANDOM\"}}")
check "$(echo "$R" | jget "['errorCode']")" "unsupported" "published page slug change without a redirect plugin -> refused"
summary
