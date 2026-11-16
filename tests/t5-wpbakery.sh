#!/bin/bash
# T5 / #336 WPBakery, against a STAND-IN (tests/standins/wpbakery-standin.php,
# built from WPBakery's documented shortcodes; not the real paid plugin):
# modules read with elementIds; text, heading, image alt (media library) and
# button link edits change only that module: every other shortcode and
# attribute stays byte-identical; the front end shows the change; brackets
# refused; rollback; a page switched back to the classic editor is classic.
cd "$(dirname "$0")"; . ./lib.sh
S=${SITE:-1}
code() { echo "$1" | jget "d.get('errorCode')"; }
$CTR cp standins/wpbakery-standin.php ${CPFX}$S:/var/www/html/wp-content/mu-plugins/wpbakery-standin.php
IMG=$(api $S POST /media '{"source_url":"http://localhost/vis-test-og.png","title":"T5 img","alt_text":"Old alt"}' | jget "['id']")
[ -z "$IMG" ] || [ "$IMG" = "None" ] && IMG=$($WP $S eval 'echo wp_insert_attachment(["post_title"=>"T5 img","post_mime_type"=>"image/png"], "/var/www/html/vis-t5.png");' | tail -1)
$WP $S post meta update $IMG _wp_attachment_image_alt "Old alt" >/dev/null
read -r -d '' BODY <<EOF
[vc_row css=".vc_custom_1{margin-top: 20px !important;}"][vc_column width="1/2"][vc_custom_heading text="Old heading" font_container="tag:h2|text_align:left" link="url:%2Fold%2F|title:Old|target:_blank"][vc_column_text css_animation="fadeIn" el_class="intro"]<p>First paragraph text.</p>
<p>Second paragraph.</p>[/vc_column_text][/vc_column][vc_column width="1/2"][vc_single_image image="$IMG" img_size="medium" title="Img title"][vc_btn title="Buy now" color="green" link="url:https%3A%2F%2Fexample.com%2Fbuy|title:Buy|target:_blank"][vc_column_text]<p>Untouched text</p>[/vc_column_text][/vc_column][/vc_row]
EOF
P=$($WP $S post create --post_type=page --post_status=publish --post_title="T5 WPBakery $RANDOM" --porcelain - <<<"$BODY" | tr -d '\r' | tail -1)
$WP $S post meta update $P _wpb_vc_js_status true >/dev/null
ORIG=$($WP $S post get $P --field=post_content)

C=$(api $S GET /capabilities)
check "$(echo "$C" | jget "list((b['slug'],b['writable']) for b in d['builders'])")" "[('wpbakery', True)]" "capabilities: WPBakery writable"
R=$(api $S GET "/posts/$P?include=blocks,builder")
check "$(echo "$R" | jget "d['editor']")" "builder:wpbakery" "editor = builder:wpbakery"
check "$(echo "$R" | jget "list(e['elementId'] for e in d['elements'] if e['editable'])")" "['vc_custom_heading-0', 'vc_column_text-0', 'vc_single_image-0', 'vc_btn-0', 'vc_column_text-1']" "elements with ids"
check "$(echo "$R" | jget "next(e for e in d['elements'] if e['elementId']=='vc_single_image-0')['attrs']['alt']")" "Old alt" "image alt from the media library"
check "$(echo "$R" | jget "next(e for e in d['elements'] if e['elementId']=='vc_btn-0')['attrs']['href']")" "https://example.com/buy" "button link decoded"
M=$(echo "$R" | jget "d['modifiedGmt']")

