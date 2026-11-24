#!/bin/bash
# T13 / #295: owner controls pushed from Visibility (allowed actions, content
# types, locked pages) are enforced by the plugin on executor actions and REST
# writes; the WordPress Pause button refuses every write, tells Visibility at
# once, and is not cleared by Visibility's own `disabled:false`.
cd "$(dirname "$0")"; . ./lib.sh
S=${SITE:-1}; PORT=3998; STATE=$(mktemp)
python3 mock-api.py $PORT "$STATE" & MOCK=$!; trap 'kill $MOCK; $WP $S option delete visibility_dev_api_base >/dev/null' EXIT; sleep 1
$WP $S option update visibility_dev_api_base "http://host.docker.internal:$PORT" >/dev/null
for o in visibility_controls visibility_allow_publish visibility_disabled visibility_paused_wp; do $WP $S option delete $o >/dev/null 2>&1; done
code() { echo "$1" | jget "d.get('errorCode')"; }
http() { curl -s -o /tmp/t13.json -w '%{http_code}' -X "$1" -H "Authorization: Bearer vis-test-token-$S" -H 'Content-Type: application/json' ${3:+--data "$3"} "http://localhost:$((PORT_BASE+S))/wp-json/visibility/v1$2"; }

LIVE=$($WP $S post create --post_type=page --post_status=publish --post_title="T13 live $RANDOM" --post_content='<!-- wp:paragraph --><p>live</p><!-- /wp:paragraph -->' --porcelain | tr -d '\r' | tail -1)
LOCK=$($WP $S post create --post_type=page --post_status=publish --post_title="T13 locked $RANDOM" --post_content='<!-- wp:paragraph --><p>locked</p><!-- /wp:paragraph -->' --porcelain | tr -d '\r' | tail -1)
OLD=$($WP $S post create --post_status=draft --post_title="T13 owner draft $RANDOM" --porcelain | tr -d '\r' | tail -1)

# Never received: nothing extra is enforced (0.10.0 behaviour).
R=$(action $S page.edit_parts "{\"postId\":$LIVE,\"edits\":[{\"target\":{\"path\":\"0\"},\"op\":\"replace_text\",\"text\":\"live 2\"}]}")
check "$(echo "$R" | jget "['ok']")" "True" "no controls received: edit allowed (as 0.10.0)"
check "$(api $S GET /capabilities | jget "d['permissions']['controls']")" "None" "capabilities: controls None until received"

# The app's defaults: drafts, meta tags, images on; edit content, publish, delete off.
CTL="{\"actions\":[\"create_drafts\",\"edit_meta\",\"images\"],\"postTypes\":[\"post\",\"page\"],\"lockedPostIds\":[$LOCK],\"fieldAllowlist\":[]}"
R=$(api $S POST /events/site-config-changed "{\"allowPublish\":true,\"disabled\":false,\"controls\":$CTL}")
check "$(echo "$R" | jget "d['controls']['lockedPostIds']")" "[$LOCK]" "site-config-changed stores and echoes controls"
check "$(echo "$R" | jget "d['allowPublish']")" "False" "allowPublish reported False (publish not in actions)"

R=$(action $S page.edit_parts "{\"postId\":$LIVE,\"edits\":[{\"target\":{\"path\":\"0\"},\"op\":\"replace_text\",\"text\":\"nope\"}]}")
check "$(code "$R")" "not_allowed" "edit_content off: edit_parts refused"
R=$(action $S post.publish "{\"postId\":$OLD}")
check "$(code "$R")" "not_allowed" "publish off in controls: post.publish refused (sent straight to the plugin)"
check "$($WP $S post get $OLD --field=post_status)" "draft" "post still a draft"
check "$(http PATCH /posts/$OLD '{"status":"publish"}')" "403" "PATCH status=publish -> 403"
check "$(jget "['code']" </tmp/t13.json)" "visibility_not_allowed" "REST code visibility_not_allowed"
check "$(http DELETE /posts/$OLD)" "403" "delete off: DELETE -> 403"
R=$(action $S seo_meta.update "{\"postId\":$LOCK,\"fields\":{\"metaDescription\":\"x\"}}")
check "$(code "$R")" "locked" "locked page: seo_meta.update refused"
check "$(http PATCH /posts/$LOCK '{"title":"x"}')" "403" "locked page: PATCH -> 403"
R=$(action $S seo_meta.update "{\"postId\":$LIVE,\"fields\":{\"metaDescription\":\"T13 allowed\"}}")
check "$(echo "$R" | jget "['ok']")" "True" "edit_meta on: seo_meta.update on an unlocked page works"

