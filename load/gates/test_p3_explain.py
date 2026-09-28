#!/usr/bin/env python3
"""Unit tests for P3.3 explain/index helpers (no live DB)."""

from __future__ import annotations

import importlib.util
import json
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

GATES = Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location('p3_explain', GATES / 'p3_explain.py')
explain = importlib.util.module_from_spec(spec)
sys.modules['p3_explain'] = explain
spec.loader.exec_module(explain)


class P3ExplainHelpersTest(unittest.TestCase):
    def test_capture_and_index_default_to_selected_run_explain_directory(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            artifact_dir = Path(tmp)
            with patch.object(explain, 'capture_plans') as capture:
                explain.main(['capture', '--artifact-dir', tmp])
                self.assertEqual(capture.call_args.kwargs['explain_dir'], artifact_dir / 'explain')
            with patch.object(explain, 'build_index_inventory', return_value={}), \
                 patch.object(explain, 'build_index_review', return_value={}) as review:
                explain.main(['build-index', '--artifact-dir', tmp])
                self.assertEqual(review.call_args.args[1], artifact_dir / 'explain')

    def test_bind_placeholders(self) -> None:
        sql = 'SELECT * FROM v_nodes WHERE parent_id = $1 AND depth < $2'
        bound = explain.bind_placeholders(sql, [12, 50])
        self.assertIn('12', bound)
        self.assertIn('50', bound)

    def test_summarize_plan_detects_seq_scan(self) -> None:
        plan = [
            {
                'Plan': {
                    'Node Type': 'Seq Scan',
                    'Relation Name': 'v_nodes',
                    'Plan Rows': 1000,
                    'Actual Rows': 50,
                    'Actual Loops': 1,
                },
                'Planning Time': 0.1,
                'Execution Time': 1.2,
            }
        ]
        summary = explain.summarize_plan(plan)
        self.assertEqual(summary['seq_scan_count'], 1)
        self.assertEqual(summary['seq_scans'][0]['relation'], 'v_nodes')

    def test_verify_artifacts_requires_top20_coverage(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            artifact_dir = Path(tmp)
            manifest = {
                'run_id': 'run-1',
                'dataset_id': 'ds-1',
            }
            top20 = {
                'fingerprints': [{'query_fingerprint': 'fp1'}],
                'mandatory_hotspots': [],
            }
            (artifact_dir / 'run-manifest.json').write_text(json.dumps(manifest), encoding='utf-8')
            (artifact_dir / 'pgss-top20.json').write_text(json.dumps(top20), encoding='utf-8')
            (artifact_dir / 'dataset-profile.json').write_text(
                json.dumps({'settings': [], 'tables': []}),
                encoding='utf-8',
            )
            (artifact_dir / 'index-inventory.json').write_text(
                json.dumps({'families': [{'family': f'v_{i}'} for i in range(8)]}),
                encoding='utf-8',
            )
            (artifact_dir / 'index-review.json').write_text(
                json.dumps({'families': [{}] * 8}),
                encoding='utf-8',
            )
            (artifact_dir / 'hypopg-candidates.json').write_text(
                json.dumps({'candidates': [{'hypopg_reset': True, 'ddl': 'x'}]}),
                encoding='utf-8',
            )
            (artifact_dir / 'explain').mkdir()
            with self.assertRaises(explain.ExplainError):
                explain.verify_artifacts(artifact_dir)


if __name__ == '__main__':
    unittest.main()
