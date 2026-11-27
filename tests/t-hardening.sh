#!/bin/bash
# #565 hardening and #580 disconnect:
# - pairing over plain http is refused unless VISIBILITY_ALLOW_HTTP is defined;
# - a new pairing only accepts signed requests (unless Visibility answers
#   requireSignature:false); a site paired before keeps working with a bearer;
# - a body over 2 MB -> 413 visibility_too_large, more than 2000 blocks in one
#   post or 100 edits in one edit_parts -> refused, nothing written;
# - over the per-minute limit -> 429 visibility_rate_limited with Retry-After;
# - POST /auth/disconnect (signed only) forgets the pairing without calling
#   Visibility back, and WP admin says it is not connected.
cd "$(dirname "$0")"; . ./lib.sh
S=${SITE:-1}; BASE="http://localhost:$((PORT_BASE+S))"; TOK=vis-test-token-$S
PORT=${MOCK_PORT:-3996}; STATE=$(mktemp); echo '{}' > "$STATE"
python3 mock-api.py $PORT "$STATE" & MOCK=$!
restore() {
  $WP $S option update visibility_site_token $TOK >/dev/null
  $WP $S option update visibility_project_id test-project >/dev/null
  $WP $S option update visibility_site_id test-site-$S >/dev/null
  $WP $S option delete visibility_site_token_pending visibility_require_signature visibility_token_rotated_at visibility_disconnected_by_app >/dev/null 2>&1
  $WP $S config delete VISIBILITY_ALLOW_HTTP >/dev/null 2>&1
  $WP $S eval 'global $wpdb; $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE \"visibility_rate_%\"");' >/dev/null
}
trap 'kill $MOCK; restore; $WP $S option delete visibility_dev_api_base >/dev/null' EXIT; sleep 1
$CTR cp /home/tulasinath/viswp/mu/visibility-dev.php ${CPFX}$S:/var/www/html/wp-content/mu-plugins/visibility-dev.php
$WP $S option update visibility_dev_api_base "http://host.docker.internal:$PORT" >/dev/null
api $S POST /events/site-config-changed '{"controls":null,"allowPublish":true,"disabled":false}' >/dev/null
restore
pairs() { python3 -c "import json;print(len(json.load(open('$STATE')).get('pairs',[])))"; }
pair() { $WP $S eval 'wp_set_current_user(1); $r = Visibility_Client::pair("VIS-TEST-01"); echo is_wp_error($r) ? $r->get_error_code() : "paired";' | tr -d '\r'; }

C=$(api $S GET /capabilities)
check "$(echo "$C" | jget "('auth.disconnect' in d['features'], 'limits.enforced' in d['features'], d['limits']['maxBodyBytes'], d['limits']['perMinute'])")" "(True, True, 2097152, 300)" "capabilities: auth.disconnect, limits"

# 1. A site paired before 2.1.2 (bearer, no signed-only setting) keeps working.
check "$(curl -s -o /dev/null -w '%{http_code}' -H "Authorization: Bearer $TOK" $BASE/wp-json/visibility/v1/health)" "200" "existing pairing: bearer still accepted"

# 2. Pairing over http.
check "$(pair)" "visibility_https_required" "http site: pairing refused"
check "$(pairs)" "0" "the code was not even sent"
check "$($WP $S option get visibility_site_token | tr -d '\r')" "$TOK" "existing pairing untouched"
CJ=$(mktemp); curl -s -c $CJ -b $CJ -o /dev/null $BASE/wp-login.php; curl -s -c $CJ -b $CJ -b 'wordpress_test_cookie=WP+Cookie+check' -o /dev/null -d "log=admin&pwd=admin&wp-submit=Log+In&testcookie=1" $BASE/wp-login.php
$WP $S option delete visibility_site_token >/dev/null
grep -q "starts with http://, so it can" <<<"$(curl -s -b $CJ "$BASE/wp-admin/admin.php?page=visibility")" && ok "settings page explains https is needed" || bad "no http warning on the settings page"
$WP $S config set VISIBILITY_ALLOW_HTTP true --raw >/dev/null
check "$(pair)" "paired" "VISIBILITY_ALLOW_HTTP: pairing allowed"
check "$($WP $S eval 'echo Visibility_Auth::required() ? "yes" : "no";' | tr -d '\r')" "yes" "new pairing: only signed requests accepted"
check "$(curl -s -o /dev/null -w '%{http_code}' -H "Authorization: Bearer vis-paired-token" $BASE/wp-json/visibility/v1/health)" "401" "new pairing: bearer-only refused"
check "$(python3 sign.py $BASE vis-paired-token GET /health --no-bearer | cut -d' ' -f1)" "200" "new pairing: signed request accepted"
echo '{"pair":{"siteToken":"vis-paired-token","projectId":"p1","requireSignature":false}}' > "$STATE"
check "$(pair)" "paired" "pairing with requireSignature:false"
check "$($WP $S eval 'echo Visibility_Auth::required() ? "yes" : "no";' | tr -d '\r')" "no" "Visibility can leave signed-only off"
echo '{}' > "$STATE"

