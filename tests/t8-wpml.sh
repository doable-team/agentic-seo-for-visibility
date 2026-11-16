#!/bin/bash
# T8 / #340 WPML with the REAL WPML Multilingual CMS (en default + fr), through
# WPML's documented hooks only: posts report language + translations (trid
# group), ?lang= lists one language, edits refused without / with the wrong
# language, the French translation untouched when the English source is
# edited, a draft created in a language and linked to its source, a second
# translation in the same language refused. With WooCommerce Multilingual
# active (WCML=1): a product's translation group, product.update needs the
# language and leaves the translation and the shared price as they were.
cd "$(dirname "$0")"; . ./lib.sh
S=${SITE:-2}
code() { echo "$1" | jget "d.get('errorCode')"; }
mk() { # mk <type> <lang> <title> <content> [trid-of]
  $WP $S eval '
    $id = wp_insert_post(["post_type"=>"'$1'","post_status"=>"publish","post_title"=>"'"$3"'","post_content"=>"'"$4"'"]);
    $src = '"${5:-0}"' ? apply_filters("wpml_element_language_details", null, ["element_id"=>'"${5:-0}"',"element_type"=>"'$1'"]) : null;
    do_action("wpml_set_element_language_details", ["element_id"=>$id,"element_type"=>"post_'$1'","trid"=>$src?$src->trid:false,"language_code"=>"'$2'","source_language_code"=>$src?$src->language_code:null]);
    echo $id;' | tr -d '\r' | tail -1
}
EN=$(mk page en "T8W About $RANDOM" "<!-- wp:paragraph --><p>English text</p><!-- /wp:paragraph -->")
FR=$(mk page fr "T8W A propos $RANDOM" "<!-- wp:paragraph --><p>Texte francais</p><!-- /wp:paragraph -->" $EN)
FR_CONTENT=$($WP $S post get $FR --field=post_content); FR_TITLE=$($WP $S post get $FR --field=post_title)

C=$(api $S GET /capabilities)
check "$(echo "$C" | jget "list(d['multilingual'][k] for k in ('slug','default','writable'))")" "['wpml', 'en', True]" "capabilities: WPML (real), default en, writable"
check "$(echo "$C" | jget "d['multilingual']['languages']")" "['en', 'fr']" "capabilities: languages"
R=$(api $S GET /posts/$EN)
check "$(echo "$R" | jget "d['language']")" "en" "GET: language (wpml_element_language_details)"
check "$(echo "$R" | jget "d['translations']")" "{'en': $EN, 'fr': $FR}" "GET: translations (wpml_get_element_translations)"
check "$(api $S GET "/posts?type=page&lang=fr&per_page=100" | jget "$FR in [p['id'] for p in d['items']] and $EN not in [p['id'] for p in d['items']]")" "True" "GET /posts?lang=fr lists only French (wpml_switch_language)"
check "$(api $S GET "/posts?type=page&per_page=100" | jget "$FR in [p['id'] for p in d['items']] and $EN in [p['id'] for p in d['items']]")" "True" "GET /posts without lang lists every language"
check "$(api $S GET "/posts?type=page&lang=fr&per_page=100" | jget "next(p['language'] for p in d['items'] if p['id']==$FR)")" "fr" "list items carry their language"

E="\"edits\":[{\"target\":{\"path\":\"0\"},\"op\":\"replace_text\",\"text\":\"English text, edited\"}]"
R=$(action $S page.edit_parts "{\"postId\":$EN,$E}")
check "$(code "$R")" "language_required" "edit without a language refused"
R=$(action $S page.edit_parts "{\"postId\":$EN,\"language\":\"fr\",$E}")
check "$(code "$R")" "language_mismatch" "edit with the wrong language refused"
check "$(echo "$R" | jget "'The fr version is post $FR' in d['error']")" "True" "mismatch names the French version"
R=$(action $S seo_meta.update "{\"postId\":$EN,\"fields\":{\"metaTitle\":\"x\"}}")
check "$(code "$R")" "language_required" "SEO edit without a language refused"
CODE=$(curl -s -o /tmp/t8w.json -w '%{http_code}' -X PATCH -H "Authorization: Bearer vis-test-token-$S" -H 'Content-Type: application/json' --data '{"title":"x"}' "http://localhost:$((PORT_BASE+S))/wp-json/visibility/v1/posts/$EN")
check "$CODE $(jget "['code']" </tmp/t8w.json)" "409 visibility_language_required" "PATCH without a language -> 409"

