"""Ordinary offline controls for complete R11 evidence admission (no live peers)."""

import copy
import json
import tempfile
import unittest
from contextlib import ExitStack
from datetime import datetime, timezone
from pathlib import Path
from unittest.mock import patch

import p7_chaos as gate
import p7_live_matrix as live

RUN = 'offline-evidence'
CHECKSUM = 'b' * 64
CELL = gate.catalog()[0]['cell_id']
EPOCH = 1790632800


def ack_for(cell, run_id=RUN):
    spec = live.fault_spec(cell, run_id)
    ack = {'injector': spec['injector'], 'target': spec['target'], 'run_id': run_id}
    if spec['injector'] == 's3-fault-proxy':
        return {**ack, 'mode': spec['mode']}
    if spec['injector'] == 'toxiproxy':
        return {**ack, 'enabled': spec['enabled'], 'toxic': spec['toxic'] if spec['enabled'] else None}
    if spec['injector'] == 'pg-failover':
        return {**ack, 'primary': 'postgres-primary', 'standby': 'postgres-standby',
                'pgbouncer': 'pgbouncer-ha', 'primary_recovery': 'down', 'standby_recovery': 'f',
                'writable': '1', 'fenced': 'yes', 'promoted': 'yes', 'repointed': 'yes'}
    return {**ack, 'fstype': 'tmpfs', 'free_bytes_after': 0, 'write_length': 8192}


def holder_ready(cell, attempt, run_id=RUN):
    marker = live.product_ops.marker_for(run_id, cell['cell_id'], attempt)
    return {'schema': 'p7-lock-holder-ready/v1', 'run_prefix': marker, 'ordinal': 1,
            'event_id': marker + ':lock-holder:1', 'backend_pid': 123,
            'database': 'vaulter_chaos' if cell['plane'] == 'primary-transition' else 'vaulter_load',
            'lock_acquired': True}


def write(path, value):
    path.write_text(json.dumps(value) + '\n')


def read(path):
    return json.loads(path.read_text())


def rows(path):
    return [json.loads(line) for line in path.read_text().splitlines()]


def write_rows(path, values):
    path.write_text(''.join(json.dumps(row) + '\n' for row in values))


