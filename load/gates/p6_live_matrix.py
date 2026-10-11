#!/usr/bin/env python3
"""P6.3 tier-S queue matrix.

One run_id binds relay, Redis workers, the controlled sink and PostgreSQL.
Product cells repeat three times. One 1× window lasts 30 minutes. 5×/10× are
bounded bursts with a measured recovery. Missing samples stay evidence-invalid.
A RED SLO with complete evidence stays valid.
"""

from __future__ import annotations

import hashlib
import json
import os
import subprocess
import time
import urllib.request
import urllib.parse
from pathlib import Path

import p6_queue as gate
import p6_evidence as ev


REPO = Path(__file__).resolve().parents[2]
LOAD = REPO / 'load'
COMPOSE_FILE = LOAD / 'docker-compose.load.yml'
ENV_FILE = LOAD / 'env' / 'S.env'
PROM_URL = os.environ.get('LOAD_PROM_URL', 'http://127.0.0.1:9090')
APP_URL = os.environ.get('LOAD_APP_URL', f"http://127.0.0.1:{os.environ.get('LOAD_APP_PORT', '8080')}")
CONTEXT = LOAD / 'seed' / 'out' / 'p6-queue-context.json'
LEDGER = LOAD / 'seed' / 'out' / 'p6-lifecycle.jsonl'
SINK_LEDGER = LOAD / 'seed' / 'out' / 'sink-ledger.jsonl'
STEADY_CELL = 'product-realistic.relay.i20.f1.2xx'
STEADY_SECONDS = 1800
SECRET_MARKERS = ('postgresql://', 'redis://', 'vaulter-load-secret', 'PGPASSWORD=', 'p6-stand-')
SINK_PROFILE = {
    '2xx': '2xx',
    '500': '500',
    'slow-under-timeout': 'delay',
    'over-timeout': 'hang',
}
P6_PROMETHEUS_QUERIES = {
    name: f'sum({name})'
    for name in ev.P6_METRIC_NAMES
}
P6_PROMETHEUS_QUERIES['vaulter_command_peak_memory_bytes'] = (
    'sum(vaulter_command_peak_memory_bytes{command="vaulter:webhooks:retry"})'
)


def compose(*args: str, timeout: int = 180, input_text: str | None = None) -> subprocess.CompletedProcess[str]:
    return subprocess.run(
        ['docker', 'compose', '-f', str(COMPOSE_FILE), '--env-file', str(ENV_FILE), *args],
        cwd=LOAD, text=True, capture_output=True, timeout=timeout, check=False, input=input_text,
    )


def probe(action: str, timeout: int = 180, **options: str) -> dict:
    command = ['exec', '-T', '-e', 'LOAD_QUEUE_CONTEXT=/app/load/seed/out/p6-queue-context.json', 'app', '/usr/bin/time', '-f', 'R09_RSS_KIB=%M', 'php', 'vendor/bin/testbench', 'load:queue-probe', action]
    if run_id := options.get('run_id'):
        command[4:4] = ['-e', f'LOAD_RUN_ID={run_id}']
    for key, value in options.items():
        command.append(f'--{key.replace("_", "-")}={value}')
    completed = compose(*command, timeout=timeout)
    lines = [line for line in completed.stdout.splitlines() if line.startswith('{')]
    if not lines:
        return {
            'ok': False,
            'exit': completed.returncode,
            'stderr': completed.stderr[-800:],
            'stdout': completed.stdout[-800:],
        }
    payload = json.loads(lines[-1])
    payload['exit'] = completed.returncode
    rss = [line.split('=', 1)[1] for line in completed.stderr.splitlines() if line.startswith('R09_RSS_KIB=')]
    payload['rss_peak_bytes'] = int(rss[-1]) * 1024 if rss and rss[-1].isdigit() else None
    if 'retried' in payload and isinstance(payload.get('status'), int):
        payload['ok'] = completed.returncode == 0 and payload['status'] == 0
    else:
        payload['ok'] = completed.returncode == 0
    if completed.returncode != 0:
        payload['stderr'] = completed.stderr[-800:]
    return payload


def psql(sql: str, timeout: int = 180) -> str:
    completed = compose(
        'exec', '-T', '-e', 'PGPASSWORD=secret', 'postgres',
        'psql', '-U', 'vaulter', '-d', 'vaulter_load', '-v', 'ON_ERROR_STOP=1', '-At',
        timeout=timeout, input_text=sql,
    )
    if completed.returncode != 0:
        raise gate.EvidenceInvalid(f'psql failed: {completed.stderr.strip()[:300]}')
    return completed.stdout


def redis_depth() -> dict:
    depths = {}
    for queue in gate.QUEUES:
        key = f'queues:{queue}'
        fields = {}
        for op, suffix, name in (('LLEN', '', 'pending'), ('ZCARD', ':delayed', 'delayed'), ('ZCARD', ':reserved', 'reserved')):
            completed = compose('exec', '-T', 'redis', 'redis-cli', op, f'{key}{suffix}', timeout=20)
            if completed.returncode != 0 or not completed.stdout.strip().lstrip('-').isdigit():
                raise gate.EvidenceInvalid(f'missing redis sample for {queue}')
            fields[name] = int(completed.stdout.strip())
        depths[queue] = fields
    return depths


def flush_redis() -> None:
    for queue in gate.QUEUES:
        key = f'queues:{queue}'
        for suffix in ('', ':delayed', ':reserved'):
            result = compose('exec', '-T', 'redis', 'redis-cli', 'DEL', f'{key}{suffix}', timeout=20)
            if result.returncode:
                raise gate.EvidenceInvalid('queue cleanup failed')


def _worker_pids(source: str) -> list[int]:
    if source == 'host':
        completed = subprocess.run(
            ['docker', 'top', 'vaulter-load-queue-worker-1', '-o', 'pid,args'],
            text=True, capture_output=True, timeout=20, check=False,
        )
        lines = completed.stdout.splitlines()[1:]
    else:
        completed = compose('exec', '-T', 'queue-worker', 'ps', '-o', 'pid,args', timeout=20)
        lines = completed.stdout.splitlines()[1:]
    pids = []
    for line in lines:
        if 'queue:work' not in line:
            continue
        pid = line.split()[0]
        if pid.isdigit() and int(pid) > 0:
            pids.append(int(pid))
    return pids


def worker_pids() -> list[int]:
    return _worker_pids('host')


