#!/bin/bash
# #561: what a redirect source may be, on a root install and on a folder install
# (home URL .../blog/, made with fixtures/vis-folder-home.php). The home page and
# WordPress's admin, login and REST addresses are refused from the domain root and
# from the folder; //host/… sources and broken %-escapes are refused; a normal
# address in the folder is still accepted.
cd "$(dirname "$0")"; . ./lib.sh
S=${SITE:-1}; N=$RANDOM
api $S POST /events/site-config-changed '{"controls":null,"allowPublish":true,"disabled":false}' >/dev/null
try() { action $S redirect.create "{\"source\":\"$1\",\"target\":\"https://example.org/\"}"; }
refused() { R=$(try "$1"); check "$(echo "$R" | jget "(d['ok'], d.get('errorCode'))")" "(False, 'invalid')" "$2: $1 refused"; [ "$(echo "$R" | jget "d['ok']")" = True ] && action $S redirect.delete "{\"redirectId\":\"$(echo "$R" | jget "d['result']['redirectId']")\"}" >/dev/null; }
accepted() { R=$(try "$1"); check "$(echo "$R" | jget "d['ok']")" "True" "$2: $1 accepted"; [ "$(echo "$R" | jget "d['ok']")" = True ] && action $S redirect.delete "{\"redirectId\":\"$(echo "$R" | jget "d['result']['redirectId']")\"}" >/dev/null; }

for src in / /wp-login.php /wp-admin/ /wp-json/x '//example.com/wp-admin' '//other.example.net/x' '/%77p-admin%ZZ' '/%ZZ%77p-login.php' '/%2577p-admin/' '/%77p-json/'; do refused "$src" "root"; done
accepted "/t-src-$N/" "root"

$CTR cp fixtures/vis-folder-home.php ${CPFX}$S:/var/www/html/wp-content/mu-plugins/vis-folder-home.php
trap '$CTR exec ${CPFX}$S rm -f /var/www/html/wp-content/mu-plugins/vis-folder-home.php' EXIT
check "$($WP $S eval 'echo home_url("/");' | tr -d '\r')" "http://localhost:$((PORT_BASE+S))/blog/" "folder install: home is /blog/"
for src in /blog /blog/ /blog/wp-login.php /blog/wp-admin/ /blog/wp-json/x '/blog/%77p-admin/' / /wp-login.php '//localhost/blog/wp-admin'; do refused "$src" "folder"; done
accepted "/blog/t-src-$N/" "folder"
summary