R=$(action $S page.edit_parts "{\"postId\":$EN,\"language\":\"en\",$E}")
check "$(echo "$R" | jget "['ok']")" "True" "edit with language en ok"
R=$(action $S seo_meta.update "{\"postId\":$EN,\"language\":\"en\",\"fields\":{\"metaTitle\":\"English SEO title\"}}")
check "$(echo "$R" | jget "['ok']")" "True" "SEO edit (Yoast) with language ok"
check "$($WP $S post get $FR --field=post_content)" "$FR_CONTENT" "French translation content untouched"
check "$($WP $S post get $FR --field=post_title)" "$FR_TITLE" "French translation title untouched"
check "$($WP $S post meta get $FR _yoast_wpseo_title 2>/dev/null)" "" "French translation SEO untouched"
check "$(api $S GET /posts/$EN | jget "d['translations']")" "{'en': $EN, 'fr': $FR}" "translation group intact"

# New translation draft linked to the source (wpml_set_element_language_details).
$WP $S eval 'wpml_get_setup_instance()->set_active_languages(["en","fr","de"]);' >/dev/null
R=$(action $S page.create_draft "{\"title\":\"Ueber uns\",\"content\":\"<p>Deutscher Text</p>\",\"language\":\"de\",\"translationOf\":$EN}")
check "$(echo "$R" | jget "['ok']")" "True" "page.create_draft in de linked to the source"
NEW=$(echo "$R" | jget "d['result']['postId']")
check "$(api $S GET /posts/$NEW | jget "d['language']")" "de" "new draft is German"
check "$(api $S GET /posts/$EN | jget "d['translations']")" "{'en': $EN, 'fr': $FR, 'de': $NEW}" "linked into the source's group; fr kept"
check "$($WP $S eval "\$d=apply_filters('wpml_element_language_details',null,['element_id'=>$NEW,'element_type'=>'page']); echo \$d->source_language_code;")" "en" "WPML records en as its source language"
R=$(action $S page.create_draft "{\"title\":\"Encore\",\"language\":\"fr\",\"translationOf\":$EN}")
check "$(code "$R")" "conflict" "a second fr translation refused"
R=$(action $S page.create_draft "{\"title\":\"x\",\"language\":\"xx\"}")
check "$(code "$R")" "invalid" "unknown language refused"
P=$(api $S POST /posts "{\"title\":\"REST fr page\",\"type\":\"page\",\"language\":\"fr\"}")
check "$(echo "$P" | jget "d['language']")" "fr" "POST /posts with language"
$WP $S eval 'wpml_get_setup_instance()->set_active_languages(["en","fr"]);' >/dev/null

if [ "${WCML:-0}" = "1" ]; then
  PEN=$($WP $S eval '$p=new WC_Product_Simple(); $p->set_name("T8W Mug"); $p->set_status("publish"); $p->set_regular_price("12"); $p->set_description("EN desc"); $id=$p->save(); do_action("wpml_set_element_language_details",["element_id"=>$id,"element_type"=>"post_product","trid"=>false,"language_code"=>"en"]); echo $id;' | tr -d '\r' | tail -1)
  PFR=$($WP $S eval '$p=new WC_Product_Simple(); $p->set_name("T8W Tasse"); $p->set_status("publish"); $p->set_regular_price("12"); $p->set_description("FR desc"); $id=$p->save(); $src=apply_filters("wpml_element_language_details",null,["element_id"=>'$PEN',"element_type"=>"product"]); do_action("wpml_set_element_language_details",["element_id"=>$id,"element_type"=>"post_product","trid"=>$src->trid,"language_code"=>"fr","source_language_code"=>"en"]); echo $id;' | tr -d '\r' | tail -1)
  check "$(api $S GET /posts/$PEN | jget "d['translations']")" "{'en': $PEN, 'fr': $PFR}" "WCML: product translation group"
  R=$(action $S product.update "{\"productId\":$PEN,\"fields\":{\"description\":\"EN new desc\"}}")
  check "$(code "$R")" "language_required" "WCML: product.update without a language refused"
  R=$(action $S product.update "{\"productId\":$PEN,\"language\":\"en\",\"fields\":{\"description\":\"EN new desc\"}}")
  check "$(echo "$R" | jget "['ok']")" "True" "WCML: product.update in en ok"
  check "$($WP $S eval "echo wc_get_product($PFR)->get_description().'|'.wc_get_product($PFR)->get_regular_price().'|'.wc_get_product($PEN)->get_regular_price();")" "FR desc|12|12" "WCML: French product + both prices untouched"
  R=$(action $S product.update "{\"productId\":$PEN,\"language\":\"en\",\"fields\":{\"regular_price\":\"1\"}}")
  check "$(code "$R")" "not_allowed" "WCML: price refused"
fi
summary
