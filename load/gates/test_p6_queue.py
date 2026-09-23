#!/usr/bin/env python3
"""Fixture and dry-run checks for the P6.1 queue lane."""

from __future__ import annotations

import importlib.util
import json
import subprocess
import sys
import unittest
from pathlib import Path

GATES = Path(__file__).resolve().parent
REPO = GATES.parents[1]
FIXTURES = GATES / 'fixtures' / 'p6'
SCRIPT = REPO / 'load' / 'scripts' / 'p6-queue.sh'
SNAPSHOT = REPO / 'load' / 'scripts' / 'p6-queue-snapshot.sh'
spec = importlib.util.spec_from_file_location('p6_queue', GATES / 'p6_queue.py')
gate = importlib.util.module_from_spec(spec)
sys.modules['p6_queue'] = gate
spec.loader.exec_module(gate)

CELL = 'product-realistic.relay.i20.f0.2xx'
RUN = 'run-complete'


def _common(source: str, ns: int, queue: str = 'default') -> dict:
    return {
        'run_id': RUN,
        'cell_id': CELL,
        'attempt': 1,
        'monotonic_ns': ns,
        'wall_time': '2026-09-23T04:00:00.000000Z',
        'source': source,
        'queue': queue,
        'config_fingerprint': gate.config_fingerprint(),
    }


def write_complete(root: Path) -> None:
    fingerprint = gate.config_fingerprint()
    cell = {
        'schema': 'p6-cell/v1',
        'run_id': RUN,
        'cell_id': CELL,
        'attempt': 1,
        'plane': 'product-realistic',
        'config_fingerprint': fingerprint,
        'outbox_rows_per_s': 200,
        'jobs_per_s': 50,
        'redis_pending': 3,
        'redis_delayed': 1,
        'redis_reserved': 2,
        'worker_occupancy': 2,
        'http_receives': 1,
    }
    lifecycle = [
        {
            **_common('queued', 1000),
            'schema': 'p6-lifecycle/v1',
            'uuid': 'job-1',
            'class': 'Vaulter\\Jobs\\Webhooks\\DeliverWebhook',
            'job_attempt': 0,
            'outcome': 'queued',
        },
        {
            **_common('processing', 2000),
            'schema': 'p6-lifecycle/v1',
            'uuid': 'job-1',
            'class': 'Vaulter\\Jobs\\Webhooks\\DeliverWebhook',
            'job_attempt': 1,
            'outcome': 'started',
        },
        {
            **_common('processed', 3000),
            'schema': 'p6-lifecycle/v1',
            'uuid': 'job-1',
            'class': 'Vaulter\\Jobs\\Webhooks\\DeliverWebhook',
            'job_attempt': 1,
            'outcome': 'processed',
        },
    ]
    redis_rows = []
    for index, queue in enumerate(gate.QUEUES):
        redis_rows.append({
            **_common('redis-depth', 4000 + index, queue),
            'schema': 'p6-redis/v1',
            'pending': 3,
            'delayed': 1,
            'reserved': 2,
        })
    workers = [{
        **_common('worker-pid', 5000),
        'schema': 'p6-worker/v1',
        'pid': 42,
        'restarted': False,
    }]
    db_rows = [{
        **_common('db-transition', 6000),
        'schema': 'p6-db/v1',
        'entity': 'delivery',
        'event_id': 'evt-1',
        'from_status': 'pending',
        'to_status': 'delivered',
    }]
    sink_rows = [{
        **_common('http-receive', 7000),
        'schema': 'p6-sink/v1',
        'event_id': 'evt-1',
        'profile': '2xx',
        'status': 200,
        'start_ns': 6800,
        'end_ns': 6900,
        'request_ordinal': 1,
    }]
    links = [
        {**_common('prometheus', 8000), 'schema': 'p6-link/v1', 'path': 'prometheus.prom'},
        {**_common('pg-locks', 8100), 'schema': 'p6-link/v1', 'path': 'pg-locks.json'},
    ]
    manifest = {
        'schema': 'p6-run/v1',
        'run_id': RUN,
        'tier': 'S',
        'config_fingerprint': fingerprint,
        'worker_command': gate.WORKER_COMMAND,
        'retry_after': gate.RETRY_AFTER,
        'worker_timeout': gate.WORKER_TIMEOUT,
        'tries': gate.TRIES,
        'redis_keys': gate.redis_key_map(),
        'terminal_marker': 'p6-run-terminal/v1',
    }
    terminal = {
        'schema': 'p6-run-terminal/v1',
        'run_id': RUN,
        'monotonic_ns': 9000,
    }
    root.mkdir(parents=True, exist_ok=True)
    (root / 'run-manifest.json').write_text(json.dumps(manifest, indent=2) + '\n', encoding='utf-8')
    (root / 'cells.json').write_text(json.dumps([cell], indent=2) + '\n', encoding='utf-8')
    (root / 'terminal.json').write_text(json.dumps(terminal, indent=2) + '\n', encoding='utf-8')
    streams = {
        'lifecycle.jsonl': lifecycle,
        'redis.jsonl': redis_rows,
        'workers.jsonl': workers,
        'db-transitions.jsonl': db_rows,
        'sink-ledger.jsonl': sink_rows,
        'links.jsonl': links,
    }
    for name, rows in streams.items():
        text = ''.join(json.dumps(row, sort_keys=True) + '\n' for row in rows)
        (root / name).write_text(text, encoding='utf-8')


