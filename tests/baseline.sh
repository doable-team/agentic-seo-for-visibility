#!/bin/bash
# baseline.sh <git-ref> <test-script>: run a check against the plugin as it was
# at <ref> (e.g. 0.9.1), then put the working copy back on the test sites.
cd "$(dirname "$0")/.."
REF=$1; T=$2; TMP=$(mktemp -d)
git archive "$REF" agentic-seo-visibility | tar -x -C "$TMP"
for c in viswp-1 viswp-2 viswp-3 viswp-4; do
  docker exec $c rm -rf /var/www/html/wp-content/plugins/agentic-seo-visibility
  docker cp -q "$TMP/agentic-seo-visibility" $c:/var/www/html/wp-content/plugins/agentic-seo-visibility
done
"tests/$T"; rc=$?
/home/tulasinath/viswp/sync.sh; rm -rf "$TMP"; exit $rc
