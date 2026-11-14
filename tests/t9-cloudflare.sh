#!/bin/bash
# T9 / #333 Cloudflare (official plugin, APO): when the plugin is connected,
# a change purges the changed page's URLs through the plugin's own API
# client (zone lookup, token, APO), only that page (plus its home/archive
# links), never "purge everything"; cachePurged lists Cloudflare only when
# Cloudflare answered success. Not connected -> not listed.
# The Cloudflare API is mocked (cf-mock.py); the site's mu-plugin
# vis-cloudflare-mock.php forwards api.cloudflare.com calls to it.
cd "$(dirname "$0")"; . ./lib.sh
S=${SITE:-17}; PORT=3997; STATE=$(mktemp)
python3 cf-mock.py $PORT "$STATE" & MOCK=$!; trap 'kill $MOCK; $WP $S option delete vis_cf_mock >/dev/null' EXIT; sleep 1
docker cp -q /home/tulasinath/viswp/mu/vis-cloudflare-mock.php viswp-$S:/var/www/html/wp-content/mu-plugins/
$WP $S option update vis_cf_mock "http://host.docker.internal:$PORT/client/v4/" >/dev/null
calls() { python3 -c "import json;print(json.dumps([c for c in json.load(open('$STATE')).get('calls',[]) if '$1' in c['path']]))"; }
reset() { echo '{}' > "$STATE"; }
edit() { action $S page.edit_parts "{\"postId\":$1,\"edits\":[{\"target\":{\"path\":\"0\"},\"op\":\"replace_text\",\"text\":\"$2\"}]}"; }
A=$($WP $S post create --post_type=page --post_status=publish --post_title="T9 CF A $RANDOM" --post_content="<!-- wp:paragraph --><p>one</p><!-- /wp:paragraph -->" --porcelain | tr -d '\r' | tail -1)
B=$($WP $S post create --post_type=page --post_status=publish --post_title="T9 CF B $RANDOM" --porcelain | tr -d '\r' | tail -1)
UA=$($WP $S post url $A | tr -d '\r'); UB=$($WP $S post url $B | tr -d '\r')

# Not connected: not listed as purged.
$WP $S option delete cloudflare_api_key >/dev/null 2>&1; reset
check "$(api $S GET /capabilities | jget "'cloudflare' in d['cachePurge']")" "False" "plugin not connected: Cloudflare not purgeable"
R=$(edit $A "two"); check "$(echo "$R" | jget "d['result']['cachePurged']")" "[]" "not connected: cachePurged []"
# (The Cloudflare plugin's own save_post hook may still try a purge; that is
# the plugin's behaviour on any save, not Visibility's.)

# Connected (API token, zone, APO on), as after signing in to the plugin.
$WP $S eval 'update_option("cloudflare_api_key", str_repeat("t", 40)); update_option("cloudflare_api_email", ""); update_option("cloudflare_cached_domain_name", "localhost"); update_option("automatic_platform_optimization", ["value" => ["enabled" => true, "cf" => true, "wordpress" => true, "wp_plugin" => true]]);' >/dev/null
reset
check "$(api $S GET /capabilities | jget "'cloudflare' in d['cachePurge']")" "True" "connected: Cloudflare purgeable"
R=$(edit $A "three")
check "$(echo "$R" | jget "d['result']['cachePurged']")" "['Cloudflare']" "result: cachePurged = ['Cloudflare']"
P=$(calls purge_cache)
check "$(echo "$P" | jget "len(d)>0 and all(c['method']=='DELETE' and c['path']=='zones/zone123/purge_cache' and c['auth']=='bearer' for c in d)")" "True" "purge sent to the site's zone with the plugin's token"
check "$(echo "$P" | jget "any('$UA' in json.loads(c['body'])['files'] for c in d)" 2>/dev/null || echo "$P" | python3 -c "import sys,json; d=json.load(sys.stdin); print(any('$UA' in json.loads(c['body'])['files'] for c in d))")" "True" "the changed page's URL is purged"
check "$(echo "$P" | python3 -c "import sys,json; d=json.load(sys.stdin); print(any('$UB' in json.loads(c['body'])['files'] for c in d))")" "False" "another page's URL is not purged"
check "$(echo "$P" | python3 -c "import sys,json; d=json.load(sys.stdin); print(any('purge_everything' in c['body'] for c in d))")" "False" "never purge_everything"

# Cloudflare answers an error: not reported as purged.
reset; python3 -c "import json; json.dump({'fail': True}, open('$STATE','w'))"
R=$(edit $A "four")
check "$(echo "$R" | jget "d['result']['cachePurged']")" "[]" "Cloudflare error: not listed as purged"
check "$(echo "$R" | jget "d['ok']")" "True" "the change itself still succeeded"
summary
