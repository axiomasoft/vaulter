#!/usr/bin/env python3
"""P7.1 chaos-lane schema, tier-S matrix, and evidence validator.

Injectors and the authoritative run belong to P7.2/P7.3. This module owns
the closed planes, phase ledger, barrier/injector event schema, collectors,
checksum reconciler, and fail-closed verification. A missing revert,
terminal marker, barrier, or checksum is evidence-invalid. Dependency
availability, HTTP outcome and durable product state stay separate fields.
Timing is recorded without an RTO verdict.
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
    'transport-control',
    'protocol-control',
    'primary-transition',
    'storage-control',
)
PHASES = (
    'baseline',
    'armed',
    'fault',
    'revert',
    'recovery',
    'reconcile',
)
PHASE_ORDER = {phase: index for index, phase in enumerate(PHASES)}
DIRECTIONS = ('inject', 'observe', 'revert')
PROTOCOL_OPS = (
    'tus-patch',
    's3-put-part',
    's3-complete',
    'cas-promote',
    'abort',
    'sweeper',
)
PROTOCOL_MOMENTS = ('pre-upstream', 'after-accept', 'on-recovery')
INJECTOR_VERSION = 'p7.1-lane-stub'
CHECKSUM = re.compile(r'^[0-9a-f]{64}$')
WALL_TIME = re.compile(r'^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?Z$')
FORBIDDEN_KEYS = frozenset({
    'body', 'signature', 'secret', 'authorization', 'dsn', 'payload', 'command',
    'password', 'credential', 'access_key', 'secret_key', 'aws_secret',
})
JSONL_FILES = (
    'ledger.jsonl',
    'injectors.jsonl',
    'barriers.jsonl',
    'database.jsonl',
    'objects.jsonl',
    'temp.jsonl',
    'readiness.jsonl',
    'prometheus.jsonl',
    'attempts.jsonl',
    'reconcile.jsonl',
)
COLLECTORS = (
    'database',
    'minio',
    'temp',
    'readiness',
    'prometheus',
)
AMBIGUOUS = ('ambiguous', 'unresolved')
RESOLVED = ('matched', 'matched-absent', 'rejected-persisted', 'not-applicable')


class EvidenceInvalid(Exception):
    """A sample, identity, phase, or reconcile rule failed."""


def _product_cells() -> list[tuple[str, str]]:
    return [
        ('tus-patch', '503'),
        ('tus-patch', 'tcp-reset'),
        ('s3-put-part', '503'),
        ('s3-put-part', 'timeout'),
        ('s3-complete', '503'),
        ('cas-promote', '503'),
        ('cas-promote', 'tcp-down'),
        ('abort', '503'),
        ('sweeper', '503'),
        ('upload-patch', 'connection-loss'),
        ('upload-commit', 'connection-loss'),
        ('outbox-mutation', 'connection-loss'),
        ('lock-holder', 'disconnect'),
    ]


def _transport_cells() -> list[tuple[str, str]]:
    return [
        ('tus-patch', 'latency'),
        ('tus-patch', 'timeout'),
        ('tus-patch', 'reset'),
        ('tus-patch', 'down'),
        ('s3-put-part', 'latency'),
        ('s3-put-part', 'reset'),
        ('upload-commit', 'timeout'),
        ('upload-commit', 'reset'),
        ('outbox-mutation', 'reset'),
        ('outbox-mutation', 'down'),
        ('lock-holder', 'reset'),
        ('pgbouncer', 'reconnect'),
    ]


def _primary_cells() -> list[tuple[str, str]]:
    return [
        ('upload-commit', 'promote'),
        ('outbox-mutation', 'promote'),
        ('lock-holder', 'fence-then-promote'),
    ]


def _storage_cells() -> list[tuple[str, str]]:
    return [
        ('spill', 'enospc'),
        ('cas-stage', 'enospc'),
        ('write', 'enospc'),
        ('spill', 'enospc-then-retry'),
        ('cas-stage', 'enospc-then-retry'),
    ]


def _cell(plane: str, operation: str, fault: str, *, moment: str | None = None,
          integrity_claim: bool) -> dict:
    suffix = '' if moment is None else f'.{moment}'
    return {
        'cell_id': f'{plane}.{operation}.{fault}{suffix}',
        'plane': plane,
        'operation': operation,
        'fault': fault,
        'moment': moment,
        'integrity_claim': integrity_claim,
        'rto_verdict': False,
    }


def catalog() -> list[dict]:
    cells = [
        _cell('product-realistic', operation, fault, integrity_claim=True)
        for operation, fault in _product_cells()
    ]
    cells.extend(
        _cell('protocol-control', operation, '503', moment=moment, integrity_claim=False)
        for operation in PROTOCOL_OPS
        for moment in PROTOCOL_MOMENTS
    )
    cells.extend(
        _cell('transport-control', operation, toxic, integrity_claim=False)
        for operation, toxic in _transport_cells()
    )
    cells.extend(
        _cell('primary-transition', operation, fault, integrity_claim=False)
        for operation, fault in _primary_cells()
    )
    cells.extend(
        _cell('storage-control', operation, fault, integrity_claim=False)
        for operation, fault in _storage_cells()
    )
    return cells


def fingerprint_payload() -> dict:
    return {
        'cells': [cell['cell_id'] for cell in catalog()],
        'collectors': list(COLLECTORS),
        'injector_version': INJECTOR_VERSION,
        'phases': list(PHASES),
        'planes': list(PLANES),
    }


def config_fingerprint() -> str:
    raw = json.dumps(fingerprint_payload(), sort_keys=True, separators=(',', ':'))
    return hashlib.sha256(raw.encode('utf-8')).hexdigest()


def _dash(value) -> str:
    if value is None or value == []:
        return '-'
    if isinstance(value, bool):
        return 'yes' if value else 'no'
    return str(value)


def matrix_text() -> str:
    cells = catalog()
    lines = [(
        'p7-chaos matrix tier=S '
        f'cells={len(cells)} injector={INJECTOR_VERSION} '
        f'phases={",".join(PHASES)} planes={",".join(PLANES)} '
        f'fingerprint={config_fingerprint()}'
    )]
    for cell in cells:
        lines.append(
            f"{cell['cell_id']} plane={cell['plane']} "
            f"operation={cell['operation']} fault={cell['fault']} "
            f"moment={_dash(cell['moment'])} "
            f"integrity_claim={_dash(cell['integrity_claim'])} "
            f"rto_verdict=no"
        )
    lines.append('p7-chaos dry-run: ok tier=S docker=no db-write=no object-write=no')
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
            not lowered.startswith(('postgres://', 'postgresql://', 'redis://', 's3://')),
            f'redacted dsn in {where}',
        )


def _identity(row: dict, manifest: dict, known_cells: dict, where: str) -> None:
    _forbidden(row, where)
    _require(row.get('run_id') == manifest['run_id'], f'cross-run event in {where}')
    _require(row.get('cell_id') in known_cells, f'unknown cell in {where}')
    _require(_int(row.get('attempt')) and row['attempt'] >= 1, f'attempt in {where}')
    _require(_int(row.get('monotonic_ns')) and row['monotonic_ns'] >= 0, f'monotonic_ns in {where}')
    wall = row.get('wall_time')
    _require(isinstance(wall, str) and WALL_TIME.match(wall) is not None, f'wall_time in {where}')
    _require(row.get('phase') in PHASES, f'phase in {where}')
    _require(row.get('direction') in DIRECTIONS, f'direction in {where}')
    fault_id = row.get('fault_id')
    _require(isinstance(fault_id, str) and fault_id, f'fault_id in {where}')
    _require(isinstance(row.get('injector_version'), str) and row['injector_version'], f'injector version in {where}')
    digest = row.get('injector_config_digest')
    _require(isinstance(digest, str) and CHECKSUM.match(digest) is not None, f'injector config digest in {where}')
    _require(isinstance(row.get('target'), str) and row['target'], f'target in {where}')
    _require(row.get('config_fingerprint') == manifest['config_fingerprint'], f'config fingerprint in {where}')


def _check_phases(rows: list[dict]) -> None:
    by_cell: dict[tuple[str, int], list[dict]] = {}
    for row in rows:
        _require(row.get('schema') == 'p7-ledger/v1', 'ledger schema')
        by_cell.setdefault((row['cell_id'], row['attempt']), []).append(row)
    for (cell_id, _attempt), events in by_cell.items():
        ordered = sorted(events, key=lambda item: item['monotonic_ns'])
        last = -1
        seen = []
        for event in ordered:
            rank = PHASE_ORDER[event['phase']]
            if rank < last:
                raise EvidenceInvalid('impossible phase order')
            last = rank
            seen.append(event['phase'])
        _require('baseline' in seen, f'missing baseline in {cell_id}')
        if 'fault' in seen:
            _require('revert' in seen, 'missing revert')
        if 'armed' in seen:
            _require('fault' in seen or 'revert' in seen, f'armed without continuation in {cell_id}')


def _check_barriers(barriers: list[dict], ledger: list[dict]) -> None:
    observed = {}
    for row in barriers:
        _require(row.get('schema') == 'p7-barrier/v1', 'barrier schema')
        _require(row.get('observed') is True, 'missing barrier')
        _require(isinstance(row.get('barrier_id'), str) and row['barrier_id'], 'barrier_id')
        observed.setdefault(row['cell_id'], []).append(row)
    for event in ledger:
        if event['phase'] != 'armed':
            continue
        matches = [
            row for row in observed.get(event['cell_id'], [])
            if row['fault_id'] == event['fault_id'] and row['monotonic_ns'] <= event['monotonic_ns']
        ]
        _require(matches, 'missing barrier')


def _check_fault_ids(ledger: list[dict], injectors: list[dict]) -> None:
    owners: dict[str, str] = {}
    for row in (*ledger, *injectors):
        owner = owners.setdefault(row['fault_id'], row['cell_id'])
        if owner != row['cell_id']:
            raise EvidenceInvalid('overlapping fault ids')
    armed = False
    for event in sorted(ledger, key=lambda item: item['monotonic_ns']):
        if event['phase'] == 'fault':
            if armed:
                raise EvidenceInvalid('overlapping fault ids')
            armed = True
        elif event['phase'] == 'revert':
            armed = False


def _check_objects(rows: list[dict], manifest: dict) -> None:
    for row in rows:
        _require(row.get('schema') in {'p7-object/v1', 'p7-object/v2'}, 'object schema')
        _require(row.get('run_id') == manifest['run_id'], 'cross-run object')
        key = row.get('key')
        _require(isinstance(key, str) and key, 'object key')
        if row.get('schema') == 'p7-object/v1':
            _require(manifest['run_id'] in key, 'cross-run object')
        else:
            run_marker = row.get('run_marker')
            _require(
                isinstance(run_marker, str) and manifest['run_id'] in run_marker,
                'cross-run object marker',
            )
        exists = row.get('exists', True)
        _require(isinstance(exists, bool), 'object exists')
        checksum = row.get('checksum')
        if exists or checksum is not None:
            _require(isinstance(checksum, str) and CHECKSUM.match(checksum) is not None, 'absent checksum')
        _require(_int(row.get('size')) and row['size'] >= 0, 'object size')
        _require('body' not in row, 'redacted field body in objects')


def _check_database(rows: list[dict]) -> None:
    for row in rows:
        _require(row.get('schema') == 'p7-database/v1', 'database schema')
        _require(row.get('entity') in {
            'upload_session', 'upload_part', 'blob', 'file_version', 'outbox',
        }, 'database entity')
        _require(isinstance(row.get('entity_id'), str) and row['entity_id'], 'database entity_id')
        _require(isinstance(row.get('status'), str) and row['status'], 'database status')
        if row.get('checksum') is not None:
            _require(CHECKSUM.match(row['checksum']) is not None, 'absent checksum')


def _check_temp(rows: list[dict]) -> None:
    for row in rows:
        _require(row.get('schema') == 'p7-temp/v1', 'temp schema')
        _require(_int(row.get('free_bytes')) and row['free_bytes'] >= 0, 'temp free_bytes')
        _require(_int(row.get('write_length')) and row['write_length'] >= 0, 'temp write_length')
        path = row.get('path')
        _require(isinstance(path, str) and path, 'temp path')
        _require(row['run_id'] in path, 'temp path escapes run scope')


def _check_readiness(rows: list[dict]) -> dict[str, list[dict]]:
    by_cell: dict[str, list[dict]] = {}
    for row in rows:
        _require(row.get('schema') == 'p7-readiness/v1', 'readiness schema')
        _require(isinstance(row.get('up_ok'), bool), 'readiness /up')
        _require(isinstance(row.get('ready_ok'), bool), 'readiness /ready')
        if row.get('schema') == 'p7-readiness/v1' and 'metrics_ok' in row:
            _require(isinstance(row.get('metrics_ok'), bool), 'readiness /metrics')
        if row.get('phase') == 'baseline' and 'metrics_ok' in row:
            _require(row['up_ok'] and row['ready_ok'] and row['metrics_ok'], 'baseline is not green')
        _require(row.get('source') in {'up', 'ready', 'dependency-probe'}, 'readiness source')
        by_cell.setdefault(row['cell_id'], []).append(row)
    return by_cell


def _check_attempts(rows: list[dict], known: dict[str, dict]) -> None:
    for row in rows:
        _require(row.get('schema') in {'p7-attempt/v1', 'p7-attempt/v2'}, 'attempt schema')
        _require(row.get('http_outcome') in {'accepted', 'rejected', 'error', 'timeout', None}
                 or _int(row.get('http_outcome')), 'http outcome')
        _require(row.get('availability') in {'up', 'down', 'degraded'}, 'availability')
        _require(row.get('durable_state') in {
            'absent', 'pending', 'committed', 'rejected', 'ambiguous',
        }, 'durable state')
        _require('rto_verdict' not in row or row['rto_verdict'] is False, 'rto verdict')
        if row.get('schema') == 'p7-attempt/v2':
            expected = known[row['cell_id']]
            _require(row.get('operation') == expected['operation'], 'named product operation')
            results = row.get('product_results')
            _require(isinstance(results, list) and results, 'missing product result')
            request_ids = []
            for result in results:
                _require(isinstance(result, dict), 'product result')
                _require(result.get('operation') in {expected['operation'], 'tus-patch', 's3-put-part'},
                         'product operation mismatch')
                _require(_int(result.get('status')), 'product status')
                _require(result.get('transport') in {'http', 'artisan'}, 'product transport')
                request_id = result.get('request_id')
                _require(isinstance(request_id, str) and request_id, 'product request id')
                request_ids.append(request_id)
            if expected['plane'] == 'storage-control':
                first = results[0]
                first_failed = first['status'] == 0 or first['status'] >= 400
                if _int(first.get('requested_bytes')) and _int(first.get('written_bytes')):
                    first_failed = first_failed or first['written_bytes'] < first['requested_bytes']
                _require(first_failed, 'ENOSPC first write did not fail or short-write')
                if expected['operation'] in {'cas-stage', 'write'}:
                    _require(_int(first.get('requested_bytes')), 'ENOSPC requested bytes')
                    _require(_int(first.get('written_bytes')), 'ENOSPC written bytes')
                    _require(first['written_bytes'] < first['requested_bytes'], 'ENOSPC write was not short')
            if expected['fault'] == 'enospc-then-retry':
                _require(len(results) == 2, 'missing ENOSPC retry')
                _require(len(set(request_ids)) == 2, 'ENOSPC retry reused first request')
                retry = results[1]
                _require(200 <= retry['status'] < 300, 'ENOSPC retry did not succeed')
                _require(_int(retry.get('requested_bytes')), 'ENOSPC retry requested bytes')
                _require(_int(retry.get('written_bytes')), 'ENOSPC retry written bytes')
                _require(retry['written_bytes'] == retry['requested_bytes'], 'ENOSPC retry was short')


def _check_reconcile(rows: list[dict], readiness: dict[str, list[dict]],
                     ledger: list[dict]) -> None:
    by_cell: dict[str, list[dict]] = {}
    for row in rows:
        _require(row.get('schema') == 'p7-reconcile/v1', 'reconcile schema')
        join = row.get('join')
        _require(join in ('db-to-object', 'object-to-db'), 'reconcile join')
        status = row.get('status')
        _require(status in (*RESOLVED, *AMBIGUOUS, 'missing-object', 'missing-row',
                            'checksum-mismatch', 'orphan-object'), 'reconcile status')
        identity = row.get('durable_identity')
        if status in AMBIGUOUS:
            raise EvidenceInvalid('unresolved ambiguity')
        _require(isinstance(identity, str) and identity, 'reconcile durable_identity')
        by_cell.setdefault(row['cell_id'], []).append(row)
    recovered_cells = {
        event['cell_id']
        for event in ledger
        if event['phase'] == 'recovery' and event.get('recovered') is True
    }
    for cell_id in recovered_cells:
        probes = [
            row for row in readiness.get(cell_id, [])
            if row.get('ready_ok') is True and row.get('up_ok') is True
            and row.get('metrics_ok', True) is True
        ]
        _require(probes, 'recovered without dependency probe')
        matches = [row for row in by_cell.get(cell_id, []) if row.get('status') in RESOLVED]
        _require(matches, 'recovered without product reconcile')


def _check_injectors(rows: list[dict]) -> None:
    for row in rows:
        _require(row.get('schema') == 'p7-injector/v1', 'injector schema')
        _require(row.get('injector_version') == INJECTOR_VERSION, 'injector version')
        _require(isinstance(row.get('injector_id'), str) and row['injector_id'], 'injector_id')


def _check_timing(cell: dict) -> None:
    for field in ('t_detect_ns', 't_restore_ns', 't_converge_ns'):
        value = cell.get(field)
        _require(_int(value) and value >= 0, f'missing sample: {field}')
    _require(cell.get('rto_verdict') is False, 'rto verdict')
    _require(cell.get('availability') in {'up', 'down', 'degraded'}, 'cell availability')
    _require(cell.get('http_outcome') in {'accepted', 'rejected', 'error', 'timeout'}
             or _int(cell.get('http_outcome')), 'cell http outcome')
    _require(cell.get('durable_state') in {
        'absent', 'pending', 'committed', 'rejected', 'ambiguous',
    }, 'cell durable state')


def verify_run_dir(run_dir: Path) -> None:
    run_dir = Path(run_dir)
    manifest_path = run_dir / 'run-manifest.json'
    terminal_path = run_dir / 'terminal.json'
    _require(manifest_path.is_file(), 'missing run manifest')
    _require(terminal_path.is_file(), 'missing terminal marker')
    manifest = _load_json(manifest_path)
    _require(manifest.get('schema') == 'p7-run/v1', 'manifest schema')
    _require(manifest.get('tier') == 'S', 'tier must be S')
    _require(isinstance(manifest.get('run_id'), str) and manifest['run_id'], 'run_id')
    _require(manifest.get('config_fingerprint') == config_fingerprint(), 'config fingerprint')
    _require(manifest.get('injector_version') == INJECTOR_VERSION, 'injector version')
    _require(manifest.get('terminal_marker') == 'p7-run-terminal/v1', 'terminal marker')
    _require(manifest.get('cleanup_scope') == 'run', 'cleanup must be run-scoped')
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
        _check_timing(cell)
    streams = {name: _load_jsonl(run_dir / name) for name in JSONL_FILES}
    for name, rows in streams.items():
        for row in rows:
            _identity(row, manifest, known, name)
            _require(row.get('cell_id') in seen_cells, f'cell without declaration in {name}')
    _check_phases(streams['ledger.jsonl'])
    _check_barriers(streams['barriers.jsonl'], streams['ledger.jsonl'])
    _check_fault_ids(streams['ledger.jsonl'], streams['injectors.jsonl'])
    _check_injectors(streams['injectors.jsonl'])
    _check_objects(streams['objects.jsonl'], manifest)
    _check_database(streams['database.jsonl'])
    _check_temp(streams['temp.jsonl'])
    readiness = _check_readiness(streams['readiness.jsonl'])
    _check_attempts(streams['attempts.jsonl'], known)
    _check_reconcile(streams['reconcile.jsonl'], readiness, streams['ledger.jsonl'])
    for row in streams['prometheus.jsonl']:
        _require(row.get('schema') in {'p7-prometheus/v1', 'p7-prometheus/v2'}, 'prometheus schema')
        _require(isinstance(row.get('path'), str) and row['path'], 'prometheus path')
        if row.get('schema') == 'p7-prometheus/v2':
            _require(row.get('job') == 'vaulter-sut', 'prometheus SUT job')
    terminal = _load_json(terminal_path)
    _require(terminal.get('schema') == 'p7-run-terminal/v1', 'missing terminal marker')
    _require(terminal.get('run_id') == manifest['run_id'], 'cross-run event in terminal')
    _require(_int(terminal.get('monotonic_ns')), 'terminal monotonic_ns')


def revert_run_dir(run_dir: Path) -> dict:
    """Append exact-scope revert markers. Never deletes buckets, databases, or volumes."""
    run_dir = Path(run_dir)
    _require(run_dir.is_dir(), 'missing run dir for revert')
    marker = run_dir / 'emergency-revert.json'
    payload = {
        'schema': 'p7-emergency-revert/v1',
        'scope': 'run',
        'wildcard': False,
        'path': str(run_dir.name),
    }
    marker.write_text(json.dumps(payload, indent=2) + '\n', encoding='utf-8')
    return payload


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description='P7.1 chaos lane')
    sub = parser.add_subparsers(dest='command', required=True)
    axes = sub.add_parser('axes')
    axes.add_argument('--tier', required=True)
    axes.add_argument('--env', required=True)
    sub.add_parser('catalog')
    verify = sub.add_parser('verify')
    verify.add_argument('--run-dir', required=True)
    revert = sub.add_parser('revert')
    revert.add_argument('--run-dir', required=True)
    sub.add_parser('fingerprint')
    args = parser.parse_args(argv)
    try:
        if args.command == 'axes':
            _require(args.tier == 'S', 'P7.1 matrix is tier S')
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
            print(f'p7-chaos verify: ok fingerprint={config_fingerprint()}')
        elif args.command == 'revert':
            payload = revert_run_dir(Path(args.run_dir))
            print(f'p7-chaos revert: ok scope={payload["scope"]} wildcard=no')
        else:
            print(config_fingerprint())
    except EvidenceInvalid as exc:
        print(f'p7-chaos: evidence-invalid: {exc}', file=sys.stderr)
        return 1
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
