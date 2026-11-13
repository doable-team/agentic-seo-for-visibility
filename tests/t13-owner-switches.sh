#!/bin/bash
# T13 / #273: the plugin enforces "publishing off" and "paused" itself.
cd "$(dirname "$0")"; . ./lib.sh
S=1
D=$($WP $S post create --post_status=draft --post_title="T13 draft $RANDOM" --porcelain | tr -d '\r')
status() { $WP $S post get $D --field=post_status; }

# Never set -> allowed (as before).
$WP $S option delete visibility_allow_publish >/dev/null 2>&1; $WP $S option delete visibility_disabled >/dev/null 2>&1
R=$(action $S post.create_draft '{"title":"T13 default ok"}'); check "$(echo "$R" | jget "['ok']")" "True" "flags never set: writes allowed"

# Publishing off via the server's push.
api $S POST /events/site-config-changed '{"allowPublish":false}' >/dev/null
R=$(action $S post.publish "{\"postId\":$D}")
check "$(echo "$R" | jget "['ok']")" "False" "publishing off: post.publish refused"
check "$(echo "$R" | jget "['errorCode']")" "publishing_off" "errorCode publishing_off"
check "$(status)" "draft" "post still a draft"
R=$(action $S post.schedule "{\"postId\":$D,\"publishAt\":\"2030-01-01T00:00:00Z\"}"); check "$(echo "$R" | jget "['ok']")" "False" "publishing off: post.schedule refused"
R=$(action $S post.delete_permanent "{\"postId\":$D}"); check "$(echo "$R" | jget "['ok']")" "False" "publishing off: delete refused"
CODE=$(curl -s -o /dev/null -w '%{http_code}' -X PATCH -H "Authorization: Bearer vis-test-token-$S" -H 'Content-Type: application/json' --data '{"status":"publish"}' http://localhost:8091/wp-json/visibility/v1/posts/$D)
check "$CODE" "403" "publishing off: PATCH status=publish -> 403"
check "$(status)" "draft" "post still a draft after PATCH"
CODE=$(curl -s -o /dev/null -w '%{http_code}' -X DELETE -H "Authorization: Bearer vis-test-token-$S" http://localhost:8091/wp-json/visibility/v1/posts/$D)
check "$CODE" "403" "publishing off: DELETE -> 403"
R=$(action $S post.update_content "{\"postId\":$D,\"fields\":{\"title\":\"T13 edited\"}}"); check "$(echo "$R" | jget "['ok']")" "True" "publishing off: draft edits still allowed"

# Paused: every write refused, reads still work.
api $S POST /events/site-config-changed '{"allowPublish":true,"disabled":true}' >/dev/null
R=$(action $S post.update_content "{\"postId\":$D,\"fields\":{\"title\":\"T13 paused edit\"}}")
check "$(echo "$R" | jget "['errorCode']")" "paused" "paused: post.update_content refused"
R=$(action $S post.create_draft '{"title":"T13 paused draft"}'); check "$(echo "$R" | jget "['ok']")" "False" "paused: create_draft refused"
CODE=$(curl -s -o /dev/null -w '%{http_code}' -X POST -H "Authorization: Bearer vis-test-token-$S" -H 'Content-Type: application/json' --data '{"title":"x"}' http://localhost:8091/wp-json/visibility/v1/posts)
check "$CODE" "403" "paused: POST /posts -> 403"
CODE=$(curl -s -o /dev/null -w '%{http_code}' -H "Authorization: Bearer vis-test-token-$S" http://localhost:8091/wp-json/visibility/v1/posts/$D)
check "$CODE" "200" "paused: reads still allowed"

# Back on.
api $S POST /events/site-config-changed '{"allowPublish":true,"disabled":false}' >/dev/null
R=$(action $S post.publish "{\"postId\":$D}"); check "$(echo "$R" | jget "['ok']")" "True" "switches back on: publish works"
$WP $S option delete visibility_allow_publish >/dev/null; $WP $S option delete visibility_disabled >/dev/null
summary
