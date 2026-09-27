"""Trace evidence cannot infer N+1 from an empty or uncorrelated file."""

import json
import tempfile
import unittest
from pathlib import Path

from p3_trace_report import TraceReportError, build_report


class P3TraceReportTest(unittest.TestCase):
    def test_empty_trace_is_rejected(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / 'run-manifest.json').write_text(json.dumps({'run_id': 'r', 'dataset_id': 'd'}))
            (root / 'pgss-top20.json').write_text(json.dumps({'fingerprints': []}))
            (root / 'query-trace.jsonl').write_text('')
            with self.assertRaisesRegex(TraceReportError, 'TRACE_EMPTY'):
                build_report(root)

    def test_multiplicity_is_scoped_to_invocation(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / 'run-manifest.json').write_text(json.dumps({'run_id': 'r', 'dataset_id': 'd'}))
            (root / 'pgss-top20.json').write_text(json.dumps({'fingerprints': [
                {'query_fingerprint': str(number)} for number in range(20)
            ]}))
            (root / 'explain').mkdir()
            (root / 'explain' / '0.json').write_text(json.dumps({
                'run_id': 'r', 'query_fingerprint': '0', 'status': 'ok',
            }))
            rows = [
                {'run_id': 'r', 'command_id': 'http:tree_list', 'invocation_id': invocation,
                 'sql_template_hash': 'a' * 64}
                for invocation in ['i1', 'i1', 'i2']
            ]
            (root / 'query-trace.jsonl').write_text('\n'.join(json.dumps(row) for row in rows))
            report = build_report(root)
            self.assertEqual(report['top20_coverage']['analyzed'], 1)
            self.assertEqual(report['n_plus_one']['candidates'][0]['count'], 2)
            self.assertEqual(len(report['n_plus_one']['candidates']), 1)


if __name__ == '__main__':
    unittest.main()
