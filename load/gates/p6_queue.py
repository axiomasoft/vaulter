#!/usr/bin/env python3
"""P6.1 queue-lane schema, tier-S matrix, and evidence validator.

Product fixtures and the authoritative run belong to P6.2/P6.3. This module
owns the closed planes, the dry-run matrix, and fail-closed verification.
A missing sample is evidence-invalid. Outbox rows/s, jobs/s, Redis depth,
worker occupancy and HTTP receives stay separate fields.
"""

from __future__ import annotations

import argparse
import hashlib
import json
import re
import sys
from pathlib import Path


PLANES = (
    'product-realistic',
    'overlap-control',
    'state-machine-control',
    'dirty-cardinality-probe',
)
SINKS = ('2xx', '500', 'slow-under-timeout', 'over-timeout')
FANOUTS = ('0', '1', 'all')
INGRESS = (20, 100, 200)
QUEUES = ('load-health', 'default')
WORKER_COMMAND = (
    'php vendor/bin/testbench queue:work redis '
    '--queue=load-health,default --sleep=1 --tries=1'
)
RETRY_AFTER = 90
WORKER_TIMEOUT = 60
TRIES = 1
TIER_S_WORKERS = 2
SINK_MS = 50
OUTBOX_ROWS = 10_000
ENDPOINTS = 20
DUE_ROWS = 5_000
PRODUCT_SLOS = ('SLO-58', 'SLO-61', 'SLO-64')
LIFECYCLE_SOURCES = ('queued', 'processing', 'processed', 'exception', 'failed')
TERMINAL_SOURCES = ('processed', 'exception', 'failed')
FORBIDDEN_KEYS = frozenset({
    'body', 'signature', 'secret', 'authorization', 'dsn', 'payload', 'command',
})
WALL_TIME = re.compile(r'^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?Z$')
JSONL_FILES = (
    'lifecycle.jsonl',
    'redis.jsonl',
    'workers.jsonl',
    'db-transitions.jsonl',
    'sink-ledger.jsonl',
    'links.jsonl',
)


class EvidenceInvalid(Exception):
    """A sample, identity, or ordering rule failed."""


def redis_key_map() -> dict:
    mapped = {}
    for queue in QUEUES:
        base = f'queues:{queue}'
        mapped[queue] = {
            'pending': base,
            'pending_op': 'LLEN',
            'delayed': f'{base}:delayed',
            'delayed_op': 'ZCARD',
            'reserved': f'{base}:reserved',
            'reserved_op': 'ZCARD',
        }
    return mapped


def fingerprint_payload() -> dict:
    return {
        'forbidden_pending_sample': 'RedisQueue.size',
        'pending_sample': 'pendingSize-or-LLEN',
        'queues': list(QUEUES),
        'redis_keys': redis_key_map(),
        'redis_prefix': '',
        'retry_after': RETRY_AFTER,
        'sink_ms': SINK_MS,
        'tier_s_workers': TIER_S_WORKERS,
        'tries': TRIES,
        'worker_command': WORKER_COMMAND,
        'worker_timeout': WORKER_TIMEOUT,
    }


def config_fingerprint() -> str:
    raw = json.dumps(fingerprint_payload(), sort_keys=True, separators=(',', ':'))
    return hashlib.sha256(raw.encode('utf-8')).hexdigest()


def _base_cell(**extra) -> dict:
    cell = {
        'workers': TIER_S_WORKERS,
        'outbox_rows': OUTBOX_ROWS,
        'endpoints': ENDPOINTS,
        'due_rows': DUE_ROWS,
        'ingress_per_s': None,
        'fanout': None,
        'sink': None,
        'throughput_claim': False,
        'slo': [],
    }
    cell.update(extra)
    return cell


