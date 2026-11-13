#!/bin/bash
# T2 (classic part): on a Classic Editor site an edit keeps shortcodes and the
# blank-line paragraphs wpautop turns into <p>; an embed elsewhere survives.
cd "$(dirname "$0")"; . ./lib.sh
S=${SITE:-2}
C='First paragraph with [gallery ids="1,2"] shortcode.

Second paragraph to edit.

<iframe src="https://www.youtube.com/embed/xyz" width="560" height="315"></iframe>

[contact-form-7 id="12" title="Contact"]'
ID=$(printf '%s' "$C" | $WP $S post create - --post_type=page --post_status=publish --post_title="T2 classic $RANDOM" --porcelain | tail -1)
NEW=$(printf '%s' "$C" | python3 -c "import sys,json; print(json.dumps(sys.stdin.read().replace('Second paragraph to edit.','Second paragraph, edited by an agent.')))")
R=$(action $S page.update_content "{\"postId\":$ID,\"fields\":{\"content\":$NEW}}")
check "$(echo "$R" | jget "['ok']")" "True" "classic page.update_content ok"
EXP=$(printf '%s' "$C" | sed 's/Second paragraph to edit./Second paragraph, edited by an agent./')
check "$($WP $S post get $ID --field=post_content)" "$EXP" "content is exactly the edit (shortcodes, blank lines, iframe kept)"
PAGE=$(curl -sL "http://localhost:809$S/?page_id=$ID")
grep -q '<p>Second paragraph, edited by an agent.</p>' <<<"$PAGE" && ok "wpautop paragraph on the page" || bad "wpautop paragraph missing"
grep -q 'youtube.com/embed/xyz' <<<"$PAGE" && ok "embed on the page" || bad "embed missing"
check "$(api $S GET "/posts/$ID" | jget "['editor']")" "classic" "editor = classic"
summary
