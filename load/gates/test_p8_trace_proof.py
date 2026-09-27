#!/usr/bin/env python3
"""Fixture checks for the P8.11 tracing proof gate."""

from __future__ import annotations

import importlib.util
import json
import tempfile
import unittest
from pathlib import Path

GATES = Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location('p8_trace_proof', GATES / 'p8_trace_proof.py')
gate = importlib.util.module_from_spec(spec)
spec.loader.exec_module(gate)


def _sample(ratio: float, exported: int, p95: int, peak: int, iterations: int = 200) -> dict:
    return {
        'ratio': ratio,
        'iterations': iterations,
        'p95_ns': p95,
        'peak_bytes': peak,
        'exported': exported,
    }


class TraceProofTest(unittest.TestCase):
    def test_record_and_verify_are_byte_stable(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            manifest = root / 'manifest.json'
            manifest.write_text(json.dumps({
                'mode': 'nonempty-candidates',
                'selected': [
                    {'target_id': 'webhook.pipeline'},
                    {'target_id': 'upload.sweep'},
                ],
            }), encoding='utf-8')
            proof = root / 'proof.json'
            overhead = root / 'overhead.json'
            gate.record(
                manifest=manifest,
                disabled=_sample(0, 0, 1_000_000, 40_000_000),
                sampled=_sample(0.01, 4, 1_050_000, 41_000_000),
                full=_sample(1, 200, 1_200_000, 42_000_000),
                proof_path=proof,
                overhead_path=overhead,
                otel_api='1.10.0',
                otel_sdk='1.15.0',
                php_version='8.4.1',
            )
            first = gate.verify(proof_path=proof, overhead_path=overhead, manifest=manifest)
            second = gate.verify(proof_path=proof, overhead_path=overhead, manifest=manifest)
            self.assertEqual(first, second)

    def test_delta_above_the_ceiling_is_rejected(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            manifest = root / 'manifest.json'
            manifest.write_text(json.dumps({
                'mode': 'nonempty-candidates',
                'selected': [{'target_id': 'upload.sweep'}],
            }), encoding='utf-8')
            with self.assertRaises(gate.ProofError):
                gate.record(
                    manifest=manifest,
                    disabled=_sample(0, 0, 1_000_000, 40_000_000),
                    sampled=_sample(0.01, 4, 1_200_000, 41_000_000),
                    full=_sample(1, 200, 1_300_000, 42_000_000),
                    proof_path=root / 'proof.json',
                    overhead_path=root / 'overhead.json',
                    otel_api='1.10.0',
                    otel_sdk='1.15.0',
                    php_version='8.4.1',
                )


if __name__ == '__main__':
    unittest.main()
