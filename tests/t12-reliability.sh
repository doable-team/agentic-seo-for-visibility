#!/bin/bash
# T12 / #272: an approved request runs exactly once, even when reporting the
# result fails, and when the push and the cron tick fire at the same time.
cd "$(dirname "$0")"; . ./lib.sh
S=1; PORT=3999; STATE=$(mktemp)
python3 mock-api.py $PORT "$STATE" & MOCK=$!; trap 'kill $MOCK' EXIT; sleep 1
$WP $S option update visibility_dev_api_base "http://host.docker.internal:$PORT" >/dev/null
count() { $WP $S post list --post_type=post --post_status=any --title="$1" --format=count; }
tick()  { $WP $S eval 'Visibility_Requests::run_cron_tick();'; }

# 1. Report fails once -> action still runs once; only the report is retried.
T1="T12 report-fails $RANDOM"; R1="req-$RANDOM$RANDOM"
python3 - "$STATE" "$R1" "$T1" <<'PY'
import json,sys; s,r,t=sys.argv[1:]
json.dump({"failReports":1,"inbox":[{"id":r,"siteId":"test-site-1","status":"approved","actionType":"post.create_draft","actionPayload":{"title":t,"content":"<p>x</p>"}}]},open(s,"w"))
PY
tick; tick; tick
check "$(count "$T1")" "1" "report failed once: draft created once over 3 ticks"
check "$(python3 -c "import json;print(sum(1 for r in json.load(open('$STATE'))['reports'] if r['id']=='$R1'))")" "2" "report retried once after failure (2 attempts)"
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

$WP $S option delete visibility_dev_api_base >/dev/null
summary
