"""Received-byte conservation across actual P7 producer and full-reader admission."""

import unittest

import p7_chaos as gate
import p7_live_matrix as live
import test_p7_integrity_contract as contract
import test_p7_object_inventory as inventory


class MultipartIntegrityTest(unittest.TestCase):
    def window(self, **options):
        record, streams, _ = inventory.InventoryTest('runTest').window(**options)
        return record, streams

    def assert_admitted(self, record, streams):
        self.assertEqual(record['evidence'], 'valid')
        contract.IntegrityContractTest('runTest').admit(record, streams)

    def assert_rejected(self, record, streams, message):
        self.assertEqual(record['evidence'], 'invalid')
        with self.assertRaisesRegex(gate.EvidenceInvalid, message):
            live._raw_integrity(streams, streams['attempts.jsonl'][0])
        # Forge success labels: the full reader must derive integrity from raw DB state.
        record['evidence'] = 'valid'
        for row in streams['reconcile.jsonl']:
            row['status'] = 'matched-absent'
        with self.assertRaisesRegex(gate.EvidenceInvalid, message):
            contract.IntegrityContractTest('runTest').admit(record, streams)

    def test_lost_rows_and_objects_with_received_bytes_are_rejected(self):
        for status in ('initiated', 'uploading'):
            with self.subTest(status=status):
                self.assert_rejected(*self.window(status=status, blob=False, parts=(),
                    received=16, objects={}), 'multipart received bytes contradict durable parts')

    def test_lost_subset_of_rows_and_objects_is_rejected(self):
        self.assert_rejected(*self.window(status='uploading', blob=False, received=32,
            objects={inventory.PREFIX+'1': inventory.present(inventory.PREFIX+'1')}),
            'multipart received bytes contradict durable parts')

    def test_recorded_total_above_received_is_rejected(self):
        self.assert_rejected(*self.window(status='uploading', blob=False, parts=(1, 2), received=16,
            objects={inventory.PREFIX+str(n): inventory.present(inventory.PREFIX+str(n)) for n in (1, 2)}),
            'multipart received bytes contradict durable parts')

    def test_duplicate_parts_cannot_supply_received_total(self):
        self.assert_rejected(*self.window(status='uploading', blob=False, parts=(1, 1), received=32,
            objects={inventory.PREFIX+'1': inventory.present(inventory.PREFIX+'1')}),
            'duplicate (part identity|durable DB identity)')

    def test_malformed_part_sizes_are_rejected(self):
        for size in (-1, True, '16', None):
            with self.subTest(size=size):
                self.assert_rejected(*self.window(status='uploading', blob=False, received=16,
                    part_sizes={1: size}, objects={}), 'invalid part size')

    def test_unequal_nonconsecutive_parts_conserve_received_total(self):
        # S3 part numbers need not be contiguous, and parts need not have equal sizes.
        self.assert_admitted(*self.window(status='uploading', blob=False, parts=(1, 7), received=16,
            part_sizes={1: 5, 7: 11}, objects={inventory.PREFIX+str(n):
                inventory.present(inventory.PREFIX+str(n), size=size) for n, size in ((1, 5), (7, 11))}))

    def test_zero_byte_pre_write_absence_remains_admitted(self):
        self.assert_admitted(*self.window(status='initiated', blob=False, parts=(), received=0, objects={}))

    def test_assembled_bytes_are_proved_by_staging_without_part_rows(self):
        self.assert_admitted(*self.window(status='assembled', blob=False, parts=(), received=16,
            objects={inventory.STAGED: inventory.present(inventory.STAGED)}))
        record, streams = self.window(status='assembled', blob=False, parts=(), received=16, objects={})
        self.assertEqual(record['evidence'], 'invalid')
        with self.assertRaises(gate.EvidenceInvalid):
            contract.IntegrityContractTest('runTest').admit(record, streams)

    def test_terminal_cleanup_does_not_require_part_rows(self):
        for status in ('committed', 'aborted', 'expired'):
            with self.subTest(status=status):
                self.assert_admitted(*self.window(status=status, blob=status == 'committed',
                    parts=(), received=16, objects={inventory.FINAL: inventory.present(inventory.FINAL)}
                    if status == 'committed' else {}))


if __name__ == '__main__':
    unittest.main()
