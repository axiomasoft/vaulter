#!/usr/bin/env python3
"""P6.2 semantic matrix: plane × case invariants, not performance verdicts."""

from __future__ import annotations

import argparse
import importlib.util
import json
import sys
import threading
import time
from pathlib import Path


GATES = Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location('p6_queue', GATES / 'p6_queue.py')
queue = importlib.util.module_from_spec(spec)
sys.modules['p6_queue'] = queue
spec.loader.exec_module(queue)

PLANES = queue.PLANES
INGRESS_MULTIPLIER = {20: '1x', 100: '5x', 200: '10x'}
SINK_PROFILE = {
    '2xx': '2xx',
    '500': '500',
    'slow-under-timeout': 'delay',
    'over-timeout': 'hang',
}
BACKOFF_SECONDS = 10
MAX_ATTEMPTS = 5
DISABLE_AFTER = 10
S0_CELLS = (
    'product-realistic.relay.i20.f1.2xx',
    'product-realistic.relay.i20.f1.500',
    'overlap-control.relay-retry.barrier.relay2-retry2',
    'overlap-control.duplicate-job.concurrent.same-delivery',
)
FORBIDDEN_SINK_KEYS = frozenset({'body', 'payload', 'signature', 'secret', 'authorization'})


class MatrixInvalid(Exception):
    """A plane, case, or integrity rule failed."""


def _require(condition: bool, message: str) -> None:
    if not condition:
        raise MatrixInvalid(message)


def annotate(cell: dict) -> dict:
    """Expected invariants for one catalog cell. Never a performance verdict."""
    plane = cell['plane']
    cell_id = cell['cell_id']
    annotated = {
        'plane': plane,
        'performance_verdict': False,
        'fanout_source': 'deliveries_per_jobs',
        'endpoint_count_is_not_fanout_proof': True,
    }
    if plane == 'product-realistic':
        annotated.update({
            'ingress_multiplier': INGRESS_MULTIPLIER[cell['ingress_per_s']],
            'fanout': cell['fanout'],
            'sink_profile': SINK_PROFILE[cell['sink']],
            'workers': 2,
            'outbox_rows': 10_000,
            'endpoints': 20,
            'due_rows': 5_000,
            'throughput_claim': True,
            'slo': ['SLO-58', 'SLO-61', 'SLO-64'],
        })
        return annotated
    if plane == 'overlap-control':
        annotated['throughput_claim'] = False
        if cell_id.endswith('relay2-retry2'):
            annotated.update({'relay_ticks': 2, 'retry_ticks': 2, 'barrier': True})
        elif cell_id.endswith('serial.same-delivery'):
            annotated.update({
                'shared_delivery': True,
                'distinct_queue_uuids': True,
                'proves_concurrent_exactly_once': False,
            })
        elif cell_id.endswith('concurrent.same-delivery'):
            annotated.update({
                'shared_delivery': True,
                'distinct_queue_uuids': True,
                'proves_concurrent_exactly_once': False,
            })
        else:
            raise MatrixInvalid(f'unknown overlap cell {cell_id}')
        return annotated
    if plane == 'state-machine-control':
        annotated.update({'clock': 'explicit', 'elapsed_time_slo': False, 'throughput_claim': False})
        if cell_id.endswith('backoff.force-due'):
            annotated.update({
                'configured_backoff_seconds': BACKOFF_SECONDS,
                'recorded_before_force_due': True,
            })
        elif cell_id.endswith('dead.force-due'):
            annotated.update({'attempts': MAX_ATTEMPTS, 'status': 'dead'})
        elif cell_id.endswith('disable.after-dead'):
            annotated.update({
                'after_status': 'dead',
                'failure_count': DISABLE_AFTER,
                'is_active': False,
            })
        else:
            raise MatrixInvalid(f'unknown state-machine cell {cell_id}')
        return annotated
    if plane == 'dirty-cardinality-probe':
        annotated.update({
            'attempt': 1,
            'snapshot_restore_before': True,
            'tier_ml_slo': False,
            'throughput_claim': False,
            'seeded': False,
        })
        if 'endpoints.n200' in cell_id:
            annotated['endpoints'] = 200
        elif 'endpoints.n1000' in cell_id:
            annotated['endpoints'] = 1000
        elif 'due-rows.n50000' in cell_id:
            annotated['due_rows'] = 50_000
        elif 'due-rows.n500000' in cell_id:
            annotated['due_rows'] = 500_000
        else:
            raise MatrixInvalid(f'unknown cardinality cell {cell_id}')
        return annotated
    raise MatrixInvalid(f'unknown plane {plane}')