def _rewrite_jsonl(root: Path, name: str, mutate) -> None:
    rows = [json.loads(line) for line in (root / name).read_text(encoding='utf-8').splitlines()]
    mutate(rows)
    (root / name).write_text(''.join(json.dumps(row, sort_keys=True) + '\n' for row in rows), encoding='utf-8')


def materialize_fixtures() -> None:
    write_complete(FIXTURES / 'complete')
    write_complete(FIXTURES / 'missing-terminal')
    (FIXTURES / 'missing-terminal' / 'terminal.json').unlink()
    write_complete(FIXTURES / 'truncated')
    raw = (FIXTURES / 'truncated' / 'lifecycle.jsonl').read_text(encoding='utf-8')
    (FIXTURES / 'truncated' / 'lifecycle.jsonl').write_text(raw[:-1] + '{"truncated":', encoding='utf-8')
    write_complete(FIXTURES / 'cross-run')
    _rewrite_jsonl(FIXTURES / 'cross-run', 'lifecycle.jsonl', lambda rows: rows[0].__setitem__('run_id', 'other-run'))
    write_complete(FIXTURES / 'impossible-ordering')
    _rewrite_jsonl(
        FIXTURES / 'impossible-ordering',
        'lifecycle.jsonl',
        lambda rows: rows[1].__setitem__('monotonic_ns', 500),
    )
    write_complete(FIXTURES / 'duplicate-lifecycle')
    _rewrite_jsonl(FIXTURES / 'duplicate-lifecycle', 'lifecycle.jsonl', lambda rows: rows.append(dict(rows[0])))
    write_complete(FIXTURES / 'negative-depth')
    _rewrite_jsonl(FIXTURES / 'negative-depth', 'redis.jsonl', lambda rows: rows[0].__setitem__('pending', -1))
    write_complete(FIXTURES / 'db-sink-mismatch')
    _rewrite_jsonl(
        FIXTURES / 'db-sink-mismatch',
        'sink-ledger.jsonl',
        lambda rows: rows[0].__setitem__('event_id', 'evt-other'),
    )


