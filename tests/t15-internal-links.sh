#!/bin/bash
# T15 / #266: internal_link.suggest links a phrase once, never inside an
# existing link, heading or code; reports an error (and saves nothing) when
# no link could be added.
cd "$(dirname "$0")"; . ./lib.sh
S=${SITE:-1}
TGT=$($WP $S post create --post_status=publish --post_title="Target $RANDOM" --porcelain | tr -d '\r' | tail -1)
URL=$($WP $S post url $TGT)
HTML='<!-- wp:heading -->
<h2>Keyword research basics</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>See the <a href="https://example.com/">keyword research guide</a>. Run <code>keyword research</code>. Good keyword research saves time. More keyword research later.</p>
<!-- /wp:paragraph -->'
ID=$(printf '%s' "$HTML" | $WP $S post create - --post_status=publish --post_title="T15 source" --porcelain | tr -d '\r' | tail -1)
REVS0=$($WP $S post list --post_type=revision --post_parent=$ID --format=count)
R=$(action $S internal_link.suggest "{\"postId\":$ID,\"suggestions\":[{\"phrase\":\"keyword research\",\"targetPostId\":$TGT},{\"phrase\":\"missing phrase\",\"targetPostId\":$TGT}]}")
check "$(echo "$R" | jget "['ok']")" "True" "ok"
check "$(echo "$R" | jget "['result']['linksApplied']")" "1" "linksApplied = 1"
AFTER=$($WP $S post get $ID --field=post_content)
check "$(grep -o "href=\"$URL\"" <<<"$AFTER" | wc -l | tr -d ' ')" "1" "phrase linked exactly once"
grep -q "Good <a href=\"$URL\">keyword research</a> saves" <<<"$AFTER" && ok "linked in plain paragraph text" || bad "wrong place: $AFTER"
grep -q '<h2>Keyword research basics</h2>' <<<"$AFTER" && ok "heading untouched" || bad "heading changed"
grep -q '<a href="https://example.com/">keyword research guide</a>' <<<"$AFTER" && ok "existing link untouched" || bad "existing link changed"
grep -q '<code>keyword research</code>' <<<"$AFTER" && ok "code untouched" || bad "code changed"
# Nothing linkable -> error, nothing saved.
M1=$($WP $S post get $ID --field=post_modified_gmt)
R=$(action $S internal_link.suggest "{\"postId\":$ID,\"suggestions\":[{\"phrase\":\"nowhere to be found\",\"targetPostId\":$TGT}]}")
check "$(echo "$R" | jget "['ok']")" "False" "0 links -> ok:false"
check "$(echo "$R" | jget "['result']['linksApplied']")" "0" "0 links reported"
check "$($WP $S post get $ID --field=post_content)" "$AFTER" "0 links -> content not saved"
summary
