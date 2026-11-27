#!/bin/bash
# T12 / #272: an approved request runs exactly once, even when reporting the
# result fails, and when the push and the cron tick fire at the same time.
cd "$(dirname "$0")"; . ./lib.sh
S=${SITE:-1}; PORT=${MOCK_PORT:-3999}; STATE=$(mktemp)
python3 mock-api.py $PORT "$STATE" & MOCK=$!; trap 'kill $MOCK' EXIT; sleep 1
$WP $S option update visibility_dev_api_base "http://host.docker.internal:$PORT" >/dev/null
# Start clean: unreported records left by other checks would be retried on
# each tick and use up the single failing report.
for o in $($WP $S option list --search='visibility_req_*' --field=option_name); do $WP $S option delete "$o" >/dev/null; done
count() { $WP $S post list --post_type=post --post_status=any --title="$1" --format=count; }
tick()  { $WP $S eval 'Visibility_Requests::run_cron_tick();'; }

# 1. Report fails once -> action still runs once; only the report is retried.
T1="T12 report-fails $RANDOM"; R1="req-$RANDOM$RANDOM"
python3 - "$STATE" "$R1" "$T1" <<'PY'
import json,sys; s,r,t=sys.argv[1:]
json.dump({"failReports":1,"inbox":[{"id":r,"siteId":"test-site-1","status":"approved","actionType":"post.create_draft","actionPayload":{"title":t,"content":"<p>x</p>"}}]},open(s,"w"))
PY
tick; tick; tick
echo "   reports: $(python3 -c "import json;print([x for x in json.load(open('$STATE'))['reports'] if x['id']=='$R1'])")"
check "$(count "$T1")" "1" "report failed once: draft created once over 3 ticks"
# WP-Cron on the site may add a tick of its own, so: at least one failed
# attempt followed by a successful one, and the action itself ran once.
check "$(python3 -c "import json;r=[x for x in json.load(open('$STATE'))['reports'] if x['id']=='$R1'];print(len(r)>=2 and r[0]['failed'] and not r[-1]['failed'])")" "True" "report retried after the failure until it succeeded"
check "$(python3 -c "import json;print([r['failed'] for r in json.load(open('$STATE'))['reports'] if r['id']=='$R1'][-1])")" "False" "second report succeeded"

# 2. Push and cron together -> runs once.
rm -f /tmp/t12push*
T2="T12 race $RANDOM"; R2="req-$RANDOM$RANDOM"
python3 - "$STATE" "$R2" "$T2" <<'PY'
import json,sys; s,r,t=sys.argv[1:]
json.dump({"inbox":[{"id":r,"siteId":"test-site-1","status":"approved","actionType":"post.create_draft","actionPayload":{"title":t,"content":"<p>y</p>"}}]},open(s,"w"))
PY
P="{\"title\":\"$T2\",\"content\":\"<p>y</p>\"}"
PIDS=""
for i in 1 2 3; do action $S post.create_draft "$P" "$R2" > /tmp/t12push$i & PIDS="$PIDS $!"; done
tick & PIDS="$PIDS $!"; tick & PIDS="$PIDS $!"
wait $PIDS
check "$(count "$T2")" "1" "3 pushes + 2 cron ticks at once: draft created once"
D=$(cat /tmp/t12push* | grep -o '"duplicate":true' | wc -l)
[ "$D" -ge 2 ] && ok "late pushes answered duplicate:true ($D)" || bad "duplicate flag missing: $(cat /tmp/t12push*)"
check "$(cat /tmp/t12push* | grep -o '"ok":true' | wc -l)" "3" "every push got the real outcome (ok:true), not a failure"

# 3. Same requestId pushed again later -> stored result, not a second run.
R=$(action $S post.create_draft "$P" "$R2")
check "$(echo "$R" | jget "['duplicate']")" "True" "replayed push is a duplicate"
check "$(count "$T2")" "1" "still one draft"

# 4. #568: ten edits at once with the same modifiedGmt -> exactly one wins, nine conflict;
#    ten undos of that change at once -> exactly one.
ID=$(api $S POST /posts '{"title":"T12 race568","content":"<!-- wp:paragraph -->\n<p>orig</p>\n<!-- /wp:paragraph -->","status":"draft"}' | jget "['id']")
M=$(api $S GET "/posts/$ID" | jget "['modifiedGmt']")
rm -f /tmp/t12e_* /tmp/t12r_*
for i in 0 1 2 3 4 5 6 7 8 9; do action $S post.edit_parts "{\"postId\":$ID,\"modifiedGmt\":\"$M\",\"edits\":[{\"target\":{\"path\":\"0\"},\"op\":\"replace_text\",\"find\":\"orig\",\"text\":\"writer $i\"}]}" > /tmp/t12e_$i & P4="$P4 $!"; done; wait $P4
check "$(cat /tmp/t12e_* | grep -o '"ok":true' | wc -l)" "1" "10 simultaneous edits, same modifiedGmt: one ok"
check "$(cat /tmp/t12e_* | grep -o '"errorCode":"conflict"' | wc -l)" "9" "the other nine are conflicts"
W=$(grep -l '"ok":true' /tmp/t12e_* | head -1)
CH=$(python3 -c "import json;print(json.load(open('$W'))['result']['changeId'])")
check "$(api $S GET "/posts/$ID" | jget "['content']" | grep -o 'writer [0-9]' | wc -l)" "1" "one writer's text on the page"
for i in 0 1 2 3 4 5 6 7 8 9; do api $S POST /changes/$CH/rollback > /tmp/t12r_$i & P5="$P5 $!"; done; wait $P5
check "$(cat /tmp/t12r_* | grep -o '"ok":true' | wc -l)" "1" "10 simultaneous undos: exactly one applied"
check "$(api $S GET "/posts/$ID" | jget "['content']" | grep -c '<p>orig</p>')" "1" "content back to the original"

$WP $S option delete visibility_dev_api_base >/dev/null
summary
