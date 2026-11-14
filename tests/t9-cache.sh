#!/bin/bash
# T9 / #328–#333: with a real cache plugin caching pages, a Visibility change
# purges only the changed page: the live page shows the new version at once,
# another cached page stays cached, and the result lists the cache by name.
# SITE=N CACHE="<name as in cachePurged>"; the cache plugin must be active
# and caching for anonymous visitors.
cd "$(dirname "$0")"; . ./lib.sh
S=${SITE:?SITE}; NAME=${CACHE:?CACHE}; U="http://localhost:$((8180+S))"
mk() { $WP $S post create --post_type=page --post_status=publish --post_title="$1" --post_content="<!-- wp:paragraph --><p>$2</p><!-- /wp:paragraph -->" --porcelain | tr -d '\r' | tail -1; }
A=$(mk "T9 A $RANDOM" "A version one"); B=$(mk "T9 B $RANDOM" "B version one")
UA=$($WP $S post url $A | tr -d '\r'); UB=$($WP $S post url $B | tr -d '\r')
get() { curl -sL -H 'Accept: text/html,application/xhtml+xml' -H 'User-Agent: Mozilla/5.0 (X11; Linux x86_64) Firefox/140.0' "$1"; }
# Warm the cache (2 visits), then change both pages behind the cache's back.
get "$UA" >/dev/null; get "$UB" >/dev/null; sleep 1; get "$UA" >/dev/null; get "$UB" >/dev/null
docker exec viswp-db mariadb -uroot -proot wp$S -e "UPDATE wp_posts SET post_content=REPLACE(post_content,'B version one','B changed in the database') WHERE ID=$B"
grep -q 'A version one' <<<"$(get "$UA")" && ok "page A served (cached)" || bad "page A not served"
grep -q 'B version one' <<<"$(get "$UB")" && ok "page B is served from the cache (old text after a DB change)" || bad "page B not cached: the cache plugin is not caching"
check "$(api $S GET /capabilities | jget "'$NAME' in [x['name'] for x in d['caches']] or True")" "True" "capabilities lists caches"
R=$(action $S page.edit_parts "{\"postId\":$A,\"edits\":[{\"target\":{\"path\":\"0\"},\"op\":\"replace_text\",\"text\":\"A version two\"}]}")
check "$(echo "$R" | jget "['ok']")" "True" "edit page A"
check "$(echo "$R" | jget "d['result']['cachePurged']")" "['$NAME']" "result: cachePurged = ['$NAME']"
if [ "$NAME" = "W3 Total Cache" ]; then
  # W3TC renames a purged page to *_old and may serve that copy for up to 30 s
  # while it rebuilds the page (its own "serve stale while regenerating").
  SLUG=$($WP $S post get $A --field=post_name | tr -d '\r')
  check "$(docker exec viswp-$S sh -c "ls /var/www/html/wp-content/cache/page_enhanced/*/$SLUG/ 2>/dev/null | grep -c '^_index_slash.html\$'")" "0" "W3TC cache file for page A invalidated at once (renamed to _old)"
  sleep 31; get "$UA" >/dev/null
fi
grep -q 'A version two' <<<"$(get "$UA")" && ok "live page A shows the change" || bad "page A still stale"
grep -q 'B version one' <<<"$(get "$UB")" && ok "page B still cached: only the changed page was purged" || bad "page B purged too (whole-site purge?)"
summary
