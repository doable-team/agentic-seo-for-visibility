#!/bin/bash
# 2.1.0 GET /posts/{id}?include=blocks -> blockSummary: parse_blocks() summary
# (name, small attrs, innerBlocks count, html length), classicBlocks (content
# outside blocks), invalid hints (not in blocks, unknown block type,
# unbalanced block comments). Read-only.
cd "$(dirname "$0")"; . ./lib.sh
S=${SITE:-1}
mk() { $WP $S post create --post_type=post --post_status=draft --post_title="Blocks $RANDOM" --porcelain | tr -d '\r' | tail -1; }
set_content() { $WP $S eval "wp_update_post(['ID'=>$1,'post_content'=>base64_decode('$(printf '%s' "$2" | base64 -w0)')]);" >/dev/null; }
sumq() { api $S GET "/posts/$1?include=blocks" | jget "$2"; }

A=$(mk)
set_content $A '<!-- wp:heading {"level":3} -->
<h3 class="wp-block-heading">Title</h3>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>One</p>
<!-- /wp:paragraph -->

<!-- wp:group {"layout":{"type":"constrained"}} -->
<div class="wp-block-group"><!-- wp:paragraph -->
<p>Inner</p>
<!-- /wp:paragraph --></div>
<!-- /wp:group -->'
check "$(sumq $A "(d['blockSummary']['hasBlocks'], d['blockSummary']['total'], d['blockSummary']['classicBlocks'], d['blockSummary']['invalid'])")" "(True, 4, 0, [])" "clean block post: 4 blocks, no classic, no hints"
check "$(sumq $A "list((i['name'], i['innerBlocks']) for i in d['blockSummary']['items'])")" "[('core/heading', 0), ('core/paragraph', 0), ('core/group', 1)]" "top-level items with innerBlocks count"
check "$(sumq $A "(d['blockSummary']['items'][0]['attrs'], d['blockSummary']['items'][0]['htmlLength'])")" "({'level': 3}, 41)" "attrs + html length"
check "$(sumq $A "'blocks' in d and 'meta' not in d")" "True" "include=blocks keeps the block tree, nothing else"

B=$(mk)
set_content $B '<p>Old classic paragraph</p>
<p>Second</p>'
check "$(sumq $B "(d['blockSummary']['hasBlocks'], d['blockSummary']['classicBlocks'], len(d['blockSummary']['invalid']))")" "(False, 1, 1)" "classic post: not in blocks, one classic block, hinted"

C=$(mk)
set_content $C '<!-- wp:paragraph -->
<p>Block</p>
<!-- /wp:paragraph -->
<p>Stray HTML outside blocks</p>
<!-- wp:acme/widget {"big":"'"$(printf 'y%.0s' $(seq 1 300))"'"} /-->
<!-- wp:paragraph -->
<p>Unclosed</p>'
R=$(api $S GET "/posts/$C?include=blocks")
check "$(echo "$R" | jget "d['blockSummary']['classicBlocks']")" "1" "mixed post: stray HTML counted as a classic block"
check "$(echo "$R" | jget "any('acme/widget' in h for h in d['blockSummary']['invalid'])")" "True" "unknown block type hinted"
check "$(echo "$R" | jget "any('do not match' in h for h in d['blockSummary']['invalid'])")" "True" "unbalanced block comments hinted"
check "$(echo "$R" | jget "list(i['attrs'] for i in d['blockSummary']['items'] if i['name']=='acme/widget')[0]['big']")" "(302 chars)" "long attribute replaced by a size note"
for p in $A $B $C; do $WP $S post delete $p --force >/dev/null; done
summary
