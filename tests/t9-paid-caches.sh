#!/bin/bash
# T9 / #338 WP Rocket, #339 Kinsta / WP Engine host cache, against STAND-INS
# (WP Rocket also purges the revision WordPress saves, through its own
# clean_post_cache hook, as the real plugin does; revisions are not pages.)
# (tests/standins/*, built from WP Rocket's public source and the hosts'
# must-use plugin APIs; not the real paid products): only the changed page is
# purged, the result names the cache, drafts are not reported as purged, a
# failing purge is not reported as done.
cd "$(dirname "$0")"; . ./lib.sh
S=${SITE:-1}
mu() { $CTR exec ${CPFX}$S sh -c "rm -f /var/www/html/wp-content/mu-plugins/*-standin.php"; for f in "$@"; do $CTR cp standins/$f ${CPFX}$S:/var/www/html/wp-content/mu-plugins/$f; done; }
log() { $WP $S option get vis_test_purges --format=json 2>/dev/null || echo '[]'; }
reset() { $WP $S option delete vis_test_purges >/dev/null 2>&1; }
A=$($WP $S post create --post_type=page --post_status=publish --post_title="T9p A $RANDOM" --post_content='<!-- wp:paragraph --><p>A text</p><!-- /wp:paragraph -->' --porcelain | tr -d '\r' | tail -1)
B=$($WP $S post create --post_type=page --post_status=publish --post_title="T9p B $RANDOM" --porcelain | tr -d '\r' | tail -1)
D=$($WP $S post create --post_type=page --post_status=draft --post_title="T9p draft" --post_content='<!-- wp:paragraph --><p>D</p><!-- /wp:paragraph -->' --porcelain | tr -d '\r' | tail -1)
edit() { action $S page.edit_parts "{\"postId\":$1,\"edits\":[{\"target\":{\"path\":\"0\"},\"op\":\"replace_text\",\"text\":\"$2\"}]}"; }

for row in "wp-rocket-standin.php|wp-rocket|WP Rocket|wp-rocket" "kinsta-standin.php|kinsta|Kinsta cache|kinsta" "wpengine-standin.php|wpengine|WP Engine cache|wpengine"; do
  IFS='|' read -r file slug name logname <<<"$row"
  mu "$file"; reset
  C=$(api $S GET /capabilities)
  check "$(echo "$C" | jget "d['cachePurge']")" "['$slug']" "$name: capabilities cachePurge"
  check "$(echo "$C" | jget "list(c['slug'] for c in d['caches'])")" "['$slug']" "$name: capabilities caches"
  R=$(edit $A "A by $slug")
  check "$(echo "$R" | jget "d['result']['cachePurged']")" "['$name']" "$name: result names the cache"
  check "$(log | jget "sorted(set(x['post'] for x in d if x['cache']=='$logname' and x.get('type')!='revision'))")" "[$A]" "$name: only the changed page purged (not $B, not everything)"
  reset
  R=$(edit $D "Draft by $slug")
  check "$(echo "$R" | jget "d['result']['cachePurged']")" "[]" "$name: a draft is not reported as purged"
done

# Kinsta's cache server failing: not reported as purged.
mu kinsta-standin.php; $WP $S option update vis_test_kinsta_code 500 >/dev/null
R=$(edit $A "A kinsta 500")
check "$(echo "$R" | jget "d['ok']")" "True" "Kinsta 500: the change itself still succeeds"
check "$(echo "$R" | jget "d['result']['cachePurged']")" "[]" "Kinsta 500: not reported as purged"
$WP $S option delete vis_test_kinsta_code >/dev/null
# Kinsta's own autopurge switched off: not reported either.
$WP $S option update kinsta-autopurge-status disabled >/dev/null
R=$(edit $A "A kinsta off")
check "$(echo "$R" | jget "d['result']['cachePurged']")" "[]" "Kinsta autopurge off: not reported as purged"
$WP $S option delete kinsta-autopurge-status >/dev/null

# WP Rocket + host cache together: both named.
mu wp-rocket-standin.php wpengine-standin.php; reset
R=$(edit $A "A both")
check "$(echo "$R" | jget "sorted(d['result']['cachePurged'])")" "['WP Engine cache', 'WP Rocket']" "WP Rocket + WP Engine: both named"
mu; reset
summary