# 3. Disconnect from Visibility's side.
pair >/dev/null
check "$(curl -s -o /dev/null -w '%{http_code}' -X POST -H "Authorization: Bearer vis-paired-token" $BASE/wp-json/visibility/v1/auth/disconnect)" "401" "/auth/disconnect without a signature: refused"
R=$(python3 sign.py $BASE vis-paired-token POST /auth/disconnect '{}' --no-bearer)
check "$R" '200 {"ok":true}' "signed /auth/disconnect: ok"
check "$($WP $S eval 'echo Visibility_Client::is_paired() ? "paired" : "not paired";' | tr -d '\r')" "not paired" "the site forgot its pairing"
check "$(python3 -c "import json;print(len(json.load(open('$STATE')).get('disconnects',[])))")" "0" "no call back to Visibility"
check "$(python3 sign.py $BASE vis-paired-token GET /health --no-bearer | cut -d' ' -f1)" "401" "the old key no longer works"
H=$(curl -s -b $CJ "$BASE/wp-admin/admin.php?page=visibility")
grep -q "Disconnected from Visibility on" <<<"$H" && ok "WP admin says it was disconnected" || bad "no disconnected note"
grep -q 'name="visibility_pairing_code"' <<<"$H" && ok "WP admin shows the pairing form (not Connected)" || bad "still shows Connected"
restore

# 4. Size limits.
big=$(python3 -c "print('{\"title\":\"big\",\"content\":\"' + 'a'*(2*1024*1024+10) + '\"}')")
R=$(echo "$big" | curl -s -D /tmp/thd -X POST -H "Expect:" -H "Authorization: Bearer $TOK" -H "Content-Type: application/json" --data-binary @- $BASE/wp-json/visibility/v1/posts)
check "$(head -1 /tmp/thd | cut -d' ' -f2)" "413" "2 MB+ body: HTTP 413"
check "$(echo "$R" | jget "(d['code'], d['data']['limit'])")" "('visibility_too_large', 2097152)" "clear JSON error"
check "$($WP $S post list --post_type=post --post_status=any --title=big --format=count)" "0" "nothing written"
blocks=$(python3 -c "print(''.join('<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->' for _ in range(2001)))")
R=$(api $S POST /posts "{\"title\":\"many blocks\",\"content\":\"$blocks\"}")
check "$(echo "$R" | jget "(d['code'], d['data']['status'])")" "('visibility_too_large', 413)" "2001 blocks in POST /posts: 413"
R=$(action $S post.create_draft "{\"title\":\"many blocks\",\"content\":\"$blocks\"}")
check "$(echo "$R" | jget "(d['ok'], d['errorCode'])")" "(False, 'too_large')" "2001 blocks in post.create_draft: refused"
check "$($WP $S post list --post_type=post --post_status=any --title='many blocks' --format=count)" "0" "nothing written"
P=$(api $S POST /posts '{"title":"edits limit","content":"<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->"}' | jget "['id']")
E=$(python3 -c "import json;print(json.dumps([{'target':{'path':'0'},'op':'replace_text','find':'x','text':'y'}]*101))")
R=$(action $S post.edit_parts "{\"postId\":$P,\"edits\":$E}")
check "$(echo "$R" | jget "(d['ok'], d['errorCode'])")" "(False, 'too_large')" "101 edits in one edit_parts: refused"
$WP $S post delete $P --force >/dev/null

# 5. Rate limit (300 a minute; lowered to 5 here through the filter).
$CTR exec ${CPFX}$S sh -c "echo '<?php add_filter(\"visibility_rate_limit\", function () { return 5; });' > /var/www/html/wp-content/mu-plugins/vis-rate-test.php"
$WP $S eval 'global $wpdb; $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE \"visibility_rate_%\"");' >/dev/null
sleep $(( (62 - $(date +%s) % 60) % 60 ))   # start of a minute
for i in 1 2 3 4 5; do curl -s -o /dev/null -H "Authorization: Bearer $TOK" $BASE/wp-json/visibility/v1/health; done
R=$(curl -s -D /tmp/thd -H "Authorization: Bearer $TOK" $BASE/wp-json/visibility/v1/health)
check "$(head -1 /tmp/thd | cut -d' ' -f2)" "429" "over the limit: HTTP 429"
check "$(echo "$R" | jget "d['code']")" "visibility_rate_limited" "clear JSON error"
grep -qi '^Retry-After: [0-9]' /tmp/thd && ok "Retry-After header ($(grep -i '^Retry-After' /tmp/thd | tr -d '\r'))" || bad "no Retry-After"
$CTR exec ${CPFX}$S rm -f /var/www/html/wp-content/mu-plugins/vis-rate-test.php
rm -f $CJ /tmp/thd
summary
