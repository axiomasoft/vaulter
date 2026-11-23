#!/usr/bin/env python3
"""P5.1 concurrency-lane schema, axis catalog, and evidence validator.

Product scenarios stay in P5.2. This module owns the closed axes, the
primitive-control SQL text, and fail-closed verification of raw evidence.
A missing sample is evidence-invalid. A present counter may be zero.
"""

from __future__ import annotations

import argparse
import json
import sys
import time
from pathlib import Path


PLANES = (
    'product-realistic',
    'product-worst-case',
    'primitive-control',
    'dirty-cardinality-probe',
)
SURFACES = (
    'upload-tus',
    'upload-s3',
    'cas-lock',
    'subtree-move',
    'sweeper-overlap',
    'advisory-primitive',
    'pgbouncer-queue',
)
SHAPES = (
    'same-resource',
    'different-resource',
    'normal-key',
    'int4-collision',
    'disjoint-tree',
    'overlapping-tree',
    'issuance-burst',
    'live-patch-overlap',
    'xact-release',
    'try-lock-nowait',
    'blocking-wait',
    'pool-queue',
    'pool-timeout',
    'dirty-20k',
    'dirty-1m',
)
SATURATION_N = (1, 8, 32, 200)
BOUNDARY_N = 201
CLOSED_N = SATURATION_N + (BOUNDARY_N,)
POLL_INTERVAL_MS = 50
REQUIRED_SOURCES = (
    'barrier',
    'pg_locks',
    'pg_stat_activity',
    'pg_blocking_pids',
    'pgbouncer_pools',
    'pgbouncer_stats',
)
POOL_FIELDS = (
    'cl_waiting',
    'sv_active',
    'maxwait',
    'pool_mode',
    'default_pool_size',
    'max_client_conn',
    'query_wait_timeout',
)
XACT_LOCKS = ('pg_advisory_xact_lock', 'pg_try_advisory_xact_lock')
SESSION_LOCKS = ('pg_advisory_lock', 'pg_try_advisory_lock', 'pg_advisory_unlock')

# Admitted cells. N=201 is only a client-boundary control.
_CELL_SPECS = (
    ('product-realistic', 'upload-tus', 'different-resource', SATURATION_N),
    ('product-realistic', 'upload-s3', 'different-resource', SATURATION_N),
    ('product-realistic', 'cas-lock', 'normal-key', SATURATION_N),
    ('product-realistic', 'subtree-move', 'disjoint-tree', SATURATION_N),
    ('product-realistic', 'sweeper-overlap', 'live-patch-overlap', (1, 8, 32)),
    ('product-worst-case', 'upload-tus', 'same-resource', SATURATION_N),
    ('product-worst-case', 'upload-s3', 'same-resource', SATURATION_N),
    ('product-worst-case', 'cas-lock', 'int4-collision', SATURATION_N),
    ('product-worst-case', 'subtree-move', 'overlapping-tree', SATURATION_N),
    ('primitive-control', 'advisory-primitive', 'xact-release', (1,)),
    ('primitive-control', 'advisory-primitive', 'try-lock-nowait', CLOSED_N),
    ('primitive-control', 'advisory-primitive', 'blocking-wait', (1, 8, 32)),
    ('primitive-control', 'pgbouncer-queue', 'pool-queue', CLOSED_N),
    ('primitive-control', 'pgbouncer-queue', 'pool-timeout', (200,)),
    ('dirty-cardinality-probe', 'sweeper-overlap', 'dirty-20k', (1,)),
    ('dirty-cardinality-probe', 'sweeper-overlap', 'dirty-1m', (1,)),
)


class EvidenceInvalid(Exception):
    """A sample, counter, or identity is missing or inconsistent."""


def cell_id(plane: str, surface: str, shape: str, n: int) -> str:
    return f'{plane}.{surface}.{shape}.n{n}'


