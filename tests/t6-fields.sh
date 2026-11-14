#!/bin/bash
# T6 / #288 / #321: custom fields are listed with types, read, and updated by
# key only when the owner allowlisted them; a field outside the allowlist,
# a denied key and a bad value are refused; rollback restores them.
# Site 7: ACF (free) + The SEO Framework + Meta Box + Pods, fixture mu-plugin
# vis-fields-fixture.php (ACF group "Hero", Meta Box "Service", registered meta).
cd "$(dirname "$0")"; . ./lib.sh
S=${SITE:-7}
docker cp -q /home/tulasinath/viswp/mu/vis-fields-fixture.php viswp-$S:/var/www/html/wp-content/mu-plugins/
IMG=$(api $S POST /media '{"source_url":"http://localhost/vis-test-og.png","title":"T6 hero"}' | jget "['id']")
P=$($WP $S post create --post_type=page --post_status=publish --post_title="T6 fields $RANDOM" --porcelain | tr -d '\r' | tail -1)
$WP $S eval "update_field('field_vis_hero_title','Old hero',$P); update_field('field_vis_hero_style','light',$P); update_field('field_vis_faq',['field_vis_faq_q'=>'Old Q','field_vis_faq_a'=>'Old A'],$P);" >/dev/null
code() { echo "$1" | jget "d.get('errorCode')"; }
SRC=${SOURCES:-acf}

# Schema with types.
SCH=$(api $S GET "/fields/schema?type=page")
check "$(echo "$SCH" | jget "dict((f['key'],f['type']) for f in d['fields'] if f['source']=='acf')['acf:field_vis_hero_image']")" "image" "schema: ACF image field typed"
check "$(echo "$SCH" | jget "dict((f['key'],f['choices']) for f in d['fields'] if 'choices' in f)['acf:field_vis_hero_style']")" "['light', 'dark']" "schema: select choices"
check "$(echo "$SCH" | jget "dict((f['key'],[s['name'] for s in f.get('subFields',[])]) for f in d['fields'])['acf:field_vis_faq']")" "['question', 'answer']" "schema: group sub fields"
check "$(echo "$SCH" | jget "any(f['writable'] for f in d['fields'])")" "False" "nothing writable without an allowlist"

# No controls -> field writes refused (strict).
api $S POST /events/site-config-changed '{"controls":null}' >/dev/null
R=$(action $S fields.update "{\"postId\":$P,\"fields\":{\"acf:field_vis_hero_title\":\"x\"}}")
check "$(code "$R")" "not_allowed" "no allowlist: field write refused"

ALLOW='["acf:field_vis_hero_title","acf:field_vis_hero_body","acf:field_vis_hero_link","acf:field_vis_hero_image","acf:field_vis_hero_count","acf:field_vis_hero_style","acf:field_vis_hero_show","acf:field_vis_faq","acf:field_vis_api_token","metabox:service_tagline","metabox:service_level","metabox:service_badge","pods:subtitle_pods","pods:featured_pods","meta:vis_subtitle"]'
api $S POST /events/site-config-changed "{\"controls\":{\"actions\":[\"create_drafts\",\"edit_meta\",\"images\",\"edit_fields\"],\"postTypes\":[\"post\",\"page\"],\"lockedPostIds\":[],\"fieldAllowlist\":$ALLOW}}" >/dev/null
check "$(api $S GET "/fields/schema?type=page" | jget "dict((f['key'],f['writable']) for f in d['fields'])['acf:field_vis_hero_title']")" "True" "allowlisted field writable in the schema"
check "$(api $S GET /posts/$P | jget "dict((f['key'],f['value']) for f in d['fields'])['acf:field_vis_hero_title']")" "Old hero" "read: current value in GET /posts fields"

R=$(action $S fields.update "{\"postId\":$P,\"fields\":{\"acf:field_vis_hero_title\":\"New hero\",\"acf:field_vis_hero_image\":$IMG,\"acf:field_vis_hero_count\":\"7\",\"acf:field_vis_hero_style\":\"dark\",\"acf:field_vis_hero_show\":true,\"acf:field_vis_hero_body\":\"<p>Body <script>x</script></p>\",\"acf:field_vis_faq\":{\"question\":\"New Q\",\"answer\":\"New A\"}}}")
check "$(echo "$R" | jget "['ok']")" "True" "fields.update ok"
CH=$(echo "$R" | jget "d['result']['changeId']")
check "$(echo "$R" | jget "d['result']['before']['acf:field_vis_hero_title']")" "Old hero" "result: before"
check "$(echo "$R" | jget "d['result']['after']['acf:field_vis_hero_image']['id']")" "$IMG" "result: after image = attachment"
check "$($WP $S eval "echo get_field('hero_title',$P);")" "New hero" "ACF reads the new text"
check "$($WP $S eval "echo get_field('hero_count',$P);")" "7" "number stored"
check "$($WP $S eval "echo get_field('hero_style',$P);")" "dark" "select stored"
check "$($WP $S eval "echo get_field('hero_image',$P);")" "$IMG" "image stored by id"
check "$($WP $S eval "echo get_field('faq',$P)['question'];")" "New Q" "group sub field stored"
check "$($WP $S eval "echo get_post_meta($P,'_hero_title',true);")" "field_vis_hero_title" "ACF field-key reference meta written (ACF can find the field)"
case "$($WP $S eval "echo get_field('hero_body',$P,false);")" in *script*) bad "wysiwyg not sanitised";; *) ok "wysiwyg sanitised";; esac

