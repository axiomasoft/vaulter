"""Read-only original ACK/order reproduction; no Docker, DB or product writes."""

import json
import subprocess
import sys
import types
from contextlib import ExitStack
from pathlib import Path
from unittest.mock import patch

REPO = Path(__file__).resolve().parents[3]
sys.path.insert(0, str(REPO / 'load/gates'))
import p7_live_matrix as current

BASE = '85c0afd'
source = subprocess.run(['git', '-C', str(REPO), 'show', f'{BASE}:load/gates/p7_live_matrix.py'],
                        check=True, capture_output=True, text=True).stdout
original = types.ModuleType('original_p7_live_matrix')
original.__file__ = str(REPO / 'load/gates/p7_live_matrix.py')
exec(compile(source, original.__file__, 'exec'), original.__dict__)
cell = next(row for row in current.gate.catalog() if row['cell_id'] == 'product-realistic.lock-holder.disconnect')
run = 'offline-original'
events = []
fixture = {'marker': current.product_ops.marker_for(run, cell['cell_id'], 1),
           'requested_bytes': 16, 'payload_sha256': 'b' * 64}
unfaulted = {'name': 'pgbouncer', 'upstream': 'pgbouncer:6432', 'listen': '[::]:16432',
            'enabled': True, 'toxics': []}

with patch.object(original, 'app_http', return_value={'status': 200, 'body': json.dumps(unfaulted)}):
    original.barrier_ack('toxiproxy', run)
with patch.object(current, 'app_http', return_value={'status': 200, 'body': json.dumps(unfaulted)}):
    try:
        current.barrier_ack('toxiproxy', run, cell)
    except current.LiveError as exc:
        rejection = str(exc)
    else:
        raise AssertionError('current ACK accepted an unfaulted proxy')

class Runner:
    def __init__(self, **kwargs):
        pass

    def prepare(self, *args):
        events.append('prepare-fixture')
        return fixture

    def execute(self, *args, **kwargs):
        events.append('execute-lock-holder')
        return {'status': 500, 'request_id': 'lock-holder-1', 'requested_bytes': 16,
                'written_bytes': 0, 'error_class': 'ControlledFailure'}

ack = original.barrier_ack
def observed(*args):
    events.append('ack')
    return ack(*args)

def arm(*args):
    events.append('fault')
    return 'toxiproxy'

with ExitStack() as stack:
    stack.enter_context(patch.object(original.product_ops, 'ProductOperationRunner', Runner))
    peers = {'app_http': {'status': 200, 'body': json.dumps(unfaulted)}, 'host_status': 200,
             'host_probe': (200, 'vaulter_metric 1'), 'cleanup_fixture': None,
             'sample_entities': [{'entity': 'outbox', 'entity_id': fixture['marker'], 'status': 'absent'}]}
    for name, value in peers.items():
        stack.enter_context(patch.object(original, name, return_value=value))
    stack.enter_context(patch.object(original, 'arm', side_effect=arm))
    stack.enter_context(patch.object(original, 'barrier_ack', side_effect=observed))
    stack.enter_context(patch.object(original, 'revert', side_effect=lambda *args: events.append('revert')))
    streams = {name: [] for name in current.gate.JSONL_FILES}
    original.run_window(streams, original.Clock(), cell, 1, run, 'offline-fingerprint', 'offline-digest')

assert events == ['prepare-fixture', 'fault', 'ack', 'execute-lock-holder', 'revert'], events
assert streams['barriers.jsonl'][0]['target'] == 'minio'
assert 'holder_ready' not in streams['barriers.jsonl'][0]
print(json.dumps({'scope': 'offline controlled peers only', 'base_revision': BASE,
                  'original': {'verdict': 'expected RED', 'unfaulted_http_200_accepted': True,
                               'order': events, 'reported_target': 'minio', 'holder_ready': None},
                  'current_unfaulted_ack': {'verdict': 'REJECTED', 'reason': rejection}}, indent=2))
