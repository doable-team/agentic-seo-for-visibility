#!/bin/bash
# T16 / #281: after uninstall no visibility_* options, transients or cron
# events remain; SEO fields saved on posts are kept by default. Uninstalls the
# plugin on site 1 and puts it back afterwards.
cd "$(dirname "$0")"; . ./lib.sh
S=1
# State a paired, busy site would have (token stays valid for the calls).
$WP $S option update visibility_project_name "Project X" >/dev/null
$WP $S option update visibility_company_name "Company Y" >/dev/null
$WP $S transient set visibility_test_transient 1 3600 >/dev/null
action $S post.create_draft '{"title":"T16 record"}' >/dev/null   # leaves a visibility_req_* record
P=$($WP $S post create --post_status=publish --post_title="T16 seo" --porcelain | tr -d '\r')
action $S seo_meta.update "{\"postId\":$P,\"fields\":{\"metaDescription\":\"kept\"}}" >/dev/null
check "$($WP $S post meta get $P _visibility_seo_description)" "kept" "SEO field stored before uninstall"
$WP $S cron event schedule visibility_pull_approved_requests now visibility_every_5_min >/dev/null 2>&1
echo "before: $($WP $S option list --search='visibility_*' --format=count) visibility_* options, $($WP $S cron event list --format=csv --fields=hook | grep -c visibility) cron events"
$WP $S plugin deactivate agentic-seo-visibility >/dev/null
check "$($WP $S cron event list --format=csv --fields=hook | grep -c visibility)" "0" "deactivate: no visibility cron events"
$WP $S plugin uninstall agentic-seo-visibility >/dev/null
check "$($WP $S option list --search='visibility_*' --format=count)" "0" "uninstall: no visibility_* options (token included)"
check "$($WP $S option list --search='_transient_visibility_*' --format=count)" "0" "uninstall: no visibility transients"
check "$($WP $S cron event list --format=csv --fields=hook | grep -c visibility)" "0" "uninstall: no visibility cron events"
check "$($WP $S post meta get $P _visibility_seo_description)" "kept" "SEO fields on posts kept by default"
# Put the plugin back for the other checks.
/home/tulasinath/viswp/sync.sh
$WP $S plugin activate agentic-seo-visibility >/dev/null
$WP $S option update visibility_site_token vis-test-token-$S >/dev/null
$WP $S option update visibility_site_id test-site-$S >/dev/null
summary
