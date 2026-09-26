#!/usr/bin/env python3
"""R06 failed-command diagnostics; disposable mocks and local PHP only."""
import importlib.util
import json
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

GATES = Path(__file__).resolve().parent
sys.path.insert(0, str(GATES))
spec = importlib.util.spec_from_file_location('p5_r06', GATES / 'p5_live_matrix.py')
p5 = importlib.util.module_from_spec(spec)
spec.loader.exec_module(p5)
CELL = {'cell_id': 'dirty-cardinality-probe.sweeper-overlap.dirty-20k.n1', 'shape': 'dirty-20k'}


class R06CaptureTest(unittest.TestCase):
    def capture(self, result, candidates, metrics):
        with tempfile.TemporaryDirectory() as tmp, \
             patch.object(p5, 'psql', return_value=''), \
             patch.object(p5, 'sweep_candidates', side_effect=candidates), \
             patch.object(p5, 'sweep_batch_metric', side_effect=metrics), \
             patch.object(p5, 'compose', return_value=result) as command:
            root = Path(tmp)
            (root / 'sweeper').mkdir()
            observed = p5.run_sweeper(CELL, 1, root, run_id='r06-test')['statuses']
            stored = json.loads(next((root / 'sweeper').glob('*.json')).read_text())
        self.assertEqual(stored, observed)
        self.assertEqual(observed['run_id'], 'r06-test')
        self.assertIn('auto_prepend_file=/app/load/scripts/p5-php-peak.php', command.call_args.args)
        return observed

    def test_failure_and_missing_metrics_are_retained_without_zero_inference(self):
        result = subprocess.CompletedProcess([], 1, 'Exception in console output', 'fatal\nP5_PHP_PEAK_BYTES=8192\n')
        row = self.capture(result, [20000, 20000], [None, None])
        self.assertTrue(row['command_failed'])
        self.assertEqual(row['exit_code'], 1)
        self.assertEqual(row['stdout'], result.stdout)
        self.assertEqual(row['stderr'], result.stderr)
        self.assertEqual(row['processed_rows'], 0)
        self.assertEqual(row['php_peak_bytes'], 8192)
        self.assertIsNone(row['vaulter_sweep_batch_rows'])

    def test_post_db_failure_keeps_durable_command_diagnostics(self):
        row = self.capture(subprocess.CompletedProcess([], 7, 'partial', 'explicit failure'),
            [20000, p5.gate.EvidenceInvalid('post probe unavailable')], [None, None])
        self.assertEqual(row['exit_code'], 7)
        self.assertEqual(row['stdout'], 'partial')
        self.assertIsNone(row['processed_rows'])
        self.assertIsNone(row['remaining_rows'])
        self.assertIn('unavailable', row['progress_capture_error'])

    def test_reset_histogram_cannot_produce_a_negative_batch_measurement(self):
        row = self.capture(subprocess.CompletedProcess([], 0, '', ''), [20000, 19900],
            [{'sum': 500, 'count': 5}, {'sum': 100, 'count': 1}])
        self.assertIsNone(row['vaulter_sweep_batch_rows'])
        self.assertIn('unevaluated', row['batch_metric_error'])
        self.assertIsNone(row['php_peak_bytes'])

    def test_timeout_saves_partial_bytes_and_stops_before_post_probe(self):
        error = subprocess.TimeoutExpired(['docker'], 900, output=b'partial stdout', stderr=b'partial stderr')
        with tempfile.TemporaryDirectory() as tmp, \
             patch.object(p5, 'psql', return_value=''), \
             patch.object(p5, 'sweep_candidates', return_value=20000) as candidates, \
             patch.object(p5, 'sweep_batch_metric', return_value=None), \
             patch.object(p5, 'compose', side_effect=error):
            root = Path(tmp)
            (root / 'sweeper').mkdir()
            with self.assertRaises(p5.SweeperCaptureTimeout):
                p5.run_sweeper(CELL, 1, root, run_id='r06-test')
            row = json.loads(next((root / 'sweeper').glob('*.json')).read_text())
        self.assertTrue(row['timed_out'])
        self.assertTrue(row['command_failed'])
        self.assertIsNone(row['exit_code'])
        self.assertEqual(row['stderr'], 'partial stderr')
        self.assertEqual(row['stdout'], 'partial stdout')
        candidates.assert_called_once()

    def test_matrix_timeout_aborts_instead_of_launching_another_attempt(self):
        cell = {**CELL, 'plane': 'dirty-cardinality-probe', 'surface': 'sweeper-overlap', 'n': 1}
        with tempfile.TemporaryDirectory() as tmp, \
             patch.object(p5.gate, 'catalog', return_value=[cell]), \
             patch.object(p5, 'preflight', return_value={}), \
             patch.object(p5, 'ensure_document', return_value='1'), \
             patch.object(p5, 'ensure_tree', return_value={}), \
             patch.object(p5, 'counts', return_value={}), \
             patch.object(p5, 'write_dump'), \
             patch.object(p5, 'execute_cell', side_effect=p5.SweeperCaptureTimeout('timeout')) as execute:
            with self.assertRaises(p5.SweeperCaptureTimeout):
                p5.run_matrix(Path(tmp), 'r06-test')
        execute.assert_called_once()

    def test_emitted_batch_histogram_keeps_buckets_and_missing_metric_is_null(self):
        text = ('vaulter_sweep_batch_rows_bucket{le="1000"} 1\n'
                'vaulter_sweep_batch_rows_bucket{le="+Inf"} 2\n'
                'vaulter_sweep_batch_rows_count 2\nvaulter_sweep_batch_rows_sum 20001\n')
        for raw, expected in [(text, 4), ('# no sweep observations\n', None)]:
            with patch.object(p5.urllib.request, 'urlopen') as request:
                request.return_value.__enter__.return_value.read.return_value = raw.encode()
                metric = p5.sweep_batch_metric()
            if expected is None:
                self.assertIsNone(metric)
            else:
                self.assertEqual(len(metric['raw']), expected)
                self.assertEqual(metric['sum'], 20001)

    def test_php_shutdown_peak_survives_success_and_uncaught_exception(self):
        helper = GATES.parents[1] / 'load/scripts/p5-php-peak.php'
        with tempfile.TemporaryDirectory() as tmp:
            script = Path(tmp) / 'probe.php'
            for code, expected in [('echo "success";', 0), ('throw new RuntimeException("r06-negative-control");', 255)]:
                script.write_text('<?php '+code)
                result = subprocess.run(['php', '-d', 'display_errors=stderr', '-d', 'log_errors=0', '-d', 'auto_prepend_file='+str(helper), str(script)],
                    text=True, capture_output=True)
                self.assertEqual(result.returncode, expected)
                self.assertRegex(result.stderr, r'P5_PHP_PEAK_BYTES=[1-9][0-9]*')
                if expected:
                    self.assertIn('r06-negative-control', result.stderr)


if __name__ == '__main__':
    unittest.main()
