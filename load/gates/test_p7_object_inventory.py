"""Offline object collectors and complete pre-cleanup P7 inventory controls."""

import base64
import copy
import hashlib
import json
import subprocess
import unittest
from contextlib import ExitStack
from unittest.mock import patch

import p7_chaos as gate
import p7_live_matrix as live
import test_p7_evidence as evidence
import test_p7_integrity_contract as contract


STAGED = 'tmp/uploads/inventory-upload'
PREFIX = STAGED + '.parts/'
FINAL = evidence.FINAL_KEY


def absent(key):
    return {'key': key, 'exists': False, 'checksum': None, 'size': 0, 'observation': 'absent'}


def present(key, *, checksum=evidence.CHECKSUM, size=16):
    return {'key': key, 'exists': True, 'checksum': checksum, 'size': size, 'observation': 'observed'}


class InventoryTest(unittest.TestCase):
    def window(self, *, status='committed', parts=(1,), objects=None, listing=None,
               blob=True, baseline=None, missing_session=False, received=None, part_sizes=None):
        cell = next(row for row in gate.catalog() if row['cell_id'] == 'product-realistic.s3-complete.503')
        fixture = {'run_id': evidence.RUN, 'marker': live.product_ops.marker_for(evidence.RUN, cell['cell_id'], 1),
                   'upload_id': 'inventory-upload', 'cas_scope': 'global',
                   'payload_sha256': evidence.CHECKSUM, 'requested_bytes': 16}
        entities = [{'entity': 'upload_session', 'entity_id': 'session-1', 'upload_id': 'inventory-upload',
                     'status': status, 'received_bytes': 16 * len(parts) if received is None else received,
                     'assembled_blob_id': 'blob-1' if blob else None}]
        if missing_session:
            entities = [{'entity': 'outbox', 'entity_id': fixture['marker'], 'status': 'absent'}]
        else:
            entities.extend({'entity': 'upload_part', 'entity_id': f'session-1:{number}', 'status': 'recorded',
                             'checksum': evidence.CHECKSUM,
                             'size': part_sizes[number] if part_sizes is not None else 16} for number in parts)
            if blob:
                entities.append({'entity': 'blob', 'entity_id': 'blob-1', 'status': 'committed',
                                 'storage_key': FINAL, 'checksum': evidence.CHECKSUM, 'size': 16})
        objects = {FINAL: present(FINAL)} if objects is None else objects
        if listing is None:
            listing = {'status': 'observed', 'keys': sorted(key for key, obj in objects.items()
                                                         if key.startswith(PREFIX) and obj.get('exists') is True)}
        streams = {name: [] for name in gate.JSONL_FILES}
        events = []
        def sample(key, checksum=None):
            events.append(('sample', key))
            if key == FINAL and len(events) == 1:
                return baseline if baseline is not None else absent(key)
            return copy.deepcopy(objects.get(key, absent(key)))
        def cleanup(fixture, entities, captured, *, ha):
            events.append(('cleanup', None))
            self.assertEqual(len(streams['objects.jsonl']), len(captured))
            self.assertTrue(streams['reconcile.jsonl'])
            self.assertEqual({row['key'] for row in captured}, {row['key'] for row in streams['objects.jsonl']})
        with ExitStack() as stack:
            stack.enter_context(patch.object(live, '_run', side_effect=AssertionError('live boundary')))
            for name, value in {'host_status': 200, 'host_probe': (200, 'vaulter_metric 1'),
                                'revert': None, 'sample_entities': entities, 'object_listing': listing}.items():
                stack.enter_context(patch.object(live, name, return_value=value))
            stack.enter_context(patch.object(live.product_ops.ProductOperationRunner, 'prepare', return_value=fixture))
            stack.enter_context(patch.object(live.product_ops.ProductOperationRunner, 'execute', return_value={
                'operation': 's3-complete', 'status': 201 if blob else 503, 'transport': 'http',
                'request_id': 'inventory-request-1', 'requested_bytes': 16, 'written_bytes': 0}))
            arm = stack.enter_context(patch.object(live, 'arm', return_value='s3-fault-proxy'))
            stack.enter_context(patch.object(live, 'barrier_ack', return_value=evidence.ack_for(cell)))
            stack.enter_context(patch.object(live, 'object_sample', side_effect=sample))
            stack.enter_context(patch.object(live, 'cleanup_fixture', side_effect=cleanup))
            if baseline is not None and baseline.get('observation') != 'absent':
                with self.assertRaises(live.LiveError):
                    live.run_window(streams, live.Clock(), cell, 1, evidence.RUN, gate.config_fingerprint(), 'a' * 64)
                arm.assert_not_called()
                self.assertEqual(events, [('sample', FINAL)])
                return
            record = live.run_window(streams, live.Clock(), cell, 1, evidence.RUN, gate.config_fingerprint(), 'a' * 64)
        self.assertEqual(events[-1], ('cleanup', None))
        return record, streams, events

    def check(self, record, streams, valid):
        self.assertEqual(record['evidence'], 'valid' if valid else 'invalid')
        if valid:
            live._raw_integrity(streams, streams['attempts.jsonl'][0])
        else:
            with self.assertRaises(gate.EvidenceInvalid):
                live._raw_integrity(streams, streams['attempts.jsonl'][0])

    def test_complete_records_final_and_absent_stage_and_parts_before_cleanup(self):
        record, streams, events = self.window()
        self.check(record, streams, True)
        self.assertEqual({row['key'] for row in streams['objects.jsonl']}, {STAGED, PREFIX+'1', FINAL})
        statuses = {(row['object_key'], row['join']): row['status'] for row in streams['reconcile.jsonl']}
        self.assertEqual(len(statuses), 6)
        self.assertEqual(statuses[(FINAL, 'db-to-object')], 'matched')
        self.assertEqual(statuses[(STAGED, 'object-to-db')], 'matched-absent')
        self.assertEqual(statuses[(PREFIX+'1', 'object-to-db')], 'matched-absent')
        contract.IntegrityContractTest('runTest').admit(record, streams)

    def test_leftover_part_after_complete_is_captured_and_rejected(self):
        record, streams, _ = self.window(objects={FINAL: present(FINAL), PREFIX+'1': present(PREFIX+'1')})
        self.check(record, streams, False)
        self.assertTrue(any(row['object_key'] == PREFIX+'1' and row['status'] == 'orphan-object'
                            for row in streams['reconcile.jsonl']))

    def test_leftover_staging_after_complete_is_rejected(self):
        record, streams, _ = self.window(objects={FINAL: present(FINAL), STAGED: present(STAGED)})
        self.check(record, streams, False)

    def test_extra_part_without_row_is_captured(self):
        record, streams, events = self.window(objects={FINAL: present(FINAL), PREFIX+'99': present(PREFIX+'99')})
        self.check(record, streams, False)
        self.assertIn(('sample', PREFIX+'99'), events)
        self.assertIn(PREFIX+'99', streams['attempts.jsonl'][0]['object_inventory']['part_listing']['keys'])

    def test_part_known_to_fixture_is_checked_when_its_row_is_gone(self):
        record, streams, _ = self.window(parts=(), objects={FINAL: present(FINAL), PREFIX+'1': present(PREFIX+'1')})
        self.check(record, streams, False)
        self.assertIn(PREFIX+'1', {row['key'] for row in streams['objects.jsonl']})

    def test_active_multipart_requires_each_part_and_absent_stage_and_final(self):
        record, streams, _ = self.window(status='uploading', blob=False, parts=(1, 2),
                                        objects={PREFIX+'1': present(PREFIX+'1'), PREFIX+'2': present(PREFIX+'2')})
        self.check(record, streams, True)
        self.assertEqual(len(streams['objects.jsonl']), 4)
        contract.IntegrityContractTest('runTest').admit(record, streams)

    def test_missing_second_required_part_is_rejected(self):
        record, streams, _ = self.window(status='uploading', blob=False, parts=(1, 2),
                                        objects={PREFIX+'1': present(PREFIX+'1')})
        self.check(record, streams, False)

    def test_each_part_checksum_and_size_are_compared(self):
        for change in ({'checksum': 'c'*64}, {'size': 15}):
            with self.subTest(change=change):
                record, streams, _ = self.window(status='uploading', blob=False, parts=(1, 2),
                    objects={PREFIX+'1': present(PREFIX+'1'), PREFIX+'2': present(PREFIX+'2', **change)})
                self.check(record, streams, False)

    def test_missing_required_final_is_rejected(self):
        record, streams, _ = self.window(objects={})
        self.check(record, streams, False)

    def test_final_without_blob_row_is_an_orphan(self):
        record, streams, _ = self.window(status='initiated', parts=(), blob=False)
        self.check(record, streams, False)

    def test_abort_and_expiry_require_absence_even_with_retained_part_rows(self):
        for status in ('aborted', 'expired'):
            with self.subTest(status=status):
                record, streams, _ = self.window(status=status, blob=False, objects={})
                self.check(record, streams, True)
                self.assertTrue(all(row['status'] == 'matched-absent' for row in streams['reconcile.jsonl']))
                record, streams, _ = self.window(status=status, blob=False, objects={PREFIX+'1': present(PREFIX+'1')})
                self.check(record, streams, False)

    def test_collector_error_never_means_absence(self):
        for key in (STAGED, PREFIX+'1', FINAL):
            with self.subTest(key=key):
                unknown = {**absent(key), 'exists': None, 'observation': 'observation-error'}
                record, streams, _ = self.window(objects={FINAL: present(FINAL), key: unknown})
                self.check(record, streams, False)
                self.assertTrue(any(row['status'] == 'observation-error' for row in streams['reconcile.jsonl']))

    def test_listing_error_cannot_claim_complete_inventory(self):
        record, streams, _ = self.window(listing={'status': 'observation-error', 'keys': []})
        self.check(record, streams, False)
        self.assertEqual(len(streams['objects.jsonl']), 3)

    def test_foreign_listing_key_is_not_read_or_deleted(self):
        foreign = 'tmp/uploads/foreign.parts/1'
        record, streams, events = self.window(listing={'status': 'observed', 'keys': [foreign]})
        self.check(record, streams, False)
        self.assertNotIn(('sample', foreign), events)
        self.assertNotIn(foreign, {row['key'] for row in streams['objects.jsonl']})

    def test_missing_db_session_still_captures_scoped_identities(self):
        record, streams, _ = self.window(missing_session=True)
        self.check(record, streams, False)
        self.assertEqual({row['key'] for row in streams['objects.jsonl']}, {STAGED, PREFIX+'1', FINAL})

    def test_preexisting_or_unobserved_final_refuses_before_fault(self):
        self.window(baseline=present(FINAL))
        self.window(baseline={**absent(FINAL), 'exists': None, 'observation': 'observation-error'})

    def test_missing_session_still_captures_actual_extra_owned_parts(self):
        record, streams, events = self.window(missing_session=True,
                                              objects={FINAL: present(FINAL), PREFIX+'99': present(PREFIX+'99')})
        self.check(record, streams, False)
        self.assertIn(('sample', PREFIX+'99'), events)
        self.assertTrue(any(row['object_key'] == PREFIX+'99' and row['status'] == 'orphan-object'
                            for row in streams['reconcile.jsonl']))

    def test_listing_and_exact_observation_race_invalidates_producer_and_reader(self):
        record, streams, _ = self.window(listing={'status':'observed','keys':[PREFIX+'1']})
        self.check(record, streams, False)
        self.assertTrue(any(row['object_key'] == 'inventory' and row['status'] == 'observation-error'
                            for row in streams['reconcile.jsonl']))

    def test_full_reader_rejects_forged_success_for_orphans_and_errors(self):
        for options in ({'objects': {FINAL: present(FINAL), PREFIX+'1': present(PREFIX+'1')}},
                        {'objects': {FINAL: present(FINAL), PREFIX+'99': present(PREFIX+'99')}},
                        {'objects': {}}, {'listing': {'status': 'observation-error', 'keys': []}}):
            with self.subTest(options=options):
                record, streams, _ = self.window(**options)
                record['evidence'] = 'valid'
                for row in streams['reconcile.jsonl']:
                    row['status'] = 'matched'
                with self.assertRaises(gate.EvidenceInvalid):
                    contract.IntegrityContractTest('runTest').admit(record, streams)

    def test_reader_rejects_dropped_part_observation_and_missing_listing(self):
        _, streams, _ = self.window()
        for mutate in (lambda raw: raw['object_inventory'].pop('part_listing'),
                       lambda raw: streams['objects.jsonl'].pop()):
            with self.subTest(mutate=mutate):
                copied = copy.deepcopy(streams)
                raw = streams['attempts.jsonl'][0]
                mutate(raw)
                with self.assertRaises(gate.EvidenceInvalid):
                    live._raw_integrity(streams, raw)
                streams = copied

    def test_cleanup_only_uses_captured_owned_keys(self):
        fixture = {'upload_id': 'inventory-upload', 'marker': 'offline-cleanup', 'cas_scope': 'global',
                   'payload_sha256': evidence.CHECKSUM, 'owned_final_key': FINAL}
        observed = [present(STAGED), present(PREFIX+'99'), present(FINAL), present('tmp/uploads/foreign'),
                    {**absent(PREFIX+'2'), 'exists': None, 'observation': 'observation-error'}]
        with patch.object(live, '_run'), patch.object(live, 'remove_object') as remove:
            live.cleanup_fixture(fixture, [], observed, ha=True)
        self.assertEqual([call.args[0] for call in remove.call_args_list], [STAGED, PREFIX+'99', FINAL])
        fixture.pop('owned_final_key')
        with patch.object(live, '_run'), patch.object(live, 'remove_object') as remove:
            live.cleanup_fixture(fixture, [], [present(FINAL)], ha=True)
        remove.assert_not_called()

    def test_malformed_join_and_scope_fail_as_evidence_invalid(self):
        for mutate in (lambda streams: streams['reconcile.jsonl'][0].pop('object_key'),
                       lambda streams: streams['attempts.jsonl'][0]['object_inventory'].update(marker='../foreign'),
                       lambda streams: streams['attempts.jsonl'][0]['object_inventory'].update(upload_id=0)):
            with self.subTest(mutate=mutate):
                _, streams, _ = self.window()
                mutate(streams)
                with self.assertRaises(gate.EvidenceInvalid):
                    live._raw_integrity(streams, streams['attempts.jsonl'][0])

    def test_inconsistent_observation_and_boolean_size_are_collector_errors(self):
        for value in ({**absent(STAGED), 'observation': 'observed'}, {**absent(STAGED), 'size': False}):
            with self.subTest(value=value):
                record, streams, _ = self.window(objects={FINAL: present(FINAL), STAGED: value})
                self.check(record, streams, False)


