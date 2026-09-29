"""Offline producer/reader regressions for the captured P7 DB/object pair."""

import copy
import tempfile
import unittest
from contextlib import ExitStack
from datetime import datetime
from pathlib import Path
from unittest.mock import patch

import p7_chaos as gate
import p7_live_matrix as live
import test_p7_evidence as evidence


class IntegrityContractTest(unittest.TestCase):
    def window(self, *, operation='tus-patch', status='initiated', received=0,
               exists=False, http=503, attempt=1, part=False, blob=False,
               checksum=evidence.CHECKSUM, recovery=True):
        cid = f'product-realistic.{operation}.503'
        cell = next(row for row in gate.catalog() if row['cell_id'] == cid)
        fixture = {'marker': live.product_ops.marker_for(evidence.RUN, cid, attempt),
                   'run_id': evidence.RUN, 'upload_id': 'offline-upload',
                   'payload_sha256': evidence.CHECKSUM, 'requested_bytes': 16, 'cas_scope': 'global'}
        upload = {'entity': 'upload_session', 'entity_id': 'session-1',
                  'upload_id': fixture['upload_id'], 'status': status, 'received_bytes': received,
                  'assembled_blob_id': 'blob-1' if blob else None}
        entities = [upload]
        if part:
            entities.append({'entity': 'upload_part', 'entity_id': 'session-1:1',
                             'status': 'stored', 'checksum': evidence.CHECKSUM, 'size': 16})
        if blob:
            entities.append({'entity': 'blob', 'entity_id': 'blob-1', 'status': 'committed',
                             'storage_key': evidence.FINAL_KEY, 'checksum': evidence.CHECKSUM, 'size': 16})
        suffix = '.parts/1' if operation in {'s3-put-part', 's3-complete', 'sweeper'} else ''
        key = evidence.FINAL_KEY if blob else f'tmp/uploads/{fixture["upload_id"]}{suffix}'
        obj = {'key': key, 'exists': exists, 'checksum': checksum if exists else None,
               'size': 16 if exists else 0, 'observation': 'observed' if exists else 'absent'}
        self.primary_key = key
        sampled = []
        def sample_object(requested, expected_checksum=None):
            sampled.append(requested)
            if requested == key and (requested != evidence.FINAL_KEY or len(sampled) > 1):
                return obj
            return {'key': requested, 'exists': False, 'checksum': None, 'size': 0, 'observation': 'absent'}
        result = {'operation': operation, 'status': http, 'transport': 'http',
                  'request_id': 'offline-request-1', 'requested_bytes': 16, 'written_bytes': 0}
        streams = {name: [] for name in gate.JSONL_FILES}
        with ExitStack() as stack:
            # Unexpected dependency calls fail before they can reach a real stand.
            stack.enter_context(patch.object(live, '_run', side_effect=AssertionError('live boundary')))
            for name, value in {'host_probe': (200, 'vaulter_metric 1'), 'revert': None,
                                'cleanup_fixture': None, 'sample_entities': entities}.items():
                stack.enter_context(patch.object(live, name, return_value=value))
            stack.enter_context(patch.object(live, 'host_status', side_effect=[200, 200, 200, 200 if recovery else 503]))
            stack.enter_context(patch.object(live, 'arm', return_value=live.fault_spec(cell, evidence.RUN)['injector']))
            stack.enter_context(patch.object(live, 'barrier_ack', return_value=evidence.ack_for(cell)))
            stack.enter_context(patch.object(live.product_ops.ProductOperationRunner, 'prepare', return_value=fixture))
            stack.enter_context(patch.object(live.product_ops.ProductOperationRunner, 'execute', return_value=result))
            stack.enter_context(patch.object(live, 'object_sample', side_effect=sample_object))
            stack.enter_context(patch.object(live, 'object_listing', return_value={
                'status': 'observed', 'keys': [key] if '.parts/' in key and exists else []}))
            record = live.run_window(streams, live.Clock(), cell, attempt, evidence.RUN,
                                     gate.config_fingerprint(), 'a' * 64)
            self.assertIn(key, sampled)
        return record, streams

    def assert_pair(self, record, streams, *, status, valid):
        self.assertEqual([row['status'] for row in streams['reconcile.jsonl'] if row['object_key'] == self.primary_key], [status, status])
        self.assertEqual(record['evidence'], 'valid' if valid else 'invalid')
        raw = streams['attempts.jsonl'][0]
        if valid:
            live._raw_integrity(streams, raw)
        else:
            with self.assertRaises(gate.EvidenceInvalid):
                live._raw_integrity(streams, raw)

    def test_pre_write_refusal_all_repeated_attempts(self):
        for attempt in (1, 2, 3):
            with self.subTest(attempt=attempt):
                record, streams = self.window(attempt=attempt)
                self.assert_pair(record, streams, status='matched-absent', valid=True)
                self.assertEqual(record['http_outcome'], 'rejected')
                self.assertEqual(record['durable_state'], 'pending')
                self.assertEqual(streams['database.jsonl'][0]['received_bytes'], 0)

    def test_clean_abort(self):
        record, streams = self.window(operation='abort', status='aborted', received=16, http=204)
        self.assert_pair(record, streams, status='matched-absent', valid=True)
        self.assertEqual(record['durable_state'], 'absent')

    def test_clean_expiry(self):
        record, streams = self.window(operation='sweeper', status='expired', received=16, http=200)
        self.assert_pair(record, streams, status='matched-absent', valid=True)
        self.assertEqual(record['durable_state'], 'absent')

    def test_missing_required_staging_object(self):
        record, streams = self.window(status='uploading', received=16)
        self.assert_pair(record, streams, status='missing-object', valid=False)

    def test_missing_required_part_object(self):
        record, streams = self.window(operation='s3-put-part', status='uploading', received=16, part=True)
        self.assert_pair(record, streams, status='missing-object', valid=False)

    def test_missing_required_blob_object(self):
        record, streams = self.window(operation='s3-complete', status='committed', received=16, blob=True)
        self.assert_pair(record, streams, status='missing-object', valid=False)

    def test_orphan_after_pre_write_refusal(self):
        record, streams = self.window(exists=True)
        self.assert_pair(record, streams, status='orphan-object', valid=False)

    def test_orphan_after_abort(self):
        record, streams = self.window(operation='abort', status='aborted', received=16, exists=True, http=204)
        self.assert_pair(record, streams, status='orphan-object', valid=False)

    def test_orphan_after_expiry(self):
        record, streams = self.window(operation='sweeper', status='expired', received=16, exists=True, http=200)
        self.assert_pair(record, streams, status='orphan-object', valid=False)

    def test_matched_present_object_remains_valid(self):
        record, streams = self.window(status='uploading', received=16, exists=True)
        self.assert_pair(record, streams, status='matched', valid=True)

    def test_checksum_mismatch_remains_invalid(self):
        record, streams = self.window(status='uploading', received=16, exists=True, checksum='c' * 64)
        self.assert_pair(record, streams, status='checksum-mismatch', valid=False)

    def test_matched_absent_still_requires_recovery(self):
        record, streams = self.window(recovery=False)
        self.assertEqual(record['evidence'], 'invalid')
        self.assertEqual(streams['reconcile.jsonl'][0]['status'], 'matched-absent')

    def admit(self, record, streams, *, force_absence=False):
        """Put actual producer output into a complete 51-cell/77-attempt fixture."""
        with tempfile.TemporaryDirectory(prefix='p7-integrity-contract-') as directory:
            root = Path(directory)
            evidence.build(root)
            cid, attempt = record['cell_id'], record['attempt']
            # Reserve a disjoint monotonic window after the fixture's 77 windows.
            ticks = sorted({row['monotonic_ns'] for events in streams.values() for row in events})
            mapped = {value: 10000 + rank for rank, value in enumerate(ticks)}
            for name, emitted in streams.items():
                values = [row for row in evidence.rows(root / name)
                          if (row['cell_id'], row['attempt']) != (cid, attempt)]
                values.extend({**row, 'monotonic_ns': mapped[row['monotonic_ns']]} for row in emitted)
                if force_absence and name == 'reconcile.jsonl':
                    for row in values:
                        if (row['cell_id'], row['attempt']) == (cid, attempt):
                            row['status'] = 'matched-absent'
                evidence.write_rows(root / name, values)
            record = copy.deepcopy(record)
            if force_absence:
                record['evidence'] = 'valid'
            evidence.write(root / 'attempts' / f'{cid}.attempt-{attempt}.json', record)
            cells = evidence.read(root / 'cells.json')
            if attempt == 1:
                for cell in cells:
                    if cell['cell_id'] == cid:
                        cell.update({field: record[field] for field in
                                     ('http_outcome', 'availability', 'durable_state',
                                      't_detect_ns', 't_restore_ns', 't_converge_ns')})
                evidence.write(root / 'cells.json', cells)
            start = datetime.fromisoformat(streams['readiness.jsonl'][0]['wall_time']).timestamp()
            end = datetime.fromisoformat(streams['ledger.jsonl'][-1]['wall_time']).timestamp()
            prom_path = root / 'prometheus' / f'{cid}.attempt-{attempt}.json'
            prom = evidence.read(prom_path)
            prom['data']['result'][0]['value'] = [(start + end) / 2, '1']
            evidence.write(prom_path, prom)
            evidence.write(root / 'terminal.json', {'schema': 'p7-run-terminal/v1',
                                                   'run_id': evidence.RUN, 'monotonic_ns': 20000})
            before = live.digest_tree(root)
            digest = live.verify_artifacts(root)
            self.assertEqual(digest, before)
            self.assertEqual(live.verify_artifacts(root, prior_digest=digest), before)

    def test_full_reader_accepts_producer_expected_absence(self):
        for case in ({}, {'attempt': 2},
                     {'operation': 'abort', 'status': 'aborted', 'received': 16, 'http': 204},
                     {'operation': 'sweeper', 'status': 'expired', 'received': 16, 'http': 200}):
            with self.subTest(case=case):
                self.admit(*self.window(**case))

    def test_full_reader_rejects_false_matched_absent_even_with_valid_labels(self):
        for case in ({'status': 'uploading', 'received': 16},
                     {'operation': 's3-put-part', 'status': 'uploading', 'received': 16, 'part': True},
                     {'operation': 's3-complete', 'status': 'committed', 'received': 16, 'blob': True},
                     {'exists': True},
                     {'operation': 'abort', 'status': 'aborted', 'received': 16, 'exists': True, 'http': 204},
                     {'operation': 'sweeper', 'status': 'expired', 'received': 16, 'exists': True, 'http': 200}):
            with self.subTest(case=case), self.assertRaisesRegex(
                    gate.EvidenceInvalid, 'missing required object or orphan object'):
                self.admit(*self.window(**case), force_absence=True)


if __name__ == '__main__':
    unittest.main()
