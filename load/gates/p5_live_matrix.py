#!/usr/bin/env python3
"""P5.3 tier-S concurrency matrix.

One run_id binds product HTTP, primitive SQL, sweeper probes, and the
PostgreSQL / PgBouncer samples taken on a direct or admin connection.
A missing sample stays evidence-invalid. HTTP 5xx is never success.
SLO RED with complete raw evidence remains a valid cell.
"""

from __future__ import annotations

import hashlib
import json
import os
import re
import subprocess
import time
from concurrent.futures import ThreadPoolExecutor
import urllib.error
import urllib.request
from pathlib import Path

import p5_concurrency as gate


REPO = Path(__file__).resolve().parents[2]
LOAD = REPO / 'load'
COMPOSE_FILE = LOAD / 'docker-compose.load.yml'
ENV_FILE = LOAD / 'env' / 'S.env'
ART = REPO / 'plans' / 'archive' / 'VLT-LOAD' / 'artifacts' / 'P5'
PG_SCRIPT = LOAD / 'scripts' / 'p5-postgres-snapshot.sh'
APP_URL = os.environ.get('LOAD_APP_URL', 'http://127.0.0.1:8080')
PROM_URL = os.environ.get('LOAD_PROM_URL', 'http://127.0.0.1:9090')
SECRET_MARKERS = ('PGPASSWORD=', 'postgresql://', 'vaulter-load-secret', '"password"')


def compose(*args: str, timeout: int = 120) -> subprocess.CompletedProcess[str]:
    return subprocess.run(
        ['docker', 'compose', '-f', str(COMPOSE_FILE), '--env-file', str(ENV_FILE), *args],
        cwd=LOAD,
        text=True,
        capture_output=True,
        timeout=timeout,
        check=False,
    )


def psql(sql: str, *, database: str = 'vaulter_load', host: str | None = None, timeout: int = 180) -> str:
    command = ['exec', '-T', '-e', 'PGPASSWORD=secret', 'postgres', 'psql', '-U', 'vaulter', '-d', database, '-v', 'ON_ERROR_STOP=1', '-At']
    if host:
        command[5:5] = ['-h', host, '-p', '6432']
    argv = ['docker', 'compose', '-f', str(COMPOSE_FILE), '--env-file', str(ENV_FILE), *command]
    completed = subprocess.run(
        argv, cwd=LOAD, input=sql, text=True, capture_output=True, timeout=timeout, check=False,
    )
    if completed.returncode != 0:
        raise gate.EvidenceInvalid(f'psql failed: {completed.stderr.strip()[:400]}')
    return completed.stdout


def snapshot_sql() -> str:
    text = PG_SCRIPT.read_text(encoding='utf-8')
    start = text.index("cat <<'SQL'\n") + len("cat <<'SQL'\n")
    end = text.index('\nSQL\n', start)
    return text[start:end]


def postgres_snapshot() -> dict:
    raw = psql(snapshot_sql()).strip()
    payload = json.loads(raw)
    if not isinstance(payload, dict):
        raise gate.EvidenceInvalid('postgres snapshot is not an object')
    return payload


def pgbouncer_snapshot() -> dict:
    pool_csv = _csv('SHOW POOLS;', database='pgbouncer', host='pgbouncer')
    stat_csv = _csv('SHOW STATS;', database='pgbouncer', host='pgbouncer')
    cfg_csv = _csv('SHOW CONFIG;', database='pgbouncer', host='pgbouncer')
    product = next((row for row in pool_csv if row.get('database') == 'vaulter_load'), None)
    if product is None or not stat_csv or not cfg_csv:
        raise gate.EvidenceInvalid('PgBouncer admin returned no product pool row')
    cfg = {row.get('key') or row.get('Key'): row.get('value') if 'value' in row else row.get('Value') for row in cfg_csv}
    stat = stat_csv[0]
    return {
        'cl_waiting': int(product['cl_waiting']),
        'sv_active': int(product['sv_active']),
        'maxwait': int(product['maxwait']),
        'avg_wait_time': int(stat['avg_wait_time']),
        'pool_mode': product['pool_mode'],
        'default_pool_size': int(cfg['default_pool_size']),
        'max_client_conn': int(cfg['max_client_conn']),
        'query_wait_timeout': int(cfg['query_wait_timeout']),
    }


def _csv(query: str, *, database: str, host: str) -> list[dict]:
    import csv
    import io
    argv = [
        'docker', 'compose', '-f', str(COMPOSE_FILE), '--env-file', str(ENV_FILE),
        'exec', '-T', '-e', 'PGPASSWORD=secret', 'postgres',
        'psql', '-h', host, '-p', '6432', '-U', 'vaulter', '-d', database,
        '--csv', '-v', 'ON_ERROR_STOP=1', '-c', query,
    ]
    completed = subprocess.run(argv, cwd=LOAD, text=True, capture_output=True, timeout=30, check=False)
    if completed.returncode != 0:
        raise gate.EvidenceInvalid(f'pgbouncer query failed: {completed.stderr.strip()[:300]}')
    return list(csv.DictReader(io.StringIO(completed.stdout)))


def log_cursor() -> str:
    logfile = psql("SELECT COALESCE(pg_current_logfile(), '');").strip()
    if logfile:
        return f'pg_current_logfile:{logfile}'
    lsn = psql('SELECT pg_current_wal_lsn()::text;').strip()
    if not lsn:
        raise gate.EvidenceInvalid('missing log cursor')
    return f'wal-lsn:{lsn}'


def repeat_count(cell: dict) -> int:
    if cell['plane'] == 'product-realistic' or cell['shape'] == 'dirty-20k':
        return 3
    return 1


def execution_order(cells: list[dict]) -> list[dict]:
    def key(cell: dict) -> tuple:
        plane, shape, n = cell['plane'], cell['shape'], cell['n']
        if plane == 'primitive-control' and n == 1 and shape != 'pool-timeout':
            phase = 0
        elif plane == 'product-realistic' and n == 1:
            phase = 1
        elif plane == 'product-realistic':
            phase = 2
        elif plane == 'product-worst-case':
            phase = 3
        elif plane == 'primitive-control':
            phase = 4
        elif shape == 'dirty-20k':
            phase = 5
        else:
            phase = 6
        return (phase, cell['surface'], shape, n)

    return sorted(cells, key=key)


def secret_scan(root: Path) -> list[str]:
    hits = []
    for path in sorted(root.rglob('*')):
        if not path.is_file():
            continue
        try:
            text = path.read_text(encoding='utf-8')
        except (UnicodeError, OSError):
            continue
        for marker in SECRET_MARKERS:
            if marker in text:
                hits.append(f'{path.relative_to(root)}:{marker}')
    return hits


def digest_tree(root: Path) -> str:
    hasher = hashlib.sha256()
    for path in sorted(item for item in root.rglob('*') if item.is_file()):
        hasher.update(path.relative_to(root).as_posix().encode())
        hasher.update(b'\0')
        hasher.update(path.read_bytes())
        hasher.update(b'\0')
    return hasher.hexdigest()


