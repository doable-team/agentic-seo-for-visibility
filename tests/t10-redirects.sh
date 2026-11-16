#!/bin/bash
# T10 / #292, C-31..C-36: redirects through whatever redirect plugin the site
# has (EXPECT=redirection|rankmath|srm|yoast|aioseo|seopress|visibility):
# create in the right place, the live old address answers with the redirect,
# conflict and loop refused, chain warned, GET /redirects?source=, delete,
# rollback of create and delete, automatic 301 when a published page's slug
# changes (and its rollback), owner "redirects" switch enforced.
# EXPECT=redirection also checks the group choice and a deleted group 1 (#270).
cd "$(dirname "$0")"; . ./lib.sh
S=${SITE:-1}; EXPECT=${EXPECT:-redirection}
BASE="http://localhost:$((PORT_BASE+S))"
code() { echo "$1" | jget "d.get('errorCode')"; }
loc() { curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$BASE$1"; }
api $S POST /events/site-config-changed '{"controls":null,"allowPublish":true,"disabled":false}' >/dev/null

C=$(api $S GET /capabilities)
check "$(echo "$C" | jget "d['redirectStore']['provider']")" "$EXPECT" "capabilities: redirectStore.provider"
check "$(echo "$C" | jget "'redirects.manage' in d['features'] and 'redirects.auto' in d['features']")" "True" "capabilities: redirects.manage + redirects.auto"
if [ "$EXPECT" = visibility ]; then check "$(echo "$C" | jget "d['redirects']")" "None" "no redirect plugin: legacy redirects null"; fi

N=$RANDOM
# A real page as target, so the redirect lands somewhere.
T=$($WP $S post create --post_type=page --post_status=publish --post_title="T10 target $N" --post_name=t10-target-$N --porcelain | tr -d '\r' | tail -1)
R=$(action $S redirect.create "{\"source\":\"/t10-old-$N/\",\"target\":\"/t10-target-$N/\",\"type\":301}")
check "$(echo "$R" | jget "d['ok']")" "True" "redirect.create ok"
check "$(echo "$R" | jget "d['result']['provider']")" "$EXPECT" "created in $EXPECT"
ID=$(echo "$R" | jget "d['result']['redirectId']"); CH=$(echo "$R" | jget "d['result']['changeId']")
check "$( [ -n "$CH" ] && [ "$CH" != None ] && echo y)" "y" "redirect.create returns changeId"
sleep 1
check "$(loc /t10-old-$N/)" "301 $BASE/t10-target-$N/" "live: old address 301s to the target"
L=$(api $S GET "/redirects?source=/t10-old-$N")
check "$(echo "$L" | jget "list((i['id'],i['target'],i['type'],i['provider']) for i in d['items'])")" "[('$ID', '/t10-target-$N/', 301, '$EXPECT')]" "GET /redirects?source= finds it"

R=$(action $S redirect.create "{\"source\":\"/t10-old-$N\",\"target\":\"/elsewhere/\"}")
check "$(code "$R")" "conflict" "same source again -> conflict"
R=$(action $S redirect.create "{\"source\":\"/t10-target-$N/\",\"target\":\"/t10-old-$N/\"}")
check "$(code "$R")" "invalid" "loop A->B->A refused"
echo "  $(echo "$R" | jget "d['error']")"
R=$(action $S redirect.create "{\"source\":\"/t10-self-$N/\",\"target\":\"/t10-self-$N\"}")
check "$(code "$R")" "invalid" "source == target refused"
R=$(action $S redirect.create "{\"source\":\"/t10-chain-$N/\",\"target\":\"/t10-old-$N/\"}")
check "$(echo "$R" | jget "d['ok'] and 'loopWarning' in d['result']")" "True" "chain: created with a loopWarning"
CID=$(echo "$R" | jget "d['result']['redirectId']")

# Delete + rollback of the delete (redirect comes back) + rollback of a create.
R=$(action $S redirect.delete "{\"redirectId\":\"$CID\"}")
check "$(echo "$R" | jget "d['result']['deleted']['source'].rstrip('/')")" "/t10-chain-$N" "redirect.delete ok, says what was deleted"
DCH=$(echo "$R" | jget "d['result']['changeId']")
check "$(api $S GET "/redirects?source=/t10-chain-$N/" | jget "d['total']")" "0" "deleted: gone from the list"
R=$(api $S POST /changes/$DCH/rollback)
check "$(echo "$R" | jget "d['ok']")" "True" "rollback of the delete"
check "$(api $S GET "/redirects?source=/t10-chain-$N/" | jget "list(i['target'] for i in d['items'])")" "['/t10-old-$N/']" "the deleted redirect is back"
R=$(api $S POST /changes/$CH/rollback)
check "$(echo "$R" | jget "d['ok']")" "True" "rollback of the create"
check "$(api $S GET "/redirects?source=/t10-old-$N/" | jget "d['total']")" "0" "created redirect removed by rollback"
check "$(api $S POST /changes/$CH/rollback | jget "d['code']")" "visibility_already_rolled_back" "second rollback refused"

# Automatic 301 when a published page's address changes.
P=$($WP $S post create --post_type=page --post_status=publish --post_title="T10 page $N" --post_name=t10-page-$N --porcelain | tr -d '\r' | tail -1)
R=$(action $S page.update_content "{\"postId\":$P,\"fields\":{\"slug\":\"t10-moved-$N\"}}")
check "$(echo "$R" | jget "d['ok']")" "True" "page slug change ok"
check "$(echo "$R" | jget "(d['result']['redirectAdded']['source'].rstrip('/'), d['result']['redirectAdded']['target'].rstrip('/'), d['result']['redirectAdded']['type'])")" "('/t10-page-$N', '/t10-moved-$N', 301)" "redirectAdded old -> new (301)"
check "$(echo "$R" | jget "d['result']['slugChanged']['redirect']")" "$EXPECT" "slugChanged.redirect names the store"
PCH=$(echo "$R" | jget "d['result']['changeId']")
sleep 1
check "$(loc /t10-page-$N/)" "301 $BASE/t10-moved-$N/" "live: old page address 301s to the new one"
R=$(api $S POST /changes/$PCH/rollback)
check "$(echo "$R" | jget "d['ok']")" "True" "rollback of the slug change"
check "$($WP $S post get $P --field=post_name | tr -d '\r')" "t10-page-$N" "slug restored"
check "$(api $S GET "/redirects?source=/t10-page-$N/" | jget "d['total']")" "0" "rollback also removed the automatic redirect"
check "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/t10-page-$N/")" "200" "old address is the page again"
# Draft: no redirect.
D=$($WP $S post create --post_type=page --post_status=draft --post_title="T10 draft $N" --post_name=t10-draft-$N --porcelain | tr -d '\r' | tail -1)
R=$(action $S page.update_content "{\"postId\":$D,\"fields\":{\"slug\":\"t10-draft2-$N\"}}")
check "$(echo "$R" | jget "'redirectAdded' in d['result'] or 'redirectSkipped' not in d['result']")" "False" "draft slug change: redirectSkipped, nothing added"
# Rename and back: the redirect on the new address is removed so nothing loops.
R=$(action $S page.update_content "{\"postId\":$P,\"fields\":{\"slug\":\"t10-b-$N\"}}")
R=$(action $S page.update_content "{\"postId\":$P,\"fields\":{\"slug\":\"t10-page-$N\"}}")
check "$(echo "$R" | jget "(d['result'].get('redirectRemoved',{}).get('source','').rstrip('/'), d['result']['redirectAdded']['source'].rstrip('/'))")" "('/t10-page-$N', '/t10-b-$N')" "rename back: old rule removed, new one added"
sleep 1
check "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/t10-page-$N/")" "200" "renamed back: page answers, no loop"
# REST PATCH also adds it.
R=$(api $S PATCH /posts/$P "{\"slug\":\"t10-rest-$N\"}")
check "$(echo "$R" | jget "d['redirectAdded']['target'].rstrip('/')")" "/t10-rest-$N" "REST PATCH slug change: redirectAdded"