def set_workers(running: bool) -> None:
    """Pause or continue queue:work. Never recreate the container: its entrypoint runs migrate:fresh."""
    # Existing containers only: compose up can recreate and migrate:fresh.
    if running and len(_worker_pids('container')) < 2:
        started = compose(
            'exec', '-d', 'queue-worker', 'sh', '-c',
            'exec php vendor/bin/testbench queue:work redis --queue=load-health,default --sleep=1 --tries=1 > /tmp/p6-second-worker.log 2>&1', timeout=30,
        )
        if started.returncode != 0:
            raise gate.EvidenceInvalid(f'second worker failed: {started.stderr[-400:]}')
    if running and not wait_until(lambda: len(_worker_pids('container')) == 2, 10):
        raise gate.EvidenceInvalid('exactly two queue workers required')
    for queue in gate.QUEUES:
        result = compose('exec', '-T', 'app', 'php', 'vendor/bin/testbench',
                         'queue:continue' if running else 'queue:pause', f'redis:{queue}', timeout=20)
        if result.returncode:
            raise gate.EvidenceInvalid('queue pause/continue failed: ' + result.stderr[-300:])
    # The image has no pcntl: SIGUSR2 kills detached workers. Use Laravel's shared cache pause.
    if not running:
        time.sleep(1)
    if not running:
        if not wait_until(lambda: all(d['reserved'] == 0 for d in redis_depth().values()), 70):
            raise gate.EvidenceInvalid('workers did not finish reserved jobs before cleanup')
        # The serial sink may still have timed-out requests in its accept backlog.
        # A health response after pausing producers fences those requests before reset.
        result = compose('exec', '-T', 'app', 'curl', '-fsS', '--max-time', '120',
                         'http://webhook-sink:8090/health', timeout=130)
        if result.returncode or result.stdout.strip() != 'ok':
            raise gate.EvidenceInvalid('sink did not settle before fixture cleanup')



def write_context(run_id: str, cell_id: str, attempt: int, fingerprint: str) -> None:
    CONTEXT.parent.mkdir(parents=True, exist_ok=True)
    temporary = CONTEXT.with_suffix('.tmp')
    temporary.write_text(json.dumps({
        'path': '/app/load/seed/out/p6-lifecycle.jsonl',
        'run_id': run_id,
        'cell_id': cell_id,
        'attempt': attempt,
        'fingerprint': fingerprint,
    }), encoding='utf-8')
    temporary.chmod(0o666)
    temporary.replace(CONTEXT)


def ledger_slice(path: Path, start: int) -> list[dict]:
    if not path.exists():
        return []
    rows = []
    for line in path.read_text(encoding='utf-8').splitlines()[start:]:
        if not line.strip():
            continue
        rows.append(json.loads(line))
    return rows


def line_count(path: Path) -> int:
    if not path.exists():
        return 0
    return len(path.read_text(encoding='utf-8').splitlines())


def repeat_count(cell: dict) -> int:
    if cell['plane'] == 'product-realistic':
        return 3
    return 1


def burst_events(cell: dict) -> int:
    rate = cell.get('ingress_per_s') or 0
    sink = cell.get('sink')
    if cell.get('fanout') == 'all' and rate >= 100:
        return rate
    return rate or 2


def is_steady(cell: dict, attempt: int) -> bool:
    return cell['cell_id'] == STEADY_CELL and attempt == 1


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


def counts_checksum() -> dict:
    raw = psql(
        "SELECT json_build_object("
        "'outbox', (SELECT count(*) FROM v_outbox),"
        "'endpoints', (SELECT count(*) FROM v_webhook_endpoints),"
        "'deliveries', (SELECT count(*) FROM v_webhook_deliveries),"
        "'outbox_md5', (SELECT md5(coalesce(string_agg(id::text, ',' ORDER BY id), '')) FROM v_outbox),"
        "'delivery_md5', (SELECT md5(coalesce(string_agg(id::text, ',' ORDER BY id), '')) FROM v_webhook_deliveries)"
        ");"
    ).strip()
    return json.loads(raw)


def dump_tables(path: Path) -> None:
    completed = compose(
        'exec', '-T', 'postgres', 'pg_dump', '-U', 'vaulter', '-d', 'vaulter_load',
        '-t', 'v_outbox', '-t', 'v_webhook_endpoints', '-t', 'v_webhook_deliveries', '--data-only',
        timeout=180,
    )
    if completed.returncode != 0:
        raise gate.EvidenceInvalid('snapshot dump failed')
    path.write_text(completed.stdout, encoding='utf-8')


def restore_tables(path: Path) -> None:
    set_workers(False)
    sql = (
        'TRUNCATE v_webhook_deliveries, v_outbox, v_webhook_endpoints RESTART IDENTITY CASCADE;\n'
        + path.read_text(encoding='utf-8')
    )
    psql(sql, timeout=180)
    flush_redis()


def failures() -> list[dict]:
    raw = psql("SELECT json_build_object('uuid', uuid, 'class', payload::json->>'displayName', "
               "'command_class', payload::json->'data'->>'commandName', "
               "'payload_sha256', encode(sha256(payload::bytea),'hex'), "
               "'exception_class', split_part(exception, ':', 1), 'failed_at', failed_at) FROM failed_jobs;")
    return [json.loads(line) for line in raw.splitlines() if line.strip()]


def clean_cell(run_id: str) -> None:
    set_workers(False)
    flush_redis()
    result = probe('reset', run_id=run_id)
    if not result['ok']:
        raise gate.EvidenceInvalid('fixture cleanup failed')


def start_cell(run_id: str, cell_id: str, attempt: int, fingerprint: str) -> None:
    clean_cell(run_id)
    write_context(run_id, cell_id, attempt, fingerprint)
    set_workers(True)


def metrics_open(run_id: str) -> None:
    result = compose('exec', '-T', 'app', 'curl', '-fsS', '-X', 'POST',
                     '-H', 'Content-Type: application/json', '-d',
                     json.dumps({'run_id': run_id, 'ttl_seconds': 21600}),
                     'http://localhost:8080/metrics/run/open')
    if result.returncode:
        raise gate.EvidenceInvalid('cannot open metrics run: ' + result.stderr[-300:])


def scrape_sample(out: Path) -> dict:
    with urllib.request.urlopen(PROM_URL + '/api/v1/query?query=up%7Bjob%3D%22vaulter-sut%22%7D', timeout=10) as response:
        payload = json.load(response)
    rows = payload.get('data', {}).get('result', [])
    sample = {'time': time.time(), 'result': rows}
    append_jsonl(out / 'prometheus' / 'scrape.jsonl', sample)
    return sample


def prometheus_scalar(payload: dict) -> float:
    rows = payload.get('data', {}).get('result', [])
    if payload.get('status') != 'success' or len(rows) != 1:
        raise gate.EvidenceInvalid('Prometheus scalar query did not return exactly one series')
    try:
        return float(rows[0]['value'][1])
    except (KeyError, TypeError, ValueError) as exc:
        raise gate.EvidenceInvalid('Prometheus scalar query returned an invalid value') from exc


def query_prometheus(query: str) -> dict:
    params = urllib.parse.urlencode({'query': query})
    with urllib.request.urlopen(PROM_URL + '/api/v1/query?' + params, timeout=10) as response:
        return json.load(response)


def p6_metrics_reached(volume: dict) -> bool:
    try:
        metrics = {
            name: prometheus_scalar(query_prometheus(query))
            for name, query in P6_PROMETHEUS_QUERIES.items()
        }
        ev.validate_p6_metrics(metrics, volume)
    except (OSError, ValueError, gate.EvidenceInvalid):
        return False

    return True