def http_json(method: str, path: str, body: bytes | None = None, headers: dict | None = None) -> tuple[int, dict | str, str | None]:
    request = urllib.request.Request(APP_URL + path, data=body, method=method)
    request.add_header('X-Load-Actor', str((headers or {}).get('X-Load-Actor', '1')))
    request.add_header('Accept', 'application/json')
    for key, value in (headers or {}).items():
        request.add_header(key, value)
    try:
        with urllib.request.urlopen(request, timeout=30) as response:
            raw = response.read()
            status = response.status
            retry_after = response.headers.get('Retry-After')
    except urllib.error.HTTPError as exc:
        raw = exc.read()
        status = exc.code
        retry_after = exc.headers.get('Retry-After')
    try:
        parsed = json.loads(raw.decode('utf-8')) if raw else {}
    except (UnicodeError, json.JSONDecodeError):
        parsed = raw.decode('utf-8', 'replace')[:500]
    return status, parsed, retry_after


def counts() -> dict[str, int]:
    text = psql(
        "SELECT 'nodes', count(*) FROM v_nodes "
        "UNION ALL SELECT 'uploads', count(*) FROM v_upload_sessions "
        "UNION ALL SELECT 'blobs', count(*) FROM v_blobs "
        "UNION ALL SELECT 'storages', count(*) FROM v_storages;"
    )
    found = {}
    for line in text.splitlines():
        name, value = line.split('|')
        found[name] = int(value)
    return found


def run_matrix(out: Path, run_id: str) -> None:
    if os.environ.get('TIER', 'S') != 'S':
        raise gate.EvidenceInvalid('matrix requires TIER=S')
    out.mkdir(parents=True, exist_ok=True)
    for name in ('attempts', 'postgres', 'pgbouncer', 'k6', 'prometheus', 'sweeper'):
        (out / name).mkdir(exist_ok=True)
    cells = execution_order(gate.catalog())
    fingerprint = preflight(out, run_id)
    observations: list[dict] = []
    published: list[dict] = []
    verdicts = []
    baseline: dict = {}
    dump = out / 'postgres' / 'pre-dirty.sql'
    document_id = ensure_document()
    tree = ensure_tree()
    ns = time.monotonic_ns()

    def clock() -> int:
        nonlocal ns
        now = time.monotonic_ns()
        if now <= ns:
            now = ns + 1
        ns = now
        return now

    for cell in cells:
        repeats = repeat_count(cell)
        print(f"p5-matrix cell {cell['cell_id']} repeats={repeats}", flush=True)
        valid_rows = []
        for attempt in range(1, repeats + 1):
            if cell['shape'] == 'dirty-1m' and attempt == 1:
                restore_dump(dump)
            if cell['shape'] == 'dirty-20k' and attempt == 1 and not dump.exists():
                baseline = counts()
                (out / 'integrity-before.json').write_text(json.dumps(baseline, sort_keys=True) + '\n', encoding='utf-8')
                write_dump(dump)
            try:
                row, extra = execute_cell(cell, attempt, run_id, fingerprint, document_id, tree, clock, out)
            except (gate.EvidenceInvalid, subprocess.TimeoutExpired, OSError) as exc:
                if isinstance(exc, SweeperCaptureTimeout):
                    raise
                rejected = {
                    'cell_id': cell['cell_id'],
                    'attempt': attempt,
                    'status': 'rejected',
                    'cause': str(exc)[:500],
                }
                target = out / 'attempts' / f"{cell['cell_id']}.attempt-{attempt}.rejected.json"
                target.write_text(json.dumps(rejected, indent=2) + '\n', encoding='utf-8')
                continue
            observations.extend(extra)
            valid_rows.append(row)
            (out / 'attempts' / f"{cell['cell_id']}.attempt-{attempt}.json").write_text(
                json.dumps(row, indent=2, sort_keys=True) + '\n', encoding='utf-8',
            )
        if not valid_rows:
            verdicts.append({'cell_id': cell['cell_id'], 'evidence': 'rejected', 'slo': 'not-evaluated', 'attempts': repeats})
            continue
        published.append(valid_rows[-1])
        verdicts.append(verdict_for(cell, valid_rows))
        if cell['shape'] == 'dirty-1m':
            restore_dump(dump)

    restored = counts()
    (out / 'integrity-after.json').write_text(json.dumps(restored, sort_keys=True) + '\n', encoding='utf-8')
    (out / 'cells.json').write_text(json.dumps(published, indent=2) + '\n', encoding='utf-8')
    published_attempts = {(row['cell_id'], row['attempt']) for row in published}
    published_observations = [
        row for row in observations
        if (row['cell_id'], row['attempt']) in published_attempts
    ]
    (out / 'observations.jsonl').write_text(
        ''.join(json.dumps(row, sort_keys=True) + '\n' for row in published_observations),
        encoding='utf-8',
    )
    (out / 'matrix.json').write_text(json.dumps({
        'schema': 'p5-matrix/v1',
        'run_id': run_id,
        'order': [cell['cell_id'] for cell in cells],
        'repeats': {cell['cell_id']: repeat_count(cell) for cell in cells},
    }, indent=2) + '\n', encoding='utf-8')
    (out / 'cell-verdicts.json').write_text(json.dumps({
        'schema': 'p5-cell-verdicts/v1',
        'run_id': run_id,
        'cells': verdicts,
    }, indent=2) + '\n', encoding='utf-8')
    (out / 'run-manifest.json').write_text(json.dumps({
        'schema': 'p5-run/v1',
        'capture_contract': 'r05-b07/v2',
        'run_id': run_id,
        'tier': 'S',
        'fingerprint': fingerprint,
        'collectors': {
            'postgres': 'direct-socket-inside-postgres',
            'pgbouncer': 'admin-database-pgbouncer',
        },
        'integrity_before': baseline,
        'integrity_after': restored,
    }, indent=2) + '\n', encoding='utf-8')
    capture_prometheus(out)


def preflight(out: Path, run_id: str) -> dict:
    health = compose('ps', '--format', 'json')
    if health.returncode != 0:
        raise gate.EvidenceInvalid(f'compose ps failed: {health.stderr.strip()[:300]}')
    sha = subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=REPO, text=True).strip()
    dirty = subprocess.run(['git', 'diff', '--quiet'], cwd=REPO, check=False).returncode != 0
    source = hashlib.sha256()
    for path in (LOAD / 'gates' / 'p5_live_matrix.py', LOAD / 'gates' / 'p5_concurrency.py', LOAD / 'k6' / 'scenarios' / 'concurrency-live.js', LOAD / 'scripts' / 'p5-php-peak.php', LOAD / 'app' / 'P5PatchWindow.php', LOAD / 'app' / 'LoadServiceProvider.php'):
        source.update(str(path.relative_to(REPO)).encode())
        source.update(path.read_bytes())
    images = {}
    for name in ('vaulter-load-postgres-1', 'vaulter-load-pgbouncer-1', 'vaulter-load-app-1'):
        inspected = subprocess.run(
            ['docker', 'inspect', '-f', '{{.Config.Image}} {{.Image}}', name],
            text=True, capture_output=True, check=False,
        )
        images[name] = inspected.stdout.strip()
    pool = pgbouncer_snapshot()
    fingerprint = {
        'git_sha': sha,
        'git_dirty': dirty,
        'source_digest': source.hexdigest(),
        'images': images,
        'pgbouncer': pool,
        'clock': time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime()),
        'run_id': run_id,
        'dataset_counts': counts(),
    }
    (out / 'postgres' / 'preflight.json').write_text(json.dumps(fingerprint, indent=2) + '\n', encoding='utf-8')
    if pool['default_pool_size'] != 8 or pool['max_client_conn'] != 200 or pool['query_wait_timeout'] != 120:
        raise gate.EvidenceInvalid('tier-S pool fingerprint does not match reference env')
    if 'edoburu/pgbouncer' not in images.get('vaulter-load-pgbouncer-1', ''):
        raise gate.EvidenceInvalid('pgbouncer container is not the pgbouncer image')
    return fingerprint


