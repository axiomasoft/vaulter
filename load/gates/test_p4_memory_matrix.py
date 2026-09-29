#!/usr/bin/env python3
"""Fixture checks for P4.3 memory matrix verify-matrix gate."""

from __future__ import annotations

import importlib.util
import shutil
import sys
import tempfile
import unittest
from pathlib import Path

GATES = Path(__file__).resolve().parent
FIXTURES = GATES / 'fixtures' / 'p4-matrix' / 'valid-contract'

spec = importlib.util.spec_from_file_location('p4_memory_matrix', GATES / 'p4_memory_matrix.py')
matrix = importlib.util.module_from_spec(spec)
sys.modules['p4_memory_matrix'] = matrix
spec.loader.exec_module(matrix)


class P4MemoryMatrixGateTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        if not FIXTURES.is_dir():
            matrix.write_contract_artifacts(
                FIXTURES,
                run_id='00000000-0000-4000-8000-00000000p403',
                tier='S',
                fingerprint='deadbeefcafebabe',
                dataset_id='vlt-load-p4-matrix-fixture',
                mode='contract',
            )

    def test_verify_matrix_valid_contract(self) -> None:
        digest = matrix.verify_matrix(FIXTURES)
        self.assertEqual(len(digest), 64)
        matrix.verify_matrix(FIXTURES, prior_digest=digest)

    def test_reject_attempt_count(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            shutil.copytree(FIXTURES, root, dirs_exist_ok=True)
            cases = matrix.load_json(root / 'cases.json')
            cases['attempts'] = cases['attempts'][:44]
            matrix.dump_json(root / 'cases.json', cases)
            with self.assertRaises(matrix.MatrixError):
                matrix.verify_matrix(root)

    def test_reject_size_bytes(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            shutil.copytree(FIXTURES, root, dirs_exist_ok=True)
            cases = matrix.load_json(root / 'cases.json')
            cases['attempts'][0]['size_bytes'] = 1
            matrix.dump_json(root / 'cases.json', cases)
            with self.assertRaises(matrix.MatrixError):
                matrix.verify_matrix(root)

    def test_matrix_cells_live_override(self) -> None:
        dry = matrix.matrix_cells_from_node(live=False)
        self.assertTrue(any(cell['dry_run'] for cell in dry))
        live = matrix.matrix_cells_from_node(live=True)
        self.assertFalse(any(cell['dry_run'] for cell in live))

    def test_execution_order_sizes(self) -> None:
        cells = matrix.matrix_cells_from_node(live=False)
        ordered = matrix.execution_order(cells)
        size_ids = [cell['size_id'] for cell in ordered]
        self.assertEqual(size_ids[:5], ['5m'] * 5)
        self.assertEqual(size_ids[5:10], ['500m'] * 5)
        self.assertEqual(size_ids[10:15], ['5g'] * 5)


if __name__ == '__main__':
    unittest.main()
