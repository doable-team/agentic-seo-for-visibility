#!/bin/bash
# #293 (shared part): after a change only the changed post's cache is purged
# and the result lists the cache. Uses the WP Rocket stand-in (tests/standins, mu-plugin that
# defines rocket_clean_post and logs each call) on site S; site N has no cache.
cd "$(dirname "$0")"; . ./lib.sh
S=${SITE:-1}; N=${NOCACHE_SITE:-4}
$CTR cp standins/wp-rocket-standin.php ${CPFX}$S:/var/www/html/wp-content/mu-plugins/wp-rocket-standin.php
A=$($WP $S post create --post_type=page --post_status=publish --post_title="T9 A $RANDOM" --post_content='<!-- wp:paragraph --><p>A text</p><!-- /wp:paragraph -->' --porcelain | tr -d '\r' | tail -1)
B=$($WP $S post create --post_type=page --post_status=publish --post_title="T9 B $RANDOM" --porcelain | tr -d '\r' | tail -1)
# WP Rocket also purges on clean_post_cache, so the revision WordPress saves shows up; revisions aren't pages.
log() { ($WP $S option get vis_test_purges --format=json 2>/dev/null || echo '[]') | python3 -c "import sys,json; print(json.dumps([x for x in json.load(sys.stdin) if x.get('type')!='revision']))"; }
$WP $S option delete vis_test_purges >/dev/null 2>&1

check "$(api $S GET /capabilities | jget "d['cachePurge']")" "['wp-rocket']" "capabilities: cachePurge lists wp-rocket"
R=$(action $S page.edit_parts "{\"postId\":$A,\"edits\":[{\"target\":{\"path\":\"0\"},\"op\":\"replace_text\",\"text\":\"A changed\"}]}")
check "$(echo "$R" | jget "d['result']['cachePurged']")" "['WP Rocket']" "edit_parts result: cachePurged = [WP Rocket]"
check "$(log | jget "list(x['post'] for x in d)")" "[$A]" "only the changed page was purged (not page B $B, not the site)"
check "$(log | jget "d[0]['url']")" "$($WP $S post url $A)" "purged URL = the changed page's URL"

$WP $S option delete vis_test_purges >/dev/null 2>&1
R=$(action $S seo_meta.update "{\"postId\":$A,\"fields\":{\"metaDescription\":\"T9 desc\"}}")
check "$(echo "$R" | jget "d['result']['cachePurged']")" "['WP Rocket']" "seo_meta.update result lists the cache"
R=$(api $S PATCH /posts/$A '{"title":"T9 A patched"}')
check "$(echo "$R" | jget "d['cachePurged']")" "['WP Rocket']" "PATCH /posts result lists the cache"
CH=$(echo "$R" | jget "d['changeId']")
R=$(api $S POST /changes/$CH/rollback)
check "$(echo "$R" | jget "d['cachePurged']")" "['WP Rocket']" "rollback result lists the cache"
check "$(log | jget "sorted(set(x['post'] for x in d))")" "[$A]" "still only page A purged"

# No cache plugin: empty list, change still works.
P=$($WP $N post create --post_type=page --post_status=publish --post_title="T9 nocache" --porcelain | tr -d '\r' | tail -1)
R=$(action $N page.update_content "{\"postId\":$P,\"fields\":{\"title\":\"T9 nocache 2\"}}")
check "$(echo "$R" | jget "d['result']['cachePurged']")" "[]" "no cache plugin: cachePurged = []"
$CTR exec ${CPFX}$S rm -f /var/www/html/wp-content/mu-plugins/wp-rocket-standin.php
summary