# Drafts Visibility builds: content edits count as create_drafts.
D=$(api $S POST /posts '{"title":"T13 agent draft"}' | jget "['id']")
check "$(api $S GET /posts/$D | jget "d['createdByVisibility']")" "True" "createdByVisibility on the new draft"
check "$(api $S GET /posts/$OLD | jget "d['createdByVisibility']")" "False" "createdByVisibility false on an owner's draft"
check "$(http PATCH /posts/$D '{"content":"<p>with image</p>"}')" "200" "PATCH content on Visibility's own draft = create_drafts -> allowed"
check "$(http PATCH /posts/$OLD '{"content":"<p>x</p>"}')" "403" "PATCH content on the owner's draft = edit_content -> 403"
R=$(action $S post.update_meta "{\"postId\":$LIVE,\"fields\":{\"featuredMediaId\":null}}")
check "$(echo "$R" | jget "['ok']")" "True" "featured image only = images control -> allowed"
check "$(http POST /media "{\"source_url\":\"http://localhost/vis-test-og.png\",\"post_id\":$D}")" "200" "POST /media for Visibility's draft allowed"

# A locked page's address can't be redirected away, even with redirects allowed (#464).
LSLUG=$($WP $S post get $LOCK --field=post_name | tr -d '\r')
LIVESLUG=$($WP $S post get $LIVE --field=post_name | tr -d '\r')
api $S POST /events/site-config-changed '{"controls":null}' >/dev/null
R=$(action $S redirect.create "{\"source\":\"/$LIVESLUG-old-$RANDOM/\",\"target\":\"/\"}")
OLDRID=$(echo "$R" | jget "d['result']['redirectId']")
api $S POST /events/site-config-changed "{\"controls\":{\"actions\":[\"create_drafts\",\"redirects\"],\"postTypes\":[\"post\",\"page\"],\"lockedPostIds\":[$LOCK],\"fieldAllowlist\":[]}}" >/dev/null
R=$(action $S redirect.create "{\"source\":\"/$LSLUG/\",\"target\":\"https://evil.example/landing\",\"type\":301}")
check "$(code "$R")" "locked" "locked page: redirect.create from its address refused"
R=$(action $S redirect.create "{\"source\":\"http://localhost:$((PORT_BASE+S))/$LSLUG\",\"target\":\"/\"}")
check "$(code "$R")" "locked" "locked page: same address as a full URL without the slash refused"
check "$(api $S GET "/redirects?source=/$LSLUG/" | jget "d['total']")" "0" "no redirect was added for the locked page"
check "$(curl -s -o /dev/null -w '%{http_code}' "http://localhost:$((PORT_BASE+S))/$LSLUG/")" "200" "locked page still answers 200"
# Its old address (an old slug WordPress still sends to it) is protected too.
$WP $S post meta add $LOCK _wp_old_slug "t13-protect-$LOCK" >/dev/null
R=$(action $S redirect.create "{\"source\":\"/t13-protect-$LOCK/\",\"target\":\"/\"}")
check "$(code "$R")" "locked" "locked page: its old slug can't be redirected either"
# A redirect that protects a locked page can't be deleted; an unrelated one can.
api $S POST /events/site-config-changed '{"controls":null}' >/dev/null
PRID=$(action $S redirect.create "{\"source\":\"/t13-protect-$LOCK/\",\"target\":\"/$LSLUG/\"}" | jget "d['result']['redirectId']")
api $S POST /events/site-config-changed "{\"controls\":{\"actions\":[\"create_drafts\",\"redirects\"],\"postTypes\":[\"post\",\"page\"],\"lockedPostIds\":[$LOCK],\"fieldAllowlist\":[]}}" >/dev/null
R=$(action $S redirect.delete "{\"redirectId\":\"$PRID\"}")
check "$(code "$R")" "locked" "redirect.delete of a redirect on a locked page's old address refused"
R=$(action $S redirect.delete "{\"redirectId\":\"$OLDRID\"}")
check "$(echo "$R" | jget "d['ok']")" "True" "redirect.delete of an unrelated redirect allowed"
R=$(action $S redirect.create "{\"source\":\"/t13-free-$RANDOM/\",\"target\":\"/\"}")
check "$(echo "$R" | jget "d['ok']")" "True" "redirects on: an address that isn't a locked page works"
$WP $S post meta delete $LOCK _wp_old_slug "t13-protect-$LOCK" >/dev/null
api $S POST /events/site-config-changed '{"controls":null}' >/dev/null
action $S redirect.delete "{\"redirectId\":\"$PRID\"}" >/dev/null
api $S POST /events/site-config-changed "{\"controls\":$CTL}" >/dev/null

