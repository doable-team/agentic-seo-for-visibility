#!/bin/bash
# #286 shared part: a builder page reports editor builder:<slug> and
# writable; without a writing adapter post.edit_parts is refused plainly
# (unsupported) and post_content is untouched. A test adapter registered via
# the visibility_builder_adapters filter makes the same page writable and
# receives the edits by elementId.
cd "$(dirname "$0")"; . ./lib.sh
S=${SITE:-6}
P=$($WP $S post create --post_type=page --post_status=publish --post_title="T286 builder $RANDOM" --post_content='<p>fallback</p>' --porcelain | tr -d '\r' | tail -1)
$WP $S post meta update $P _elementor_edit_mode builder >/dev/null
$WP $S post meta update $P _elementor_data '[{"id":"aa11","elType":"widget","widgetType":"heading","settings":{"title":"Hi"}}]' >/dev/null
docker exec viswp-$S rm -f /var/www/html/wp-content/mu-plugins/vis-test-builder.php
R=$(api $S GET "/posts/$P?include=blocks,builder")
check "$(echo "$R" | jget "d['editor']")" "builder:elementor" "editor = builder:elementor"
W=$(echo "$R" | jget "d['builder']['writable']")
if [ "$W" = "False" ]; then
  R=$(action $S page.edit_parts "{\"postId\":$P,\"edits\":[{\"target\":{\"elementId\":\"aa11\"},\"op\":\"replace_text\",\"text\":\"x\"}]}")
  check "$(echo "$R" | jget "d['errorCode']")" "unsupported" "not writable: edit_parts refused as unsupported"
  check "$($WP $S post get $P --field=post_content)" "<p>fallback</p>" "post_content untouched"
else
  ok "Elementor adapter present: builder writable (checked in t3-elementor.sh)"
fi
summary
