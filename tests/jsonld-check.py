#!/usr/bin/env python3
"""Exit 0 when the page (stdin) carries the injected FAQ (marker argv[1]) in
exactly one JSON-LD node across all JSON-LD blocks, and every block parses."""
import sys, re, json
mark = sys.argv[1]; html = sys.stdin.read()
blocks = re.findall(r'<script[^>]*application/ld\+json[^>]*>(.*?)</script>', html, re.S)
nodes = 0
for b in blocks:
    d = json.loads(b)
    graph = d.get('@graph', [d]) if isinstance(d, dict) else d
    nodes += sum(1 for n in graph if mark in json.dumps(n))
print("   %d JSON-LD block(s) on the page, %d node(s) with the injected schema" % (len(blocks), nodes))
sys.exit(0 if nodes == 1 else 1)
