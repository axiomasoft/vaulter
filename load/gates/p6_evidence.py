"""Pure recomputation of R09 evidence. No inferred samples or product success."""
from collections import defaultdict
from math import ceil, isclose, isfinite
import re

WEBHOOK = 'Vaulter\\Jobs\\Webhooks\\DeliverWebhook'

P6_METRIC_NAMES = (
    'vaulter_outbox_relayed_total',
    'vaulter_outbox_relay_duration_seconds_count',
    'vaulter_outbox_endpoints_scanned_total',
    'vaulter_webhook_queue_wait_seconds_count',
    'vaulter_webhook_delivery_duration_seconds_count',
    'vaulter_webhook_delivered_total',
    'vaulter_webhook_retry_tick_seconds_count',
    'vaulter_webhook_retry_lockset_rows_count',
    'vaulter_webhook_retry_lockset_rows_sum',
    'vaulter_command_peak_memory_bytes',
)


def percentile(values, fraction=.95):
    return sorted(values)[max(0, ceil(len(values) * fraction) - 1)] if values else None


def window(rows, run_id, cell_id, attempt=None):
    return [r for r in rows if r.get('run_id') == run_id and r.get('cell_id') == cell_id
            and (attempt is None or r.get('attempt') == attempt)]


def lifecycle(rows):
    jobs = defaultdict(lambda: defaultdict(list))
    for row in rows:
        if row.get('class') == WEBHOOK:
            jobs[row['uuid']][row['source']].append(row['monotonic_ns'])
    waits, processes, errors = [], [], []
    for uuid, sources in jobs.items():
        queueing, queued, started, done = (sources[k] for k in ('queueing', 'queued', 'processing', 'processed'))
        if len(queueing) != 1 or len(queued) != 1 or len(started) > 1 or len(done) > 1 or queued[0] < queueing[0]:
            errors.append(uuid)
            continue
        if started:
            wait = (started[0] - queueing[0]) / 1e9
            if wait < 0:
                errors.append(uuid)
            else:
                waits.append(wait)
        if done:
            if not started or done[0] < started[0]:
                errors.append(uuid)
            else:
                processes.append((done[0] - started[0]) / 1e9)
    return {'queue_wait_source': 'JobQueueing before Redis push (upper bound includes enqueue IO)',
            'queued': sum(len(s['queued']) for s in jobs.values()),
            'processing': sum(len(s['processing']) for s in jobs.values()),
            'processed': sum(len(s['processed']) for s in jobs.values()),
            'uuids': sorted(jobs), 'invalid_uuids': errors,
            'queue_wait_samples': len(waits), 'queue_wait_p95_seconds': percentile(waits),
            'process_samples': len(processes), 'process_p95_seconds': percentile(processes)}


def failure_delta(before, after):
    known = {r['uuid'] for r in before}
    return [r for r in after if r['uuid'] not in known]


def aggregate_slo(attempts):
    verdicts = {r['slo'] for r in attempts}
    return 'red' if 'red' in verdicts else ('unevaluated' if 'unevaluated' in verdicts else attempts[-1]['slo'])


def volume_measurements(volume):
    relay_ticks = volume.get('relay_ticks', [])
    retry_ticks = volume.get('retry_ticks', [])
    relay_seconds = volume.get('relay_seconds')
    retry_seconds = sum(t.get('elapsed_ns', 0) / 1e9 for t in retry_ticks)
    if not isinstance(relay_seconds, (int, float)) or not isfinite(relay_seconds) or relay_seconds <= 0:
        raise ValueError('invalid relay wall duration')
    if relay_seconds < sum(t.get('elapsed_ns', 0) / 1e9 for t in relay_ticks):
        raise ValueError('relay wall duration is shorter than its ticks')
    if not isfinite(retry_seconds) or retry_seconds <= 0:
        raise ValueError('invalid retry tick duration')

    return {
        'relay_rows_per_second': sum(t.get('relayed', 0) for t in relay_ticks) / relay_seconds,
        'relay_tick_p95_seconds': percentile([t.get('elapsed_ns', 0) / 1e9 for t in relay_ticks]),
        'retry_rows_per_second': sum(t.get('retried', 0) for t in retry_ticks) / retry_seconds,
        'retry_tick_p95_seconds': percentile([t.get('elapsed_ns', 0) / 1e9 for t in retry_ticks]),
    }


def validate_volume(volume):
    for field, count, remaining in [('relay_ticks', 'relayed', 'outbox_remaining'),
                                     ('retry_ticks', 'retried', 'due_remaining')]:
        expected = 10000 if count == 'relayed' else 5000
        ticks = volume.get(field, [])
        if not ticks or sum(t.get(count, 0) for t in ticks) != expected or volume.get(remaining) != 0:
            raise ValueError(f'incomplete {field} drain')
        if any(not t.get('ok') or t.get('elapsed_ns', 0) <= 0 for t in ticks):
            raise ValueError(f'failed {field} tick')
    if not volume.get('relay_drained') or not volume.get('retry_drained'):
        raise ValueError('volume queue remains undrained')
    if volume.get('active_endpoints') != 20:
        raise ValueError('volume requires 20 active endpoints')
    measurements = volume_measurements(volume)
    for name, measured in measurements.items():
        stored = volume.get(name)
        if not isinstance(stored, (int, float)) or not isclose(stored, measured, rel_tol=1e-12, abs_tol=1e-12):
            raise ValueError(f'stale volume summary: {name}')


