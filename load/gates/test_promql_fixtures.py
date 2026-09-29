#!/usr/bin/env python3
"""Fixture checks for P8.7 gate reader materialization."""
import importlib.util
import json
import sys
import unittest
from pathlib import Path

GATES = Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location('perf_assert', GATES / 'perf-assert.py')
perf_assert = importlib.util.module_from_spec(spec)
sys.modules['perf_assert'] = perf_assert
spec.loader.exec_module(perf_assert)
materialize_promql = perf_assert.materialize_promql
run_window = perf_assert.run_window


class PromqlFixtureTest(unittest.TestCase):
    def test_run_window_minimum(self):
        summary = {'state': {'testRunDurationMs': 1000}}
        self.assertEqual(run_window(summary), '60s')

    def test_materialize_replaces_run_and_pm_max(self):
        fixture = json.loads((GATES / 'fixtures' / 'promql_materialize.json').read_text())
        promql = 'max_over_time(vaulter_download_stream_active[run]) <= PM_MAX_CHILDREN'
        out = materialize_promql(promql, fixture['summary'])
        self.assertIn('[120s]', out)
        self.assertIn('<= 5', out)


if __name__ == '__main__':
    unittest.main()
