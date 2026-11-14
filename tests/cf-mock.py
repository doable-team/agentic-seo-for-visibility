#!/usr/bin/env python3
"""Stand-in for the Cloudflare v4 API (tests only).

Zone "localhost" = zone123. Records every call in the JSON state file
(argv[2]); {"fail": true} in it makes purge_cache answer success=false.
"""
import json, sys, threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
PORT = int(sys.argv[1]); STATE = sys.argv[2]; lock = threading.Lock()
def load():
    try: return json.load(open(STATE))
    except Exception: return {}
class H(BaseHTTPRequestHandler):
    def log_message(self, *a): pass
    def handle_any(self):
        n = int(self.headers.get("Content-Length") or 0)
        body = self.rfile.read(n).decode() if n else ""
        path = self.path.split("/client/v4/", 1)[-1]
        with lock:
            s = load()
            s.setdefault("calls", []).append({"method": self.command, "path": path, "body": body,
                "auth": "bearer" if self.headers.get("Authorization") else ("key" if self.headers.get("X-Auth-Key") else "none")})
            json.dump(s, open(STATE, "w"))
        ok, result, code = True, [], 200
        if "purge_cache" in path:
            ok = not s.get("fail"); result = {"id": "purge1"} if ok else None; code = 200 if ok else 400
        elif "/settings/" in path: result = {"id": "x", "value": "off"}
        elif "pagerules" in path: result = []
        elif path.startswith("zones"): result = [{"id": "zone123", "name": "localhost", "status": "active"}]
        b = json.dumps({"success": ok, "errors": [] if ok else [{"code": 1000, "message": "mock failure"}], "messages": [], "result": result}).encode()
        self.send_response(code); self.send_header("Content-Type", "application/json"); self.send_header("Content-Length", str(len(b))); self.end_headers(); self.wfile.write(b)
    do_GET = do_POST = do_DELETE = do_PATCH = do_PUT = handle_any
ThreadingHTTPServer(("0.0.0.0", PORT), H).serve_forever()