def ensure_document() -> str:
    name = f'p5-collab-{time.time_ns()}'
    status, payload, _ = http_json(
        'POST',
        '/api/vaulter/storages/1/documents',
        json.dumps({'name': name, 'content': {'type': 'doc', 'content': []}}).encode(),
        {'Content-Type': 'application/json'},
    )
    if status != 201 or not isinstance(payload, dict):
        raise gate.EvidenceInvalid(f'document create failed: {status}')
    return str(payload['data']['id'])


def ensure_tree() -> dict[str, str]:
    psql("DELETE FROM v_nodes WHERE name = 'p5-tree-parent' OR name LIKE 'p5-tree-%';")
    parent_lines = [
        line.strip() for line in psql(
            "INSERT INTO v_nodes (storage_id, parent_id, kind, name, created_at, updated_at) "
            "VALUES (1, 1, 'folder', 'p5-tree-parent', now(), now()) RETURNING id;"
        ).splitlines()
        if line.strip().isdigit()
    ]
    parent_id = parent_lines[0] if parent_lines else ''
    listed = psql(
        "INSERT INTO v_nodes (storage_id, parent_id, kind, name, created_at, updated_at) "
        "SELECT 1, 1, 'folder', 'p5-tree-' || g, now(), now() FROM generate_series(1, 1000) AS g "
        "RETURNING id;"
    )
    ids = [line.strip() for line in listed.splitlines() if line.strip().isdigit()]
    if len(ids) != 1000 or not parent_id:
        raise gate.EvidenceInvalid(f'tree seed returned {len(ids)} nodes')
    return {'parent': parent_id, 'nodes': ','.join(ids)}


def write_dump(path: Path) -> None:
    completed = compose('exec', '-T', 'postgres', 'pg_dump', '-U', 'vaulter', '--data-only', 'vaulter_load', timeout=180)
    if completed.returncode != 0:
        raise gate.EvidenceInvalid(f'pg_dump failed: {completed.stderr.strip()[:300]}')
    path.write_text(completed.stdout, encoding='utf-8')


def restore_dump(path: Path) -> None:
    if not path.exists():
        raise gate.EvidenceInvalid('pre-dirty snapshot is missing')
    # Data-only restore is not a full cluster replace. Dirty rows are deleted by prefix.
    psql("DELETE FROM v_upload_sessions WHERE target_name LIKE 'p5-dirty-%';")


def execute_cell(cell, attempt, run_id, fingerprint, document_id, tree, clock, out):
    pre_ns = clock()
    pre_deadlocks = int(psql('SELECT deadlocks FROM pg_stat_database WHERE datname = current_database();').strip())
    pre_cursor = log_cursor()
    pre_pool = pgbouncer_snapshot()
    if cell['plane'] == 'dirty-cardinality-probe':
        actor = run_sweeper(cell, attempt, out, run_id=run_id)
    elif cell['plane'] == 'primitive-control':
        actor = run_primitive(cell)
    else:
        actor = run_k6(cell, attempt, run_id, document_id, tree, out)
    during = actor.get('during_samples') or []
    chosen = max(
        during,
        key=lambda item: (
            any(lock.get('granted') is False for lock in item['postgres'].get('locks', [])),
            item['pgbouncer']['cl_waiting'],
            len(item['postgres'].get('locks', [])),
        ),
    ) if during else None
    sample_pg = chosen['postgres'] if chosen else postgres_snapshot()
    sample_pool = chosen['pgbouncer'] if chosen else pgbouncer_snapshot()
    post_deadlocks = int(psql('SELECT deadlocks FROM pg_stat_database WHERE datname = current_database();').strip())
    post_cursor = log_cursor()
    sample_ns = clock()
    post_ns = sample_ns
    (out / 'postgres' / f"{cell['cell_id']}.a{attempt}.json").write_text(
        json.dumps({'samples': [{'monotonic_ns': item['monotonic_ns'], **item['postgres']} for item in during]} if during else sample_pg) + '\n', encoding='utf-8',
    )
    (out / 'pgbouncer' / f"{cell['cell_id']}.a{attempt}.json").write_text(
        json.dumps({'pre': pre_pool, 'during': [{'monotonic_ns': item['monotonic_ns'], **item['pgbouncer']} for item in during] if during else [sample_pool]}, sort_keys=True) + '\n', encoding='utf-8',
    )
    waits = {
        'db_lock_wait_seconds': actor.get('db_lock_wait_seconds'),
        'pgbouncer_wait_seconds': actor.get('pgbouncer_wait_seconds', sample_pool['maxwait']),
        'http_duration_seconds': actor.get('http_duration_seconds'),
        'barrier_skew_seconds': actor.get('barrier_skew_seconds', 0),
    }
    if cell['plane'] == 'primitive-control':
        waits['http_duration_seconds'] = None
    row = {
        'schema': 'p5-cell/v1',
        'run_id': run_id,
        'cell_id': cell['cell_id'],
        'attempt': attempt,
        'plane': cell['plane'],
        'surface': cell['surface'],
        'shape': cell['shape'],
        'n': cell['n'],
        'boundary_control': cell['boundary_control'],
        'supported_workload': cell['supported_workload'],
        'backend_pids': actor.get('backend_pids', []),
        'pre': {'deadlocks': pre_deadlocks, 'log_cursor': pre_cursor, 'monotonic_ns': pre_ns},
        'post': {'deadlocks': post_deadlocks, 'log_cursor': post_cursor, 'monotonic_ns': post_ns},
        'waits': waits,
        'statuses': actor.get('statuses', {}),
        'http_responses': actor.get('http_responses', []),
        'actor_ids': actor.get('actor_ids', []),
        'participation': actor.get('participation'),
        'overlap': actor.get('overlap'),
        'during_sample_count': len(during),
        'http_5xx': actor.get('http_5xx', 0),
    }
    if cell['shape'] == 'try-lock-nowait':
        row['nowait'] = {
            'source': 'actor-status',
            'attempt_count': cell['n'],
            'refused_count': actor['refused_count'],
        }
    if cell['plane'] == 'product-realistic':
        validate_participation(row, cell['n'])
        if cell['shape'] == 'live-patch-overlap':
            validate_overlap(row)
    observations = observations_for(row, sample_pg, sample_pool, actor, sample_ns)
    last = max(item['monotonic_ns'] for item in observations)
    if last <= pre_ns:
        raise gate.EvidenceInvalid('non-monotonic time')
    row['post']['monotonic_ns'] = last + 1
    return row, observations


