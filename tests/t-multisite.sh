#!/bin/bash
# #570: on a network-activated multisite every site gets the daily check-in and
# the 5-minute catch-up job, not only the main site: existing sub-sites on their
# first request, new sub-sites when they are created; a network deactivation
# clears them on every site. SITE = a subdirectory multisite with sub-site /shop/
# (created by hand: site.sh, `wp core multisite-convert`, `wp site create --slug=shop`).
cd "$(dirname "$0")"; . ./lib.sh
S=${SITE:-6}; U="http://localhost:$((PORT_BASE+S))"
ev() { $WP $S --url="$1" cron event list --format=csv --fields=hook 2>/dev/null | grep -c '^visibility_\(daily_heartbeat\|pull_approved_requests\)$'; }
$WP $S plugin deactivate agentic-seo-visibility --network >/dev/null 2>&1
$WP $S site delete --slug=later --yes >/dev/null 2>&1
check "$(ev $U/shop/)" "0" "network deactivated: sub-site has no jobs"
$WP $S plugin activate agentic-seo-visibility --network >/dev/null
check "$(ev $U/)" "2" "network activated: main site has both jobs"
curl -s -o /dev/null "$U/shop/"   # the sub-site's first request after activation
check "$(ev $U/shop/)" "2" "existing sub-site: both jobs after its first request"
$WP $S site create --slug=later >/dev/null
check "$(ev $U/later/)" "2" "new sub-site: both jobs at creation"
$WP $S plugin deactivate agentic-seo-visibility --network >/dev/null
check "$(( $(ev $U/) + $(ev $U/shop/) + $(ev $U/later/) ))" "0" "network deactivation clears every site"
$WP $S site delete --slug=later --yes >/dev/null
$WP $S plugin activate agentic-seo-visibility --network >/dev/null
summary
