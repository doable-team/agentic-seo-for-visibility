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
PAGE=$(curl -sL "http://localhost:818$S/?page_id=$ID")
grep -q '<p>Second paragraph, edited by an agent.</p>' <<<"$PAGE" && ok "wpautop paragraph on the page" || bad "wpautop paragraph missing"
grep -q 'youtube.com/embed/xyz' <<<"$PAGE" && ok "embed on the page" || bad "embed missing"
check "$(api $S GET "/posts/$ID" | jget "['editor']")" "classic" "editor = classic"

# #285: post.edit_parts on classic content targets one paragraph by path.
C2='Intro with [gallery ids="1,2"] shortcode.

Paragraph to change with <a href="https://example.com/x">a link</a>.

<h2>Old classic heading</h2>
<img class="alignnone wp-image-9" src="https://example.com/i.png" alt="old" />

<iframe src="https://www.youtube.com/embed/xyz" width="560" height="315"></iframe>

[contact-form-7 id="12" title="Contact"]

<p>Explicit paragraph.</p>'
ID2=$(printf '%s' "$C2" | $WP $S post create - --post_type=page --post_status=publish --post_title="T2 parts $RANDOM" --porcelain | tail -1)
B=$(api $S GET "/posts/$ID2?include=blocks")
check "$(echo "$B" | jget "list(b['path']+'='+b['text'][:12] for b in d['blocks'])")" "['p.0=Intro with [', 'p.1=Paragraph to', 'a.0=a link', 'h2.0=Old classic ', 'img.0=', 'p.2=Explicit par']" "classic read: paths for paragraphs, link, heading, image"
R=$(action $S page.edit_parts "{\"postId\":$ID2,\"edits\":[{\"target\":{\"path\":\"p.1\"},\"op\":\"replace_text\",\"text\":\"Paragraph changed by an agent.\"},{\"target\":{\"path\":\"h2.0\"},\"op\":\"replace_text\",\"text\":\"New classic heading\"},{\"target\":{\"path\":\"img.0\"},\"op\":\"set_attr\",\"attrs\":{\"alt\":\"Clear alt\"}},{\"target\":{\"path\":\"p.2\"},\"op\":\"replace_text\",\"text\":\"Explicit, edited.\"}]}")
check "$(echo "$R" | jget "['ok']")" "True" "classic edit_parts ok"
EXP2=$(printf '%s' "$C2" | python3 -c "import sys; s=sys.stdin.read(); s=s.replace('Paragraph to change with <a href=\"https://example.com/x\">a link</a>.','Paragraph changed by an agent.').replace('Old classic heading','New classic heading').replace('alt=\"old\"','alt=\"Clear alt\"').replace('<p>Explicit paragraph.</p>','<p>Explicit, edited.</p>'); sys.stdout.write(s)")
check "$($WP $S post get $ID2 --field=post_content)" "$EXP2" "classic: only the targets changed; shortcodes, blank lines, iframe byte-identical, no <p> added"
PAGE=$(curl -sL "http://localhost:818$S/?page_id=$ID2")
grep -q '<p>Paragraph changed by an agent.</p>' <<<"$PAGE" && ok "front end: wpautop paragraph" || bad "front end paragraph"
grep -q 'alt="Clear alt"' <<<"$PAGE" && ok "front end: alt" || bad "front end alt"
summary
