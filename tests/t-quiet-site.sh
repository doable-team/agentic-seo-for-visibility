#!/bin/bash
# #280 quiet sites: WP-Cron only runs on visits. With WP-Cron disabled and no
# visitor, (1) a pushed approval runs at once, (2) a missed approval in the
# inbox is picked up as soon as Visibility calls the site (any authenticated
# request), not at the next visit, and (3) capabilities.cron reports
# lastTickAt / lastTickVia / systemCron; a system-cron tick (WP-CLI) is
# reported as systemCron.
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
$WP $S config delete DISABLE_WP_CRON >/dev/null
summary
