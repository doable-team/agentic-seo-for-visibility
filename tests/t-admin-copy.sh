#!/bin/bash
# #581: WP admin names the app's real path (Settings › Integrations › your
# website › Connect › WordPress) and the controls table says "any post setting"
# when that switch is on, instead of "Custom fields: none".
cd "$(dirname "$0")"; . ./lib.sh
S=${SITE:-1}; U="http://localhost:$((PORT_BASE+S))"
CJ=$(mktemp); curl -s -c $CJ -b $CJ -o /dev/null $U/wp-login.php; curl -s -c $CJ -b $CJ -b 'wordpress_test_cookie=WP+Cookie+check' -o /dev/null -d "log=admin&pwd=admin&wp-submit=Log+In&testcookie=1" $U/wp-login.php
page() { curl -s -b $CJ "$U/wp-admin/admin.php?page=visibility"; }
api $S POST /events/site-config-changed '{"controls":{"actions":["create_drafts","edit_fields"],"postTypes":["post","page"],"lockedPostIds":[],"fieldAllowlist":[],"anyPostMeta":true}}' >/dev/null
H=$(page)
grep -q 'any post setting (except protected ones)' <<<"$H" && ok "any post setting on: the table says so" || bad "table still says none"
grep -q 'Integrations › your website › WordPress' <<<"$H" && ok "link names the app's path" || bad "old path in the link"
grep -q 'Connect via plugin\|Settings › WordPress' <<<"$H" && bad "old wording still shown" || ok "no old wording"
api $S POST /events/site-config-changed '{"controls":{"actions":["create_drafts"],"postTypes":["post"],"lockedPostIds":[],"fieldAllowlist":[]}}' >/dev/null
grep -A1 'Custom fields agents may change' <<<"$(page)" | grep -q '>none<' && ok "nothing allowed: none" || bad "none missing"
# The pairing screen (site not paired).
T=$($WP $S option get visibility_site_token | tr -d '\r'); $WP $S option delete visibility_site_token >/dev/null
grep -q 'Settings › Integrations</strong>, pick your website, click <strong>Connect</strong> and choose <strong>WordPress</strong>' <<<"$(page)" && ok "pairing steps name the app's path" || bad "pairing steps"
$WP $S option update visibility_site_token "$T" >/dev/null
api $S POST /events/site-config-changed '{"controls":null}' >/dev/null
rm -f $CJ
summary
