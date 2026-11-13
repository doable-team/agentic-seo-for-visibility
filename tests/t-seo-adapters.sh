#!/bin/bash
# #287 / #311 / #312 / #313 (and T2 Yoast part, T4 AIOSEO part): write SEO
# fields through seo_meta.update and check the rendered <head> shows each one
# exactly once, without a manual re-save; check before/after, read-back,
# unsupportedFields, clearing, and category SEO.
cd "$(dirname "$0")"; . ./lib.sh
SITES=${SITES:-"1 2 3 4"}
for S in $SITES; do
  NAME=$(api $S GET /capabilities | jget "['seo']['plugin']")
  L="site $S ($NAME): "
  ID=$($WP $S post create --post_type=page --post_status=publish --post_title="Adapter page $RANDOM" --post_content="<p>Original body text for the page.</p>" --porcelain | tr -d '\r' | tail -1)
  curl -sL "http://localhost:809$S/?page_id=$ID" -o /dev/null   # let the SEO plugin build its cache/indexable first
  T=$RANDOM
  # Rank Math only prints social images from the media library: use one there.
  IMG="https://example.com/og-$T.png"
  if [ "$NAME" = "rankmath" ]; then
    IMG=$(api $S POST /media "{\"source_url\":\"http://localhost/vis-test-og.png\",\"title\":\"og $T\"}" | jget "['url']")
  fi
  F="{\"metaTitle\":\"Title $T\",\"metaDescription\":\"Description $T\",\"canonical\":\"https://example.com/c-$T/\",\"ogTitle\":\"OG title $T\",\"ogDescription\":\"OG description $T\",\"ogImage\":\"$IMG\",\"twitterTitle\":\"TW title $T\",\"twitterDescription\":\"TW description $T\",\"focusKeyword\":\"keyword $T\",\"notAField\":\"x\"}"
  R=$(action $S seo_meta.update "{\"postId\":$ID,\"fields\":$F}")
  check "$(echo "$R" | jget "['ok']")" "True" "${L}seo_meta.update ok"
  check "$(echo "$R" | jget "['result']['unsupportedFields']")" "['notAField']" "${L}unknown field reported in unsupportedFields"
  check "$(echo "$R" | jget "['result']['before']['metaTitle']")" "" "${L}before.metaTitle empty"
  check "$(echo "$R" | jget "['result']['after']['metaTitle']")" "Title $T" "${L}after.metaTitle"
  check "$(echo "$R" | jget "['result']['rendered']['description']")" "Description $T" "${L}rendered.description"
  PAGE=$(curl -sL "http://localhost:809$S/?page_id=$ID")
  python3 head-check.py "{\"title\":\"Title $T\",\"description\":\"Description $T\",\"canonical\":\"https://example.com/c-$T/\",\"og:title\":\"OG title $T\",\"og:description\":\"OG description $T\",\"og:image\":\"$IMG\",\"twitter:title\":\"TW title $T\",\"twitter:description\":\"TW description $T\"}" "$L" <<<"$PAGE" && pass=$((pass+1)) || fail=$((fail+1))
  check "$(grep -c '<!-- Agentic SEO for Visibility -->' <<<"$PAGE")" "$([ "$NAME" = None ] && echo 1 || echo 0)" "${L}fallback head only without an SEO plugin"
  # noindex (SEO plugins drop the canonical on noindex pages, so separately).
  R=$(action $S seo_meta.update "{\"postId\":$ID,\"fields\":{\"robotsNoindex\":true}}")
  check "$(echo "$R" | jget "['ok']")" "True" "${L}noindex ok"
  python3 head-check.py '{"robots_contains":["noindex"]}' "$L" <<<"$(curl -sL "http://localhost:809$S/?page_id=$ID")" && pass=$((pass+1)) || fail=$((fail+1))
  # Read back through the rich read.
  RR=$(api $S GET "/posts/$ID?include=seo")
  check "$(echo "$RR" | jget "['seo']['values']['focusKeyword']")" "keyword $T" "${L}read: focusKeyword"
  check "$(echo "$RR" | jget "['seo']['values']['robotsNoindex']")" "True" "${L}read: robotsNoindex"
  # Clear title + noindex again.
  R=$(action $S seo_meta.update "{\"postId\":$ID,\"fields\":{\"metaTitle\":\"\",\"robotsNoindex\":false}}")
  check "$(echo "$R" | jget "['ok']")" "True" "${L}clear ok"
  check "$(echo "$R" | jget "['result']['before']['metaTitle']")" "Title $T" "${L}clear: before.metaTitle"
  PAGE=$(curl -sL "http://localhost:809$S/?page_id=$ID")
  grep -q "<title>Title $T</title>" <<<"$PAGE" && bad "${L}title not cleared" || ok "${L}title cleared on the page"
  grep -iE '<meta name=.robots.[^>]*noindex' <<<"$PAGE" >/dev/null && bad "${L}noindex still there" || ok "${L}noindex removed"
  if [ "$NAME" = "rankmath" ]; then
    R=$(action $S seo_meta.update "{\"postId\":$ID,\"fields\":{\"ogImage\":\"https://example.com/remote.png\",\"ogTitle\":\"x $T\"}}")
    check "$(echo "$R" | jget "['result']['unsupportedFields']")" "['ogImage']" "${L}remote og image refused as unsupported (not silently dropped)"
  fi
  # Category SEO.
  CAT=$($WP $S term create category "Cat $T" --porcelain | tr -d '\r' | tail -1)
  $WP $S post create --post_status=publish --post_title="In cat $T" --post_category=$CAT >/dev/null
  R=$(action $S seo_meta.update "{\"termId\":$CAT,\"taxonomy\":\"category\",\"fields\":{\"metaTitle\":\"Cat title $T\",\"metaDescription\":\"Cat description $T\"}}")
  if [ "$NAME" = "aioseo" ]; then
    check "$(echo "$R" | jget "['errorCode']")" "unsupported" "${L}term SEO refused plainly (AIOSEO Lite has none)"
  else
    check "$(echo "$R" | jget "['ok']")" "True" "${L}term seo_meta.update ok"
    PAGE=$(curl -sL "http://localhost:809$S/?cat=$CAT")
    python3 head-check.py "{\"title\":\"Cat title $T\",\"description\":\"Cat description $T\"}" "${L}category " <<<"$PAGE" && pass=$((pass+1)) || fail=$((fail+1))
  fi
done
summary
