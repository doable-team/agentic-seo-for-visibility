#!/bin/bash
# C-42 TranslatePress (real, free from wordpress.org; en + fr): detected,
# languages and default read, a page reports language = default and its
# translated URLs, edits in the default language (or without one) work,
# an edit or a new draft in another language is refused with
# language_unsupported and nothing changes; TranslatePress's translated text
# is never touched.
cd "$(dirname "$0")"; . ./lib.sh
S=${SITE:-1}; BASE="http://localhost:$((PORT_BASE+S))"
api $S POST /events/site-config-changed '{"controls":null,"allowPublish":true,"disabled":false}' >/dev/null
$WP $S plugin is-active translatepress-multilingual || $WP $S plugin install translatepress-multilingual --activate >/dev/null 2>&1
$WP $S eval '$s=get_option("trp_settings"); $s["translation-languages"]=["en_US","fr_FR"]; $s["publish-languages"]=["en_US","fr_FR"]; $s["url-slugs"]=["en_US"=>"en","fr_FR"=>"fr"]; update_option("trp_settings",$s);' >/dev/null
P=$($WP $S post create --post_type=page --post_status=publish --post_title="TP page $RANDOM" --post_content='<p>Hello world</p>' --porcelain | tr -d '\r' | tail -1)
SLUG=$($WP $S post get $P --field=post_name | tr -d '\r')
C=$(api $S GET /capabilities)
check "$(echo "$C" | jget "(d['multilingual']['slug'], d['multilingual']['languages'], d['multilingual']['default'], d['multilingual']['writable'], 'multilingual.translatepress' in d['features'])")" "('translatepress', ['en', 'fr'], 'en', False, True)" "capabilities: TranslatePress, en+fr, default en, not writable"
R=$(api $S GET /posts/$P)
check "$(echo "$R" | jget "(d['language'], list((t['language'], t['url']) for t in d['translations']))")" "('en', [('en', '$BASE/$SLUG/'), ('fr', '$BASE/fr/$SLUG/')])" "page: language en, translations with URLs"
check "$(api $S GET "/posts?type=page&per_page=5" | jget "d['items'][0]['language']")" "en" "list: language on items"
check "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/fr/$SLUG/")" "200" "French URL answers"
R=$(action $S post.update_content "{\"postId\":$P,\"fields\":{\"title\":\"TP title en\"}}")
check "$(echo "$R" | jget "d['ok']")" "True" "edit without a language: ok (default language)"
R=$(action $S post.update_content "{\"postId\":$P,\"language\":\"en\",\"fields\":{\"title\":\"TP title en2\"}}")
check "$(echo "$R" | jget "d['ok']")" "True" "edit with language en: ok"
R=$(action $S seo_meta.update "{\"postId\":$P,\"language\":\"fr\",\"fields\":{\"metaTitle\":\"Titre\"}}")
check "$(echo "$R" | jget "(d['errorCode'], 'TranslatePress' in d['error'])")" "('language_unsupported', True)" "edit in fr: language_unsupported"
check "$(api $S GET /posts/$P | jget "d['seo']['values']['metaTitle']")" "" "nothing written"
check "$(api $S PATCH /posts/$P '{"title":"x","language":"fr"}' | jget "d['code']")" "visibility_language_unsupported" "REST PATCH in fr refused"
R=$(action $S page.create_draft "{\"title\":\"Brouillon\",\"language\":\"fr\",\"translationOf\":$P}")
check "$(echo "$R" | jget "d['errorCode']")" "language_unsupported" "draft as a fr translation refused"
check "$($WP $S post list --post_type=page --title=Brouillon --post_status=any --format=count | tr -d '\r')" "0" "no draft left behind"
summary
