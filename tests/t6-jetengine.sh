#!/bin/bash
# T6 / #337 JetEngine, against a STAND-IN (tests/standins/jetengine-standin.php,
# built from JetEngine's meta box config format and storage; not the real paid
# plugin): fields listed with types and choices, refused until allowlisted,
# written as JetEngine stores them (plain meta; switcher "true"/"false"; media
# id), bad values refused, layout tabs skipped, rollback restores them.
cd "$(dirname "$0")"; . ./lib.sh
S=${SITE:-1}
code() { echo "$1" | jget "d.get('errorCode')"; }
$CTR cp standins/jetengine-standin.php ${CPFX}$S:/var/www/html/wp-content/mu-plugins/jetengine-standin.php
$WP $S option update jet_engine_meta_boxes --format=json '{"meta-1":{"args":{"object_type":"post","allowed_post_type":["page"],"name":"Service details"},"meta_fields":[
 {"title":"Main","name":"tab_main","object_type":"tab","type":"text"},
 {"title":"Tagline","name":"je_tagline","object_type":"field","type":"text"},
 {"title":"Intro","name":"je_intro","object_type":"field","type":"textarea"},
 {"title":"Level","name":"je_level","object_type":"field","type":"select","options":[{"key":"basic","value":"Basic"},{"key":"pro","value":"Pro"}]},
 {"title":"Featured","name":"je_featured","object_type":"field","type":"switcher"},
 {"title":"Badge","name":"je_badge","object_type":"field","type":"media","value_format":"id"},
 {"title":"Gallery","name":"je_gallery","object_type":"field","type":"gallery"},
 {"title":"Secret token","name":"je_api_token","object_type":"field","type":"text"}]}}' >/dev/null
IMG=$(api $S POST /media '{"source_url":"http://localhost/vis-test-og.png","title":"T6 JE badge"}' | jget "['id']")
P=$($WP $S post create --post_type=page --post_status=publish --post_title="T6 JetEngine $RANDOM" --porcelain | tr -d '\r' | tail -1)
$WP $S post meta update $P je_tagline "Old tagline" >/dev/null
$WP $S post meta update $P je_featured false >/dev/null

C=$(api $S GET /capabilities)
check "$(echo "$C" | jget "list((f['slug'],f['writable']) for f in d['customFields'] if f['slug']=='jetengine')")" "[('jetengine', True)]" "capabilities: JetEngine listed, writable"
SCH=$(api $S GET "/fields/schema?type=page")
check "$(echo "$SCH" | jget "list((f['key'],f['type']) for f in d['fields'] if f['source']=='jetengine')")" "[('jetengine:je_tagline', 'text'), ('jetengine:je_intro', 'textarea'), ('jetengine:je_level', 'select'), ('jetengine:je_featured', 'boolean'), ('jetengine:je_badge', 'image'), ('jetengine:je_gallery', 'gallery'), ('jetengine:je_api_token', 'text')]" "schema: fields typed, tab skipped"
check "$(echo "$SCH" | jget "next(f for f in d['fields'] if f['key']=='jetengine:je_level')['choices']")" "['basic', 'pro']" "schema: select choices"
check "$(echo "$SCH" | jget "next(f for f in d['fields'] if f['key']=='jetengine:je_tagline')['group']")" "Service details (JetEngine)" "schema: group"

api $S POST /events/site-config-changed '{"controls":null}' >/dev/null
R=$(action $S fields.update "{\"postId\":$P,\"fields\":{\"jetengine:je_tagline\":\"x\"}}")
check "$(code "$R")" "not_allowed" "no allowlist: refused"
ALLOW='["jetengine:je_tagline","jetengine:je_intro","jetengine:je_level","jetengine:je_featured","jetengine:je_badge","jetengine:je_gallery","jetengine:je_api_token"]'
api $S POST /events/site-config-changed "{\"controls\":{\"actions\":[\"create_drafts\",\"edit_meta\",\"images\",\"edit_fields\",\"edit_content\"],\"postTypes\":[\"post\",\"page\",\"product\"],\"lockedPostIds\":[],\"fieldAllowlist\":$ALLOW}}" >/dev/null
check "$(api $S GET /posts/$P | jget "dict((f['key'],f['value']) for f in d['fields'])['jetengine:je_tagline']")" "Old tagline" "read: value"

R=$(action $S fields.update "{\"postId\":$P,\"fields\":{\"jetengine:je_tagline\":\"New tagline\",\"jetengine:je_level\":\"pro\",\"jetengine:je_featured\":true,\"jetengine:je_badge\":$IMG,\"jetengine:je_intro\":\"Line 1\\nLine 2\"}}")
check "$(echo "$R" | jget "d['ok']")" "True" "fields.update ok"
CH=$(echo "$R" | jget "d['result']['changeId']")
check "$($WP $S post meta get $P je_tagline)" "New tagline" "stored as plain meta"
check "$($WP $S post meta get $P je_featured)" "true" "switcher stored as \"true\""
check "$($WP $S post meta get $P je_badge)" "$IMG" "media stored as the attachment id"
check "$($WP $S post meta get $P je_level)" "pro" "select stored"
check "$(echo "$R" | jget "d['result']['after']['jetengine:je_badge']['id']")" "$IMG" "result: image read back"

R=$(action $S fields.update "{\"postId\":$P,\"fields\":{\"jetengine:je_level\":\"gold\"}}")
check "$(code "$R")" "invalid" "value outside the choices refused"
R=$(action $S fields.update "{\"postId\":$P,\"fields\":{\"jetengine:je_api_token\":\"x\"}}")
check "$(code "$R")" "not_allowed" "token field never written"
R=$(action $S fields.update "{\"postId\":$P,\"fields\":{\"jetengine:je_gallery\":[$IMG]}}")
check "$(code "$R")" "not_allowed" "gallery (not a supported type) read-only"
R=$(action $S fields.update "{\"postId\":$P,\"fields\":{\"jetengine:je_nope\":\"x\"}}")
check "$(code "$R")" "not_allowed" "unknown key refused"

R=$(api $S POST /changes/$CH/rollback)
check "$(echo "$R" | jget "d['ok']")" "True" "rollback ok"
check "$($WP $S post meta get $P je_tagline)|$($WP $S post meta get $P je_featured)|$($WP $S post meta get $P je_badge)" "Old tagline|false|" "rollback restored the fields"
api $S POST /events/site-config-changed '{"controls":null}' >/dev/null
$CTR exec ${CPFX}$S rm -f /var/www/html/wp-content/mu-plugins/jetengine-standin.php
$WP $S option delete jet_engine_meta_boxes >/dev/null
summary
