#!/bin/bash
# T8 / #334 Polylang (2 languages: en default, fr): posts report language and
# translations; an edit without a language is refused, a wrong language is
# refused; editing the source leaves its translation untouched; a draft can be
# created in a language and linked to its source; GET /posts?lang=.
cd "$(dirname "$0")"; . ./lib.sh
S=${SITE:-1}
code() { echo "$1" | jget "d.get('errorCode')"; }
EN=$($WP $S post create --post_type=page --post_status=publish --post_title="T8 About $RANDOM" --post_content='<!-- wp:paragraph --><p>English text</p><!-- /wp:paragraph -->' --porcelain | tr -d '\r' | tail -1)
FR=$($WP $S post create --post_type=page --post_status=publish --post_title="T8 A propos $RANDOM" --post_content='<!-- wp:paragraph --><p>Texte français</p><!-- /wp:paragraph -->' --porcelain | tr -d '\r' | tail -1)
$WP $S eval "pll_set_post_language($EN,'en'); pll_set_post_language($FR,'fr'); pll_save_post_translations(['en'=>$EN,'fr'=>$FR]);" >/dev/null
$WP $S post meta update $FR rank_fr_marker keep >/dev/null
FR_CONTENT=$($WP $S post get $FR --field=post_content); FR_TITLE=$($WP $S post get $FR --field=post_title)

C=$(api $S GET /capabilities)
check "$(echo "$C" | jget "[d['multilingual'][k] for k in ('slug','default')]" 2>/dev/null || echo "$C" | jget "list(d['multilingual'][k] for k in ('slug','default'))")" "['polylang', 'en']" "capabilities: polylang, default en"
check "$(echo "$C" | jget "d['multilingual']['languages']")" "['en', 'fr']" "capabilities: languages"
R=$(api $S GET /posts/$EN)
check "$(echo "$R" | jget "d['language']")" "en" "GET: language"
check "$(echo "$R" | jget "d['translations']")" "{'en': $EN, 'fr': $FR}" "GET: translations"
check "$(api $S GET "/posts?type=page&lang=fr&per_page=100" | jget "$FR in [p['id'] for p in d['items']] and $EN not in [p['id'] for p in d['items']]")" "True" "GET /posts?lang=fr lists only French"
check "$(api $S GET "/posts?type=page&per_page=100" | jget "$FR in [p['id'] for p in d['items']] and $EN in [p['id'] for p in d['items']]")" "True" "GET /posts without lang lists every language"

# Edits need the language.
E="\"edits\":[{\"target\":{\"path\":\"0\"},\"op\":\"replace_text\",\"text\":\"English text, edited\"}]"
R=$(action $S page.edit_parts "{\"postId\":$EN,$E}")
check "$(code "$R")" "language_required" "edit without a language refused"
R=$(action $S page.edit_parts "{\"postId\":$EN,\"language\":\"fr\",$E}")
check "$(code "$R")" "language_mismatch" "edit with the wrong language refused"
R=$(action $S seo_meta.update "{\"postId\":$EN,\"fields\":{\"metaTitle\":\"x\"}}")
check "$(code "$R")" "language_required" "SEO edit without a language refused"
CODE=$(curl -s -o /tmp/t8.json -w '%{http_code}' -X PATCH -H "Authorization: Bearer vis-test-token-$S" -H 'Content-Type: application/json' --data '{"title":"x"}' "http://localhost:$((8180+S))/wp-json/visibility/v1/posts/$EN")
check "$CODE $(jget "['code']" </tmp/t8.json)" "409 visibility_language_required" "PATCH without a language -> 409 visibility_language_required"

# Edit the source in its language: the translation is untouched.
R=$(action $S page.edit_parts "{\"postId\":$EN,\"language\":\"en\",$E}")
check "$(echo "$R" | jget "['ok']")" "True" "edit with language en ok"
R=$(action $S seo_meta.update "{\"postId\":$EN,\"language\":\"en\",\"fields\":{\"metaTitle\":\"English SEO title\"}}")
check "$(echo "$R" | jget "['ok']")" "True" "SEO edit with language ok"
check "$($WP $S post get $FR --field=post_content)" "$FR_CONTENT" "French translation content untouched"
check "$($WP $S post get $FR --field=post_title)" "$FR_TITLE" "French translation title untouched"
check "$($WP $S post meta get $FR _visibility_seo_title 2>/dev/null)" "" "French translation SEO untouched"
check "$($WP $S eval "echo json_encode(pll_get_post_translations($EN));")" "{\"en\":$EN,\"fr\":$FR}" "translation group intact"

# New translation draft linked to its source.
DE=$($WP $S eval '$r = PLL()->model->add_language(["name"=>"Deutsch","slug"=>"de","locale"=>"de_DE","rtl"=>0,"term_group"=>2]); PLL()->model->clean_languages_cache(); echo "ok";')
R=$(action $S page.create_draft "{\"title\":\"Über uns\",\"content\":\"<p>Deutscher Text</p>\",\"language\":\"de\",\"translationOf\":$EN}")
check "$(echo "$R" | jget "['ok']")" "True" "page.create_draft in de linked to the source"
NEW=$(echo "$R" | jget "d['result']['postId']")
check "$($WP $S eval "echo pll_get_post_language($NEW);")" "de" "new draft is German"
check "$($WP $S eval "echo json_encode(pll_get_post_translations($EN));")" "{\"en\":$EN,\"fr\":$FR,\"de\":$NEW}" "linked into the source's translation group; fr kept"
R=$(action $S page.create_draft "{\"title\":\"Encore\",\"language\":\"fr\",\"translationOf\":$EN}")
check "$(code "$R")" "conflict" "a second fr translation of the same source refused"
R=$(action $S page.create_draft "{\"title\":\"x\",\"language\":\"xx\"}")
check "$(code "$R")" "invalid" "unknown language refused"
P=$(api $S POST /posts "{\"title\":\"REST fr page\",\"type\":\"page\",\"language\":\"fr\"}")
check "$(echo "$P" | jget "d['language']")" "fr" "POST /posts with language"
$WP $S eval 'PLL()->model->delete_language(PLL()->model->get_language("de")->term_id);' >/dev/null 2>&1
summary
