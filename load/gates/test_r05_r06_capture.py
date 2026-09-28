#!/usr/bin/env python3
"""Offline evidence checks for R05 actors and R06 sweeper capture."""

from __future__ import annotations

import importlib.util
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

GATES = Path(__file__).resolve().parent
sys.path.insert(0, str(GATES))
spec = importlib.util.spec_from_file_location('p5_live_matrix', GATES / 'p5_live_matrix.py')
p5 = importlib.util.module_from_spec(spec)
spec.loader.exec_module(p5)


class P5CaptureTest(unittest.TestCase):
    def test_each_attempt_and_vu_has_a_distinct_actor(self):
        cells = [cell for cell in p5.gate.catalog() if cell['plane'] == 'product-realistic']
        identities = []
        for cell in cells:
            for attempt in range(1, p5.repeat_count(cell) + 1):
                base = p5.actor_base('run-123', cell, attempt)
                identities.extend(range(base, base + cell['n'] + 1))
        self.assertEqual(len(identities), len(set(identities)))

    def test_actor_grants_use_seed_owner_root_for_shared_storage(self):
        with patch.object(p5, 'psql', return_value='101\n102\n103\n') as query:
            p5.grant_actors(101, 2)
        sql = query.call_args.args[0]
        self.assertIn('root_node_id', sql)
        self.assertIn("g.subject_id = 1 AND g.level = 'owner'", sql)
        self.assertIn('generate_series(101, 103)', sql)

    def test_empty_lock_snapshot_does_not_infer_grant(self):
        row = {'run_id': 'r', 'cell_id': 'c', 'attempt': 1, 'plane': 'product-realistic',
               'shape': 'same-resource', 'waits': {'barrier_skew_seconds': 0}}
        pg = {'locks': [], 'activity': [], 'blockers': []}
        pool = {'cl_waiting': 0, 'sv_active': 0, 'maxwait': 0, 'pool_mode': 'transaction',
                'default_pool_size': 8, 'max_client_conn': 200, 'query_wait_timeout': 120,
                'avg_wait_time': 0}
        observations = p5.observations_for(row, pg, pool, {}, 100)
        lock = next(item for item in observations if item['source'] == 'pg_locks')
        self.assertEqual(lock['locks'], [])
        self.assertNotIn('granted', lock)

    def test_failed_sweep_retains_failure_and_progress(self):
        cell = {'cell_id': 'dirty-cardinality-probe.sweeper-overlap.dirty-20k.n1',
                'shape': 'dirty-20k'}
        result = subprocess.CompletedProcess([], 1, 'partial output',
                                             'sweep failed\nP5_PHP_PEAK_BYTES=4096\n')
        with tempfile.TemporaryDirectory() as tmp, \
             patch.object(p5, 'psql', return_value=''), \
             patch.object(p5, 'sweep_candidates', side_effect=[20000, 19990]), \
             patch.object(p5, 'sweep_batch_metric', side_effect=[{'count': 2, 'sum': 200}, {'count': 3, 'sum': 210}]), \
             patch.object(p5, 'compose', return_value=result):
            out = Path(tmp)
            (out / 'sweeper').mkdir()
            observed = p5.run_sweeper(cell, 1, out)['statuses']
        self.assertNotIn('artisan', observed['command'])
        self.assertIn('vaulter:sweep-uploads', observed['command'])
        self.assertEqual(observed['exit_code'], 1)
        self.assertEqual(observed['candidate_rows'], 20000)
        self.assertEqual(observed['processed_rows'], 10)
        self.assertEqual(observed['remaining_rows'], 19990)
        self.assertEqual(observed['php_peak_bytes'], 4096)
        self.assertIn('sweep failed', observed['stderr'])
        self.assertEqual(observed['vaulter_sweep_batch_rows']['sum_delta'], 10)


if __name__ == '__main__':
    unittest.main()
