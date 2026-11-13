#!/bin/bash
# #294: GET /capabilities. Site 2 runs Elementor + Yoast + a WP Rocket
# detection stub (mu-plugin defining WP_ROCKET_VERSION) + LiteSpeed Cache.
cd "$(dirname "$0")"; . ./lib.sh
C=$(api 2 GET /capabilities)
c() { echo "$C" | jget "$1"; }
check "$(c "['seo']['plugin']")" "yoast" "SEO plugin = yoast"
check "$(c "['seo']['version'] is not None")" "True" "SEO plugin version reported"
check "$(c "list(b['slug'] for b in d['builders'])")" "['elementor']" "builder = elementor"
check "$(c "'wp-rocket' in [x['slug'] for x in d['caches']]")" "True" "cache: WP Rocket"
check "$(c "'litespeed-cache' in [x['slug'] for x in d['caches']]")" "True" "cache: LiteSpeed Cache"
check "$(c "['theme']['blockTheme']")" "False" "classic theme reported"
check "$(c "['editor']")" "classic" "Classic Editor -> editor classic"
check "$(c "sorted(p['name'] for p in d['postTypes'] if p['name'] in ('post','page'))")" "['page', 'post']" "post types post + page"
check "$(c "list(p['name'] for p in d['postTypes'] if p['name'] in ('attachment','elementor_library','e-floating-buttons'))")" "[]" "attachments and Elementor templates not listed"
check "$(c "['permissions']")" "{'allowPublish': True, 'paused': False}" "owner switches"
C1=$(api 1 GET /capabilities)
check "$(echo "$C1" | jget "['seo']['plugin']")" "None" "site 1: no SEO plugin"
check "$(echo "$C1" | jget "['seo']['renderedBy']")" "visibility" "site 1: tags rendered by Visibility"
check "$(echo "$C1" | jget "['theme']['blockTheme']")" "True" "site 1: block theme"
check "$(echo "$C1" | jget "['editor']")" "block" "site 1: block editor"
check "$(api 3 GET /capabilities | jget "['seo']['plugin']")" "rankmath" "site 3: rankmath"
check "$(api 4 GET /capabilities | jget "['seo']['plugin']")" "aioseo" "site 4: aioseo"
check "$(curl -s -o /dev/null -w '%{http_code}' http://localhost:8092/wp-json/visibility/v1/capabilities)" "401" "needs the site token"
summary
