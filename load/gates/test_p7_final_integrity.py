"""Final CAS bytes must preserve the attempt payload, independently of DB agreement."""

import unittest

import p7_chaos as gate
import p7_live_matrix as live
import test_p7_integrity_contract as contract
import test_p7_object_inventory as inventory


class FinalIntegrityTest(unittest.TestCase):
    def window(self, **options):
        record, streams, _ = inventory.InventoryTest('runTest').window(**options)
        return record, streams

    def assert_rejected(self, record, streams, message):
        self.assertEqual(record['evidence'], 'invalid')
        with self.assertRaisesRegex(gate.EvidenceInvalid, message):
            live._raw_integrity(streams, streams['attempts.jsonl'][0])
        record['evidence'] = 'valid'
        for row in streams['reconcile.jsonl']:
            row['status'] = 'matched' if row['object_key'] == inventory.FINAL else 'matched-absent'
        with self.assertRaisesRegex(gate.EvidenceInvalid, message):
            contract.IntegrityContractTest('runTest').admit(record, streams)

    def test_matching_db_and_object_with_wrong_payload_are_rejected(self):
        self.assert_rejected(*self.window(blob_checksum='c'*64,
            objects={inventory.FINAL: inventory.present(inventory.FINAL, checksum='c'*64)}),
            'blob checksum contradicts payload and CAS key')

    def test_wrong_db_checksum_with_correct_object_is_rejected(self):
        self.assert_rejected(*self.window(blob_checksum='c'*64),
            'blob checksum contradicts payload and CAS key')

    def test_missing_or_malformed_blob_checksum_is_rejected(self):
        for checksum in (None, '', 'invalid', True):
            with self.subTest(checksum=checksum):
                self.assert_rejected(*self.window(blob_checksum=checksum),
                    'blob checksum contradicts payload and CAS key|absent checksum')

    def test_observed_checksum_must_match_payload_even_when_db_is_correct(self):
        self.assert_rejected(*self.window(
            objects={inventory.FINAL: inventory.present(inventory.FINAL, checksum='c'*64)}),
            'missing required object or orphan object or observation error')

    def test_observed_size_check_is_preserved(self):
        self.assert_rejected(*self.window(objects={inventory.FINAL: inventory.present(inventory.FINAL, size=15)}),
            'missing required object or orphan object or observation error')

    def test_correct_committed_payload_is_admitted_after_part_cleanup(self):
        for parts in ((), (1,)):
            with self.subTest(parts=parts):
                record, streams = self.window(parts=parts, received=16)
                self.assertEqual(record['evidence'], 'valid')
                contract.IntegrityContractTest('runTest').admit(record, streams)

    def test_full_reader_recomputes_payload_link_even_with_consistent_forged_metadata(self):
        record, streams = self.window()
        self.assertEqual(record['evidence'], 'valid')
        contract.IntegrityContractTest('runTest').admit(record, streams)
        for row in streams['database.jsonl']:
            if row['entity'] == 'blob':
                row['checksum'] = 'c'*64
        for item in streams['attempts.jsonl'][0]['object_inventory']['expected']:
            if item['role'] == 'final':
                item['checksum'] = 'c'*64
        for row in streams['objects.jsonl']:
            if row['role'] == 'final':
                row['checksum'] = 'c'*64
                row['expected']['checksum'] = 'c'*64
        with self.assertRaisesRegex(gate.EvidenceInvalid, 'blob checksum contradicts payload and CAS key'):
            contract.IntegrityContractTest('runTest').admit(record, streams)

    def test_full_reader_rejects_foreign_content_address(self):
        record, streams = self.window()
        for row in streams['database.jsonl']:
            if row['entity'] == 'blob':
                row['storage_key'] = 'global/cc/cc/'+'c'*64
        with self.assertRaisesRegex(gate.EvidenceInvalid, 'object does not join blob key'):
            contract.IntegrityContractTest('runTest').admit(record, streams)


if __name__ == '__main__':
    unittest.main()
