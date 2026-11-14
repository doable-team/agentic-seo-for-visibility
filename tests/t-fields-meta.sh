#!/bin/bash
# #288 shared part: native registered meta (show_in_rest) listed in
# /fields/schema, written by key only when allowlisted; denied keys, unknown
# keys and bad values refused; edit_fields control enforced; rollback works.
# Uses site 7's fixture (registered meta `vis_subtitle` on pages).
cd "$(dirname "$0")"; . ./lib.sh
S=${SITE:-7}
docker cp -q /home/tulasinath/viswp/mu/vis-fields-fixture.php viswp-$S:/var/www/html/wp-content/mu-plugins/
code() { echo "$1" | jget "d.get('errorCode')"; }
P=$($WP $S post create --post_type=page --post_status=publish --post_title="T288 $RANDOM" --porcelain | tr -d '\r' | tail -1)
$WP $S post meta update $P vis_subtitle "Old subtitle" >/dev/null
api $S POST /events/site-config-changed '{"controls":null}' >/dev/null

F=$(api $S GET "/fields/schema?type=page" | jget "list(f for f in d['fields'] if f['key']=='meta:vis_subtitle')[0]")
check "$(echo "$F" | python3 -c "import sys,ast; f=ast.literal_eval(sys.stdin.read()); print(f['label'],f['type'],f['source'],f['writable'])")" "Subtitle string meta False" "schema: registered meta listed with label/type, not writable without allowlist"
check "$(curl -s -o /dev/null -w '%{http_code}' -H "Authorization: Bearer vis-test-token-$S" "http://localhost:$((8180+S))/wp-json/visibility/v1/fields/schema?type=nav_menu_item")" "400" "schema: non-public type -> 400"
R=$(action $S fields.update "{\"postId\":$P,\"fields\":{\"meta:vis_subtitle\":\"x\"}}")
check "$(code "$R")" "not_allowed" "no controls: field write refused"

api $S POST /events/site-config-changed '{"controls":{"actions":["edit_meta"],"postTypes":["page"],"lockedPostIds":[],"fieldAllowlist":["meta:vis_subtitle"]}}' >/dev/null
R=$(action $S fields.update "{\"postId\":$P,\"fields\":{\"meta:vis_subtitle\":\"x\"}}")
check "$(code "$R")" "not_allowed" "edit_fields off: refused even when allowlisted"
api $S POST /events/site-config-changed '{"controls":{"actions":["edit_fields"],"postTypes":["page"],"lockedPostIds":[],"fieldAllowlist":["meta:vis_subtitle","meta:_wp_page_template","meta:other_key"]}}' >/dev/null
check "$(api $S GET "/posts/$P" | jget "dict((f['key'],(f['value'],f['writable'])) for f in d['fields'])['meta:vis_subtitle']")" "('Old subtitle', True)" "read: value + writable in GET /posts fields"
R=$(action $S fields.update "{\"postId\":$P,\"fields\":{\"meta:vis_subtitle\":\"New <b>subtitle</b>\"}}")
check "$(echo "$R" | jget "['ok']")" "True" "allowlisted meta written"
check "$(echo "$R" | jget "d['result']['before']['meta:vis_subtitle']+' -> '+d['result']['after']['meta:vis_subtitle']")" "Old subtitle -> New subtitle" "before/after, text sanitised"
CH=$(echo "$R" | jget "d['result']['changeId']")
R=$(action $S fields.update "{\"postId\":$P,\"fields\":{\"meta:_wp_page_template\":\"x\"}}")
check "$(code "$R")" "not_allowed" "allowlisted but denied (_wp_*) refused"
R=$(action $S fields.update "{\"postId\":$P,\"fields\":{\"meta:other_key\":\"x\"}}")
check "$(code "$R")" "not_found" "a key that is not a field on this page is never written"
check "$($WP $S post meta get $P other_key 2>/dev/null)" "" "no arbitrary meta written"
R=$(action $S fields.update "{\"postId\":$P,\"fields\":{\"meta:vis_subtitle\":[1,2]}}")
check "$(code "$R")" "invalid" "wrong value type refused"
api $S POST /changes/$CH/rollback >/dev/null
check "$($WP $S post meta get $P vis_subtitle)" "Old subtitle" "rollback restored the field"
api $S POST /events/site-config-changed '{"controls":null}' >/dev/null
summary