# Owner switch.
api $S POST /events/site-config-changed '{"controls":{"actions":["create_drafts","edit_meta","images","edit_content"],"postTypes":["post","page"],"lockedPostIds":[],"fieldAllowlist":[]}}' >/dev/null
R=$(action $S redirect.create "{\"source\":\"/t10-no-$N/\",\"target\":\"/\"}")
check "$(code "$R")" "not_allowed" "redirects not allowed by the owner -> refused"
R=$(action $S page.update_content "{\"postId\":$P,\"fields\":{\"slug\":\"t10-noredir-$N\"}}")
check "$(echo "$R" | jget "('redirectAdded' in d['result'], 'not allowed' in d['result'].get('redirectSkipped',''))")" "(False, True)" "slug change without the redirects switch: redirectSkipped says why"
api $S POST /events/site-config-changed '{"controls":null}' >/dev/null

if [ "$EXPECT" = redirection ]; then
  R=$(action $S redirect.create "{\"source\":\"/t10-g-$N/\",\"target\":\"/\"}")
  check "$(echo "$R" | jget "d['result']['redirect']['group']")" "Visibility" "Redirection: default group is 'Visibility'"
  $WP $S db query "DELETE FROM wp_redirection_groups WHERE id=1" >/dev/null 2>&1
  $WP $S option delete visibility_redirection_group >/dev/null 2>&1
  R=$(action $S redirect.create "{\"source\":\"/t10-g2-$N/\",\"target\":\"/\"}")
  check "$(echo "$R" | jget "(d['ok'], d['result']['redirect']['group'])")" "(True, 'Visibility')" "group 1 deleted: still created, in the Visibility group (#270)"
  R=$(action $S redirect.create "{\"source\":\"/t10-g3-$N/\",\"target\":\"/\",\"group\":\"Modified Posts\"}")
  check "$(echo "$R" | jget "d['result']['redirect']['group']")" "Modified Posts" "group chosen by name"
  R=$(action $S redirect.create "{\"source\":\"/t10-g4-$N/\",\"target\":\"/\",\"group\":999}")
  check "$(code "$R")" "invalid" "unknown group id refused"
  check "$(api $S GET /redirects | jget "'Visibility' in [g['name'] for g in d['groups']]")" "True" "GET /redirects lists Redirection groups"
fi
if [ "$EXPECT" = visibility ]; then
  # Built-in store: never hides a real page.
  R=$(action $S redirect.create "{\"source\":\"/t10-target-$N/\",\"target\":\"/\"}")
  sleep 1
  check "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/t10-target-$N/")" "200" "built-in store: a real page is not redirected"
  R=$(action $S redirect.create "{\"source\":\"/t10-gone-$N/\",\"type\":410}")
  check "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/t10-gone-$N/")" "410" "built-in store: 410 gone"
fi
summary
