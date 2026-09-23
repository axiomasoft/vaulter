#!/usr/bin/env python3
"""Fixture and dry-run checks for the P7.1 chaos lane."""

from __future__ import annotations

import importlib.util
import json
import os
import signal
import subprocess
import sys
import time
import unittest
from pathlib import Path

GATES = Path(__file__).resolve().parent
REPO = GATES.parents[1]
FIXTURES = GATES / 'fixtures' / 'p7'
SCRIPT = REPO / 'load' / 'scripts' / 'p7-chaos.sh'
SNAPSHOT = REPO / 'load' / 'scripts' / 'p7-chaos-snapshot.sh'
spec = importlib.util.spec_from_file_location('p7_chaos', GATES / 'p7_chaos.py')
gate = importlib.util.module_from_spec(spec)
sys.modules['p7_chaos'] = gate
spec.loader.exec_module(gate)

CELL = 'product-realistic.tus-patch.503'
RUN = 'run-complete'
DIGEST = 'a' * 64
OBJECT_CHECKSUM = 'b' * 64


def _common(source: str, ns: int, phase: str, direction: str = 'observe') -> dict:
    return {
        'run_id': RUN,
        'cell_id': CELL,
        'attempt': 1,
        'monotonic_ns': ns,
        'wall_time': '2026-09-23T08:00:00.000000Z',
        'source': source,
        'phase': phase,
        'direction': direction,
        'fault_id': 'fault-complete',
        'injector_version': gate.INJECTOR_VERSION,
        'injector_config_digest': DIGEST,
        'target': 'minio',
        'config_fingerprint': gate.config_fingerprint(),
    }