class McCollectorTest(unittest.TestCase):
    def run_sample(self, *responses):
        with patch.object(live, '_run', side_effect=responses) as peer:
            result = live.object_sample(STAGED)
        self.assertTrue(all('KEY='+STAGED in call.args[0] for call in peer.call_args_list))
        return result

    def response(self, value, code=0):
        return subprocess.CompletedProcess([], code, value if isinstance(value, str) else json.dumps(value), '')

    def test_only_exact_structured_no_such_key_is_absence(self):
        error = {'status': 'error', 'error': {'cause': {'error': {'Code': 'NoSuchKey',
                 'BucketName': live.BUCKET, 'Key': STAGED}}}}
        self.assertEqual(self.run_sample(self.response(error, 1)), absent(STAGED))
        for field, value in (('Code', 'AccessDenied'), ('Code', 'NoSuchBucket'), ('Key', 'foreign')):
            wrong = copy.deepcopy(error)
            wrong['error']['cause']['error'][field] = value
            self.assertEqual(self.run_sample(self.response(wrong, 1))['observation'], 'observation-error')

    def test_nonzero_cat_timeout_and_malformed_stat_are_errors(self):
        for response in (self.response('not JSON', 1), self.response('', 1), subprocess.TimeoutExpired([], 1)):
            with self.subTest(response=response):
                self.assertIsNone(self.run_sample(response)['exists'])
        self.assertIsNone(self.run_sample(self.response({'status':'success','size':16}), self.response('',1))['exists'])

    def test_mc_object_missing_type_is_bound_to_the_exact_stat_target(self):
        message = {'status': 'error', 'error': {'message': f'Unable to stat `local/{live.BUCKET}/{STAGED}`.',
                   'cause': {'message': 'Object does not exist', 'error': {}}}}
        with patch.object(live, '_run', return_value=self.response(message, 1)) as peer:
            self.assertEqual(live.object_sample(STAGED), absent(STAGED))
            self.assertIn('stat --no-list --json', peer.call_args.args[0][-1])
        for wrong in ({**message, 'status': 'success'},
                      {**message, 'error': {**message['error'], 'message': 'Unable to stat `foreign`.'}},
                      {**message, 'error': {**message['error'], 'cause': {'message': 'Access Denied', 'error': {}}}}):
            self.assertIsNone(self.run_sample(self.response(wrong, 1))['exists'])

    def test_binary_bytes_are_hashed_without_text_decoding(self):
        raw = b'\x00\xff\x80\r\n'
        result = self.run_sample(self.response({'status':'success','size':len(raw)}),
                                 self.response(base64.b64encode(raw).decode()))
        self.assertEqual(result['checksum'], hashlib.sha256(raw).hexdigest())
        self.assertEqual(result['size'], len(raw))

    def test_size_race_and_invalid_base64_are_observation_errors(self):
        for body in ('%%%','YQ=='):
            self.assertIsNone(self.run_sample(self.response({'status':'success','size':16}), self.response(body))['exists'])

    def test_listing_parses_pretty_json_and_is_strictly_scoped(self):
        body = '\n'.join(json.dumps({'status':'success','type':'file','key':key}, indent=2) for key in ('2','1'))
        with patch.object(live, '_run', return_value=self.response(body)) as peer:
            self.assertEqual(live.object_listing(PREFIX), {'status':'observed','keys':[PREFIX+'1',PREFIX+'2']})
            self.assertIn('KEY='+PREFIX, peer.call_args.args[0])
            with self.assertRaises(gate.EvidenceInvalid):
                live.object_listing('tmp/uploads/')
            self.assertEqual(peer.call_count, 1)

    def test_listing_errors_duplicates_and_traversal_are_not_empty_success(self):
        for response in (self.response('',1), self.response('broken'),
                         self.response({'status':'error'}), self.response({'status':'success','type':'file','key':'../foreign'}),
                         self.response('\n'.join(json.dumps({'status':'success','type':'file','key':'1'}) for _ in range(2)))):
            with self.subTest(response=response), patch.object(live, '_run', return_value=response):
                self.assertEqual(live.object_listing(PREFIX)['status'], 'observation-error')


if __name__ == '__main__':
    unittest.main()
