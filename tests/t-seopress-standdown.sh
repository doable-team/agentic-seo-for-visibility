#!/bin/bash
# #282 (in #287) / #314 / T5: on a SEOPress site the plugin prints no head
# tags of its own, SEO fields are written into SEOPress, and the page has one
# title and one description. Site 8 runs SEOPress.
cd "$(dirname "$0")"; . ./lib.sh
S=${SITE:-8}
ID=$($WP $S post create --post_type=page --post_status=publish --post_title="SEOPress page" --porcelain | tr -d '\r' | tail -1)
$WP $S post meta update $ID _visibility_seo_description "ours" >/dev/null
check "$(api $S GET /capabilities | jget "['seo']['plugin']")" "seopress" "capabilities: seopress detected"
check "$(api $S GET /capabilities | jget "['seo']['writable']")" "True" "capabilities: seopress writable"
R=$(action $S seo_meta.update "{\"postId\":$ID,\"fields\":{\"metaTitle\":\"SP title\",\"metaDescription\":\"SP description\",\"robotsNofollow\":true}}")
check "$(echo "$R" | jget "['ok']")" "True" "seo_meta.update into SEOPress"
check "$($WP $S post meta get $ID _seopress_titles_title)" "SP title" "stored in _seopress_titles_title"
check "$($WP $S post meta get $ID _seopress_robots_follow)" "yes" "nofollow stored as SEOPress's 'yes'"
PAGE=$(curl -sL "http://localhost:$((PORT_BASE+S))/?page_id=$ID")
check "$(grep -c 'Agentic SEO for Visibility' <<<"$PAGE")" "0" "our head prints nothing"
check "$(grep -c 'content="ours"' <<<"$PAGE")" "0" "our stored description not printed"
python3 head-check.py '{"title":"SP title","description":"SP description","robots_contains":["nofollow"]}' "SEOPress " <<<"$PAGE" && pass=$((pass+1)) || fail=$((fail+1))
CH=$(echo "$R" | jget "d['result']['changeId']")
api $S POST /changes/$CH/rollback >/dev/null
check "$($WP $S post meta get $ID _seopress_titles_title 2>/dev/null)" "" "rollback cleared the SEOPress title"
summary
