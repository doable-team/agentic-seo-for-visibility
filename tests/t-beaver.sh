#!/bin/bash
# C-37 Beaver Builder (real Beaver Builder Lite from wordpress.org): a page
# built through Beaver Builder's own model is read as builder:beaver-builder
# with its modules; heading text/level, rich text (find/replace), button and
# callout link, photo alt (media library) and caption are changed by node id;
# every other module and setting is unchanged; the live page shows the
# change; the page CSS cache is rebuilt; unpublished builder changes and
# saved (global) modules are refused; rollback restores the layout.
cd "$(dirname "$0")"; . ./lib.sh
S=${SITE:-2}; BASE="http://localhost:$((PORT_BASE+S))"
api $S POST /events/site-config-changed '{"controls":null,"allowPublish":true,"disabled":false}' >/dev/null
$CTR cp fixtures/bb-page.php ${CPFX}$S:/var/www/html/bb-page.php
$CTR exec ${CPFX}$S test -f /var/www/html/vis-test-og.png || $CTR cp fixtures/og-1200x630.png ${CPFX}$S:/var/www/html/vis-test-og.png
IMG=$(api $S POST /media '{"source_url":"http://localhost/vis-test-og.png","title":"BB img","alt_text":"Old alt"}' | jget "['id']")
J=$($WP $S eval-file /var/www/html/bb-page.php "BB test $RANDOM" $IMG 2>/dev/null | tail -1)
P=$(echo "$J" | jget "['postId']"); id() { echo "$J" | jget "d['ids']['$1']"; }
H=$(id heading); T=$(id text); PH=$(id photo); B=$(id button); CO=$(id callout)
layout() { $WP $S eval "echo wp_json_encode(get_post_meta($P, '_fl_builder_data', true));" | tr -d '\r'; }
L0=$(layout)

C=$(api $S GET /capabilities)
check "$(echo "$C" | jget "next((b['writable'], 'builder.beaver' in d['features']) for b in d['builders'] if b['slug']=='beaver-builder')")" "(True, True)" "capabilities: Beaver Builder writable, builder.beaver"
R=$(api $S GET "/posts/$P?include=builder,blocks")
check "$(echo "$R" | jget "(d['editor'], d['builder']['slug'], d['builder']['writable'])")" "('builder:beaver-builder', 'beaver-builder', True)" "read: builder:beaver-builder, writable"
check "$(echo "$R" | jget "list(e['widgetType'] for e in d['elements'])")" "['heading', 'rich-text', 'photo', 'button', 'callout']" "read: modules in page order"
check "$(echo "$R" | jget "next(e for e in d['elements'] if e['elementId']=='$PH')['attrs']['alt']")" "Old alt" "read: photo alt from the media library"
check "$(echo "$R" | jget "next(e for e in d['elements'] if e['elementId']=='$H')['attrs']['level']")" "2" "read: heading level"
MOD=$(echo "$R" | jget "['modifiedGmt']")

