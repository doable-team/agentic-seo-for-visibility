#!/bin/bash
# #284 rich read (+ the list part of #283) and T12's "stale modifiedGmt -> conflict".
cd "$(dirname "$0")"; . ./lib.sh
S=${SITE:-1}
HTML='<!-- wp:heading -->
<h2>Intro</h2>
<!-- /wp:heading -->

<!-- wp:group -->
<div class="wp-block-group"><!-- wp:paragraph -->
<p>Inside group.</p>
<!-- /wp:paragraph -->

<!-- wp:image {"id":0} -->
<figure class="wp-block-image"><img src="https://example.com/a.png" alt="An example"/></figure>
<!-- /wp:image --></div>
<!-- /wp:group -->'
PARENT=$($WP $S post create --post_type=page --post_status=publish --post_title="Read parent $RANDOM" --porcelain | tr -d '\r' | tail -1)
ID=$(printf '%s' "$HTML" | $WP $S post create - --post_type=page --post_status=publish --post_parent=$PARENT --post_title="Read page $RANDOM" --porcelain | tr -d '\r' | tail -1)
action $S seo_meta.update "{\"postId\":$ID,\"fields\":{\"metaTitle\":\"Read title\",\"metaDescription\":\"Read description\"}}" >/dev/null
R=$(api $S GET "/posts/$ID?include=all")
j() { echo "$R" | jget "$1"; }
check "$(j "['type']")" "page" "type"
check "$(j "['parent']")" "$PARENT" "parent"
check "$(j "['editor']")" "gutenberg" "editor = gutenberg"
check "$(j "['seo']['plugin']")" "None" "seo.plugin = null (no SEO plugin)"
check "$(j "['seo']['values']['metaDescription']")" "Read description" "seo.values.metaDescription"
check "$(j "['seo']['rendered']['description']")" "Read description" "seo.rendered.description"
check "$(j "['blocks'][0]['blockName']")" "core/heading" "blocks[0] = core/heading"
check "$(j "['blocks'][1]['path']")" "2" "group block path is its parse_blocks index"
check "$(j "['blocks'][1]['innerBlocks'][1]['path']")" "2.1" "nested image path 2.1"
check "$(j "['blocks'][1]['innerBlocks'][0]['text']")" "Inside group." "block text"
check "$(j "['images'][0]['alt']")" "An example" "images[].alt"
MOD=$(j "['modifiedGmt']")
[[ "$MOD" =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9:]{8}Z$ ]] && ok "modifiedGmt is ISO UTC ($MOD)" || bad "modifiedGmt format: $MOD"
R2=$(api $S GET "/posts/$ID"); check "$(echo "$R2" | jget "'blocks' in d")" "False" "no include -> no blocks"
# Lists
L=$(api $S GET "/posts?type=page&parent=$PARENT&status=any")
check "$(echo "$L" | jget "list(x['id'] for x in d['items'])")" "[$ID]" "list type=page&parent="
check "$(echo "$L" | jget "['items'][0]['type']")" "page" "list items carry type"
check "$(curl -s -o /dev/null -w '%{http_code}' -H "Authorization: Bearer vis-test-token-$S" "http://localhost:$((PORT_BASE+S))/wp-json/visibility/v1/posts?type=nav_menu_item")" "400" "non-public type -> 400"
check "$(curl -s -o /dev/null -w '%{http_code}' -H "Authorization: Bearer vis-test-token-$S" "http://localhost:$((PORT_BASE+S))/wp-json/visibility/v1/posts?type=nope")" "400" "unknown type -> 400"
# Optimistic lock: someone edits after the read.
sleep 1; $WP $S post update $ID --post_title="Edited by a person" >/dev/null
CODE=$(curl -s -o /tmp/conf.json -w '%{http_code}' -X PATCH -H "Authorization: Bearer vis-test-token-$S" -H 'Content-Type: application/json' --data "{\"title\":\"Agent title\",\"modifiedGmt\":\"$MOD\"}" http://localhost:$((PORT_BASE+S))/wp-json/visibility/v1/posts/$ID)
check "$CODE" "409" "PATCH with stale modifiedGmt -> 409"
check "$(jget "['code']" < /tmp/conf.json)" "visibility_conflict" "code visibility_conflict"
R3=$(action $S page.update_content "{\"postId\":$ID,\"modifiedGmt\":\"$MOD\",\"fields\":{\"title\":\"Agent title\"}}")
check "$(echo "$R3" | jget "['errorCode']")" "conflict" "page.update_content with stale modifiedGmt -> conflict"
check "$($WP $S post get $ID --field=post_title)" "Edited by a person" "the person's edit is kept"
FRESH=$(api $S GET "/posts/$ID" | jget "['modifiedGmt']")
R4=$(action $S page.update_content "{\"postId\":$ID,\"modifiedGmt\":\"$FRESH\",\"fields\":{\"title\":\"Agent title\"}}")
check "$(echo "$R4" | jget "['ok']")" "True" "fresh modifiedGmt -> ok"
check "$(echo "$R4" | jget "['result']['modifiedGmt'] is not None")" "True" "result returns the new modifiedGmt"
# #576 (site without a language plugin): language on a new post is refused, not dropped;
# status=trash lists trashed posts only; an unknown status is refused.
T="lang probe $RANDOM"
R=$(api $S POST /posts "{\"title\":\"$T\",\"content\":\"<p>x</p>\",\"status\":\"draft\",\"language\":\"fr\"}")
check "$(echo "$R" | jget "(d.get('code'), d.get('data',{}).get('status'))")" "('visibility_language_unsupported', 400)" "POST /posts language=fr without a language plugin -> refused"
check "$($WP $S post list --post_type=post --post_status=any --title="$T" --format=count)" "0" "no draft left behind"
R=$(action $S post.create_draft "{\"title\":\"$T\",\"content\":\"<p>x</p>\",\"language\":\"fr\"}")
check "$(echo "$R" | jget "(d['ok'], d.get('errorCode'))")" "(False, 'language_unsupported')" "post.create_draft language=fr -> refused"
check "$($WP $S post list --post_type=post --post_status=any --title="$T" --format=count)" "0" "still no draft"
SITE_LANG=$($WP $S eval 'echo substr(get_locale(), 0, 2);')
R=$(api $S POST /posts "{\"title\":\"$T\",\"content\":\"<p>x</p>\",\"status\":\"draft\",\"language\":\"$SITE_LANG\"}")
check "$(echo "$R" | jget "d.get('id') is not None")" "True" "the site's own language ($SITE_LANG) is fine"
$WP $S post delete $(echo "$R" | jget "['id']") --force >/dev/null
TR=$($WP $S post create --post_title="trash probe" --post_status=publish --porcelain | tr -d '\r' | tail -1); $WP $S post delete $TR >/dev/null
L=$(api $S GET "/posts?status=trash&per_page=100")
check "$(echo "$L" | jget "str(sorted(set(i['status'] for i in d['items'])))")" "['trash']" "status=trash lists trashed posts only"
check "$(echo "$L" | jget "str($TR in [i['id'] for i in d['items']])")" "True" "the trashed post is listed"
check "$(api $S GET "/posts?status=bogus" | jget "(d.get('code'), d.get('data',{}).get('status'))")" "('visibility_bad_status', 400)" "unknown status -> 400"
check "$(api $S GET "/posts?status=draft,publish&per_page=50" | jget "str(set(i['status'] for i in d['items']) <= {'draft','publish'})")" "True" "a list of known statuses still works"
summary
