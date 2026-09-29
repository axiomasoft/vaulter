#!/usr/bin/env python3
"""Load-only S3 503 controller.

Pre-upstream 503 never contacts the upstream proxy. TCP latency/timeout/reset
belong to Toxiproxy and are not labelled as this protocol fault. Signed headers
and bodies are never logged. The control API binds one run id and rejects
foreign or non-chaos upstreams.
"""

from __future__ import annotations

import argparse
import json
import re
import socket
import threading
import urllib.error
import urllib.parse
import urllib.request
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from typing import Any


ALLOWED_UPSTREAM_HOSTS = frozenset({
    'toxiproxy', 'minio', 's3-fault-proxy', '127.0.0.1', 'localhost',
})
FORBIDDEN_HEADER = re.compile(
    r'(authorization|signature|secret|credential|x-amz-security-token)',
    re.IGNORECASE,
)
MODES = ('passthrough', 'pre-upstream-503', 'after-accept-503')
STATE_LOCK = threading.Lock()
STATE: dict[str, Any] = {
    'run_id': None,
    'mode': 'passthrough',
    'target': 'minio',
    'events': [],
    'upstream': 'http://toxiproxy:19001',
}


def _redact_headers(headers: dict[str, str]) -> dict[str, str]:
    kept = {}
    for key, value in headers.items():
        if FORBIDDEN_HEADER.search(key) or FORBIDDEN_HEADER.search(value or ''):
            continue
        kept[key] = value
    return kept


def _event(kind: str, **fields: Any) -> dict[str, Any]:
    row = {'kind': kind, **fields}
    with STATE_LOCK:
        STATE['events'].append(row)
        STATE['events'] = STATE['events'][-64:]
    return row


def _json_bytes(payload: dict[str, Any]) -> bytes:
    return json.dumps(payload, separators=(',', ':')).encode('utf-8')


def _host_of(url: str) -> str:
    parsed = urllib.parse.urlparse(url)
    host = (parsed.hostname or '').lower()
    return host


def _reject_upstream(url: str) -> str | None:
    host = _host_of(url)
    if host not in ALLOWED_UPSTREAM_HOSTS:
        return f'non-chaos upstream {host or url!r}'
    return None


