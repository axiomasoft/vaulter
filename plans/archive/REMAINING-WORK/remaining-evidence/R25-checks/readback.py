#!/usr/bin/env python3
"""Offline R25 readback. Print observations; never rewrite a historical verdict.

Large primary ledgers live in the committed archives, not in the small browsable
trees. Extract only into a temporary directory and check their original digests.
No telemetry overlay, Docker, package execution or live data is used.
"""

from collections import defaultdict
import hashlib
import json
from math import ceil, isclose
from pathlib import Path
import subprocess
import sys
import tarfile
import tempfile

REPO = Path(__file__).resolve().parents[3]
sys.path.insert(0, str(REPO / 'load/gates'))
import p6_live_matrix as live

RUNS = (
    ('P6', None, 'd48c8ffd07fb11847cff3a5f80d4c56cfe9b2e3459ed78ce251ab9a9b66f3766'),
    ('R01', 'R01-P6-721dd40b-ce8e-410a-8ecf-58873644f038.tar.gz', '48f8d50e7f6a7e4910da909518cb3be7884507e75c7166ebd54e2c5a7c7b50b6'),
    ('R09-first', 'R09-P6-3df3b9e1-32b8-4e15-8b8f-c4efffe28f52.tar.gz', '7bac6725d3af18ac57789e7f70f1fdc3d78b4f28fdc051ff7de2dda39124c270'),
    ('R09-follow-up', 'R09-P6-bc199ced-5b0f-4e77-b5ca-1a73055c0bfa.tar.gz', 'bbfa77d90edc2664a158b37aa7cab0a8a936c7af8bfe90cefac3e745a184a0e6'),
    ('R10', 'R10-P6-56823709-fe47-4173-b965-dc51ea523bcb.tar.gz', '100d368f90e4c985a40fb4bb624128bda8ee6e483727dc4a7d4db302c9a2dad7'),
)


def read(path):
    return json.loads(path.read_text())


def records(path):
    with path.open() as stream:
        for line in stream:
            if line.strip():
                yield json.loads(line)


