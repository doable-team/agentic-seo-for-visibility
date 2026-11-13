#!/bin/bash
# T3 (schema part) / #267: JSON-LD written via seo_meta.update appears exactly
# once in the page source — inside the SEO plugin's own graph on Yoast, Rank
# Math and AIOSEO; as our own <script> with no SEO plugin.
cd "$(dirname "$0")"; . ./lib.sh
for S in ${SITES:-1 2 3 4}; do
  ID=$($WP $S post create --post_type=post --post_status=publish --post_title="T3 schema $RANDOM" --post_content="<p>Schema body.</p>" --porcelain | tr -d '\r' | tail -1)
  MARK="vis-faq-$RANDOM$RANDOM"
  R=$(action $S seo_meta.update "{\"postId\":$ID,\"fields\":{\"metaDescription\":\"Schema test\",\"schemaJsonLd\":{\"@context\":\"https://schema.org\",\"@type\":\"FAQPage\",\"@id\":\"https://example.com/#$MARK\",\"mainEntity\":[{\"@type\":\"Question\",\"name\":\"Q \\\"quoted\\\" $MARK\",\"acceptedAnswer\":{\"@type\":\"Answer\",\"text\":\"A\"}}]}}}")
  check "$(echo "$R" | jget "['ok']")" "True" "site $S: seo_meta.update ok"
  PAGE=$(curl -sL "http://localhost:809$S/?p=$ID")
  # It must sit inside a JSON-LD script that parses.
  python3 jsonld-check.py "$MARK" <<<"$PAGE" && ok "site $S: injected schema appears exactly once, JSON-LD parses" || bad "site $S: injected schema not exactly once"
done
summary
