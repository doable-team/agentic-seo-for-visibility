#!/bin/bash
# #297 bulk alt text: GET /media?missingAlt=1 lists images without alt (with
# where they are used); media.bulk_alt sets alt (and title/caption) for many
# images at once, also inside the core/image blocks and classic <img> of the
# pages that show them (an alt written on purpose on a page is kept); the alt
# shows in the live page HTML; one changeId rolls everything back; limits and
# the owner's images switch / locked pages are enforced.
cd "$(dirname "$0")"; . ./lib.sh
S=${SITE:-1}; BASE="http://localhost:$((PORT_BASE+S))"; COUNT=${COUNT:-50}
api $S POST /events/site-config-changed '{"controls":null,"allowPublish":true,"disabled":false}' >/dev/null
N=$RANDOM
check "$(api $S GET /capabilities | jget "'media.bulk_alt' in d['features']")" "True" "capabilities: media.bulk_alt"

# COUNT images without alt (copies of one attachment file, cheap to make).
IDS=$($WP $S eval '
$ids = [];
for ($i = 0; $i < '$COUNT'; $i++) {
  $f = wp_upload_dir()["path"] . "/bulk-'"$N"'-$i.png"; copy(ABSPATH . "vis-test-og.png", $f);
  $id = wp_insert_attachment(["post_mime_type" => "image/png", "post_title" => "Bulk '"$N"' $i", "post_status" => "inherit"], $f);
  update_post_meta($id, "_wp_attachment_image_alt", "");
  $ids[] = $id;
}
echo implode(",", $ids);' | tr -d '\r' | tail -1)
FIRST=${IDS%%,*}; SECOND=$(echo $IDS | cut -d, -f2); THIRD=$(echo $IDS | cut -d, -f3)
# A block page with image 1 (twice) and image 2 with a hand-written alt; a classic page with image 1.
BP=$($WP $S post create --post_type=page --post_status=publish --post_title="Bulk blocks $N" --post_content="<!-- wp:paragraph -->
<p>Intro</p>
<!-- /wp:paragraph -->

<!-- wp:image {\"id\":$FIRST,\"sizeSlug\":\"full\"} -->
<figure class=\"wp-block-image size-full\"><img src=\"$BASE/wp-content/uploads/x.png\" alt=\"\" class=\"wp-image-$FIRST\"/></figure>
<!-- /wp:image -->

<!-- wp:image {\"id\":$SECOND,\"sizeSlug\":\"full\"} -->
<figure class=\"wp-block-image size-full\"><img src=\"$BASE/wp-content/uploads/y.png\" alt=\"Written for this page\" class=\"wp-image-$SECOND\"/></figure>
<!-- /wp:image -->

<!-- wp:image {\"id\":$FIRST} -->
<figure class=\"wp-block-image\"><img src=\"$BASE/wp-content/uploads/x.png\" class=\"wp-image-$FIRST\"/></figure>
<!-- /wp:image -->" --porcelain | tr -d '\r' | tail -1)
CP=$($WP $S post create --post_type=page --post_status=publish --post_title="Bulk classic $N" --post_content="<p>Text</p>
<img class=\"alignnone size-full wp-image-$FIRST\" src=\"$BASE/wp-content/uploads/x.png\" alt=\"\" width=\"10\" height=\"10\" />" --porcelain | tr -d '\r' | tail -1)
$WP $S post meta update $CP _thumbnail_id $THIRD >/dev/null
BEFORE_BP=$($WP $S post get $BP --field=post_content)

L=$(api $S GET "/media?missingAlt=1&per_page=100")
check "$(echo "$L" | jget "all(str(i) in [str(x['id']) for x in d['items']] for i in '$IDS'.split(','))")" "True" "missingAlt lists all $COUNT new images"
check "$(echo "$L" | jget "all(x['altText']=='' for x in d['items'])")" "True" "missingAlt: only images without alt"
check "$(echo "$L" | jget "sorted(u['postId'] for u in next(x for x in d['items'] if x['id']==$FIRST)['usedIn'])")" "$(python3 -c "print(sorted([$BP,$CP]))")" "usedIn: the pages showing image 1"
check "$(echo "$L" | jget "list(u['postId'] for u in next(x for x in d['items'] if x['id']==$THIRD)['usedIn'])")" "[$CP]" "usedIn: featured image counted"

ITEMS=$(python3 -c "import json;ids='$IDS'.split(',');print(json.dumps([{'mediaId':int(i),'altText':'Alt %s'%i} for i in ids]))")
ITEMS=$(python3 -c "import json;x=json.loads('''$ITEMS''');x[0]['title']='Hero image';x[0]['caption']='Our hero';print(json.dumps(x))")
R=$(action $S media.bulk_alt "{\"items\":$ITEMS}")
check "$(echo "$R" | jget "(d['ok'], len(d['result']['updated']), d['result']['failed'])")" "(True, $COUNT, [])" "bulk update: $COUNT images updated"
CH=$(echo "$R" | jget "d['result']['changeId']")
check "$(echo "$R" | jget "next(u for u in d['result']['updated'] if u['mediaId']==$FIRST)['blocksUpdated']")" "2" "image 1: alt updated in 2 pages"
check "$(echo "$R" | jget "next(u for u in d['result']['updated'] if u['mediaId']==$FIRST)['after']")" "{'altText': 'Alt $FIRST', 'title': 'Hero image', 'caption': 'Our hero'}" "title and caption too"
check "$(api $S GET "/media?missingAlt=1&per_page=100" | jget "any(str(x['id']) in '$IDS'.split(',') for x in d['items'])")" "False" "none of them is listed as missing any more"
H=$(curl -sL "$BASE/?page_id=$BP")
check "$(echo "$H" | grep -o "alt=\"Alt $FIRST\"[^>]*wp-image-$FIRST\|wp-image-$FIRST[^>]*alt=\"Alt $FIRST\"" | wc -l | tr -d ' ')" "2" "live block page: both copies of image 1 carry the new alt"
check "$(echo "$H" | grep -c 'alt="Written for this page"')" "1" "hand-written alt on the page kept"
check "$(curl -sL "$BASE/?page_id=$CP" | grep -c "alt=\"Alt $FIRST\"")" "1" "live classic page: new alt"
AFTER_BP=$($WP $S post get $BP --field=post_content)
check "$(diff <(echo "$BEFORE_BP" | sed "s/ alt=\"Alt $FIRST\"//; s/alt=\"\" class=\"wp-image-$FIRST\"/class=\"wp-image-$FIRST\"/") <(echo "$AFTER_BP" | sed "s/ alt=\"Alt $FIRST\"//; s/alt=\"Alt $FIRST\" class=\"wp-image-$FIRST\"/class=\"wp-image-$FIRST\"/") >/dev/null && echo same)" "same" "block page: nothing but the alt changed"
check "$(api $S GET /posts/$BP?include=blocks | jget "d['blocks'][1]['blockName']")" "core/image" "blocks still parse as core/image"

R=$(api $S POST /changes/$CH/rollback)
check "$(echo "$R" | jget "d['ok']")" "True" "rollback of the batch"
check "$($WP $S post meta get $FIRST _wp_attachment_image_alt | tr -d '\r')" "" "image 1 alt back to empty"
check "$($WP $S post get $FIRST --field=post_title | tr -d '\r')" "Bulk $N 0" "title back"
check "$([ "$($WP $S post get $BP --field=post_content)" = "$BEFORE_BP" ] && echo same)" "same" "block page content byte-identical to before"
check "$(api $S GET "/media?missingAlt=1&per_page=100" | jget "sum(1 for x in d['items'] if str(x['id']) in '$IDS'.split(','))")" "$COUNT" "all listed as missing again"

# Conflict: change one alt by hand after a bulk run.
R=$(action $S media.bulk_alt "{\"items\":[{\"mediaId\":$FIRST,\"altText\":\"Once\"}]}")
CH2=$(echo "$R" | jget "d['result']['changeId']")
$WP $S post meta update $FIRST _wp_attachment_image_alt "By hand" >/dev/null
check "$(api $S POST /changes/$CH2/rollback | jget "d['code']")" "visibility_conflict" "rollback after a manual edit -> conflict"

# Limits and owner switches.
BIG=$(python3 -c "import json;print(json.dumps([{'mediaId':$FIRST,'altText':'x'}]*51))")
check "$(action $S media.bulk_alt "{\"items\":$BIG}" | jget "d['errorCode']")" "invalid" "more than 50 -> invalid"
R=$(action $S media.bulk_alt "{\"items\":[{\"mediaId\":$BP,\"altText\":\"x\"},{\"mediaId\":$SECOND}]}")
check "$(echo "$R" | jget "(d['errorCode'], len(d['result']['failed']))")" "('not_applied', 2)" "not an image / no altText -> each failed, nothing updated"
api $S POST /events/site-config-changed '{"controls":{"actions":["create_drafts","edit_meta"],"postTypes":["post","page"],"lockedPostIds":[],"fieldAllowlist":[]}}' >/dev/null
check "$(action $S media.bulk_alt "{\"items\":[{\"mediaId\":$THIRD,\"altText\":\"x\"}]}" | jget "d['errorCode']")" "not_allowed" "images switch off -> not_allowed"
api $S POST /events/site-config-changed "{\"controls\":{\"actions\":[\"create_drafts\",\"edit_meta\",\"images\"],\"postTypes\":[\"post\",\"page\"],\"lockedPostIds\":[$CP],\"fieldAllowlist\":[]}}" >/dev/null
# The pages show "Once" (from the run above); make the library say so too, so both pages qualify.
$WP $S post meta update $FIRST _wp_attachment_image_alt "Once" >/dev/null
R=$(action $S media.bulk_alt "{\"items\":[{\"mediaId\":$FIRST,\"altText\":\"Locked page\"}]}")
check "$(echo "$R" | jget "(next(u for u in d['result']['updated'])['blocksUpdated'], next(u for u in d['result']['updated']).get('postsSkipped'))")" "(1, [$CP])" "locked page skipped, the other page updated"
check "$($WP $S post get $CP --field=post_content | grep -c 'Locked page')" "0" "locked page untouched"
api $S POST /events/site-config-changed '{"controls":null}' >/dev/null
summary
