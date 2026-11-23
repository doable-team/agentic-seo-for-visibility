#!/bin/bash
# 2.1.0 post meta inventory + meta.update.
# GET /posts/{id}?include=meta lists every meta key (registered or not):
# secrets / housekeeping / Visibility's own keys left out, builder data masked,
# SEO plugins' keys read-only, long values cut at 4 KB, arrays JSON-safe.
# meta.update: refused without controls / without the key allowlisted, never
# for denied keys, type-checked for registered keys, no new unregistered key
# unless named exactly, "any post meta" mode, fields.update meta:<protected>,
# rollback.
cd "$(dirname "$0")"; . ./lib.sh
S=${SITE:-1}
code() { echo "$1" | jget "d.get('errorCode')"; }
cat > /tmp/vis-meta-fixture.php <<'PHP'
<?php
// Test fixture: one registered protected integer key and one registered array key.
add_action('init', function () {
  register_post_meta('page', '_vis_t_count', ['type' => 'integer', 'single' => true, 'show_in_rest' => false]);
  register_post_meta('page', 'vis_t_list', ['type' => 'array', 'single' => true, 'show_in_rest' => ['schema' => ['type' => 'array', 'items' => ['type' => 'string']]]]);
  register_post_meta('page', '_vis_t_flag', ['type' => 'boolean', 'single' => true, 'show_in_rest' => true]);
});
PHP
$CTR cp /tmp/vis-meta-fixture.php ${CPFX}$S:/var/www/html/wp-content/mu-plugins/vis-meta-fixture.php
$CTR exec ${CPFX}$S chown -R www-data:www-data /var/www/html/wp-content/mu-plugins
api $S POST /events/site-config-changed '{"controls":null,"allowPublish":true,"disabled":false}' >/dev/null

P=$($WP $S post create --post_type=page --post_status=publish --post_title="Meta $RANDOM" --porcelain | tr -d '\r' | tail -1)
$WP $S eval "
update_post_meta($P, '_my_plugin_note', 'Hello note');
update_post_meta($P, 'my_api_token', 'SECRET-1');
update_post_meta($P, '_stripe_secret_x', 'SECRET-2');
update_post_meta($P, '_user_session_id', 'SECRET-3');
update_post_meta($P, '_edit_last', '1');
update_post_meta($P, '_wp_old_slug', 'old');
update_post_meta($P, '_visibility_created', '1');
update_post_meta($P, '_elementor_data', wp_slash(json_encode([['id'=>'a','elType'=>'section']])));
update_post_meta($P, '_et_pb_use_builder', 'off');
update_post_meta($P, '_yoast_wpseo_title', 'Yoast T');
update_post_meta($P, 'rank_math_title', 'RM T');
update_post_meta($P, '_vis_t_count', 3);
update_post_meta($P, 'vis_t_list', ['a','b']);
update_post_meta($P, 'big_value', str_repeat('x', 6000));
update_post_meta($P, 'obj_value', ['k' => ['n' => 1], 'o' => new ArrayObject([1])]);
add_post_meta($P, 'multi_value', 'one'); add_post_meta($P, 'multi_value', 'two');
" >/dev/null