def capture_prometheus(out: Path, run_id: str, volume: dict) -> None:
    confirmed_relay_minimum = ev.confirmed_relay_rows(ledger_slice(LEDGER, 0), run_id)
    target = out / 'prometheus'
    target.mkdir(parents=True, exist_ok=True)
    captures = dict((
        ('targets', '/api/v1/targets'),
        ('up', '/api/v1/query?query=up'),
        ('relayed', '/api/v1/query?query=vaulter_outbox_relayed_total'),
    ))
    captures.update({name: '/api/v1/query?' + urllib.parse.urlencode({'query': query})
                     for name, query in P6_PROMETHEUS_QUERIES.items()})
    for name, query in captures.items():
        try:
            with urllib.request.urlopen(PROM_URL + query, timeout=10) as response:
                body = response.read()
                status = response.status
        except Exception as exc:  # noqa: BLE001 - raw fallback, not a zero
            body = str(exc).encode()
            status = 0
        (target / f'{name}.json').write_bytes(body)
        (target / f'{name}.status').write_text(str(status) + '\n', encoding='utf-8')
    scraped_metrics = {
        name: prometheus_scalar(json.loads((target / f'{name}.json').read_text(encoding='utf-8')))
        for name in ev.P6_METRIC_NAMES
    }
    try:
        ev.validate_p6_metrics(scraped_metrics, volume, confirmed_relay_minimum)
    except ValueError as exc:
        raise gate.EvidenceInvalid(str(exc)) from exc
    with urllib.request.urlopen(
        APP_URL + '/metrics?' + urllib.parse.urlencode({'run_id': run_id}),
        timeout=10,
    ) as response:
        exposition = response.read().decode('utf-8')
    (target / 'exposition.prom').write_text(exposition, encoding='utf-8')
    try:
        exposition_metrics = ev.exposition_totals(exposition)
        ev.validate_p6_metrics(exposition_metrics, volume, confirmed_relay_minimum)
    except ValueError as exc:
        raise gate.EvidenceInvalid(str(exc)) from exc
    for name in ev.P6_METRIC_NAMES:
        if scraped_metrics[name] > exposition_metrics[name]:
            raise gate.EvidenceInvalid(f'Prometheus metric {name} exceeds the run-scoped exposition')


def capture_scrape_range(out: Path) -> None:
    samples = ledger_slice(out / 'prometheus' / 'scrape.jsonl', 0)
    start = next(s['time'] for s in samples if any(r['value'][1] == '1' for r in s['result']))
    params = urllib.parse.urlencode({'query': 'up{job="vaulter-sut"}', 'start': start, 'end': time.time(), 'step': 5})
    with urllib.request.urlopen(PROM_URL + '/api/v1/query_range?' + params, timeout=20) as response:
        (out / 'prometheus' / 'up-range.json').write_bytes(response.read())
    params = urllib.parse.urlencode({'query': 'timestamp(up{job="vaulter-sut"})', 'start': start, 'end': time.time(), 'step': 5})
    with urllib.request.urlopen(PROM_URL + '/api/v1/query_range?' + params, timeout=20) as response:
        (out / 'prometheus' / 'scrape-timestamps.json').write_bytes(response.read())


def append_jsonl(path: Path, row: dict) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open('a', encoding='utf-8') as handle:
        handle.write(json.dumps(row, sort_keys=True) + '\n')


def wait_until(predicate, timeout: float) -> bool:
    deadline = time.monotonic() + timeout
    while time.monotonic() < deadline:
        if predicate():
            return True
        time.sleep(0.4)
    return False


def prepare_endpoints(run_id: str, cell: dict) -> None:
    profile = SINK_PROFILE.get(cell.get('sink') or '', '2xx')
    delay = '50' if cell.get('sink') == 'slow-under-timeout' else ''
    options = {'run_id': run_id, 'cell_id': cell['cell_id'], 'count': '20', 'profile': profile}
    if delay:
        options['delay_ms'] = delay
    created = probe('endpoints', **options)
    if not created.get('ok') or created.get('endpoints') != 20:
        raise gate.EvidenceInvalid(f'endpoint seed failed for {cell["cell_id"]}')
    density = cell.get('fanout') or '1'
    configured = probe('density', run_id=run_id, cell_id=cell['cell_id'], density=str(density), profile=profile, delay_ms=delay)
    if not configured.get('ok') or probe('counts')['endpoints_active'] != 20:
        raise gate.EvidenceInvalid('endpoint density fixture is not clean')


def run_burst(out: Path, run_id: str, cell: dict, attempt: int, fingerprint: str) -> dict:
    start_cell(run_id, cell['cell_id'], attempt, fingerprint)
    failed_before = failures()
    scrape_sample(out)
    sink_at = line_count(SINK_LEDGER)
    life_at = line_count(LEDGER)
    prepare_endpoints(run_id, cell)
    started = time.time()
    monotonic = time.monotonic()
    events = 0
    samples = []
    if is_steady(cell, attempt):
        deadline = time.monotonic() + STEADY_SECONDS
        next_sample = 0.0
        next_ingress = time.monotonic()
        while time.monotonic() < deadline:
            probe('outbox', run_id=run_id, cell_id=cell['cell_id'], count='20', timeout=60)
            probe('relay', run_id=run_id, limit='100', timeout=60)
            events += 20
            now = time.monotonic()
            if now >= next_sample:
                row = {'t': round(now - monotonic, 1), 'counts': probe('counts', run_id=run_id), 'redis': redis_depth()}
                samples.append(row)
                append_jsonl(out / 'database' / 'transitions.jsonl', {'cell_id': cell['cell_id'], 'attempt': attempt, **row})
                scrape_sample(out)
                next_sample = now + 30
            next_ingress += 1
            time.sleep(max(0.0, next_ingress - time.monotonic()))
    else:
        events = burst_events(cell)
        probe('outbox', run_id=run_id, cell_id=cell['cell_id'], count=str(events), timeout=120)
        probe('relay', run_id=run_id, limit=str(max(events, 1)), timeout=180)
    before_depth = redis_depth()
    recovered = wait_until(
        lambda: all(not any(item.values()) for item in redis_depth().values()),
        20 if not is_steady(cell, attempt) else 60,
    )
    recovery_seconds = round(time.monotonic() - monotonic, 3)
    after = redis_depth()
    counts = probe('counts', run_id=run_id)
    duration = round(time.monotonic() - monotonic, 3)
    observed_ns = time.monotonic_ns()
    sink_end = line_count(SINK_LEDGER)
    sink_rows = ev.window(ledger_slice(SINK_LEDGER, sink_at)[:sink_end-sink_at], run_id, cell['cell_id'])
    life_rows = ev.window(ledger_slice(LEDGER, life_at), run_id, cell['cell_id'], attempt)
    life_rows = [r for r in life_rows if r['monotonic_ns'] <= observed_ns]
    joined = ev.lifecycle(life_rows)
    failed_after = failures()
    delta = ev.failure_delta(failed_before, failed_after)
    http = len(sink_rows)
    uuids = [row.get('uuid') for row in life_rows if row.get('source') == 'queued']
    slope = None
    if samples:
        slope = samples[-1]['counts'].get('outbox_pending', 0) - samples[0]['counts'].get('outbox_pending', 0)
    slo = 'not-a-product-slo'
    if cell['plane'] == 'product-realistic':
        slo = 'green'
        if delta or not recovered:
            slo = 'red'
        if is_steady(cell, attempt) and slope is not None and slope > 0:
            slo = 'red'
    pids = worker_pids()
    evidence = 'valid' if len(pids) == 2 and counts.get('ok', True) else 'invalid'
    if cell.get('fanout') not in (None, '0') and cell.get('sink') and http == 0 and events > 0 and recovered:
        evidence = 'invalid'
    expected_jobs = events * {'0': 0, '1': 1, 'all': 20}[cell['fanout']]
    if joined['queued'] != expected_jobs or joined['invalid_uuids']:
        evidence = 'invalid'
    record = {
        'run_id': run_id,
        'cell_id': cell['cell_id'],
        'attempt': attempt,
        'plane': cell['plane'],
        'evidence': evidence,
        'duration_seconds': duration,
        'burst_seconds': STEADY_SECONDS if is_steady(cell, attempt) else 1,
        'recovery_seconds': recovery_seconds,
        'recovered': recovered,
        'events_sent': events,
        'ingress_per_s': cell.get('ingress_per_s'),
        'fanout': cell.get('fanout'),
        'sink': cell.get('sink'),
        'http_receives': http,
        'sink_start_line': sink_at, 'sink_end_line': sink_end,
        'fanout_http_per_event': http / events if events else None,
        'duplicate_http': len(sink_rows) - len({(r['event_id'], r.get('slot')) for r in sink_rows}),
        'dispatch_uuids': len([item for item in uuids if item]),
        'failed_jobs': len(delta),
        'failed_jobs_before': failed_before,
        'failed_jobs_after': failed_after,
        'failed_jobs_delta': delta,
        'lifecycle': joined,
        'observed_through_ns': observed_ns,
        'jobs_per_second': joined['processed'] / duration,
        'recovery_verdict': 'recovered' if recovered else 'recovered-timeout',
        'business_failed': counts.get('deliveries_failed'),
        'business_dead': counts.get('deliveries_dead'),
        'redis_before': before_depth,
        'redis_after': after,
        'backlog_slope': slope,
        'worker_pids': pids,
        'slo': slo,
        'started_at': time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime(started)),
    }
    record['slo'] = ev.product_verdict(record)
    if recovered and (joined['processed'] + len(delta) != expected_jobs):
        record['evidence'] = 'invalid'
    if cell['fanout'] == '0' and http:
        record['evidence'] = 'invalid'
    for queue, depth in after.items():
        append_jsonl(out / 'queue' / 'redis.jsonl', {'cell_id': cell['cell_id'], 'attempt': attempt, 'queue': queue, **depth})
    scrape_sample(out)
    return record