def catalog() -> list[dict]:
    cells = []
    for plane, surface, shape, sizes in _CELL_SPECS:
        for n in sizes:
            boundary = n == BOUNDARY_N
            cells.append({
                'cell_id': cell_id(plane, surface, shape, n),
                'plane': plane,
                'surface': surface,
                'shape': shape,
                'n': n,
                'boundary_control': boundary,
                'supported_workload': not boundary,
            })
    return cells


def parse_env(path: Path) -> dict[str, str]:
    values = {}
    for raw in path.read_text(encoding='utf-8').splitlines():
        line = raw.strip()
        if not line or line.startswith('#') or '=' not in line:
            continue
        key, value = line.split('=', 1)
        values[key.strip()] = value.strip().strip('"').strip("'")
    return values


def assert_tier_s_axis(env: dict[str, str]) -> None:
    pool = int(env['PGBOUNCER_DEFAULT_POOL_SIZE'])
    clients = int(env['PGBOUNCER_MAX_CLIENT_CONN'])
    derived = (1, pool, pool * 4, clients, clients + 1)
    if derived != CLOSED_N:
        raise EvidenceInvalid(
            f'evidence-invalid: tier S axis {derived} != closed N {CLOSED_N}'
        )


def axes_text(tier: str, env: dict[str, str]) -> str:
    if tier == 'S':
        assert_tier_s_axis(env)
    pool = env.get('PGBOUNCER_DEFAULT_POOL_SIZE', '')
    clients = env.get('PGBOUNCER_MAX_CLIENT_CONN', '')
    lines = [
        'plane=' + ','.join(PLANES),
        'surface=' + ','.join(SURFACES),
        'shape=' + ','.join(SHAPES),
        'N=' + ','.join(str(n) for n in CLOSED_N),
        'boundary_control=201',
        'supported_workload_n=1,8,32,200',
        f'poll_interval_ms={POLL_INTERVAL_MS}',
        f'cells={len(catalog())}',
        f'tier={tier}',
        f'limits.default_pool_size={pool}',
        f'limits.max_client_conn={clients}',
        'derivation=1,default_pool_size,4*default_pool_size,max_client_conn,max_client_conn+1',
        'n201=boundary-control',
    ]
    return '\n'.join(lines) + '\n'


def control_sql(shape: str) -> str:
    """Transaction-scoped advisory SQL. Session locks are not emitted."""
    if shape == 'try-lock-nowait':
        body = 'SELECT pg_try_advisory_xact_lock(42001);'
    elif shape == 'blocking-wait':
        body = 'SELECT pg_advisory_xact_lock(42001);'
    elif shape == 'xact-release':
        body = 'SELECT pg_advisory_xact_lock(42001);'
    elif shape in ('pool-queue', 'pool-timeout'):
        body = 'SELECT pg_advisory_xact_lock(42002);'
    else:
        raise EvidenceInvalid(f'evidence-invalid: no primitive SQL for shape {shape}')
    return (
        'BEGIN;\n'
        f'{body}\n'
        'SELECT pg_backend_pid();\n'
        'ROLLBACK;\n'
    )


def _fail(message: str) -> None:
    raise EvidenceInvalid(f'evidence-invalid: {message}')


def _load_json(path: Path):
    try:
        return json.loads(path.read_text(encoding='utf-8'))
    except (OSError, UnicodeError, json.JSONDecodeError) as exc:
        _fail(f'{path.name} is not readable JSON ({exc})')


def _load_jsonl(path: Path) -> list[dict]:
    try:
        raw = path.read_bytes()
    except OSError as exc:
        _fail(f'{path.name} is missing ({exc})')
    if not raw.endswith(b'\n'):
        _fail(f'truncated JSONL {path.name}')
    rows = []
    text = raw.decode('utf-8')
    for line in text.splitlines():
        if not line.strip():
            _fail(f'truncated JSONL {path.name}')
        try:
            row = json.loads(line)
        except json.JSONDecodeError as exc:
            _fail(f'truncated JSONL {path.name} ({exc})')
        if not isinstance(row, dict):
            _fail(f'{path.name} row is not an object')
        rows.append(row)
    return rows