# Content types.
api $S POST /events/site-config-changed "{\"controls\":{\"actions\":[\"create_drafts\",\"edit_meta\",\"images\",\"edit_content\"],\"postTypes\":[\"post\"],\"lockedPostIds\":[],\"fieldAllowlist\":[]}}" >/dev/null
R=$(action $S page.create_draft '{"title":"T13 page"}')
check "$(code "$R")" "not_allowed" "postTypes=[post]: page.create_draft refused"
R=$(action $S page.edit_parts "{\"postId\":$LIVE,\"edits\":[{\"target\":{\"path\":\"0\"},\"op\":\"replace_text\",\"text\":\"x\"}]}")
check "$(code "$R")" "not_allowed" "postTypes=[post]: editing a page refused"
R=$(action $S post.create_draft '{"title":"T13 post ok"}')
check "$(echo "$R" | jget "['ok']")" "True" "post.create_draft allowed"

# Pause in WordPress (logged-in admin clicks the button).
CJ=$(mktemp); U="http://localhost:$((PORT_BASE+S))"
curl -s -c $CJ -b $CJ -o /dev/null $U/wp-login.php
curl -s -c $CJ -b $CJ -b 'wordpress_test_cookie=WP+Cookie+check' -o /dev/null -d 'log=admin&pwd=admin&wp-submit=Log+In&testcookie=1' $U/wp-login.php
H=$(curl -s -b $CJ "$U/wp-admin/admin.php?page=visibility")
grep -q 'id="visibility-pause"' <<<"$H" && ok "settings page has the Pause all button" || bad "no pause button"
grep -q 'id="visibility-controls"' <<<"$H" && ok "settings page shows the controls read-only" || bad "controls not shown"
NONCE=$(grep -B2 -A6 'visibility_pause' <<<"$H" | grep -oE 'name="_wpnonce" value="[a-f0-9]+"' | head -1 | grep -oE '[a-f0-9]{10}')
curl -s -b $CJ -o /dev/null -d "action=visibility_pause&visibility_pause=on&_wpnonce=$NONCE" $U/wp-admin/admin-post.php
check "$(python3 -c "import json;h=json.load(open('$STATE')).get('heartbeats',[]);print(h[-1].get('paused'),h[-1].get('pausedBy')) if h else print('none')")" "True wordpress" "Pause sent a heartbeat at once: paused=True, pausedBy=wordpress"
R=$(action $S post.create_draft '{"title":"T13 paused"}')
check "$(code "$R")" "paused" "paused in WP: create_draft refused"
check "$(http POST /posts '{"title":"x"}')" "403" "paused in WP: POST /posts -> 403"
api $S POST /events/site-config-changed '{"disabled":false}' >/dev/null
check "$(api $S GET /capabilities | jget "d['permissions']['pausedBy']")" "wordpress" "Visibility's disabled:false does not clear the WordPress pause"
check "$(http GET /posts/$LIVE)" "200" "reads still work while paused"
H=$(curl -s -b $CJ "$U/wp-admin/admin.php?page=visibility")
NONCE=$(grep -B2 -A6 'visibility_pause' <<<"$H" | grep -oE 'name="_wpnonce" value="[a-f0-9]+"' | head -1 | grep -oE '[a-f0-9]{10}')
curl -s -b $CJ -o /dev/null -d "action=visibility_pause&visibility_pause=off&_wpnonce=$NONCE" $U/wp-admin/admin-post.php
check "$(python3 -c "import json;h=json.load(open('$STATE')).get('heartbeats',[]);print(h[-1].get('paused'),h[-1].get('pausedBy'))")" "False None" "Resume sent a heartbeat: paused=False"
api $S POST /events/site-config-changed '{"disabled":true}' >/dev/null
check "$(api $S GET /capabilities | jget "d['permissions']['pausedBy']")" "visibility" "disabled from Visibility -> pausedBy visibility"
api $S POST /events/site-config-changed '{"disabled":false}' >/dev/null

