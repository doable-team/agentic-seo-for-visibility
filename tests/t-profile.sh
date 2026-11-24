#!/bin/bash
# #471 (2.1.1): GET /profile — registered and used blocks (with a sample of the
# site's own markup), taxonomies with terms, public meta keys only, allowlisted
# options only, how recent posts are built, the template; a hash in
# /capabilities that changes with the content; the owner's off switch.
cd "$(dirname "$0")"; . ./lib.sh
S=${SITE:-1}
$WP $S option delete visibility_profile_off >/dev/null 2>&1
C=$($WP $S term create category "Profile cat $RANDOM" --porcelain | tr -d '\r' | tail -1)
BODY='<!-- wp:heading --><h2>Intro</h2><!-- /wp:heading --><!-- wp:paragraph --><p>Text</p><!-- /wp:paragraph --><!-- wp:quote --><blockquote class="wp-block-quote"><!-- wp:paragraph --><p>Quoted.</p><!-- /wp:paragraph --></blockquote><!-- /wp:quote -->'
for i in 1 2; do P=$($WP $S post create --post_status=publish --post_title="Profile $i $RANDOM" --post_content="$BODY" --post_category=$C --porcelain | tr -d '\r' | tail -1); done
$WP $S post meta add $P reading_time "4 min" >/dev/null
$WP $S post meta add $P _private_thing "x" >/dev/null
$WP $S post meta add $P my_api_key "s3cr3t" >/dev/null
D=$($WP $S post create --post_status=draft --post_title="Draft secret words" --post_content="<!-- wp:paragraph --><p>Unpublished draft text</p><!-- /wp:paragraph -->" --porcelain | tr -d '\r' | tail -1)
$WP $S transient delete visibility_site_profile >/dev/null 2>&1
R=$(api $S GET /profile); echo "$R" > /tmp/profile-$S.json
check "$(echo "$R" | jget "any(b['name']=='core/quote' for b in d['blocks']['registered'])")" "True" "core/quote listed as registered"
check "$(echo "$R" | jget "str([b for b in d['blocks']['used'] if b['name']=='core/quote'][0]['posts']>=2)")" "True" "core/quote listed as used, with post count"
check "$(echo "$R" | jget "'<blockquote' in [b for b in d['blocks']['used'] if b['name']=='core/quote'][0]['sample']")" "True" "sample markup from a real post"
check "$(echo "$R" | jget "any(t['id']==$C for x in d['taxonomies'] if x['name']=='category' for t in x['terms'])")" "True" "categories listed"
check "$(echo "$R" | jget "any(x['name']=='post_tag' for x in d['taxonomies'])")" "True" "tags listed"
check "$(echo "$R" | jget "str([m['sample'] for m in d['postMeta'] if m['key']=='reading_time'])")" "['4 min']" "a public meta key with its sample"
check "$(echo "$R" | jget "any(m['key'].startswith('_') or 'api_key' in m['key'] for m in d['postMeta'])")" "False" "no private or secret-looking meta keys"
check "$(echo "$R" | jget "sorted(d['options'].keys())==sorted(['permalink_structure','show_on_front','page_on_front','page_for_posts','default_category','blogname','blogdescription','timezone_string','posts_per_page','WPLANG'])")" "True" "only allowlisted options"
check "$(echo "$R" | jget "'Unpublished draft text' in json.dumps(d) if False else 'Unpublished draft text' in str(d)")" "False" "no draft text"
check "$(echo "$R" | jget "'core/quote' in d['postPatterns']['examples'][0] and d['postPatterns']['postsRead']>=2")" "True" "how the latest post is built (its block order)"
check "$(echo "$R" | jget "d['templates']['kind'] in ('block','classic') and d['templates']['single'] is not None")" "True" "template reported"
H1=$(api $S GET /capabilities | jget "d['profile']['hash']")
check "$H1" "$(echo "$R" | jget "d['hash']")" "capabilities carries the profile hash"
$WP $S term create post_tag "Profile tag $RANDOM" >/dev/null
check "$([ "$(api $S GET /capabilities | jget "d['profile']['hash']")" != "$H1" ] && echo changed)" "changed" "hash changes when a tag is added"
$WP $S option update visibility_profile_off 1 >/dev/null
check "$(curl -s -o /dev/null -w '%{http_code}' -H "Authorization: Bearer vis-test-token-$S" http://localhost:$((PORT_BASE+S))/wp-json/visibility/v1/profile)" "403" "owner switch off: /profile refused"
check "$(api $S GET /capabilities | jget "d['profile']")" "{'enabled': False}" "capabilities says it's off"
$WP $S option delete visibility_profile_off >/dev/null
summary
