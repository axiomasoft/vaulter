"""Normative P8.7 Class-A PromQL map (observability-spec.md sections 8 and 16)."""

from __future__ import annotations

import math
import re
from copy import deepcopy

from _slo_table import blocking_profiles


def _selector(name, labels=None):
    labels = labels or {}
    if not labels:
        return name
    body = ','.join(f'{key}="{value}"' for key, value in sorted(labels.items()))
    return f'{name}{{{body}}}'


def _predicate(expression_class, promql, comparator, threshold, *, count_promql=None):
    result = {
        'expression_class': expression_class,
        'promql': promql,
        'comparator': comparator,
        'threshold': threshold,
    }
    if count_promql is not None:
        result['count_promql'] = count_promql
    return result


def _histogram_ratio(name, le, *, labels=None, comparator='>=', threshold=0.95):
    labels = labels or {}
    bucket = _selector(f'{name}_bucket', {**labels, 'le': str(le)})
    count = _selector(f'{name}_count', labels)
    return _predicate(
        'histogram_le_ratio',
        f'sum(increase({bucket}[run])) / sum(increase({count}[run]))',
        comparator,
        threshold,
        count_promql=f'sum(increase({count}[run]))',
    )


def _histogram_all(name, le, *, labels=None):
    labels = labels or {}
    bucket = _selector(f'{name}_bucket', {**labels, 'le': str(le)})
    count = _selector(f'{name}_count', labels)
    return _predicate(
        'histogram_le_all',
        f'sum(increase({bucket}[run])) == bool sum(increase({count}[run]))',
        '==',
        1,
        count_promql=f'sum(increase({count}[run]))',
    )


def _histogram_quantile(name, quantile, *, labels=None):
    labels = labels or {}
    bucket = _selector(f'{name}_bucket', labels)
    count = _selector(f'{name}_count', labels)
    return _predicate(
        'histogram_quantile',
        f'histogram_quantile({quantile}, sum by (le) (increase({bucket}[run])))',
        '<',
        1,
        count_promql=f'sum(increase({count}[run]))',
    )


def _gauge_max(name, comparator, threshold, *, labels=None):
    return _predicate(
        'gauge_max',
        f'max_over_time({_selector(name, labels)}[run])',
        comparator,
        threshold,
    )


def _gauge_delta(name, comparator, threshold, *, labels=None):
    return _predicate(
        'gauge_delta',
        f'delta({_selector(name, labels)}[run])',
        comparator,
        threshold,
    )


def _counter_rate(name, comparator, threshold, window='5m', *, labels=None):
    return _predicate(
        'counter_rate',
        f'sum(rate({_selector(name, labels)}[{window}]))',
        comparator,
        threshold,
    )


def _counter_increase(name, comparator, threshold, *, labels=None):
    return _predicate(
        'counter_increase',
        f'sum(increase({_selector(name, labels)}[run]))',
        comparator,
        threshold,
    )


