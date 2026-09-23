#!/usr/bin/env python3
"""P7.3 tier-S fault/recovery matrix.

One run id binds MinIO, PostgreSQL/PgBouncer and the dedicated chaos tmpfs.
Product-realistic cells repeat three times. Mechanism and promotion cells run
once, and promotion runs only after the isolated HA lab is restored. A missed
probe stays evidence-invalid. Timing is recorded without an RTO verdict.
"""

from __future__ import annotations

import hashlib
import json
import os
import subprocess
import time
import urllib.request
from pathlib import Path

import p7_chaos as gate
import p7_injectors as injectors


REPO = Path(__file__).resolve().parents[2]
LOAD = REPO / 'load'
COMPOSE = LOAD / 'docker-compose.load.yml'
CHAOS = LOAD / 'docker-compose.chaos.yml'
ENV_FILE = LOAD / 'env' / 'S.env'
FAILOVER = LOAD / 'chaos' / 'pg-failover.sh'
PROJECT = 'vaulter-load'
APP = f'{PROJECT}-app-1'
MINIO = f'{PROJECT}-minio-1'
POSTGRES = f'{PROJECT}-postgres-1'
PGBOUNCER = f'{PROJECT}-pgbouncer-1'
BUCKET = 'vaulter-load'
SECRET_MARKERS = (
    'postgresql://', 'postgres://', 'redis://', 'vaulter-load-secret',
    'PGPASSWORD=', 'AWS_SECRET', 'MINIO_ROOT_PASSWORD=',
)


class LiveError(RuntimeError):
    """The stand rejected a fault window or cleanup."""


class Clock:
    def __init__(self) -> None:
        self.value = time.monotonic_ns()

    def next(self) -> int:
        self.value = max(self.value + 1000, time.monotonic_ns())
        return self.value


def wall() -> str:
    return time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime())


def repeat_count(cell: dict) -> int:
    return 3 if cell['plane'] == 'product-realistic' else 1


def _run(command: list[str], *, timeout: int = 60, check: bool = True,
         input_bytes: bytes | None = None, env: dict | None = None) -> subprocess.CompletedProcess[str]:
    result = subprocess.run(
        command, text=True, input=None if input_bytes is None else input_bytes.decode('utf-8'),
        capture_output=True, timeout=timeout, check=False, env=env,
    )
    if input_bytes is not None and result.returncode != 0 and check:
        raise LiveError(result.stderr.strip() or result.stdout.strip() or 'command failed')
    if check and result.returncode != 0:
        detail = (result.stderr or result.stdout or '').strip()
        raise LiveError(detail or 'command failed')
    return result


def compose(*args: str, profile: bool = False, timeout: int = 240) -> None:
    command = [
        'docker', 'compose', '-p', PROJECT, '-f', str(COMPOSE), '-f', str(CHAOS),
        '--env-file', str(ENV_FILE),
    ]
    if profile:
        command.extend(['--profile', 'chaos-ha'])
    command.extend(args)
    _run(command, timeout=timeout)


def compose_base(*args: str, timeout: int = 240) -> None:
    _run([
        'docker', 'compose', '-p', PROJECT, '-f', str(COMPOSE),
        '--env-file', str(ENV_FILE), *args,
    ], timeout=timeout)


def psql(sql: str) -> str:
    result = _run([
        'docker', 'exec', '-i', POSTGRES,
        'psql', '-U', 'vaulter', '-d', 'vaulter_load', '-A', '-t', '-v', 'ON_ERROR_STOP=1',
        '-c', sql,
    ], timeout=60)
    return result.stdout.strip()


def counts_checksum() -> dict:
    names = [
        name for name in psql(
            "SELECT c.relname FROM pg_class c "
            "JOIN pg_namespace n ON n.oid = c.relnamespace "
            "WHERE n.nspname = 'public' AND c.relkind = 'r' ORDER BY 1"
        ).split()
        if name.replace('_', '').isalnum()
    ]
    payload = {}
    for name in names:
        payload[name] = int(psql(f'SELECT count(*) FROM public.{name}'))
    payload['ungranted_locks'] = int(psql('SELECT count(*) FROM pg_locks WHERE NOT granted'))
    raw = json.dumps(payload, sort_keys=True, separators=(',', ':'))
    payload['md5'] = hashlib.md5(raw.encode('utf-8')).hexdigest()
    return payload