M=$(api $S GET "/posts/$P?include=meta")
q() { echo "$M" | jget "$1"; }
keys="{m['key']:m for m in d['meta']}"
check "$(q "list(k for k in ['my_api_token','_stripe_secret_x','_user_session_id','_edit_last','_wp_old_slug','_visibility_created'] if k in $keys)")" "[]" "inventory: secrets, housekeeping and Visibility keys not listed"
check "$(q "$keys['_my_plugin_note']['value'], $keys['_my_plugin_note']['protected'], $keys['_my_plugin_note']['registered'], $keys['_my_plugin_note']['writable']")" "('Hello note', True, False, False)" "inventory: protected unregistered key listed, not writable without permission"
check "$(q "$keys['_elementor_data']['value'], $keys['_elementor_data']['writable']")" "('(page builder data, 31 chars)', False)" "inventory: page builder data masked, read-only"
check "$(q "$keys['_et_pb_use_builder']['writable']")" "False" "inventory: Divi key read-only"
check "$(q "$keys['_yoast_wpseo_title']['value'], $keys['_yoast_wpseo_title']['writable'], $keys['_yoast_wpseo_title'].get('via')")" "('Yoast T', False, 'seo_meta.update')" "inventory: SEO plugin key readable, read-only"
check "$(q "$keys['rank_math_title']['writable']")" "False" "inventory: rank_math key read-only"
check "$(q "$keys['_vis_t_count']['value'], $keys['_vis_t_count']['type'], $keys['_vis_t_count']['registered'], $keys['_vis_t_count']['showInRest']")" "(3, 'integer', True, False)" "inventory: registered int (not in REST) with type"
check "$(q "$keys['vis_t_list']['value'], $keys['vis_t_list']['type']")" "(['a', 'b'], 'array')" "inventory: array value JSON-safe"
check "$(q "len($keys['big_value']['value']), $keys['big_value'].get('truncated')")" "(4096, True)" "inventory: long value cut at 4 KB with truncated:true"
check "$(q "$keys['obj_value']['type'], $keys['obj_value']['value']['k']")" "('object', {'n': 1})" "inventory: object JSON-safe (no PHP objects built)"
check "$(q "$keys['multi_value']['value'], $keys['multi_value'].get('multiple'), $keys['multi_value']['writable']")" "(['one', 'two'], True, False)" "inventory: several values listed as a list"
check "$(q "$keys['_vis_t_flag']['exists'], $keys['_vis_t_flag']['value']")" "(False, None)" "inventory: registered key without a value listed with exists:false"
check "$(api $S GET "/posts/$P" | jget "'meta' in d and 'fields' in d")" "True" "default full read includes meta"

# Writes
R=$(action $S meta.update "{\"postId\":$P,\"meta\":{\"_my_plugin_note\":\"x\"}}")
check "$(code "$R")" "not_allowed" "no controls: refused"
CTL='"actions":["edit_fields"],"postTypes":["page"],"lockedPostIds":[]'
api $S POST /events/site-config-changed "{\"controls\":{$CTL,\"fieldAllowlist\":[\"meta:_vis_t_count\"]}}" >/dev/null
R=$(action $S meta.update "{\"postId\":$P,\"meta\":{\"_my_plugin_note\":\"x\"}}")
check "$(code "$R")" "not_allowed" "key not in allowlist: refused"
check "$(api $S GET "/posts/$P?include=meta" | jget "list(m['writable'] for m in d['meta'] if m['key']=='_vis_t_count')[0]")" "True" "inventory: allowlisted key writable"
R=$(action $S meta.update "{\"postId\":$P,\"meta\":{\"_vis_t_count\":\"seven\"}}")
check "$(code "$R")" "invalid" "registered integer: text refused"
R=$(action $S meta.update "{\"postId\":$P,\"meta\":{\"_vis_t_count\":7}}")
check "$(echo "$R" | jget "(d['ok'], d['result']['meta'], d['result']['before']['_vis_t_count'], d['result']['after']['_vis_t_count'])")" "(True, ['_vis_t_count'], 3, 7)" "allowlisted protected meta written, before/after"
CH=$(echo "$R" | jget "d['result']['changeId']")
check "$($WP $S post meta get $P _vis_t_count | tr -d '\r')" "7" "stored"

api $S POST /events/site-config-changed "{\"controls\":{$CTL,\"fieldAllowlist\":[\"meta:my_api_token\",\"meta:_yoast_wpseo_title\",\"meta:_elementor_data\",\"meta:_edit_last\",\"meta:brand_new\",\"meta:vis_t_list\"]}}" >/dev/null
for k in my_api_token _yoast_wpseo_title _elementor_data _edit_last; do
  R=$(action $S meta.update "{\"postId\":$P,\"meta\":{\"$k\":\"x\"}}")
  check "$(code "$R")" "not_allowed" "denied even when allowlisted: $k"
done
check "$($WP $S post meta get $P _yoast_wpseo_title | tr -d '\r')" "Yoast T" "SEO key untouched"
R=$(action $S meta.update "{\"postId\":$P,\"meta\":{\"vis_t_list\":[\"c\",3]}}")
check "$(code "$R")" "invalid" "registered array: item of wrong type refused (REST schema)"
R=$(action $S meta.update "{\"postId\":$P,\"meta\":{\"vis_t_list\":[\"c\",\"<b>d</b><script>x</script>\"]}}")
check "$(echo "$R" | jget "d['result']['after']['vis_t_list']")" "['c', '<b>d</b>x']" "registered array written, HTML sanitised"
R=$(action $S meta.update "{\"postId\":$P,\"meta\":{\"brand_new\":\"made\"}}")
check "$(echo "$R" | jget "d['ok']")" "True" "new unregistered key created when named exactly"

