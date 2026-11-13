#!/usr/bin/env python3
"""Check a page's <head> (stdin) against expected SEO values (argv[1] = JSON).

Every expected tag must appear exactly once with the expected value.
Keys: title, description, canonical, robots_contains, og:title,
og:description, og:image, twitter:title, twitter:description, twitter:image.
Prints PASS/FAIL lines; exits 1 on any failure."""
import sys, json, re, html as H
exp = json.loads(sys.argv[1]); label = sys.argv[2] if len(sys.argv) > 2 else ""
page = sys.stdin.read()
head = page.split("</head>")[0]
def metas(attr, name):
    out = []
    for tag in re.findall(r"<meta\b[^>]*>", head):
        if re.search(r'%s=["\']%s["\']' % (attr, re.escape(name)), tag):
            m = re.search(r'content=["\']([^"\']*)["\']', tag)
            out.append(H.unescape(m.group(1)) if m else "")
    return out
fail = 0
def report(ok, msg):
    global fail
    print(("PASS  " if ok else "FAIL  ") + label + msg)
    if not ok: fail += 1
for k, v in exp.items():
    if k == "title":
        got = [H.unescape(t).strip() for t in re.findall(r"<title[^>]*>(.*?)</title>", head, re.S)]
    elif k == "canonical":
        got = re.findall(r'<link\b[^>]*rel=["\']canonical["\'][^>]*href=["\']([^"\']*)["\']', head)
    elif k == "robots_contains":
        got = metas("name", "robots")
        report(len(got) == 1 and all(x in got[0] for x in v), "robots once, contains %s (got %s)" % (v, got)); continue
    elif k.startswith("og:"):
        got = metas("property", k)
    else:
        got = metas("name", k)
    if k in ("og:image", "twitter:image"):
        # SEO plugins may switch an image URL to the site's own scheme.
        strip = lambda u: re.sub(r"^https?:", "", u)
        report([strip(g) for g in got] == [strip(v)], "%s once = %r (got %r)" % (k, v, got)); continue
    report(got == [v], "%s once = %r (got %r)" % (k, v, got))
sys.exit(1 if fail else 0)
