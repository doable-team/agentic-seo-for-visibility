#!/bin/bash
# #571: POST /blocks/faq builds the site's own FAQ / How-to block from questions
# and answers, exactly as the block's save function writes it. Each built block
# is saved in a draft and opened in the real block editor (editor-check.js,
# headless Chrome): every block must be valid. Also checks the profile names
# the FAQ block the site's posts use, and that blockSummary (#567) flags a
# block whose attributes and HTML differ.
#   SITE=2 PLUGIN=rankmath  (Rank Math SEO installed; wizard skipped:
#     rank_math_registration_skip + rank_math_wizard_completed)
#   SITE=3 PLUGIN=yoast     (Yoast SEO)
#   SITE=1 PLUGIN=none      (core/details only)
# Needs NODE_PATH with playwright-core and Chrome (CHROME).
cd "$(dirname "$0")"; . ./lib.sh
S=${SITE:-2}; PLUGIN=${PLUGIN:-rankmath}; BASE="http://localhost:$((PORT_BASE+S))"
case $PLUGIN in rankmath) FAQ=rank-math/faq-block; HOWTO=rank-math/howto-block;; yoast) FAQ=yoast/faq-block; HOWTO=yoast/how-to-block;; *) FAQ=core/details; HOWTO=;; esac
api $S POST /events/site-config-changed '{"controls":null,"allowPublish":true,"disabled":false}' >/dev/null
check "$(api $S GET /capabilities | jget "('blocks.faq' in d['features'], 'blocks.howto' in d['features'])")" "(True, True)" "capabilities: blocks.faq, blocks.howto"

ITEMS='[{"question":"What is a title tag?","answer":"<p>The <strong>title</strong> of a page, shown in search results &amp; tabs.</p><p>Keep it under 60 characters.</p>"},{"question":"Is it <free>?","answer":"Yes, it is free & always will be."},{"question":"Where do I add one?","answer":"<ul><li>In your SEO plugin</li><li>Or the <a href=\"https://example.com/x\">theme</a></li></ul>"}]'
editor() { NODE_PATH=${NODE_PATH:-/tmp/pw/node_modules} node editor-check.js "$BASE" "$@"; }
draft() { python3 - "$1" "$2" <<'PY' | api $S POST /posts "$(cat)" | jget "['id']"
import json,sys; print(json.dumps({"title":sys.argv[1],"status":"draft","content":"<!-- wp:paragraph -->\n<p>Intro.</p>\n<!-- /wp:paragraph -->\n\n"+sys.argv[2]}))
PY
}

# 1. Explicit block.
R=$(api $S POST /blocks/faq "{\"items\":$ITEMS,\"block\":\"$FAQ\"}")
check "$(echo "$R" | jget "(d['ok'], d['block'])")" "(True, '$FAQ')" "builds $FAQ"
M=$(echo "$R" | jget "d['markup']")
P1=$(draft "FAQ $PLUGIN $RANDOM" "$M")
check "$(api $S GET "/posts/$P1" | jget "['content']" | grep -c 'What is a title tag?')" "$( [ $FAQ = core/details ] && echo 1 || echo 2)" "stored as sent"
E=$(editor $P1)
check "$(echo "$E" | jget "(d['invalid'], len([b for b in d['blocks'] if b['name']=='$FAQ']))")" "([], $( [ $FAQ = core/details ] && echo 3 || echo 1))" "editor: every block valid, the FAQ block is there"
[ "$(echo "$E" | jget "d['invalid']")" = "[]" ] || echo "   $(echo "$E" | jget "d['messages']")"
check "$(api $S GET "/posts/$P1?include=blocks" | jget "d['blockSummary']['invalid']")" "[]" "blockSummary: no problem reported"

