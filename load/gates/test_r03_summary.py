"""Focused checks for full-window and dense class-B evidence."""

import unittest

from r03_summary import audit


class R03SummaryTest(unittest.TestCase):
    def test_premature_abort_does_not_become_proof(self):
        summary = {
            'meta': {'profile': 'lab', 'tier': 'S', 'expected_class_b_keys': ['http_req_duration{op:OP-12,tier:S}']},
            'state': {'testRunDurationMs': 32000},
            'metrics': {'http_req_duration{op:OP-12,tier:S}': {
                'values': {'count': 120, 'p(95)': 200},
                'thresholds': {'p(95)<300': {'ok': True}},
            }},
        }
        report = audit(summary, '60s')
        self.assertFalse(report['full_window'])
        self.assertFalse(report['proof_ready'])

    def test_sparse_trend_is_unevaluated(self):
        summary = {
            'meta': {'profile': 'lab', 'tier': 'S', 'expected_class_b_keys': ['http_req_duration{op:OP-12,tier:S}']},
            'state': {'testRunDurationMs': 181000},
            'metrics': {'http_req_duration{op:OP-12,tier:S}': {
                'values': {'count': 20, 'p(95)': 200},
                'thresholds': {'p(95)<300': {'ok': True}},
            }},
        }
        report = audit(summary, '180s')
        self.assertEqual(report['class_b']['http_req_duration{op:OP-12,tier:S}']['status'], 'unevaluated')
        self.assertFalse(report['proof_ready'])

    def test_dense_full_window_can_prove_a_red_product_metric(self):
        key = 'http_req_duration{op:OP-12,tier:S}'
        summary = {
            'meta': {'profile': 'lab', 'tier': 'S', 'expected_class_b_keys': [key]},
            'state': {'testRunDurationMs': 181000},
            'metrics': {key: {
                'values': {'count': 120, 'p(95)': 400},
                'thresholds': {'p(95)<300': {'ok': False}},
            }},
        }
        report = audit(summary, '180s')
        self.assertTrue(report['proof_ready'])
        self.assertEqual(report['class_b'][key]['status'], 'fail')


if __name__ == '__main__':
    unittest.main()
