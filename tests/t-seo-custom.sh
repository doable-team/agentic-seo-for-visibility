#!/bin/bash
# 2.1.0 SEO plugin Visibility doesn't know, found by its registered meta
# (stand-in: standins/editorial-seo-standin.php, keys _sse_seo_*), no known
# SEO plugin active. capabilities.seoPlugin = custom + prefix + keys;
# GET /posts?include=seo shows the plugin's current values; seo_meta.update
# writes into the plugin's keys (keyword list primary first, robots as the
# plugin stores them), reports seoPlugin custom:<prefix> / renderedBy; the live
# head has each tag once (the plugin prints them, Visibility stands down);
# the _sse keys are read-only in the meta inventory; rollback.
cd "$(dirname "$0")"; . ./lib.sh
S=${SITE:-1}; BASE="http://localhost:$((PORT_BASE+S))"
code() { echo "$1" | jget "d.get('errorCode')"; }
count() { python3 -c "import sys,re;h=sys.stdin.read().split('</head>')[0];print(len(re.findall(sys.argv[1],h)))" "$1"; }
api $S POST /events/site-config-changed '{"controls":null,"allowPublish":true,"disabled":false}' >/dev/null
P=$($WP $S post create --post_type=post --post_status=publish --post_title="Custom SEO $RANDOM" --post_content='<!-- wp:paragraph --><p>Body text.</p><!-- /wp:paragraph -->' --porcelain | tr -d '\r' | tail -1)

# Without the plugin: Visibility's own head, nothing custom.
$CTR exec ${CPFX}$S rm -f /var/www/html/wp-content/mu-plugins/editorial-seo-standin.php
check "$(api $S GET /capabilities | jget "(d['seo']['plugin'], d['seoPlugin'])")" "(None, None)" "no SEO plugin: seoPlugin null"

$CTR cp standins/editorial-seo-standin.php ${CPFX}$S:/var/www/html/wp-content/mu-plugins/editorial-seo-standin.php
$CTR exec ${CPFX}$S chown -R www-data:www-data /var/www/html/wp-content/mu-plugins
$WP $S post meta update $P _sse_seo_title "Old SSE title" >/dev/null
$WP $S post meta update $P _sse_seo_score 80 >/dev/null

C=$(api $S GET /capabilities)
check "$(echo "$C" | jget "(d['seoPlugin']['slug'], d['seoPlugin']['prefix'], sorted(d['seoPlugin']['fields'].items()))")" "('custom', '_sse_seo_', [('canonical', '_sse_seo_canonical'), ('description', '_sse_seo_description'), ('keyword', '_sse_seo_keyword'), ('robotsFollow', '_sse_seo_robots_follow'), ('robotsIndex', '_sse_seo_robots_index'), ('title', '_sse_seo_title')])" "capabilities.seoPlugin: custom family with its keys"
check "$(echo "$C" | jget "(d['seo']['plugin'], d['seo']['writable'], d['seo']['renderedBy'], 'seo.custom' in d['features'])")" "('custom:_sse_seo_', True, '_sse_seo_ plugin', True)" "capabilities.seo: custom:<prefix>, writable"
check "$(echo "$C" | jget "'ogTitle' in d['seo']['fields'], 'secondaryKeywords' in d['seo']['fields']")" "(False, True)" "OG left to the plugin (it has OG fields); secondaryKeywords offered"

G=$(api $S GET "/posts/$P?include=seo,meta")
check "$(echo "$G" | jget "(d['seo']['plugin'], d['seo']['values']['metaTitle'], d['seo']['custom']['fields']['title'])")" "('custom:_sse_seo_', 'Old SSE title', {'key': '_sse_seo_title', 'value': 'Old SSE title'})" "include=seo: current values from the plugin's keys"
check "$(echo "$G" | jget "dict((m['key'],(m['writable'],m.get('via'))) for m in d['meta'])['_sse_seo_title']")" "(False, 'seo_meta.update')" "meta inventory: the plugin's SEO keys read-only (seo_meta.update)"
check "$(echo "$G" | jget "dict((m['key'],(m['value'],m['type'],m['showInRest'])) for m in d['meta'])['_sse_seo_score']")" "(80, 'integer', True)" "meta inventory: other _sse keys listed with type"

R=$(action $S seo_meta.update "{\"postId\":$P,\"fields\":{\"metaTitle\":\"New SSE title\",\"metaDescription\":\"New SSE description\",\"focusKeyword\":\"primary kw\",\"secondaryKeywords\":[\"second kw\",\"third, kw\"],\"canonical\":\"$BASE/canon-x/\",\"robotsNoindex\":true,\"robotsNofollow\":true}}")
check "$(echo "$R" | jget "(d['ok'], d['result']['seoPlugin'], d['result']['renderedBy'], sorted(d['result']['fields']), d['result']['unsupportedFields'])")" "(True, 'custom:_sse_seo_', '_sse_seo_ plugin', ['canonical', 'focusKeyword', 'metaDescription', 'metaTitle', 'robotsNofollow', 'robotsNoindex', 'secondaryKeywords'], [])" "seo_meta.update: written into the custom plugin"
check "$(echo "$R" | jget "(d['result']['before']['metaTitle'], d['result']['after']['metaTitle'])")" "('Old SSE title', 'New SSE title')" "before/after"
CH=$(echo "$R" | jget "d['result']['changeId']")
g() { $WP $S post meta get $P $1 | tr -d '\r'; }
check "$(g _sse_seo_title)|$(g _sse_seo_description)|$(g _sse_seo_keyword)|$(g _sse_seo_canonical)|$(g _sse_seo_robots_index)|$(g _sse_seo_robots_follow)" "New SSE title|New SSE description|primary kw, second kw, third kw|$BASE/canon-x/|noindex|nofollow" "stored in the plugin's own keys (keywords primary first)"
check "$(g _visibility_seo_title)$(g _visibility_seo_description)$(g _visibility_seo_focus_keyword)" "" "nothing in Visibility's own SEO keys"

