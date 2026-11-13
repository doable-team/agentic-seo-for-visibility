#!/bin/bash
# T11 / #276: editing one block must leave a Custom HTML block (iframe, form,
# script) byte-identical.
cd "$(dirname "$0")"; . ./lib.sh
S=1
HTML='<!-- wp:paragraph -->
<p>First paragraph.</p>
<!-- /wp:paragraph -->

<!-- wp:html -->
<iframe src="https://www.youtube.com/embed/abc123" width="560" height="315" allowfullscreen></iframe>
<form action="https://example.com/subscribe" method="post" data-track="nl"><input type="email" name="e"><button>Go</button></form>
<script>window.dataLayer=window.dataLayer||[];dataLayer.push({"event":"x"});</script>
<svg width="10" height="10"><circle cx="5" cy="5" r="4"/></svg>
<!-- /wp:html -->

<!-- wp:paragraph -->
<p>Second paragraph.</p>
<!-- /wp:paragraph -->'
ID=$(printf '%s' "$HTML" | $WP $S post create - --post_type=page --post_status=publish --post_title="T11 embeds" --user=admin --porcelain | tr -d '\r')
BEFORE=$($WP $S post get $ID --field=post_content)
check "$BEFORE" "$HTML" "fixture saved verbatim"
NEW=$(printf '%s' "$HTML" | python3 -c "import sys,json; s=sys.stdin.read().replace('<p>Second paragraph.</p>','<p>Second paragraph, <strong>rewritten</strong> <img src=x onerror=alert(1)>.</p>'); print(json.dumps(s))")
R=$(action $S page.update_content "{\"postId\":$ID,\"fields\":{\"content\":$NEW}}")
check "$(echo "$R" | jget "['ok']")" "True" "page.update_content ok"
AFTER=$($WP $S post get $ID --field=post_content)
blk() { python3 -c "import sys,re; s=sys.stdin.read(); print(re.search(r'<!-- wp:html -->.*?<!-- /wp:html -->',s,re.S).group(0))"; }
check "$(echo "$AFTER" | blk)" "$(echo "$HTML" | blk)" "Custom HTML block byte-identical after editing another block"
case "$AFTER" in *"<strong>rewritten</strong>"*) ok "edit applied";; *) bad "edit not applied";; esac
case "$AFTER" in *onerror*) bad "new markup not sanitised";; *) ok "new markup sanitised (onerror removed)";; esac
# Same through PATCH /posts/{id}
NEW2=$(echo "$AFTER" | python3 -c "import sys,json; print(json.dumps({'content':sys.stdin.read().replace('First paragraph.','First paragraph (patched).')}))")
api $S PATCH /posts/$ID "$NEW2" >/dev/null
AFTER2=$($WP $S post get $ID --field=post_content)
check "$(echo "$AFTER2" | blk)" "$(echo "$HTML" | blk)" "PATCH /posts keeps Custom HTML block byte-identical"
case "$AFTER2" in *"(patched)"*) ok "PATCH edit applied";; *) bad "PATCH edit not applied";; esac
summary