def _require_counter(side: dict, name: str) -> int:
    if name not in side:
        _fail(f'missing pre/post counters ({name})')
    value = side[name]
    if isinstance(value, bool) or not isinstance(value, int) or value < 0:
        _fail(f'missing pre/post counters ({name})')
    return value


def _require_cursor(side: dict) -> str:
    if 'log_cursor' not in side or not isinstance(side['log_cursor'], str) or side['log_cursor'] == '':
        _fail('missing log cursor')
    return side['log_cursor']


def _check_identity(row: dict, manifest_run: str, known_cells: set[str]) -> None:
    if row.get('run_id') != manifest_run:
        _fail('cross-run sample')
    if row.get('plane') not in PLANES:
        _fail('untyped plane')
    if row.get('cell_id') not in known_cells:
        _fail(f'unknown cell {row.get("cell_id")}')
    for key in ('attempt', 'snapshot_id', 'monotonic_ns', 'source', 'client'):
        if key not in row:
            _fail(f'observation missing {key}')
    if not isinstance(row['monotonic_ns'], int):
        _fail('non-monotonic time')
    if row['source'] not in REQUIRED_SOURCES and row['source'] != 'actor':
        _fail(f'unknown observation source {row["source"]}')
    if 'query' in row or 'password' in row or 'dsn' in row:
        _fail('redaction failed')
    lock_fn = row.get('lock_fn')
    if lock_fn is not None:
        if lock_fn in SESSION_LOCKS or lock_fn not in XACT_LOCKS:
            _fail('session lock is forbidden')


def _check_blocker(row: dict, backend_pids: set[int]) -> None:
    if row.get('source') != 'pg_blocking_pids':
        return
    if row.get('diagnostic_only') is True:
        _fail('pg_blocking_pids is not diagnostic')
    blockers = row.get('blocker_pids')
    if not isinstance(blockers, list):
        _fail('impossible blocker PID')
    waiter = row.get('pid')
    for pid in blockers:
        if isinstance(pid, bool) or not isinstance(pid, int) or pid < 1:
            _fail('impossible blocker PID')
        if waiter is not None and pid == waiter:
            _fail('impossible blocker PID')
        if backend_pids and pid not in backend_pids:
            _fail('impossible blocker PID')


def _check_pool(row: dict) -> None:
    if row.get('source') == 'pgbouncer_pools':
        for field in POOL_FIELDS:
            if field not in row:
                _fail(f'missing PgBouncer field {field}')
    if row.get('source') == 'pgbouncer_stats' and 'avg_wait_time' not in row:
        _fail('missing PgBouncer field avg_wait_time')


def validate_participation(row, n):
    p = row.get('participation') or {}
    expected = p.get('expected_actor_ids', [])
    responses = row.get('http_responses', [])
    observed = sorted({r.get('actor_id') for r in responses if r.get('phase') != 'setup' and r.get('actor_id') is not None})
    if (len(expected) != n or len(set(expected)) != n or observed != sorted(expected)
        or row.get('actor_ids') != observed or p.get('observed_actor_ids') != observed
        or sorted(p.get('started', [])) != sorted(expected)
        or sorted(p.get('completed', [])) != sorted(expected)
        or p.get('aborted') != [] or p.get('iterations') != n):
        raise EvidenceInvalid('declared N does not match observed completed actors')
    for actor in expected:
        actor_rows = [r for r in responses if r.get('actor_id') == actor]
        if not any(r.get('phase') == 'operation' for r in actor_rows) and not any(
            r.get('phase') == 'create' and r.get('status') != 201 for r in actor_rows
        ):
            raise EvidenceInvalid('actor completed without operation or explicit create failure')


def validate_overlap(row):
    o = row.get('overlap') or {}
    if (not o.get('upload_id') or not o.get('backend_pid') or o.get('sweep_exit') != 0
        or not o.get('target_refused') or not o.get('sweepable') or not o.get('lock_observed') or not o.get('lock_after_sweep')
        or not (o.get('held_ns', 0) < o.get('sweep_start_ns', 0)
                < o.get('sweep_end_ns', 0) < o.get('release_ns', 0))
        or not o.get('final_state') or not o.get('retry_response')):
        raise EvidenceInvalid('missing concurrent sweep/PATCH evidence')