class P6QueueGateTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        materialize_fixtures()

    def test_catalog_is_the_closed_tier_s_matrix(self) -> None:
        cells = gate.catalog()
        self.assertEqual(len(cells), 46)
        planes = {cell['plane'] for cell in cells}
        self.assertEqual(planes, set(gate.PLANES))
        product = [cell for cell in cells if cell['plane'] == 'product-realistic']
        self.assertEqual(len(product), 36)
        self.assertTrue(all(cell['throughput_claim'] for cell in product))
        self.assertTrue(all(cell['slo'] == list(gate.PRODUCT_SLOS) for cell in product))
        controls = [cell for cell in cells if cell['plane'] != 'product-realistic']
        self.assertTrue(all(not cell['throughput_claim'] and cell['slo'] == [] for cell in controls))
        ids = [cell['cell_id'] for cell in cells]
        self.assertEqual(len(ids), len(set(ids)))
        self.assertIn('overlap-control.duplicate-job.concurrent.same-delivery', ids)
        self.assertIn('dirty-cardinality-probe.due-rows.n500000.attempt1', ids)

    def test_dry_run_emits_exact_matrix_without_docker(self) -> None:
        proc = subprocess.run(
            ['sh', str(SCRIPT), 'dry-run'],
            check=False,
            capture_output=True,
            text=True,
            env={'TIER': 'S', 'PATH': '/usr/bin:/bin'},
        )
        self.assertEqual(proc.returncode, 0, proc.stderr)
        self.assertEqual(proc.stdout, gate.matrix_text())
        self.assertIn('docker=no', proc.stdout)
        self.assertIn('db-write=no', proc.stdout)
        self.assertNotIn('postgres://', proc.stdout)
        self.assertNotIn('redis://', proc.stdout)

    def test_accepts_complete_fixture(self) -> None:
        gate.verify_run_dir(FIXTURES / 'complete')

    def test_rejects_missing_terminal_marker(self) -> None:
        with self.assertRaisesRegex(gate.EvidenceInvalid, 'missing terminal marker'):
            gate.verify_run_dir(FIXTURES / 'missing-terminal')

    def test_rejects_truncated_jsonl(self) -> None:
        with self.assertRaisesRegex(gate.EvidenceInvalid, 'truncated jsonl'):
            gate.verify_run_dir(FIXTURES / 'truncated')

    def test_rejects_cross_run_event(self) -> None:
        with self.assertRaisesRegex(gate.EvidenceInvalid, 'cross-run'):
            gate.verify_run_dir(FIXTURES / 'cross-run')

    def test_rejects_impossible_ordering(self) -> None:
        with self.assertRaisesRegex(gate.EvidenceInvalid, 'impossible ordering'):
            gate.verify_run_dir(FIXTURES / 'impossible-ordering')

    def test_rejects_duplicate_lifecycle_key(self) -> None:
        with self.assertRaisesRegex(gate.EvidenceInvalid, 'duplicate lifecycle key'):
            gate.verify_run_dir(FIXTURES / 'duplicate-lifecycle')

    def test_rejects_negative_depth(self) -> None:
        with self.assertRaisesRegex(gate.EvidenceInvalid, 'negative depth'):
            gate.verify_run_dir(FIXTURES / 'negative-depth')

    def test_rejects_db_sink_mismatch(self) -> None:
        with self.assertRaisesRegex(gate.EvidenceInvalid, 'db/sink mismatch'):
            gate.verify_run_dir(FIXTURES / 'db-sink-mismatch')

    def test_worker_route_and_snapshot_syntax(self) -> None:
        gate.assert_worker_route(
            (REPO / 'load' / 'docker-compose.load.yml').read_text(encoding='utf-8'),
            (REPO / 'load' / 'app' / 'LoadServiceProvider.php').read_text(encoding='utf-8'),
        )
        proc = subprocess.run([str(SNAPSHOT), 'syntax'], check=False, capture_output=True, text=True)
        self.assertEqual(proc.returncode, 0, proc.stderr)
        self.assertIn('pending=LLEN queues:default', proc.stdout)
        self.assertIn('delayed=ZCARD queues:default:delayed', proc.stdout)
        self.assertIn('reserved=ZCARD queues:load-health:reserved', proc.stdout)
        self.assertIn('size=forbidden', proc.stdout)
        missing = subprocess.run([str(SNAPSHOT), 'sample'], check=False, capture_output=True, text=True)
        self.assertEqual(missing.returncode, 2)
        self.assertIn('missing redis sample', missing.stderr)

    def test_publish_is_atomic_after_verify(self) -> None:
        refused = subprocess.run(
            ['sh', str(SCRIPT), 'run'],
            check=False,
            capture_output=True,
            text=True,
            env={'TIER': 'S', 'PATH': '/usr/bin:/bin'},
        )
        self.assertEqual(refused.returncode, 1)
        self.assertIn('P6.3', refused.stderr)
        with self.subTest('invalid evidence is not published'):
            dest = FIXTURES / 'published-invalid'
            if dest.exists():
                subprocess.run(['rm', '-rf', str(dest)], check=True)
            proc = subprocess.run(
                ['sh', str(SCRIPT), 'run', '--run-dir', str(FIXTURES / 'negative-depth')],
                check=False,
                capture_output=True,
                text=True,
                env={'TIER': 'S', 'PATH': '/usr/bin:/bin', 'P6_PUBLISH': '1', 'P6_ARTIFACTS': str(dest)},
            )
            self.assertNotEqual(proc.returncode, 0)
            self.assertFalse(dest.exists())


if __name__ == '__main__':
    unittest.main()
