#!/bin/bash
# #324 Astra, #325 GeneratePress, #326 Kadence, #327 OceanWP (part of #288):
# with the theme active its page options are listed (theme:<theme>:<key>),
# writes are refused until the owner allowlists them, then a layout/title
# option is written, shows on the front end, and rollback restores it.
# THEMES="astra generatepress kadence oceanwp" on site 11 (only the themes installed).
cd "$(dirname "$0")"; . ./lib.sh
S=${SITE:-11}; U="http://localhost:$((8180+S))"
code() { echo "$1" | jget "d.get('errorCode')"; }
# theme  meta key  value  front-end check (grep -E on the page, must match after the write)
declare -A KEY VAL SEE
KEY[astra]=site-post-title;                VAL[astra]=disabled;   SEE[astra]='<h1 class="entry-title"'
KEY[generatepress]=_generate-disable-headline; VAL[generatepress]=true; SEE[generatepress]='<h1 class="entry-title"'
KEY[kadence]=_kad_post_title;              VAL[kadence]=hide;     SEE[kadence]='<h1 class="entry-title"'
KEY[oceanwp]=ocean_post_layout;            VAL[oceanwp]=left-sidebar; SEE[oceanwp]='content-left-sidebar|left-sidebar'
for T in ${THEMES:-astra}; do
  $WP $S theme activate $T >/dev/null 2>&1
  L="$T: "; K="theme:$T:${KEY[$T]}"
  P=$($WP $S post create --post_type=page --post_status=publish --post_title="Theme opt $T $RANDOM" --post_content='<p>Body</p>' --porcelain | tr -d '\r' | tail -1)
  api $S POST /events/site-config-changed '{"controls":null}' >/dev/null
  SCH=$(api $S GET "/fields/schema?type=page")
  check "$(echo "$SCH" | jget "len(list(f for f in d['fields'] if f['source']=='$T'))>0")" "True" "${L}page options listed"
  check "$(echo "$SCH" | jget "list(f['writable'] for f in d['fields'] if f['key']=='$K')")" "[False]" "${L}$K listed, not writable"
  check "$(api $S GET /capabilities | jget "'$T' in [c['slug'] for c in d['customFields']]")" "True" "${L}capabilities lists the theme options"
  R=$(action $S fields.update "{\"postId\":$P,\"fields\":{\"$K\":\"${VAL[$T]}\"}}")
  check "$(code "$R")" "not_allowed" "${L}write refused until the owner allows it"
  BEFORE=$(curl -sL "$U/?page_id=$P")
  api $S POST /events/site-config-changed "{\"controls\":{\"actions\":[\"edit_fields\"],\"postTypes\":[\"page\"],\"lockedPostIds\":[],\"fieldAllowlist\":[\"$K\"]}}" >/dev/null
  R=$(action $S fields.update "{\"postId\":$P,\"fields\":{\"$K\":\"not-a-choice\"}}")
  check "$(code "$R")" "invalid" "${L}value outside the theme's choices refused"
  R=$(action $S fields.update "{\"postId\":$P,\"fields\":{\"$K\":\"${VAL[$T]}\"}}")
  check "$(echo "$R" | jget "['ok']")" "True" "${L}allowed: $K = ${VAL[$T]}"
  CH=$(echo "$R" | jget "d['result']['changeId']")
  check "$($WP $S post meta get $P ${KEY[$T]})" "${VAL[$T]}" "${L}stored in ${KEY[$T]}"
  AFTER=$(curl -sL "$U/?page_id=$P")
  [ "$(grep -cE "${SEE[$T]}" <<<"$BEFORE")" != "$(grep -cE "${SEE[$T]}" <<<"$AFTER")" ] && ok "${L}front end changed (${SEE[$T]})" || bad "${L}front end did not change"
  api $S POST /changes/$CH/rollback >/dev/null
  check "$($WP $S post meta get $P ${KEY[$T]} 2>/dev/null)" "" "${L}rollback removed the option"
  check "$(curl -sL "$U/?page_id=$P" | grep -cE "${SEE[$T]}")" "$(grep -cE "${SEE[$T]}" <<<"$BEFORE")" "${L}front end back to before"
done
api $S POST /events/site-config-changed '{"controls":null}' >/dev/null
$WP $S theme activate twentytwentyfive >/dev/null
summary
