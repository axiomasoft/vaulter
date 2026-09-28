#!/usr/bin/env python3
"""R03 window/sample/lab admission. A qualified measured RED is still valid evidence."""
from __future__ import annotations

import argparse
import json
import math
import re
from pathlib import Path

MIN_P95_SAMPLES = 100
LAB_B_S = {
    'OP-18': 120, 'OP-12': 300, 'OP-14': 600, 'OP-16': 180,
    'OP-20': 900, 'OP-25': 225, 'OP-28': 225, 'OP-29': 300,
    'OP-34': 150, 'OP-31': 375, 'OP-38': 225, 'OP-01': 180,
    'OP-02': 600, 'OP-06': 4500, 'OP-17': 1800,
}
IDENTITY_FIELDS = ('run_id', 'commit', 'source_digest', 'env_fingerprint',
                   'dataset_id', 'tier', 'declared_duration')
SPARSE_KEYS = ('ttfb_ms{scenario:download,tier:S}', 'broadcast_lag_ms{tier:S}')


def duration_ms(value: str) -> int:
    match = re.fullmatch(r'(\d+)(ms|s|m)', value)
    if not match:
        raise ValueError(f'invalid declared duration: {value}')
    return int(match.group(1)) * {'ms': 1, 's': 1000, 'm': 60000}[match.group(2)]


def finite(value):
    return isinstance(value, (int, float)) and not isinstance(value, bool) and math.isfinite(value)


def metric_verdict(metrics: dict, key: str, *, min_samples: int, expected_thresholds=None) -> dict:
    metric = metrics.get(key) or {}
    values = metric.get('values') or {}
    count = values.get('count')
    if not finite(count) or count < min_samples:
        return {'status': 'unevaluated', 'count': count, 'required': min_samples}
    thresholds = metric.get('thresholds') or {}
    if not thresholds or (expected_thresholds is not None and set(thresholds) != set(expected_thresholds)):
        return {'status': 'unevaluated', 'count': count, 'reason': 'missing or changed thresholds'}
    stats = [re.split(r'[<>]=?', expression)[0] for expression in thresholds]
    if any(not finite(values.get(stat)) for stat in stats) or any(
            not isinstance(row.get('ok'), bool) for row in thresholds.values()):
        return {'status': 'unevaluated', 'count': count, 'reason': 'missing statistic or threshold result'}
    return {
        'status': 'pass' if all(row.get('ok') is True for row in thresholds.values()) else 'fail',
        'count': count, 'p95': values.get('p(95)'), 'median_mibs': values.get('med'),
    }


def lab_admission(meta, facts):
    facts = facts or {}
    identity = facts.get('identity') or {}
    identity_ok = all(meta.get(key) not in (None, '', 'unknown', 'unset')
                      and identity.get(key) == meta[key] for key in IDENTITY_FIELDS)
    checks = facts.get('checks') or {}
    rejected = [f'L-{i}' for i in range(1, 8) if
                not isinstance(checks.get(f'L-{i}'), dict)
                or checks[f'L-{i}'].get('ok') is not True
                or not checks[f'L-{i}'].get('evidence')]
    return {'admitted': identity_ok and not rejected, 'identity_matches': identity_ok,
            'rejected_conditions': rejected, 'facts': facts}


def stream_contract(tier):
    # Original floors; S0 reuses S. These must match thresholds.js, tested offline.
    index = {'S0': 0, 'S': 0, 'M': 1, 'L': 2}.get(tier)
    if index is None:
        return {}
    upload = [f'med>{[25, 30, 35][index]}', f'min>{[15, 18, 21][index]}']
    contract = {}
    for scenario, op in [('tus-upload', 'OP-02'), ('s3-multipart', 'OP-06'), ('single-shot', 'OP-17')]:
        contract[f'stream_throughput_mibs{{scenario:{scenario},op:{op},tier:{tier}}}'] = upload
    contract[f'stream_throughput_mibs{{scenario:s3-multipart,op:OP-07,tier:{tier}}}'] = [f'med>{[100, 200, 300][index]}']
    contract[f'stream_throughput_mibs{{scenario:download,tier:{tier}}}'] = [f'med>{[30, 40, 50][index]}', f'min>{[18, 24, 30][index]}']
    contract[f'accepted_sent_bytes{{scenario:tus-upload,op:OP-02,tier:{tier}}}'] = [f'rate>{[52428800, 209715200, 524288000][index]}']
    contract[f'accepted_received_bytes{{scenario:download,op:OP-08,tier:{tier}}}'] = [f'rate>{[62914560, 262144000, 629145600][index]}']
    return contract


