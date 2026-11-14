#!/bin/bash
# #317 Spectra, #318 Kadence Blocks, #319 GenerateBlocks (part of #285):
# editing one heading (text in the block's HTML), a sub-part by selector,
# and an image alt inside these plugins' blocks leaves every other block
# byte-identical, keeps the block's attributes, and the rendered page is the
# same as before except for the edited text. Site 1 (block theme) with the
# three plugins active.
cd "$(dirname "$0")"; . ./lib.sh
S=${SITE:-1}
U="http://localhost:$((8180+S))"
IMG="$U/vis-test-og.png"
C='<!-- wp:uagb/advanced-heading {"block_id":"a1b2c3d4","classMigrate":true,"headingDescToggle":true} -->
<div class="wp-block-uagb-advanced-heading uagb-block-a1b2c3d4"><h2 class="uagb-heading-text">Spectra heading</h2><p class="uagb-desc-text">Spectra sub text</p></div>
<!-- /wp:uagb/advanced-heading -->

<!-- wp:uagb/image {"block_id":"e5f6a7b8","url":"'$IMG'","alt":"spectra old alt","width":1200,"height":630} -->
<div class="wp-block-uagb-image uagb-block-e5f6a7b8 wp-block-uagb-image--layout-default wp-block-uagb-image--effect-static wp-block-uagb-image--align-none"><figure class="wp-block-uagb-image__figure"><img srcset="'$IMG' " sizes="(max-width: 480px) 150px" src="'$IMG'" alt="spectra old alt" class="uag-image-" width="1200" height="630" title="" loading="lazy" role="img"/></figure></div>
<!-- /wp:uagb/image -->

<!-- wp:kadence/advancedheading {"uniqueID":"_kh1234","level":3,"color":"#cc0000"} -->
<h3 class="kt-adv-heading_kh1234 wp-block-kadence-advancedheading" data-kb-block="kb-adv-heading_kh1234">Kadence heading</h3>
<!-- /wp:kadence/advancedheading -->

<!-- wp:kadence/image {"uniqueID":"_ki1234","sizeSlug":"full"} -->
<figure class="wp-block-kadence-image kb-image_ki1234 size-full"><img src="'$IMG'" alt="kadence old alt" class="kb-img"/></figure>
<!-- /wp:kadence/image -->

<!-- wp:generateblocks/text {"uniqueId":"gbt12345","tagName":"h2","styles":{"color":"#0000aa"},"css":".gb-text-gbt12345{color:#0000aa}"} -->
<h2 class="gb-text gb-text-gbt12345">GenerateBlocks heading</h2>
<!-- /wp:generateblocks/text -->

<!-- wp:generateblocks/media {"uniqueId":"gbm12345","tagName":"img","htmlAttributes":{"src":"'$IMG'","alt":"gb old alt","width":"1200","height":"630"}} -->
<img class="gb-media-gbm12345" src="'$IMG'" alt="gb old alt" width="1200" height="630"/>
<!-- /wp:generateblocks/media -->

<!-- wp:paragraph -->
<p>Plain paragraph that must not change.</p>
<!-- /wp:paragraph -->'
ID=$(printf '%s' "$C" | $WP $S post create - --post_type=page --post_status=publish --post_title="Block plugins $RANDOM" --user=admin --porcelain | tr -d '\r' | tail -1)
check "$($WP $S post get $ID --field=post_content)" "$C" "fixture saved verbatim"
render() { curl -sL "$U/?page_id=$ID" | sed -n '/<main/,/<\/main>/p'; }
BEFORE=$(render)
for t in "Spectra heading" "spectra old alt" "Kadence heading" "kadence old alt" "GenerateBlocks heading" "gb old alt"; do
  grep -q "$t" <<<"$BEFORE" && ok "rendered before: $t" || bad "not rendered before: $t"
done
B=$(api $S GET "/posts/$ID?include=blocks")
check "$(echo "$B" | jget "list(b['blockName'] for b in d['blocks'])")" "['uagb/advanced-heading', 'uagb/image', 'kadence/advancedheading', 'kadence/image', 'generateblocks/text', 'generateblocks/media', 'core/paragraph']" "blocks listed with paths"

E='[
 {"target":{"path":"0"},"op":"replace_text","selector":".uagb-heading-text","text":"Spectra heading, edited"},
 {"target":{"path":"0"},"op":"replace_text","selector":".uagb-desc-text","text":"Spectra sub, edited"},
 {"target":{"path":"2"},"op":"set_attr","attrs":{"alt":"Spectra new alt"}},
 {"target":{"path":"4"},"op":"replace_text","text":"Kadence heading, edited"},
 {"target":{"path":"6"},"op":"set_attr","attrs":{"alt":"Kadence new alt"}},
 {"target":{"path":"8"},"op":"replace_text","text":"GenerateBlocks heading, edited"},
 {"target":{"path":"10"},"op":"set_attr","attrs":{"alt":"GB new alt"}}]'
R=$(action $S page.edit_parts "{\"postId\":$ID,\"edits\":$(echo "$E" | tr -d '\n')}")
check "$(echo "$R" | jget "['ok']")" "True" "edit_parts on Spectra/Kadence/GenerateBlocks blocks ok"
EXP=$(printf '%s' "$C" | python3 -c "
import sys; s=sys.stdin.read()
for a,b in [('>Spectra heading<','>Spectra heading, edited<'),('>Spectra sub text<','>Spectra sub, edited<'),
            ('\"alt\":\"spectra old alt\"','\"alt\":\"Spectra new alt\"'),('alt=\"spectra old alt\"','alt=\"Spectra new alt\"'),
            ('>Kadence heading<','>Kadence heading, edited<'),('alt=\"kadence old alt\"','alt=\"Kadence new alt\"'),
            ('>GenerateBlocks heading<','>GenerateBlocks heading, edited<'),
            ('\"alt\":\"gb old alt\"','\"alt\":\"GB new alt\"'),('alt=\"gb old alt\"','alt=\"GB new alt\"')]:
    assert a in s, a; s=s.replace(a,b)
sys.stdout.write(s)")
check "$($WP $S post get $ID --field=post_content)" "$EXP" "only the targeted text/alt changed; attributes and every other block byte-identical"
AFTER=$(render)
NORM=$(printf '%s' "$BEFORE" | python3 -c "
import sys; s=sys.stdin.read()
for a,b in [('Spectra heading','Spectra heading, edited'),('Spectra sub text','Spectra sub, edited'),('spectra old alt','Spectra new alt'),
            ('Kadence heading','Kadence heading, edited'),('kadence old alt','Kadence new alt'),
            ('GenerateBlocks heading','GenerateBlocks heading, edited'),('gb old alt','GB new alt')]:
    s=s.replace(a,b)
sys.stdout.write(s)")
if [ "$AFTER" = "$NORM" ]; then ok "rendered page identical apart from the edited text"; else bad "rendered page differs:"; diff <(echo "$NORM") <(echo "$AFTER") | head -20; fi
# Rollback puts every byte back.
CH=$(echo "$R" | jget "d['result']['changeId']")
api $S POST /changes/$CH/rollback >/dev/null
check "$($WP $S post get $ID --field=post_content)" "$C" "rollback restores the original content"
summary
