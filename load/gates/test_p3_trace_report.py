"""Trace evidence must be correlated; analyzed counts require actual execution."""

import json
import tempfile
import unittest
from pathlib import Path

from p3_trace_report import TraceReportError, build_report


class P3TraceReportTest(unittest.TestCase):
    def fixture(self, root):
        (root / 'run-manifest.json').write_text(json.dumps({'run_id': 'r', 'dataset_id': 'd'}))
        (root / 'pgss-top20.json').write_text(json.dumps({'run_id': 'r', 'dataset_id': 'd', 'fingerprints': [
            {'query_fingerprint': str(number)} for number in range(20)
        ]}))
        (root / 'explain').mkdir()
        plan = {'schema_version': 'p3-explain-plan/v1', 'run_id': 'r', 'dataset_id': 'd',
                'query_fingerprint': '0', 'status': 'ok', 'plan': [
                    {'Execution Time': 0.1, 'Plan': {'Actual Rows': 0, 'Actual Loops': 1, 'Actual Total Time': 0.01}}
                ]}
        (root / 'explain/0.json').write_text(json.dumps(plan))
        rows = [
            {'run_id': 'r', 'command_id': 'http:tree_list', 'invocation_id': invocation,
             'sql_template_hash': 'a' * 64, 'ordinal': ordinal, 'duration_ms': 0.1,
             'connection': 'pgsql', 'in_transaction': False}
            for invocation, ordinal in [('1' * 16, 1), ('1' * 16, 2), ('2' * 16, 1)]
        ]
        (root / 'query-trace.jsonl').write_text('\n'.join(json.dumps(row) for row in rows))
        return plan

    def test_empty_trace_is_rejected(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            self.fixture(root)
            (root / 'query-trace.jsonl').write_text('')
            with self.assertRaisesRegex(TraceReportError, 'TRACE_EMPTY'):
                build_report(root)

    def test_multiplicity_is_scoped_to_invocation(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            self.fixture(root)
            report = build_report(root)
            self.assertEqual(report['top20_coverage']['analyzed'], 1)
            self.assertEqual(report['n_plus_one']['candidates'][0]['count'], 2)
            self.assertEqual(len(report['n_plus_one']['candidates']), 1)

    def test_unexecuted_wrong_identity_and_pending_plans_are_rejected(self):
        for failure in ('missing-plan', 'planner-only', 'dataset', 'run', 'pending', 'top20-dataset', 'duplicate-trace'):
            with self.subTest(failure=failure), tempfile.TemporaryDirectory() as directory:
                root = Path(directory)
                plan = self.fixture(root)
                if failure == 'missing-plan':
                    plan.pop('plan')
                elif failure == 'planner-only':
                    plan['plan'] = [{'Plan': {'Node Type': 'Seq Scan', 'Plan Rows': 1}}]
                elif failure == 'dataset':
                    plan['dataset_id'] = 'foreign'
                elif failure == 'run':
                    plan['run_id'] = 'foreign'
                elif failure == 'pending':
                    plan['status'] = 'pending'
                elif failure == 'top20-dataset':
                    path = root / 'pgss-top20.json'
                    top20 = json.loads(path.read_text())
                    top20['dataset_id'] = 'foreign'
                    path.write_text(json.dumps(top20))
                else:
                    path = root / 'query-trace.jsonl'
                    path.write_text(path.read_text() + '\n' + path.read_text())
                (root / 'explain/0.json').write_text(json.dumps(plan))
                with self.assertRaises(TraceReportError):
                    build_report(root)

    def test_rejected_plan_counts_as_attempted_without_analyzed(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            plan = self.fixture(root)
            plan.update(status='rejected', rejection_reason='binding type mismatch', plan=None)
            (root / 'explain/0.json').write_text(json.dumps(plan))
            report = build_report(root)
            self.assertEqual(report['top20_coverage']['attempted'], 1)
            self.assertEqual(report['top20_coverage']['analyzed'], 0)


if __name__ == '__main__':
    unittest.main()