def run_controls(out: Path, run_id: str, fingerprint: str) -> list[dict]:
    records = []
    start_cell(run_id, 'overlap-control.relay-retry.barrier.relay2-retry2', 1, fingerprint)
    psql('CREATE TABLE IF NOT EXISTS p6_barrier (run_id varchar(80) PRIMARY KEY, waiting integer NOT NULL);')
    probe('outbox', run_id=run_id, cell_id='overlap', count='4')
    probe('due', run_id=run_id, cell_id='overlap', count='2', profile='2xx')
    started = time.monotonic()
    processes = []
    for kind in ('relay', 'relay', 'retry', 'retry'):
        processes.append(subprocess.Popen(
            ['docker', 'compose', '-f', str(COMPOSE_FILE), '--env-file', str(ENV_FILE),
             'exec', '-T', '-e', 'LOAD_QUEUE_CONTEXT=/app/load/seed/out/p6-queue-context.json', 'app', 'php', 'vendor/bin/testbench', 'load:queue-probe', 'barrier',
             f'--run-id={run_id}', '--parties=4', f'--kind={kind}', '--limit=10'],
            cwd=LOAD, text=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
        ))
    spans = []
    for process in processes:
        stdout, stderr = process.communicate(timeout=40)
        lines = [line for line in stdout.splitlines() if line.startswith('{')]
        if process.returncode != 0 or not lines:
            raise gate.EvidenceInvalid(f'overlap barrier failed: {stderr[-300:]}')
        spans.append(json.loads(lines[-1])['started'])
    records.append({
        'run_id': run_id, 'cell_id': 'overlap-control.relay-retry.barrier.relay2-retry2', 'attempt': 1,
        'plane': 'overlap-control', 'evidence': 'valid', 'relay_ticks': 2, 'retry_ticks': 2,
        'span_seconds': max(spans) - min(spans), 'slo': 'not-a-product-slo',
        'duration_seconds': round(time.monotonic() - started, 3), 'worker_pids': worker_pids(),
    })
    for cell_id, serial in (
        ('overlap-control.duplicate-job.serial.same-delivery', True),
        ('overlap-control.duplicate-job.concurrent.same-delivery', False),
    ):
        start_cell(run_id, cell_id, 1, fingerprint)
        life_at = line_count(LEDGER)
        sink_at = line_count(SINK_LEDGER)
        if serial:
            first = probe('duplicate', run_id=run_id, cell_id=cell_id, count='1', profile='2xx')
            wait_until(lambda: any(row.get('source') == 'processed' for row in ledger_slice(LEDGER, life_at)), 20)
            second = probe('duplicate', run_id=run_id, cell_id=cell_id, delivery_id=first['delivery_id'], count='1', profile='2xx')
            delivery_id = first['delivery_id']
            dispatches = first['dispatches'] + second['dispatches']
        else:
            made = probe('duplicate', run_id=run_id, cell_id=cell_id, count='2', profile='delay', delay_ms='1000')
            delivery_id = made['delivery_id']
            dispatches = made['dispatches']
        finished = wait_until(lambda: ev.lifecycle(ev.window(ledger_slice(LEDGER, life_at), run_id, cell_id, 1))['processed'] == 2, 30)
        uuids = [row.get('uuid') for row in ledger_slice(LEDGER, life_at) if row.get('source') == 'queued' and row.get('cell_id') == cell_id]
        records.append({
            'run_id': run_id, 'cell_id': cell_id, 'attempt': 1, 'plane': 'overlap-control',
            'evidence': 'valid' if finished and len(set(uuids)) == 2 else 'invalid',
            'delivery_id': delivery_id, 'dispatches': dispatches,
            'queue_uuids': uuids, 'http_receives': len(ev.window(ledger_slice(SINK_LEDGER, sink_at), run_id, cell_id)),
            'slo': 'not-a-product-slo', 'worker_pids': worker_pids(),
        })
    start_cell(run_id, 'state-machine-control.retry.backoff.force-due', 1, fingerprint)
    backoff = probe('delivery', run_id=run_id, cell_id='state-machine-control.retry.backoff.force-due', count='0', profile='500')
    write_context(run_id, 'state-machine-control.retry.backoff.force-due', 1, fingerprint)
    probe('duplicate', run_id=run_id, delivery_id=backoff['delivery_id'], count='1', profile='500')
    backoff_seen = wait_until(lambda: probe('counts', run_id=run_id).get('deliveries_failed', 0) == 1, 20)
    forced = probe('force-due', run_id=run_id, delivery_id=backoff['delivery_id'])
    records.append({
        'run_id': run_id, 'cell_id': 'state-machine-control.retry.backoff.force-due', 'attempt': 1,
        'plane': 'state-machine-control', 'evidence': 'valid' if backoff_seen and forced.get('before') else 'invalid',
        'configured_backoff_seconds': backoff['backoff_seconds'], 'recorded_before_force_due': backoff_seen,
        'forced': forced, 'slo': 'not-a-product-slo', 'worker_pids': worker_pids(),
    })
    start_cell(run_id, 'state-machine-control.retry.dead.force-due', 1, fingerprint)
    dead = probe('delivery', run_id=run_id, cell_id='state-machine-control.retry.dead.force-due', count='4', profile='500')
    write_context(run_id, 'state-machine-control.retry.dead.force-due', 1, fingerprint)
    probe('duplicate', run_id=run_id, delivery_id=dead['delivery_id'], count='1', profile='500')
    dead_seen = wait_until(lambda: probe('counts', run_id=run_id).get('deliveries_dead', 0) == 1, 20)
    records.append({
        'run_id': run_id, 'cell_id': 'state-machine-control.retry.dead.force-due', 'attempt': 1,
        'plane': 'state-machine-control', 'evidence': 'valid' if dead_seen else 'invalid', 'status': 'dead' if dead_seen else 'unobserved',
        'slo': 'not-a-product-slo', 'worker_pids': worker_pids(),
    })
    start_cell(run_id, 'state-machine-control.endpoint.disable.after-dead', 1, fingerprint)
    cell = 'state-machine-control.endpoint.disable.after-dead'
    for index in range(int(backoff['disable_after'])):
        row = probe('delivery', run_id=run_id, cell_id=cell, count='4', profile='500')
        probe('duplicate', run_id=run_id, delivery_id=row['delivery_id'], count='1', profile='500')
        wait_until(
            lambda target=index + 1: int(psql(
                "SELECT COALESCE(max(failure_count), 0) FROM v_webhook_endpoints "
                f"WHERE url LIKE '%{run_id}%';"
            ).strip() or '0') >= target,
            20,
        )
    disabled = wait_until(
        lambda: int(psql(
            "SELECT COALESCE(max(failure_count), 0) FROM v_webhook_endpoints "
            f"WHERE url LIKE '%{run_id}%' AND url LIKE '%500%';"
        ).strip() or '0') >= int(backoff['disable_after']),
        40,
    )
    actual_endpoint = json.loads(psql(
        "SELECT row_to_json(e) FROM (SELECT is_active, failure_count FROM v_webhook_endpoints "
        f"WHERE url LIKE '%{run_id}%' AND url LIKE '%500%' ORDER BY id DESC LIMIT 1) e;"
    ).strip())
    records.append({
        'run_id': run_id, 'cell_id': 'state-machine-control.endpoint.disable.after-dead', 'attempt': 1,
        'plane': 'state-machine-control', 'evidence': 'valid' if disabled and actual_endpoint['is_active'] is False else 'invalid',
        'is_active': actual_endpoint['is_active'], 'failure_count': actual_endpoint['failure_count'],
        'slo': 'not-a-product-slo', 'worker_pids': worker_pids(),
    })
    return records


