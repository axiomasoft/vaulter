#!/usr/bin/env python3
"""Fixture and dry-run checks for the P5.1 concurrency lane."""

from __future__ import annotations

import importlib.util
import json
import os
import stat
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

GATES = Path(__file__).resolve().parent
REPO = GATES.parents[1]
FIXTURES = GATES / 'fixtures' / 'p5'
SCRIPT = REPO / 'load' / 'scripts' / 'p5-concurrency.sh'
spec = importlib.util.spec_from_file_location('p5_concurrency', GATES / 'p5_concurrency.py')
gate = importlib.util.module_from_spec(spec)
sys.modules['p5_concurrency'] = gate
spec.loader.exec_module(gate)


def _obs(source: str, ns: int, **extra) -> dict:
    row = {
        'schema': 'p5-observation/v1',
        'run_id': 'run-complete',
        'cell_id': 'primitive-control.advisory-primitive.try-lock-nowait.n1',
        'attempt': 1,
        'plane': 'primitive-control',
        'source': source,
        'snapshot_id': f'snap-{ns}',
        'monotonic_ns': ns,
        'pid': 42,
        'client': 'actor-0',
    }
    row.update(extra)
    return row


def write_complete(root: Path, run_id: str = 'run-complete') -> None:
    cell_id = 'primitive-control.advisory-primitive.try-lock-nowait.n1'
    cell = {
        'schema': 'p5-cell/v1',
        'run_id': run_id,
        'cell_id': cell_id,
        'attempt': 1,
        'plane': 'primitive-control',
        'surface': 'advisory-primitive',
        'shape': 'try-lock-nowait',
        'n': 1,
        'boundary_control': False,
        'supported_workload': True,
        'backend_pids': [41, 42],
        'pre': {'deadlocks': 1, 'log_cursor': 'log:100', 'monotonic_ns': 1000},
        'post': {'deadlocks': 1, 'log_cursor': 'log:180', 'monotonic_ns': 9000},
        'waits': {
            'db_lock_wait_seconds': None,
            'pgbouncer_wait_seconds': 0.01,
            'http_duration_seconds': None,
            'barrier_skew_seconds': 0.002,
        },
        'nowait': {'source': 'actor-status', 'attempt_count': 1, 'refused_count': 0},
    }
    observations = [
        _obs('barrier', 2000, pid=None, client='orchestrator', barrier_skew_ns=2000000),
        _obs('pg_locks', 3000, granted=True),
        _obs('pg_stat_activity', 4000, wait_event_type='Lock'),
        _obs('pg_blocking_pids', 5000, pid=42, blocker_pids=[41]),
        _obs(
            'pgbouncer_pools', 6000, pid=None, client='pgbouncer',
            cl_waiting=0, sv_active=1, maxwait=0, pool_mode='transaction',
            default_pool_size=8, max_client_conn=200, query_wait_timeout=120,
        ),
        _obs('pgbouncer_stats', 7000, pid=None, client='pgbouncer', avg_wait_time=0),
        _obs('actor', 8000, lock_fn='pg_try_advisory_xact_lock', try_lock=True),
    ]
    for row in observations:
        row['run_id'] = run_id
        row['cell_id'] = cell_id
    root.mkdir(parents=True, exist_ok=True)
    (root / 'run-manifest.json').write_text(
        json.dumps({'schema': 'p5-run/v1', 'run_id': run_id, 'tier': 'S'}, indent=2) + '\n',
        encoding='utf-8',
    )
    (root / 'cells.json').write_text(json.dumps([cell], indent=2) + '\n', encoding='utf-8')
    lines = ''.join(json.dumps(row, sort_keys=True) + '\n' for row in observations)
    (root / 'observations.jsonl').write_text(lines, encoding='utf-8')


def materialize_fixtures() -> None:
    write_complete(FIXTURES / 'complete')
    write_complete(FIXTURES / 'missing-counters')
    cells = json.loads((FIXTURES / 'missing-counters' / 'cells.json').read_text())
    del cells[0]['pre']['deadlocks']
    del cells[0]['post']['log_cursor']
    (FIXTURES / 'missing-counters' / 'cells.json').write_text(json.dumps(cells) + '\n', encoding='utf-8')

    write_complete(FIXTURES / 'duplicate-cell')
    cells = json.loads((FIXTURES / 'duplicate-cell' / 'cells.json').read_text())
    cells.append(dict(cells[0]))
    (FIXTURES / 'duplicate-cell' / 'cells.json').write_text(json.dumps(cells) + '\n', encoding='utf-8')

    write_complete(FIXTURES / 'cross-run')
    text = (FIXTURES / 'cross-run' / 'observations.jsonl').read_text(encoding='utf-8')
    (FIXTURES / 'cross-run' / 'observations.jsonl').write_text(
        text.replace('"run_id": "run-complete"', '"run_id": "other-run"', 1),
        encoding='utf-8',
    )

    write_complete(FIXTURES / 'untyped-plane')
    cells = json.loads((FIXTURES / 'untyped-plane' / 'cells.json').read_text())
    cells[0]['plane'] = 'artificial-storm'
    (FIXTURES / 'untyped-plane' / 'cells.json').write_text(json.dumps(cells) + '\n', encoding='utf-8')

    write_complete(FIXTURES / 'impossible-blocker')
    lines = (FIXTURES / 'impossible-blocker' / 'observations.jsonl').read_text(encoding='utf-8').splitlines()
    rows = [json.loads(line) for line in lines]
    for row in rows:
        if row['source'] == 'pg_blocking_pids':
            row['blocker_pids'] = [42]
    (FIXTURES / 'impossible-blocker' / 'observations.jsonl').write_text(
        ''.join(json.dumps(row, sort_keys=True) + '\n' for row in rows),
        encoding='utf-8',
    )

    write_complete(FIXTURES / 'non-monotonic')
    lines = (FIXTURES / 'non-monotonic' / 'observations.jsonl').read_text(encoding='utf-8').splitlines()
    rows = [json.loads(line) for line in lines]
    rows[2]['monotonic_ns'] = rows[1]['monotonic_ns']
    (FIXTURES / 'non-monotonic' / 'observations.jsonl').write_text(
        ''.join(json.dumps(row, sort_keys=True) + '\n' for row in rows),
        encoding='utf-8',
    )

    write_complete(FIXTURES / 'truncated-jsonl')
    raw = (FIXTURES / 'truncated-jsonl' / 'observations.jsonl').read_text(encoding='utf-8')
    (FIXTURES / 'truncated-jsonl' / 'observations.jsonl').write_text(raw[:-1] + '{"truncated":', encoding='utf-8')