# Rollback restores the ACF values (T14 part), then apply the change again.
R=$(api $S POST /changes/$CH/rollback)
check "$(echo "$R" | jget "['ok']")" "True" "rollback of the ACF change ok"
check "$($WP $S eval "echo get_field('hero_title',$P).'|'.get_field('hero_style',$P).'|'.get_field('faq',$P)['question'];")" "Old hero|light|Old Q" "rollback restored ACF text, select and group"
check "$($WP $S eval "var_export(get_field('hero_image',$P));")" "NULL" "rollback removed the image the change added"
R=$(action $S fields.update "{\"postId\":$P,\"fields\":{\"acf:field_vis_hero_title\":\"New hero\"}}")
check "$(echo "$R" | jget "['ok']")" "True" "re-applied after rollback"

# Refusals.
R=$(action $S fields.update "{\"postId\":$P,\"fields\":{\"acf:field_vis_hero_text\":\"x\"}}")
check "$(code "$R")" "not_allowed" "field outside the allowlist refused"
R=$(action $S fields.update "{\"postId\":$P,\"fields\":{\"acf:field_vis_api_token\":\"x\"}}")
check "$(code "$R")" "not_allowed" "allowlisted but denied key (token) refused"
R=$(action $S fields.update "{\"postId\":$P,\"fields\":{\"acf:field_vis_hero_style\":\"purple\"}}")
check "$(code "$R")" "invalid" "bad select value refused"
R=$(action $S fields.update "{\"postId\":$P,\"fields\":{\"acf:field_vis_hero_link\":\"not a url\",\"acf:field_vis_hero_title\":\"partial\"}}")
check "$(code "$R")" "invalid" "bad URL refused"
check "$($WP $S eval "echo get_field('hero_title',$P);")" "New hero" "nothing written when one value is bad"
R=$(action $S fields.update "{\"postId\":$P,\"fields\":{\"meta:_wp_page_template\":\"x\"}}")
check "$(code "$R")" "not_allowed" "arbitrary meta key refused"
check "$(curl -s -o /dev/null -w '%{http_code}' -X POST -H "Authorization: Bearer vis-test-token-$S" -H 'Content-Type: application/json' --data '{"type":"page"}' http://localhost:818$S/wp-json/visibility/v1/fields/schema)" "404" "no write route besides the action"

if [[ " $SRC " == *" metabox "* ]]; then
  R=$(action $S fields.update "{\"postId\":$P,\"fields\":{\"metabox:service_tagline\":\"Fast and friendly\",\"metabox:service_level\":\"pro\",\"metabox:service_badge\":$IMG}}")
  check "$(echo "$R" | jget "['ok']")" "True" "Meta Box fields.update ok"
  check "$($WP $S eval "echo rwmb_meta('service_tagline',[],$P);")" "Fast and friendly" "Meta Box text via rwmb_meta"
  check "$($WP $S eval "echo rwmb_meta('service_level',[],$P);")" "pro" "Meta Box select"
  check "$($WP $S eval "echo rwmb_meta('service_badge',[],$P)['ID'];")" "$IMG" "Meta Box single_image"
  R=$(action $S fields.update "{\"postId\":$P,\"fields\":{\"metabox:service_price_note\":\"x\"}}")
  check "$(code "$R")" "not_allowed" "Meta Box field outside the allowlist refused"
  R=$(action $S fields.update "{\"postId\":$P,\"fields\":{\"metabox:service_level\":\"gold\"}}")
  check "$(code "$R")" "invalid" "Meta Box bad choice refused"
fi
if [[ " $SRC " == *" pods "* ]]; then
  R=$(action $S fields.update "{\"postId\":$P,\"fields\":{\"pods:subtitle_pods\":\"From Pods\",\"pods:featured_pods\":true}}")
  check "$(echo "$R" | jget "['ok']")" "True" "Pods fields.update ok"
  check "$($WP $S eval "echo pods('page',$P)->field('subtitle_pods');")" "From Pods" "Pods text via pods()->field"
  check "$($WP $S eval "echo (int) pods('page',$P)->field('featured_pods');")" "1" "Pods boolean"
  R=$(action $S fields.update "{\"postId\":$P,\"fields\":{\"pods:intro_pods\":\"x\"}}")
  check "$(code "$R")" "not_allowed" "Pods field outside the allowlist refused"
  R=$(action $S fields.update "{\"postId\":$P,\"fields\":{\"meta:vis_subtitle\":\"Registered meta\"}}")
  check "$($WP $S post meta get $P vis_subtitle)" "Registered meta" "native registered meta written"
fi

api $S POST /events/site-config-changed '{"controls":null}' >/dev/null
summary
