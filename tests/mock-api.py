#!/usr/bin/env python3
"""Tiny stand-in for the Visibility plugin API, for the reliability checks.

State lives in a JSON file (argv[2]) so a test can set the inbox and read back
what the plugin reported:
  {"inbox": [...rows...], "failReports": 1, "reports": [...], "heartbeat": {...}}
"""
import json, sys, threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

PORT = int(sys.argv[1]); STATE = sys.argv[2]
lock = threading.Lock()

def load():
    try:
        with open(STATE) as f: return json.load(f)
    except Exception: return {}

def save(s):
    with open(STATE, "w") as f: json.dump(s, f)

class H(BaseHTTPRequestHandler):
    def log_message(self, *a): pass
    def send(self, code, body):
        b = json.dumps(body).encode()
        self.send_response(code); self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(b))); self.end_headers(); self.wfile.write(b)
    def do_GET(self):
        with lock:
            s = load()
            s.setdefault("gets", []).append(self.path); save(s)
        if "/inbox" in self.path: return self.send(200, s.get("inbox", []))
        return self.send(404, {"error": {"message": "no"}})
    def do_POST(self):
        n = int(self.headers.get("Content-Length") or 0)
        body = json.loads(self.rfile.read(n) or b"{}")
        with lock:
            s = load()
            if self.path.endswith("/heartbeat"):
                s.setdefault("heartbeats", []).append(body); save(s)
                return self.send(200, s.get("heartbeat", {"siteId": "test-site-1"}))
            if self.path.endswith("/execution-result"):
                rid = self.path.split("/requests/")[1].split("/")[0]
                failing = s.get("failReports", 0) > 0
                s.setdefault("reports", []).append({"id": rid, "ok": body.get("ok"), "failed": failing})
                if failing: s["failReports"] -= 1
                save(s)
                return self.send(500 if failing else 200, {"error": {"message": "boom"}} if failing else {"ok": True})
            s.setdefault("posts", []).append({"path": self.path, "body": body}); save(s)
        return self.send(200, {"ok": True})

ThreadingHTTPServer(("0.0.0.0", PORT), H).serve_forever()