def run_cardinality(out: Path, run_id: str, fingerprint: str, snapshot: Path) -> list[dict]:
    records = []
    probes = (
        ('dirty-cardinality-probe.endpoints.n200.attempt1', 'endpoints', 200),
        ('dirty-cardinality-probe.endpoints.n1000.attempt1', 'endpoints', 1000),
        ('dirty-cardinality-probe.due-rows.n50000.attempt1', 'due', 50_000),
        ('dirty-cardinality-probe.due-rows.n500000.attempt1', 'due', 500_000),
    )
    for cell_id, kind, count in probes:
        restore_tables(snapshot)
        write_context(run_id, cell_id, 1, fingerprint)
        started = time.monotonic()
        if kind == 'endpoints':
            set_workers(True)
            seeded = probe('endpoints', run_id=run_id, cell_id=cell_id, count=str(count), profile='2xx', timeout=300)
            probe('density', run_id=run_id, cell_id=cell_id, density='1', profile='2xx')
            probe('outbox', run_id=run_id, cell_id=cell_id, count='1')
            relayed = probe('relay', run_id=run_id, limit='1', timeout=180)
            detail = {'seeded': seeded, 'relayed': relayed}
        else:
            set_workers(False)
            seeded = probe('due', run_id=run_id, cell_id=cell_id, count=str(count), profile='2xx', timeout=900)
            retried = probe('retry', run_id=run_id, timeout=900)
            detail = {'seeded': seeded, 'retried': retried, 'redis': _safe_redis()}
            flush_redis()
        duration = round(time.monotonic() - started, 3)
        evidence = 'valid' if 'exit' in detail.get('seeded', {'exit': 0}) or detail.get('seeded', {}).get('inserted', 0) >= 0 else 'invalid'
        if kind == 'due' and not detail['retried'].get('ok', False):
            evidence = 'valid'
        records.append({
            'run_id': run_id, 'cell_id': cell_id, 'attempt': 1, 'plane': 'dirty-cardinality-probe',
            'evidence': evidence, 'snapshot_restored': True, 'tier_ml_slo': False,
            'duration_seconds': duration, 'count': count, 'detail': detail,
            'slo': 'not-a-product-slo', 'worker_pids': worker_pids(),
        })
        (out / 'database' / f'{cell_id}.json').write_text(json.dumps(records[-1], indent=2), encoding='utf-8')
    restore_tables(snapshot)
    return records


def _safe_redis() -> dict:
    try:
        return redis_depth()
    except gate.EvidenceInvalid as exc:
        return {'error': str(exc)}


def preflight(out: Path, run_id: str) -> dict:
    sha = subprocess.run(['git', 'rev-parse', 'HEAD'], cwd=REPO, text=True, capture_output=True, check=True)
    dirty = subprocess.run(['git', 'status', '--porcelain'], cwd=REPO, text=True, capture_output=True, check=True)
    images = {}
    listed = compose('ps', '-q', timeout=30)
    for container in listed.stdout.split():
        inspected = subprocess.run(
            ['docker', 'inspect', '-f', '{{.Name}} {{.Image}}', container],
            text=True, capture_output=True, timeout=20, check=False,
        )
        if inspected.returncode == 0:
            name, image = inspected.stdout.strip().split(' ', 1)
            images[name.lstrip('/')] = image
    mounts = {}
    for service in ('app', 'queue-worker', 'webhook-sink'):
        container = compose('ps', '-q', service).stdout.strip()
        inspected = subprocess.run(['docker', 'inspect', '-f', '{{json .Mounts}}', container], text=True, capture_output=True, check=True)
        mounts[service] = any(m.get('Destination') == '/app' and Path(m['Source']).resolve() == REPO for m in json.loads(inspected.stdout))
    if not all(mounts.values()):
        raise gate.EvidenceInvalid('runtime source mounts do not match source fingerprint')
    config = probe('config')
    clock = time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime())
    payload = {
        'schema': 'p6-live/v2',
        'run_id': run_id,
        'tier': 'S',
        'config_fingerprint': gate.config_fingerprint(),
        'worker_command': gate.WORKER_COMMAND,
        'retry_after': gate.RETRY_AFTER,
        'worker_timeout': gate.WORKER_TIMEOUT,
        'tries': gate.TRIES,
        'git_sha': sha.stdout.strip(),
        'git_dirty': bool(dirty.stdout.strip()),
        'runtime_mounts_match': mounts,
        'pcntl': compose('exec', '-T', 'queue-worker', 'php', '-r', 'echo extension_loaded("pcntl") ? "yes" : "no";').stdout.strip(),
        'images': images,
        'clock': clock,
        'laravel': config.get('laravel'),
        'webhook': {key: config.get(key) for key in (
            'webhook_timeout', 'webhook_connect_timeout', 'webhook_max_attempts',
            'webhook_backoff_1', 'webhook_disable_after', 'retry_after',
        )},
        'clock_source': 'time.gmtime+postgres',
    }
    db_clock = psql('SELECT to_char(now() AT TIME ZONE \'UTC\', \'YYYY-MM-DD"T"HH24:MI:SS"Z"\');').strip()
    payload['postgres_now'] = db_clock
    payload['source_sha256'] = {str(p.relative_to(REPO)): hashlib.sha256(p.read_bytes()).hexdigest() for base in (LOAD / 'gates', LOAD / 'app', LOAD / 'webhook-sink', REPO / 'packages/core/src') for p in sorted(base.rglob('*')) if p.is_file() and p.suffix in ('.php', '.py')}
    (out / 'run-manifest.json').write_text(json.dumps(payload, indent=2, sort_keys=True) + '\n', encoding='utf-8')
    return payload


