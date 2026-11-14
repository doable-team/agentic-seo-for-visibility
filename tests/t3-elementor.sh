#!/bin/bash
# T3 / #320 (Elementor, part of #286): a widget change by element id shows on
# the front end, Elementor's CSS is regenerated, the page still opens in
# Elementor, other widgets are untouched; rollback; Rank Math schema once.
# Site 6: Astra + Elementor + Rank Math. Builds its page through Elementor's
# own Document::save as admin.
cd "$(dirname "$0")"; . ./lib.sh
S=${SITE:-6}; U="http://localhost:818$S"
IMG=$(api $S POST /media '{"source_url":"http://localhost/vis-test-og.png","title":"elementor hero","alt_text":"old elementor alt"}' | jget "['id']")
ID=$($WP $S eval-file - <<EOF | tr -d '\r' | tail -1
<?php
wp_set_current_user(1);
\$id = wp_insert_post(['post_type'=>'page','post_status'=>'publish','post_title'=>'T3 Elementor $RANDOM']);
update_post_meta(\$id, '_elementor_edit_mode', 'builder');
\$doc = \Elementor\Plugin::\$instance->documents->get(\$id, false);
\$doc->save(['elements'=>[[ 'id'=>'c0ntain','elType'=>'container','settings'=>[],'isInner'=>false,'elements'=>[
  ['id'=>'h3ad1ng','elType'=>'widget','widgetType'=>'heading','settings'=>['title'=>'Old Elementor heading','header_size'=>'h2','title_color'=>'#aa0000'],'elements'=>[]],
  ['id'=>'t3xt0ne','elType'=>'widget','widgetType'=>'text-editor','settings'=>['editor'=>'<p>Old body text with <strong>bold</strong>.</p>'],'elements'=>[]],
  ['id'=>'1mage01','elType'=>'widget','widgetType'=>'image','settings'=>['image'=>['url'=>wp_get_attachment_url($IMG),'id'=>$IMG,'alt'=>'','source'=>'library']],'elements'=>[]],
  ['id'=>'butt0n1','elType'=>'widget','widgetType'=>'button','settings'=>['text'=>'Old button','link'=>['url'=>'https://example.com/old','is_external'=>'','nofollow'=>'']],'elements'=>[]],
]]]]);
echo \$id;
EOF
)
CONTENT0=$($WP $S post get $ID --field=post_content)
CSS="/var/www/html/wp-content/uploads/elementor/css/post-$ID.css"
for i in 1 2 3; do curl -sL -o /dev/null "$U/?page_id=$ID"; docker exec viswp-$S test -f $CSS && break; sleep 1; done
docker exec viswp-$S test -f $CSS && ok "Elementor CSS file exists before the edit" || bad "no CSS file before"
CSS_T0=$(docker exec viswp-$S stat -c %Y $CSS 2>/dev/null)

R=$(api $S GET "/posts/$ID?include=blocks,builder")
check "$(echo "$R" | jget "d['editor']")" "builder:elementor" "editor = builder:elementor"
check "$(echo "$R" | jget "d['builder']['writable']")" "True" "builder writable"
check "$(echo "$R" | jget "list(e['elementId']+':'+e['widgetType'] for e in d['elements'])")" "['h3ad1ng:heading', 't3xt0ne:text-editor', '1mage01:image', 'butt0n1:button']" "elements listed by id"
check "$(echo "$R" | jget "list(e['attrs'].get('alt') for e in d['elements'] if e['widgetType']=='image')")" "['old elementor alt']" "image alt read from the media library"
check "$(api $S GET /capabilities | jget "list(b['writable'] for b in d['builders'] if b['slug']=='elementor')")" "[True]" "capabilities: elementor writable"
DATA0=$($WP $S post meta get $ID _elementor_data)

