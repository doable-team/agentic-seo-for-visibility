#!/bin/bash
# #275: new content from Visibility gets the default author (site admin by
# default, changeable in the settings page); edits keep the author; a deleted
# chosen user falls back to the first administrator with a notice.
cd "$(dirname "$0")"; . ./lib.sh
S=${SITE:-1}
$WP $S option delete visibility_default_author >/dev/null 2>&1; $WP $S option delete visibility_paired_by >/dev/null 2>&1
ADMIN=$($WP $S user get admin --field=ID)
author() { $WP $S post get $1 --field=post_author; }

R=$(action $S post.create_draft '{"title":"T275 default"}'); P1=$(echo "$R" | jget "d['result']['postId']")
check "$(author $P1)" "$ADMIN" "draft from an agent: author = site admin"
P2=$(api $S POST /posts '{"title":"T275 rest","type":"page"}' | jget "['id']")
check "$(author $P2)" "$ADMIN" "POST /posts page: author = site admin"
check "$(api $S GET /capabilities | jget "d['defaultAuthor']['id']")" "$ADMIN" "capabilities.defaultAuthor = admin"
PAGE=$(curl -sL "http://localhost:818$S/?p=$P1&preview=true" -o /dev/null; api $S GET "/posts/$P1" | jget "d['author']['name']")
check "$PAGE" "admin" "read: author name shown"

# Pick an editor through the settings form (logged in as admin).
ED=$($WP $S user get t275editor --field=ID 2>/dev/null || $WP $S user create t275editor t275editor@example.com --role=editor --display_name="Ed Itor" --porcelain)
ED=$(echo "$ED" | tr -d '\r' | tail -1)
CJ=$(mktemp); U="http://localhost:818$S"
curl -s -c $CJ -b $CJ -o /dev/null $U/wp-login.php
curl -s -c $CJ -b $CJ -b 'wordpress_test_cookie=WP+Cookie+check' -o /dev/null -d 'log=admin&pwd=admin&wp-submit=Log+In&testcookie=1' $U/wp-login.php
H=$(curl -s -b $CJ "$U/wp-admin/admin.php?page=visibility")
grep -q 'Default author for content from Visibility' <<<"$H" && ok "settings page shows the dropdown" || bad "dropdown missing"
grep -q "<option value=\"$ED\"" <<<"$H" && ok "editor is in the dropdown" || bad "editor not listed"
NONCE=$(grep -A3 'visibility_author' <<<"$H" | grep -oE 'name="_wpnonce" value="[a-f0-9]+"' | head -1 | grep -oE '[a-f0-9]{10}')
LOC=$(curl -s -b $CJ -o /dev/null -w '%{redirect_url}' -d "action=visibility_author&visibility_default_author=$ED&_wpnonce=$NONCE" $U/wp-admin/admin-post.php)
case "$LOC" in *author_saved*) ok "dropdown saved";; *) bad "save failed: $LOC";; esac
R=$(action $S page.create_draft '{"title":"T275 after change"}'); P3=$(echo "$R" | jget "d['result']['postId']")
check "$(author $P3)" "$ED" "after the dropdown change: new draft by the chosen user"

# Editing never changes the author.
action $S post.update_content "{\"postId\":$P1,\"fields\":{\"title\":\"T275 edited\"}}" >/dev/null
api $S PATCH /posts/$P1 '{"content":"<p>edited</p>"}' >/dev/null
check "$(author $P1)" "$ADMIN" "editing an existing post keeps its author"

# Chosen user deleted -> first administrator + notice.
$WP $S user delete $ED --reassign=$ADMIN --yes >/dev/null
R=$(action $S post.create_draft '{"title":"T275 fallback"}'); P4=$(echo "$R" | jget "d['result']['postId']")
check "$(author $P4)" "$ADMIN" "chosen user deleted: falls back to the first administrator"
grep -q 'no longer exists' <<<"$(curl -s -b $CJ "$U/wp-admin/admin.php?page=visibility")" && ok "settings page shows the fallback notice" || bad "no fallback notice"
check "$(api $S GET /capabilities | jget "d['defaultAuthor']['source']")" "fallback" "capabilities says source=fallback"
$WP $S option delete visibility_default_author >/dev/null
rm -f $CJ
summary
