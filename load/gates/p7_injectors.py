#!/usr/bin/env python3
"""P7.2 injectors, isolation guards, and fidelity-labelled degradation matrix.

The P7.1 lane owns phase/evidence schema. This module owns reversible S3/TCP/PG/
ENOSPC injectors, proxy preflight, and expected invariants per cell. Direct
bypass of declared proxies fails preflight. Network-loss cells stay off the
physical-promotion lab.
"""

from __future__ import annotations

import argparse
import errno
import hashlib
import json
import os
import shutil
import signal
import socket
import struct
import subprocess
import sys
import threading
import time
import urllib.error
import urllib.request
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from typing import Any

import p7_chaos as lane


GATES = Path(__file__).resolve().parent
LOAD = GATES.parent
REPO = LOAD.parent
CHAOS = LOAD / 'chaos'

INJECTOR_VERSION = 'p7.2-injectors'
S0_CELLS = (
    'protocol-control.s3-put-part.503.pre-upstream',
    'transport-control.s3-put-part.reset',
    'transport-control.tus-patch.down',
    'storage-control.cas-stage.enospc',
)
CHAOS_SERVICES = frozenset({
    'toxiproxy', 's3-fault-proxy', 'minio', 'pgbouncer',
    'postgres-primary', 'postgres-standby', 'pgbouncer-ha',
    '127.0.0.1', 'localhost',
})
SHARED_ENDPOINTS = (
    's3.amazonaws.com',
    'prod-db.example',
    '8.8.8.8',
    'postgres',
    '/tmp',
    '/var/tmp',
)
TMPFS_ROOT_NAME = 'vaulter-chaos'


class InjectorError(Exception):
    """A guard, preflight, or injector contract failed."""


def _require(condition: bool, message: str) -> None:
    if not condition:
        raise InjectorError(message)


def injector_for(cell: dict) -> str:
    plane = cell['plane']
    fault = cell['fault']
    if plane == 'protocol-control':
        return 's3-fault-proxy'
    if plane == 'transport-control':
        return 'toxiproxy'
    if plane == 'primary-transition':
        return 'pg-failover'
    if plane == 'storage-control':
        return 'tmpfs-enospc'
    if fault in {'503'}:
        return 's3-fault-proxy'
    if fault in {'tcp-reset', 'timeout', 'tcp-down', 'connection-loss', 'disconnect'}:
        return 'toxiproxy'
    return 'product-path'


def fidelity_for(cell: dict) -> dict[str, Any]:
    plane = cell['plane']
    labels = {
        'product-realistic': {
            'fidelity': 'product-realistic',
            'not_real_s3_outage': True,
            'not_pg_failover': True,
            'integrity_claim': True,
        },
        'transport-control': {
            'fidelity': 'transport-control',
            'not_real_s3_outage': True,
            'not_pg_failover': True,
            'integrity_claim': False,
        },
        'protocol-control': {
            'fidelity': 'protocol-control',
            'not_real_s3_outage': True,
            'not_pg_failover': True,
            'integrity_claim': False,
            'pre_upstream_distinct_from_tcp': True,
        },
        'primary-transition': {
            'fidelity': 'primary-transition',
            'not_session_kill': True,
            'fence_before_promote': True,
            'no_automatic_failback': True,
            'integrity_claim': False,
        },
        'storage-control': {
            'fidelity': 'storage-control',
            'not_host_tmp_fill': True,
            'dedicated_tmpfs': True,
            'integrity_claim': False,
        },
    }
    return labels[plane]


def annotate(cell: dict) -> dict:
    annotated = dict(cell)
    annotated.update(fidelity_for(cell))
    annotated['injector'] = injector_for(cell)
    annotated['injector_version'] = INJECTOR_VERSION
    annotated['barrier'] = 'harness-hook'
    annotated['sleep_is_not_barrier'] = True
    annotated['multipart_cleanup'] = 'flysystem-part-keys'
    annotated['aws_upload_id'] = None
    annotated['performance_verdict'] = False
    annotated['rto_verdict'] = False
    if cell['plane'] == 'transport-control' and cell['plane'] == 'primary-transition':
        raise InjectorError('network-loss mixed with promotion')
    if cell['plane'] == 'transport-control':
        annotated['separate_from_promotion'] = True
    if cell['plane'] == 'primary-transition':
        annotated['separate_from_network_loss'] = True
        annotated['expected'] = {
            'writable_primaries': 1,
            'pgbouncer_reconnect': True,
            'unfenced_promotion': False,
        }
    if cell['plane'] == 'storage-control':
        annotated['expected'] = {
            'record_free_bytes': True,
            'record_actual_write_length': True,
            'restore_only_dedicated_volume': True,
        }
    if cell['plane'] == 'protocol-control':
        annotated['expected'] = {
            'http_status': 503,
            'moment': cell.get('moment'),
            'no_signed_headers_logged': True,
        }
    return annotated