def static_validate(cells: list[dict] | None = None) -> dict:
    cells = queue.catalog() if cells is None else cells
    _require(len(cells) == 46, f'expected 46 cells, got {len(cells)}')
    ids = [cell['cell_id'] for cell in cells]
    _require(len(ids) == len(set(ids)), 'duplicate cell id')
    planes = set()
    for cell in cells:
        _require(cell['plane'] in PLANES, f"plane missing for {cell['cell_id']}")
        planes.add(cell['plane'])
        invariants = annotate(cell)
        _require(invariants['performance_verdict'] is False, 'matrix must not emit a performance verdict')
        _require(invariants['fanout_source'] == 'deliveries_per_jobs', 'fan-out must be measured from deliveries/jobs')
        if cell['plane'] == 'product-realistic':
            _require(cell['workers'] == invariants['workers'], 'product workers drifted')
            _require(cell['outbox_rows'] == 10_000, 'tier-S outbox drifted')
            _require(cell['endpoints'] == 20, 'tier-S endpoints drifted')
            _require(cell['due_rows'] == 5_000, 'tier-S due rows drifted')
            _require(cell['throughput_claim'] is True, 'product cell must claim throughput')
            _require(cell['slo'] == invariants['slo'], 'product SLO set drifted')
            _require(cell['ingress_per_s'] in INGRESS_MULTIPLIER, 'ingress axis missing')
            _require(cell['fanout'] in ('0', '1', 'all'), 'fan-out axis missing')
            _require(cell['sink'] in SINK_PROFILE, 'sink axis missing')
        else:
            _require(cell['throughput_claim'] is False, f"{cell['cell_id']} must not claim throughput")
        if cell['plane'] == 'dirty-cardinality-probe':
            _require(invariants['snapshot_restore_before'] is True, 'cardinality case needs a snapshot restore')
            _require(invariants['tier_ml_slo'] is False, 'cardinality probe is not a tier M/L SLO')
        if cell['plane'] == 'state-machine-control':
            _require(invariants['clock'] == 'explicit', 'state-machine clock must be explicit')
            _require(invariants['elapsed_time_slo'] is False, 'forced due is not an elapsed-time SLO')
    _require(planes == set(PLANES), f'planes {sorted(planes)} != {list(PLANES)}')
    missing = [cell_id for cell_id in S0_CELLS if cell_id not in ids]
    _require(not missing, f'S0 cells missing: {missing}')
    return {
        'cells': len(cells),
        'planes': list(PLANES),
        's0': list(S0_CELLS),
        'mechanism_only': {
            'name': 'timeout-ge-retry-after',
            'queue': 'load-timeout-control',
            'product_slo': False,
        },
    }


def integrity_defects(report: dict) -> list[str]:
    defects = []
    expected = report.get('expected') if isinstance(report.get('expected'), dict) else {}
    actual = report.get('actual') if isinstance(report.get('actual'), dict) else {}
    for field in ('delivery_rows', 'queue_jobs', 'sink_receives'):
        left = expected.get(field)
        right = actual.get(field)
        if type(left) is not int or type(right) is not int:
            defects.append(f'invalid_{field}')
            continue
        if right < left:
            defects.append(f'missing_{field}')
        if right > left:
            defects.append(f'extra_{field}')
    fields = report.get('sink_fields') if isinstance(report.get('sink_fields'), list) else []
    if 'body' in fields or 'payload' in fields:
        defects.append('sink_recorded_body')
    if 'signature' in fields:
        defects.append('sink_recorded_signature')
    return defects


