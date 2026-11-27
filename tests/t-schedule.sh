#!/bin/bash
# #572: a draft scheduled for a future date stays scheduled (status future, that
# date) through post.schedule and PATCH /posts/{id} status=future + date; POST
# /posts with status future + date too. A date already past publishes now.
cd "$(dirname "$0")"; . ./lib.sh
S=${SITE:-1}
TOM=$(date -u -d '+1 day' +%Y-%m-%dT%H:%M:%S); TOMDB=$(date -u -d "$TOM" '+%Y-%m-%d %H:%M:%S')
draft() { api $S POST /posts "{\"title\":\"sched $1 $RANDOM\",\"content\":\"<p>x</p>\",\"status\":\"draft\"}" | jget "['id']"; }
api $S POST /events/site-config-changed '{"controls":null,"allowPublish":true,"disabled":false}' >/dev/null

ID=$(draft action)
R=$(action $S post.schedule "{\"postId\":$ID,\"publishAt\":\"${TOM}Z\"}")
check "$(echo "$R" | jget "(d['ok'], d['result']['postStatus'])")" "(True, 'future')" "post.schedule: ok, status future"
check "$($WP $S post get $ID --field=post_status | tr -d '\r')" "future" "stored status future"
check "$($WP $S post get $ID --field=post_date_gmt | tr -d '\r')" "$TOMDB" "stored date is the one asked"
check "$(api $S GET "/posts/$ID" | jget "['status']")" "future" "read back: future"

ID=$(draft patch)
R=$(api $S PATCH /posts/$ID "{\"status\":\"future\",\"date\":\"${TOM}Z\"}")
check "$(echo "$R" | jget "d['status']")" "future" "PATCH status=future + date: answer says future"
check "$($WP $S post get $ID --field=post_status | tr -d '\r')" "future" "PATCH: stored status future"
check "$($WP $S post get $ID --field=post_date_gmt | tr -d '\r')" "$TOMDB" "PATCH: stored date is the one asked"

R=$(api $S POST /posts "{\"title\":\"sched create $RANDOM\",\"content\":\"<p>x</p>\",\"status\":\"future\",\"date\":\"${TOM}Z\"}")
check "$(echo "$R" | jget "d['status']")" "future" "POST /posts status=future + date: future"

ID=$(draft past)
R=$(action $S post.schedule "{\"postId\":$ID,\"publishAt\":\"2020-01-01T00:00:00Z\"}")
check "$(echo "$R" | jget "(d['ok'], d['result']['postStatus'])")" "(True, 'publish')" "post.schedule with a past date publishes now"
ID=$(draft pastpatch)
R=$(api $S PATCH /posts/$ID '{"status":"future","date":"2020-01-01T00:00:00Z"}')
check "$(echo "$R" | jget "(d.get('code'), d.get('data',{}).get('status'))")" "('visibility_schedule_failed', 409)" "PATCH status=future with a past date: refused, not published"
check "$($WP $S post get $ID --field=post_status | tr -d '\r')" "draft" "and the draft is left as it was"
summary