def catalog() -> list[dict]:
    cells = []
    for ingress in INGRESS:
        for fanout in FANOUTS:
            for sink in SINKS:
                cells.append(_base_cell(
                    cell_id=f'product-realistic.relay.i{ingress}.f{fanout}.{sink}',
                    plane='product-realistic',
                    ingress_per_s=ingress,
                    fanout=fanout,
                    sink=sink,
                    throughput_claim=True,
                    slo=list(PRODUCT_SLOS),
                ))
    cells.extend([
        _base_cell(
            cell_id='overlap-control.relay-retry.barrier.relay2-retry2',
            plane='overlap-control',
        ),
        _base_cell(
            cell_id='overlap-control.duplicate-job.serial.same-delivery',
            plane='overlap-control',
        ),
        _base_cell(
            cell_id='overlap-control.duplicate-job.concurrent.same-delivery',
            plane='overlap-control',
        ),
        _base_cell(
            cell_id='state-machine-control.retry.backoff.force-due',
            plane='state-machine-control',
        ),
        _base_cell(
            cell_id='state-machine-control.retry.dead.force-due',
            plane='state-machine-control',
        ),
        _base_cell(
            cell_id='state-machine-control.endpoint.disable.after-dead',
            plane='state-machine-control',
        ),
        _base_cell(
            cell_id='dirty-cardinality-probe.endpoints.n200.attempt1',
            plane='dirty-cardinality-probe',
            endpoints=200,
        ),
        _base_cell(
            cell_id='dirty-cardinality-probe.endpoints.n1000.attempt1',
            plane='dirty-cardinality-probe',
            endpoints=1000,
        ),
        _base_cell(
            cell_id='dirty-cardinality-probe.due-rows.n50000.attempt1',
            plane='dirty-cardinality-probe',
            due_rows=50_000,
        ),
        _base_cell(
            cell_id='dirty-cardinality-probe.due-rows.n500000.attempt1',
            plane='dirty-cardinality-probe',
            due_rows=500_000,
        ),
    ])
    return cells


def _dash(value) -> str:
    if value is None or value == []:
        return '-'
    if isinstance(value, list):
        return ','.join(value)
    return str(value)


def matrix_text() -> str:
    lines = [(
        'p6-queue matrix tier=S '
        f'cells={len(catalog())} workers={TIER_S_WORKERS} sink_ms={SINK_MS} '
        f'retry_after={RETRY_AFTER} worker_timeout={WORKER_TIMEOUT} tries={TRIES} '
        f'queues={",".join(QUEUES)} fingerprint={config_fingerprint()}'
    )]
    for cell in catalog():
        claim = 'yes' if cell['throughput_claim'] else 'no'
        lines.append(
            f"{cell['cell_id']} plane={cell['plane']} throughput_claim={claim} "
            f"workers={cell['workers']} outbox={cell['outbox_rows']} "
            f"endpoints={cell['endpoints']} due={cell['due_rows']} "
            f"ingress_per_s={_dash(cell['ingress_per_s'])} fanout={_dash(cell['fanout'])} "
            f"sink={_dash(cell['sink'])} slo={_dash(cell['slo'])}"
        )
    lines.append('p6-queue dry-run: ok tier=S docker=no db-write=no')
    return '\n'.join(lines) + '\n'


def _require(condition: bool, message: str) -> None:
    if not condition:
        raise EvidenceInvalid(message)


def _int(value) -> bool:
    return isinstance(value, int) and not isinstance(value, bool)


def _load_json(path: Path) -> dict:
    try:
        value = json.loads(path.read_text(encoding='utf-8'))
    except (OSError, UnicodeError, json.JSONDecodeError) as exc:
        raise EvidenceInvalid(f'truncated json: {path.name}') from exc
    _require(isinstance(value, dict), f'{path.name} must be an object')
    return value


def _load_jsonl(path: Path) -> list[dict]:
    try:
        text = path.read_text(encoding='utf-8')
    except (OSError, UnicodeError) as exc:
        raise EvidenceInvalid(f'missing sample: {path.name}') from exc
    if text == '':
        raise EvidenceInvalid(f'missing sample: {path.name}')
    rows = []
    for line in text.splitlines():
        if line.strip() == '':
            continue
        try:
            row = json.loads(line)
        except json.JSONDecodeError as exc:
            raise EvidenceInvalid(f'truncated jsonl: {path.name}') from exc
        _require(isinstance(row, dict), f'truncated jsonl: {path.name}')
        rows.append(row)
    _require(rows, f'missing sample: {path.name}')
    return rows


