#!/bin/bash
# T4 (AIOSEO part) / #268: title, description, canonical, robots and OG written
# for an AIOSEO site show in the rendered <head>.
cd "$(dirname "$0")"; . ./lib.sh
S=4
ID=$($WP $S post create --post_type=page --post_status=publish --post_title="T4 AIOSEO page" --post_content="<p>Body text.</p>" --porcelain | tr -d '\r')
TAG=$RANDOM
R=$(action $S seo_meta.update "{\"postId\":$ID,\"fields\":{\"metaTitle\":\"AIOSEO Title $TAG\",\"metaDescription\":\"AIOSEO description $TAG\",\"canonical\":\"https://example.com/canon-$TAG/\",\"ogTitle\":\"OG $TAG\",\"robotsNoindex\":true}}")
check "$(echo "$R" | jget "['ok']")" "True" "seo_meta.update ok ($R)"
check "$(echo "$R" | jget "['result']['seoPlugin']")" "aioseo" "routed to aioseo"
HEAD=$(curl -sL "http://localhost:8094/?page_id=$ID" | sed -n '/<head/,/<\/head>/p')
grep -q <<<"$HEAD" "<title>AIOSEO Title $TAG</title>" && ok "title in <head>" || bad "title missing"
check "$(grep -c <<<"$HEAD" "name=\"description\" content=\"AIOSEO description $TAG\"")" "1" "meta description once"
check "$(grep -c <<<"$HEAD" "rel=\"canonical\" href=\"https://example.com/canon-$TAG/\"")" "1" "canonical once"
grep -qi <<<"$HEAD" 'name="robots" content="noindex' && ok "noindex in robots" || bad "noindex missing: $(echo "$HEAD" | grep -i robots)"
grep -q <<<"$HEAD" "og:title\" content=\"OG $TAG\"" && ok "og:title" || bad "og:title missing"
check "$(grep -c <<<"$HEAD" 'Agentic SEO for Visibility -->')" "0" "fallback renderer silent"
summary
