#!/bin/bash
# T9 / #330 LiteSpeed Cache: the purge is an X-LiteSpeed-Purge response
# header that the LiteSpeed web server acts on. Test sites run Apache, so
# this checks the header the plugin sends: tagged for the changed post
# (Po.<id>), never a purge of everything ("*"), and cachePurged lists it.
cd "$(dirname "$0")"; . ./lib.sh
S=${SITE:-14}; U="http://localhost:$((8180+S))"
$WP $S option update litespeed.conf.cache 1 >/dev/null 2>&1
A=$($WP $S post create --post_type=page --post_status=publish --post_title="T9 LS A $RANDOM" --post_content="<!-- wp:paragraph --><p>A one</p><!-- /wp:paragraph -->" --porcelain | tr -d '\r' | tail -1)
B=$($WP $S post create --post_type=page --post_status=publish --post_title="T9 LS B $RANDOM" --porcelain | tr -d '\r' | tail -1)
check "$(api $S GET /capabilities | jget "d['cachePurge']")" "['litespeed-cache']" "capabilities: LiteSpeed Cache purgeable"
H=$(curl -s -D - -o /tmp/t9ls.json -X POST -H "Authorization: Bearer vis-test-token-$S" -H 'Content-Type: application/json' --data "{\"requestId\":\"ls-$RANDOM$RANDOM\",\"actionType\":\"page.edit_parts\",\"actionPayload\":{\"postId\":$A,\"edits\":[{\"target\":{\"path\":\"0\"},\"op\":\"replace_text\",\"text\":\"A two\"}]}}" "$U/wp-json/visibility/v1/events/action-approved" | grep -i '^X-LiteSpeed-Purge:' | tr -d '\r')
echo "   $H"
check "$(jget "d['result']['cachePurged']" </tmp/t9ls.json)" "['LiteSpeed Cache']" "result: cachePurged = ['LiteSpeed Cache']"
grep -qE "_Po\.$A(,|$)" <<<"$H" && ok "purge header tags the changed post (Po.$A)" || bad "post tag missing"
grep -qE "_Po\.$B(,|$)" <<<"$H" && bad "other page B purged too" || ok "other page B not purged"
grep -qE "(: |,)\*(,|$)|purge_all" <<<"$H" && bad "whole-site purge" || ok "no whole-site purge (*)"
summary
