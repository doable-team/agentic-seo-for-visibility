#!/bin/bash
# C-43 Carbon Fields (real library from github.com/htmlburger/carbon-fields
# v3.6.11, loaded by fixtures/vis-carbon-fixture.php with a post meta
# container for pages): fields listed with types and choices, only for the
# post types the container is shown on, refused until allowlisted, written
# through carbon_set_post_meta (read back with carbon_get_post_meta), bad
# values / complex fields / token-like names refused, rollback restores them.
cd "$(dirname "$0")"; . ./lib.sh
S=${SITE:-1}
code() { echo "$1" | jget "d.get('errorCode')"; }
$CTR exec ${CPFX}$S test -d /var/www/html/wp-content/carbon-fields || $CTR cp ${CF_DIR:-/tmp/wpsrc/carbon-fields-3.6.11} ${CPFX}$S:/var/www/html/wp-content/carbon-fields
$CTR cp fixtures/vis-carbon-fixture.php ${CPFX}$S:/var/www/html/wp-content/mu-plugins/vis-carbon-fixture.php
$CTR exec ${CPFX}$S chown -R www-data:www-data /var/www/html/wp-content
IMG=$(api $S POST /media '{"source_url":"http://localhost/vis-test-og.png","title":"Carbon badge"}' | jget "['id']")
P=$($WP $S post create --post_type=page --post_status=publish --post_title="Carbon $RANDOM" --porcelain | tr -d '\r' | tail -1)
$WP $S eval "carbon_set_post_meta($P, 'crb_tagline', 'Old tagline');" >/dev/null
api $S POST /events/site-config-changed '{"controls":null}' >/dev/null

C=$(api $S GET /capabilities)
check "$(echo "$C" | jget "(list((f['slug'],f['writable']) for f in d['customFields'] if f['slug']=='carbon-fields'), 'fields.carbon' in d['features'])")" "([('carbon-fields', True)], True)" "capabilities: Carbon Fields, fields.carbon"
SCH=$(api $S GET "/fields/schema?type=page")
check "$(echo "$SCH" | jget "list((f['key'],f['type']) for f in d['fields'] if f['source']=='carbon')")" "[('carbon:crb_tagline', 'text'), ('carbon:crb_intro', 'textarea'), ('carbon:crb_level', 'select'), ('carbon:crb_featured', 'boolean'), ('carbon:crb_badge', 'image'), ('carbon:crb_rows', 'complex'), ('carbon:crb_api_token', 'text')]" "schema: page container fields typed"
check "$(echo "$SCH" | jget "next(f for f in d['fields'] if f['key']=='carbon:crb_level')['choices']")" "['basic', 'pro']" "schema: select choices"
check "$(api $S GET "/fields/schema?type=post" | jget "list(f['key'] for f in d['fields'] if f['source']=='carbon')")" "['carbon:crb_post_only']" "schema: post type of each container respected"
R=$(action $S fields.update "{\"postId\":$P,\"fields\":{\"carbon:crb_tagline\":\"x\"}}")
check "$(code "$R")" "not_allowed" "no allowlist: refused"
ALLOW='["carbon:crb_tagline","carbon:crb_intro","carbon:crb_level","carbon:crb_featured","carbon:crb_badge","carbon:crb_rows","carbon:crb_api_token"]'
api $S POST /events/site-config-changed "{\"controls\":{\"actions\":[\"create_drafts\",\"edit_meta\",\"images\",\"edit_fields\",\"edit_content\"],\"postTypes\":[\"post\",\"page\"],\"lockedPostIds\":[],\"fieldAllowlist\":$ALLOW}}" >/dev/null
check "$(api $S GET /posts/$P | jget "dict((f['key'],f['value']) for f in d['fields'])['carbon:crb_tagline']")" "Old tagline" "read: value through carbon_get_post_meta"
R=$(action $S fields.update "{\"postId\":$P,\"fields\":{\"carbon:crb_tagline\":\"New tagline\",\"carbon:crb_level\":\"pro\",\"carbon:crb_featured\":true,\"carbon:crb_badge\":$IMG,\"carbon:crb_intro\":\"Line 1\\nLine 2\"}}")
check "$(echo "$R" | jget "d['ok']")" "True" "fields.update ok"
CH=$(echo "$R" | jget "d['result']['changeId']")
check "$($WP $S eval "echo carbon_get_post_meta($P,'crb_tagline').'|'.carbon_get_post_meta($P,'crb_level').'|'.var_export(carbon_get_post_meta($P,'crb_featured'),true).'|'.carbon_get_post_meta($P,'crb_badge');" | tr -d '\r')" "New tagline|pro|true|$IMG" "Carbon Fields reads the new values back"
check "$($WP $S post meta get $P _crb_featured | tr -d '\r')" "yes" "stored as Carbon Fields stores a checkbox (_crb_featured = yes)"
check "$(echo "$R" | jget "d['result']['after']['carbon:crb_badge']['id']")" "$IMG" "after: image as {id,url,alt}"
check "$(code "$(action $S fields.update "{\"postId\":$P,\"fields\":{\"carbon:crb_level\":\"gold\"}}")")" "invalid" "choice outside the list refused"
check "$(code "$(action $S fields.update "{\"postId\":$P,\"fields\":{\"carbon:crb_rows\":[{\"label\":\"a\"}]}}")")" "not_allowed" "complex field: read-only"
check "$(code "$(action $S fields.update "{\"postId\":$P,\"fields\":{\"carbon:crb_api_token\":\"x\"}}")")" "not_allowed" "token-like field never written"
check "$(code "$(action $S fields.update "{\"postId\":$P,\"fields\":{\"carbon:crb_badge\":999999}}")")" "invalid" "image must be a library image"
R=$(api $S POST /changes/$CH/rollback)
check "$(echo "$R" | jget "d['ok']")" "True" "rollback"
# Raw meta (Carbon returns a select's first choice when it has no value).
check "$($WP $S eval "echo get_post_meta($P,'_crb_tagline',true).'|'.var_export(metadata_exists('post',$P,'_crb_level'),true).'|'.var_export(metadata_exists('post',$P,'_crb_featured'),true);" | tr -d '\r')" "Old tagline|false|false" "values restored (raw meta as before)"
api $S POST /events/site-config-changed '{"controls":null}' >/dev/null
summary
