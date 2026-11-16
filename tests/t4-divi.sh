#!/bin/bash
# T4 / #335 Divi with the REAL Divi theme (4.x) + All in One SEO: a Divi page
# reports builder:divi with its modules; text, heading, blurb title, image alt
# and button text/link edits change only that module and every shortcode
# attribute stays byte-identical; the front end (rendered by Divi) shows them;
# the page still opens in the Divi Builder (admin, et_fb=1); rollback.
cd "$(dirname "$0")"; . ./lib.sh
S=${SITE:-2}
code() { echo "$1" | jget "d.get('errorCode')"; }
BASE="http://localhost:$((PORT_BASE+S))"
IMG=$(api $S POST /media '{"source_url":"http://localhost/vis-test-og.png","title":"T4 img"}' | jget "['id']")
IMGURL=$($WP $S eval "echo wp_get_attachment_url($IMG);" | tr -d '\r')
read -r -d '' BODY <<EOF
[et_pb_section fb_built="1" _builder_version="4.21.0" _module_preset="default" background_color="#f4f4f4"][et_pb_row _builder_version="4.21.0" column_structure="1_2,1_2"][et_pb_column type="1_2" _builder_version="4.21.0"][et_pb_text _builder_version="4.21.0" text_font_size="18px" custom_margin="||20px||false|false" hover_enabled="0" sticky_enabled="0"]<h2>Old Divi heading</h2>
<p>Old Divi paragraph about our service.</p>[/et_pb_text][et_pb_blurb title="Old blurb title" url="/old-blurb/" image="$IMGURL" alt="Old blurb alt" _builder_version="4.21.0" header_level="h3"]<p>Blurb body text.</p>[/et_pb_blurb][/et_pb_column][et_pb_column type="1_2" _builder_version="4.21.0"][et_pb_image src="$IMGURL" alt="Old image alt" title_text="Old image title" _builder_version="4.21.0" align="center"][/et_pb_image][et_pb_button button_url="https://example.com/old" button_text="Old button" _builder_version="4.21.0" custom_button="on" button_bg_color="#0c71c3"][/et_pb_button][et_pb_text _builder_version="4.21.0"]<p>Untouched Divi text.</p>[/et_pb_text][/et_pb_column][/et_pb_row][/et_pb_section]
EOF
P=$($WP $S post create --post_type=page --post_status=publish --post_title="T4 Divi $RANDOM" --porcelain - <<<"$BODY" | tr -d '\r' | tail -1)
$WP $S post meta update $P _et_pb_use_builder on >/dev/null
$WP $S post meta update $P _et_pb_page_layout et_full_width_page >/dev/null
ORIG=$($WP $S post get $P --field=post_content)
URL=$($WP $S post url $P | tr -d '\r')
check "$(curl -sL "$URL" | grep -c 'Old Divi paragraph about our service.')" "1" "Divi renders the page before the edit"

C=$(api $S GET /capabilities)
check "$(echo "$C" | jget "list((b['slug'],b['version'],b['writable']) for b in d['builders'])")" "[('divi', '4.21.0', True)]" "capabilities: Divi 4.21.0 writable (real Divi)"
R=$(api $S GET "/posts/$P?include=blocks,builder")
check "$(echo "$R" | jget "d['editor']")" "builder:divi" "editor = builder:divi"
check "$(echo "$R" | jget "list(e['elementId'] for e in d['elements'] if e['editable'])")" "['et_pb_text-0', 'et_pb_blurb-0', 'et_pb_image-0', 'et_pb_button-0', 'et_pb_text-1']" "modules with ids (sections/rows/columns not editable)"
check "$(echo "$R" | jget "next(e for e in d['elements'] if e['elementId']=='et_pb_image-0')['attrs']['alt']")" "Old image alt" "image alt read"
M=$(echo "$R" | jget "d['modifiedGmt']")