def inspect(root, label, digest):
    assert live.digest_tree(root) == digest, f'{label}: raw tree changed'
    manifest = read(root / 'run-manifest.json')
    verdicts = read(root / 'cell-verdicts.json')
    run_id = manifest['run_id']
    assert verdicts['run_id'] == run_id
    primary = defaultdict(list)
    sink = []
    redis = {}
    if label not in ('P6', 'R01'):
        for row in records(root / 'queue/lifecycle.jsonl'):
            assert row['run_id'] == run_id
            if row['cell_id'].startswith('product-realistic.'):
                primary[(row['cell_id'], row['attempt'])].append(row)
        sink = list(records(root / 'sink/ledger.jsonl'))
        assert all(row['run_id'] == run_id for row in sink)
        for row in records(root / 'queue/redis.jsonl'):
            redis[(row['cell_id'], row['attempt'], row['queue'])] = {
                key: row[key] for key in ('pending', 'delayed', 'reserved')}
    observations = []
    all_product = []
    for cell in verdicts['cells']:
        if cell['plane'] != 'product-realistic':
            continue
        attempts = []
        for number in range(1, cell['attempts'] + 1):
            path = root / 'attempts' / f"{cell['cell_id']}.attempt-{number}.json"
            row = read(path)
            assert (row['run_id'], row['cell_id'], row['attempt']) == (run_id, cell['cell_id'], number)
            all_product.append(row)
            if label == 'P6':
                assert row['failed_jobs'] == 2 and row['dispatch_uuids'] == 0
            elif label != 'R01':
                assert row['evidence'] == 'valid'
                jobs = defaultdict(lambda: defaultdict(list))
                for entry in primary[(cell['cell_id'], number)]:
                    if entry['monotonic_ns'] <= row['observed_through_ns']:
                        assert entry['class'] == 'Vaulter\\Jobs\\Webhooks\\DeliverWebhook'
                        jobs[entry['uuid']][entry['source']].append(entry['monotonic_ns'])
                for sources in jobs.values():
                    assert len(sources['queueing']) == len(sources['queued']) == 1
                    assert sources['queued'][0] >= sources['queueing'][0]
                    assert len(sources['processing']) <= 1 and len(sources['processed']) <= 1
                    if sources['processing']:
                        assert sources['processing'][0] >= sources['queueing'][0]
                    if sources['processed']:
                        assert sources['processing'] and sources['processed'][0] >= sources['processing'][0]
                counts = {key: sum(len(sources[key]) for sources in jobs.values())
                          for key in ('queued', 'processing', 'processed')}
                assert all(counts[key] == row['lifecycle'][key] for key in counts)
                waits = [(sources['processing'][0] - sources['queueing'][0]) / 1e9
                         for sources in jobs.values() if sources['processing']]
                processes = [(sources['processed'][0] - sources['processing'][0]) / 1e9
                             for sources in jobs.values() if sources['processed']]
                for samples, field in ((waits, 'queue_wait_p95_seconds'), (processes, 'process_p95_seconds')):
                    observed = sorted(samples)[ceil(.95 * len(samples)) - 1] if samples else None
                    stored = row['lifecycle'][field]
                    assert (observed is None and stored is None) or (
                        observed is not None and stored is not None and isclose(observed, stored))
                assert isclose(row['jobs_per_second'], counts['processed'] / row['duration_seconds'])
                assert counts['queued'] == row['events_sent'] * {'0': 0, '1': 1, 'all': 20}[row['fanout']]
                assert not row['lifecycle']['invalid_uuids']
                before = {job['uuid'] for job in row['failed_jobs_before']}
                delta = [job for job in row['failed_jobs_after'] if job['uuid'] not in before]
                assert row['failed_jobs_delta'] == delta == [] and row['failed_jobs'] == 0
                receives = [entry for entry in sink[row['sink_start_line']:row['sink_end_line']]
                            if entry['cell_id'] == cell['cell_id']]
                pairs = {(entry['event_id'], entry.get('slot')) for entry in receives}
                assert len(receives) == row['http_receives'] == len(pairs) and row['duplicate_http'] == 0
                assert all(redis[(cell['cell_id'], number, queue)] == depth
                           for queue, depth in row['redis_after'].items())
                expected = 'red' if not row['recovered'] or (row['backlog_slope'] or 0) > 0 else 'unevaluated'
                assert row['slo'] == expected
            attempts.append({
                'attempt': number, 'slo': row['slo'], 'recovered': row['recovered'],
                'failed_jobs': row['failed_jobs'], 'business_failed': row['business_failed'],
                'business_dead': row['business_dead'], 'redis_after': row['redis_after'],
                'lifecycle': {key: row.get('lifecycle', {}).get(key) for key in ('queued', 'processing', 'processed')},
                'http_receives': row['http_receives'], 'duration_seconds': row['duration_seconds'],
                'attempt_file': f"attempts/{path.name}",
            })
        assert (cell['slo'] == 'red') == any(row['slo'] == 'red' for row in attempts)
        if cell['slo'] != 'red':
            continue
        if label == 'P6':
            route = 'harness correction R09 completed; historical RED retained; original job identities unknown'
        elif label == 'R01':
            route = 'rejected evidence family; harness correction R09 completed; not accepted product evidence'
        else:
            assert all(row['recovered'] is False for row in attempts if row['slo'] == 'red')
            route = 'retained RED; R26 remeasurement after owner resumption/lab admission; no localized package defect'
        observations.append({'cell_id': cell['cell_id'], 'route': route, 'attempts': attempts})
    red_attempts = sum(row['slo'] == 'red' for row in all_product)
    expected = {'P6': (36, 108), 'R01': (19, None), 'R09-first': (9, 25), 'R09-follow-up': (8, 24), 'R10': (9, 26)}
    cells_expected, attempts_expected = expected[label]
    assert len(observations) == cells_expected
    assert attempts_expected is None or red_attempts == attempts_expected
    result = {'label': label, 'run_id': run_id, 'raw_tree_sha256': digest,
              'red_cells': len(observations), 'red_attempts': red_attempts, 'classification': observations}
    result['historical_runtime'] = {key: manifest.get(key) for key in (
        'schema', 'git_sha', 'git_dirty', 'config_fingerprint', 'tier', 'worker_command', 'pcntl')}
    result['historical_source_map_sha256'] = hashlib.sha256(json.dumps(
        manifest.get('source_sha256', {}), sort_keys=True, separators=(',', ':')).encode()).hexdigest()
    if label not in ('P6', 'R01'):
        slo = read(root / 'slo-verdicts.json')
        assert slo['SLO-61'] == 'red'
        result['stored_slo'] = slo
        latency = [row for row in all_product if row['cell_id'] == slo['delivery_limiting_cell']]
        assert len(latency) == 3
        assert min(latency, key=lambda row: row['jobs_per_second'])['attempt'] == slo['delivery_limiting_attempt']
        assert isclose(min(row['jobs_per_second'] for row in latency), slo['delivery_min_jobs_per_second'])
        assert all(row['jobs_per_second'] < 50 and row['lifecycle']['queue_wait_p95_seconds'] > 5 for row in latency)
        result['delivery_attempts'] = [{key: row[key] for key in (
            'cell_id', 'attempt', 'jobs_per_second', 'recovered', 'duration_seconds')}
            | {'queue_wait_p95_seconds': row['lifecycle']['queue_wait_p95_seconds'],
               'process_p95_seconds': row['lifecycle']['process_p95_seconds']} for row in latency]
    command = [sys.executable, '-B', str(REPO / 'load/gates/p6_live_matrix.py'),
               'verify', '--artifacts', str(root), '--prior-digest', digest]
    verified = subprocess.run(command, cwd=REPO, capture_output=True, text=True, check=False)
    result['current_verifier'] = {'command': command, 'exit': verified.returncode,
                                  'stdout': verified.stdout, 'stderr': verified.stderr}
    assert result['current_verifier']['exit'] == (0 if label == 'P6' else 1)
    assert live.digest_tree(root) == digest, f'{label}: readback mutated raw tree'
    return result


def main():
    results = []
    for label, archive_name, digest in RUNS:
        if archive_name is None:
            results.append(inspect(REPO / 'plans/VLT-LOAD/artifacts/P6', label, digest))
            continue
        archive = REPO / 'plans/remaining-evidence' / archive_name
        before = hashlib.sha256(archive.read_bytes()).hexdigest()
        with tempfile.TemporaryDirectory(prefix='vaulter-r25-') as directory:
            with tarfile.open(archive) as source:
                source.extractall(directory, filter='data')
            roots = list(Path(directory).iterdir())
            assert len(roots) == 1 and roots[0].is_dir()
            result = inspect(roots[0], label, digest)
        assert hashlib.sha256(archive.read_bytes()).hexdigest() == before
        result.update(archive=str(archive.relative_to(REPO)), archive_sha256=before)
        results.append(result)
    print(json.dumps({'scope': 'offline historical classification, not new runtime proof', 'runs': results}, indent=2))


if __name__ == '__main__':
    main()