def build(root):
    """51 declared cells, all 77 independent windows, real unpatched verifier."""
    streams = {name: [] for name in gate.JSONL_FILES}
    cells, verdicts = [], []
    for directory in ('attempts', 'prometheus', 'database'):
        (root / directory).mkdir()
    counter = 0
    for cell in gate.catalog():
        cid = cell['cell_id']
        verdicts.append({'cell_id': cid, 'plane': cell['plane'],
                         'attempts': live.repeat_count(cell), 'evidence': 'valid'})
        for attempt in range(1, live.repeat_count(cell) + 1):
            counter += 1
            uid = f'upload-{counter}'
            entity_id = str(counter)
            suffix = '.parts/1' if cell['operation'] in {'s3-put-part', 's3-complete', 'sweeper'} else ''
            key = f'tmp/uploads/{uid}{suffix}'
            result = {'operation': cell['operation'], 'status': 503, 'transport': 'http',
                      'request_id': f'request-{counter}-1', 'requested_bytes': 16, 'written_bytes': 0}
            results = [result]
            if cell['operation'] == 'lock-holder':
                result.update(transport='artisan', lock_boundary=holder_ready(cell, attempt),
                              command_exit=1, error_class='PDOException', request_id='lock-holder-1', status=500)
            if cell['fault'] == 'enospc-then-retry':
                results.append({**result, 'status': 200, 'request_id': f'request-{counter}-2', 'written_bytes': 16})
            http = live.outcome(results[-1]['status'])
            availability = 'degraded' if results[-1]['status'] >= 500 else 'up'
            durable = 'committed' if cell['operation'] in {'cas-stage', 'write'} and http == 'accepted' else 'pending'

            def add(name, rank, phase, **extra):
                row = {'run_id': RUN, 'cell_id': cid, 'attempt': attempt,
                       'monotonic_ns': counter * 100 + rank,
                       'wall_time': datetime.fromtimestamp(EPOCH + counter * 100 + rank, timezone.utc)
                       .isoformat().replace('+00:00', 'Z'),
                       'phase': phase, 'direction': 'observe', 'fault_id': f'fault-{counter}',
                       'injector_version': gate.INJECTOR_VERSION, 'injector_config_digest': 'a' * 64,
                       'target': live.fault_spec(cell, RUN)['target'], 'config_fingerprint': gate.config_fingerprint()}
                row.update(extra)
                streams[name].append(row)

            add('readiness.jsonl', 1, 'baseline', schema='p7-readiness/v1', source='dependency-probe',
                up_ok=True, ready_ok=True, metrics_ok=True)
            for rank, phase in ((2, 'baseline'), (4, 'armed'), (5, 'fault'), (7, 'revert'),
                                (14, 'recovery'), (17, 'reconcile')):
                extra = {'recovered': True} if phase == 'recovery' else {}
                extra['direction'] = 'inject' if phase == 'fault' else ('revert' if phase == 'revert' else 'observe')
                add('ledger.jsonl', rank, phase, schema='p7-ledger/v1', **extra)
            add('barriers.jsonl', 3, 'armed', schema='p7-barrier/v1', observed=True, barrier_id=f'barrier-{counter}',
                ack=ack_for(cell), **({'holder_ready': holder_ready(cell, attempt)} if cell['operation'] == 'lock-holder' else {}))
            add('injectors.jsonl', 6, 'fault', schema='p7-injector/v1',
                injector_id=live.fault_spec(cell, RUN)['injector'], ack=ack_for(cell))
            add('database.jsonl', 8, 'fault', schema='p7-database/v1', entity='upload_session',
                entity_id=entity_id, upload_id=uid, status='uploading', received_bytes=16)
            if suffix:
                add('database.jsonl', 8, 'fault', schema='p7-database/v1', entity='upload_part',
                    entity_id=entity_id + ':1', status='recorded', checksum=CHECKSUM, size=16)
            add('objects.jsonl', 9, 'fault', schema='p7-object/v2', run_marker=RUN,
                key=key, exists=True, checksum=CHECKSUM, size=16)
            add('temp.jsonl', 10, 'fault', schema='p7-temp/v1', path=f'/tmp/{RUN}', free_bytes=32, write_length=0)
            add('prometheus.jsonl', 11, 'fault', schema='p7-prometheus/v2', job='vaulter-sut',
                path=f'prometheus/{cid}.attempt-{attempt}.json')
            add('attempts.jsonl', 12, 'fault', schema='p7-attempt/v2', operation=cell['operation'],
                product_results=results, payload_checksum=CHECKSUM, http_outcome=http,
                availability=availability, durable_state=durable)
            add('readiness.jsonl', 13, 'recovery', schema='p7-readiness/v1', source='dependency-probe',
                up_ok=True, ready_ok=True, metrics_ok=True)
            for rank, join, identity in ((15, 'db-to-object', entity_id), (16, 'object-to-db', key)):
                add('reconcile.jsonl', rank, 'reconcile', schema='p7-reconcile/v1',
                    join=join, durable_identity=identity, status='matched')
            record = {'run_id': RUN, 'cell_id': cid, 'attempt': attempt, 'plane': cell['plane'],
                      'http_outcome': http, 'availability': availability, 'durable_state': durable,
                      'product_results': results, 'checksum': CHECKSUM, 'baseline_green': True,
                      'retry_distinct': True, 'evidence': 'valid', 't_detect_ns': 3,
                      't_restore_ns': 15, 't_converge_ns': 18}
            write(root / 'attempts' / f'{cid}.attempt-{attempt}.json', record)
            write(root / 'prometheus' / f'{cid}.attempt-{attempt}.json', {
                'status': 'success', 'data': {'resultType': 'vector', 'result': [{
                    'metric': {'__name__': 'up', 'job': 'vaulter-sut', 'instance': 'app:8080'},
                    'value': [EPOCH + counter * 100 + 17, '1']}]}})
            if attempt == 1:
                cells.append({'schema': 'p7-cell/v1', 'run_id': RUN, 'cell_id': cid, 'attempt': 1,
                              'plane': cell['plane'], 'config_fingerprint': gate.config_fingerprint(),
                              **{field: record[field] for field in ('http_outcome', 'availability', 'durable_state',
                                                                   't_detect_ns', 't_restore_ns', 't_converge_ns')},
                              'rto_verdict': False})
    write(root / 'run-manifest.json', {'schema': 'p7-run/v1', 'run_id': RUN, 'tier': 'S',
                                     'harness_version': 'r11-product-fidelity/v2',
                                     'config_fingerprint': gate.config_fingerprint(),
                                     'injector_version': gate.INJECTOR_VERSION,
                                     'injector_config_digest': 'a' * 64,
                                     'terminal_marker': 'p7-run-terminal/v1', 'cleanup_scope': 'run'})
    write(root / 'terminal.json', {'schema': 'p7-run-terminal/v1', 'run_id': RUN, 'monotonic_ns': counter * 100 + 99})
    write(root / 'cells.json', cells)
    write(root / 'cell-verdicts.json', {'run_id': RUN, 'cells': verdicts})
    for name, values in streams.items():
        write_rows(root / name, values)
    for name in ('integrity-before.json', 'integrity-after.json'):
        write(root / 'database' / name, {'unchanged': 0})
    write(root / 'database/locks.json', {'ungranted': 0})


class CompleteEvidenceTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='p7-admission-')
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        build(self.root)
        live.verify_artifacts(self.root)

    def stream(self, name, mutate, *, cid=CELL, attempt=1):
        values = rows(self.root / name)
        for row in values:
            if row['cell_id'] == cid and row['attempt'] == attempt:
                mutate(row)
        write_rows(self.root / name, values)

    def file(self, name, mutate):
        value = read(self.root / name)
        mutate(value)
        write(self.root / name, value)

    def rejected(self):
        with self.assertRaises(gate.EvidenceInvalid):
            live.verify_artifacts(self.root)

    def test_complete_matrix_is_byte_stable(self):
        before = live.digest_tree(self.root)
        self.assertEqual(live.verify_artifacts(self.root), before)
        self.assertEqual(live.verify_artifacts(self.root, prior_digest=before), before)
        self.assertEqual(len(read(self.root / 'cells.json')), 51)
        self.assertEqual(len(list((self.root / 'attempts').iterdir())), 77)

    def test_missing_76_primary_attempts(self):
        for name in gate.JSONL_FILES:
            write_rows(self.root / name, [row for row in rows(self.root / name)
                                        if row['cell_id'] == CELL and row['attempt'] == 1])
        self.rejected()

    def test_each_stream_requires_each_attempt(self):
        for name in gate.JSONL_FILES:
            with self.subTest(stream=name):
                original = rows(self.root / name)
                write_rows(self.root / name, [row for row in original if not (row['cell_id'] == CELL and row['attempt'] == 2)])
                self.rejected()
                write_rows(self.root / name, original)

    def test_baseline_cannot_substitute_for_recovery(self):
        write_rows(self.root / 'readiness.jsonl', [row for row in rows(self.root / 'readiness.jsonl') if row['phase'] == 'baseline'])
        self.rejected()

    def test_other_attempt_cannot_supply_recovery(self):
        write_rows(self.root / 'readiness.jsonl', [row for row in rows(self.root / 'readiness.jsonl')
                                                if not (row['cell_id'] == CELL and row['attempt'] == 2 and row['phase'] == 'recovery')])
        self.rejected()

    def test_attempt_file_identity_and_verdict(self):
        path = f'attempts/{CELL}.attempt-2.json'
        original = read(self.root / path)
        for field, value in (('run_id', 'foreign'), ('cell_id', 'wrong-cell'), ('attempt', 99),
                             ('plane', 'transport-control'), ('evidence', 'invalid'), ('checksum', 'c' * 64),
                             ('baseline_green', False), ('retry_distinct', False), ('durable_state', 'committed')):
            with self.subTest(field=field):
                self.file(path, lambda row: row.__setitem__(field, value))
                self.rejected()
                write(self.root / path, original)

    def test_raw_result_and_stored_result_must_agree(self):
        self.stream('attempts.jsonl', lambda row: row['product_results'][0].__setitem__('status', 200))
        self.rejected()

    def test_raw_outcome_is_recomputed_even_when_stored_agrees(self):
        self.stream('attempts.jsonl', lambda row: row.__setitem__('http_outcome', 'accepted'))
        self.file(f'attempts/{CELL}.attempt-1.json', lambda row: row.__setitem__('http_outcome', 'accepted'))
        self.rejected()

    def test_object_checksum_must_equal_its_durable_row(self):
        self.stream('objects.jsonl', lambda row: row.__setitem__('checksum', 'c' * 64))
        self.rejected()

    def test_multipart_checksum_cannot_borrow_payload_checksum(self):
        cid = 'product-realistic.s3-put-part.503'
        self.stream('database.jsonl', lambda row: row.__setitem__('checksum', 'c' * 64)
                    if row['entity'] == 'upload_part' else None, cid=cid)
        self.rejected()

    def test_object_size_and_storage_identity(self):
        original = rows(self.root / 'objects.jsonl')
        for field, value in (('size', 99), ('key', 'tmp/uploads/foreign'), ('exists', False)):
            with self.subTest(field=field):
                self.stream('objects.jsonl', lambda row: row.__setitem__(field, value))
                self.rejected()
                write_rows(self.root / 'objects.jsonl', original)

    def test_both_joins_are_required_per_attempt(self):
        original = rows(self.root / 'reconcile.jsonl')
        for join in ('db-to-object', 'object-to-db'):
            with self.subTest(join=join):
                write_rows(self.root / 'reconcile.jsonl', [row for row in original if not
                           (row['cell_id'] == CELL and row['attempt'] == 2 and row['join'] == join)])
                self.rejected()
                write_rows(self.root / 'reconcile.jsonl', original)

    def test_every_join_status_and_identity_matches_raw(self):
        original = rows(self.root / 'reconcile.jsonl')
        for join in ('db-to-object', 'object-to-db'):
            for field, value in (('status', 'matched-absent'), ('durable_identity', 'foreign')):
                with self.subTest(join=join, field=field):
                    self.stream('reconcile.jsonl', lambda row: row.__setitem__(field, value) if row['join'] == join else None)
                    self.rejected()
                    write_rows(self.root / 'reconcile.jsonl', original)

    def test_duplicate_phase_and_result(self):
        for name in ('ledger.jsonl', 'attempts.jsonl', 'reconcile.jsonl'):
            with self.subTest(stream=name):
                original = rows(self.root / name)
                write_rows(self.root / name, original + [copy.deepcopy(original[0])])
                self.rejected()
                write_rows(self.root / name, original)

    def test_identity_fields_are_consistent_across_streams(self):
        original = rows(self.root / 'objects.jsonl')
        for field, value in (('fault_id', 'foreign-fault'), ('target', 'postgres'),
                             ('injector_config_digest', 'c' * 64), ('attempt', 99)):
            with self.subTest(field=field):
                self.stream('objects.jsonl', lambda row: row.__setitem__(field, value))
                self.rejected()
                write_rows(self.root / 'objects.jsonl', original)

    def test_each_phase_and_terminal_orders_observations(self):
        for name, phase, ns in (('readiness.jsonl', 'recovery', 101), ('reconcile.jsonl', 'reconcile', 106),
                                ('objects.jsonl', 'fault', 104), ('injectors.jsonl', 'fault', 110)):
            with self.subTest(stream=name):
                original = rows(self.root / name)
                self.stream(name, lambda row: row.__setitem__('monotonic_ns', ns) if row['phase'] == phase else None)
                self.rejected()
                write_rows(self.root / name, original)
        self.file('terminal.json', lambda row: row.__setitem__('monotonic_ns', 101))
        self.rejected()

    def test_cell_verdict_identity_and_coverage(self):
        original = read(self.root / 'cell-verdicts.json')
        for change in (lambda obj: obj.__setitem__('run_id', 'foreign'),
                       lambda obj: obj['cells'].append(obj['cells'][0]),
                       lambda obj: obj['cells'].pop(),
                       lambda obj: obj['cells'][0].__setitem__('evidence', 'invalid')):
            change_value = copy.deepcopy(original)
            change(change_value)
            write(self.root / 'cell-verdicts.json', change_value)
            self.rejected()
        write(self.root / 'cell-verdicts.json', original)

    def test_prometheus_job_metric_instance_window_and_values(self):
        path = f'prometheus/{CELL}.attempt-1.json'
        original = read(self.root / path)
        changes = []
        for field, value in (('job', 'prometheus'), ('__name__', 'unrelated'), ('instance', 'foreign:8080')):
            changes.append(lambda item, field=field, value=value: item['metric'].__setitem__(field, value))
        for value in (1, EPOCH + 100, EPOCH + 118, 'NaN', 'Infinity'):
            changes.append(lambda item, value=value: item['value'].__setitem__(0, value))
        for value in ('0', 'NaN', 'Infinity'):
            changes.append(lambda item, value=value: item['value'].__setitem__(1, value))
        for index, change in enumerate(changes):
            with self.subTest(control=index):
                data = copy.deepcopy(original)
                change(data['data']['result'][0])
                write(self.root / path, data)
                self.rejected()
        write(self.root / path, original)

    def test_green_series_cannot_hide_foreign_series(self):
        self.file(f'prometheus/{CELL}.attempt-1.json', lambda data: data['data']['result'].append({
            'metric': {'job': 'prometheus'}, 'value': [EPOCH + 117, '1']}))
        self.rejected()

    def test_prometheus_reference_must_bind_to_attempt(self):
        self.stream('prometheus.jsonl', lambda row: row.__setitem__('path', f'prometheus/{CELL}.attempt-2.json'))
        self.rejected()

    def test_old_labelled_get_evidence_is_rejected(self):
        self.stream('attempts.jsonl', lambda row: row.__setitem__('schema', 'p7-attempt/v1'))
        self.rejected()

    def test_expected_absence_is_admitted_without_relaxing_integrity(self):
        for status, durable in (('initiated', 'pending'), ('aborted', 'absent'), ('expired', 'absent')):
            with self.subTest(status=status):
                self.stream('database.jsonl', lambda row: row.update(status=status, received_bytes=0))
                self.stream('objects.jsonl', lambda row: row.update(exists=False, checksum=None, size=0))
                self.stream('reconcile.jsonl', lambda row: row.__setitem__('status', 'matched-absent'))
                self.stream('attempts.jsonl', lambda row: row.__setitem__('durable_state', durable))
                self.file(f'attempts/{CELL}.attempt-1.json', lambda row: row.__setitem__('durable_state', durable))
                self.file('cells.json', lambda cells: cells[0].__setitem__('durable_state', durable))
                live.verify_artifacts(self.root)
        self.stream('objects.jsonl', lambda row: row.update(exists=True, checksum=CHECKSUM, size=16))
        self.rejected()

    def test_blob_requires_exact_upload_join_checksum_and_key(self):
        self.stream('database.jsonl', lambda row: row.update(assembled_blob_id='blob-1', status='committed'))
        db = rows(self.root / 'database.jsonl')
        first = db[0]
        db.append({**first, 'entity': 'blob', 'entity_id': 'blob-1', 'status': 'committed',
                   'checksum': CHECKSUM, 'storage_key': 'cas/final', 'size': 16})
        write_rows(self.root / 'database.jsonl', db)
        self.stream('objects.jsonl', lambda row: row.__setitem__('key', 'cas/final'))
        self.stream('reconcile.jsonl', lambda row: row.__setitem__('durable_identity',
                    'blob-1' if row['join'] == 'db-to-object' else 'cas/final'))
        self.stream('attempts.jsonl', lambda row: row.__setitem__('durable_state', 'committed'))
        self.file(f'attempts/{CELL}.attempt-1.json', lambda row: row.__setitem__('durable_state', 'committed'))
        self.file('cells.json', lambda cells: cells[0].__setitem__('durable_state', 'committed'))
        live.verify_artifacts(self.root)
        self.stream('database.jsonl', lambda row: row.__setitem__('checksum', 'c' * 64) if row['entity'] == 'blob' else None)
        self.rejected()

    def test_extra_attempt_files_are_rejected(self):
        write(self.root / 'attempts/foreign.json', {})
        self.rejected()

    def test_attempt_file_product_results_and_timing_cannot_contradict_raw(self):
        path = f'attempts/{CELL}.attempt-1.json'
        original = read(self.root / path)
        for field, value in (('product_results', []), ('t_converge_ns', 99), ('t_detect_ns', -1), ('attempt', True)):
            with self.subTest(field=field):
                self.file(path, lambda row: row.__setitem__(field, value))
                self.rejected()
                write(self.root / path, original)

    def test_wall_time_must_agree_with_monotonic_order(self):
        self.stream('objects.jsonl', lambda row: row.__setitem__('wall_time', '2026-01-01T00:00:00Z'))
        self.rejected()

    def test_observations_cannot_overlap_next_attempt(self):
        self.stream('reconcile.jsonl', lambda row: row.__setitem__('monotonic_ns', 215))
        self.rejected()

    def test_readiness_requires_explicit_metrics_and_dependency_source(self):
        original = rows(self.root / 'readiness.jsonl')
        for field, value in (('metrics_ok', None), ('ready_ok', False), ('source', 'up')):
            with self.subTest(field=field):
                self.stream('readiness.jsonl', lambda row: row.__setitem__(field, value))
                self.rejected()
                write_rows(self.root / 'readiness.jsonl', original)

    def test_malformed_verdict_and_prometheus_are_evidence_invalid(self):
        path = f'prometheus/{CELL}.attempt-1.json'
        original = read(self.root / path)
        for value in ({}, {'status': 'error'}, {'status': 'success', 'data': {'resultType': 'vector', 'result': []}},
                      {'status': 'success', 'data': {'resultType': 'vector', 'result': [None]}}):
            with self.subTest(value=value):
                write(self.root / path, value)
                self.rejected()
        write(self.root / path, original)
        self.file('cell-verdicts.json', lambda value: value['cells'].append(None))
        self.rejected()

    def test_not_applicable_outbox_is_admitted_with_both_joins(self):
        self.stream('database.jsonl', lambda row: row.update(entity='outbox', status='pending', entity_id='outbox-1'))
        key = f'tmp/uploads/{RUN}/marker/not-applicable'
        self.stream('objects.jsonl', lambda row: row.update(key=key, exists=False, checksum=None, size=0))
        self.stream('reconcile.jsonl', lambda row: row.update(status='not-applicable',
                    durable_identity='outbox-1' if row['join'] == 'db-to-object' else key))
        self.stream('attempts.jsonl', lambda row: row.__setitem__('durable_state', 'committed'))
        self.file(f'attempts/{CELL}.attempt-1.json', lambda row: row.__setitem__('durable_state', 'committed'))
        self.file('cells.json', lambda cells: cells[0].__setitem__('durable_state', 'committed'))
        live.verify_artifacts(self.root)

    def test_actual_window_producer_binds_to_full_verifier(self):
        cell = gate.catalog()[0]
        produced = {name: [] for name in gate.JSONL_FILES}

        class Clock:
            value = 100

            def next(self):
                self.value += 1
                return self.value

        clock = Clock()
        fixture = {'payload_sha256': CHECKSUM, 'upload_id': 'upload-1', 'marker': RUN, 'run_id': RUN}
        entities = [{'entity': 'upload_session', 'entity_id': '1', 'upload_id': 'upload-1',
                     'status': 'uploading', 'received_bytes': 16}]
        result = {'status': 503, 'transport': 'http', 'operation': 'tus-patch', 'request_id': 'producer-1'}
        boundaries = {
            'host_status': 200, 'host_probe': (200, 'vaulter_metric 1'), 'arm': 's3-fault-proxy',
            'barrier_ack': ack_for(cell), 'revert': None, 'sample_entities': entities, 'cleanup_fixture': None,
            'object_sample': {'key': 'tmp/uploads/upload-1', 'exists': True, 'checksum': CHECKSUM, 'size': 16},
        }
        with ExitStack() as stack:
            for name, value in boundaries.items():
                stack.enter_context(patch.object(live, name, return_value=value))
            stack.enter_context(patch.object(live.product_ops.ProductOperationRunner, 'prepare', return_value=fixture))
            stack.enter_context(patch.object(live.product_ops.ProductOperationRunner, 'execute', return_value=result))
            stack.enter_context(patch.object(live, 'wall', side_effect=lambda: datetime.fromtimestamp(
                EPOCH + clock.value, timezone.utc).isoformat().replace('+00:00', 'Z')))
            record = live.run_window(produced, clock, cell, 1, RUN, gate.config_fingerprint(), 'a' * 64)
        for name, values in produced.items():
            old = [row for row in rows(self.root / name) if not (row['cell_id'] == CELL and row['attempt'] == 1)]
            write_rows(self.root / name, values + old)
        write(self.root / f'attempts/{CELL}.attempt-1.json', record)
        self.file('cells.json', lambda cells: cells[0].update({field: record[field]
                  for field in ('t_detect_ns', 't_restore_ns', 't_converge_ns')}))
        self.file(f'prometheus/{CELL}.attempt-1.json', lambda prom: prom['data']['result'][0]['value']
                  .__setitem__(0, EPOCH + produced['ledger.jsonl'][-1]['monotonic_ns']))
        live.verify_artifacts(self.root)

    def test_committed_upload_without_blob_cannot_claim_expected_absence(self):
        self.stream('database.jsonl', lambda row: row.update(status='committed', received_bytes=0))
        self.stream('objects.jsonl', lambda row: row.update(exists=False, checksum=None, size=0))
        self.stream('reconcile.jsonl', lambda row: row.__setitem__('status', 'matched-absent'))
        self.stream('attempts.jsonl', lambda row: row.__setitem__('durable_state', 'committed'))
        self.file(f'attempts/{CELL}.attempt-1.json', lambda row: row.__setitem__('durable_state', 'committed'))
        self.file('cells.json', lambda cells: cells[0].__setitem__('durable_state', 'committed'))
        self.rejected()


if __name__ == '__main__':
    unittest.main()
