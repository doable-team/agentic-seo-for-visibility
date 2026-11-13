#!/bin/bash
# php -l every plugin file on PHP 7.4 (the minimum) and 8.3.
cd "$(dirname "$0")/.."
rc=0
for img in php:7.4-cli wordpress:cli-php8.3; do
  out=$(tar -c agentic-seo-visibility | timeout 120 docker run --rm -i --entrypoint sh $img -c 'mkdir -p /tmp/p && cd /tmp/p && tar -x && for f in $(find . -name "*.php"); do php -l "$f" >/dev/null 2>/tmp/e || { cat /tmp/e; php -l "$f"; exit 1; }; done; php -v | head -1')
  echo "$img: $out"; [ $? -eq 0 ] && echo "$out" | grep -q "^PHP" || rc=1
done
exit $rc