H=$(curl -sL "$BASE/?p=$P")
echo "$H" | python3 head-check.py "{\"title\":\"New SSE title\",\"description\":\"New SSE description\",\"canonical\":\"$BASE/canon-x/\",\"robots_contains\":[\"noindex\",\"nofollow\"],\"og:title\":\"New SSE title\",\"twitter:title\":\"New SSE title\"}" "live head: " && ok "live head: each tag once" || bad "live head: duplicate or missing tags"
check "$(echo "$H" | count 'property="og:type"')|$(echo "$H" | count 'name="twitter:card"')" "1|1" "og:type and twitter:card once"

# "a, b" in focusKeyword alone = primary + secondary.
R2=$(action $S seo_meta.update "{\"postId\":$P,\"fields\":{\"focusKeyword\":\"alpha, beta\"}}")
check "$(echo "$R2" | jget "(d['ok'], d['result']['after']['focusKeyword'], d['result']['after']['secondaryKeywords'])")|$(g _sse_seo_keyword)" "(True, 'alpha', ['beta'])|alpha, beta" "focusKeyword 'a, b' -> primary a, secondary b"
api $S POST /changes/$(echo "$R2" | jget "d['result']['changeId']")/rollback >/dev/null
R2=$(action $S seo_meta.update "{\"postId\":$P,\"fields\":{\"robotsNoindex\":false}}")
check "$(echo "$R2" | jget "d['ok']")|$(g _sse_seo_robots_index)" "True|" "robotsNoindex false clears the plugin's key (its default: index)"
api $S POST /changes/$(echo "$R2" | jget "d['result']['changeId']")/rollback >/dev/null
R2=$(action $S seo_meta.update "{\"postId\":$P,\"fields\":{\"ogTitle\":\"x\"}}")
check "$(code "$R2")" "unsupported" "ogTitle refused (the plugin prints its own Open Graph)"
# meta.update can't go around seo_meta.update.
api $S POST /events/site-config-changed '{"controls":{"actions":["edit_fields","edit_meta"],"postTypes":["post"],"lockedPostIds":[],"fieldAllowlist":["meta:_sse_seo_title"],"anyPostMeta":true}}' >/dev/null
R2=$(action $S meta.update "{\"postId\":$P,\"meta\":{\"_sse_seo_title\":\"x\"}}")
check "$(code "$R2")" "not_allowed" "meta.update refuses the custom SEO keys (seo_meta.update owns them)"
R2=$(action $S meta.update "{\"postId\":$P,\"meta\":{\"_sse_seo_score\":\"high\"}}")
check "$(code "$R2")" "invalid" "meta.update: other _sse key type-checked (integer)"
R2=$(action $S meta.update "{\"postId\":$P,\"meta\":{\"_sse_seo_schema_type\":\"HowTo\"}}")
check "$(echo "$R2" | jget "d['ok']")|$(g _sse_seo_schema_type)" "True|HowTo" "meta.update: other _sse key written in any-meta mode"
api $S POST /changes/$(echo "$R2" | jget "d['result']['changeId']")/rollback >/dev/null
api $S POST /events/site-config-changed '{"controls":null}' >/dev/null

R=$(api $S POST /changes/$CH/rollback)
check "$(echo "$R" | jget "d['ok']")|$(g _sse_seo_title)|$(g _sse_seo_keyword)|$(g _sse_seo_robots_index)" "True|Old SSE title||" "rollback restores the plugin's keys"
check "$(curl -sL "$BASE/?p=$P" | count 'New SSE')" "0" "live page back after rollback"

# Pages are registered too; a page shows the plugin's title once.
PG=$($WP $S post create --post_type=page --post_status=publish --post_title="Custom SEO page $RANDOM" --porcelain | tr -d '\r' | tail -1)
R=$(action $S seo_meta.update "{\"postId\":$PG,\"fields\":{\"metaTitle\":\"Page SSE title\",\"metaDescription\":\"Page SSE desc\"}}")
curl -sL "$BASE/?page_id=$PG" | python3 head-check.py '{"title":"Page SSE title","description":"Page SSE desc"}' "page: " && ok "page: title + description once" || bad "page head"
api $S POST /changes/$(echo "$R" | jget "d['result']['changeId']")/rollback >/dev/null

$CTR exec ${CPFX}$S rm -f /var/www/html/wp-content/mu-plugins/editorial-seo-standin.php
check "$(api $S GET /capabilities | jget "d['seo']['plugin']")" "None" "plugin removed: back to Visibility's own head"
summary