class ControlHandler(BaseHTTPRequestHandler):
    def log_message(self, format: str, *args: Any) -> None:
        return

    def _read_json(self) -> dict[str, Any]:
        length = int(self.headers.get('Content-Length') or '0')
        if length <= 0 or length > 65536:
            return {}
        raw = self.rfile.read(length)
        try:
            payload = json.loads(raw.decode('utf-8'))
        except (UnicodeDecodeError, json.JSONDecodeError):
            return {}
        return payload if isinstance(payload, dict) else {}

    def _send(self, code: int, payload: dict[str, Any]) -> None:
        body = _json_bytes(payload)
        self.send_response(code)
        self.send_header('Content-Type', 'application/json')
        self.send_header('Content-Length', str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def do_GET(self) -> None:
        if self.path in {'/health', '/__chaos/health'}:
            with STATE_LOCK:
                self._send(200, {
                    'ok': True,
                    'mode': STATE['mode'],
                    'run_id': STATE['run_id'],
                    'upstream': STATE['upstream'],
                })
            return
        if self.path in {'/observe', '/__chaos/observe'}:
            with STATE_LOCK:
                self._send(200, {
                    'run_id': STATE['run_id'],
                    'mode': STATE['mode'],
                    'target': STATE['target'],
                    'events': list(STATE['events']),
                })
            return
        self._send(404, {'error': 'not-found'})

    def do_POST(self) -> None:
        payload = self._read_json()
        path = self.path.rstrip('/')
        if path.endswith('/arm'):
            self._arm(payload)
            return
        if path.endswith('/revert'):
            self._revert(payload)
            return
        if path.endswith('/self-test'):
            self._self_test(payload)
            return
        self._send(404, {'error': 'not-found'})

    def _arm(self, payload: dict[str, Any]) -> None:
        run_id = str(payload.get('run_id') or '')
        mode = str(payload.get('mode') or 'pre-upstream-503')
        target = str(payload.get('target') or 'minio')
        if not run_id:
            self._send(400, {'error': 'run_id required'})
            return
        if mode not in MODES:
            self._send(400, {'error': 'unknown mode'})
            return
        if target not in {'minio', 's3-fault-proxy', 'toxiproxy'}:
            self._send(403, {'error': 'non-chaos target'})
            return
        with STATE_LOCK:
            if STATE['run_id'] not in {None, run_id}:
                self._send(409, {'error': 'foreign run_id'})
                return
            STATE['run_id'] = run_id
            STATE['mode'] = mode
            STATE['target'] = target
        _event('arm', run_id=run_id, mode=mode, target=target, plane='protocol-control')
        self._send(200, {'ok': True, 'run_id': run_id, 'mode': mode})

    def _revert(self, payload: dict[str, Any]) -> None:
        run_id = str(payload.get('run_id') or '')
        with STATE_LOCK:
            if STATE['run_id'] not in {None, run_id} and run_id:
                self._send(409, {'error': 'foreign run_id'})
                return
            STATE['mode'] = 'passthrough'
        _event('revert', run_id=run_id or STATE['run_id'], mode='passthrough')
        self._send(200, {'ok': True, 'mode': 'passthrough'})

    def _self_test(self, payload: dict[str, Any]) -> None:
        run_id = str(payload.get('run_id') or '')
        with STATE_LOCK:
            bound = STATE['run_id']
            mode = STATE['mode']
        if run_id and bound not in {None, run_id}:
            self._send(409, {'error': 'foreign run_id'})
            return
        self._send(200, {
            'ok': True,
            'run_id': bound,
            'mode': mode,
            'pre_upstream_distinct_from_tcp': True,
        })


class ProxyHandler(BaseHTTPRequestHandler):
    def log_message(self, format: str, *args: Any) -> None:
        return

    def _read_body(self) -> bytes:
        length = int(self.headers.get('Content-Length') or '0')
        if length <= 0:
            return b''
        return self.rfile.read(min(length, 8_388_608))

    def _s3_503(self, moment: str) -> None:
        payload = {
            'fault': 's3-503',
            'moment': moment,
            'plane': 'protocol-control',
            'fidelity': 'not-real-s3-outage',
        }
        body = _json_bytes(payload)
        self.send_response(503)
        self.send_header('Content-Type', 'application/json')
        self.send_header('Content-Length', str(len(body)))
        self.send_header('x-vaulter-chaos-fault', 's3-503')
        self.send_header('x-vaulter-chaos-moment', moment)
        self.end_headers()
        self.wfile.write(body)
        _event('s3-503', moment=moment, path=self.path.split('?', 1)[0],
               headers=_redact_headers(dict(self.headers)))

    def _proxy(self) -> None:
        body = self._read_body()
        with STATE_LOCK:
            upstream = STATE['upstream']
            mode = STATE['mode']
        if mode == 'pre-upstream-503':
            self._s3_503('pre-upstream')
            return
        if mode == 'after-accept-503':
            self._s3_503('after-accept')
            return
        rejected = _reject_upstream(upstream)
        if rejected:
            self.send_error(403, rejected)
            _event('reject', reason=rejected)
            return
        url = urllib.parse.urljoin(upstream.rstrip('/') + '/', self.path.lstrip('/'))
        headers = _redact_headers({k: v for k, v in self.headers.items() if k.lower() != 'host'})
        request = urllib.request.Request(url, data=body or None, method=self.command, headers=headers)
        try:
            with urllib.request.urlopen(request, timeout=5) as response:
                payload = response.read()
                self.send_response(response.status)
                for key, value in response.headers.items():
                    if FORBIDDEN_HEADER.search(key):
                        continue
                    if key.lower() in {'transfer-encoding', 'connection'}:
                        continue
                    self.send_header(key, value)
                self.send_header('Content-Length', str(len(payload)))
                self.end_headers()
                self.wfile.write(payload)
        except (urllib.error.URLError, TimeoutError, socket.timeout) as exc:
            self.send_error(502, 'upstream-transport')
            _event('upstream-transport', error=type(exc).__name__,
                   distinct_from='s3-503')

    def do_GET(self) -> None:
        self._proxy()

    def do_PUT(self) -> None:
        self._proxy()

    def do_POST(self) -> None:
        self._proxy()

    def do_HEAD(self) -> None:
        self._proxy()

    def do_DELETE(self) -> None:
        self._proxy()


def serve(listen: str, control: str, upstream: str) -> None:
    rejected = _reject_upstream(upstream)
    if rejected:
        raise SystemExit(rejected)
    with STATE_LOCK:
        STATE['upstream'] = upstream

    def _split(bind: str) -> tuple[str, int]:
        host, port = bind.rsplit(':', 1)
        return host, int(port)

    proxy = ThreadingHTTPServer(_split(listen), ProxyHandler)
    api = ThreadingHTTPServer(_split(control), ControlHandler)
    threading.Thread(target=api.serve_forever, daemon=True).start()
    try:
        proxy.serve_forever()
    except KeyboardInterrupt:
        proxy.shutdown()
        api.shutdown()


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description='Load-only S3 503 controller')
    parser.add_argument('--listen', default='0.0.0.0:19000')
    parser.add_argument('--control', default='0.0.0.0:19099')
    parser.add_argument('--upstream', default='http://toxiproxy:19001')
    args = parser.parse_args(argv)
    serve(args.listen, args.control, args.upstream)
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