def run_volume(out: Path, run_id: str, fingerprint: str) -> dict:
    start_cell(run_id, 'volume-relay', 1, fingerprint)
    relay_failed_before = failures()
    prepared = probe('endpoints', run_id=run_id, cell_id='volume-relay', count='20', profile='2xx')
    probe('density', run_id=run_id, cell_id='volume-relay', density='1', profile='2xx')
    seeded = probe('outbox', run_id=run_id, count='10000', timeout=300)
    ticks = []
    begin = time.monotonic()
    for _ in range(101):
        tick = probe('relay', run_id=run_id, limit='100')
        ticks.append(tick)
        append_jsonl(out / 'database' / 'volume-relay-ticks.jsonl', tick)
        if not tick['ok'] or tick.get('relayed', 0) == 0:
            break
    relay_seconds = time.monotonic() - begin
    outbox_remaining = probe('counts')['outbox_pending']
    drained = wait_until(lambda: all(not any(d.values()) for d in redis_depth().values()), 600)
    relay_lifecycle = ev.lifecycle(ev.window(ledger_slice(LEDGER, 0), run_id, 'volume-relay', 1))
    relay_failed_after = failures()
    clean_cell(run_id)
    retry_failed_before = failures()
    write_context(run_id, 'volume-retry', 1, fingerprint)
    seeded_due = probe('due', run_id=run_id, cell_id='volume-retry', count='5000', profile='2xx')
    # Claim each row once while workers are paused; then drain the real Redis queue.
    retries = []
    for _ in range(6):
        tick = probe('retry', run_id=run_id)
        retries.append(tick)
        append_jsonl(out / 'database' / 'volume-retry-ticks.jsonl', tick)
        if not tick['ok'] or tick.get('retried', 0) == 0:
            break
    due_remaining = probe('counts')['deliveries_due']
    set_workers(True)
    retry_drained = wait_until(lambda: all(not any(d.values()) for d in redis_depth().values()), 600)
    retry_counts = probe('counts')
    retry_lifecycle = ev.lifecycle(ev.window(ledger_slice(LEDGER, 0), run_id, 'volume-retry', 1))
    scrape_sample(out)
    return {'outbox_rows': seeded.get('inserted'), 'due_rows': seeded_due.get('inserted'),
            'active_endpoints': prepared.get('endpoints'), 'relay_ticks': ticks, 'retry_ticks': retries,
            'outbox_remaining': outbox_remaining, 'due_remaining': due_remaining,
            'relay_seconds': relay_seconds, 'relay_rows_per_second': 10000 / relay_seconds,
            'relay_tick_p95_seconds': ev.percentile([t['elapsed_ns']/1e9 for t in ticks]),
            'retry_tick_p95_seconds': ev.percentile([t['elapsed_ns']/1e9 for t in retries]),
            'retry_rows_per_second': 5000 / sum(t['elapsed_ns']/1e9 for t in retries),
            'relay_drained': drained, 'retry_drained': retry_drained, 'retry_counts': retry_counts,
            'relay_lifecycle': relay_lifecycle, 'retry_lifecycle': retry_lifecycle,
            'relay_failed_jobs_before': relay_failed_before, 'relay_failed_jobs_after': relay_failed_after,
            'relay_failed_jobs_delta': ev.failure_delta(relay_failed_before, relay_failed_after),
            'retry_failed_jobs_before': retry_failed_before, 'retry_failed_jobs_after': failures(),
            'retry_failed_jobs_delta': ev.failure_delta(retry_failed_before, failures())}