def audit(summary: dict, declared_duration: str, lab_facts=None) -> dict:
    metrics = summary.get('metrics') or {}
    meta = summary.get('meta') or {}
    elapsed = (summary.get('state') or {}).get('testRunDurationMs')
    declared = duration_ms(declared_duration)
    full_window = finite(elapsed) and elapsed >= declared
    tier = meta.get('tier')
    expected_b = {f'http_req_duration{{op:{op},tier:S}}': [f'p(95)<{limit}'] for op, limit in LAB_B_S.items()}
    declared_b = meta.get('expected_class_b_keys') or []
    class_b = {key: metric_verdict(metrics, key, min_samples=MIN_P95_SAMPLES,
                                   expected_thresholds=limits) for key, limits in expected_b.items()}
    streaming = {key: metric_verdict(metrics, key, min_samples=1, expected_thresholds=limits)
                 for key, limits in stream_contract(tier).items()}
    participation = {}
    for op in ['OP-08', 'OP-09', 'OP-10']:
        key = f'http_reqs{{op:{op},tier:{tier}}}'
        count = ((metrics.get(key) or {}).get('values') or {}).get('count')
        participation[key] = {'count': count, 'observed': finite(count) and count > 0}
    lab = lab_admission(meta, lab_facts if lab_facts is not None else summary.get('lab_facts'))
    ready = bool(full_window and declared >= 180000
                 and meta.get('declared_duration') == declared_duration
                 and meta.get('profile') == 'lab' and tier == 'S'
                 and set(declared_b) == set(expected_b) and lab['admitted']
                 and all(row['status'] in {'pass', 'fail'} for row in class_b.values())
                 and streaming and all(row['status'] in {'pass', 'fail'} for row in streaming.values())
                 and all(row['observed'] for row in participation.values()))
    return {
        'source_meta': meta, 'declared_duration_ms': declared, 'observed_duration_ms': elapsed,
        'full_window': full_window, 'class_b': class_b, 'streaming': streaming,
        'tier_s_streaming': streaming if tier == 'S' else {},
        'sparse': {key: metric_verdict(metrics, key, min_samples=MIN_P95_SAMPLES) for key in SPARSE_KEYS},
        'download_participation': participation, 'lab': lab,
        # Server protocol=single includes TUS/S3 finalization via UploadFileAction.
        # OP-17 is isolated only in the client accepted-byte/operation series.
        'server_single_counter_scope': 'includes TUS/S3 finalization; not an OP-17 byte source',
        'stream_outcomes': {key: value for key, value in metrics.items()
                            if key.startswith(('stream_accepted_total', 'stream_rejected_total',
                                               'accepted_sent_bytes', 'accepted_received_bytes',
                                               'finalized_bytes', 'accepted_stream_duration_seconds'))},
        'proof_ready': ready,
    }


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--summary', type=Path, required=True)
    parser.add_argument('--declared-duration', required=True)
    parser.add_argument('--lab-facts', type=Path, help='Run-bound L-1…L-7 checks with raw evidence')
    args = parser.parse_args()
    facts = json.loads(args.lab_facts.read_text()) if args.lab_facts else None
    report = audit(json.loads(args.summary.read_text()), args.declared_duration, facts)
    print(json.dumps(report, indent=2, sort_keys=True))
    return 0 if report['proof_ready'] else 2


if __name__ == '__main__':
    raise SystemExit(main())
