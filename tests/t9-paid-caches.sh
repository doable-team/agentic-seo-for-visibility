#!/bin/bash
# T9 / #338 WP Rocket, #339 Kinsta / WP Engine host cache, against STAND-INS
# (WP Rocket also purges the revision WordPress saves, through its own
# clean_post_cache hook, as the real plugin does; revisions are not pages.)
# (tests/standins/*, built from WP Rocket's public source and the hosts'
# must-use plugin APIs; not the real paid products): only the changed page is
# purged, the result names the cache, drafts are not reported as purged, a
# failing purge is not reported as done.
# REAL_ROCKET=1: run against the REAL WP Rocket instead (active on SITE, page
# caching on, licence constants set): the changed page's cache file is gone and
# the live page fresh, another page's file stays, the result names WP Rocket,
# and a purge that leaves the file behind is not reported as done.
cd "$(dirname "$0")"; . ./lib.sh
S=${SITE:-1}
mu() { $CTR exec ${CPFX}$S sh -c "rm -f /var/www/html/wp-content/mu-plugins/*-standin.php"; for f in "$@"; do $CTR cp standins/$f ${CPFX}$S:/var/www/html/wp-content/mu-plugins/$f; done; }
log() { $WP $S option get vis_test_purges --format=json 2>/dev/null || echo '[]'; }
reset() { $WP $S option delete vis_test_purges >/dev/null 2>&1; }
A=$($WP $S post create --post_type=page --post_status=publish --post_title="T9p A $RANDOM" --post_content='<!-- wp:paragraph --><p>A text</p><!-- /wp:paragraph -->' --porcelain | tr -d '\r' | tail -1)
B=$($WP $S post create --post_type=page --post_status=publish --post_title="T9p B $RANDOM" --porcelain | tr -d '\r' | tail -1)
D=$($WP $S post create --post_type=page --post_status=draft --post_title="T9p draft" --post_content='<!-- wp:paragraph --><p>D</p><!-- /wp:paragraph -->' --porcelain | tr -d '\r' | tail -1)
edit() { action $S page.edit_parts "{\"postId\":$1,\"edits\":[{\"target\":{\"path\":\"0\"},\"op\":\"replace_text\",\"text\":\"$2\"}]}"; }

if [ "${REAL_ROCKET:-0}" = 1 ]; then
  H=/var/www/html/wp-content/cache/wp-rocket; U="http://localhost:$((PORT_BASE+S))"
  host=$(echo "$U" | sed 's#^http://##; s#:.*##')
  get() { curl -s -H 'Accept: text/html,application/xhtml+xml' -H 'User-Agent: Mozilla/5.0 (X11; Linux x86_64) Firefox/140.0' "$1"; }
  files() { $CTR exec ${CPFX}$S sh -c "ls $H/$host*/$1/index*.html* 2>/dev/null" | wc -l | tr -d ' '; }
  mk() { $WP $S post create --post_type=page --post_status=publish --post_title="$1" --post_content="<!-- wp:paragraph --><p>$2</p><!-- /wp:paragraph -->" --porcelain | tr -d '\r' | tail -1; }
  # B first, spacers between: WP Rocket also clears the home page and the changed
  # post's neighbours on any purge; B must be none of those.
  B=$(mk "T9r B $RANDOM" "B version one"); mk "T9r s $RANDOM" x >/dev/null; mk "T9r s $RANDOM" x >/dev/null; A=$(mk "T9r A $RANDOM" "A version one")
  SA=$($WP $S post get $A --field=post_name | tr -d '\r'); SB=$($WP $S post get $B --field=post_name | tr -d '\r')
  C=$(api $S GET /capabilities)
  V=$(echo "$C" | jget "''.join(c['version'] or '' for c in d['caches'] if c['slug']=='wp-rocket')")
  echo "WP Rocket version on the site: $V"
  check "$(echo "$C" | jget "d['cachePurge']")" "['wp-rocket']" "real WP Rocket: capabilities cachePurge"
  get "$U/$SA/" >/dev/null; get "$U/$SB/" >/dev/null
  check "$(files $SA)" "2" "page A cached by WP Rocket (index.html + _gzip)"
  check "$(files $SB)" "2" "page B cached by WP Rocket"
  MB=$($CTR exec ${CPFX}$S sh -c "stat -c %Y $H/$host*/$SB/index.html")
  $CTR exec ${CPFX}db mariadb -uroot -proot wp$S -e "UPDATE wp_posts SET post_content=REPLACE(post_content,'B version one','B changed in the database') WHERE ID=$B"
  grep -q 'B version one' <<<"$(get "$U/$SB/")" && ok "page B served from WP Rocket's cache" || bad "page B not served from the cache"
  R=$(edit $A "A version two")
  check "$(echo "$R" | jget "d['result']['cachePurged']")" "['WP Rocket']" "real WP Rocket: result names WP Rocket"
  check "$(files $SA)" "0" "page A's cache file is gone"
  check "$(files $SB)" "2" "page B's cache file is still there"
  check "$($CTR exec ${CPFX}$S sh -c "stat -c %Y $H/$host*/$SB/index.html")" "$MB" "page B's cache file untouched"
  grep -q 'A version two' <<<"$(get "$U/$SA/")" && ok "live page A shows the change" || bad "page A still stale"
  check "$(files $SA)" "2" "page A cached again (fresh copy)"
  grep -q 'B version one' <<<"$(get "$U/$SB/")" && ok "page B still served from the cache" || bad "page B purged too"
  D=$($WP $S post create --post_type=page --post_status=draft --post_title="T9r draft" --post_content='<!-- wp:paragraph --><p>D</p><!-- /wp:paragraph -->' --porcelain | tr -d '\r' | tail -1)
  check "$(edit $D "Draft two" | jget "d['result']['cachePurged']")" "[]" "real WP Rocket: a draft is not reported as purged"
  # The purge fails: WP Rocket can't delete page A's cache file (folder not writable).
  DIR=$($CTR exec ${CPFX}$S sh -c "ls -d $H/$host*/$SA" | head -1 | tr -d '\r')
  $CTR exec ${CPFX}$S sh -c "chown root:root $DIR && chmod 555 $DIR"
  R=$(edit $A "A version three")
  check "$(echo "$R" | jget "d['ok']")" "True" "purge failing: the change itself still succeeds"
  check "$(echo "$R" | jget "d['result']['cachePurged']")" "[]" "purge failing: not reported as purged"
  check "$(files $SA)" "2" "purge failing: the stale file really is still there"
  $CTR exec ${CPFX}$S sh -c "chown www-data:www-data $DIR && chmod 755 $DIR"
  R=$(edit $A "A version four")
  check "$(echo "$R" | jget "d['result']['cachePurged']")" "['WP Rocket']" "folder writable again: purged and named"
  grep -q 'A version four' <<<"$(get "$U/$SA/")" && ok "live page A fresh" || bad "page A stale"
  summary; exit $?
fi

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