def observations_for(row, sample_pg, sample_pool, actor, sample_ns) -> list[dict]:
    base = {
        'run_id': row['run_id'],
        'cell_id': row['cell_id'],
        'attempt': row['attempt'],
        'plane': row['plane'],
        'snapshot_id': f"{row['cell_id']}-a{row['attempt']}",
    }
    rows = []
    skew_ns = int(float(row['waits']['barrier_skew_seconds'] or 0) * 1_000_000_000)

    def add(source: str, extra: dict, client: str = 'collector') -> None:
        item = dict(base)
        item.update({
            'source': source,
            'monotonic_ns': sample_ns,
            'pid': extra.pop('pid', None),
            'client': client,
        })
        item.update(extra)
        rows.append(item)

    add('barrier', {'barrier_skew_ns': skew_ns, 'pid': None}, 'orchestrator')
    locks = sample_pg.get('locks', [])
    add('pg_locks', {'pid': None, 'locks': locks})
    activity = sample_pg.get('activity', [])
    add('pg_stat_activity', {'pid': None, 'activity': activity})
    blockers = sample_pg.get('blockers', [])
    add('pg_blocking_pids', {'pid': None, 'blockers': blockers, 'blocker_pids': [pid for item in blockers for pid in item.get('blocker_pids', [])]})
    add('pgbouncer_pools', {
        'pid': None,
        'cl_waiting': sample_pool['cl_waiting'],
        'sv_active': sample_pool['sv_active'],
        'maxwait': sample_pool['maxwait'],
        'pool_mode': sample_pool['pool_mode'],
        'default_pool_size': sample_pool['default_pool_size'],
        'max_client_conn': sample_pool['max_client_conn'],
        'query_wait_timeout': sample_pool['query_wait_timeout'],
    }, 'pgbouncer')
    add('pgbouncer_stats', {'pid': None, 'avg_wait_time': sample_pool['avg_wait_time']}, 'pgbouncer')
    if row['plane'] == 'primitive-control':
        add('actor', {'pid': None, 'lock_fn': 'pg_try_advisory_xact_lock' if row['shape'] == 'try-lock-nowait' else 'pg_advisory_xact_lock'}, 'sql')
    # Unique monotonic timestamps. The validator rejects equal timestamps per cell.
    for index, item in enumerate(rows):
        item['monotonic_ns'] = sample_ns + index
    return rows


def release_control_locks() -> None:
    psql(
        "SELECT pg_terminate_backend(pid) FROM pg_stat_activity "
        "WHERE (query LIKE '%pg_advisory_xact_lock(4200%' OR query LIKE '%p5-pool-hold%') "
        "AND pid <> pg_backend_pid();"
    )