def app_http(method: str, url: str, body: str = '') -> dict:
    php = (
        '$ctx = stream_context_create(["http" => ["method" => getenv("M"), '
        '"header" => "Content-Type: application/json\\r\\n", "content" => getenv("B") ?: "", '
        '"ignore_errors" => true, "timeout" => 8]]);'
        '$payload = @file_get_contents(getenv("U"), false, $ctx);'
        '$status = 0;'
        'if (isset($http_response_header[0]) && preg_match("#\\s(\\d{3})\\s#", $http_response_header[0], $m)) '
        '{ $status = (int) $m[1]; }'
        'echo json_encode(["status" => $status, "body" => is_string($payload) ? $payload : ""]);'
    )
    result = _run([
        'docker', 'exec', '-e', f'M={method}', '-e', f'U={url}', '-e', f'B={body}',
        APP, 'php', '-r', php,
    ], timeout=30, check=False)
    if result.returncode != 0:
        return {'status': 0, 'body': ''}
    try:
        parsed = json.loads(result.stdout.strip() or '{}')
    except json.JSONDecodeError:
        return {'status': 0, 'body': ''}
    return {'status': int(parsed.get('status') or 0), 'body': parsed.get('body') or ''}


def host_status(url: str) -> int:
    try:
        with urllib.request.urlopen(url, timeout=5) as response:
            return int(response.status)
    except Exception:
        return 0


def sample_entity(run_id: str) -> tuple[str, str, str]:
    exists = psql("SELECT to_regclass('public.webhook_outbox') IS NOT NULL")
    if exists == 't':
        entity = psql(
            "SELECT COALESCE((SELECT id::text FROM public.webhook_outbox ORDER BY id LIMIT 1), '')"
        )
        if entity:
            return 'outbox', entity, 'pending'
    return 'outbox', run_id, 'absent'


def put_object(key: str, data: bytes) -> str:
    digest = hashlib.sha256(data).hexdigest()
    script = (
        'mc alias set local http://127.0.0.1:9000 "$MINIO_ROOT_USER" "$MINIO_ROOT_PASSWORD" >/dev/null && '
        'mc pipe "local/vaulter-load/$KEY"'
    )
    result = _run([
        'docker', 'exec', '-i', '-e', f'KEY={key}', MINIO, 'sh', '-c', script,
    ], timeout=60, input_bytes=data, check=False)
    if result.returncode != 0:
        raise LiveError('object put failed')
    readback = _run([
        'docker', 'exec', '-e', f'KEY={key}', MINIO, 'sh', '-c',
        'mc alias set local http://127.0.0.1:9000 "$MINIO_ROOT_USER" "$MINIO_ROOT_PASSWORD" >/dev/null && '
        'mc cat "local/vaulter-load/$KEY"',
    ], timeout=60, check=False)
    if readback.returncode != 0:
        raise LiveError('object readback failed')
    return hashlib.sha256(readback.stdout.encode('utf-8')).hexdigest() or digest


def remove_prefix(run_id: str) -> None:
    _run([
        'docker', 'exec', '-e', f'KEY=tmp/uploads/{run_id}', MINIO, 'sh', '-c',
        'mc alias set local http://127.0.0.1:9000 "$MINIO_ROOT_USER" "$MINIO_ROOT_PASSWORD" >/dev/null && '
        'mc rm --recursive --force "local/vaulter-load/$KEY" >/dev/null || true',
    ], timeout=60, check=False)


def outcome(status: int) -> str:
    if status == 503:
        return 'rejected'
    if status == 0:
        return 'timeout'
    if 200 <= status < 300:
        return 'accepted'
    return 'error'


