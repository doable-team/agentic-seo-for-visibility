#!/bin/bash
# T6 repeater + image (#321, ACF Pro / Secure Custom Fields): a repeater and an
# image field are listed with types and sub fields, updated by field key
# when allowlisted; a bad row is refused; rollback restores the rows.
# Site 10: Secure Custom Fields (ACF's fork with Pro fields, same API).
cd "$(dirname "$0")"; . ./lib.sh
S=${SITE:-10}
code() { echo "$1" | jget "d.get('errorCode')"; }
IMG=$(api $S POST /media '{"source_url":"http://localhost/vis-test-og.png","title":"T6 icon"}' | jget "['id']")
P=$($WP $S post create --post_type=page --post_status=publish --post_title="T6 repeater $RANDOM" --porcelain | tr -d '\r' | tail -1)
$WP $S eval "update_field('field_vis_rep',[['field_vis_rep_q'=>'Old Q1','field_vis_rep_a'=>'Old A1','field_vis_rep_img'=>'']],$P);" >/dev/null
SCH=$(api $S GET "/fields/schema?postId=$P")
check "$(echo "$SCH" | jget "dict((f['key'],(f['type'],[s['name']+':'+s['type'] for s in f.get('subFields',[])])) for f in d['fields'])['acf:field_vis_rep']")" "('repeater', ['question:text', 'answer:textarea', 'icon:image'])" "repeater listed with typed sub fields"
check "$(echo "$SCH" | jget "dict((f['key'],f['type']) for f in d['fields'])['acf:field_vis_hero_img2']")" "image" "image field listed"
api $S POST /events/site-config-changed '{"controls":{"actions":["edit_fields"],"postTypes":["page"],"lockedPostIds":[],"fieldAllowlist":["acf:field_vis_rep","acf:field_vis_hero_img2"]}}' >/dev/null
check "$(api $S GET /posts/$P | jget "dict((f['key'],f['value']) for f in d['fields'])['acf:field_vis_rep'][0]['field_vis_rep_q']")" "Old Q1" "repeater rows read"
R=$(action $S fields.update "{\"postId\":$P,\"fields\":{\"acf:field_vis_rep\":[{\"question\":\"New Q1\",\"answer\":\"New A1\",\"icon\":$IMG},{\"question\":\"New Q2\",\"answer\":\"New A2\"}],\"acf:field_vis_hero_img2\":$IMG}}")
check "$(echo "$R" | jget "['ok']")" "True" "repeater + image updated by key"
CH=$(echo "$R" | jget "d['result']['changeId']")
check "$($WP $S eval "\$r=get_field('faqs',$P); echo count(\$r).'|'.\$r[0]['question'].'|'.\$r[1]['answer'].'|'.\$r[0]['icon'];")" "2|New Q1|New A2|$IMG" "ACF reads 2 rows, sub fields and the row image"
check "$($WP $S eval "echo get_field('hero_image',$P);")" "$IMG" "image field stored"
R=$(action $S fields.update "{\"postId\":$P,\"fields\":{\"acf:field_vis_rep\":[{\"question\":\"x\",\"nope\":\"y\"}]}}")
check "$(code "$R")" "invalid" "unknown sub field refused"
R=$(action $S fields.update "{\"postId\":$P,\"fields\":{\"acf:field_vis_rep\":[{\"icon\":999999}]}}")
check "$(code "$R")" "invalid" "row image that is not an attachment refused"
api $S POST /changes/$CH/rollback >/dev/null
check "$($WP $S eval "\$r=get_field('faqs',$P); echo count(\$r).'|'.\$r[0]['question'];")" "1|Old Q1" "rollback restores the repeater rows"
api $S POST /events/site-config-changed '{"controls":null}' >/dev/null
summary