class P5ConcurrencyGateTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        materialize_fixtures()

    def test_accepts_complete_fixture(self) -> None:
        gate.verify_run_dir(FIXTURES / 'complete')

    def test_reject_missing_counters(self) -> None:
        with self.assertRaises(gate.EvidenceInvalid) as caught:
            gate.verify_run_dir(FIXTURES / 'missing-counters')
        self.assertIn('missing pre/post counters', str(caught.exception))

    def test_reject_duplicate_cell_id(self) -> None:
        with self.assertRaises(gate.EvidenceInvalid) as caught:
            gate.verify_run_dir(FIXTURES / 'duplicate-cell')
        self.assertIn('duplicate cell id', str(caught.exception))

    def test_reject_cross_run_sample(self) -> None:
        with self.assertRaises(gate.EvidenceInvalid) as caught:
            gate.verify_run_dir(FIXTURES / 'cross-run')
        self.assertIn('cross-run sample', str(caught.exception))

    def test_reject_untyped_plane(self) -> None:
        with self.assertRaises(gate.EvidenceInvalid) as caught:
            gate.verify_run_dir(FIXTURES / 'untyped-plane')
        self.assertIn('untyped plane', str(caught.exception))

    def test_reject_impossible_blocker_pid(self) -> None:
        with self.assertRaises(gate.EvidenceInvalid) as caught:
            gate.verify_run_dir(FIXTURES / 'impossible-blocker')
        self.assertIn('impossible blocker PID', str(caught.exception))

    def test_reject_non_monotonic_time(self) -> None:
        with self.assertRaises(gate.EvidenceInvalid) as caught:
            gate.verify_run_dir(FIXTURES / 'non-monotonic')
        self.assertIn('non-monotonic time', str(caught.exception))

    def test_reject_truncated_jsonl(self) -> None:
        with self.assertRaises(gate.EvidenceInvalid) as caught:
            gate.verify_run_dir(FIXTURES / 'truncated-jsonl')
        self.assertIn('truncated JSONL', str(caught.exception))

    def test_sql_is_transaction_scoped(self) -> None:
        for shape in ('try-lock-nowait', 'blocking-wait', 'xact-release', 'pool-queue', 'pool-timeout'):
            sql = gate.control_sql(shape)
            self.assertIn('ROLLBACK;', sql)
            self.assertNotIn('pg_advisory_lock(', sql)
            self.assertNotIn('pg_try_advisory_lock(', sql)

    def test_catalog_marks_201_as_boundary(self) -> None:
        boundary = [cell for cell in gate.catalog() if cell['n'] == 201]
        self.assertTrue(boundary)
        self.assertTrue(all(cell['boundary_control'] and not cell['supported_workload'] for cell in boundary))
        self.assertTrue(all(cell['plane'] == 'primitive-control' for cell in boundary))

    def test_dry_run_emits_axes_without_docker(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            bindir = Path(tmp)
            docker = bindir / 'docker'
            docker.write_text('#!/bin/sh\necho docker-invoked >&2\nexit 99\n', encoding='utf-8')
            docker.chmod(docker.stat().st_mode | stat.S_IEXEC)
            env = os.environ.copy()
            env['PATH'] = str(bindir) + os.pathsep + env.get('PATH', '')
            env['TIER'] = 'S'
            completed = subprocess.run(
                [str(SCRIPT), 'dry-run'],
                cwd=REPO,
                env=env,
                text=True,
                capture_output=True,
                check=False,
            )
        self.assertEqual(completed.returncode, 0, completed.stderr)
        self.assertNotIn('docker-invoked', completed.stderr)
        for token in (
            'plane=product-realistic,product-worst-case,primitive-control,dirty-cardinality-probe',
            'N=1,8,32,200,201',
            'boundary_control=201',
            'poll_interval_ms=50',
            'docker=no db-write=no',
        ):
            self.assertIn(token, completed.stdout)
        self.assertIn('cells=52', completed.stdout)

    def test_snapshot_syntax(self) -> None:
        for name in ('p5-postgres-snapshot.sh', 'p5-pgbouncer-snapshot.sh'):
            completed = subprocess.run(
                [str(REPO / 'load' / 'scripts' / name), 'syntax'],
                cwd=REPO,
                text=True,
                capture_output=True,
                check=False,
            )
            self.assertEqual(completed.returncode, 0, completed.stderr)
            self.assertIn('syntax: ok', completed.stdout)

    def test_barrier_skew_for_eight_actors(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            report = gate.filesystem_barrier(8, Path(tmp))
        self.assertEqual(report['actors'], 8)
        self.assertGreaterEqual(report['barrier_skew_ns'], 0)


if __name__ == '__main__':
    unittest.main()