def write_complete(root: Path) -> None:
    fingerprint = gate.config_fingerprint()
    cell = {
        'schema': 'p7-cell/v1',
        'run_id': RUN,
        'cell_id': CELL,
        'attempt': 1,
        'plane': 'product-realistic',
        'config_fingerprint': fingerprint,
        'availability': 'up',
        'http_outcome': 'rejected',
        'durable_state': 'rejected',
        't_detect_ns': 100,
        't_restore_ns': 200,
        't_converge_ns': 300,
        'rto_verdict': False,
    }
    ledger = [
        {**_common('ledger', 1000, 'baseline'), 'schema': 'p7-ledger/v1'},
        {**_common('ledger', 2000, 'armed', 'observe'), 'schema': 'p7-ledger/v1'},
        {**_common('ledger', 3000, 'fault', 'inject'), 'schema': 'p7-ledger/v1'},
        {**_common('ledger', 4000, 'revert', 'revert'), 'schema': 'p7-ledger/v1'},
        {**_common('ledger', 5000, 'recovery'), 'schema': 'p7-ledger/v1', 'recovered': True},
        {**_common('ledger', 6000, 'reconcile'), 'schema': 'p7-ledger/v1'},
    ]
    injectors = [{
        **_common('injector', 3100, 'fault', 'inject'),
        'schema': 'p7-injector/v1',
        'injector_id': 's3-503-stub',
    }]
    barriers = [{
        **_common('barrier', 1900, 'armed'),
        'schema': 'p7-barrier/v1',
        'barrier_id': 'barrier-complete',
        'observed': True,
    }]
    database = [{
        **_common('database', 3200, 'fault'),
        'schema': 'p7-database/v1',
        'entity': 'upload_session',
        'entity_id': 'upload-1',
        'status': 'pending',
        'checksum': OBJECT_CHECKSUM,
    }]
    objects = [{
        **_common('object', 3300, 'fault'),
        'schema': 'p7-object/v1',
        'key': f'tmp/uploads/{RUN}/upload-1.parts/1',
        'checksum': OBJECT_CHECKSUM,
        'size': 16,
    }]
    temp = [{
        **_common('temp', 3400, 'fault'),
        'schema': 'p7-temp/v1',
        'path': f'/tmp/p7-chaos/{RUN}/spill',
        'free_bytes': 1024,
        'write_length': 16,
    }]
    readiness = [
        {
            **_common('dependency-probe', 1500, 'baseline'),
            'schema': 'p7-readiness/v1',
            'up_ok': True,
            'ready_ok': True,
        },
        {
            **_common('dependency-probe', 5100, 'recovery'),
            'schema': 'p7-readiness/v1',
            'up_ok': True,
            'ready_ok': True,
        },
    ]
    prometheus = [{
        **_common('prometheus', 3500, 'fault'),
        'schema': 'p7-prometheus/v1',
        'path': 'prometheus.prom',
    }]
    attempts = [{
        **_common('attempt', 3600, 'fault'),
        'schema': 'p7-attempt/v1',
        'availability': 'down',
        'http_outcome': 'rejected',
        'durable_state': 'rejected',
        'rejected': True,
    }]
    reconcile = [
        {
            **_common('reconcile', 6100, 'reconcile', 'observe'),
            'schema': 'p7-reconcile/v1',
            'join': 'db-to-object',
            'status': 'rejected-persisted',
            'durable_identity': 'upload-1',
        },
        {
            **_common('reconcile', 6200, 'reconcile', 'observe'),
            'schema': 'p7-reconcile/v1',
            'join': 'object-to-db',
            'status': 'matched',
            'durable_identity': f'tmp/uploads/{RUN}/upload-1.parts/1',
        },
    ]
    manifest = {
        'schema': 'p7-run/v1',
        'run_id': RUN,
        'tier': 'S',
        'config_fingerprint': fingerprint,
        'injector_version': gate.INJECTOR_VERSION,
        'terminal_marker': 'p7-run-terminal/v1',
        'cleanup_scope': 'run',
    }
    terminal = {
        'schema': 'p7-run-terminal/v1',
        'run_id': RUN,
        'monotonic_ns': 9000,
    }
    root.mkdir(parents=True, exist_ok=True)
    (root / 'run-manifest.json').write_text(json.dumps(manifest, indent=2) + '\n', encoding='utf-8')
    (root / 'cells.json').write_text(json.dumps([cell], indent=2) + '\n', encoding='utf-8')
    (root / 'terminal.json').write_text(json.dumps(terminal, indent=2) + '\n', encoding='utf-8')
    streams = {
        'ledger.jsonl': ledger,
        'injectors.jsonl': injectors,
        'barriers.jsonl': barriers,
        'database.jsonl': database,
        'objects.jsonl': objects,
        'temp.jsonl': temp,
        'readiness.jsonl': readiness,
        'prometheus.jsonl': prometheus,
        'attempts.jsonl': attempts,
        'reconcile.jsonl': reconcile,
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
    write_complete(FIXTURES / 'truncated')
    raw = (FIXTURES / 'truncated' / 'ledger.jsonl').read_text(encoding='utf-8')
    (FIXTURES / 'truncated' / 'ledger.jsonl').write_text(raw[:-1] + '{"truncated":', encoding='utf-8')
    write_complete(FIXTURES / 'cross-run')
    _rewrite_jsonl(
        FIXTURES / 'cross-run',
        'objects.jsonl',
        lambda rows: rows[0].__setitem__('run_id', 'other-run'),
    )
    write_complete(FIXTURES / 'missing-revert')
    _rewrite_jsonl(
        FIXTURES / 'missing-revert',
        'ledger.jsonl',
        lambda rows: rows.__setitem__(3, {**rows[3], 'phase': 'recovery', 'monotonic_ns': 4000}),
    )
    write_complete(FIXTURES / 'missing-barrier')
    _rewrite_jsonl(
        FIXTURES / 'missing-barrier',
        'barriers.jsonl',
        lambda rows: rows[0].__setitem__('observed', False),
    )
    write_complete(FIXTURES / 'overlapping-fault-ids')
    _rewrite_jsonl(
        FIXTURES / 'overlapping-fault-ids',
        'ledger.jsonl',
        lambda rows: rows.insert(3, {**rows[2], 'monotonic_ns': 3500}),
    )
    write_complete(FIXTURES / 'impossible-phase-order')
    _rewrite_jsonl(
        FIXTURES / 'impossible-phase-order',
        'ledger.jsonl',
        lambda rows: rows[2].__setitem__('monotonic_ns', 1500),
    )
    write_complete(FIXTURES / 'absent-checksum')
    _rewrite_jsonl(
        FIXTURES / 'absent-checksum',
        'objects.jsonl',
        lambda rows: rows[0].__setitem__('checksum', ''),
    )
    write_complete(FIXTURES / 'ambiguous-outcome')
    _rewrite_jsonl(
        FIXTURES / 'ambiguous-outcome',
        'reconcile.jsonl',
        lambda rows: rows[0].__setitem__('status', 'ambiguous'),
    )


class P7ChaosGateTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        materialize_fixtures()

    def test_catalog_is_the_closed_tier_s_matrix(self) -> None:
        cells = gate.catalog()
        self.assertEqual(len(cells), 51)
        planes = {cell['plane'] for cell in cells}
        self.assertEqual(planes, set(gate.PLANES))
        product = [cell for cell in cells if cell['plane'] == 'product-realistic']
        self.assertEqual(len(product), 13)
        self.assertTrue(all(cell['integrity_claim'] for cell in product))
        controls = [cell for cell in cells if cell['plane'] != 'product-realistic']
        self.assertTrue(all(not cell['integrity_claim'] for cell in controls))
        self.assertTrue(all(cell['rto_verdict'] is False for cell in cells))
        ids = [cell['cell_id'] for cell in cells]
        self.assertEqual(len(ids), len(set(ids)))
        self.assertIn('protocol-control.tus-patch.503.pre-upstream', ids)
        self.assertIn('transport-control.pgbouncer.reconnect', ids)
        self.assertIn('primary-transition.lock-holder.fence-then-promote', ids)
        self.assertIn('storage-control.cas-stage.enospc-then-retry', ids)

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
        self.assertIn('object-write=no', proc.stdout)
        self.assertNotIn('postgres://', proc.stdout)
        self.assertNotIn('s3://', proc.stdout)

    def test_accepts_complete_fixture(self) -> None:
        gate.verify_run_dir(FIXTURES / 'complete')

    def test_rejects_truncated_jsonl(self) -> None:
        with self.assertRaisesRegex(gate.EvidenceInvalid, 'truncated jsonl'):
            gate.verify_run_dir(FIXTURES / 'truncated')

    def test_rejects_cross_run_object(self) -> None:
        with self.assertRaisesRegex(gate.EvidenceInvalid, 'cross-run'):
            gate.verify_run_dir(FIXTURES / 'cross-run')

    def test_rejects_missing_revert(self) -> None:
        with self.assertRaisesRegex(gate.EvidenceInvalid, 'missing revert'):
            gate.verify_run_dir(FIXTURES / 'missing-revert')

    def test_rejects_missing_barrier(self) -> None:
        with self.assertRaisesRegex(gate.EvidenceInvalid, 'missing barrier'):
            gate.verify_run_dir(FIXTURES / 'missing-barrier')

    def test_rejects_overlapping_fault_ids(self) -> None:
        with self.assertRaisesRegex(gate.EvidenceInvalid, 'overlapping fault ids'):
            gate.verify_run_dir(FIXTURES / 'overlapping-fault-ids')

    def test_rejects_impossible_phase_order(self) -> None:
        with self.assertRaisesRegex(gate.EvidenceInvalid, 'impossible phase order'):
            gate.verify_run_dir(FIXTURES / 'impossible-phase-order')

    def test_rejects_absent_checksum(self) -> None:
        with self.assertRaisesRegex(gate.EvidenceInvalid, 'absent checksum'):
            gate.verify_run_dir(FIXTURES / 'absent-checksum')

    def test_rejects_unresolved_ambiguity(self) -> None:
        with self.assertRaisesRegex(gate.EvidenceInvalid, 'unresolved ambiguity'):
            gate.verify_run_dir(FIXTURES / 'ambiguous-outcome')

    def test_snapshot_syntax_and_missing_live_sample(self) -> None:
        proc = subprocess.run([str(SNAPSHOT), 'syntax'], check=False, capture_output=True, text=True)
        self.assertEqual(proc.returncode, 0, proc.stderr)
        self.assertIn('entity=upload_session', proc.stdout)
        self.assertIn('checksum+size', proc.stdout)
        self.assertIn('body=forbidden', proc.stdout)
        self.assertIn('ready=/ready', proc.stdout)
        self.assertIn('cleanup=run-scope', proc.stdout)
        missing = subprocess.run([str(SNAPSHOT), 'sample'], check=False, capture_output=True, text=True)
        self.assertEqual(missing.returncode, 2)
        self.assertIn('missing sample', missing.stderr)

    def test_interrupt_fixture_proves_revert(self) -> None:
        out = FIXTURES / 'interrupt'
        if out.exists():
            subprocess.run(['rm', '-rf', str(out)], check=True)
        out.mkdir(parents=True)
        proc = subprocess.Popen(
            ['sh', str(SCRIPT), 'interrupt-fixture', '--out', str(out)],
            stdout=subprocess.DEVNULL,
            stderr=subprocess.PIPE,
            text=True,
            start_new_session=True,
        )
        armed = out / 'armed'
        deadline = time.time() + 5
        while time.time() < deadline and not armed.exists():
            time.sleep(0.05)
        self.assertTrue(armed.exists())
        os.kill(-proc.pid, signal.SIGINT)
        try:
            code = proc.wait(timeout=5)
        except subprocess.TimeoutExpired:
            os.kill(proc.pid, signal.SIGTERM)
            proc.wait(timeout=2)
            self.fail('interrupt-fixture did not exit after SIGINT')
        stderr = ''
        if proc.stderr is not None:
            stderr = proc.stderr.read()
            proc.stderr.close()
        self.assertEqual(code, 0, stderr)
        self.assertTrue((out / 'reverted').exists())
        self.assertFalse(armed.exists())
        text = (out / 'reverted').read_text(encoding='utf-8')
        self.assertIn('revert-executed', text)
        self.assertIn('"wildcard":false', text.replace(' ', ''))

    def test_publish_is_atomic_after_verify(self) -> None:
        refused = subprocess.run(
            ['sh', str(SCRIPT), 'run'],
            check=False,
            capture_output=True,
            text=True,
            env={'TIER': 'S', 'PATH': '/usr/bin:/bin'},
        )
        self.assertEqual(refused.returncode, 1)
        self.assertIn('P7.3', refused.stderr)
        dest = FIXTURES / 'published-invalid'
        if dest.exists():
            subprocess.run(['rm', '-rf', str(dest)], check=True)
        proc = subprocess.run(
            ['sh', str(SCRIPT), 'run', '--run-dir', str(FIXTURES / 'absent-checksum')],
            check=False,
            capture_output=True,
            text=True,
            env={'TIER': 'S', 'PATH': '/usr/bin:/bin', 'P7_PUBLISH': '1', 'P7_ARTIFACTS': str(dest)},
        )
        self.assertNotEqual(proc.returncode, 0)
        self.assertFalse(dest.exists())


if __name__ == '__main__':
    unittest.main()