# Heartbeat reply carries controls too; controls:null resets to never received.
python3 - "$STATE" <<'PY'
import json,sys; s=json.load(open(sys.argv[1])); s["heartbeat"]={"siteId":"test-site-1","allowPublish":True,"disabled":False,"controls":{"actions":["create_drafts"],"postTypes":["post","page"],"lockedPostIds":[],"fieldAllowlist":[]}}; json.dump(s,open(sys.argv[1],"w"))
PY
$WP $S eval 'Visibility_Client::heartbeat();' >/dev/null
check "$(api $S GET /capabilities | jget "d['permissions']['controls']['actions']")" "['create_drafts']" "heartbeat reply updates controls"
# #474: the settings page says when Visibility last sent them, and "Check in now" runs the heartbeat.
H=$(curl -s -b $CJ "$U/wp-admin/admin.php?page=visibility")
grep -q 'id="visibility-controls-at">Last updated from Visibility' <<<"$H" && ok "settings page shows when the controls were last updated" || bad "no 'last updated from Visibility'"
NONCE=$(grep 'value="visibility_checkin"' <<<"$H" | grep -oE 'name="_wpnonce" value="[a-f0-9]+"' | head -1 | grep -oE '[a-f0-9]{10}')
python3 - "$STATE" <<'PY'
import json,sys; s=json.load(open(sys.argv[1])); s["heartbeat"]["controls"]["actions"]=["create_drafts","edit_meta"]; json.dump(s,open(sys.argv[1],"w"))
PY
N0=$(python3 -c "import json;print(len(json.load(open('$STATE')).get('heartbeats',[])))")
L=$(curl -s -b $CJ -o /dev/null -w '%{redirect_url}' -d "action=visibility_checkin&_wpnonce=$NONCE" $U/wp-admin/admin-post.php)
check "$(python3 -c "import json;print(len(json.load(open('$STATE')).get('heartbeats',[])))")" "$((N0+1))" "Check in now sent a heartbeat"
grep -q 'visibility_status=checked_in' <<<"$L" && ok "Check in now reports success" || bad "check-in result: $L"
check "$(api $S GET /capabilities | jget "d['permissions']['controls']['actions']")" "['create_drafts', 'edit_meta']" "Check in now took the latest controls"
# #473: the first request on a new version tells Visibility at once.
$WP $S option update visibility_installed_version 0.0.1 >/dev/null
N0=$(python3 -c "import json;print(len(json.load(open('$STATE')).get('heartbeats',[])))")
curl -s -o /dev/null "$U/"
check "$(python3 -c "import json;h=json.load(open('$STATE')).get('heartbeats',[]);print(len(h)-$N0, h[-1].get('pluginVersion') if h else '')")" "1 $($WP $S eval 'echo VISIBILITY_PLUGIN_VERSION;' | tr -d '\r')" "a new version sends one heartbeat with it at once"
curl -s -o /dev/null "$U/"
check "$(python3 -c "import json;print(len(json.load(open('$STATE')).get('heartbeats',[]))-$N0)")" "1" "only once per version"
api $S POST /events/site-config-changed '{"controls":null}' >/dev/null
check "$(api $S GET /capabilities | jget "d['permissions']['controls']")" "None" "controls:null resets to never received"
check "$(api $S GET /capabilities | jget "'privacy' in d['specialPages']")" "True" "specialPages lists the privacy page"
rm -f $CJ
summary
