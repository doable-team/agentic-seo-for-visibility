#!/bin/bash
# #296 signed requests and key rotation (signing v1, agreed with the app side):
# bearer-only still works (backward compatible); signed GET/POST/PATCH work on
# pretty and ?rest_route= URLs and with X-HTTP-Method-Override; a replayed
# nonce, an old timestamp, a wrong key or a changed body/query is refused;
# requireSignature refuses bearer-only; rotation returns the same pending token
# until it is used, both tokens work until then, the first use of the new
# token retires the old one; the plugin's own heartbeat switches to it.
cd "$(dirname "$0")"; . ./lib.sh
S=${SITE:-1}; BASE="http://localhost:$((PORT_BASE+S))"; TOK=vis-test-token-$S
PORT=${MOCK_PORT:-3997}; STATE=$(mktemp)
python3 mock-api.py $PORT "$STATE" & MOCK=$!
cleanup() { kill $MOCK; $WP $S option update visibility_site_token $TOK >/dev/null; $WP $S option delete visibility_site_token_pending visibility_require_signature visibility_token_rotated_at visibility_dev_api_base >/dev/null 2>&1; }
trap cleanup EXIT; sleep 1
$CTR cp /home/tulasinath/viswp/mu/visibility-dev.php ${CPFX}$S:/var/www/html/wp-content/mu-plugins/visibility-dev.php
$WP $S option update visibility_dev_api_base "http://host.docker.internal:$PORT" >/dev/null
st() { echo "$1" | cut -d' ' -f1; }
bd() { echo "$1" | cut -d' ' -f2-; }
api $S POST /events/site-config-changed '{"controls":null,"allowPublish":true,"disabled":false}' >/dev/null

C=$(api $S GET /capabilities)
check "$(echo "$C" | jget "('auth.signed' in d['features'], 'auth.rotate' in d['features'], d['auth']['signing'], d['auth']['requireSignature'], d['auth']['pendingRotation'])")" "(True, True, 'v1', False, False)" "capabilities: auth.signed/auth.rotate, auth {signing v1, not required}"
check "$(curl -s -o /dev/null -w '%{http_code}' -H "Authorization: Bearer $TOK" $BASE/wp-json/visibility/v1/health)" "200" "bearer only: still accepted"
check "$(curl -s -o /dev/null -w '%{http_code}' -H "Authorization: Bearer nope" $BASE/wp-json/visibility/v1/health)" "401" "wrong bearer: 401"

R=$(python3 sign.py $BASE $TOK GET "/posts?type=page&per_page=2&search=a b")
check "$(st "$R")" "200" "signed GET with a query (sorted, RFC 3986)"
R=$(python3 sign.py $BASE $TOK GET "/posts?per_page=1" --plain)
check "$(st "$R")" "200" "signed GET on ?rest_route="
R=$(python3 sign.py $BASE $TOK GET "/health" --no-bearer)
check "$(st "$R")" "200" "signed without the bearer"
P=$($WP $S post create --post_status=draft --post_title="Sign $RANDOM" --porcelain | tr -d '\r' | tail -1)
R=$(python3 sign.py $BASE $TOK PATCH "/posts/$P" '{"title":"Signed title"}' --override)
check "$(st "$R")" "200" "signed PATCH sent as POST + X-HTTP-Method-Override"
check "$($WP $S post get $P --field=post_title | tr -d '\r')" "Signed title" "PATCH applied"
R=$(python3 sign.py $BASE $TOK POST "/events/action-approved" "{\"requestId\":\"sig-$RANDOM$RANDOM\",\"actionType\":\"post.update_content\",\"actionPayload\":{\"postId\":$P,\"fields\":{\"excerpt\":\"x\"}}}")
check "$(bd "$R" | jget "d['ok']")" "True" "signed action push"

