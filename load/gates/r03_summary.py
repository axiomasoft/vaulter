#!/usr/bin/env python3
"""Class A/B summary audit; a sparse or shortened run never becomes product proof."""

from __future__ import annotations

import argparse
import json
import re
from pathlib import Path

MIN_P95_SAMPLES = 100  # at least five observations in the upper 5% tail
SPARSE_KEYS = ('ttfb_ms{scenario:download,tier:S}', 'broadcast_lag_ms{tier:S}')
STREAM_KEYS = (
    'stream_throughput_mibs{scenario:tus-upload,tier:S}',
    'stream_throughput_mibs{scenario:s3-multipart,tier:S}',
    'stream_throughput_mibs{scenario:single-shot,tier:S}',
    'stream_throughput_mibs{scenario:download,tier:S}',
)


def duration_ms(value: str) -> int:
    match = re.fullmatch(r'(\d+)(ms|s|m)', value)
    if not match:
        raise ValueError(f'invalid declared duration: {value}')
    return int(match.group(1)) * {'ms': 1, 's': 1000, 'm': 60000}[match.group(2)]


def metric_verdict(metrics: dict, key: str, *, min_samples: int) -> dict:
    metric = metrics.get(key) or {}
    values = metric.get('values') or {}
    count = values.get('count')
    if count is None or count < min_samples:
        return {'status': 'unevaluated', 'count': count, 'required': min_samples}
    thresholds = metric.get('thresholds') or {}
    if not thresholds:
        return {'status': 'observed', 'count': count, 'p95': values.get('p(95)')}
    return {
        'status': 'pass' if all(row.get('ok') is True for row in thresholds.values()) else 'fail',
        'count': count,
        'p95': values.get('p(95)'),
    }


def audit(summary: dict, declared_duration: str) -> dict:
    metrics = summary.get('metrics') or {}
    meta = summary.get('meta') or {}
    elapsed = (summary.get('state') or {}).get('testRunDurationMs')
    declared = duration_ms(declared_duration)
    full_window = isinstance(elapsed, (int, float)) and elapsed >= declared
    expected_b = meta.get('expected_class_b_keys') or []
    class_b = {key: metric_verdict(metrics, key, min_samples=MIN_P95_SAMPLES) for key in expected_b}
    sparse = {key: metric_verdict(metrics, key, min_samples=MIN_P95_SAMPLES) for key in SPARSE_KEYS}
    stream = {}
    for key in STREAM_KEYS:
        row = metrics.get(key) or {}
        thresholds = row.get('thresholds') or {}
        stream[key] = {
            'status': 'unevaluated' if not thresholds else (
                'pass' if all(item.get('ok') is True for item in thresholds.values()) else 'fail'
            ),
            'median_mibs': (row.get('values') or {}).get('med'),
        }
    return {
        'source_meta': meta,
        'declared_duration_ms': declared,
        'observed_duration_ms': elapsed,
        'full_window': full_window,
        'class_b': class_b,
        'sparse': sparse,
        'tier_s_streaming': stream,
        'proof_ready': bool(full_window and meta.get('profile') == 'lab' and meta.get('tier') == 'S'
                            and expected_b and all(
            row['status'] in {'pass', 'fail'} for row in class_b.values()
        )),
    }


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--summary', type=Path, required=True)
    parser.add_argument('--declared-duration', required=True)
    args = parser.parse_args()
    report = audit(json.loads(args.summary.read_text(encoding='utf-8')), args.declared_duration)
    print(json.dumps(report, indent=2, sort_keys=True))
    return 0 if report['proof_ready'] else 2


if __name__ == '__main__':
    raise SystemExit(main())