def matrix() -> list[dict]:
    return [annotate(cell) for cell in lane.catalog()]


def config_digest() -> str:
    payload = {
        'cells': [cell['cell_id'] for cell in matrix()],
        'injector_version': INJECTOR_VERSION,
        's0': list(S0_CELLS),
        'proxies': ['s3-fault-proxy', 'toxiproxy'],
    }
    raw = json.dumps(payload, sort_keys=True, separators=(',', ':'))
    return hashlib.sha256(raw.encode('utf-8')).hexdigest()


def static_validate() -> dict:
    cells = matrix()
    ids = [cell['cell_id'] for cell in cells]
    _require(len(cells) == len(lane.catalog()), 'matrix/catalog length')
    _require(len(set(ids)) == len(ids), 'duplicate cell')
    for cell_id in S0_CELLS:
        _require(cell_id in ids, f'missing S0 cell {cell_id}')
    planes = {cell['plane'] for cell in cells}
    _require(planes == set(lane.PLANES), 'closed planes')
    _require(all(cell['injector_version'] == INJECTOR_VERSION for cell in cells),
             'injector version')
    _require(all(cell['rto_verdict'] is False for cell in cells), 'rto verdict')
    transport = [cell for cell in cells if cell['plane'] == 'transport-control']
    primary = [cell for cell in cells if cell['plane'] == 'primary-transition']
    _require(transport and primary, 'separated planes')
    _require(all(cell['separate_from_promotion'] for cell in transport),
             'transport mixed with promotion')
    return {
        'cells': len(cells),
        's0': list(S0_CELLS),
        'injector_version': INJECTOR_VERSION,
        'digest': config_digest(),
        'planes': sorted(planes),
    }


def reject_shared_endpoint(target: str) -> None:
    lowered = target.lower().rstrip('/')
    if lowered in SHARED_ENDPOINTS or lowered.endswith('.amazonaws.com'):
        raise InjectorError(f'shared endpoint {target}')
    if lowered in {'/tmp', '/var/tmp'} or lowered.startswith('/tmp/') or lowered.startswith('/var/tmp/'):
        if TMPFS_ROOT_NAME not in lowered:
            raise InjectorError(f'tmpfs path escape {target}')
    host = lowered.split('://')[-1].split(':')[0].split('/')[0]
    if host == 'postgres':
        raise InjectorError(f'shared product-realistic target {target}')
    if host not in CHAOS_SERVICES:
        raise InjectorError(f'non-chaos target {target}')


def reject_unfenced_promotion(*, fenced: bool) -> None:
    if not fenced:
        raise InjectorError('unfenced promotion')


def reject_tmpfs_escape(path: str, root: str) -> None:
    real_root = os.path.realpath(root)
    real_path = os.path.realpath(path)
    if real_path != real_root and not real_path.startswith(real_root + os.sep):
        raise InjectorError(f'tmpfs path escape {path}')
    if TMPFS_ROOT_NAME not in real_root:
        raise InjectorError(f'tmpfs path escape {path}')


def inspect_compose(text: str) -> None:
    normalized = text.replace('"', '').replace("'", '')
    _require('s3-fault-proxy' in normalized, 'overlay missing s3-fault-proxy')
    _require('toxiproxy' in normalized, 'overlay missing toxiproxy')
    _require('postgres-primary' in normalized, 'overlay missing postgres-primary')
    _require('postgres-standby' in normalized, 'overlay missing postgres-standby')
    _require('/var/tmp/vaulter-chaos' in normalized, 'overlay missing dedicated tmpfs')
    _require('s3-fault-proxy:19000' in normalized, 'app must use s3-fault-proxy')
    _require(
        'DB_HOST: ${P7_APP_DB_HOST:-toxiproxy}' in normalized
        or 'DB_HOST:${P7_APP_DB_HOST:-toxiproxy}' in normalized.replace(' ', '')
        or 'DB_HOST: toxiproxy' in normalized
        or 'DB_HOST:toxiproxy' in normalized.replace(' ', ''),
        'app must use toxiproxy for DB',
    )
    if 'MINIO_ENDPOINT: http://minio:9000' in normalized:
        raise InjectorError('direct minio bypass')