def verify_run_dir(path: Path) -> None:
    path = Path(path)
    manifest = _load_json(path / 'run-manifest.json')
    if not isinstance(manifest, dict) or not manifest.get('run_id'):
        _fail('run manifest missing run_id')
    run_id = manifest['run_id']
    cells = _load_json(path / 'cells.json')
    if not isinstance(cells, list) or not cells:
        _fail('cells.json is empty')
    seen = set()
    by_id = {}
    for cell in cells:
        if not isinstance(cell, dict):
            _fail('cell is not an object')
        cid = cell.get('cell_id')
        if not isinstance(cid, str) or cid in seen:
            _fail('duplicate cell id')
        seen.add(cid)
        by_id[cid] = cell
        if cell.get('run_id') != run_id:
            _fail('cross-run sample')
        if cell.get('plane') not in PLANES:
            _fail('untyped plane')
        if cell.get('surface') not in SURFACES or cell.get('shape') not in SHAPES:
            _fail('untyped surface or shape')
        n = cell.get('n')
        if n not in CLOSED_N:
            _fail(f'N {n} is outside the closed axis')
        boundary = n == BOUNDARY_N
        if cell.get('boundary_control') is not boundary:
            _fail('N=201 boundary flag mismatch')
        if cell.get('supported_workload') is boundary:
            _fail('N=201 is not a supported workload')
        admitted = any(item['cell_id'] == cid for item in catalog())
        if not admitted:
            _fail(f'cell {cid} is outside the admitted catalog')
        if manifest.get('capture_contract') == 'r05-b07/v2' and cell['plane'] in {'product-realistic', 'product-worst-case'}:
            validate_participation(cell, n)
            if cell['shape'] == 'live-patch-overlap':
                validate_overlap(cell)
        for side_name in ('pre', 'post'):
            side = cell.get(side_name)
            if not isinstance(side, dict):
                _fail('missing pre/post counters')
            _require_counter(side, 'deadlocks')
            _require_cursor(side)
            if not isinstance(side.get('monotonic_ns'), int):
                _fail('non-monotonic time')
        if cell['post']['monotonic_ns'] <= cell['pre']['monotonic_ns']:
            _fail('non-monotonic time')
        waits = cell.get('waits')
        if not isinstance(waits, dict):
            _fail('waits are missing')
        for field in (
            'db_lock_wait_seconds',
            'pgbouncer_wait_seconds',
            'http_duration_seconds',
            'barrier_skew_seconds',
        ):
            if field not in waits:
                _fail(f'wait field {field} is missing')
        if cell['plane'] == 'primitive-control' and waits['http_duration_seconds'] is not None:
            _fail('primitive-control must not claim an HTTP duration')
        nowait = cell.get('nowait')
        if cell['shape'] == 'try-lock-nowait':
            if not isinstance(nowait, dict):
                _fail('NOWAIT refusal is missing')
            if nowait.get('source') != 'actor-status':
                _fail('NOWAIT refusal must come from actor status')
            attempts = nowait.get('attempt_count')
            refused = nowait.get('refused_count')
            if not isinstance(attempts, int) or attempts != n:
                _fail('NOWAIT attempt count must equal N')
            if not isinstance(refused, int) or refused < 0 or refused > attempts:
                _fail('NOWAIT refused count is inconsistent')

    observations = _load_jsonl(path / 'observations.jsonl')
    last_ns: dict[str, int] = {}
    sources: dict[str, set[str]] = {cid: set() for cid in by_id}
    for row in observations:
        _check_identity(row, run_id, seen)
        cid = row['cell_id']
        cell = by_id[cid]
        ns = row['monotonic_ns']
        if ns <= cell['pre']['monotonic_ns'] or ns >= cell['post']['monotonic_ns']:
            _fail('non-monotonic time')
        previous = last_ns.get(cid)
        if previous is not None and ns <= previous:
            _fail('non-monotonic time')
        last_ns[cid] = ns
        if row.get('attempt') != cell.get('attempt'):
            _fail('attempt mismatch')
        sources[cid].add(row['source'])
        backend = cell.get('backend_pids') or []
        if not isinstance(backend, list):
            _fail('impossible blocker PID')
        _check_blocker(row, {pid for pid in backend if isinstance(pid, int)})
        _check_pool(row)
        if row.get('source') == 'barrier':
            skew = row.get('barrier_skew_ns')
            if not isinstance(skew, int) or skew < 0:
                _fail('barrier skew is missing')
    for cid, have in sources.items():
        missing = [name for name in REQUIRED_SOURCES if name not in have]
        if missing:
            _fail('missing sample ' + ','.join(missing))