def _forbidden(value, where: str) -> None:
    if isinstance(value, dict):
        for key, item in value.items():
            _require(key not in FORBIDDEN_KEYS, f'redacted field {key} in {where}')
            _forbidden(item, where)
        return
    if isinstance(value, list):
        for item in value:
            _forbidden(item, where)
        return
    if isinstance(value, str):
        lowered = value.lower()
        _require(
            not lowered.startswith(('postgres://', 'postgresql://', 'redis://')),
            f'redacted dsn in {where}',
        )


def _identity(row: dict, manifest: dict, known_cells: dict, where: str) -> None:
    _forbidden(row, where)
    _require(row.get('run_id') == manifest['run_id'], f'cross-run event in {where}')
    _require(row.get('cell_id') in known_cells, f'unknown cell in {where}')
    _require(row.get('attempt') == 1, f'attempt must be 1 in {where}')
    _require(_int(row.get('monotonic_ns')) and row['monotonic_ns'] >= 0, f'monotonic_ns in {where}')
    wall = row.get('wall_time')
    _require(isinstance(wall, str) and WALL_TIME.match(wall) is not None, f'wall_time in {where}')
    _require(isinstance(row.get('source'), str) and row['source'], f'source in {where}')
    _require(row.get('queue') in QUEUES, f'queue name in {where}')
    _require(row.get('config_fingerprint') == manifest['config_fingerprint'], f'config fingerprint in {where}')


def _check_lifecycle(rows: list[dict]) -> None:
    seen = set()
    grouped: dict[tuple, list[dict]] = {}
    by_uuid: dict[tuple, list[dict]] = {}
    for row in rows:
        _require(row.get('source') in LIFECYCLE_SOURCES, 'lifecycle source')
        _require(row.get('schema') == 'p6-lifecycle/v1', 'lifecycle schema')
        uuid = row.get('uuid')
        _require(isinstance(uuid, str) and uuid, 'lifecycle uuid')
        job_attempt = row.get('job_attempt')
        _require(_int(job_attempt) and job_attempt >= 0, 'lifecycle job_attempt')
        key = (row['run_id'], row['cell_id'], uuid, row['source'], job_attempt)
        _require(key not in seen, 'duplicate lifecycle key')
        seen.add(key)
        grouped.setdefault((row['cell_id'], uuid, job_attempt), []).append(row)
        by_uuid.setdefault((row['cell_id'], uuid), []).append(row)
    for events in grouped.values():
        by_source = {event['source']: event for event in events}
        if 'processed' in by_source and ('failed' in by_source or 'exception' in by_source):
            raise EvidenceInvalid('impossible ordering')
    for events in by_uuid.values():
        queued = [event['monotonic_ns'] for event in events if event['source'] == 'queued']
        processing = [event['monotonic_ns'] for event in events if event['source'] == 'processing']
        terminals = [event['monotonic_ns'] for event in events if event['source'] in TERMINAL_SOURCES]
        queued_ns = min(queued) if queued else None
        processing_ns = min(processing) if processing else None
        if queued_ns is not None and processing_ns is not None and processing_ns <= queued_ns:
            raise EvidenceInvalid('impossible ordering')
        for terminal_ns in terminals:
            if processing_ns is not None and terminal_ns <= processing_ns:
                raise EvidenceInvalid('impossible ordering')
            if queued_ns is not None and terminal_ns <= queued_ns:
                raise EvidenceInvalid('impossible ordering')


def _check_redis(rows: list[dict], cell_ids: set[str]) -> None:
    seen = {(row['cell_id'], row['queue']) for row in rows}
    for cell_id in cell_ids:
        for queue in QUEUES:
            _require((cell_id, queue) in seen, 'missing redis sample')
    for row in rows:
        _require(row.get('schema') == 'p6-redis/v1', 'redis schema')
        _require(row.get('source') == 'redis-depth', 'redis source')
        for field in ('pending', 'delayed', 'reserved'):
            value = row.get(field)
            _require(_int(value), 'missing redis sample')
            _require(value >= 0, 'negative depth')
        _require('size' not in row, 'redis total size is not pending depth')


