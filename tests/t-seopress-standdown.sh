#!/bin/bash
# #282 (in #287) / T5 B17: on a SEOPress site the plugin prints no head tags,
# and SEO writes are refused plainly (SEOPress writes come in phase 2).
cd "$(dirname "$0")"; . ./lib.sh
S=1
$WP $S plugin install wp-seopress --activate >/dev/null 2>&1
ID=$($WP $S post create --post_type=page --post_status=publish --post_title="SEOPress page" --porcelain | tail -1)
$WP $S post meta update $ID _visibility_seo_description "ours" >/dev/null
PAGE=$(curl -sL "http://localhost:8091/?page_id=$ID")
check "$(grep -c 'Agentic SEO for Visibility' <<<"$PAGE")" "0" "SEOPress active: our head prints nothing"
check "$(grep -c 'content="ours"' <<<"$PAGE")" "0" "our stored description not printed"
check "$(api $S GET /capabilities | jget "['seo']['plugin']")" "seopress" "capabilities: seopress detected"
R=$(action $S seo_meta.update "{\"postId\":$ID,\"fields\":{\"metaTitle\":\"x\"}}")
check "$(echo "$R" | jget "['errorCode']")" "unsupported" "SEO write refused with a plain message, not silently"
$WP $S plugin deactivate wp-seopress >/dev/null; $WP $S plugin delete wp-seopress >/dev/null
PAGE=$(curl -sL "http://localhost:8091/?page_id=$ID")
check "$(grep -c '<!-- Agentic SEO for Visibility -->' <<<"$PAGE")" "1" "SEOPress removed: our head is back, once"
summary
