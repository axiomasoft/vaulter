#!/usr/bin/env python3
"""Static checks for P7.2 injectors, guards, and the fidelity matrix."""

from __future__ import annotations

import importlib.util
import json
import sys
import tempfile
import unittest
from pathlib import Path

GATES = Path(__file__).resolve().parent
LOAD = GATES.parent
spec = importlib.util.spec_from_file_location('p7_injectors', GATES / 'p7_injectors.py')
injectors = importlib.util.module_from_spec(spec)
sys.modules['p7_injectors'] = injectors
spec.loader.exec_module(injectors)


class InjectorMatrixTest(unittest.TestCase):
    def test_every_lane_cell_is_fidelity_labelled(self):
        summary = injectors.static_validate()
        self.assertEqual(summary['cells'], 51)
        self.assertEqual(summary['s0'], list(injectors.S0_CELLS))
        self.assertEqual(summary['injector_version'], 'p7.2-injectors')
        cells = injectors.matrix()
        self.assertEqual({cell['plane'] for cell in cells}, set(injectors.lane.PLANES))
        for cell in cells:
            self.assertTrue(cell['sleep_is_not_barrier'])
            self.assertEqual(cell['multipart_cleanup'], 'flysystem-part-keys')
            self.assertIsNone(cell['aws_upload_id'])
            self.assertFalse(cell['rto_verdict'])

    def test_s0_cells_cover_required_faults(self):
        ids = set(injectors.S0_CELLS)
        self.assertTrue(any('503' in cell_id for cell_id in ids))
        self.assertTrue(any(cell_id.endswith('.reset') for cell_id in ids))
        self.assertTrue(any(cell_id.endswith('.down') for cell_id in ids))
        self.assertTrue(any(cell_id.endswith('.enospc') for cell_id in ids))

    def test_protocol_503_is_not_a_tcp_fault(self):
        cell = next(
            item for item in injectors.matrix()
            if item['cell_id'] == 'protocol-control.s3-put-part.503.pre-upstream'
        )
        self.assertEqual(cell['injector'], 's3-fault-proxy')
        self.assertTrue(cell['pre_upstream_distinct_from_tcp'])
        self.assertTrue(cell['not_real_s3_outage'])

    def test_promotion_stays_off_the_network_loss_plane(self):
        for cell in injectors.matrix():
            if cell['plane'] == 'transport-control':
                self.assertTrue(cell['separate_from_promotion'])
                self.assertNotEqual(cell['injector'], 'pg-failover')
            if cell['plane'] == 'primary-transition':
                self.assertTrue(cell['fence_before_promote'])
                self.assertTrue(cell['no_automatic_failback'])
                self.assertTrue(cell['separate_from_network_loss'])

    def test_guards_reject_shared_unfenced_and_tmpfs_escape(self):
        with self.assertRaises(injectors.InjectorError):
            injectors.reject_shared_endpoint('s3.amazonaws.com')
        with self.assertRaises(injectors.InjectorError):
            injectors.reject_shared_endpoint('postgres')
        with self.assertRaises(injectors.InjectorError):
            injectors.reject_unfenced_promotion(fenced=False)
        with self.assertRaises(injectors.InjectorError):
            injectors.reject_tmpfs_escape('/tmp/escape', '/var/tmp/vaulter-chaos')
        injectors.reject_shared_endpoint('toxiproxy')
        injectors.reject_unfenced_promotion(fenced=True)
        injectors.reject_tmpfs_escape(
            '/var/tmp/vaulter-chaos/fill.bin',
            '/var/tmp/vaulter-chaos',
        )

    def test_inspect_compose_rejects_direct_minio(self):
        with self.assertRaises(injectors.InjectorError):
            injectors.inspect_compose('MINIO_ENDPOINT: http://minio:9000\n')
        with tempfile.NamedTemporaryFile('w+', encoding='utf-8', delete=False) as handle:
            handle.write(
                's3-fault-proxy\ntoxiproxy\npostgres-primary\npostgres-standby\n'
                'MINIO_ENDPOINT: http://s3-fault-proxy:19000\n'
                'DB_HOST: toxiproxy\n/var/tmp/vaulter-chaos\n'
            )
            handle.flush()
            self.assertEqual(injectors.main(['inspect-compose', '--file', handle.name]), 0)

    def test_cli_guards_fail_closed(self):
        self.assertEqual(injectors.main(['guard', 'shared', '--target', 's3.amazonaws.com']), 1)
        self.assertEqual(injectors.main(['guard', 'unfenced']), 1)
        self.assertEqual(
            injectors.main(['guard', 'tmpfs', '--target', '/tmp/x', '--root', '/tmp']),
            1,
        )


if __name__ == '__main__':
    unittest.main()