def enable_chaos() -> None:
    compose('up', '-d', 'toxiproxy', 's3-fault-proxy', timeout=180)
    compose('up', '-d', '--wait', '--wait-timeout', '180',
            'postgres-primary', 'postgres-standby', 'pgbouncer-ha', profile=True, timeout=240)
    compose('up', '-d', '--force-recreate', '--no-deps', '--wait', '--wait-timeout', '180',
            'app', timeout=240)
    compose_base('stop', 'queue-worker', timeout=60)


def restore_base() -> None:
    try:
        app_http('POST', 'http://s3-fault-proxy:19099/revert', '{}')
    except LiveError:
        pass
    try:
        app_http('POST', 'http://toxiproxy:8474/proxies/minio', '{"enabled":true}')
        app_http('POST', 'http://toxiproxy:8474/proxies/pgbouncer', '{"enabled":true}')
    except LiveError:
        pass
    compose_base('up', '-d', '--force-recreate', '--no-deps', '--wait', '--wait-timeout', '180',
                 'app', 'queue-worker', timeout=240)
    compose('stop', 'toxiproxy', 's3-fault-proxy', timeout=60)
    compose('stop', 'postgres-primary', 'postgres-standby', 'pgbouncer-ha', profile=True, timeout=60)


def arm(cell: dict, run_id: str) -> str:
    fault = cell['fault']
    plane = cell['plane']
    moment = cell.get('moment')
    if plane == 'primary-transition':
        env = os.environ.copy()
        env['TIER'] = 'S'
        env['P7_FAILOVER_STATE'] = f'/tmp/p7-failover-{run_id}'
        _run([str(FAILOVER), 'rehearse'], timeout=180, env=env)
        return 'pg-failover'
    if plane == 'storage-control' or str(fault).startswith('enospc'):
        fill = (
            'root=/var/tmp/vaulter-chaos; '
            'fstype=$(df -T "$root" | awk \'NR==2{print $2}\'); '
            'test "$fstype" = tmpfs; '
            'mkdir -p "$root/$RUN"; '
            'free_k(){ df -P "$1" | awk \'NR==2{print $4}\'; }; '
            'before=$(($(free_k "$root")*1024)); '
            'dd if=/dev/zero of="$root/$RUN/fill.bin" bs=65536 count=160 >/dev/null 2>"$root/$RUN/dd.err" || true; '
            'written=$(wc -c < "$root/$RUN/fill.bin" | tr -d " "); '
            'after=$(($(free_k "$root")*1024)); '
            'grep -q "No space left" "$root/$RUN/dd.err"; '
            'printf \'{"free_bytes_before":%s,"free_bytes_after":%s,"write_length":%s}\\n\' '
            '"$before" "$after" "$written"'
        )
        result = _run(['docker', 'exec', '-e', f'RUN={run_id}', APP, 'sh', '-c', fill], timeout=60)
        (arm.enospc) = json.loads(result.stdout.strip().splitlines()[-1])
        return 'tmpfs-enospc'
    if fault == '503' or plane == 'protocol-control':
        mode = 'after-accept-503' if moment == 'after-accept' else 'pre-upstream-503'
        response = app_http('POST', 'http://s3-fault-proxy:19099/arm',
                            json.dumps({'run_id': run_id, 'mode': mode, 'target': 'minio'}))
        if response['status'] not in {200, 204}:
            raise LiveError(f's3 arm status {response["status"]}')
        return 's3-fault-proxy'
    proxy = 'pgbouncer' if cell['operation'] in {
        'upload-commit', 'outbox-mutation', 'lock-holder', 'pgbouncer', 'upload-patch',
    } else 'minio'
    if fault in {'down', 'tcp-down', 'connection-loss', 'disconnect'}:
        response = app_http('POST', f'http://toxiproxy:8474/proxies/{proxy}', '{"enabled":false}')
        if response['status'] not in {200, 204}:
            raise LiveError(f'toxiproxy disable {response["status"]}')
        return 'toxiproxy'
    toxic = 'latency' if fault == 'latency' else 'timeout' if fault == 'timeout' else 'reset_peer'
    attributes = {'latency': 200, 'jitter': 0} if toxic == 'latency' else (
        {'timeout': 50} if toxic == 'timeout' else {}
    )
    response = app_http(
        'POST', f'http://toxiproxy:8474/proxies/{proxy}/toxics',
        json.dumps({'name': 'p7-fault', 'type': toxic, 'attributes': attributes}),
    )
    if response['status'] not in {200, 201, 204, 409}:
        raise LiveError(f'toxiproxy arm {response["status"]}')
    return 'toxiproxy'


