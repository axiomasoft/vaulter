#!/usr/bin/env python3
"""Static checks for the P5.2 case matrix."""

from __future__ import annotations

import importlib.util
import json
import subprocess
import unittest
from pathlib import Path

GATES = Path(__file__).resolve().parent
REPO = GATES.parents[1]
spec = importlib.util.spec_from_file_location('p5_cases', GATES / 'p5_cases.py')
cases = importlib.util.module_from_spec(spec)
spec.loader.exec_module(cases)


class CaseMatrixTest(unittest.TestCase):
    def test_signature_covers_lane_catalog(self):
        lane_ids = [cell['cell_id'] for cell in cases.LANE.catalog()]
        signed = cases.matrix_signature()
        self.assertEqual(lane_ids, [row['cell_id'] for row in signed])
        self.assertEqual(len(signed), len(set(lane_ids)))
        surfaces = {cell['surface'] for cell in cases.LANE.catalog()}
        self.assertEqual(surfaces, set(cases.LANE.SURFACES))

    def test_five_xx_is_never_success(self):
        for row in cases.matrix_signature():
            self.assertFalse(row['http_5xx_success'], row['cell_id'])

    def test_same_resource_409_is_outside_the_percent_budget(self):
        rows = [row for row in cases.matrix_signature() if 'same-resource' in row['cell_id']]
        self.assertGreaterEqual(len(rows), 1)
        for row in rows:
            self.assertEqual(row['expected_409'], 'protocol-owner-plus-conflicts')
            self.assertEqual(row['error_budget'], 'excluded-from-5pct')
            self.assertEqual(row['progress_owners'], 1)
            self.assertIn(409, row['expected_statuses'])

    def test_different_resource_409_stays_in_budget(self):
        rows = [row for row in cases.matrix_signature() if 'different-resource' in row['cell_id']]
        self.assertGreaterEqual(len(rows), 1)
        for row in rows:
            self.assertEqual(row['expected_409'], 'budgeted-5pct')
            self.assertEqual(row['error_budget'], 'within-5pct')

    def test_primitive_sql_has_no_op_or_slo(self):
        rows = [row for row in cases.matrix_signature() if row['cell_id'].startswith('primitive-control.')]
        self.assertGreaterEqual(len(rows), 1)
        for row in rows:
            self.assertIsNone(row['op'])
            self.assertIsNone(row['slo'])
            self.assertEqual(row['error_budget'], 'not-a-product-slo')

    def test_dirty_1m_is_not_tier_l_and_not_a_contract_cell(self):
        million = [row for row in cases.matrix_signature() if row['cell_id'].endswith('dirty-1m.n1')]
        self.assertEqual(len(million), 1)
        self.assertFalse(million[0]['tier_l_claim'])
        self.assertIsNone(million[0]['slo'])
        contract_ids = [cell['cell_id'] for cell in cases.contract_cells()]
        self.assertEqual(len(contract_ids), len(cases.LANE.SURFACES))
        self.assertTrue(all('dirty-1m' not in cell_id for cell_id in contract_ids))
        self.assertEqual(len({cell_id.split('.')[1] for cell_id in contract_ids}), len(cases.LANE.SURFACES))

    def test_javascript_signature_matches(self):
        script = r'''
import { matrixSignature } from "./load/k6/lib/concurrency-cases.js";
const rows = matrixSignature().map((row) => ({
  cell_id: row.cell_id,
  http_5xx_success: row.http_5xx_success,
  op: row.op,
  slo: row.slo,
  error_budget: row.error_budget,
  expected_409: row.expected_409,
  progress_owners: row.progress_owners,
  tier_l_claim: row.tier_l_claim,
  expected_statuses: row.expected_statuses,
}));
process.stdout.write(JSON.stringify(rows));
'''
        completed = subprocess.run(
            ['node', '--input-type=module', '-e', script],
            cwd=REPO,
            check=True,
            capture_output=True,
            text=True,
        )
        javascript = json.loads(completed.stdout)
        python_rows = []
        for row in cases.matrix_signature():
            python_rows.append({key: row[key] for key in (
                'cell_id', 'http_5xx_success', 'op', 'slo', 'error_budget',
                'expected_409', 'progress_owners', 'tier_l_claim', 'expected_statuses',
            )})
        self.assertEqual(javascript, python_rows)

    def test_published_matrix_matches_signature(self):
        published = json.loads((GATES / 'fixtures' / 'p5' / 'case-matrix.json').read_text(encoding='utf-8'))
        self.assertEqual(published['cells'], cases.matrix_signature())
        self.assertEqual(
            published['contract_cell_ids'],
            [cell['cell_id'] for cell in cases.contract_cells()],
        )


if __name__ == '__main__':
    unittest.main()