def filesystem_barrier(n: int, directory: Path) -> dict:
    """Start n actors together. Skew is max-min ready time after the release."""
    if n not in CLOSED_N:
        _fail(f'N {n} is outside the closed axis')
    directory.mkdir(parents=True, exist_ok=True)
    start = directory / 'start'
    ready = directory / 'ready'
    ready.mkdir(exist_ok=True)
    import subprocess
    script = (
        'import pathlib,sys,time\n'
        'root,slot=sys.argv[1],sys.argv[2]\n'
        'start=pathlib.Path(root)/"start"\n'
        'while not start.exists():\n'
        '    time.sleep(0.001)\n'
        'mark=pathlib.Path(root)/"ready"/slot\n'
        'mark.write_text(str(time.monotonic_ns()))\n'
    )
    procs = []
    for index in range(n):
        procs.append(subprocess.Popen(
            [sys.executable, '-c', script, str(directory), str(index)],
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
        ))
    deadline = time.monotonic() + 10
    while time.monotonic() < deadline and any(proc.poll() is None for proc in procs):
        # Actors block until start exists; give them time to reach the wait.
        if all(proc.poll() is None for proc in procs):
            break
        time.sleep(0.01)
    released = time.monotonic_ns()
    start.write_text('go\n', encoding='utf-8')
    for proc in procs:
        code = proc.wait(timeout=10)
        if code != 0:
            _fail(f'barrier actor exited {code}')
    stamps = []
    for index in range(n):
        stamps.append(int((ready / str(index)).read_text(encoding='utf-8')))
    skew = max(stamps) - min(stamps)
    return {'n': n, 'released_ns': released, 'barrier_skew_ns': skew, 'actors': n}


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description='P5.1 concurrency evidence gate')
    sub = parser.add_subparsers(dest='command', required=True)
    axes = sub.add_parser('axes')
    axes.add_argument('--tier', required=True)
    axes.add_argument('--env', required=True)
    sub.add_parser('catalog')
    verify = sub.add_parser('verify')
    verify.add_argument('--run-dir', required=True)
    sql = sub.add_parser('print-sql')
    sql.add_argument('--shape', required=True)
    barrier = sub.add_parser('barrier')
    barrier.add_argument('--n', type=int, required=True)
    barrier.add_argument('--out', required=True)
    args = parser.parse_args(argv)
    try:
        if args.command == 'axes':
            env = parse_env(Path(args.env))
            sys.stdout.write(axes_text(args.tier, env))
        elif args.command == 'catalog':
            for cell in catalog():
                flag = 'boundary' if cell['boundary_control'] else 'workload'
                sys.stdout.write(
                    f"{cell['cell_id']} {flag}\n"
                )
        elif args.command == 'verify':
            verify_run_dir(Path(args.run_dir))
            sys.stdout.write('p5-concurrency verify: ok\n')
        elif args.command == 'print-sql':
            sys.stdout.write(control_sql(args.shape))
        elif args.command == 'barrier':
            report = filesystem_barrier(args.n, Path(args.out))
            sys.stdout.write(json.dumps(report, sort_keys=True) + '\n')
    except (EvidenceInvalid, KeyError, ValueError, OSError) as exc:
        sys.stderr.write(str(exc) + '\n')
        return 1
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