R=$(action $S page.edit_parts "{\"postId\":$P,\"modifiedGmt\":\"$M\",\"edits\":[
 {\"target\":{\"elementId\":\"et_pb_text-0\"},\"op\":\"replace_text\",\"find\":\"Old Divi paragraph about our service.\",\"text\":\"New Divi paragraph, \\\"quoted\\\" & clear.\"},
 {\"target\":{\"elementId\":\"et_pb_blurb-0\"},\"op\":\"replace_text\",\"field\":\"title\",\"text\":\"New blurb \\\"title\\\"\"},
 {\"target\":{\"elementId\":\"et_pb_image-0\"},\"op\":\"set_attr\",\"attrs\":{\"alt\":\"New image alt\"}},
 {\"target\":{\"elementId\":\"et_pb_button-0\"},\"op\":\"replace_text\",\"field\":\"button_text\",\"text\":\"New button\"},
 {\"target\":{\"elementId\":\"et_pb_button-0\"},\"op\":\"set_attr\",\"attrs\":{\"href\":\"https://example.com/new\"}}]}")
check "$(echo "$R" | jget "d['ok']")" "True" "edit_parts ok"
CH=$(echo "$R" | jget "d['result']['changeId']")
NOW=$($WP $S post get $P --field=post_content)
EXP=$(python3 - "$ORIG" <<'PY'
import sys
s=sys.argv[1]
s=s.replace('<p>Old Divi paragraph about our service.</p>','<p>New Divi paragraph, "quoted" &amp; clear.</p>')
s=s.replace('title="Old blurb title"','title="New blurb %22title%22"')
s=s.replace('alt="Old image alt"','alt="New image alt"')
s=s.replace('button_text="Old button"','button_text="New button"')
s=s.replace('button_url="https://example.com/old"','button_url="https://example.com/new"')
print(s, end='')
PY
)
check "$([ "$NOW" = "$EXP" ] && echo same || diff <(echo "$EXP") <(echo "$NOW"))" "same" "only the edited text/attributes changed; every other shortcode attribute byte-identical"
PAGE=$(curl -sL "$URL")
check "$(echo "$PAGE" | grep -c 'New Divi paragraph, &#8220;quoted&#8221; &amp; clear.')" "1" "front end (Divi): text"
check "$(echo "$PAGE" | grep -c 'New blurb &#8220;title&#8221;\|New blurb &quot;title&quot;\|New blurb "title"')" "1" "front end (Divi): blurb title with quotes"
check "$(echo "$PAGE" | grep -c 'alt="New image alt"')" "1" "front end (Divi): image alt"
check "$(echo "$PAGE" | grep -o 'href="https://example.com/new"[^>]*>New button' | wc -l | tr -d ' ')" "1" "front end (Divi): button text + link"
check "$(echo "$PAGE" | grep -c 'Untouched Divi text.')" "1" "front end: other module untouched"

# Still opens in the Divi Builder: log in and load the Visual Builder.
J=$(mktemp)
curl -s -c $J -b $J -o /dev/null "$BASE/wp-login.php"
curl -s -c $J -b $J -o /dev/null -d "log=admin&pwd=admin&wp-submit=Log+In&testcookie=1" "$BASE/wp-login.php"
FB=$(curl -s -b $J "$URL?et_fb=1&PageSpeed=off")
check "$(echo "$FB" | grep -c 'et-fb-app\|et_fb_\|ETBuilderBackend' | awk '{print ($1>0)?"yes":"no"}')" "yes" "Divi Visual Builder loads the page"
rm -f $J

R=$(action $S page.edit_parts "{\"postId\":$P,\"edits\":[{\"target\":{\"elementId\":\"et_pb_row-0\"},\"op\":\"replace_text\",\"text\":\"x\"}]}")
check "$(code "$R")" "unsupported" "a row refused"
R=$(action $S page.edit_parts "{\"postId\":$P,\"edits\":[{\"target\":{\"elementId\":\"et_pb_text-1\"},\"op\":\"replace_text\",\"text\":\"[et_pb_text]x\"}]}")
check "$(code "$R")" "invalid" "square brackets in text refused"
R=$(action $S page.edit_parts "{\"postId\":$P,\"edits\":[{\"target\":{\"path\":\"0\"},\"op\":\"replace_text\",\"text\":\"x\"}]}")
check "$(code "$R")" "invalid" "a block path on a Divi page refused"

R=$(api $S POST /changes/$CH/rollback); echo "$R" | grep -q "\"ok\":true" || echo "rollback reply: $R"
check "$(echo "$R" | jget "d['ok']")" "True" "rollback ok"
check "$($WP $S post get $P --field=post_content)" "$ORIG" "rollback: content byte-identical"
# SEO on the Divi page (AIOSEO when active).
if [ "$(echo "$C" | jget "d['seo']['plugin']")" = "aioseo" ]; then
  R=$(action $S seo_meta.update "{\"postId\":$P,\"fields\":{\"metaTitle\":\"T4 Divi AIOSEO title\"}}")
  check "$(echo "$R" | jget "d['ok']")" "True" "AIOSEO title on the Divi page"
  check "$(curl -sL "$URL" | grep -c '<title>T4 Divi AIOSEO title</title>')" "1" "front end: AIOSEO title"
fi

summary