# "any post meta" mode
api $S POST /events/site-config-changed "{\"controls\":{$CTL,\"fieldAllowlist\":[],\"anyPostMeta\":true}}" >/dev/null
check "$(api $S GET /capabilities | jget "d['permissions']['controls']['anyPostMeta']")" "True" "capabilities: anyPostMeta reported"
R=$(action $S meta.update "{\"postId\":$P,\"meta\":{\"_my_plugin_note\":\"Changed <em>note</em>\"}}")
check "$(echo "$R" | jget "(d['ok'], d['result']['after']['_my_plugin_note'])")" "(True, 'Changed <em>note</em>')" "any-meta mode: existing protected key written (HTML kept, kses)"
R=$(action $S meta.update "{\"postId\":$P,\"meta\":{\"never_seen\":\"x\"}}")
check "$(code "$R")" "not_found" "any-meta mode: new unregistered key refused"
check "$($WP $S post meta get $P never_seen | tr -d '\r')" "" "nothing created"
R=$(action $S meta.update "{\"postId\":$P,\"meta\":{\"_my_plugin_note\":\"ok\",\"my_api_token\":\"x\"}}")
check "$(code "$R")|$($WP $S post meta get $P _my_plugin_note | tr -d '\r')" "not_allowed|Changed <em>note</em>" "all or nothing"
R=$(action $S meta.update "{\"postId\":$P,\"meta\":{\"_vis_t_flag\":\"maybe\"}}")
check "$(code "$R")" "invalid" "registered boolean: bad value refused"
R=$(action $S meta.update "{\"postId\":$P,\"meta\":{\"_vis_t_flag\":true}}")
check "$(echo "$R" | jget "d['ok']")" "True" "any-meta mode: registered key without a value written"
R=$(action $S meta.update "{\"postId\":$P,\"meta\":{\"multi_value\":\"x\"}}")
check "$(code "$R")" "unsupported" "key with several values refused"
R=$(action $S meta.update "{\"postId\":$P,\"modifiedGmt\":\"2001-01-01T00:00:00Z\",\"meta\":{\"_my_plugin_note\":\"x\"}}")
check "$(code "$R")" "conflict" "stale modifiedGmt refused"
# fields.update with meta:<protected key> goes through the same rules.
R=$(action $S fields.update "{\"postId\":$P,\"fields\":{\"meta:_my_plugin_note\":\"Via fields\"}}")
check "$(echo "$R" | jget "(d['ok'], d['result']['after']['meta:_my_plugin_note'])")" "(True, 'Via fields')" "fields.update meta:<protected key> works in any-meta mode"
R=$(action $S fields.update "{\"postId\":$P,\"fields\":{\"meta:never_seen\":\"x\"}}")
check "$(code "$R")" "not_found" "fields.update never creates keys"
R2=$(action $S meta.update "{\"postId\":$P,\"meta\":{\"_my_plugin_note\":\"Last\"}}")
CH2=$(echo "$R2" | jget "d['result']['changeId']")
R=$(api $S POST /changes/$CH2/rollback)
check "$(echo "$R" | jget "d['ok']")|$($WP $S post meta get $P _my_plugin_note | tr -d '\r')" "True|Via fields" "rollback restores the meta"
# edit_fields off
api $S POST /events/site-config-changed "{\"controls\":{\"actions\":[\"edit_meta\"],\"postTypes\":[\"page\"],\"lockedPostIds\":[],\"fieldAllowlist\":[],\"anyPostMeta\":true}}" >/dev/null
R=$(action $S meta.update "{\"postId\":$P,\"meta\":{\"_my_plugin_note\":\"x\"}}")
check "$(code "$R")" "not_allowed" "edit_fields switch off: refused"
api $S POST /events/site-config-changed "{\"controls\":{$CTL,\"fieldAllowlist\":[],\"anyPostMeta\":true,\"lockedPostIds\":[$P]}}" >/dev/null
R=$(action $S meta.update "{\"postId\":$P,\"meta\":{\"_my_plugin_note\":\"x\"}}")
check "$(code "$R")" "locked" "locked page refused"
api $S POST /events/site-config-changed '{"controls":null}' >/dev/null
$CTR exec ${CPFX}$S rm -f /var/www/html/wp-content/mu-plugins/vis-meta-fixture.php
summary