# Refusals.
N=nonce-$RANDOM$RANDOM$RANDOM
R=$(python3 sign.py $BASE $TOK GET "/health" --nonce $N); check "$(st "$R")" "200" "first use of a nonce"
R=$(python3 sign.py $BASE $TOK GET "/health" --nonce $N); check "$(bd "$R" | jget "d['code']")" "visibility_replayed" "same nonce again -> visibility_replayed"
R=$(python3 sign.py $BASE $TOK GET "/health" --ts $(( $(date +%s) - 400 ))); check "$(bd "$R" | jget "d['code']")" "visibility_stale_request" "timestamp 400 s old -> visibility_stale_request"
R=$(python3 sign.py $BASE $TOK GET "/health" --key other-key --no-bearer); check "$(bd "$R" | jget "d['code']")" "visibility_bad_signature" "wrong key -> visibility_bad_signature"
R=$(python3 sign.py $BASE $TOK GET "/health" --key other-key); check "$(st "$R")" "401" "wrong key with the right bearer -> 401"
# Body changed after signing: sign one body, send another.
TS=$(date +%s); NN=n-$RANDOM$RANDOM$RANDOM$RANDOM
SIG=$(python3 -c "
import hmac,hashlib;c='\n'.join(['v1','PATCH','/visibility/v1/posts/$P','','$TS','$NN',hashlib.sha256(b'{\"title\":\"A\"}').hexdigest()]);print('v1='+hmac.new(b'$TOK',c.encode(),hashlib.sha256).hexdigest())")
check "$(curl -s -X PATCH -H "X-Visibility-Timestamp: $TS" -H "X-Visibility-Nonce: $NN" -H "X-Visibility-Signature: $SIG" -H 'Content-Type: application/json' --data '{"title":"B"}' $BASE/wp-json/visibility/v1/posts/$P | jget "d['code']")" "visibility_bad_signature" "body changed after signing -> refused"

# Owner requires signatures (WP admin switch / requireSignature).
$WP $S option update visibility_require_signature 1 >/dev/null
check "$(curl -s -H "Authorization: Bearer $TOK" $BASE/wp-json/visibility/v1/health | jget "d['code']")" "visibility_signature_required" "required: bearer only -> visibility_signature_required"
check "$(st "$(python3 sign.py $BASE $TOK GET /health)")" "200" "required: signed works"
$WP $S option update visibility_require_signature 0 >/dev/null

# Rotation.
R=$(python3 sign.py $BASE $TOK POST /auth/rotate '{}')
NEW=$(bd "$R" | jget "d['siteToken']")
check "$(echo -n "$NEW" | wc -c)" "64" "rotate: 64-char new token"
R2=$(python3 sign.py $BASE $TOK POST /auth/rotate '{"requireSignature":false}')
check "$(bd "$R2" | jget "d['siteToken']")" "$NEW" "rotate again with the old token: same pending token (lost answer is safe)"
check "$(api $S GET /capabilities | jget "d['auth']['pendingRotation']")" "True" "pendingRotation true"
check "$(curl -s -o /dev/null -w '%{http_code}' -H "Authorization: Bearer $TOK" $BASE/wp-json/visibility/v1/health)" "200" "old token still works before the new one is used"
check "$(st "$(python3 sign.py $BASE $NEW GET /capabilities)")" "200" "new token works (signed) -> promoted"
sleep 1
check "$(curl -s -o /dev/null -w '%{http_code}' -H "Authorization: Bearer $TOK" $BASE/wp-json/visibility/v1/health)" "401" "old token refused after the new one was used"
check "$(st "$(python3 sign.py $BASE $TOK GET /health --no-bearer)")" "401" "old token signature refused too"
check "$(curl -s -H "Authorization: Bearer $NEW" $BASE/wp-json/visibility/v1/capabilities | jget "(d['auth']['pendingRotation'], d['auth']['rotatedAt'] is not None)")" "(False, True)" "capabilities after promotion"
check "$($WP $S option get visibility_site_token | tr -d '\r')" "$NEW" "plugin's own calls now use the new token"
check "$(python3 -c "import json;print(json.load(open('$STATE')).get('heartbeatAuth','?'))")" "Bearer $NEW" "heartbeat sent with the new token right after promotion"
R=$(python3 sign.py $BASE $NEW POST /auth/rotate '{"requireSignature":true}')
check "$(bd "$R" | jget "d['requireSignature']")" "True" "rotate with requireSignature:true"
summary
