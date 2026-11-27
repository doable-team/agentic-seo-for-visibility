#!/bin/bash
# #569 / #564: the check-in that tells Visibility about a new plugin version
# runs after the page is sent (a slow Visibility never holds up a visitor), and
# the version is only marked as reported once that check-in worked; a failed
# one is retried on a later request (at most once a minute).
cd "$(dirname "$0")"; . ./lib.sh
S=${SITE:-1}; PORT=${MOCK_PORT:-3997}; STATE=$(mktemp); U="http://localhost:$((PORT_BASE+S))"
python3 mock-api.py $PORT "$STATE" & MOCK=$!
trap 'kill $MOCK 2>/dev/null; $WP $S option delete visibility_dev_api_base >/dev/null' EXIT; sleep 1
$CTR cp /home/tulasinath/viswp/mu/visibility-dev.php ${CPFX}$S:/var/www/html/wp-content/mu-plugins/visibility-dev.php
V=$($WP $S eval 'echo VISIBILITY_PLUGIN_VERSION;')
beats() { python3 -c "import json;print(len([h for h in json.load(open('$STATE')).get('heartbeats',[]) if h.get('pluginVersion')=='$V']))"; }
old() { $WP $S option update visibility_installed_version 2.0.0 >/dev/null; $WP $S transient delete visibility_version_report_try >/dev/null 2>&1; }
# No daily heartbeat or catch-up of their own during the check.
$WP $S option update visibility_last_tick "{\"at\":$(date +%s),\"via\":\"web\"}" --format=json >/dev/null
$WP $S cron event unschedule visibility_daily_heartbeat >/dev/null 2>&1

# 1. Visibility answers after 2 s: the page doesn't wait, the version still arrives.
echo '{"delay":2}' > "$STATE"
$WP $S option update visibility_dev_api_base "http://host.docker.internal:$PORT" >/dev/null
old
T=$(curl -s -o /tmp/tvr_page -w '%{time_total}' "$U/")
python3 -c "import sys; sys.exit(0 if float('$T') < 1.0 else 1)" && ok "first page load after the update: ${T}s (Visibility answers in 2 s)" || bad "first page load waited ${T}s"
grep -q '</html>' /tmp/tvr_page && ok "the page arrives complete" || bad "page cut off"
sleep 4
check "$(beats)" "1" "one check-in with the new version"
check "$($WP $S option get visibility_installed_version | tr -d '\r')" "$V" "marked as reported after it worked"
curl -s -o /dev/null "$U/"; sleep 1
check "$(beats)" "1" "not repeated on the next page load"

# 2. Visibility unreachable on the first load, back later: the version still gets there, once.
echo '{}' > "$STATE"
$WP $S option update visibility_dev_api_base "http://host.docker.internal:1" >/dev/null
old
curl -s -o /dev/null "$U/"; sleep 2
check "$($WP $S option get visibility_installed_version | tr -d '\r')" "2.0.0" "unreachable: not marked as reported"
check "$(beats)" "0" "nothing received"
$WP $S option update visibility_dev_api_base "http://host.docker.internal:$PORT" >/dev/null
curl -s -o /dev/null "$U/"; sleep 2
check "$(beats)" "0" "within a minute of the failed try: no new try"
$WP $S transient delete visibility_version_report_try >/dev/null   # a minute later
curl -s -o /dev/null "$U/"; sleep 2
check "$(beats)" "1" "back up: exactly one check-in with the new version"
check "$($WP $S option get visibility_installed_version | tr -d '\r')" "$V" "now marked as reported"
curl -s -o /dev/null "$U/"; sleep 1
check "$(beats)" "1" "and not again"

# 3. Visibility hangs (9 s): the page is still fast; the check-in gives up after 3 s and is retried later.
echo '{"delay":9}' > "$STATE"
old
T=$(curl -s -o /dev/null -w '%{time_total}' "$U/")
python3 -c "import sys; sys.exit(0 if float('$T') < 1.0 else 1)" && ok "Visibility answering in 9 s: page load ${T}s" || bad "page load waited ${T}s"
sleep 5
check "$($WP $S option get visibility_installed_version | tr -d '\r')" "2.0.0" "a check-in that timed out is not counted as reported"
$WP $S option update visibility_installed_version "$V" >/dev/null
$WP $S eval 'if (!wp_next_scheduled("visibility_daily_heartbeat")) wp_schedule_event(time() + 60, "daily", "visibility_daily_heartbeat");' >/dev/null
rm -f /tmp/tvr_page
summary