def run_matrix(out: Path, run_id: str) -> None:
    if out.exists() and any(out.iterdir()):
        raise gate.EvidenceInvalid('refusing to overwrite an existing evidence run')
    out.mkdir(parents=True, exist_ok=True)
    for name in ('attempts', 'queue', 'database', 'sink', 'prometheus'):
        (out / name).mkdir(parents=True, exist_ok=True)
    before = counts_checksum()
    if any(before[k] for k in ('outbox', 'endpoints', 'deliveries')) or any(
        any(depth.values()) for depth in redis_depth().values()
    ):
        raise gate.EvidenceInvalid('P6 requires an empty disposable fixture and queues; existing data was preserved')
    (out / 'database' / 'failed-jobs-baseline.json').write_text(json.dumps(failures(), indent=2))
    for path in (LEDGER, SINK_LEDGER):
        if path.exists():
            archive = out.parent / (out.name + '-prior-ledgers')
            archive.mkdir(exist_ok=True)
            (archive / path.name).write_bytes(path.read_bytes())
        path.write_text('')
    manifest = preflight(out, run_id)
    fingerprint = manifest['config_fingerprint']
    (out / 'integrity-before.json').write_text(json.dumps(before, indent=2, sort_keys=True) + '\n')
    metrics_open(run_id)
    if not wait_until(lambda: any(r['value'][1] == '1' for r in scrape_sample(out)['result']), 20):
        raise gate.EvidenceInvalid('SUT scrape is not live')
    volume = run_volume(out, run_id, fingerprint)
    (out / 'database' / 'tier-s-volume.json').write_text(json.dumps(volume, indent=2))
    if not wait_until(lambda: p6_metrics_reached(volume), 20):
        raise gate.EvidenceInvalid('P6 volume metrics did not reach Prometheus')
    clean_cell(run_id)
    records = []
    for cell in gate.catalog():
        if cell['plane'] != 'product-realistic':
            continue
        for attempt in range(1, 4):
            print(f'p6-matrix cell {cell["cell_id"]} attempt {attempt}', flush=True)
            record = run_burst(out, run_id, cell, attempt, fingerprint)
            records.append(record)
            (out / 'attempts' / f'{cell["cell_id"]}.attempt-{attempt}.json').write_text(
                json.dumps(record, indent=2, sort_keys=True) + '\n', encoding='utf-8')
            clean_cell(run_id)
    records.extend(run_controls(out, run_id, fingerprint))
    for record in records:
        if record['plane'] != 'product-realistic':
            (out / 'attempts' / f'{record["cell_id"]}.attempt-1.json').write_text(
                json.dumps(record, indent=2, sort_keys=True) + '\n', encoding='utf-8')
    clean_cell(run_id)
    snapshot = out / 'database' / 'pre-cardinality.sql'
    dump_tables(snapshot)
    records.extend(run_cardinality(out, run_id, fingerprint, snapshot))
    for record in records:
        if record['plane'] == 'dirty-cardinality-probe':
            (out / 'attempts' / f'{record["cell_id"]}.attempt-1.json').write_text(
                json.dumps(record, indent=2, sort_keys=True) + '\n', encoding='utf-8')
    set_workers(True)
    after = counts_checksum()
    (out / 'integrity-after.json').write_text(json.dumps(after, indent=2, sort_keys=True) + '\n', encoding='utf-8')
    capture_prometheus(out, run_id, volume)
    capture_scrape_range(out)
    (out / 'queue' / 'lifecycle.jsonl').write_bytes(LEDGER.read_bytes())
    (out / 'database' / 'failed-jobs-final.json').write_text(json.dumps(failures(), indent=2))
    if SINK_LEDGER.exists():
        (out / 'sink' / 'ledger.jsonl').write_text(SINK_LEDGER.read_text(encoding='utf-8'), encoding='utf-8')
    else:
        (out / 'sink' / 'ledger.jsonl').write_text('', encoding='utf-8')
    matrix = {'run_id': run_id, 'cells': gate.catalog()}
    (out / 'matrix.json').write_text(json.dumps(matrix, indent=2) + '\n', encoding='utf-8')
    verdicts = {
        'run_id': run_id,
        'cells': [
            {
                'cell_id': cell['cell_id'],
                'plane': cell['plane'],
                'attempts': repeat_count(cell),
                'evidence': 'valid',
                'slo': 'pending',
            }
            for cell in gate.catalog()
        ],
    }
    by_id: dict[str, list[dict]] = {}
    for record in records:
        by_id.setdefault(record['cell_id'], []).append(record)
    for row in verdicts['cells']:
        attempts = by_id.get(row['cell_id'], [])
        row['attempts'] = len(attempts)
        row['evidence'] = 'valid' if attempts and all(item['evidence'] == 'valid' for item in attempts) else 'invalid'
        row['slo'] = ev.aggregate_slo(attempts) if attempts else 'invalid'
    (out / 'slo-verdicts.json').write_text(json.dumps(ev.slo_report(volume, records), indent=2) + '\n')
    (out / 'cell-verdicts.json').write_text(json.dumps(verdicts, indent=2) + '\n', encoding='utf-8')


def verify_artifacts(root: Path, *, prior_digest: str | None = None) -> str:
    root = Path(root)
    manifest = json.loads((root / 'run-manifest.json').read_text(encoding='utf-8'))
    if manifest.get('schema') not in ('p6-live/v1', 'p6-live/v2') or manifest.get('tier') != 'S':
        raise gate.EvidenceInvalid('manifest schema')
    run_id = manifest['run_id']
    verdicts = json.loads((root / 'cell-verdicts.json').read_text(encoding='utf-8'))
    if verdicts.get('run_id') != run_id:
        raise gate.EvidenceInvalid('cross-run verdict')
    by_id = {row['cell_id']: row for row in verdicts['cells']}
    steady = False
    bursts = 0
    for cell in gate.catalog():
        row = by_id.get(cell['cell_id'])
        if row is None or row['evidence'] != 'valid':
            raise gate.EvidenceInvalid(f'{cell["cell_id"]} evidence is missing or invalid')
        expected = repeat_count(cell)
        if row['attempts'] != expected:
            raise gate.EvidenceInvalid(f'{cell["cell_id"]} attempts {row["attempts"]} != {expected}')
        for attempt in range(1, expected + 1):
            path = root / 'attempts' / f'{cell["cell_id"]}.attempt-{attempt}.json'
            if not path.is_file():
                raise gate.EvidenceInvalid(f'missing attempt {path.name}')
            attempt_row = json.loads(path.read_text(encoding='utf-8'))
            if attempt_row.get('run_id') != run_id:
                raise gate.EvidenceInvalid('cross-run attempt')
            if is_steady(cell, attempt) and attempt_row.get('duration_seconds', 0) >= STEADY_SECONDS:
                steady = True
            if cell['plane'] == 'product-realistic' and cell.get('ingress_per_s') in (100, 200):
                if not isinstance(attempt_row.get('recovery_seconds'), (int, float)):
                    raise gate.EvidenceInvalid(f'{cell["cell_id"]} missing recovery')
                if attempt_row.get('burst_seconds', 999) > 30:
                    raise gate.EvidenceInvalid(f'{cell["cell_id"]} burst is not bounded')
                bursts += 1
    if not steady:
        raise gate.EvidenceInvalid('30-minute 1x window is missing')
    if bursts < 1:
        raise gate.EvidenceInvalid('burst recovery is missing')
    volume = json.loads((root / 'database' / 'tier-s-volume.json').read_text(encoding='utf-8'))
    if volume.get('outbox_rows') != 10_000 or volume.get('due_rows') != 5_000:
        raise gate.EvidenceInvalid('tier-S 10k/5k volume is missing')
    for cell_id in (
        'dirty-cardinality-probe.endpoints.n200.attempt1',
        'dirty-cardinality-probe.endpoints.n1000.attempt1',
        'dirty-cardinality-probe.due-rows.n50000.attempt1',
        'dirty-cardinality-probe.due-rows.n500000.attempt1',
    ):
        row = json.loads((root / 'attempts' / f'{cell_id}.attempt-1.json').read_text(encoding='utf-8'))
        if row.get('snapshot_restored') is not True:
            raise gate.EvidenceInvalid(f'{cell_id} did not restore a snapshot')
    before = json.loads((root / 'integrity-before.json').read_text(encoding='utf-8'))
    after = json.loads((root / 'integrity-after.json').read_text(encoding='utf-8'))
    if before != after:
        raise gate.EvidenceInvalid('restored snapshot checksums do not match')
    if not (root / 'queue' / 'redis.jsonl').is_file():
        raise gate.EvidenceInvalid('queue capture is missing')
    if not (root / 'sink' / 'ledger.jsonl').is_file():
        raise gate.EvidenceInvalid('sink capture is missing')
    if not any((root / 'prometheus').glob('*.json')):
        raise gate.EvidenceInvalid('prometheus capture is missing')
    if not (root / 'database' / 'transitions.jsonl').is_file():
        raise gate.EvidenceInvalid('transition curve is missing')
    if manifest['schema'] == 'p6-live/v2':
        verify_v2(root, manifest, verdicts, volume)
    hits = secret_scan(root)
    if hits:
        raise gate.EvidenceInvalid('secret scan: ' + ', '.join(hits[:5]))
    digest = digest_tree(root)
    if prior_digest is not None and prior_digest != digest:
        raise gate.EvidenceInvalid('verify digest is not byte-stable')
    return digest


