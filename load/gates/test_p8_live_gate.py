#!/usr/bin/env python3
"""Fixture checks for P8.7 live-gate verifier."""

from __future__ import annotations

import importlib.util
import json
import sys
import tempfile
import unittest
from pathlib import Path

GATES = Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location('p8_live_gate', GATES / 'p8_live_gate.py')
gate = importlib.util.module_from_spec(spec)
sys.modules['p8_live_gate'] = gate
spec.loader.exec_module(gate)


def _exposition(families: dict[str, str]) -> str:
    lines = []
    for name, kind in sorted(families.items()):
        lines.append(f'# HELP {name} live-gate fixture')
        lines.append(f'# TYPE {name} {kind}')
        if kind == 'histogram':
            lines.append(f'{name}_bucket{{le="1"}} 1')
            lines.append(f'{name}_bucket{{le="+Inf"}} 1')
            lines.append(f'{name}_sum 0.2')
            lines.append(f'{name}_count 1')
        else:
            lines.append(f'{name}{{fixture="1"}} 1')
    return '\n'.join(lines) + '\n'


def _triggers(families: dict[str, str]) -> dict:
    return {
        name: {'ran': True, 'command': f'trigger:{name}', 'zero_ok': False}
        for name in families
    }


def _verdicts(rows, status='pass') -> dict:
    out = {}
    for row in rows:
        if row['class'] == 'A' and row['id'] in gate.KNOWN_RED_IDS:
            out[row['id']] = {'status': 'known-red', 'detail': 'L-01'}
        elif row['class'] == 'A':
            out[row['id']] = {'status': status, 'detail': 'ok'}
        else:
            out[row['id']] = {'status': 'pass', 'detail': 'k6'}
    return out


def _pair(fingerprint='abc123'):
    sample = {
        'env_fingerprint': fingerprint,
        'dataset_id': 'vlt-load-fixture',
        'image_versions': 'postgres:16-alpine',
        'cpuset': '0,2',
        'http_p95_ms': 100.0,
        'app_rss_bytes': 40_000_000,
    }
    return sample, dict(sample)


class LiveGateTest(unittest.TestCase):
    def setUp(self):
        self.families = gate.expected_families()
        self.rows = gate.parse_rows(str(gate.SLO_GATES_MD))

    def _write_valid(self, directory: Path, *, latency_on=100.0, fingerprint='abc123'):
        triggers = _triggers(self.families)
        scraped = gate.parse_exposition(_exposition(self.families))
        matrix = gate.build_metric_matrix(families=self.families, scraped=scraped, triggers=triggers)
        gate.dump_json(directory / 'metric-matrix.json', matrix)
        gate.dump_json(
            directory / 'gate-matrix.json',
            gate.build_gate_matrix(rows=self.rows, verdicts=_verdicts(self.rows), profile='smoke-perf', tier='S0'),
        )
        on, off = _pair(fingerprint)
        on['http_p95_ms'] = latency_on
        gate.dump_json(directory / 'overhead.json', gate.build_overhead(on=on, off=off, ceilings={'app_rss_bytes': 67_108_864}))
        gate.dump_json(directory / 'run-manifest.json', gate.build_manifest(
            run_id_sha256='a' * 64,
            tier='S0',
            profile='smoke-perf',
            dataset_id='vlt-load-fixture',
            env_fingerprint=fingerprint,
            image_versions='postgres:16-alpine',
            prometheus_job='vaulter-sut',
            metrics_enabled=True,
        ))

    def test_catalog_is_fifty(self):
        self.assertEqual(len(self.families), 50)
        self.assertEqual(len(self.rows), 96)

    def test_verify_valid_twice_byte_identical(self):
        with tempfile.TemporaryDirectory() as tmp:
            directory = Path(tmp)
            self._write_valid(directory)
            first = json.dumps(gate.verify_artifacts(directory), sort_keys=True)
            second = json.dumps(gate.verify_artifacts(directory), sort_keys=True)
            self.assertEqual(first, second)
            self.assertIn('"status": "green"', first)

    def test_missing_family_is_evidence_invalid(self):
        with tempfile.TemporaryDirectory() as tmp:
            directory = Path(tmp)
            self._write_valid(directory)
            matrix = gate.load_json(directory / 'metric-matrix.json')
            matrix['families'] = matrix['families'][:-1]
            matrix['expected'] = 49
            gate.dump_json(directory / 'metric-matrix.json', matrix)
            with self.assertRaises(gate.GateError) as caught:
                gate.verify_artifacts(directory)
            self.assertEqual(caught.exception.code, 'MATRIX_COUNT')

    def test_class_a_not_evaluated_is_invalid(self):
        applicable = next(
            row for row in self.rows
            if row['class'] == 'A' and gate.applicable(row, profile='smoke-perf', tier='S')
            and row['id'] not in gate.KNOWN_RED_IDS
        )
        verdicts = _verdicts(self.rows)
        verdicts[applicable['id']] = {'status': 'not-evaluated', 'detail': 'zero count'}
        matrix = gate.build_gate_matrix(rows=self.rows, verdicts=verdicts, profile='smoke-perf', tier='S')
        self.assertEqual(matrix['verdict'], 'evidence-invalid')
        self.assertIn(applicable['id'], matrix['invalid'])

    def test_fingerprint_mismatch_is_invalid(self):
        on, off = _pair()
        off['env_fingerprint'] = 'other'
        payload = gate.build_overhead(on=on, off=off, ceilings={'app_rss_bytes': 67_108_864})
        self.assertEqual(payload['verdict'], 'evidence-invalid')

    def test_overhead_delta_is_acceptance_red(self):
        on, off = _pair()
        on['http_p95_ms'] = 130.0
        payload = gate.build_overhead(on=on, off=off, ceilings={'app_rss_bytes': 67_108_864})
        self.assertEqual(payload['verdict'], 'red')
        self.assertGreater(payload['latency_delta'], 0.10)

    def test_secret_scan_rejects_uuid(self):
        with tempfile.TemporaryDirectory() as tmp:
            directory = Path(tmp)
            self._write_valid(directory)
            manifest = gate.load_json(directory / 'run-manifest.json')
            manifest['note'] = 'run 550e8400-e29b-41d4-a716-446655440000'
            gate.dump_json(directory / 'run-manifest.json', manifest)
            with self.assertRaises(gate.GateError) as caught:
                gate.verify_artifacts(directory)
            self.assertEqual(caught.exception.code, 'SECRET_LEAK')

    def test_s_tier_rows_not_applicable_on_s0(self):
        slo40 = next(row for row in self.rows if row['id'] == 'SLO-40')
        self.assertFalse(gate.applicable(slo40, profile='smoke-perf', tier='S0'))
        self.assertTrue(gate.applicable(slo40, profile='smoke-perf', tier='S'))


if __name__ == '__main__':
    unittest.main()