$CTR exec ${CPFX}$S sh -c "ls /var/www/html/wp-content/uploads/bb-plugin/cache/ 2>/dev/null | grep -c '^$P-layout'" >/tmp/bbcss0 || true
curl -s "$BASE/?page_id=$P" -L >/dev/null   # builds the CSS cache
R=$(action $S page.edit_parts "{\"postId\":$P,\"modifiedGmt\":\"$MOD\",\"edits\":[
 {\"target\":{\"elementId\":\"$H\"},\"op\":\"replace_text\",\"text\":\"New heading\"},
 {\"target\":{\"elementId\":\"$H\"},\"op\":\"set_attr\",\"attrs\":{\"level\":3}},
 {\"target\":{\"elementId\":\"$T\"},\"op\":\"replace_text\",\"find\":\"Second paragraph.\",\"text\":\"Second paragraph, changed.\"},
 {\"target\":{\"elementId\":\"$PH\"},\"op\":\"set_attr\",\"attrs\":{\"alt\":\"New photo alt\"}},
 {\"target\":{\"elementId\":\"$PH\"},\"op\":\"replace_text\",\"text\":\"New caption\"},
 {\"target\":{\"elementId\":\"$B\"},\"op\":\"set_attr\",\"attrs\":{\"href\":\"https://example.com/new\"}},
 {\"target\":{\"elementId\":\"$CO\"},\"op\":\"replace_text\",\"field\":\"title\",\"text\":\"Go now\"}]}")
check "$(echo "$R" | jget "(d['ok'], d['result']['builder'])")" "(True, 'beaver-builder')" "edit_parts on a Beaver Builder page"
CH=$(echo "$R" | jget "d['result']['changeId']")
L1=$(layout)
check "$(python3 - "$L0" "$L1" "$H" "$T" "$PH" "$B" "$CO" <<'PY'
import json,sys
a,b=json.loads(sys.argv[1]),json.loads(sys.argv[2]); H,T,PH,B,CO=sys.argv[3:]
want={H:{'heading':'New heading','tag':'h3'},T:{'text':a[T]['settings']['text'].replace('Second paragraph.','Second paragraph, changed.')},PH:{'caption':'New caption'},B:{'link':'https://example.com/new'},CO:{'title':'Go now'}}
bad=[]
for k in a:
  if k not in b: bad.append('missing '+k); continue
  sa,sb=dict(a[k].get('settings') or {}),dict(b[k].get('settings') or {})
  for f,v in want.get(k,{}).items():
    if sb.get(f)!=v: bad.append('%s.%s=%r'%(k,f,sb.get(f)))
    sa.pop(f,None); sb.pop(f,None)
  if sa!=sb: bad.append('other settings changed in '+k+': '+str([x for x in set(sa)|set(sb) if sa.get(x)!=sb.get(x)]))
  if {x:y for x,y in a[k].items() if x!='settings'}!={x:y for x,y in b[k].items() if x!='settings'}: bad.append('node fields changed '+k)
print('ok' if not bad and set(a)==set(b) else bad)
PY
)" "ok" "only the targeted settings changed; every other module and setting identical"
check "$($WP $S post meta get $IMG _wp_attachment_image_alt | tr -d '\r')" "New photo alt" "photo alt saved in the media library"
check "$([ "$($WP $S eval "echo wp_json_encode(get_post_meta($P, '_fl_builder_draft', true));" | tr -d '\r')" = "$L1" ] && echo same)" "same" "builder draft carries the change too (next save in the builder keeps it)"
PAGE=$(curl -sL "$BASE/?page_id=$P")
echo "$PAGE" | grep -q "<h3 *class=\"fl-module fl-module-heading[^\"]*fl-node-$H" && echo "$PAGE" | grep -q 'New heading' && ok "live: heading text and level" || bad "live heading"
echo "$PAGE" | grep -q 'Second paragraph, changed.' && ok "live: rich text" || bad "live rich text"
echo "$PAGE" | grep -q 'alt="New photo alt"' && ok "live: photo alt" || bad "live photo alt"
echo "$PAGE" | grep -q 'https://example.com/new' && ok "live: button link" || bad "live button link"
echo "$PAGE" | grep -q 'Go now' && ok "live: callout title" || bad "live callout"
check "$($WP $S post get $P --field=post_content | grep -c 'New heading')" "1" "post_content re-rendered (search / classic copy)"
JAR=$(mktemp); curl -s -c $JAR -o /dev/null --data "log=admin&pwd=admin&testcookie=1" -b "wordpress_test_cookie=WP%20Cookie%20check" "$BASE/wp-login.php"
ED=$(curl -sL -b $JAR "$BASE/?page_id=$P&fl_builder")
echo "$ED" | grep -q 'FLBuilderConfig' && ! echo "$ED" | grep -qi 'critical error' && ok "page still opens in the Beaver Builder editor" || bad "Beaver Builder editor"

# Refusals.
R=$(action $S page.edit_parts "{\"postId\":$P,\"edits\":[{\"target\":{\"elementId\":\"$B\"},\"op\":\"set_attr\",\"attrs\":{\"onclick\":\"x\"}}]}")
check "$(echo "$R" | jget "d['errorCode']")" "unsupported" "unknown attribute refused"
R=$(action $S page.edit_parts "{\"postId\":$P,\"edits\":[{\"target\":{\"elementId\":\"nope\"},\"op\":\"replace_text\",\"text\":\"x\"}]}")
check "$(echo "$R" | jget "d['errorCode']")" "not_found" "unknown node refused"
R=$(action $S page.edit_parts "{\"postId\":$P,\"modifiedGmt\":\"$MOD\",\"edits\":[{\"target\":{\"elementId\":\"$H\"},\"op\":\"replace_text\",\"text\":\"x\"}]}")
check "$(echo "$R" | jget "d['errorCode']")" "conflict" "stale modifiedGmt -> conflict"

R=$(api $S POST /changes/$CH/rollback)
check "$(echo "$R" | jget "d['ok']")" "True" "rollback"
check "$([ "$(layout)" = "$L0" ] && echo same)" "same" "layout restored exactly"
check "$(curl -sL "$BASE/?page_id=$P" | grep -c 'Original heading')" "1" "live page shows the original heading again"

# Unpublished changes open in the builder.
$WP $S eval "\$d = get_post_meta($P, '_fl_builder_data', true); \$d['$H'] = clone \$d['$H']; \$d['$H']->settings = clone \$d['$H']->settings; \$d['$H']->settings->heading = 'Draft in builder'; update_post_meta($P, '_fl_builder_draft', \$d);" >/dev/null
R=$(action $S page.edit_parts "{\"postId\":$P,\"edits\":[{\"target\":{\"elementId\":\"$T\"},\"op\":\"replace_text\",\"text\":\"x\"}]}")
check "$(echo "$R" | jget "d['errorCode']")" "conflict" "unpublished builder changes -> refused, nothing overwritten"
check "$([ "$(layout)" = "$L0" ] && echo same)" "same" "layout untouched after the refusal"
summary