# 2. Live page: FAQ schema printed by the SEO plugin.
if [ $PLUGIN != none ]; then
  $WP $S post update $P1 --post_status=publish >/dev/null
  H=$(curl -sL "$BASE/?p=$P1")
  grep -q "\"FAQPage\"" <<<"$H" && ok "live page has FAQPage schema" || bad "no FAQPage schema on the page"
  grep -q 'Keep it under 60 characters' <<<"$H" && ok "answers rendered" || bad "answers missing on the page"
  $WP $S post update $P1 --post_status=draft >/dev/null
fi

# 3. Default block = the one the site's posts use (profile faqBlocks).
if [ $PLUGIN != none ]; then
  $WP $S post update $P1 --post_status=publish >/dev/null
  $WP $S transient delete visibility_site_profile >/dev/null 2>&1
  check "$(api $S GET /profile | jget "d['faqBlocks'][0]")" "$FAQ" "profile faqBlocks names $FAQ"
  check "$(api $S POST /blocks/faq "{\"items\":$ITEMS}" | jget "d['block']")" "$FAQ" "no block asked: uses the site's FAQ block"
  $WP $S post update $P1 --post_status=draft >/dev/null; $WP $S transient delete visibility_site_profile >/dev/null 2>&1
fi

# 4. How-to.
if [ -n "$HOWTO" ]; then
  R=$(api $S POST /blocks/faq "{\"kind\":\"howto\",\"block\":\"$HOWTO\",\"description\":\"<p>Add a <em>title</em> in three steps.</p>\",\"steps\":[{\"name\":\"Open the page\",\"text\":\"<p>Go to <strong>Pages</strong>.</p>\"},{\"name\":\"Edit the title\",\"text\":\"Type it.\"},{\"name\":\"Save\",\"text\":\"\"}]}")
  check "$(echo "$R" | jget "(d['ok'], d['block'])")" "(True, '$HOWTO')" "builds $HOWTO"
  P2=$(draft "HowTo $PLUGIN $RANDOM" "$(echo "$R" | jget "d['markup']")")
  E=$(editor $P2)
  check "$(echo "$E" | jget "d['invalid']")" "[]" "editor: How-to block valid"
  [ "$(echo "$E" | jget "d['invalid']")" = "[]" ] || echo "   $(echo "$E" | jget "d['messages']")"
else
  check "$(api $S POST /blocks/faq '{"kind":"howto","steps":[{"name":"a","text":"b"}]}' | jget "(d['code'], d['data']['status'])")" "('visibility_no_howto_block', 400)" "no How-to block on the site: clear refusal"
fi

# 5. Refusals.
check "$(api $S POST /blocks/faq '{"items":[{"question":"q"}]}' | jget "d['code']")" "visibility_bad_body" "an item without an answer is refused"
check "$(api $S POST /blocks/faq '{"items":[{"question":"q","answer":"a"}],"block":"acme/faq"}' | jget "d['code']")" "visibility_bad_block" "an unknown block is refused"

# 6. #567: a FAQ block whose attributes and HTML disagree (the hand-written case) is reported.
if [ $PLUGIN != none ]; then
  BAD=$(echo "$M" | sed 's#>What is a title tag?<#>Something else?<#')
  P3=$(draft "FAQ bad $PLUGIN $RANDOM" "$BAD")
  check "$(editor $P3 | jget "d['invalid']")" "['$FAQ']" "editor: the mismatched block is invalid (control)"
  S3=$(api $S GET "/posts/$P3?include=blocks" | jget "json.dumps(d['blockSummary'])")
  check "$(echo "$S3" | jget "str([p['kind'] for p in d['problems']])")" "['faq_mismatch']" "blockSummary.problems: faq_mismatch"
  check "$(echo "$S3" | jget "len(d['invalid']) == 1 and 'What is a title tag?' in d['invalid'][0]")" "True" "blockSummary.invalid explains it"
  $WP $S post delete $P3 --force >/dev/null
fi
$WP $S post delete $P1 --force >/dev/null; [ -n "$P2" ] && $WP $S post delete $P2 --force >/dev/null
summary
