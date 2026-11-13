#!/bin/bash
# Run the standalone unit tests on PHP 7.4 and 8.3.
cd "$(dirname "$0")/../.."
rc=0
for img in php:7.4-cli wordpress:cli-php8.3; do
  tar -c agentic-seo-visibility tests/unit | timeout 120 docker run --rm -i --entrypoint sh $img -c 'mkdir -p /tmp/w && cd /tmp/w && tar -x && for f in tests/unit/*-test.php; do php $f || exit 1; done' || rc=1
done
exit $rc
