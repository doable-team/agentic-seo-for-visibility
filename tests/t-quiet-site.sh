#!/bin/bash
# #280 quiet sites: WP-Cron only runs on visits. With WP-Cron disabled and no
# visitor, (1) a pushed approval runs at once, (2) a missed approval in the
# inbox is picked up as soon as Visibility calls the site (any authenticated
# request), not at the next visit, and (3) capabilities.cron reports
# lastTickAt / lastTickVia / systemCron; a system-cron tick (WP-CLI) is
# reported as systemCron. (4) A backlog of unreported results never holds up
# Visibility's request (#583).
cd "$(dirname "$0")"; . ./lib.sh
S=${SITE:-1}; PORT=${MOCK_PORT:-3998}; STATE=$(mktemp)
python3 mock-api.py $PORT "$STATE" & MOCK=$!; trap 'kill $MOCK; $WP $S option delete visibility_dev_api_base >/dev/null' EXIT; sleep 1
$CTR cp /home/tulasinath/viswp/mu/visibility-dev.php ${CPFX}$S:/var/www/html/wp-content/mu-plugins/visibility-dev.php
$WP $S option update visibility_dev_api_base "http://host.docker.internal:$PORT" >/dev/null
$WP $S config set DISABLE_WP_CRON true --raw >/dev/null
count() { $WP $S post list --post_type=post --post_status=any --title="$1" --format=count; }
api $S POST /events/site-config-changed '{"controls":null,"allowPublish":true,"disabled":false}' >/dev/null

check "$(api $S GET /capabilities | jget "('cron.status' in d['features'], d['cron']['wpCronDisabled'], 'lastTickAt' in d['cron'], 'systemCron' in d['cron'])")" "(True, True, True, True)" "capabilities: cron.status, wpCronDisabled, lastTickAt, systemCron"

# 1. Push: runs at once.
T1="Quiet push $RANDOM"
R=$(action $S post.create_draft "{\"title\":\"$T1\",\"content\":\"<p>x</p>\"}")
check "$(echo "$R" | jget "d['ok']")" "True" "pushed approval executed in the same request"
check "$(count "$T1")" "1" "draft exists right away"

# 2. A missed push: the request is only in the inbox. Make the last tick old.
T2="Quiet missed $RANDOM"; R2="req-$RANDOM$RANDOM"
python3 - "$STATE" "$R2" "$T2" <<'PY'
import json,sys; s,r,t=sys.argv[1:]
json.dump({"inbox":[{"id":r,"siteId":"test-site-$S".replace("$S",""),"status":"approved","actionType":"post.create_draft","actionPayload":{"title":t,"content":"<p>x</p>"}}]},open(s,"w"))
PY
python3 - "$STATE" "$S" <<'PY'
import json,sys; s=json.load(open(sys.argv[1])); s["inbox"][0]["siteId"]="test-site-"+sys.argv[2]; json.dump(s,open(sys.argv[1],"w"))
PY
$WP $S option update visibility_last_tick '{"at":1000,"via":"web"}' --format=json >/dev/null
$WP $S transient delete visibility_catch_up >/dev/null 2>&1
check "$(count "$T2")" "0" "missed approval not run yet (no visitor, WP-Cron off)"
api $S GET /health >/dev/null   # Visibility's next call (heartbeat, read, anything)
for i in 1 2 3 4 5 6 7 8 9 10; do [ "$(count "$T2")" = 1 ] && break; sleep 1; done
check "$(count "$T2")" "1" "missed approval run after Visibility's next call, without a visitor"
check "$(python3 -c "import json;print([r['id'] for r in json.load(open('$STATE')).get('reports',[])])" | grep -c "$R2")" "1" "its result was reported"
check "$(api $S GET /capabilities | jget "(d['cron']['lastTickVia'], d['cron']['lastTickAt'] is not None)")" "('push', True)" "lastTickAt set, via push"
# A tick that is not late does not start another.
$WP $S option update visibility_last_tick "{\"at\":$(date +%s),\"via\":\"web\"}" --format=json >/dev/null
api $S GET /health >/dev/null; sleep 1
check "$(api $S GET /capabilities | jget "d['cron']['lastTickVia']")" "web" "fresh tick: no extra catch-up"

# 3. System cron (WP-CLI) is recognised.
$WP $S cron event run visibility_pull_approved_requests >/dev/null 2>&1
check "$(api $S GET /capabilities | jget "(d['cron']['lastTickVia'], d['cron']['systemCron'])")" "('cli', True)" "wp cron event run -> lastTickVia cli, systemCron true"
check "$(curl -s -u admin:admin "http://localhost:$((PORT_BASE+S))/wp-admin/admin.php?page=visibility" -o /dev/null -w '%{http_code}')" "302" "admin page needs login (sanity)"
# 4. #583: 100 results waiting to be reported and a slow Visibility (1 s an answer):
#    a late catch-up never holds up Visibility's request; it reports a few per
#    request and the rest go out with the following ticks.
for o in $($WP $S option list --search='visibility_req_*' --field=option_name); do $WP $S option delete "$o" >/dev/null; done
$WP $S eval 'for ($i = 0; $i < 100; $i++) { $id = "backlog-" . $i; add_option("visibility_req_" . md5($id), ["id" => $id, "actionType" => "post.create_draft", "state" => "done", "ok" => true, "result" => null, "error" => null, "claimedAt" => time(), "finishedAt" => time(), "reported" => false], "", "no"); }' >/dev/null
echo '{"delay":1,"inbox":[]}' > "$STATE"
$WP $S option update visibility_last_tick '{"at":1000,"via":"web"}' --format=json >/dev/null
$WP $S transient delete visibility_catch_up >/dev/null 2>&1
T=$(curl -s -o /dev/null -w '%{time_total}' -H "Authorization: Bearer vis-test-token-$S" "http://localhost:$((PORT_BASE+S))/wp-json/visibility/v1/health")
python3 -c "import sys; sys.exit(0 if float('$T') < 1.0 else 1)" && ok "late catch-up with 100 unreported results: Visibility's request answered in ${T}s" || bad "request waited ${T}s"
sleep 12
N=$(python3 -c "import json;print(len([r for r in json.load(open('$STATE')).get('reports',[]) if r['id'].startswith('backlog-')]))")
[ "$N" -ge 1 ] && [ "$N" -le 5 ] && ok "the catch-up reported $N (at most 5) and left the rest" || bad "catch-up reported $N"
echo '{"inbox":[]}' > "$STATE"
$WP $S cron event run visibility_pull_approved_requests >/dev/null 2>&1
check "$($WP $S eval 'global $wpdb; echo count(array_filter(array_map("maybe_unserialize", $wpdb->get_col("SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE \"visibility_req_%\"")), function ($r) { return empty($r["reported"]); }));')" "0" "the next tick reports the rest"
for o in $($WP $S option list --search='visibility_req_*' --field=option_name); do $WP $S option delete "$o" >/dev/null; done

$WP $S config delete DISABLE_WP_CRON >/dev/null
summary