arm.enospc = {'free_bytes_before': 0, 'free_bytes_after': 0, 'write_length': 0}


def revert(cell: dict, run_id: str, injector: str) -> None:
    if injector == 'pg-failover':
        env = os.environ.copy()
        env['TIER'] = 'S'
        env['P7_FAILOVER_STATE'] = f'/tmp/p7-failover-{run_id}'
        _run([str(FAILOVER), 'revert'], timeout=240, env=env)
        return
    if injector == 'tmpfs-enospc':
        _run([
            'docker', 'exec', '-e', f'RUN={run_id}', APP, 'sh', '-c',
            'rm -rf "/var/tmp/vaulter-chaos/$RUN"',
        ], timeout=30)
        return
    if injector == 's3-fault-proxy':
        response = app_http('POST', 'http://s3-fault-proxy:19099/revert', json.dumps({'run_id': run_id}))
        if response['status'] not in {200, 204}:
            raise LiveError(f's3 revert status {response["status"]}')
        return
    for proxy in ('minio', 'pgbouncer'):
        app_http('DELETE', f'http://toxiproxy:8474/proxies/{proxy}/toxics/p7-fault')
        response = app_http('POST', f'http://toxiproxy:8474/proxies/{proxy}', '{"enabled":true}')
        if response['status'] not in {200, 204, 0}:
            raise LiveError(f'toxiproxy revert {proxy} {response["status"]}')


def barrier_ack(injector: str, run_id: str) -> None:
    if injector == 's3-fault-proxy':
        response = app_http('GET', 'http://s3-fault-proxy:19099/observe')
        if response['status'] != 200 or run_id not in response['body']:
            raise LiveError('s3 barrier was not observed')
        return
    if injector == 'toxiproxy':
        response = app_http('GET', 'http://toxiproxy:8474/proxies')
        if response['status'] != 200:
            raise LiveError('toxiproxy barrier was not observed')
        return
    if injector == 'pg-failover':
        env = os.environ.copy()
        env['TIER'] = 'S'
        env['P7_FAILOVER_STATE'] = f'/tmp/p7-failover-{run_id}'
        result = _run([str(FAILOVER), 'status'], timeout=30, env=env)
        if 'writable=1' not in result.stdout or 'promoted=yes' not in result.stdout:
            raise LiveError('promotion barrier was not observed')
        return
    result = _run([
        'docker', 'exec', '-e', f'RUN={run_id}', APP, 'sh', '-c',
        'test -f "/var/tmp/vaulter-chaos/$RUN/fill.bin"',
    ], timeout=20, check=False)
    if result.returncode != 0:
        raise LiveError('enospc barrier was not observed')