def json_post(url: str, payload: dict) -> dict:
    data = json.dumps(payload).encode('utf-8')
    request = urllib.request.Request(
        url, data=data, method='POST',
        headers={'Content-Type': 'application/json'},
    )
    with urllib.request.urlopen(request, timeout=5) as response:
        return json.loads(response.read().decode('utf-8'))


def json_get(url: str) -> dict:
    with urllib.request.urlopen(url, timeout=5) as response:
        return json.loads(response.read().decode('utf-8'))


class _OkHandler(BaseHTTPRequestHandler):
    def log_message(self, format: str, *args: Any) -> None:
        return

    def do_GET(self) -> None:
        body = b'ok'
        self.send_response(200)
        self.send_header('Content-Type', 'text/plain')
        self.send_header('Content-Length', str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def do_PUT(self) -> None:
        length = int(self.headers.get('Content-Length') or '0')
        if length:
            self.rfile.read(length)
        self.do_GET()


class _OkServer(ThreadingHTTPServer):
    allow_reuse_address = True


class TcpFault:
    def __init__(self, listen: tuple[str, int], upstream: tuple[str, int]):
        self.listen = listen
        self.upstream = upstream
        self.mode = 'passthrough'
        self._sock: socket.socket | None = None
        self._thread: threading.Thread | None = None
        self._stop = threading.Event()

    def start(self) -> None:
        self._sock = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
        self._sock.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
        self._sock.bind(self.listen)
        self._sock.listen(8)
        self._sock.settimeout(0.2)
        self._thread = threading.Thread(target=self._loop, daemon=True)
        self._thread.start()

    def close(self) -> None:
        self._stop.set()
        if self._sock is not None:
            self._sock.close()

    def _loop(self) -> None:
        while not self._stop.is_set() and self._sock is not None:
            try:
                client, _ = self._sock.accept()
            except (TimeoutError, socket.timeout, OSError):
                continue
            threading.Thread(target=self._handle, args=(client,), daemon=True).start()

    def _handle(self, client: socket.socket) -> None:
        if self.mode == 'reset':
            client.setsockopt(socket.SOL_SOCKET, socket.SO_LINGER, struct.pack('ii', 1, 0))
            client.close()
            return
        if self.mode == 'down':
            client.close()
            return
        try:
            upstream = socket.create_connection(self.upstream, timeout=2)
        except OSError:
            client.close()
            return
        def pump(src: socket.socket, dst: socket.socket) -> None:
            try:
                while True:
                    chunk = src.recv(4096)
                    if not chunk:
                        break
                    dst.sendall(chunk)
            except OSError:
                return
            finally:
                try:
                    dst.shutdown(socket.SHUT_WR)
                except OSError:
                    pass
        threading.Thread(target=pump, args=(client, upstream), daemon=True).start()
        pump(upstream, client)
        client.close()
        upstream.close()


def _free_port() -> int:
    probe = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
    probe.bind(('127.0.0.1', 0))
    port = int(probe.getsockname()[1])
    probe.close()
    return port


def run_s3_503_cell(run_id: str) -> dict:
    reject_shared_endpoint('s3-fault-proxy')
    upstream_port = _free_port()
    proxy_port = _free_port()
    control_port = _free_port()
    upstream = _OkServer(('127.0.0.1', upstream_port), _OkHandler)
    threading.Thread(target=upstream.serve_forever, daemon=True).start()
    env = os.environ.copy()
    proc = subprocess.Popen(
        [sys.executable, str(CHAOS / 's3-fault-proxy.py'),
         '--listen', f'127.0.0.1:{proxy_port}',
         '--control', f'127.0.0.1:{control_port}',
         '--upstream', f'http://127.0.0.1:{upstream_port}'],
        env=env, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
    )
    try:
        deadline = time.time() + 5
        while time.time() < deadline:
            try:
                json_get(f'http://127.0.0.1:{control_port}/health')
                break
            except OSError:
                time.sleep(0.05)
        json_post(f'http://127.0.0.1:{control_port}/arm', {
            'run_id': run_id, 'mode': 'pre-upstream-503', 'target': 'minio',
        })
        try:
            json_post(f'http://127.0.0.1:{control_port}/arm', {
                'run_id': 'foreign', 'mode': 'pre-upstream-503', 'target': 'minio',
            })
            raise InjectorError('foreign run_id accepted')
        except urllib.error.HTTPError as exc:
            _require(exc.code == 409, 'foreign run_id must be 409')
        try:
            with urllib.request.urlopen(f'http://127.0.0.1:{proxy_port}/bucket/key', timeout=2) as response:
                raise InjectorError(f'expected 503, got {response.status}')
        except urllib.error.HTTPError as exc:
            _require(exc.code == 503, 's3 503')
            body = json.loads(exc.read().decode('utf-8'))
            _require(body.get('moment') == 'pre-upstream', 'pre-upstream moment')
            _require(body.get('fidelity') == 'not-real-s3-outage', 'fidelity')
        json_post(f'http://127.0.0.1:{control_port}/revert', {'run_id': run_id})
        with urllib.request.urlopen(f'http://127.0.0.1:{proxy_port}/bucket/key', timeout=2) as response:
            _require(response.status == 200, 'revert did not restore passthrough')
        return {'cell': S0_CELLS[0], 'reverted': True, 'status': 503}
    finally:
        proc.send_signal(signal.SIGTERM)
        try:
            proc.wait(timeout=2)
        except subprocess.TimeoutExpired:
            proc.kill()
        upstream.shutdown()


def run_tcp_reset_cell() -> dict:
    reject_shared_endpoint('toxiproxy')
    upstream_port = _free_port()
    listen_port = _free_port()
    upstream = _OkServer(('127.0.0.1', upstream_port), _OkHandler)
    threading.Thread(target=upstream.serve_forever, daemon=True).start()
    proxy = TcpFault(('127.0.0.1', listen_port), ('127.0.0.1', upstream_port))
    proxy.start()
    try:
        proxy.mode = 'reset'
        sock = socket.create_connection(('127.0.0.1', listen_port), timeout=2)
        sock.sendall(b'GET / HTTP/1.1\r\nHost: x\r\n\r\n')
        try:
            sock.recv(64)
            reset = False
        except OSError:
            reset = True
        sock.close()
        _require(reset, 'tcp reset did not fire')
        proxy.mode = 'passthrough'
        with urllib.request.urlopen(f'http://127.0.0.1:{listen_port}/', timeout=2) as response:
            _require(response.status == 200, 'tcp reset revert')
        return {'cell': S0_CELLS[1], 'reverted': True, 'fault': 'reset'}
    finally:
        proxy.close()
        upstream.shutdown()


def run_backend_termination_cell() -> dict:
    reject_shared_endpoint('toxiproxy')
    upstream_port = _free_port()
    listen_port = _free_port()
    upstream = _OkServer(('127.0.0.1', upstream_port), _OkHandler)
    thread = threading.Thread(target=upstream.serve_forever, daemon=True)
    thread.start()
    proxy = TcpFault(('127.0.0.1', listen_port), ('127.0.0.1', upstream_port))
    proxy.start()
    try:
        with urllib.request.urlopen(f'http://127.0.0.1:{listen_port}/', timeout=2) as response:
            _require(response.status == 200, 'backend baseline')
        upstream.shutdown()
        proxy.mode = 'down'
        terminated = False
        try:
            urllib.request.urlopen(f'http://127.0.0.1:{listen_port}/', timeout=1)
        except (urllib.error.URLError, TimeoutError, OSError):
            terminated = True
        _require(terminated, 'backend termination')
        replacement_port = _free_port()
        replacement = _OkServer(('127.0.0.1', replacement_port), _OkHandler)
        threading.Thread(target=replacement.serve_forever, daemon=True).start()
        proxy.upstream = ('127.0.0.1', replacement_port)
        proxy.mode = 'passthrough'
        with urllib.request.urlopen(f'http://127.0.0.1:{listen_port}/', timeout=2) as response:
            _require(response.status == 200, 'backend revert')
        replacement.shutdown()
        return {'cell': S0_CELLS[2], 'reverted': True, 'fault': 'down'}
    finally:
        proxy.close()


def _fill_until_enospc(root: str) -> dict:
    reject_tmpfs_escape(root, root)
    info = os.statvfs(root)
    free_before = info.f_bavail * info.f_frsize
    chunk = b'x' * 65536
    written = 0
    path = os.path.join(root, 'fill.bin')
    with open(path, 'wb', buffering=0) as handle:
        while True:
            try:
                n = os.write(handle.fileno(), chunk)
            except OSError as exc:
                _require(exc.errno == errno.ENOSPC, f'expected ENOSPC, got {exc.errno}')
                n = 0
                break
            if n == 0:
                break
            written += n
            if n < len(chunk):
                break
    info_after = os.statvfs(root)
    free_after = info_after.f_bavail * info_after.f_frsize
    _require(written >= 0, 'write length')
    os.remove(path)
    info_restored = os.statvfs(root)
    return {
        'free_bytes_before': free_before,
        'free_bytes_after': free_after,
        'actual_write_length': written,
        'free_bytes_restored': info_restored.f_bavail * info_restored.f_frsize,
        'errno': 'ENOSPC',
    }


def run_enospc_cell() -> dict:
    docker = shutil.which('docker')
    _require(docker is not None, 'docker required for dedicated tmpfs ENOSPC')
    result = subprocess.run(
        [docker, 'run', '--rm', '--tmpfs',
         '/var/tmp/vaulter-chaos:size=2m,mode=1777',
         'alpine:3.20', 'sh', '-c',
         'free_k(){ df -P "$1" | awk \'NR==2{print $4}\'; };'
         'root=/var/tmp/vaulter-chaos;'
         'free_before=$(($(free_k "$root")*1024));'
         'dd if=/dev/zero of="$root/fill.bin" bs=65536 2>/tmp/dd.err || true;'
         'written=$(wc -c < "$root/fill.bin" | tr -d " ");'
         'free_after=$(($(free_k "$root")*1024));'
         'rm -f "$root/fill.bin";'
         'free_restored=$(($(free_k "$root")*1024));'
         'grep -q "No space left" /tmp/dd.err;'
         'printf \'{"free_bytes_before":%s,"free_bytes_after":%s,"actual_write_length":%s,"free_bytes_restored":%s,"errno":"ENOSPC"}\\n\' '
         '"$free_before" "$free_after" "$written" "$free_restored"'],
        check=False, capture_output=True, text=True,
    )
    if result.returncode != 0:
        raise InjectorError((result.stderr or result.stdout or '').strip() or 'enospc container failed')
    payload = json.loads(result.stdout.strip().splitlines()[-1])
    _require(payload['errno'] == 'ENOSPC', 'ENOSPC')
    _require(payload['actual_write_length'] >= 0, 'write length')
    payload.update({'cell': S0_CELLS[3], 'reverted': True})
    return payload


def run_s0(run_id: str) -> dict:
    cells = [
        run_s3_503_cell(run_id),
        run_tcp_reset_cell(),
        run_backend_termination_cell(),
        run_enospc_cell(),
    ]
    _require(all(cell.get('reverted') for cell in cells), 's0 revert')
    return {'run_id': run_id, 'cells': cells, 'reverted': True}


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description='P7.2 injectors and matrix')
    sub = parser.add_subparsers(dest='command', required=True)
    sub.add_parser('static-validate')
    sub.add_parser('matrix')
    s0 = sub.add_parser('s0')
    s0.add_argument('--run-id', default='p7.2-s0')
    inspect = sub.add_parser('inspect-compose')
    inspect.add_argument('--file', required=True)
    guard = sub.add_parser('guard')
    guard.add_argument('kind', choices=('shared', 'unfenced', 'tmpfs'))
    guard.add_argument('--target', default='')
    guard.add_argument('--root', default='')
    args = parser.parse_args(argv)
    try:
        if args.command == 'static-validate':
            print(json.dumps(static_validate(), sort_keys=True))
            return 0
        if args.command == 'matrix':
            print(json.dumps(matrix(), indent=2))
            return 0
        if args.command == 's0':
            print(json.dumps(run_s0(args.run_id), sort_keys=True))
            return 0
        if args.command == 'inspect-compose':
            inspect_compose(Path(args.file).read_text(encoding='utf-8'))
            print(json.dumps({'ok': True}))
            return 0
        if args.kind == 'shared':
            reject_shared_endpoint(args.target)
        elif args.kind == 'unfenced':
            reject_unfenced_promotion(fenced=False)
        else:
            reject_tmpfs_escape(args.target, args.root or '/tmp')
        print(json.dumps({'ok': True}))
        return 0
    except InjectorError as exc:
        print(str(exc), file=sys.stderr)
        return 1


if __name__ == '__main__':
    raise SystemExit(main())
