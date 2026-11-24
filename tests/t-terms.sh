#!/bin/bash
# #470 (2.1.1): post.update_terms adds / removes / replaces categories and tags on
# an existing post (rolled back with its change), term.create makes a new one
# (undone by deleting it while unused); owner controls and locked pages apply.
cd "$(dirname "$0")"; . ./lib.sh
S=${SITE:-1}
for o in visibility_controls visibility_allow_publish visibility_disabled visibility_paused_wp; do $WP $S option delete $o >/dev/null 2>&1; done
P=$($WP $S post create --post_status=publish --post_title="Terms $RANDOM" --porcelain | tr -d '\r' | tail -1)
A=$($WP $S term create category "Terms A $RANDOM" --porcelain | tr -d '\r' | tail -1)
B=$($WP $S term create category "Terms B $RANDOM" --porcelain | tr -d '\r' | tail -1)
$WP $S post term set $P category $A --by=id >/dev/null
cats() { $WP $S post term list $P category --field=term_id | tr -d '\r' | sort -n | tr '\n' ' ' | sed 's/ $//'; }

R=$(action $S post.update_terms "{\"postId\":$P,\"taxonomy\":\"category\",\"add\":[$B],\"remove\":[$A]}")
check "$(echo "$R" | jget "['ok']")" "True" "swap category ok"
check "$(cats)" "$B" "post now in B only"
check "$(echo "$R" | jget "str([t['id'] for t in d['result']['before']])")" "[$A]" "result has before"
CID=$(echo "$R" | jget "d['result']['changeId']")
R=$(action $S post.update_terms "{\"postId\":$P,\"taxonomy\":\"category\",\"add\":[99999999]}")
check "$(echo "$R" | jget "d.get('errorCode')")" "not_found" "unknown term id refused"
R=$(action $S post.update_terms "{\"postId\":$P,\"taxonomy\":\"genre\",\"add\":[$A]}")
check "$(echo "$R" | jget "d.get('errorCode')")" "invalid" "unknown taxonomy refused"
R=$(action $S change.rollback "{\"changeId\":$CID}")
check "$(echo "$R" | jget "['ok']")" "True" "undo the swap"
check "$(cats)" "$A" "post back in A only"

# term.create + undo
N="Brand new $RANDOM"
R=$(action $S term.create "{\"taxonomy\":\"category\",\"name\":\"$N\",\"parentId\":$A}")
check "$(echo "$R" | jget "['ok']")" "True" "term.create ok"
T=$(echo "$R" | jget "d['result']['termId']"); TC=$(echo "$R" | jget "d['result']['changeId']")
check "$($WP $S term get category $T --field=parent | tr -d '\r')" "$A" "created under its parent"
R=$(action $S term.create "{\"taxonomy\":\"category\",\"name\":\"$N\",\"parentId\":$A}")
check "$(echo "$R" | jget "d.get('errorCode')")" "exists" "same name again refused"
R=$(action $S term.create "{\"taxonomy\":\"post_tag\",\"name\":\"x\",\"parentId\":$A}")
check "$(echo "$R" | jget "d.get('errorCode')")" "invalid" "tag with a parent refused"
R=$(action $S change.rollback "{\"changeId\":$TC}")
check "$(echo "$R" | jget "['ok']")" "True" "undo term.create"
check "$($WP $S term get category $T --field=term_id 2>/dev/null | tr -d '\r')" "" "term deleted"
# Undo refused once a post uses it.
R=$(action $S term.create "{\"taxonomy\":\"post_tag\",\"name\":\"Used $RANDOM\"}"); T2=$(echo "$R" | jget "d['result']['termId']"); TC2=$(echo "$R" | jget "d['result']['changeId']")
action $S post.update_terms "{\"postId\":$P,\"taxonomy\":\"post_tag\",\"add\":[$T2]}" >/dev/null
R=$(action $S change.rollback "{\"changeId\":$TC2}")
check "$(echo "$R" | jget "d.get('errorCode')")" "conflict" "undo refused while a post uses the new tag"

# Owner controls.
api $S POST /events/site-config-changed "{\"controls\":{\"actions\":[\"create_drafts\"],\"postTypes\":[\"post\",\"page\"],\"lockedPostIds\":[],\"fieldAllowlist\":[]}}" >/dev/null
R=$(action $S post.update_terms "{\"postId\":$P,\"taxonomy\":\"category\",\"add\":[$A]}")
check "$(echo "$R" | jget "d.get('errorCode')")" "not_allowed" "edit meta tags off: update_terms refused"
R=$(action $S term.create "{\"taxonomy\":\"category\",\"name\":\"Nope $RANDOM\"}")
check "$(echo "$R" | jget "d.get('errorCode')")" "not_allowed" "edit meta tags off: term.create refused"
api $S POST /events/site-config-changed "{\"controls\":{\"actions\":[\"edit_meta\"],\"postTypes\":[\"post\",\"page\"],\"lockedPostIds\":[$P],\"fieldAllowlist\":[]}}" >/dev/null
R=$(action $S post.update_terms "{\"postId\":$P,\"taxonomy\":\"category\",\"add\":[$A]}")
check "$(echo "$R" | jget "d.get('errorCode')")" "locked" "locked post refused"
api $S POST /events/site-config-changed "{\"controls\":{\"actions\":[\"edit_meta\"],\"postTypes\":[\"page\"],\"lockedPostIds\":[],\"fieldAllowlist\":[]}}" >/dev/null
R=$(action $S term.create "{\"taxonomy\":\"category\",\"name\":\"Nope $RANDOM\"}")
check "$(echo "$R" | jget "d.get('errorCode')")" "not_allowed" "posts not allowed: blog category create refused"
api $S POST /events/site-config-changed '{"controls":null}' >/dev/null
check "$(api $S GET /capabilities | jget "'terms.assign' in d['features'] and 'terms.create' in d['features']")" "True" "features list terms.assign and terms.create"
summary
