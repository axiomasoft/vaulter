#!/usr/bin/env python3
"""Fixture checks for P3.1 profiling evidence validator."""

from __future__ import annotations

import importlib.util
import json
import sys
import tempfile
import unittest
from pathlib import Path

GATES = Path(__file__).resolve().parent
FIXTURES = GATES / 'fixtures' / 'p3'
spec = importlib.util.spec_from_file_location('p3_profile', GATES / 'p3_profile.py')
profile = importlib.util.module_from_spec(spec)
sys.modules['p3_profile'] = profile
spec.loader.exec_module(profile)


def _write(path: Path, payload) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(payload, indent=2, sort_keys=True) + '\n', encoding='utf-8')


class P3ProfileGateTest(unittest.TestCase):
    def test_valid_minimal_fixture(self) -> None:
        run_dir = FIXTURES / 'valid-minimal'
        profile.verify_run_dir(run_dir)

    def test_reject_trace_bindings(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            run_dir = Path(tmp)
            _write(run_dir / 'run-manifest.json', json.loads((FIXTURES / 'valid-minimal' / 'run-manifest.json').read_text()))
            trace = (FIXTURES / 'invalid-trace-bindings' / 'query-trace.jsonl').read_text()
            (run_dir / 'query-trace.jsonl').write_text(trace, encoding='utf-8')
            with self.assertRaises(profile.ProfileError):
                profile.verify_run_dir(run_dir)

    def test_reject_missing_fingerprint(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            run_dir = Path(tmp)
            manifest = json.loads((FIXTURES / 'valid-minimal' / 'run-manifest.json').read_text())
            manifest.pop('env_fingerprint')
            _write(run_dir / 'run-manifest.json', manifest)
            with self.assertRaises(profile.ProfileError):
                profile.verify_run_dir(run_dir)

    def test_reject_missing_dealloc(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            run_dir = Path(tmp)
            manifest = json.loads((FIXTURES / 'valid-minimal' / 'run-manifest.json').read_text())
            manifest['pg_stat_statements_info'].pop('dealloc_delta')
            _write(run_dir / 'run-manifest.json', manifest)
            with self.assertRaises(profile.ProfileError):
                profile.verify_run_dir(run_dir)

    def test_reject_hypopg_analyze(self) -> None:
        run_dir = FIXTURES / 'invalid-hypopg-analyze'
        with self.assertRaises(profile.ProfileError):
            profile.verify_run_dir(run_dir)

    def test_reject_write_explain_without_disposable(self) -> None:
        run_dir = FIXTURES / 'invalid-explain-write'
        with self.assertRaises(profile.ProfileError):
            profile.verify_run_dir(run_dir)


if __name__ == '__main__':
    unittest.main()