def exposition_totals(body):
    totals = defaultdict(float)
    for line in body.splitlines():
        line = line.strip()
        if not line or line.startswith('#'):
            continue
        sample, value = line.rsplit(None, 1)
        name = sample.split('{', 1)[0]
        # P6 requires the retry command's seam; other command gauges cannot cover it.
        if name == 'vaulter_command_peak_memory_bytes' and not re.search(
                r'(?:\{|,)command="vaulter:webhooks:retry"(?:,|\})', sample):
            continue
        try:
            number = float(value)
        except ValueError as exc:
            raise ValueError(f'invalid Prometheus sample: {name}') from exc
        if not isfinite(number):
            raise ValueError(f'non-finite Prometheus sample: {name}')
        totals[name] += number
    return dict(totals)


def confirmed_relay_rows(rows, run_id):
    """Disjoint completed UUIDs in the primary volume and steady relay windows."""
    uuids = set()
    for cell in ('volume-relay', 'product-realistic.relay.i20.f1.2xx'):
        captured = window(rows, run_id, cell, 1)
        joined = lifecycle(captured)
        if joined['invalid_uuids'] or not joined['processed'] or not (
                joined['queued'] == joined['processing'] == joined['processed']):
            raise ValueError(f'incomplete confirmed relay ledger: {cell}')
        current = set(joined['uuids'])
        if current & uuids:
            raise ValueError('volume and steady relay UUIDs overlap')
        uuids.update(current)
    return len(uuids)


def validate_p6_metrics(metrics, volume, confirmed_relay_minimum=None):
    missing = [name for name in P6_METRIC_NAMES if name not in metrics]
    if missing:
        raise ValueError('missing P6 metrics: ' + ', '.join(missing))

    relay_processed = volume['relay_lifecycle']['processed']
    retry_processed = volume['retry_lifecycle']['processed']
    delivery_processed = relay_processed + retry_processed
    minimums = {
        'vaulter_outbox_relayed_total': max(sum(t['relayed'] for t in volume['relay_ticks']),
                                           confirmed_relay_minimum or 0),
        'vaulter_outbox_relay_duration_seconds_count': len(volume['relay_ticks']),
        'vaulter_outbox_endpoints_scanned_total': relay_processed,
        'vaulter_webhook_queue_wait_seconds_count': delivery_processed,
        'vaulter_webhook_delivery_duration_seconds_count': delivery_processed,
        'vaulter_webhook_delivered_total': delivery_processed,
        'vaulter_webhook_retry_tick_seconds_count': len(volume['retry_ticks']),
        'vaulter_webhook_retry_lockset_rows_count': len(volume['retry_ticks']),
        'vaulter_webhook_retry_lockset_rows_sum': sum(t['retried'] for t in volume['retry_ticks']),
        # PHP peak proves the seam exists; the SLO ceiling remains OS RSS.
        'vaulter_command_peak_memory_bytes': 1,
    }
    for name, minimum in minimums.items():
        value = metrics[name]
        if not isinstance(value, (int, float)) or not isfinite(value) or value < minimum:
            raise ValueError(f'P6 metric {name}={value!r} is below ledger/tick minimum {minimum}')


def product_verdict(row):
    # Cell outcomes alone cannot prove the tier-level throughput/latency contract.
    if row['failed_jobs'] or not row['recovered'] or (row.get('backlog_slope') or 0) > 0 or row.get('duplicate_http', 0):
        return 'red'
    return 'unevaluated'


def slo_report(volume, attempts):
    steady = next(r for r in attempts if r['cell_id'] == 'product-realistic.relay.i20.f1.2xx' and r['attempt'] == 1)
    latency = [r for r in attempts if r.get('sink') == 'slow-under-timeout' and r.get('fanout') == '1' and r.get('ingress_per_s') == 200]
    best = min(latency, key=lambda r: r['jobs_per_second'])
    measurements = volume_measurements(volume)
    retry_ticks = volume['retry_ticks']
    failed = sum(r['failed_jobs'] for r in attempts if r['plane'] == 'product-realistic')
    relay_ok = (measurements['relay_rows_per_second'] >= 200 and measurements['relay_tick_p95_seconds'] <= 1.5
                and volume['relay_drained'] and steady['backlog_slope'] <= 0
                and steady['events_sent'] >= 20 * steady['burst_seconds'] and failed == 0)
    delivery_ok = all(r['jobs_per_second'] >= 50 and r['recovered']
                      and r['lifecycle']['queue_wait_p95_seconds'] is not None
                      and r['lifecycle']['queue_wait_p95_seconds'] <= 5
                      and r['lifecycle']['process_p95_seconds'] <= 2 and r['failed_jobs'] == 0
                      for r in latency) and len(latency) == 3
    retry_ok = (measurements['retry_rows_per_second'] >= 500 and measurements['retry_tick_p95_seconds'] <= 2
                and max(t['retried'] for t in retry_ticks) <= 1000
                and all(t.get('rss_peak_bytes') is not None for t in retry_ticks)
                and max(t.get('rss_peak_bytes') or 0 for t in retry_ticks) <= 268435456 and volume['retry_drained']
                and not volume['retry_failed_jobs_delta'])
    return {'SLO-58': 'green' if relay_ok else 'red', 'SLO-61': 'green' if delivery_ok else 'red',
            'SLO-64': 'green' if retry_ok else 'red',
            'delivery_limiting_cell': best['cell_id'], 'delivery_limiting_attempt': best['attempt'],
            'delivery_min_jobs_per_second': best['jobs_per_second'],
            'daily_error_budget': 'unevaluated: this run is shorter than 24 hours',
            'retry_memory_measure': 'OS process peak RSS from /usr/bin/time; PHP peak recorded separately',
            'tier_M_L': 'unevaluated'}