sleep 1
R=$(action $S page.edit_parts "{\"postId\":$ID,\"edits\":[{\"target\":{\"elementId\":\"h3ad1ng\"},\"op\":\"replace_text\",\"text\":\"New Elementor heading\"},{\"target\":{\"elementId\":\"h3ad1ng\"},\"op\":\"set_attr\",\"attrs\":{\"level\":1}},{\"target\":{\"elementId\":\"1mage01\"},\"op\":\"set_attr\",\"attrs\":{\"alt\":\"Rankings chart, clear alt\"}},{\"target\":{\"elementId\":\"butt0n1\"},\"op\":\"set_attr\",\"attrs\":{\"href\":\"https://example.com/new\"}}]}")
check "$(echo "$R" | jget "['ok']")" "True" "edit_parts on Elementor widgets ok"
check "$(echo "$R" | jget "d['result']['diff'][0]['blockName']")" "elementor/heading" "diff names the widget"
CH=$(echo "$R" | jget "d['result']['changeId']")
# Elementor keeps a plain-HTML copy of the page in post_content for feeds and search; its own save refreshes it.
grep -q '<h1>New Elementor heading</h1>' <<<"$($WP $S post get $ID --field=post_content)" && ok "Elementor refreshed its post_content copy" || bad "post_content copy stale"
DATA1=$($WP $S post meta get $ID _elementor_data)
python3 - "$DATA0" "$DATA1" <<'PY' && ok "_elementor_data valid JSON; only the targeted settings changed" || bad "_elementor_data changed elsewhere"
import json,sys
a=json.loads(sys.argv[1]); b=json.loads(sys.argv[2])
wa={w['id']:w for w in a[0]['elements']}; wb={w['id']:w for w in b[0]['elements']}
assert wb['h3ad1ng']['settings']['title']=='New Elementor heading' and wb['h3ad1ng']['settings']['header_size']=='h1'
assert wb['h3ad1ng']['settings']['title_color']=='#aa0000'
assert wa['t3xt0ne']==wb['t3xt0ne'], 'text widget changed'
assert wb['butt0n1']['settings']['link']['url']=='https://example.com/new' and wb['butt0n1']['settings']['text']=='Old button'
PY
check "$($WP $S post meta get $IMG _wp_attachment_image_alt)" "Rankings chart, clear alt" "image alt stored on the attachment"
PAGE=$(curl -sL "$U/?page_id=$ID")
grep -qE '<h1 class="elementor-heading-title[^"]*">New Elementor heading</h1>' <<<"$PAGE" && ok "front end shows the new <h1> heading" || bad "front end heading: $(grep -o 'elementor-heading-title[^<]*<' <<<"$PAGE" | head -2)"
grep -q 'alt="Rankings chart, clear alt"' <<<"$PAGE" && ok "front end shows the new alt" || bad "front end alt"
grep -q 'href="https://example.com/new"' <<<"$PAGE" && ok "front end button link" || bad "front end link"
grep -q 'Old body text with <strong>bold</strong>' <<<"$PAGE" && ok "untouched text widget still rendered" || bad "text widget missing"
CSS_T1=$(docker exec viswp-$S stat -c %Y $CSS 2>/dev/null)
[ -n "$CSS_T1" ] && [ "$CSS_T1" -gt "${CSS_T0:-0}" ] && ok "Elementor CSS regenerated ($CSS_T0 -> $CSS_T1)" || bad "CSS not regenerated ($CSS_T0 -> $CSS_T1)"
check "$($WP $S eval "echo \Elementor\Plugin::\$instance->documents->get($ID)->is_built_with_elementor() ? 'yes' : 'no';")" "yes" "page still built with Elementor"
CJ=$(mktemp)
curl -s -c $CJ -b $CJ -o /dev/null $U/wp-login.php
curl -s -c $CJ -b $CJ -b 'wordpress_test_cookie=WP+Cookie+check' -o /dev/null -d 'log=admin&pwd=admin&wp-submit=Log+In&testcookie=1' $U/wp-login.php
ED=$(curl -s -b $CJ "$U/wp-admin/post.php?post=$ID&action=elementor")
grep -q 'elementor-editor' <<<"$ED" && ok "page opens in the Elementor editor" || bad "Elementor editor did not load"
grep -q 'New Elementor heading' <<<"$(curl -s -b $CJ "$U/wp-admin/post.php?post=$ID&action=elementor"; $WP $S eval "echo json_encode(\Elementor\Plugin::\$instance->documents->get($ID)->get_elements_data());")" && ok "editor data has the new heading" || bad "editor data"
rm -f $CJ

# Refusals.
R=$(action $S page.edit_parts "{\"postId\":$ID,\"edits\":[{\"target\":{\"elementId\":\"nope123\"},\"op\":\"replace_text\",\"text\":\"x\"}]}")
check "$(echo "$R" | jget "['errorCode']")" "not_found" "unknown element id -> not_found"
R=$(action $S page.edit_parts "{\"postId\":$ID,\"edits\":[{\"target\":{\"path\":\"0\"},\"op\":\"replace_text\",\"text\":\"x\"}]}")
check "$(echo "$R" | jget "['errorCode']")" "invalid" "block path on a builder page -> invalid (use elementId)"

# Rollback restores _elementor_data and the front end.
R=$(api $S POST /changes/$CH/rollback)
check "$(echo "$R" | jget "['ok']")" "True" "rollback ok"
check "$($WP $S post meta get $ID _elementor_data)" "$DATA0" "_elementor_data restored byte-identical"
check "$($WP $S post meta get $IMG _wp_attachment_image_alt)" "Rankings chart, clear alt" "(media library alt is the image's own; not part of the page change)"
grep -q '>Old Elementor heading</h2>' <<<"$(curl -sL "$U/?page_id=$ID")" && ok "front end back to the old heading" || bad "front end not restored"

# Rank Math schema exactly once (B02).
R=$(action $S seo_meta.update "{\"postId\":$ID,\"fields\":{\"metaDescription\":\"T3 desc\",\"schemaJsonLd\":{\"@type\":\"FAQPage\",\"mainEntity\":[{\"@type\":\"Question\",\"name\":\"Q t3el$ID\",\"acceptedAnswer\":{\"@type\":\"Answer\",\"text\":\"A\"}}]}}}")
python3 jsonld-check.py "t3el$ID" <<<"$(curl -sL "$U/?page_id=$ID")" && ok "Rank Math schema contains the injected JSON-LD once" || bad "schema not once"
summary
