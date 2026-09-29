#!/usr/bin/env python3
"""Fixture checks for P4.1 memory lane validator."""

from __future__ import annotations

import importlib.util
import json
import sys
import tempfile
import unittest
from pathlib import Path

GATES = Path(__file__).resolve().parent
FIXTURES = GATES / 'fixtures' / 'p4'
spec = importlib.util.spec_from_file_location('p4_memory', GATES / 'p4_memory.py')
gate = importlib.util.module_from_spec(spec)
sys.modules['p4_memory'] = gate
spec.loader.exec_module(gate)


def _write(path: Path, payload) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(payload, indent=2, sort_keys=True) + '\n', encoding='utf-8')


class P4MemoryGateTest(unittest.TestCase):
    def test_valid_bounded_fixture(self) -> None:
        gate.verify_run_dir(FIXTURES / 'valid-bounded')

    def test_reject_pid_reuse(self) -> None:
        with self.assertRaises(gate.MemoryError):
            gate.verify_run_dir(FIXTURES / 'invalid-pid-reuse')

    def test_reject_missing_terminal_sample(self) -> None:
        with self.assertRaises(gate.MemoryError):
            gate.verify_run_dir(FIXTURES / 'invalid-missing-terminal')

    def test_reject_cgroup_v1(self) -> None:
        with self.assertRaises(gate.MemoryError):
            gate.verify_run_dir(FIXTURES / 'invalid-cgroup-v1')

    def test_reject_truncated_jsonl(self) -> None:
        with self.assertRaises(gate.MemoryError):
            gate.verify_run_dir(FIXTURES / 'invalid-truncated-jsonl')

    def test_reject_byte_accounting(self) -> None:
        with self.assertRaises(gate.MemoryError):
            gate.verify_run_dir(FIXTURES / 'invalid-byte-accounting')

    def test_reject_checksum_mismatch(self) -> None:
        with self.assertRaises(gate.MemoryError):
            gate.verify_run_dir(FIXTURES / 'invalid-checksum-mismatch')

    def test_reject_o_file_size_anon_fixture(self) -> None:
        with self.assertRaises(gate.MemoryError):
            gate.verify_run_dir(FIXTURES / 'invalid-o-file-size-anon')

    def test_reject_missing_fingerprint(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            run_dir = Path(tmp)
            manifest = json.loads((FIXTURES / 'valid-bounded' / 'run-manifest.json').read_text())
            manifest.pop('env_fingerprint')
            _write(run_dir / 'run-manifest.json', manifest)
            samples = (FIXTURES / 'valid-bounded' / 'samples.jsonl').read_text()
            (run_dir / 'samples.jsonl').write_text(samples, encoding='utf-8')
            with self.assertRaises(gate.MemoryError):
                gate.verify_run_dir(run_dir)


if __name__ == '__main__':
    unittest.main()