def _check_rates(cell: dict) -> None:
    for field in (
        'outbox_rows_per_s', 'jobs_per_s', 'redis_pending', 'redis_delayed',
        'redis_reserved', 'worker_occupancy', 'http_receives',
    ):
        value = cell.get(field)
        _require(_int(value), f'missing sample: {field}')
        _require(value >= 0, 'negative depth' if field.startswith('redis_') else f'negative {field}')


def _check_sink_and_db(db_rows: list[dict], sink_rows: list[dict]) -> None:
    db_events = set()
    delivered = set()
    for row in db_rows:
        _require(row.get('schema') == 'p6-db/v1', 'db schema')
        _require(row.get('source') == 'db-transition', 'db source')
        event_id = row.get('event_id')
        _require(isinstance(event_id, str) and event_id, 'db event_id')
        db_events.add((row['cell_id'], event_id))
        if row.get('to_status') == 'delivered':
            delivered.add((row['cell_id'], event_id))
    sink_events = set()
    for row in sink_rows:
        _require(row.get('schema') == 'p6-sink/v1', 'sink schema')
        _require(row.get('source') == 'http-receive', 'sink source')
        _require(row.get('profile') in SINKS, 'sink profile')
        status = row.get('status')
        _require(_int(status) and 100 <= status <= 599, 'sink status')
        start = row.get('start_ns')
        end = row.get('end_ns')
        _require(_int(start) and _int(end), 'sink timing')
        _require(end > start, 'impossible ordering')
        ordinal = row.get('request_ordinal')
        _require(_int(ordinal) and ordinal >= 1, 'sink request ordinal')
        event_id = row.get('event_id')
        _require(isinstance(event_id, str) and event_id, 'sink event_id')
        sink_events.add((row['cell_id'], event_id))
    if delivered - sink_events or sink_events - db_events:
        raise EvidenceInvalid('db/sink mismatch')


def verify_run_dir(run_dir: Path) -> None:
    run_dir = Path(run_dir)
    manifest_path = run_dir / 'run-manifest.json'
    terminal_path = run_dir / 'terminal.json'
    _require(manifest_path.is_file(), 'missing run manifest')
    _require(terminal_path.is_file(), 'missing terminal marker')
    manifest = _load_json(manifest_path)
    _require(manifest.get('schema') == 'p6-run/v1', 'manifest schema')
    _require(manifest.get('tier') == 'S', 'tier must be S')
    _require(isinstance(manifest.get('run_id'), str) and manifest['run_id'], 'run_id')
    _require(manifest.get('config_fingerprint') == config_fingerprint(), 'config fingerprint')
    _require(manifest.get('worker_command') == WORKER_COMMAND, 'worker command')
    _require(manifest.get('retry_after') == RETRY_AFTER, 'retry_after')
    _require(manifest.get('worker_timeout') == WORKER_TIMEOUT, 'worker timeout')
    _require(manifest.get('tries') == TRIES, 'tries')
    _require(manifest.get('redis_keys') == redis_key_map(), 'redis keys')
    _require(manifest.get('terminal_marker') == 'p6-run-terminal/v1', 'terminal marker')
    try:
        cells = json.loads((run_dir / 'cells.json').read_text(encoding='utf-8'))
    except (OSError, UnicodeError, json.JSONDecodeError) as exc:
        raise EvidenceInvalid('truncated json: cells.json') from exc
    _require(isinstance(cells, list) and cells, 'missing cells')
    known = {cell['cell_id']: cell for cell in catalog()}
    seen_cells = set()
    for cell in cells:
        _require(isinstance(cell, dict), 'cell')
        cell_id = cell.get('cell_id')
        _require(cell_id in known, 'unknown cell')
        _require(cell_id not in seen_cells, 'duplicate cell')
        seen_cells.add(cell_id)
        expected = known[cell_id]
        _require(cell.get('plane') == expected['plane'], 'plane')
        _require(cell.get('plane') in PLANES, 'plane')
        _require(cell.get('run_id') == manifest['run_id'], 'cross-run event in cells')
        _require(cell.get('attempt') == 1, 'cell attempt')
        _require(cell.get('config_fingerprint') == manifest['config_fingerprint'], 'config fingerprint')
        _check_rates(cell)
    streams = {name: _load_jsonl(run_dir / name) for name in JSONL_FILES}
    for name, rows in streams.items():
        for row in rows:
            _identity(row, manifest, known, name)
            _require(row.get('cell_id') in seen_cells, f'cell without declaration in {name}')
    _check_lifecycle(streams['lifecycle.jsonl'])
    _check_redis(streams['redis.jsonl'], seen_cells)
    for cell_id in seen_cells:
        workers = [row for row in streams['workers.jsonl'] if row['cell_id'] == cell_id]
        _require(workers, 'missing worker sample')
        for row in workers:
            _require(row.get('schema') == 'p6-worker/v1', 'worker schema')
            _require(row.get('source') == 'worker-pid', 'worker source')
            _require(_int(row.get('pid')) and row['pid'] > 0, 'worker pid')
            _require(isinstance(row.get('restarted'), bool), 'worker restarted')
        sources = {row.get('source') for row in streams['links.jsonl'] if row['cell_id'] == cell_id}
        _require({'prometheus', 'pg-locks'} <= sources, 'missing prometheus or lock sample')
    _check_sink_and_db(streams['db-transitions.jsonl'], streams['sink-ledger.jsonl'])
    terminal = _load_json(terminal_path)
    _require(terminal.get('schema') == 'p6-run-terminal/v1', 'missing terminal marker')
    _require(terminal.get('run_id') == manifest['run_id'], 'cross-run event in terminal')
    _require(_int(terminal.get('monotonic_ns')), 'terminal monotonic_ns')


