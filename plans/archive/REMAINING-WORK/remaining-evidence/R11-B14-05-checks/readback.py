"""Reproduce B14-05 false admissions using only controlled dependency peers."""

import argparse
import importlib.util
import json
import subprocess
import sys
import tempfile
from contextlib import ExitStack
from pathlib import Path
from unittest.mock import patch


REPO = Path(__file__).resolve().parents[3]
sys.path.insert(0, str(REPO / 'load/gates'))
import p7_chaos as gate
import p7_live_matrix as current
import test_p7_evidence as evidence


def control(live, kind):
    operation = 's3-put-part' if kind == 'missing-second-part' else 's3-complete'
    cell = next(row for row in gate.catalog() if row['cell_id'] == f'product-realistic.{operation}.503')
    fixture = {'marker': live.product_ops.marker_for(evidence.RUN, cell['cell_id'], 1),
               'run_id': evidence.RUN, 'upload_id': 'readback-upload', 'cas_scope': 'global',
               'payload_sha256': evidence.CHECKSUM, 'requested_bytes': 16}
    stage, part = 'tmp/uploads/readback-upload', 'tmp/uploads/readback-upload.parts/'
    final = evidence.FINAL_KEY
    committed = kind != 'missing-second-part'
    entities = [{'entity': 'upload_session', 'entity_id': 'session-1', 'upload_id': 'readback-upload',
                 'status': 'committed' if committed else 'uploading', 'received_bytes': 16 if committed else 32,
                 'assembled_blob_id': 'blob-1' if committed else None},
                {'entity': 'upload_part', 'entity_id': 'session-1:1', 'status': 'recorded',
                 'checksum': evidence.CHECKSUM, 'size': 16}]
    if committed:
        entities.append({'entity': 'blob', 'entity_id': 'blob-1', 'status': 'committed',
                         'storage_key': final, 'checksum': evidence.CHECKSUM, 'size': 16})
    else:
        entities.append({'entity': 'upload_part', 'entity_id': 'session-1:2', 'status': 'recorded',
                         'checksum': evidence.CHECKSUM, 'size': 16})
    present = {final} if committed else {part+'1'}
    if kind == 'leftover-part':
        present.add(part+'1')
    if kind == 'extra-part':
        present.add(part+'99')
    if kind == 'leftover-stage':
        present.add(stage)
    events = []
    executed = False
    def execute(*args, **kwargs):
        nonlocal executed
        executed = True
        return {'operation': operation, 'status': 503, 'transport': 'http', 'request_id': 'readback-1'}
    def sample(key, checksum=None):
        events.append(key)
        exists = executed and key in present
        return {'key': key, 'exists': exists, 'checksum': evidence.CHECKSUM if exists else None,
                'size': 16 if exists else 0, 'observation': 'observed' if exists else 'absent'}
    streams = {name: [] for name in gate.JSONL_FILES}
    with ExitStack() as stack:
        stack.enter_context(patch.object(live, '_run', side_effect=AssertionError('unexpected live boundary')))
        for name, value in {'host_status': 200, 'host_probe': (200, 'vaulter_metric 1'),
                            'arm': 's3-fault-proxy', 'barrier_ack': evidence.ack_for(cell),
                            'revert': None, 'cleanup_fixture': None, 'sample_entities': entities}.items():
            stack.enter_context(patch.object(live, name, return_value=value))
        stack.enter_context(patch.object(live, 'object_sample', side_effect=sample))
        stack.enter_context(patch.object(live, 'object_listing', create=True, return_value={
            'status': 'observed', 'keys': sorted(key for key in present if key.startswith(part))}))
        stack.enter_context(patch.object(live.product_ops.ProductOperationRunner, 'prepare', return_value=fixture))
        stack.enter_context(patch.object(live.product_ops.ProductOperationRunner, 'execute', side_effect=execute))
        record = live.run_window(streams, live.Clock(), cell, 1, evidence.RUN, gate.config_fingerprint(), 'a'*64)
    return {'control': kind, 'evidence': record['evidence'], 'sampled_keys': events,
            'joins': [{key: row.get(key) for key in ('object_key','join','status')} for row in streams['reconcile.jsonl']]}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--original-ref')
    args = parser.parse_args()
    with tempfile.TemporaryDirectory(prefix='p7-inventory-original-') as directory:
        live = current
        if args.original_ref:
            path = Path(directory) / 'load/gates/p7_live_matrix.py'
            path.parent.mkdir(parents=True)
            path.write_bytes(subprocess.check_output(['git','show',args.original_ref+':load/gates/p7_live_matrix.py'],cwd=REPO))
            spec = importlib.util.spec_from_file_location('original_p7_inventory', path)
            live = importlib.util.module_from_spec(spec)
            spec.loader.exec_module(live)
        controls = [control(live, name) for name in ('leftover-part','extra-part','leftover-stage','missing-second-part')]
        with patch.object(live, '_run', return_value=subprocess.CompletedProcess([],1,'AccessDenied','')):
            error = live.object_sample('tmp/uploads/readback-upload')
        expected = 'valid' if args.original_ref else 'invalid'
        assert all(row['evidence'] == expected for row in controls), controls
        assert error['exists'] is (False if args.original_ref else None), error
        print(json.dumps({'source': args.original_ref or 'current', 'scope': 'controlled offline producer/collector',
                          'expected_original_false_admissions': bool(args.original_ref), 'controls': controls,
                          'nonzero_collector': error}, indent=2, sort_keys=True))
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
