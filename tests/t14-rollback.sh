#!/bin/bash
# T14 / #291: every write is snapshotted; rollback restores content and SEO
# (and custom fields, see t-fields.sh); rollback after a manual edit -> 409.
# SITES="1 2 4" (no plugin, Yoast, AIOSEO).
cd "$(dirname "$0")"; . ./lib.sh
for S in ${SITES:-1 2 4}; do
  echo "-- site $S"
  C='<!-- wp:paragraph -->
<p>Original text.</p>
<!-- /wp:paragraph -->'
  ID=$(printf '%s' "$C" | $WP $S post create - --post_type=page --post_status=publish --post_title="T14 original $RANDOM" --porcelain | tr -d '\r' | tail -1)
  TITLE0=$($WP $S post get $ID --field=post_title)
  action $S seo_meta.update "{\"postId\":$ID,\"fields\":{\"metaTitle\":\"Original SEO title\",\"metaDescription\":\"Original description\"}}" >/dev/null
  SEO0=$(api $S GET "/posts/$ID" | jget "list((d['seo']['values']['metaTitle'], d['seo']['values']['metaDescription']))")
  check "$SEO0" "['Original SEO title', 'Original description']" "starting SEO values"

  # 1. Content change -> changeId; rollback restores the exact content.
  R=$(action $S page.edit_parts "{\"postId\":$ID,\"edits\":[{\"target\":{\"path\":\"0\"},\"op\":\"replace_text\",\"text\":\"Agent text.\"}]}")
  CH=$(echo "$R" | jget "d['result']['changeId']")
  [ -n "$CH" ] && [ "$CH" != "None" ] && ok "edit_parts returns changeId ($CH)" || bad "no changeId: $R"
  check "$(api $S GET "/changes/$CH" | jget "d['changed']")" "['post:post_content']" "change lists what changed"
  R=$(api $S POST "/changes/$CH/rollback")
  check "$(echo "$R" | jget "['ok']")" "True" "rollback ok"
  check "$($WP $S post get $ID --field=post_content)" "$C" "content restored byte-identical"
  check "$(api $S GET "/changes/$CH" | jget "d['rolledBackAt'] is not None")" "True" "change marked rolled back"
  check "$(curl -s -o /dev/null -w '%{http_code}' -X POST -H "Authorization: Bearer vis-test-token-$S" http://localhost:818$S/wp-json/visibility/v1/changes/$CH/rollback)" "409" "second rollback refused"

  # 2. SEO + title change in two requests; roll back the SEO one.
  R=$(action $S seo_meta.update "{\"postId\":$ID,\"fields\":{\"metaTitle\":\"Agent SEO title\",\"metaDescription\":\"Agent description\"}}")
  CH2=$(echo "$R" | jget "d['result']['changeId']")
  check "$(api $S GET "/posts/$ID" | jget "d['seo']['values']['metaTitle']")" "Agent SEO title" "SEO changed"
  R=$(action $S change.rollback "{\"changeId\":$CH2}")
  check "$(echo "$R" | jget "['ok']")" "True" "change.rollback action ok"
  check "$(api $S GET "/posts/$ID" | jget "list((d['seo']['values']['metaTitle'], d['seo']['values']['metaDescription']))")" "$SEO0" "SEO values restored"
  PAGE=$(curl -sL "http://localhost:818$S/?page_id=$ID")
  grep -q 'Original description' <<<"$PAGE" && ok "front end shows the restored description" || bad "front end description not restored"
  grep -q 'Agent description' <<<"$PAGE" && bad "old agent description still on page" || ok "agent description gone from page"

  # 3. Title change, then a manual edit in WP -> rollback refused (409), nothing lost.
  R=$(api $S PATCH "/posts/$ID" '{"title":"T14 agent title"}')
  CH3=$(echo "$R" | jget "['changeId']")
  sleep 1
  $WP $S post update $ID --post_content='<!-- wp:paragraph -->
<p>Owner edit.</p>
<!-- /wp:paragraph -->' >/dev/null
  CODE=$(curl -s -o /tmp/rb.json -w '%{http_code}' -X POST -H "Authorization: Bearer vis-test-token-$S" http://localhost:818$S/wp-json/visibility/v1/changes/$CH3/rollback)
  check "$CODE" "409" "rollback after a manual edit -> 409"
  check "$(jget "['code']" </tmp/rb.json)" "visibility_conflict" "code visibility_conflict"
  check "$($WP $S post get $ID --field=post_title)" "T14 agent title" "title untouched by the refused rollback"
  case "$($WP $S post get $ID --field=post_content)" in *"Owner edit."*) ok "owner's edit kept";; *) bad "owner's edit lost";; esac

  # 4. Draft created by Visibility: rollback trashes it.
  R=$(action $S post.create_draft '{"title":"T14 new draft"}')
  NID=$(echo "$R" | jget "d['result']['postId']"); CH4=$(echo "$R" | jget "d['result']['changeId']")
  api $S POST "/changes/$CH4/rollback" >/dev/null
  check "$($WP $S post get $NID --field=post_status)" "trash" "rolling back a created draft trashes it"
  check "$(api $S GET "/changes?postId=$ID" | jget "len(d['items'])>=4")" "True" "GET /changes?postId lists the page's changes"

  # 5. SEO change, then another change to the same post right away (same
  #    second, other items): rolling back the SEO change is refused too.
  P5=$($WP $S post create --post_type=page --post_status=publish --post_title="T14 later $RANDOM" --post_content='<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->' --porcelain | tr -d '\r' | tail -1)
  CH5=$(action $S seo_meta.update "{\"postId\":$P5,\"fields\":{\"metaDescription\":\"first\"}}" | jget "d['result']['changeId']")
  action $S page.edit_parts "{\"postId\":$P5,\"edits\":[{\"target\":{\"path\":\"0\"},\"op\":\"replace_text\",\"text\":\"later\"}]}" >/dev/null
  check "$(curl -s -o /dev/null -w '%{http_code}' -X POST -H "Authorization: Bearer vis-test-token-$S" http://localhost:818$S/wp-json/visibility/v1/changes/$CH5/rollback)" "409" "rollback refused after a later change to other parts of the post"
done
summary