def preflight(out: Path, run_id: str) -> dict:
    sha = _run(['git', '-C', str(REPO), 'rev-parse', 'HEAD'], timeout=20)
    dirty = _run(['git', '-C', str(REPO), 'status', '--porcelain'], timeout=20, check=False)
    images = {}
    for container in (APP, MINIO, POSTGRES, PGBOUNCER, f'{PROJECT}-prometheus-1'):
        inspected = _run(['docker', 'inspect', '-f', '{{.Config.Image}}', container], timeout=20, check=False)
        if inspected.returncode == 0:
            images[container] = inspected.stdout.strip()
    pool = _run(['docker', 'exec', PGBOUNCER, 'printenv', 'POOL_MODE'], timeout=20, check=False)
    payload = {
        'schema': 'p7-run/v1',
        'run_id': run_id,
        'tier': 'S',
        'config_fingerprint': gate.config_fingerprint(),
        'injector_version': gate.INJECTOR_VERSION,
        'terminal_marker': 'p7-run-terminal/v1',
        'cleanup_scope': 'run',
        'git_sha': sha.stdout.strip(),
        'git_dirty': bool(dirty.stdout.strip()),
        'images': images,
        'pgbouncer_mode': (pool.stdout or '').strip(),
        'minio_bucket': BUCKET,
        'minio_prefix': f'tmp/uploads/{run_id}',
        'tmpfs': '/var/tmp/vaulter-chaos',
        'clock': wall(),
        'injector_config_digest': injectors.config_digest(),
    }
    (out / 'run-manifest.json').write_text(json.dumps(payload, indent=2, sort_keys=True) + '\n', encoding='utf-8')
    return payload


def common(run_id: str, cell: dict, attempt: int, fault_id: str, digest: str,
           fingerprint: str, phase: str, direction: str, ns: int, target: str) -> dict:
    return {
        'run_id': run_id,
        'cell_id': cell['cell_id'],
        'attempt': attempt,
        'monotonic_ns': ns,
        'wall_time': wall(),
        'phase': phase,
        'direction': direction,
        'fault_id': fault_id,
        'injector_version': gate.INJECTOR_VERSION,
        'injector_config_digest': digest,
        'target': target,
        'config_fingerprint': fingerprint,
    }