def _raw_map(pm_max_children):
    records = {}

    memory_rows = [
        ('SLO-40', 'tus_patch', 'S', 67_108_864),
        ('SLO-41', 'tus_patch', 'M', 67_108_864),
        ('SLO-42', 'tus_patch', 'L', 67_108_864),
        ('SLO-43', 's3_putpart', 'S', 167_772_160),
        ('SLO-44', 's3_putpart', 'M', 167_772_160),
        ('SLO-45', 's3_putpart', 'L', 167_772_160),
        ('SLO-46', 's3_complete', 'S', 167_772_160),
        ('SLO-47', 's3_complete', 'M', 167_772_160),
        ('SLO-48', 's3_complete', 'L', 167_772_160),
        ('SLO-49', 'single_shot', 'S', 67_108_864),
        ('SLO-50', 'single_shot', 'M', 67_108_864),
        ('SLO-51', 'single_shot', 'L', 67_108_864),
    ]
    for slo_id, operation, tier, threshold in memory_rows:
        records[slo_id] = {
            'predicates': [_gauge_max(
                'vaulter_request_peak_memory_bytes',
                '<',
                threshold,
                labels={'operation': operation, 'tier': tier},
            )],
        }

    for slo_id, tier in [('SLO-52', 'S'), ('SLO-53', 'M')]:
        records[slo_id] = {
            'expression_class': 'compound',
            'predicates': [
                _gauge_max(
                    'vaulter_request_peak_memory_bytes',
                    '<',
                    16_777_216,
                    labels={'operation': 'download', 'tier': tier},
                ),
                _gauge_max('vaulter_download_stream_active', '<=', pm_max_children),
            ],
        }
    records['SLO-54'] = {
        'expression_class': 'compound',
        'predicates': [_gauge_max('vaulter_download_stream_active', '<=', pm_max_children)],
    }

    for slo_id, le in [('SLO-55', '0.01'), ('SLO-56', '0.008'), ('SLO-57', '0.012')]:
        records[slo_id] = {
            'predicates': [_histogram_ratio('vaulter_outbox_stage_duration_seconds', le)],
        }

    for slo_id, le, rate in [
        ('SLO-58', '1.5', 200),
        ('SLO-59', '1', 800),
        ('SLO-60', '1', 2_000),
    ]:
        records[slo_id] = {
            'expression_class': 'compound',
            'predicates': [
                _histogram_ratio('vaulter_outbox_relay_duration_seconds', le),
                _gauge_delta('vaulter_outbox_backlog', '<=', 0),
                _counter_rate('vaulter_outbox_relayed_total', '>', rate),
            ],
        }

    for slo_id, queue_le, delivery_le, rate in [
        ('SLO-61', '5', '2', 50),
        ('SLO-62', '3', '2', 200),
        ('SLO-63', '2', '2', 600),
    ]:
        records[slo_id] = {
            'expression_class': 'compound',
            'predicates': [
                _histogram_ratio('vaulter_webhook_queue_wait_seconds', queue_le),
                _histogram_ratio('vaulter_webhook_delivery_duration_seconds', delivery_le),
                _counter_rate('vaulter_webhook_delivered_total', '>', rate),
            ],
        }

    for slo_id, tick_le in [('SLO-64', '2'), ('SLO-65', '1.5'), ('SLO-66', '1.5')]:
        records[slo_id] = {
            'expression_class': 'compound',
            'predicates': [
                _histogram_ratio('vaulter_webhook_retry_tick_seconds', tick_le),
                _histogram_all('vaulter_webhook_retry_lockset_rows', '1000'),
                _gauge_max(
                    'vaulter_command_peak_memory_bytes',
                    '<',
                    268_435_456,
                    labels={'command': 'vaulter:webhooks:retry'},
                ),
            ],
        }

    for slo_id, prune_le in [('SLO-67', '60'), ('SLO-68', '60'), ('SLO-69', '120')]:
        records[slo_id] = {
            'expression_class': 'compound',
            'predicates': [
                _histogram_all('vaulter_prune_duration_seconds', prune_le),
                _histogram_all('vaulter_pg_lock_wait_seconds', '1'),
            ],
        }

    for slo_id, tick_le, batch_le in [
        ('SLO-70', '5', '1000'),
        ('SLO-71', '10', '5000'),
        ('SLO-72', '15', '10000'),
    ]:
        records[slo_id] = {
            'expression_class': 'compound',
            'predicates': [
                _histogram_ratio('vaulter_sweep_tick_seconds', tick_le),
                _histogram_all('vaulter_sweep_batch_rows', batch_le),
                _gauge_max(
                    'vaulter_command_peak_memory_bytes',
                    '<',
                    268_435_456,
                    labels={'command': 'vaulter:uploads:sweep'},
                ),
            ],
        }

    for slo_id, duration_le, batch_le in [
        ('SLO-73', '60', '1000'),
        ('SLO-74', '90', '5000'),
        ('SLO-75', '120', '10000'),
    ]:
        records[slo_id] = {
            'expression_class': 'compound',
            'predicates': [
                _histogram_all('vaulter_purge_duration_seconds', duration_le),
                _histogram_all('vaulter_purge_batch_nodes', batch_le),
            ],
        }

    for slo_id, duration_le, batch_le, scan_rate, delete_rate in [
        ('SLO-76', '300', '1000', 2_000, 100),
        ('SLO-77', '1800', '5000', 5_000, 200),
        ('SLO-78', '21600', '10000', 10_000, 400),
    ]:
        records[slo_id] = {
            'expression_class': 'compound',
            'predicates': [
                _histogram_all('vaulter_gc_duration_seconds', duration_le),
                _histogram_all('vaulter_gc_batch_rows', batch_le),
                _counter_rate('vaulter_gc_rows_scanned_total', '>', scan_rate, window='1m'),
                _counter_rate('vaulter_gc_objects_deleted_total', '>', delete_rate, window='1m'),
                _gauge_max(
                    'vaulter_command_peak_memory_bytes',
                    '<',
                    268_435_456,
                    labels={'command': 'vaulter:gc-blobs'},
                ),
            ],
        }

    for slo_id, duration_le, rate in [
        ('SLO-79', '1800', 1_000),
        ('SLO-80', '7200', 2_000),
        ('SLO-81', '14400', 4_000),
    ]:
        records[slo_id] = {
            'expression_class': 'compound',
            'predicates': [
                _histogram_all('vaulter_reconcile_duration_seconds', duration_le),
                _counter_rate('vaulter_reconcile_objects_total', '>', rate, window='1m'),
                _gauge_max(
                    'vaulter_command_peak_memory_bytes',
                    '<',
                    536_870_912,
                    labels={'command': 'vaulter:reconcile-blobs'},
                ),
            ],
        }

    return records


