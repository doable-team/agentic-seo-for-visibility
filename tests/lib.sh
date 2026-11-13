#!/bin/bash
# Shared helpers for the end-to-end checks against the Docker test sites.
# Site N: http://localhost:809N, token vis-test-token-N (see tests/README.md).
WP=/home/tulasinath/viswp/wp.sh
pass=0; fail=0
ok()   { echo "PASS  $*"; pass=$((pass+1)); }
bad()  { echo "FAIL  $*"; fail=$((fail+1)); }
check(){ if [ "$1" = "$2" ]; then ok "$3"; else bad "$3 (expected [$2], got [$1])"; fi; }
api()  { # api <site> <METHOD> <path> [json]
  local n=$1 m=$2 p=$3 d=$4
  if [ -n "$d" ]; then
    curl -s -X "$m" -H "Authorization: Bearer vis-test-token-$n" -H 'Content-Type: application/json' --data "$d" "http://localhost:809$n/wp-json/visibility/v1$p"
  else
    curl -s -X "$m" -H "Authorization: Bearer vis-test-token-$n" "http://localhost:809$n/wp-json/visibility/v1$p"
  fi
}
action() { # action <site> <actionType> <payload-json> [requestId]
  local rid=${4:-t-$RANDOM$RANDOM}
  api "$1" POST /events/action-approved "{\"requestId\":\"$rid\",\"actionType\":\"$2\",\"actionPayload\":$3}"
}
jget() { # jget "['a']['b']" reads d['a']['b']; any other python expression on d is evaluated as is
  python3 -c "import sys,json; d=json.load(sys.stdin); e=sys.argv[1]; print(eval('d'+e if e.startswith('[') else e))" "$1"; }
summary(){ echo "---- $pass passed, $fail failed"; [ $fail -eq 0 ]; }