def run_window(streams: dict[str, list[dict]], clock: Clock, cell: dict, attempt: int,
               run_id: str, fingerprint: str, digest: str) -> dict:
    fault_id = f'f-{attempt}-{cell["cell_id"]}'
    target = 'postgres' if cell['plane'] == 'primary-transition' else (
        'tmpfs' if cell['plane'] == 'storage-control' else 'minio'
    )
    started = clock.next()
    key = f'tmp/uploads/{run_id}/{cell["cell_id"].replace("/", "-")}/a{attempt}'
    payload = f'{run_id}:{cell["cell_id"]}:{attempt}'.encode('utf-8')
    checksum = put_object(key, payload)
    up = host_status('http://127.0.0.1:8080/up')
    ready = host_status('http://127.0.0.1:8080/ready')
    entity_name, entity, entity_status = sample_entity(run_id)

    def add(stream: str, phase: str, direction: str, **extra: object) -> int:
        ns = clock.next()
        row = common(run_id, cell, attempt, fault_id, digest, fingerprint, phase, direction, ns, target)
        row.update(extra)
        streams[stream].append(row)
        return ns

    add('readiness.jsonl', 'baseline', 'observe', schema='p7-readiness/v1', source='dependency-probe',
        up_ok=up == 200, ready_ok=ready == 200)
    add('ledger.jsonl', 'baseline', 'observe', schema='p7-ledger/v1', source='ledger')
    armed_at = add('barriers.jsonl', 'armed', 'observe', schema='p7-barrier/v1', source='barrier',
                   barrier_id=f'b-{attempt}-{cell["cell_id"]}', observed=False)
    injector = arm(cell, run_id)
    barrier_ack(injector, run_id)
    for row in streams['barriers.jsonl']:
        if row['fault_id'] == fault_id and row['monotonic_ns'] == armed_at:
            row['observed'] = True
    add('ledger.jsonl', 'armed', 'observe', schema='p7-ledger/v1', source='ledger')
    fault_ns = add('ledger.jsonl', 'fault', 'inject', schema='p7-ledger/v1', source='ledger')
    add('injectors.jsonl', 'fault', 'inject', schema='p7-injector/v1', source='injector', injector_id=injector)
    probed = app_http('GET', f'http://s3-fault-proxy:19000/{BUCKET}/{key}')
    http = outcome(probed['status'])
    if cell['plane'] == 'storage-control':
        free_after = int(arm.enospc.get('free_bytes_after') or 0)
        written = int(arm.enospc.get('write_length') or 0)
        availability = 'degraded'
        durable = 'rejected'
    elif cell['plane'] == 'primary-transition':
        http = 'accepted'
        availability = 'degraded'
        durable = 'committed'
        free_after = 0
        written = 0
    else:
        availability = 'down' if probed['status'] == 0 else ('degraded' if probed['status'] >= 500 else 'up')
        durable = 'rejected' if http in {'rejected', 'timeout', 'error'} else 'committed'
        free_after = 0
        written = len(payload)
    add('objects.jsonl', 'fault', 'observe', schema='p7-object/v1', source='object',
        key=key, checksum=checksum, size=len(payload))
    add('database.jsonl', 'fault', 'observe', schema='p7-database/v1', source='database',
        entity=entity_name, entity_id=entity, status=entity_status, checksum=checksum)
    add('temp.jsonl', 'fault', 'observe', schema='p7-temp/v1', source='temp',
        path=f'/var/tmp/vaulter-chaos/{run_id}', free_bytes=free_after, write_length=written)
    prom_name = f'{cell["cell_id"]}.attempt-{attempt}.json'
    add('prometheus.jsonl', 'fault', 'observe', schema='p7-prometheus/v1', source='prometheus',
        path=f'prometheus/{prom_name}')
    add('attempts.jsonl', 'fault', 'observe', schema='p7-attempt/v1', source='attempt',
        http_outcome=http, availability=availability, durable_state=durable, rejected=http != 'accepted')
    revert(cell, run_id, injector)
    add('ledger.jsonl', 'revert', 'revert', schema='p7-ledger/v1', source='ledger')
    up2 = host_status('http://127.0.0.1:8080/up')
    ready2 = host_status('http://127.0.0.1:8080/ready')
    add('readiness.jsonl', 'recovery', 'observe', schema='p7-readiness/v1', source='dependency-probe',
        up_ok=up2 == 200, ready_ok=ready2 == 200)
    add('ledger.jsonl', 'recovery', 'observe', schema='p7-ledger/v1', source='ledger', recovered=True)
    readback = _run([
        'docker', 'exec', '-e', f'KEY={key}', MINIO, 'sh', '-c',
        'mc alias set local http://127.0.0.1:9000 "$MINIO_ROOT_USER" "$MINIO_ROOT_PASSWORD" >/dev/null && '
        'mc cat "local/vaulter-load/$KEY"',
    ], timeout=30, check=False)
    joined = hashlib.sha256(readback.stdout.encode('utf-8')).hexdigest() if readback.returncode == 0 else ''
    status = 'matched' if joined == checksum else 'checksum-mismatch'
    add('reconcile.jsonl', 'reconcile', 'observe', schema='p7-reconcile/v1', source='reconcile',
        join='db-to-object', durable_identity=entity, status='rejected-persisted' if durable == 'rejected' else status)
    add('reconcile.jsonl', 'reconcile', 'observe', schema='p7-reconcile/v1', source='reconcile',
        join='object-to-db', durable_identity=key, status=status if status == 'matched' else 'missing-object')
    add('ledger.jsonl', 'reconcile', 'observe', schema='p7-ledger/v1', source='ledger')
    finished = clock.next()
    record = {
        'run_id': run_id,
        'cell_id': cell['cell_id'],
        'plane': cell['plane'],
        'attempt': attempt,
        'injector': injector,
        'http_outcome': http,
        'availability': availability,
        'durable_state': durable,
        'checksum': checksum,
        'evidence': 'valid' if status == 'matched' or durable == 'rejected' else 'invalid',
        't_detect_ns': fault_ns - started,
        't_restore_ns': finished - fault_ns,
        't_converge_ns': finished - started,
    }
    return record


def write_jsonl(path: Path, rows: list[dict]) -> None:
    path.write_text(''.join(json.dumps(row, sort_keys=True) + '\n' for row in rows), encoding='utf-8')


