"""Webhook receiver for the integration tests (like Mailpit, for HTTP).

Runs in a container on the wp-env network as host "webhook", port 8080. Records every
request so the tests can read back what the plugin really sent.

  POST /hook                 200, recorded
  POST /status/<code>        answers with that status, recorded
  POST /slow                 waits 10 s, then 200 (longer than the plugin's timeout)
  POST /redirect-private     302 to http://127.0.0.1/hook (must not be followed)
  POST /redirect-allowed     302 to http://webhook:8080/hook (control: is followed)
  GET  /_requests            the recorded requests, as JSON
  DELETE /_requests          forget them
"""

import json
import threading
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

requests = []
lock = threading.Lock()


class Handler(BaseHTTPRequestHandler):
    def log_message(self, *args):
        pass

    def _send(self, code, body=b"", headers=None):
        self.send_response(code)
        for name, value in (headers or {}).items():
            self.send_header(name, value)
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def _record(self):
        length = int(self.headers.get("Content-Length") or 0)
        body = self.rfile.read(length).decode("utf-8", "replace")
        with lock:
            requests.append({
                "method": self.command,
                "path": self.path,
                "headers": {k.lower(): v for k, v in self.headers.items()},
                "body": body,
            })

    def do_GET(self):
        if self.path == "/_requests":
            with lock:
                data = json.dumps(requests).encode()
            return self._send(200, data, {"Content-Type": "application/json"})
        self._record()
        self._send(200, b"ok")

    def do_DELETE(self):
        if self.path == "/_requests":
            with lock:
                requests.clear()
            return self._send(204)
        self._send(404)

    def do_POST(self):
        self._record()
        if self.path.startswith("/status/"):
            return self._send(int(self.path.rsplit("/", 1)[1]), b"status")
        if self.path == "/slow":
            time.sleep(10)
            return self._send(200, b"late")
        if self.path == "/redirect-private":
            return self._send(302, b"", {"Location": "http://127.0.0.1/hook"})
        if self.path == "/redirect-allowed":
            return self._send(302, b"", {"Location": "http://webhook:8080/hook"})
        self._send(200, b"ok")


ThreadingHTTPServer(("0.0.0.0", 8080), Handler).serve_forever()