_LABEL_RE = re.compile(r'\{([^{}]+)\}')


def _labels(promql):
    found = []
    for matcher in _LABEL_RE.findall(promql):
        labels = {}
        for item in matcher.split(','):
            key, _, value = item.partition('=')
            if key and value.startswith('"') and value.endswith('"'):
                labels[key] = value[1:-1]
        if labels and labels not in found:
            found.append(labels)
    return found


def build_promql_map(rows, pm_max_children):
    raw = _raw_map(pm_max_children)
    class_a = {row['id']: row for row in rows if row['class'] == 'A'}

    if set(raw) != set(class_a):
        missing = sorted(set(class_a) - set(raw))
        extra = sorted(set(raw) - set(class_a))
        raise ValueError(f'promql map parity failure: missing={missing}, extra={extra}')

    result = {}
    for slo_id, definition in raw.items():
        row = class_a[slo_id]
        predicates = definition['predicates']
        blocking, _ = blocking_profiles(row)
        expression_class = definition.get(
            'expression_class',
            predicates[0]['expression_class'] if len(predicates) == 1 else 'compound',
        )
        promql = [item['promql'] for item in predicates]
        result[slo_id] = {
            'slo_id': slo_id,
            'class': 'A',
            'expression_class': expression_class,
            'promql': promql[0] if len(promql) == 1 else promql,
            'comparator': predicates[0]['comparator'] if len(predicates) == 1 else None,
            'threshold': predicates[0]['threshold'] if len(predicates) == 1 else None,
            'labels': [label for query in promql for label in _labels(query)],
            'blocking_profiles': sorted(blocking),
            'predicates': predicates,
        }

    return result


def instantiate_windows(records, duration_seconds):
    duration_seconds = max(60, math.ceil(float(duration_seconds)))
    run = f'{duration_seconds}s'
    rate_5m = run if duration_seconds < 300 else '5m'
    rate_1m = run if duration_seconds < 300 else '1m'
    result = deepcopy(records)

    def substitute(value):
        return (
            value
            .replace('[run]', f'[{run}]')
            .replace('[5m]', f'[{rate_5m}]')
            .replace('[1m]', f'[{rate_1m}]')
        )

    for record in result.values():
        if isinstance(record['promql'], list):
            record['promql'] = [substitute(item) for item in record['promql']]
        else:
            record['promql'] = substitute(record['promql'])

        for predicate in record['predicates']:
            predicate['promql'] = substitute(predicate['promql'])
            if 'count_promql' in predicate:
                predicate['count_promql'] = substitute(predicate['count_promql'])

    return result


__all__ = [
    '_counter_increase',
    '_histogram_quantile',
    'build_promql_map',
    'instantiate_windows',
]