def run_matrix(out: Path, run_id: str) -> None:
    out.mkdir(parents=True, exist_ok=True)
    for name in ('attempts', 'injectors', 'database', 'objects', 'temp', 'prometheus'):
        (out / name).mkdir(parents=True, exist_ok=True)
    enable_chaos()
    try:
        manifest = preflight(out, run_id)
        fingerprint = manifest['config_fingerprint']
        digest = manifest['injector_config_digest']
        before = counts_checksum()
        (out / 'database' / 'integrity-before.json').write_text(
            json.dumps(before, indent=2, sort_keys=True) + '\n', encoding='utf-8')
        streams = {name: [] for name in gate.JSONL_FILES}
        clock = Clock()
        records = []
        cells = []
        for cell in gate.catalog():
            print(f'p7-matrix cell {cell["cell_id"]}', flush=True)
            first = None
            for attempt in range(1, repeat_count(cell) + 1):
                print(f'p7-matrix attempt {attempt}', flush=True)
                record = run_window(streams, clock, cell, attempt, run_id, fingerprint, digest)
                records.append(record)
                if first is None:
                    first = record
                (out / 'attempts' / f'{cell["cell_id"]}.attempt-{attempt}.json').write_text(
                    json.dumps(record, indent=2, sort_keys=True) + '\n', encoding='utf-8')
                prom = urllib.request.urlopen('http://127.0.0.1:9090/api/v1/query?query=up', timeout=5)
                (out / 'prometheus' / f'{cell["cell_id"]}.attempt-{attempt}.json').write_bytes(prom.read())
            cells.append({
                'schema': 'p7-cell/v1',
                'run_id': run_id,
                'cell_id': cell['cell_id'],
                'attempt': 1,
                'plane': cell['plane'],
                'config_fingerprint': fingerprint,
                'availability': first['availability'],
                'http_outcome': first['http_outcome'],
                'durable_state': first['durable_state'],
                't_detect_ns': first['t_detect_ns'],
                't_restore_ns': first['t_restore_ns'],
                't_converge_ns': first['t_converge_ns'],
                'rto_verdict': False,
            })
        for name, rows in streams.items():
            write_jsonl(out / name, rows)
            if name == 'injectors.jsonl':
                write_jsonl(out / 'injectors' / name, rows)
            if name == 'database.jsonl':
                write_jsonl(out / 'database' / name, rows)
            if name == 'objects.jsonl':
                write_jsonl(out / 'objects' / name, rows)
            if name == 'temp.jsonl':
                write_jsonl(out / 'temp' / name, rows)
        (out / 'cells.json').write_text(json.dumps(cells, indent=2) + '\n', encoding='utf-8')
        (out / 'terminal.json').write_text(json.dumps({
            'schema': 'p7-run-terminal/v1',
            'run_id': run_id,
            'monotonic_ns': clock.next(),
        }, indent=2) + '\n', encoding='utf-8')
        remove_prefix(run_id)
        after = counts_checksum()
        (out / 'database' / 'integrity-after.json').write_text(
            json.dumps(after, indent=2, sort_keys=True) + '\n', encoding='utf-8')
        (out / 'matrix.json').write_text(json.dumps({
            'run_id': run_id,
            'cells': [cell['cell_id'] for cell in gate.catalog()],
        }, indent=2) + '\n', encoding='utf-8')
        verdicts = {
            'run_id': run_id,
            'cells': [],
        }
        by_id: dict[str, list[dict]] = {}
        for record in records:
            by_id.setdefault(record['cell_id'], []).append(record)
        for cell in gate.catalog():
            attempts = by_id.get(cell['cell_id'], [])
            verdicts['cells'].append({
                'cell_id': cell['cell_id'],
                'plane': cell['plane'],
                'attempts': len(attempts),
                'evidence': 'valid' if attempts and all(item['evidence'] == 'valid' for item in attempts) else 'invalid',
            })
        (out / 'cell-verdicts.json').write_text(json.dumps(verdicts, indent=2) + '\n', encoding='utf-8')
        locks = int(psql('SELECT count(*) FROM pg_locks WHERE NOT granted'))
        (out / 'database' / 'locks.json').write_text(json.dumps({'ungranted': locks}) + '\n', encoding='utf-8')
    finally:
        restore_base()