def assert_worker_route(compose_text: str, provider_text: str) -> None:
    for snippet in (
        'queue:work',
        "'redis'",
        '--queue=load-health,default',
        '--sleep=1',
        '--tries=1',
        'LOAD_QUEUE_LEDGER',
        'LOAD_QUEUE_FINGERPRINT',
    ):
        _require(snippet in compose_text, f'worker route missing {snippet}')
    _require("env('REDIS_QUEUE_RETRY_AFTER', 90)" in provider_text, 'retry_after default')
    _require('RecordQueueLifecycle::class' in provider_text, 'lifecycle listener')


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description='P6.1 queue lane')
    sub = parser.add_subparsers(dest='command', required=True)
    axes = sub.add_parser('axes')
    axes.add_argument('--tier', required=True)
    axes.add_argument('--env', required=True)
    sub.add_parser('catalog')
    verify = sub.add_parser('verify')
    verify.add_argument('--run-dir', required=True)
    route = sub.add_parser('worker-route')
    route.add_argument('--compose', required=True)
    route.add_argument('--provider', required=True)
    sub.add_parser('fingerprint')
    args = parser.parse_args(argv)
    try:
        if args.command == 'axes':
            _require(args.tier == 'S', 'P6.1 matrix is tier S')
            env_path = Path(args.env)
            _require(env_path.is_file(), f'missing env {env_path}')
            text = env_path.read_text(encoding='utf-8')
            _require(re.search(r'(?m)^TIER=S$', text) is not None, 'env TIER must be S')
            sys.stdout.write(matrix_text())
        elif args.command == 'catalog':
            json.dump(catalog(), sys.stdout, indent=2)
            sys.stdout.write('\n')
        elif args.command == 'verify':
            verify_run_dir(Path(args.run_dir))
            print(f'p6-queue verify: ok fingerprint={config_fingerprint()}')
        elif args.command == 'worker-route':
            assert_worker_route(
                Path(args.compose).read_text(encoding='utf-8'),
                Path(args.provider).read_text(encoding='utf-8'),
            )
            print('p6-queue worker-route: ok')
        else:
            print(config_fingerprint())
    except EvidenceInvalid as exc:
        print(f'p6-queue: evidence-invalid: {exc}', file=sys.stderr)
        return 1
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
