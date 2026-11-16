#!/bin/bash
# C-41 theme SEO fields, no SEO plugin active. THEME=divi (real Divi 4.21
# copy, not shipped), genesis or avada (stand-ins in standins/themes: storage
# and output as the themes' public code shows them).
# capabilities.seo reports the theme; seo_meta.update writes title and
# description into the theme's own fields; the live head shows each tag once
# (from the theme where it prints them, else from Visibility); rollback.
cd "$(dirname "$0")"; . ./lib.sh
S=${SITE:-1}; THEME=${THEME:-divi}; BASE="http://localhost:$((PORT_BASE+S))"
api $S POST /events/site-config-changed '{"controls":null,"allowPublish":true,"disabled":false}' >/dev/null
case $THEME in
  divi) SLUG=Divi; $WP $S theme activate Divi >/dev/null 2>&1 ;;
  genesis) SLUG=genesis; $CTR cp standins/themes/genesis ${CPFX}$S:/var/www/html/wp-content/themes/; $WP $S theme activate genesis >/dev/null ;;
  avada) SLUG=Avada; $CTR cp standins/themes/Avada ${CPFX}$S:/var/www/html/wp-content/themes/; $WP $S theme activate Avada >/dev/null ;;
esac
$CTR exec ${CPFX}$S chown -R www-data:www-data /var/www/html/wp-content/themes
count() { python3 -c "import sys,re;h=sys.stdin.read();print(len(re.findall(sys.argv[1],h)))" "$1"; }
P=$($WP $S post create --post_type=page --post_status=publish --post_title="Theme SEO $THEME $RANDOM" --post_content='<p>Body text of the page.</p>' --porcelain | tr -d '\r' | tail -1)

run() { # run <label>
  C=$(api $S GET /capabilities)
  check "$(echo "$C" | jget "(d['seo']['plugin'], d['seo']['writable'], d['seo']['theme'], 'seo.theme' in d['features'])")" "('$THEME', True, True, True)" "$1: capabilities.seo = $THEME theme fields"
  R=$(action $S seo_meta.update "{\"postId\":$P,\"fields\":{\"metaTitle\":\"Theme title $1\",\"metaDescription\":\"Theme description $1\",\"robotsNoindex\":true}}")
  check "$(echo "$R" | jget "(d['ok'], d['result']['seoPlugin'], sorted(d['result']['fields']))")" "(True, '$THEME', ['metaDescription', 'metaTitle', 'robotsNoindex'])" "$1: seo_meta.update ok"
  CH=$(echo "$R" | jget "d['result']['changeId']")
  check "$(api $S GET /posts/$P | jget "(d['seo']['values']['metaTitle'], d['seo']['values']['metaDescription'])")" "('Theme title $1', 'Theme description $1')" "$1: read back"
  H=$(curl -sL "$BASE/?page_id=$P")
  check "$(echo "$H" | count "<title>Theme title $1")" "1" "$1: live <title> once"
  check "$(echo "$H" | count "name=\"description\" content=\"Theme description $1\"")" "1" "$1: live meta description once"
  check "$(echo "$H" | count "name=\"description\"")" "1" "$1: only one meta description at all"
  check "$(echo "$H" | count "<meta name='robots'[^>]*noindex")" "1" "$1: robots noindex once"
  check "$(echo "$H" | count "property=\"og:title\"")" "1" "$1: og:title once"
  R=$(api $S POST /changes/$CH/rollback)
  check "$(echo "$R" | jget "d['ok']")" "True" "$1: rollback"
  check "$(curl -sL "$BASE/?page_id=$P" | count "Theme title $1")" "0" "$1: title gone after rollback"
}
case $THEME in
  divi)
    # Divi with its ePanel SEO off (default): fields in Divi's custom fields, Visibility prints them.
    $WP $S eval 'foreach (["seo_single_title","seo_single_description","seo_single_canonical"] as $o) et_update_option("divi_" . $o, "false");' >/dev/null
    run "divi-off"
    check "$($WP $S post meta get $P seo_title | tr -d '\r')" "" "divi-off: Divi's seo_title cleared by rollback"
    R=$(action $S seo_meta.update "{\"postId\":$P,\"fields\":{\"metaTitle\":\"Stored in Divi\"}}")
    check "$($WP $S post meta get $P seo_title | tr -d '\r')" "Stored in Divi" "divi: title stored in Divi's own custom field (seo_title)"
    api $S POST /changes/$(echo "$R" | jget "d['result']['changeId']")/rollback >/dev/null
    # Divi with custom titles/descriptions on: Divi prints them, Visibility stands down for those tags.
    $WP $S eval 'foreach (["seo_single_title","seo_single_description"] as $o) et_update_option("divi_" . $o, "on"); et_update_option("divi_seo_single_field_title", "my_seo_title"); et_update_option("divi_seo_single_field_description", "seo_description");' >/dev/null
    check "$(api $S GET /capabilities | jget "d['seo']['themePrints']")" "['title', 'description']" "divi-on: themePrints title + description"
    run "divi-on"
    R=$(action $S seo_meta.update "{\"postId\":$P,\"fields\":{\"metaTitle\":\"Custom field name\"}}")
    check "$($WP $S post meta get $P my_seo_title | tr -d '\r')" "Custom field name" "divi-on: uses the field name set in Divi's options"
    api $S POST /changes/$(echo "$R" | jget "d['result']['changeId']")/rollback >/dev/null
    $WP $S eval 'et_update_option("divi_seo_single_field_title", "seo_title");' >/dev/null ;;
  genesis)
    run "genesis"
    R=$(action $S seo_meta.update "{\"postId\":$P,\"fields\":{\"metaDescription\":\"In Genesis\",\"canonical\":\"$BASE/canon/\"}}")
    check "$($WP $S post meta get $P _genesis_description | tr -d '\r')|$($WP $S post meta get $P _genesis_canonical_uri | tr -d '\r')" "In Genesis|$BASE/canon/" "genesis: stored in _genesis_description / _genesis_canonical_uri"
    check "$(curl -sL "$BASE/?page_id=$P" | count 'rel="canonical"')" "1" "genesis: one canonical"
    api $S POST /changes/$(echo "$R" | jget "d['result']['changeId']")/rollback >/dev/null ;;
  avada)
    $WP $S option update fusion_options '{"status_opengraph":"1"}' --format=json >/dev/null
    check "$(api $S GET /capabilities | jget "(d['seo']['themePrints'], 'ogTitle' in d['seo']['fields'])")" "(['title', 'robots', 'description', 'og'], False)" "avada (OG on): Avada prints title/robots/description/og; ogTitle not offered"
    run "avada-og"
    R=$(action $S seo_meta.update "{\"postId\":$P,\"fields\":{\"metaTitle\":\"In Avada\",\"robotsNofollow\":true}}")
    check "$($WP $S eval "\$m=get_post_meta($P,'_fusion',true); echo \$m['seo_title'].'|'.\$m['seo_follow'];" | tr -d '\r')" "In Avada|no" "avada: stored in _fusion seo_title / seo_follow=no"
    api $S POST /changes/$(echo "$R" | jget "d['result']['changeId']")/rollback >/dev/null
    R=$(action $S seo_meta.update "{\"postId\":$P,\"fields\":{\"ogTitle\":\"x\"}}")
    check "$(echo "$R" | jget "d['errorCode']")" "unsupported" "avada (OG on): ogTitle refused (Avada prints its own)"
    $WP $S option update fusion_options '{"status_opengraph":"0"}' --format=json >/dev/null
    run "avada-noog" ;;
esac
summary