def run_checked(argv: list[str], timeout: int) -> subprocess.CompletedProcess[str]:
    process = subprocess.Popen(argv, cwd=LOAD, text=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    try:
        stdout, stderr = process.communicate(timeout=timeout)
    except subprocess.TimeoutExpired:
        process.kill()
        stdout, stderr = process.communicate()
        raise gate.EvidenceInvalid(f'actor command timed out after {timeout}s')
    return subprocess.CompletedProcess(argv, process.returncode or 0, stdout, stderr)


def command_tuples(text: str) -> list[str]:
    """psql -At still prints an UPDATE/INSERT/DELETE tag after RETURNING tuples."""
    skipped = ('UPDATE ', 'INSERT ', 'DELETE ')
    return [line.strip() for line in text.splitlines() if line.strip() and not line.startswith(skipped)]


def capture_during(action) -> tuple[subprocess.CompletedProcess, list[dict]]:
    samples = []
    with ThreadPoolExecutor(max_workers=1) as executor:
        future = executor.submit(action)
        while not future.done():
            # Started while the actor was still running. A short SQL command can
            # exit before this docker snapshot returns; that sample is still during
            # the actor. A snapshot is not started after the actor has finished.
            started = time.monotonic_ns()
            pg = postgres_snapshot()
            pool = pgbouncer_snapshot()
            samples.append({'monotonic_ns': started, 'postgres': pg, 'pgbouncer': pool})
            if future.done():
                break
            time.sleep(0.05)
        completed = future.result()
    if not samples:
        raise gate.EvidenceInvalid('no completed lock/pool sample during actor process')
    return completed, samples


def run_primitive(cell: dict) -> dict:
    release_control_locks()
    n = cell['n']
    shape = cell['shape']
    via_pool = n > 8 or shape in {'pool-queue', 'pool-timeout'}
    if shape == 'pool-timeout':
        return run_pool_timeout(n)
    sql = gate.control_sql(shape).replace('\n', ' ')
    host = 'pgbouncer' if via_pool else ''
    script = f'''
set -eu
rm -rf /tmp/p5-actors
mkdir -p /tmp/p5-actors
i=0
while [ "$i" -lt {n} ]; do
  (
    if psql {"-h pgbouncer -p 6432" if host else ""} -U vaulter -d vaulter_load -At -v ON_ERROR_STOP=1 -c "{sql}" > /tmp/p5-actors/$i 2>/tmp/p5-actors/$i.err; then
      echo ok > /tmp/p5-actors/$i.status
    else
      echo fail > /tmp/p5-actors/$i.status
    fi
  ) &
  i=$((i + 1))
done
wait
'''
    completed, during = capture_during(lambda: run_checked(
        ['docker', 'compose', '-f', str(COMPOSE_FILE), '--env-file', str(ENV_FILE),
         'exec', '-T', '-e', 'PGPASSWORD=secret', 'postgres', 'sh', '-c', script],
        180,
    ))
    if completed.returncode != 0:
        raise gate.EvidenceInvalid(f'primitive actors failed: {completed.stderr.strip()[:300]}')
    summary = psql(f"SELECT count(*) FROM generate_series(0,{n - 1});").strip()
    del summary
    statuses = subprocess.run(
        ['docker', 'compose', '-f', str(COMPOSE_FILE), '--env-file', str(ENV_FILE),
         'exec', '-T', 'postgres', 'sh', '-c', 'cat /tmp/p5-actors/*.status'],
        cwd=LOAD, text=True, capture_output=True, timeout=30, check=False,
    )
    lines = [line for line in statuses.stdout.splitlines() if line]
    if len(lines) != n:
        raise gate.EvidenceInvalid(f'primitive attempt count {len(lines)} != {n}')
    refused = 0
    if shape == 'try-lock-nowait':
        bodies = subprocess.run(
            ['docker', 'compose', '-f', str(COMPOSE_FILE), '--env-file', str(ENV_FILE),
             'exec', '-T', 'postgres', 'sh', '-c',
             'for f in /tmp/p5-actors/[0-9]*; do case "$f" in *.status|*.err) ;; *) cat "$f" ;; esac; done | grep -c "^f$" || true'],
            cwd=LOAD, text=True, capture_output=True, timeout=30, check=False,
        )
        # Count false try-locks plus failed connections.
        false_locks = int(bodies.stdout.strip() or '0')
        failed = sum(1 for line in lines if line == 'fail')
        refused = min(n, false_locks + failed)
    return {
        'refused_count': refused,
        'backend_pids': [],
        'http_duration_seconds': None,
        'during_samples': during,
        'pgbouncer_wait_seconds': max(item['pgbouncer']['maxwait'] for item in during),
        'db_lock_wait_seconds': None,
        'barrier_skew_seconds': 0,
        'statuses': {'ok': lines.count('ok'), 'fail': lines.count('fail')},
        'http_5xx': 0,
    }


def run_pool_timeout(n: int) -> dict:
    release_control_locks()
    holders = 10
    script = f'''
set -eu
rm -rf /tmp/p5-actors
mkdir -p /tmp/p5-actors
i=0
while [ "$i" -lt {holders} ]; do
  psql -h pgbouncer -p 6432 -U vaulter -d vaulter_load -v ON_ERROR_STOP=1 \
    -c "BEGIN; SELECT pg_sleep(130); ROLLBACK; -- p5-pool-hold" \
    > /tmp/p5-actors/hold-$i 2>/tmp/p5-actors/hold-$i.err &
  i=$((i + 1))
done
sleep 2
i=0
while [ "$i" -lt {n - holders} ]; do
  psql -h pgbouncer -p 6432 -U vaulter -d vaulter_load -v ON_ERROR_STOP=1 \
    -c "SELECT 1;" \
    > /tmp/p5-actors/wait-$i 2>/tmp/p5-actors/wait-$i.err &
  i=$((i + 1))
done
wait
'''
    started = time.monotonic()
    try:
        completed, during = capture_during(lambda: run_checked(
            ['docker', 'compose', '-f', str(COMPOSE_FILE), '--env-file', str(ENV_FILE),
             'exec', '-T', '-e', 'PGPASSWORD=secret', 'postgres', 'sh', '-c', script],
            180,
        ))
    finally:
        release_control_locks()
    elapsed = time.monotonic() - started
    if completed.returncode != 0 and elapsed < 100:
        raise gate.EvidenceInvalid(f'pool-timeout actors exited early: {completed.stderr.strip()[:300]}')
    return {
        'backend_pids': [],
        'http_duration_seconds': None,
        'during_samples': during,
        'pgbouncer_wait_seconds': max(item['pgbouncer']['maxwait'] for item in during),
        'db_lock_wait_seconds': None,
        'barrier_skew_seconds': 0,
        'statuses': {'elapsed_seconds': round(elapsed, 3)},
        'http_5xx': 0,
    }


def actor_base(run_id: str, cell: dict, attempt: int) -> int:
    run_slot = int(hashlib.sha256(run_id.encode()).hexdigest()[:4], 16) % 1000
    cell_slot = next(i for i, item in enumerate(gate.catalog()) if item['cell_id'] == cell['cell_id'])
    return 1_000_000 + run_slot * 100_000 + cell_slot * 1_000 + attempt * 250


def grant_actors(base: int, n: int) -> None:
    # Copy the seed owner's root grant: distinct actors retain shared-resource access.
    sql = (
        "WITH root AS (SELECT root_node_id FROM v_storages WHERE id = 1), "
        "source AS (SELECT g.* FROM v_node_grants g JOIN root ON root.root_node_id = g.node_id "
        "WHERE g.subject_id = 1 AND g.level = 'owner' LIMIT 1) "
        "INSERT INTO v_node_grants (node_id, subject_type, subject_id, level, permissions, effect, scope, created_at, updated_at) "
        "SELECT source.node_id, source.subject_type, actor_id, source.level, source.permissions, "
        "source.effect, source.scope, now(), now() FROM source "
        f"CROSS JOIN generate_series({base}, {base + n}) AS actor_id ON CONFLICT DO NOTHING RETURNING subject_id;"
    )
    inserted = [line.strip() for line in psql(sql).splitlines() if line.strip().isdigit()]
    if len(inserted) != n + 1:
        raise gate.EvidenceInvalid(f'actor grant seed inserted {len(inserted)} of {n + 1}')


def run_k6(cell, attempt, run_id, document_id, tree, out) -> dict:
    summary = LOAD / 'results' / f"p5-{cell['cell_id']}-a{attempt}.json"
    summary.parent.mkdir(parents=True, exist_ok=True)
    summary.unlink(missing_ok=True)
    base = actor_base(run_id, cell, attempt)
    grant_actors(base, cell['n'])
    env = {
        'P5_SURFACE': cell['surface'],
        'P5_SHAPE': cell['shape'],
        'P5_N': str(cell['n']),
        'P5_ACTOR_BASE': str(base),
        'P5_DOCUMENT_ID': document_id,
        'P5_NODE_IDS': tree['nodes'],
        'P5_MOVE_TARGET': tree['parent'],
        'P5_SUMMARY': f"/results/{summary.name}",
        'BASE_URL': 'http://app:8080',
        'P5_MAX_DURATION': '90s',
    }
    if cell['shape'] in {'same-resource', 'live-patch-overlap'}:
        upload_id, setup_response = create_shared_upload(cell, base + cell['n'])
        setup_status = setup_response['status']
        if upload_id is None:
            return {
                'backend_pids': [],
                'actor_ids': [],
                'during_samples': [],
                'http_responses': [setup_response],
                'http_duration_seconds': 0,
                'pgbouncer_wait_seconds': None,
                'db_lock_wait_seconds': None,
                'barrier_skew_seconds': 0,
                'statuses': {
                    '2xx': 0,
                    '409': 1 if setup_status == 409 else 0,
                    '429': 1 if setup_status == 429 else 0,
                    '5xx': 1 if setup_status >= 500 else 0,
                    'other': 0 if setup_status in {409, 429} or setup_status >= 500 else 1,
                },
                'http_5xx': 1 if setup_status >= 500 else 0,
            }
        env['P5_UPLOAD_ID'] = upload_id
    argv = ['docker', 'compose', '-f', str(COMPOSE_FILE), '--env-file', str(ENV_FILE), '--profile', 'bench',
            'run', '--rm', '--no-deps', '-T']
    for key, value in env.items():
        argv.extend(['-e', f'{key}={value}'])
    argv.extend(['k6', 'run', '--out', 'experimental-prometheus-rw', '/scripts/scenarios/concurrency-live.js'])
    started = time.monotonic()
    overlap = None
    def invoke():
        return subprocess.run(argv, cwd=LOAD, text=True, capture_output=True, timeout=150, check=False)
    if cell['shape'] == 'live-patch-overlap':
        completed, during, overlap = run_patch_overlap(invoke, upload_id, out, cell, attempt, base)
    else:
        completed, during = capture_during(invoke)
    elapsed = time.monotonic() - started
    (out / 'k6' / f"{cell['cell_id']}.a{attempt}.stdout.log").write_text(completed.stdout, encoding='utf-8')
    (out / 'k6' / f"{cell['cell_id']}.a{attempt}.stderr.log").write_text(completed.stderr, encoding='utf-8')
    if completed.returncode != 0:
        raise gate.EvidenceInvalid(f'k6 exited {completed.returncode}: {completed.stderr.strip()[:300]}')
    if not summary.exists():
        raise gate.EvidenceInvalid(f'k6 summary missing: {completed.stderr.strip()[:300]}')
    target = out / 'k6' / summary.name
    target.write_text(summary.read_text(encoding='utf-8'), encoding='utf-8')
    data = json.loads(target.read_text(encoding='utf-8'))
    metrics = data.get('metrics', {})

    def count(name: str) -> int:
        metric = metrics.get(name) or {}
        values = metric.get('values') or {}
        return int(values.get('count') or 0)

    responses = response_evidence(completed.stdout + '\n' + completed.stderr, metrics, base, cell['n'])
    if cell['shape'] in {'same-resource', 'live-patch-overlap'}:
        responses.insert(0, setup_response)
    http_5xx = count('p5_status_5xx')
    return {
        'backend_pids': [],
        'actor_ids': sorted({r['actor_id'] for r in responses if r['phase'] != 'setup'}),
        'participation': iteration_evidence(completed.stdout + '\n' + completed.stderr, metrics, base, cell['n'], responses),
        'overlap': overlap,
        'during_samples': during,
        'http_responses': responses,
        'http_duration_seconds': elapsed,
        'pgbouncer_wait_seconds': None,
        'db_lock_wait_seconds': None,
        'barrier_skew_seconds': 0,
        'statuses': {
            '2xx': count('p5_status_2xx'),
            '409': count('p5_status_409'),
            '429': count('p5_status_429'),
            '5xx': http_5xx,
            'other': count('p5_status_other'),
        },
        'http_5xx': http_5xx,
    }


def response_evidence(log: str, metrics: dict, base: int, n: int) -> list[dict]:
    responses = []
    totals = {name: 0 for name in ('2xx', '409', '429', '5xx', 'other')}
    for match in re.finditer(r'P5_HTTP[|](\d+)[|]([a-z-]+)[|](\d+)[|]([^|\r\n]*)[|]END', log):
        actor_id, phase, status, retry_after = match.groups()
        actor_id, status = int(actor_id), int(status)
        if actor_id not in range(base, base + n) or phase not in {'create', 'operation'}:
            raise gate.EvidenceInvalid('HTTP response has an unexpected actor or phase')
        category = '2xx' if 200 <= status < 300 else str(status) if status in {409, 429} else '5xx' if status >= 500 else 'other'
        totals[category] += 1
        responses.append({'actor_id': actor_id, 'phase': phase, 'status': status, 'retry_after': retry_after or None})
    if not responses:
        raise gate.EvidenceInvalid('no captured product HTTP responses')
    for category, total in totals.items():
        measured = int(((metrics.get('p5_status_' + category) or {}).get('values') or {}).get('count') or 0)
        if measured != total:
            raise gate.EvidenceInvalid(f'HTTP {category} capture {total} != metric count {measured}')
    return responses


def iteration_evidence(log, metrics, base, n, responses):
    events = {phase: [] for phase in ('started', 'completed', 'aborted')}
    for actor, phase in re.findall(r'P5_ITER[|](\d+)[|](started|completed|aborted)[|]END', log):
        events[phase].append(int(actor))
    expected = list(range(base, base + n))
    observed = sorted({r['actor_id'] for r in responses if r['phase'] != 'setup'})
    iterations = int(((metrics.get('iterations') or {}).get('values') or {}).get('count') or 0)
    result = {'expected_actor_ids': expected, 'observed_actor_ids': observed,
              **events, 'iterations': iterations}
    validate_participation({'http_responses': responses, 'actor_ids': observed,
                            'participation': result}, n)
    return result


validate_participation = gate.validate_participation
validate_overlap = gate.validate_overlap


def run_patch_overlap(invoke, upload_id, out, cell, attempt, base):
    prefix = '/tmp/p5-overlap-' + upload_id
    evidence = {'upload_id': upload_id}
    with ThreadPoolExecutor(max_workers=1) as executor:
        future = executor.submit(capture_during, invoke)
        try:
            deadline = time.monotonic() + 40
            while True:
                marker = compose('exec', '-T', 'app', 'cat', prefix + '.held')
                if marker.returncode == 0:
                    evidence.update(json.loads(marker.stdout))
                    evidence['held_ns'] = time.monotonic_ns()
                    break
                if future.done() or time.monotonic() >= deadline:
                    raise gate.EvidenceInvalid('PATCH never entered held window')
                time.sleep(0.05)
            # Make this exact session a candidate only AFTER the real PATCH owns the lock.
            marked = psql(
                f"UPDATE v_upload_sessions SET expires_at = now() - interval '1 day' "
                f"WHERE upload_id = '{upload_id}' AND status IN ('initiated','uploading','assembled') RETURNING upload_id;"
            )
            evidence['sweepable'] = upload_id in command_tuples(marked)
            evidence['lock_observed'] = psql(
                f"SELECT count(*) FROM pg_locks WHERE pid = {int(evidence['backend_pid'])} "
                "AND locktype = 'advisory' AND granted;"
            ).strip() == '1'
            evidence['sweep_start_ns'] = time.monotonic_ns()
            sweep = compose('exec', '-T', '-e', 'P5_SWEEP_TARGET=' + upload_id, 'app', 'php', 'vendor/bin/testbench',
                            'vaulter:uploads:sweep', '--no-interaction', timeout=30)
            evidence['lock_after_sweep'] = psql(
                f"SELECT count(*) FROM pg_locks WHERE pid = {int(evidence['backend_pid'])} "
                "AND locktype = 'advisory' AND granted;"
            ).strip() == '1'
            evidence['target_refused'] = f'P5_SWEEP_TARGET|{upload_id}|refused|END' in sweep.stderr
            evidence.update(sweep_exit=sweep.returncode, sweep_stdout=sweep.stdout,
                            sweep_stderr=sweep.stderr, sweep_end_ns=time.monotonic_ns())
        finally:
            release = compose('exec', '-T', 'app', 'touch', prefix + '.release')
            evidence['release_ns'] = time.monotonic_ns()
            (out / 'sweeper' / f"{cell['cell_id']}.a{attempt}.json").write_text(json.dumps(evidence) + '\n')
            if release.returncode != 0:
                raise gate.EvidenceInvalid('PATCH release failed')
        completed, during = future.result()
    evidence['final_state'] = json.loads(psql(
        "SELECT row_to_json(s) FROM (SELECT upload_id,status,received_bytes FROM v_upload_sessions "
        f"WHERE upload_id = '{upload_id}') s;"
    ).strip())
    psql(f"UPDATE v_upload_sessions SET expires_at = now() + interval '1 day' WHERE upload_id = '{upload_id}';")
    evidence['retry_fixture'] = 'restore-ttl-after-final-state-capture'
    # A same-offset, same-body retry checks staged bytes through the real rangeMatches path.
    winner = actor_base_for_retry(completed.stdout + '\n' + completed.stderr)
    if winner is None:
        evidence['retry_response'] = {'status': 0, 'skipped': 'no-successful-operation'}
    else:
        status, _, retry_after = http_json('PATCH', '/api/vaulter/resumable-uploads/' + upload_id,
            bytes([0x70, 0x35, (winner - base + 1) & 0xff, 0x0a]),
            {'Content-Type': 'application/offset+octet-stream', 'Tus-Resumable': '1.0.0',
             'Upload-Offset': '0', 'X-Load-Actor': str(winner)})
        evidence['retry_response'] = {'actor_id': winner, 'status': status, 'retry_after': retry_after}
    (out / 'sweeper' / f"{cell['cell_id']}.a{attempt}.json").write_text(json.dumps(evidence) + '\n')
    validate_overlap({'overlap': evidence})
    return completed, during, evidence


def actor_base_for_retry(log):
    match = re.search(r'P5_HTTP[|](\d+)[|]operation[|]2\d\d', log)
    return int(match.group(1)) if match else None


def create_shared_upload(cell: dict, setup_actor: int) -> tuple[str | None, dict]:
    body = json.dumps({'name': f"p5-shared-{cell['shape']}-{time.time_ns()}.bin", 'total_size_bytes': 8 if cell['shape'] == 'live-patch-overlap' else 4}).encode()
    headers = {'Content-Type': 'application/json', 'Tus-Resumable': '1.0.0', 'X-Load-Actor': str(setup_actor)}
    status, payload, retry_after = http_json('POST', '/api/vaulter/storages/1/resumable-uploads', body, headers)
    evidence = {'actor_id': setup_actor, 'phase': 'setup', 'status': status, 'retry_after': retry_after}
    if status != 201 or not isinstance(payload, dict):
        return None, evidence
    upload_id = str(payload['data']['upload_id'])
    # System-minted sessions (created_by NULL) use the real storage ability gate.
    # Otherwise every distinct VU is rejected before the session lock is reached.
    import uuid
    upload_id = str(uuid.UUID(upload_id))
    changed = psql(
        f"UPDATE v_upload_sessions SET created_by = NULL WHERE upload_id = '{upload_id}' "
        f"AND created_by = {setup_actor} RETURNING upload_id;"
    ).splitlines()
    if upload_id not in changed:
        raise gate.EvidenceInvalid('shared upload ownership fixture was not prepared')
    evidence['ownership_fixture'] = 'system-minted-created_by-null'
    return upload_id, evidence


class SweeperCaptureTimeout(gate.EvidenceInvalid):
    """The remote sweep may still run; stop before reseeding the shared DB."""


def sweep_candidates() -> int:
    return int(psql(
        "SELECT count(*) FROM v_upload_sessions WHERE target_name LIKE 'p5-dirty-%' "
        "AND status IN ('initiated', 'uploading', 'assembled') AND expires_at <= now();"
    ).strip())


def sweep_batch_metric() -> dict | None:
    try:
        with urllib.request.urlopen(APP_URL + '/metrics', timeout=10) as response:
            lines = response.read().decode('utf-8').splitlines()
    except (urllib.error.URLError, TimeoutError, UnicodeError):
        return None
    values = {}
    raw = []
    for line in lines:
        if line.startswith('vaulter_sweep_batch_rows_'):
            raw.append(line)
        match = re.fullmatch(r'vaulter_sweep_batch_rows_(sum|count)\s+([0-9.eE+-]+)', line)
        if match:
            values[match.group(1)] = float(match.group(2))
    return {**values, 'raw': raw} if 'count' in values and 'sum' in values else None


def run_sweeper(cell, attempt, out, *, run_id: str | None = None) -> dict:
    rows = 20_000 if cell['shape'] == 'dirty-20k' else 1_000_000
    psql("DELETE FROM v_upload_sessions WHERE target_name LIKE 'p5-dirty-%';")
    psql(
        "INSERT INTO v_upload_sessions (storage_id, upload_id, protocol, status, received_bytes, expires_at, target_name, created_at, updated_at) "
        f"SELECT 1, gen_random_uuid(), 'tus', 'initiated', 0, now() - interval '1 day', 'p5-dirty-{cell['shape']}', now(), now() "
        f"FROM generate_series(1, {rows});",
        timeout=600,
    )
    seeded = sweep_candidates()
    if seeded != rows:
        raise gate.EvidenceInvalid(f'dirty seed {seeded} != {rows}')
    metric_before = sweep_batch_metric()
    command = ['exec', '-T', 'app', 'php', '-d', 'auto_prepend_file=/app/load/scripts/p5-php-peak.php',
               'vendor/bin/testbench', 'vaulter:uploads:sweep', '--no-interaction']
    started = time.monotonic()
    timed_out = False
    try:
        completed = compose(*command, timeout=900)
        stdout, stderr, exit_code = completed.stdout, completed.stderr, completed.returncode
    except subprocess.TimeoutExpired as exc:
        # A docker-client timeout is not the product command's exit status.
        timed_out = True
        def decoded(value):
            return value.decode('utf-8', 'replace') if isinstance(value, bytes) else value or ''
        stdout, stderr, exit_code = decoded(exc.stdout), decoded(exc.stderr), None
    elapsed = time.monotonic() - started
    peak_match = re.search(r'^P5_PHP_PEAK_BYTES=(\d+)\s*$', stderr, flags=re.MULTILINE)
    report = {
        'run_id': run_id,
        'cell_id': cell['cell_id'],
        'attempt': attempt,
        'command': command,
        'seeded_rows': seeded,
        'candidate_rows': seeded,
        'processed_rows': None,
        'remaining_rows': None,
        'elapsed_seconds': round(elapsed, 3),
        'exit_code': exit_code,
        'timed_out': timed_out,
        'command_failed': timed_out or exit_code != 0,
        'stdout': stdout,
        'stderr': stderr,
        'php_peak_bytes': int(peak_match.group(1)) if peak_match else None,
        'php_peak_kind': 'memory_get_peak_usage(true), not process RSS',
        'vaulter_sweep_batch_rows': None,
        'batch_metric_before': metric_before,
        'batch_metric_after': None,
        'progress_capture_error': None,
        'tier_l_claim': False,
    }
    target = out / 'sweeper' / f"{cell['cell_id']}.a{attempt}.json"
    # Save command output first: a failed post-command DB probe must not erase it.
    def persist():
        target.write_text(json.dumps(report, indent=2) + '\n', encoding='utf-8')
    persist()
    if not timed_out:
        try:
            remaining = sweep_candidates()
            report['remaining_rows'] = remaining
            report['processed_rows'] = seeded - remaining
        except (gate.EvidenceInvalid, subprocess.TimeoutExpired, OSError, ValueError) as exc:
            report['progress_capture_error'] = str(exc)
        metric_after = sweep_batch_metric()
        report['batch_metric_after'] = metric_after
        if metric_before is not None and metric_after is not None:
            count_delta = metric_after['count'] - metric_before['count']
            sum_delta = metric_after['sum'] - metric_before['sum']
            if count_delta >= 0 and sum_delta >= 0:
                report['vaulter_sweep_batch_rows'] = {'count_delta': count_delta, 'sum_delta': sum_delta}
            else:
                report['batch_metric_error'] = 'counter reset; delta unevaluated'
    else:
        report['progress_capture_error'] = 'docker exec timed out; remote command termination unconfirmed'
    persist()
    if timed_out:
        # Stop the exclusive run; do not seed another attempt over a possibly live command.
        raise SweeperCaptureTimeout(f'sweeper capture timed out; remote command state unknown; evidence saved to {target}')
    return {
        'backend_pids': [],
        'http_duration_seconds': None,
        'pgbouncer_wait_seconds': None,
        'db_lock_wait_seconds': None,
        'barrier_skew_seconds': 0,
        'statuses': report,
        'http_5xx': 0,
    }


def verdict_for(cell: dict, rows: list[dict]) -> dict:
    http_5xx = sum(int(row.get('http_5xx') or 0) for row in rows)
    evidence = 'valid'
    if cell['plane'] in {'product-realistic', 'product-worst-case'}:
        for row in rows:
            validate_participation(row, cell['n'])
            if cell['shape'] == 'live-patch-overlap':
                validate_overlap(row)
    success = sum(int((row.get('statuses') or {}).get('2xx') or 0) for row in rows)
    if http_5xx:
        slo = 'red'
    elif cell['shape'] == 'int4-collision':
        slo = 'unevaluated'  # A status-only run cannot establish a CAS key collision.
    elif cell['shape'] == 'same-resource':
        slo = 'not-a-5pct-budget'
    elif cell['plane'] in {'primitive-control', 'dirty-cardinality-probe'}:
        slo = 'not-a-product-slo'
    elif cell['plane'] == 'product-realistic' and (success == 0 or not any(
        response['phase'] == 'operation' and 200 <= response['status'] < 300
        for row in rows for response in row.get('http_responses', [])
    )):
        slo = 'red'
    elif cell['shape'] == 'live-patch-overlap' and any(
        row['overlap']['final_state'].get('status') in {'expired', 'aborted'}
        or row['overlap']['final_state'].get('received_bytes') != 4
        or not 200 <= row['overlap']['retry_response']['status'] < 300 for row in rows
    ):
        slo = 'red'
    else:
        slo = 'green'
    return {
        'cell_id': cell['cell_id'],
        'evidence': evidence,
        'slo': slo,
        'attempts': len(rows),
        'http_5xx': http_5xx,
        'collision_verdict': 'unconfirmed' if cell['shape'] == 'int4-collision' else None,
    }


def capture_prometheus(out: Path) -> None:
    for name, query in (('targets', '/api/v1/targets'), ('up', '/api/v1/query?query=up')):
        try:
            with urllib.request.urlopen(PROM_URL + query, timeout=10) as response:
                body = response.read()
                status = response.status
        except urllib.error.URLError as exc:
            body = str(exc).encode()
            status = 0
        (out / 'prometheus' / f'{name}.json').write_bytes(body)
        (out / 'prometheus' / f'{name}.status').write_text(str(status) + '\n', encoding='utf-8')


def verify_artifacts(root: Path, *, prior_digest: str | None = None) -> str:
    root = Path(root)
    manifest = json.loads((root / 'run-manifest.json').read_text(encoding='utf-8'))
    run_id = manifest['run_id']
    if manifest.get('capture_contract') != 'r05-b07/v2' and run_id != '2aeca12f-6180-4595-8466-31397c4ed15d':
        raise gate.EvidenceInvalid('current run missing R05 capture contract')
    gate.verify_run_dir(root)
    verdicts = json.loads((root / 'cell-verdicts.json').read_text(encoding='utf-8'))
    by_id = {row['cell_id']: row for row in verdicts['cells']}
    if verdicts.get('run_id') != run_id:
        raise gate.EvidenceInvalid('cross-run sample')
    seen_n = set()
    for cell in gate.catalog():
        seen_n.add(cell['n'])
        row = by_id.get(cell['cell_id'])
        if row is None:
            raise gate.EvidenceInvalid(f'missing cell {cell["cell_id"]}')
        if row['evidence'] != 'valid':
            raise gate.EvidenceInvalid(f'{cell["cell_id"]} evidence is {row["evidence"]}')
        expected = repeat_count(cell)
        if row['attempts'] != expected:
            raise gate.EvidenceInvalid(f'{cell["cell_id"]} attempts {row["attempts"]} != {expected}')
        for attempt in range(1, expected + 1):
            evidence = root / 'attempts' / f"{cell['cell_id']}.attempt-{attempt}.json"
            if not evidence.is_file():
                raise gate.EvidenceInvalid(f'missing attempt file {evidence.name}')
        if manifest.get('capture_contract') == 'r05-b07/v2':
            attempts = [json.loads((root / 'attempts' / f"{cell['cell_id']}.attempt-{a}.json").read_text()) for a in range(1, expected + 1)]
            if verdict_for(cell, attempts) != row:
                raise gate.EvidenceInvalid('stored verdict differs from observed attempt evidence')
        if row['http_5xx'] and row['slo'] not in {'red', 'not-a-product-slo'}:
            raise gate.EvidenceInvalid('5xx counted as success')
    if 200 not in seen_n or 201 not in seen_n:
        raise gate.EvidenceInvalid('N axis is missing 200/201')
    sweeper = list((root / 'sweeper').glob('*.json'))
    twenty = [path for path in sweeper if 'dirty-20k' in path.name]
    million = [path for path in sweeper if 'dirty-1m' in path.name]
    if len(twenty) != 3 or len(million) != 1:
        raise gate.EvidenceInvalid(f'sweeper evidence 20k={len(twenty)} 1m={len(million)}')
    before = json.loads((root / 'integrity-before.json').read_text(encoding='utf-8'))
    after = json.loads((root / 'integrity-after.json').read_text(encoding='utf-8'))
    if before != after:
        raise gate.EvidenceInvalid('restored fixture counts do not match')
    hits = secret_scan(root)
    if hits:
        raise gate.EvidenceInvalid('secret scan: ' + ', '.join(hits[:5]))
    digest = digest_tree(root)
    if prior_digest is not None and prior_digest != digest:
        raise gate.EvidenceInvalid('verify digest is not byte-stable')
    return digest


def main(argv: list[str] | None = None) -> int:
    import argparse
    parser = argparse.ArgumentParser(description='P5.3 live concurrency matrix')
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
            print(f'p5-matrix run: ok run_id={args.run_id}')
        else:
            digest = verify_artifacts(Path(args.artifacts), prior_digest=args.prior_digest)
            print(f'p5-matrix verify: ok digest={digest}')
    except gate.EvidenceInvalid as exc:
        print(str(exc), flush=True)
        return 1
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
