#!/usr/bin/env python3
"""A stand-in for the session gateway, for trying the console without a server.

It answers the endpoints GatewayClient calls, reports the nodes you name as
connected, and says every command succeeded after a short pause, with an empty
output. Nothing runs anywhere. It is for looking at the console's own screens
and flows; whether a recipe really works on a server is what a throwaway
droplet is for.

    scripts/dev/fake-gateway.py [--port 9444] [--node web-1.org-1:org-1]...

Every command is appended to fake-gateway.log (one JSON object a line) beside
this file's working directory. To make something fail, put a JSON object in
fake-gateway.fail.json: each key is a text looked for in the command (its kind,
a step id, anything in its arguments) and its value the error to return.
Delete the file to stop failing. The file is read on every command.

    {"nginx -t": "nginx: [emerg] unexpected end of file"}
"""
import argparse
import json
import os
import re
import ssl
import sys
import threading
import time
import urllib.request
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

NODES: dict[str, str] = {}
DELAY = 1.0
FAIL_FILE = "fake-gateway.fail.json"
LOG_FILE = "fake-gateway.log"
STARTED = time.time()
RELEASES: dict[tuple[str, str], list[str]] = {}  # (node, site) -> releases, newest last; the last one is current
CONTROL_PLANE = "https://control-plane.test"
TOKEN = "local-dev-token"


def post_event(kind: str, body: dict) -> None:
    """Tell the control plane what a real gateway would: a node arrived, a host sample, some log lines."""
    req = urllib.request.Request(
        f"{CONTROL_PLANE}/api/gateway/events",
        data=json.dumps({"kind": kind, "body": body}).encode(),
        headers={"Content-Type": "application/json", "Accept": "application/json", "X-Gateway-Token": TOKEN},
    )
    try:
        urllib.request.urlopen(req, timeout=10, context=ssl._create_unverified_context()).read()
    except Exception as e:  # the control plane may not be up yet
        print(f"event {kind} not delivered: {e}", file=sys.stderr)


def announce() -> None:
    for node, org in NODES.items():
        post_event("node.connected", {"node_id": node, "organization_id": org, "agent_version": "fake0001"})
    while True:
        for node, org in NODES.items():
            post_event("node.metrics", {"node_id": node, "organization_id": org, "sample": {
                "collected_at": int(time.time()), "cpu_percent": 6.5, "load_1": 0.12,
                "mem_total": 1024 * 1024 * 1024, "mem_available": 700 * 1024 * 1024,
                "disks": {"/": {"total": 100 * 1024**3, "free": 80 * 1024**3}}, "uptime_seconds": int(time.time() - STARTED) + 86400}})
            post_event("node.logs", {"node_id": node, "organization_id": org, "batch": {"lines": [
                {"source": "nginx", "ts": int(time.time() * 1000), "line": "127.0.0.1 - - \"GET / HTTP/1.1\" 200 612"},
                {"source": "system", "ts": int(time.time() * 1000), "line": "cron: (root) CMD (run-parts /etc/cron.hourly)"}]}})
        time.sleep(20)


def failure_for(command: dict) -> str | None:
    try:
        rules = json.load(open(FAIL_FILE))
    except (OSError, ValueError):
        return None
    text = json.dumps(command)
    for needle, error in rules.items():
        if needle in text:
            return str(error)
    return None


class Handler(BaseHTTPRequestHandler):
    def log_message(self, *_):
        pass

    def _send(self, status: int, body: dict) -> None:
        data = json.dumps(body).encode()
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(data)))
        self.end_headers()
        self.wfile.write(data)

    def _json(self) -> dict:
        n = int(self.headers.get("Content-Length") or 0)
        try:
            return json.loads(self.rfile.read(n) or b"{}")
        except ValueError:
            return {}

    def do_GET(self):
        path = self.path.split("?")[0]
        if path == "/health":
            return self._send(200, {"status": "ok", "fake": True})
        if path == "/v1/nodes":
            since = time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime(STARTED))
            return self._send(200, {"nodes": [{"node_id": n, "organization_id": o, "connected_at": since} for n, o in NODES.items()]})
        m = re.fullmatch(r"/v1/nodes/([^/]+)", path)
        if m:
            return self._send(200, {"connected": m.group(1) in NODES})
        self._send(404, {"error": "not found"})

    def do_PUT(self):
        body = self._json()
        self._send(200, {"organisations": len(body.get("organisations", []))})

    def do_POST(self):
        m = re.fullmatch(r"/v1/nodes/([^/]+)/(commands|terminal)", self.path.split("?")[0])
        if not m:
            return self._send(404, {"error": "not found"})
        node, what = m.groups()
        body = self._json()
        if node not in NODES:
            return self._send(409, {"error": "node not connected", "outcome_known": True})
        if what == "terminal":
            return self._send(200, {})
        time.sleep(DELAY)
        error = failure_for(body)
        with open(LOG_FILE, "a") as f:
            f.write(json.dumps({"at": time.time(), "node": node, "command": body, "failed": error}) + "\n")
        if error:
            return self._send(200, {"status": "failed", "outcome_known": True, "error": error, "duration_ms": int(DELAY * 1000)})
        detail: dict = {"output": ""}
        args = body.get("args") or {}
        key = (node, str(args.get("site", "")))
        if body.get("kind") == "activate_release" and args.get("release_id"):
            RELEASES.setdefault(key, []).append(args["release_id"])
        elif body.get("kind") == "rollback":
            # Like the agent: the current release goes, the one before it becomes current.
            kept = RELEASES.get(key, [])
            if len(kept) > 1:
                kept.pop()
            detail["current"] = kept[-1] if kept else ""
        self._send(200, {"status": "succeeded", "outcome_known": True, "duration_ms": int(DELAY * 1000), "detail": detail})


def main() -> None:
    global DELAY, CONTROL_PLANE, TOKEN
    p = argparse.ArgumentParser()
    p.add_argument("--port", type=int, default=9444)
    p.add_argument("--delay", type=float, default=1.0, help="seconds each command takes")
    p.add_argument("--control-plane", default=CONTROL_PLANE, help="where to send node events")
    p.add_argument("--token", default=TOKEN, help="the gateway token the control plane expects")
    p.add_argument("--node", action="append", default=["web-1.org-1:org-1"], help="node_id:organization_id, repeatable")
    a = p.parse_args()
    DELAY, CONTROL_PLANE, TOKEN = a.delay, a.control_plane.rstrip("/"), a.token
    for n in a.node:
        node, _, org = n.partition(":")
        NODES[node] = org or "org-1"
    threading.Thread(target=announce, daemon=True).start()
    print(f"fake gateway on 127.0.0.1:{a.port}, nodes: {', '.join(NODES)}", file=sys.stderr)
    ThreadingHTTPServer(("127.0.0.1", a.port), Handler).serve_forever()


if __name__ == "__main__":
    main()
