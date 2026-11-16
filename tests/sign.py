#!/usr/bin/env python3
"""Signed request to the plugin, the way Visibility signs them (signing v1, #296).

  sign.py <base-url> <token> <METHOD> <path?query> [body] [--plain] [--override] [--no-bearer]
          [--nonce N] [--ts T] [--key K]

Prints "<status> <body>". --plain uses ?rest_route=, --override sends PATCH/DELETE
as POST with X-HTTP-Method-Override; --key signs with another key than the token.
"""
import sys, time, hmac, hashlib, secrets, json, urllib.request, urllib.parse, urllib.error

args = [a for a in sys.argv[1:] if not a.startswith('--')]
flags = {}
it = iter(sys.argv[1:])
for a in it:
    if a in ('--nonce', '--ts', '--key'):
        flags[a] = next(it)
    elif a.startswith('--'):
        flags[a] = True
for v in flags.values():
    if v is not True and v in args: args.remove(v)
base, token, method, path = args[:4]
body = args[4] if len(args) > 4 else ''

def enc(s):  # RFC 3986, as PHP rawurlencode
    return urllib.parse.quote(s, safe='-_.~')

p, _, q = path.partition('?')
params = urllib.parse.parse_qsl(q, keep_blank_values=True)
query = '&'.join(enc(k) + '=' + enc(v) for k, v in sorted(params, key=lambda kv: kv[0].encode()))
ts = flags.get('--ts', str(int(time.time())))
nonce = flags.get('--nonce', secrets.token_urlsafe(24))
route = '/visibility/v1' + p
canonical = '\n'.join(['v1', method.upper(), route, query, ts, nonce, hashlib.sha256(body.encode()).hexdigest()])
key = flags.get('--key', token)
sig = 'v1=' + hmac.new(key.encode(), canonical.encode(), hashlib.sha256).hexdigest()

sendq = urllib.parse.urlencode(params, quote_via=urllib.parse.quote)
if '--plain' in flags:
    url = base + '/?rest_route=' + urllib.parse.quote(route) + (('&' + sendq) if sendq else '')
else:
    url = base + '/wp-json' + route + (('?' + sendq) if sendq else '')
headers = {'X-Visibility-Timestamp': ts, 'X-Visibility-Nonce': nonce, 'X-Visibility-Signature': sig, 'Content-Type': 'application/json'}
if '--no-bearer' not in flags:
    headers['Authorization'] = 'Bearer ' + token
send_method = method
if '--override' in flags:
    headers['X-HTTP-Method-Override'] = method
    send_method = 'POST'
req = urllib.request.Request(url, data=body.encode() if body else None, method=send_method, headers=headers)
try:
    with urllib.request.urlopen(req) as r:
        print(r.status, r.read().decode())
except urllib.error.HTTPError as e:
    print(e.code, e.read().decode())
