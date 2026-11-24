#!/bin/bash
# 2.1.1 (owner): "Update available — update now to avoid errors." in WP admin when
# WordPress's own update data has a newer version of this plugin; the button goes
# straight to update.php?action=upgrade-plugin (nonce'd); dismissible per version;
# always on the plugin's settings page; only for people who can update plugins.
cd "$(dirname "$0")"; . ./lib.sh
S=${SITE:-1}; U="http://localhost:$((PORT_BASE+S))"
F=agentic-seo-visibility/agentic-seo-visibility.php
setnew() { $WP $S eval "\$t = get_site_transient('update_plugins'); if (!is_object(\$t)) \$t = new stdClass; \$t->last_checked = time(); \$t->response = isset(\$t->response) ? \$t->response : []; \$t->response['$F'] = (object) ['slug' => 'agentic-seo-visibility', 'plugin' => '$F', 'new_version' => '$1', 'package' => 'https://downloads.wordpress.org/plugin/agentic-seo-visibility.$1.zip']; set_site_transient('update_plugins', \$t);" >/dev/null; }
nonew() { $WP $S eval "\$t = get_site_transient('update_plugins'); if (is_object(\$t) && isset(\$t->response['$F'])) { unset(\$t->response['$F']); set_site_transient('update_plugins', \$t); }" >/dev/null; }
login() { local cj=$1 u=$2 p=$3; curl -s -c $cj -b $cj -o /dev/null $U/wp-login.php; curl -s -c $cj -b $cj -b 'wordpress_test_cookie=WP+Cookie+check' -o /dev/null -d "log=$u&pwd=$p&wp-submit=Log+In&testcookie=1" $U/wp-login.php; }
CJ=$(mktemp); login $CJ admin admin
$WP $S user meta delete 1 visibility_update_dismissed >/dev/null 2>&1

nonew
grep -q 'visibility-update-notice' <<<"$(curl -s -b $CJ $U/wp-admin/index.php)" && bad "notice with no update" || ok "no notice while up to date"
setnew 99.0.0
H=$(curl -s -b $CJ $U/wp-admin/index.php)
grep -q 'Update available — update now to avoid errors.' <<<"$H" && ok "dashboard shows the notice" || bad "no notice on the dashboard"
grep -q 'Version 99.0.0 is out' <<<"$H" && ok "says which version" || bad "no version"
L=$(grep -oE 'id="visibility-update-now" href="[^"]+"' <<<"$H" | sed 's/.*href="//;s/"$//;s/&#038;/\&/g;s/&amp;/\&/g')
grep -qE 'update\.php\?action=upgrade-plugin&plugin=agentic-seo-visibility%2Fagentic-seo-visibility\.php&_wpnonce=[a-f0-9]{10}' <<<"$L" && ok "Update now goes straight to the plugin update (nonce'd)" || bad "update link: $L"
# Following it reaches WordPress's updater (the nonce is accepted; with a fake package it fails to download, which is fine here).
R=$(curl -s -b $CJ "$U$(sed -E 's#^https?://[^/]+##' <<<"$L")")
grep -q 'The link you followed has expired' <<<"$R" && bad "update.php refused the nonce" || ok "update.php accepts the link"
grep -qiE 'Update Plugin|Updating|Downloading update' <<<"$R" && ok "the WordPress updater runs" || bad "updater page not reached"
setnew 99.0.0
grep -q 'visibility-update-notice' <<<"$(curl -s -b $CJ "$U/wp-admin/admin.php?page=visibility")" && ok "settings page shows it" || bad "not on the settings page"
D=$(grep -oE 'id="visibility-update-dismiss" href="[^"]+"' <<<"$H" | sed 's/.*href="//;s/"$//;s/&#038;/\&/g;s/&amp;/\&/g')
curl -s -b $CJ -o /dev/null -e "$U/wp-admin/index.php" "$D"
grep -q 'visibility-update-notice' <<<"$(curl -s -b $CJ $U/wp-admin/index.php)" && bad "still shown after dismiss" || ok "dismissed for this version"
grep -q 'visibility-update-notice' <<<"$(curl -s -b $CJ "$U/wp-admin/admin.php?page=visibility")" && ok "still on the settings page after dismiss" || bad "gone from the settings page"
setnew 99.0.1
grep -q 'Version 99.0.1 is out' <<<"$(curl -s -b $CJ $U/wp-admin/index.php)" && ok "a newer version shows again" || bad "newer version hidden"
# Someone who can't update plugins never sees it.
$WP $S user delete vis-editor --yes >/dev/null 2>&1; $WP $S user create vis-editor vis-editor@example.com --role=editor --user_pass=editor >/dev/null
CJ2=$(mktemp); login $CJ2 vis-editor editor
grep -q 'visibility-update-notice' <<<"$(curl -s -b $CJ2 $U/wp-admin/index.php)" && bad "editor sees it" || ok "an editor doesn't see it"
$WP $S user delete vis-editor --yes >/dev/null 2>&1
setnew 0.0.1
grep -q 'visibility-update-notice' <<<"$(curl -s -b $CJ $U/wp-admin/index.php)" && bad "older version announced" || ok "an older version isn't announced"
nonew; $WP $S user meta delete 1 visibility_update_dismissed >/dev/null 2>&1; rm -f $CJ $CJ2
summary
