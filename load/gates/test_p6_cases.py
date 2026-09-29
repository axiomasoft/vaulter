#!/usr/bin/env python3
"""Static checks for the P6.2 semantic matrix."""

from __future__ import annotations

import importlib.util
import json
import sys
import tempfile
import unittest
from pathlib import Path

GATES = Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location('p6_cases', GATES / 'p6_cases.py')
cases = importlib.util.module_from_spec(spec)
sys.modules['p6_cases'] = cases
spec.loader.exec_module(cases)


class SemanticMatrixTest(unittest.TestCase):
    def test_every_plane_and_case_has_invariants(self):
        summary = cases.static_validate()
        self.assertEqual(summary['cells'], 46)
        self.assertEqual(summary['planes'], list(cases.PLANES))
        self.assertEqual(len(summary['s0']), 4)
        self.assertFalse(summary['mechanism_only']['product_slo'])

    def test_product_axes_cover_ingress_fanout_and_sink(self):
        cells = cases.queue.catalog()
        product = [cell for cell in cells if cell['plane'] == 'product-realistic']
        self.assertEqual(len(product), 36)
        ingress = {cell['ingress_per_s'] for cell in product}
        fanout = {cell['fanout'] for cell in product}
        sinks = {cell['sink'] for cell in product}
        self.assertEqual(ingress, {20, 100, 200})
        self.assertEqual(fanout, {'0', '1', 'all'})
        self.assertEqual(sinks, set(cases.SINK_PROFILE))

    def test_integrity_detects_missing_and_extra_counts(self):
        missing = cases.integrity_defects({
            'expected': {'delivery_rows': 2, 'queue_jobs': 2, 'sink_receives': 2},
            'actual': {'delivery_rows': 1, 'queue_jobs': 2, 'sink_receives': 3},
            'sink_fields': ['event_id', 'signature'],
        })
        self.assertIn('missing_delivery_rows', missing)
        self.assertIn('extra_sink_receives', missing)
        self.assertIn('sink_recorded_signature', missing)

    def test_s0_cells_are_one_of_each_required_control(self):
        ids = {cell['cell_id'] for cell in cases.queue.catalog()}
        for cell_id in cases.S0_CELLS:
            self.assertIn(cell_id, ids)
        self.assertTrue(any(cell_id.endswith('.2xx') for cell_id in cases.S0_CELLS))
        self.assertTrue(any(cell_id.endswith('.500') for cell_id in cases.S0_CELLS))
        self.assertTrue(any('relay2-retry2' in cell_id for cell_id in cases.S0_CELLS))
        self.assertTrue(any('duplicate-job.concurrent' in cell_id for cell_id in cases.S0_CELLS))

    def test_cli_rejects_a_bad_report(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'report.json'
            path.write_text(json.dumps({
                'expected': {'delivery_rows': 1, 'queue_jobs': 1, 'sink_receives': 1},
                'actual': {'delivery_rows': 2, 'queue_jobs': 0, 'sink_receives': 1},
                'sink_fields': ['body'],
            }), encoding='utf-8')
            code = cases.main(['integrity', '--report', str(path)])
        self.assertEqual(code, 1)


if __name__ == '__main__':
    unittest.main()