R=$(action $S page.edit_parts "{\"postId\":$P,\"modifiedGmt\":\"$M\",\"edits\":[
 {\"target\":{\"elementId\":\"vc_column_text-0\"},\"op\":\"replace_text\",\"find\":\"First paragraph text.\",\"text\":\"First paragraph, rewritten.\"},
 {\"target\":{\"elementId\":\"vc_custom_heading-0\"},\"op\":\"replace_text\",\"field\":\"text\",\"text\":\"New heading\"},
 {\"target\":{\"elementId\":\"vc_single_image-0\"},\"op\":\"set_attr\",\"attrs\":{\"alt\":\"New alt\"}},
 {\"target\":{\"elementId\":\"vc_btn-0\"},\"op\":\"set_attr\",\"attrs\":{\"href\":\"https://example.com/shop\"}}]}")
check "$(echo "$R" | jget "d['ok']")" "True" "edit_parts ok"
check "$(echo "$R" | jget "d['result']['builder']")" "wpbakery" "result: builder"
CH=$(echo "$R" | jget "d['result']['changeId']")
NOW=$($WP $S post get $P --field=post_content)
EXP=$(python3 - "$ORIG" <<'PY'
import sys
s=sys.argv[1]
s=s.replace('<p>First paragraph text.</p>','<p>First paragraph, rewritten.</p>')
s=s.replace('text="Old heading"','text="New heading"')
s=s.replace('url:https%3A%2F%2Fexample.com%2Fbuy|','url:https%3A%2F%2Fexample.com%2Fshop|')
print(s, end='')
PY
)
check "$([ "$NOW" = "$EXP" ] && echo same || diff <(echo "$EXP") <(echo "$NOW"))" "same" "only the edited text/attributes changed; every other byte identical"
check "$($WP $S post meta get $IMG _wp_attachment_image_alt)" "New alt" "image alt stored in the media library"
PAGE=$(curl -sL "$($WP $S post url $P | tr -d '\r')")
check "$(echo "$PAGE" | grep -c 'First paragraph, rewritten.')" "1" "front end: text"
check "$(echo "$PAGE" | grep -c '>New heading<')" "1" "front end: heading"
check "$(echo "$PAGE" | grep -c 'href="https://example.com/shop"')" "1" "front end: button link"
check "$(echo "$PAGE" | grep -c 'alt="New alt"')" "1" "front end: image alt"

R=$(action $S page.edit_parts "{\"postId\":$P,\"edits\":[{\"target\":{\"elementId\":\"vc_column_text-1\"},\"op\":\"replace_text\",\"text\":\"[vc_row]bad\"}]}")
check "$(code "$R")" "invalid" "square brackets refused"
R=$(action $S page.edit_parts "{\"postId\":$P,\"edits\":[{\"target\":{\"elementId\":\"vc_row-0\"},\"op\":\"replace_text\",\"text\":\"x\"}]}")
check "$(code "$R")" "unsupported" "a layout row refused"
R=$(action $S page.edit_parts "{\"postId\":$P,\"edits\":[{\"target\":{\"elementId\":\"vc_btn-9\"},\"op\":\"replace_text\",\"text\":\"x\"}]}")
check "$(code "$R")" "not_found" "unknown element refused"
R=$(action $S page.edit_parts "{\"postId\":$P,\"edits\":[{\"target\":{\"elementId\":\"vc_custom_heading-0\"},\"op\":\"replace_text\",\"field\":\"text\",\"text\":\"Say \\\"hi\\\"\"}]}")
check "$(code "$R")" "invalid" "quotes in a WPBakery attribute refused"
check "$($WP $S post get $P --field=post_content)" "$NOW" "refusals left the page as it was"

R=$(api $S POST /changes/$CH/rollback)
check "$(echo "$R" | jget "d['ok']")" "True" "rollback ok"
check "$($WP $S post get $P --field=post_content)" "$ORIG" "rollback: content byte-identical to before"

$WP $S post meta update $P _wpb_vc_js_status false >/dev/null
check "$(api $S GET "/posts/$P" | jget "d['editor']")" "classic" "switched back to classic: editor classic"
$CTR exec ${CPFX}$S rm -f /var/www/html/wp-content/mu-plugins/wpbakery-standin.php
summary