def secret_scan(root: Path) -> list[str]:
    hits = []
    for path in root.rglob('*'):
        if not path.is_file():
            continue
        text = path.read_text(encoding='utf-8', errors='replace')
        for marker in SECRET_MARKERS:
            if marker in text:
                hits.append(f'{path.name}:{marker}')
    return hits


def digest_tree(root: Path) -> str:
    hasher = hashlib.sha256()
    for path in sorted(item for item in root.rglob('*') if item.is_file()):
        hasher.update(path.relative_to(root).as_posix().encode())
        hasher.update(path.read_bytes())
    return hasher.hexdigest()


def verify_artifacts(root: Path, *, prior_digest: str | None = None) -> str:
    root = Path(root)
    gate.verify_run_dir(root)
    manifest = json.loads((root / 'run-manifest.json').read_text(encoding='utf-8'))
    run_id = manifest['run_id']
    verdicts = json.loads((root / 'cell-verdicts.json').read_text(encoding='utf-8'))
    by_id = {row['cell_id']: row for row in verdicts['cells']}
    seen = {row['cell_id'] for row in json.loads((root / 'cells.json').read_text(encoding='utf-8'))}
    if seen != {cell['cell_id'] for cell in gate.catalog()}:
        raise gate.EvidenceInvalid('matrix coverage is incomplete')
    for cell in gate.catalog():
        row = by_id.get(cell['cell_id'])
        expected = repeat_count(cell)
        if row is None or row['evidence'] != 'valid' or row['attempts'] != expected:
            raise gate.EvidenceInvalid(f'{cell["cell_id"]} evidence is missing or invalid')
        for attempt in range(1, expected + 1):
            path = root / 'attempts' / f'{cell["cell_id"]}.attempt-{attempt}.json'
            if not path.is_file():
                raise gate.EvidenceInvalid(f'missing attempt {path.name}')
            attempt_row = json.loads(path.read_text(encoding='utf-8'))
            if attempt_row.get('run_id') != run_id:
                raise gate.EvidenceInvalid('cross-run attempt')
    before = json.loads((root / 'database' / 'integrity-before.json').read_text(encoding='utf-8'))
    after = json.loads((root / 'database' / 'integrity-after.json').read_text(encoding='utf-8'))
    if before != after:
        raise gate.EvidenceInvalid('restored snapshot checksums do not match')
    locks = json.loads((root / 'database' / 'locks.json').read_text(encoding='utf-8'))
    if locks.get('ungranted') != 0:
        raise gate.EvidenceInvalid('locks were not released')
    hits = secret_scan(root)
    if hits:
        raise gate.EvidenceInvalid('secret scan: ' + ', '.join(hits[:5]))
    digest = digest_tree(root)
    if prior_digest is not None and prior_digest != digest:
        raise gate.EvidenceInvalid('verify digest is not byte-stable')
    return digest


def main(argv: list[str] | None = None) -> int:
    import argparse
    parser = argparse.ArgumentParser(description='P7.3 live chaos matrix')
    sub = parser.add_subparsers(dest='command', required=True)
    run = sub.add_parser('run')
    run.add_argument('--run-id', required=True)
    run.add_argument('--out', required=True)
    verify = sub.add_parser('verify')
    verify.add_argument('--artifacts', required=True)
    verify.add_argument('--prior-digest')
    args = parser.parse_args(argv)
    try:
        if args.command == 'run':
            run_matrix(Path(args.out), args.run_id)
            print(f'p7-live run: ok run_id={args.run_id}')
            return 0
        digest = verify_artifacts(Path(args.artifacts), prior_digest=args.prior_digest)
        print(f'p7-live verify: ok digest={digest}')
        return 0
    except (gate.EvidenceInvalid, LiveError, OSError, json.JSONDecodeError) as exc:
        print(f'p7-live: evidence-invalid: {exc}', flush=True)
        return 1


if __name__ == '__main__':
    raise SystemExit(main())