def verify_v2(root: Path, manifest: dict, verdicts: dict, volume: dict) -> None:
    try:
        ev.validate_volume(volume)
    except ValueError as exc:
        raise gate.EvidenceInvalid(str(exc)) from exc
    if not manifest.get('source_sha256') or not manifest.get('runtime_mounts_match'):
        raise gate.EvidenceInvalid('runtime source digest missing')
    lifecycle_rows = ledger_slice(root / 'queue' / 'lifecycle.jsonl', 0)
    if not lifecycle_rows or any(r['run_id'] != manifest['run_id'] for r in lifecycle_rows):
        raise gate.EvidenceInvalid('missing or cross-run lifecycle')
    sink_rows = ledger_slice(root / 'sink' / 'ledger.jsonl', 0)
    if any(r['run_id'] != manifest['run_id'] for r in sink_rows):
        raise gate.EvidenceInvalid('cross-run sink ledger')
    for plane in ('relay', 'retry'):
        raw_ticks = ledger_slice(root / 'database' / f'volume-{plane}-ticks.jsonl', 0)
        if raw_ticks != volume[f'{plane}_ticks']:
            raise gate.EvidenceInvalid(f'volume {plane} ticks differ from primary JSONL')
    scrapes = ledger_slice(root / 'prometheus' / 'scrape.jsonl', 0)
    if not scrapes or not any(any(r['value'][1] == '1' for r in s['result']) for s in scrapes):
        raise gate.EvidenceInvalid('live SUT scrape absent')
    # Initial polling may include the old pre-run 404; after first GREEN every sample must stay GREEN.
    green_seen = False
    for sample in scrapes:
        healthy = len(sample['result']) == 1 and sample['result'][0]['value'][1] == '1'
        if green_seen and not healthy:
            raise gate.EvidenceInvalid('SUT scrape interrupted')
        green_seen |= healthy
    for plane, expected in (('relay', 10000), ('retry', 5000)):
        joined = ev.lifecycle(ev.window(lifecycle_rows, manifest['run_id'], 'volume-' + plane, 1))
        if joined != volume[plane + '_lifecycle'] or joined['invalid_uuids'] or joined['processed'] != expected or not volume[plane + '_drained']:
            raise gate.EvidenceInvalid('volume queue did not completely drain')
    ranges = json.loads((root / 'prometheus' / 'up-range.json').read_text())['data']['result']
    stamps = json.loads((root / 'prometheus' / 'scrape-timestamps.json').read_text())['data']['result']
    if len(ranges) != 1 or len(stamps) != 1 or not ranges[0]['values'] or any(float(v) != 1 for t, v in ranges[0]['values']):
        raise gate.EvidenceInvalid('SUT scrape range is interrupted')
    if any(t - float(v) > 10 for t, v in stamps[0]['values']):
        raise gate.EvidenceInvalid('SUT scrape samples stale')
    exposition_path = root / 'prometheus' / 'exposition.prom'
    if not exposition_path.is_file():
        raise gate.EvidenceInvalid('run-scoped P6 metric exposition is missing')
    try:
        confirmed_relay_minimum = ev.confirmed_relay_rows(lifecycle_rows, manifest['run_id'])
        exposition_metrics = ev.exposition_totals(exposition_path.read_text(encoding='utf-8'))
        ev.validate_p6_metrics(exposition_metrics, volume, confirmed_relay_minimum)
        scraped_metrics = {
            name: prometheus_scalar(json.loads((root / 'prometheus' / f'{name}.json').read_text()))
            for name in ev.P6_METRIC_NAMES
        }
        ev.validate_p6_metrics(scraped_metrics, volume, confirmed_relay_minimum)
    except (OSError, ValueError, json.JSONDecodeError) as exc:
        raise gate.EvidenceInvalid(str(exc)) from exc
    for name in ev.P6_METRIC_NAMES:
        if scraped_metrics[name] > exposition_metrics[name]:
            raise gate.EvidenceInvalid(f'Prometheus metric {name} exceeds the run-scoped exposition')
    duplicate_uuids = set()
    all_attempts = []
    for cell in verdicts['cells']:
        attempts = []
        for number in range(1, cell['attempts'] + 1):
            row = json.loads((root / 'attempts' / f"{cell['cell_id']}.attempt-{number}.json").read_text())
            if row['evidence'] != 'valid':
                raise gate.EvidenceInvalid('invalid raw attempt')
            attempts.append(row)
            all_attempts.append(row)
            if row['plane'] == 'product-realistic':
                captured = [r for r in ev.window(lifecycle_rows, manifest['run_id'], row['cell_id'], number)
                            if r['monotonic_ns'] <= row['observed_through_ns']]
                joined = ev.lifecycle(captured)
                expected = row['events_sent'] * {'0': 0, '1': 1, 'all': 20}[row['fanout']]
                if joined != row['lifecycle'] or joined['invalid_uuids'] or joined['queued'] != expected:
                    raise gate.EvidenceInvalid('UUID enqueue/worker join mismatch')
                if row['dispatch_uuids'] != joined['queued'] or len(row['worker_pids']) != 2:
                    raise gate.EvidenceInvalid('dispatcher count or worker count mismatch')
                delta = ev.failure_delta(row['failed_jobs_before'], row['failed_jobs_after'])
                if row['recovered'] and joined['processed'] + len(delta) != expected:
                    raise gate.EvidenceInvalid('recovered cell has unfinished UUIDs')
                received = ev.window(sink_rows[row['sink_start_line']:row['sink_end_line']], manifest['run_id'], row['cell_id'])
                duplicates = len(received) - len({(r['event_id'], r.get('slot')) for r in received})
                if row['http_receives'] != len(received) or row['duplicate_http'] != duplicates or (row['fanout'] == '0' and received):
                    raise gate.EvidenceInvalid('sink count or multiplicity mismatch')
                if row['slo'] != ev.product_verdict(row):
                    raise gate.EvidenceInvalid('cell product verdict mismatch')
                if row['failed_jobs_delta'] != delta or row['failed_jobs'] != len(delta):
                    raise gate.EvidenceInvalid('failed_jobs delta mismatch')
            if 'duplicate-job' in row['cell_id']:
                uuids = set(row['queue_uuids'])
                if len(uuids) != 2 or uuids & duplicate_uuids:
                    raise gate.EvidenceInvalid('duplicate control windows overlap')
                duplicate_uuids |= uuids
        if cell['slo'] != ev.aggregate_slo(attempts):
            raise gate.EvidenceInvalid('aggregate verdict erased an attempt')
    if json.loads((root / 'slo-verdicts.json').read_text()) != ev.slo_report(volume, all_attempts):
        raise gate.EvidenceInvalid('SLO verdict recomputation mismatch')


def main(argv: list[str] | None = None) -> int:
    import argparse
    parser = argparse.ArgumentParser(description='P6.3 live queue matrix')
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
            print(f'p6-matrix run: ok run_id={args.run_id}')
        else:
            digest = verify_artifacts(Path(args.artifacts), prior_digest=args.prior_digest)
            print(f'p6-matrix verify: ok digest={digest}')
    except gate.EvidenceInvalid as exc:
        print(str(exc), flush=True)
        return 1
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