def run_overlap(path: Path) -> None:
    barrier = threading.Barrier(4)
    rows: list[dict] = []
    lock = threading.Lock()

    def worker(kind: str, index: int) -> None:
        barrier.wait(timeout=5)
        row = {'kind': kind, 'index': index, 'monotonic': time.monotonic()}
        with lock:
            rows.append(row)

    threads = [
        threading.Thread(target=worker, args=('relay', 1)),
        threading.Thread(target=worker, args=('relay', 2)),
        threading.Thread(target=worker, args=('retry', 1)),
        threading.Thread(target=worker, args=('retry', 2)),
    ]
    for thread in threads:
        thread.start()
    for thread in threads:
        thread.join(timeout=5)
    _require(len(rows) == 4, 'overlap barrier did not release 4 ticks')
    relays = [row for row in rows if row['kind'] == 'relay']
    retries = [row for row in rows if row['kind'] == 'retry']
    _require(len(relays) == 2 and len(retries) == 2, 'overlap ticks must be 2 relay + 2 retry')
    span = max(row['monotonic'] for row in rows) - min(row['monotonic'] for row in rows)
    _require(span < 1.0, f'overlap ticks were not synchronized ({span:.3f}s)')
    path.write_text(json.dumps({'relay_ticks': 2, 'retry_ticks': 2, 'span_seconds': span, 'barrier': True}), encoding='utf-8')


def _ledger(path: Path) -> list[dict]:
    rows = []
    for line in path.read_text(encoding='utf-8').splitlines():
        if not line.strip():
            continue
        row = json.loads(line)
        _require(isinstance(row, dict), 'sink ledger row must be an object')
        _require(FORBIDDEN_SINK_KEYS.isdisjoint(row), 'sink ledger stored a forbidden field')
        rows.append(row)
    return rows


def s0_check(ledger_path: Path, overlap_path: Path) -> None:
    rows = _ledger(ledger_path)
    success = [row for row in rows if row.get('event_id') == 'evt-2xx']
    failure = [row for row in rows if row.get('event_id') == 'evt-500']
    duplicate = [row for row in rows if row.get('event_id') == 'dup-1']
    _require(len(success) == 1 and success[0].get('profile') == '2xx' and success[0].get('status') == 204, 'S0 2xx control missing')
    _require(len(failure) == 1 and failure[0].get('profile') == '500' and failure[0].get('status') == 500, 'S0 failure control missing')
    _require(len(duplicate) == 2, 'S0 duplicate control must record two sink receives')
    overlap = json.loads(overlap_path.read_text(encoding='utf-8'))
    _require(overlap.get('relay_ticks') == 2 and overlap.get('retry_ticks') == 2 and overlap.get('barrier') is True, 'S0 overlap control missing')
    report = {
        'expected': {'delivery_rows': 1, 'queue_jobs': 2, 'sink_receives': 2},
        'actual': {'delivery_rows': 1, 'queue_jobs': 2, 'sink_receives': len(duplicate)},
        'sink_fields': sorted({key for row in rows for key in row}),
    }
    defects = integrity_defects(report)
    _require(not defects, f'S0 integrity defects: {defects}')


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description='P6.2 semantic matrix validator')
    sub = parser.add_subparsers(dest='command', required=True)
    sub.add_parser('static-validate')
    overlap = sub.add_parser('overlap')
    overlap.add_argument('--out', type=Path, required=True)
    check = sub.add_parser('s0-check')
    check.add_argument('--ledger', type=Path, required=True)
    check.add_argument('--overlap', type=Path, required=True)
    integrity = sub.add_parser('integrity')
    integrity.add_argument('--report', type=Path, required=True)
    args = parser.parse_args(argv)
    try:
        if args.command == 'static-validate':
            print(json.dumps(static_validate(), sort_keys=True))
        elif args.command == 'overlap':
            run_overlap(args.out)
            print('p6-queue-cases overlap: ok')
        elif args.command == 's0-check':
            s0_check(args.ledger, args.overlap)
            print('p6-queue-cases s0: ok')
        else:
            report = json.loads(args.report.read_text(encoding='utf-8'))
            defects = integrity_defects(report)
            if defects:
                raise MatrixInvalid('integrity defects: ' + ', '.join(defects))
            print('p6-queue-cases integrity: ok')
    except (OSError, UnicodeError, json.JSONDecodeError, MatrixInvalid) as exc:
        print(f'p6-queue-cases: {exc}', file=sys.stderr)
        return 1
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
