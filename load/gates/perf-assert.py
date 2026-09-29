#!/usr/bin/env python3
"""Evaluate every slo-gates.md row on its owning plane.

Class B remains on the k6 summary/baseline plane. The 42 Class-A rows are joined to
promql_map.py and evaluated through Prometheus; classic histogram base names are never
queried as scalars.
"""

from __future__ import annotations

import argparse
import json
import math
import os
import sys
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path

sys.path.insert(0, str(Path(__file__).parent))
from _slo_table import KNOWN_RED_IDS, blocking_profiles, parse_rows  # noqa: E402
from promql_map import build_promql_map, instantiate_windows  # noqa: E402

REPO_ROOT = Path(__file__).resolve().parents[2]
SLO_GATES_MD = REPO_ROOT / 'plans' / 'archive' / 'VLT-LOAD' / 'slo-gates.md'
DELTA = 0.10


def load_json(path):
    with open(path, encoding='utf-8') as file:
        return json.load(file)


def _prometheus_value(payload):
    try:
        data = payload['data']
        result_type = data.get('resultType')
        result = data.get('result')
        if result_type == 'scalar':
            value = float(result[1])
        else:
            if not isinstance(result, list) or len(result) != 1:
                return None
            value = float(result[0]['value'][1])
    except (KeyError, IndexError, TypeError, ValueError):
        return None

    return value if math.isfinite(value) else None


class HttpPrometheusClient:
    def __init__(self, base_url):
        self.base_url = base_url.rstrip('/')

    def query(self, promql):
        url = f'{self.base_url}/api/v1/query?{urllib.parse.urlencode({"query": promql})}'
        try:
            with urllib.request.urlopen(url, timeout=5) as response:
                payload = json.loads(response.read().decode('utf-8'))
        except (urllib.error.URLError, OSError, ValueError):
            return True, None
        return False, _prometheus_value(payload)


class FixturePrometheusClient:
    def __init__(self, responses):
        self.responses = responses

    def query(self, promql):
        if promql not in self.responses:
            return False, None
        response = self.responses[promql]
        if response == 'unreachable':
            return True, None
        if isinstance(response, (int, float)):
            value = float(response)
            return False, value if math.isfinite(value) else None
        return False, _prometheus_value(response)


def query_prometheus(prom_url, promql):
    """Compatibility wrapper used by focused tests and external harness callers."""
    return HttpPrometheusClient(prom_url).query(promql)


def _compare(value, comparator, threshold):
    return {
        '<': value < threshold,
        '<=': value <= threshold,
        '>': value > threshold,
        '>=': value >= threshold,
        '==': value == threshold,
    }[comparator]


def evaluate_predicate(predicate, client):
    count_promql = predicate.get('count_promql')
    if count_promql is not None:
        unreachable, count = client.query(count_promql)
        if unreachable:
            return 'not-checkable', f'Prometheus unreachable for {count_promql}'
        if count is None:
            return 'not-checkable', f'histogram count series missing for {count_promql}'
        if count <= 0:
            return 'not-evaluated', f'histogram count did not increase for {count_promql}'

    unreachable, value = client.query(predicate['promql'])
    if unreachable:
        return 'not-checkable', f'Prometheus unreachable for {predicate["promql"]}'
    if value is None:
        return 'not-checkable', f'query returned no single finite value: {predicate["promql"]}'

    passed = _compare(value, predicate['comparator'], predicate['threshold'])
    status = 'pass' if passed else 'fail'
    detail = (
        f'{predicate["expression_class"]}: {value:g} '
        f'{predicate["comparator"]} {predicate["threshold"]:g}'
    )
    return status, detail


def evaluate_record(record, client):
    verdicts = [evaluate_predicate(predicate, client) for predicate in record['predicates']]
    statuses = [status for status, _ in verdicts]
    details = '; '.join(detail for _, detail in verdicts)

    if 'fail' in statuses:
        return 'fail', details
    if 'not-checkable' in statuses:
        return 'not-checkable', details
    if 'not-evaluated' in statuses:
        return 'not-evaluated', details
    return 'pass', details


def summary_metric_values(summary, metric_ref):
    first_ref = metric_ref.split(' + ')[0].strip()
    metrics = summary.get('metrics', {})
    if first_ref in metrics:
        return metrics[first_ref].get('values')
    bare_name = first_ref.split('{')[0]
    return metrics.get(bare_name, {}).get('values') if '{' not in first_ref else None


def class_b_verdict(row, summary, baseline, profile):
    blocking, _ = blocking_profiles(row)
    is_blocking = profile in blocking
    values = summary_metric_values(summary, row['k6_metric'])

    if values is None or 'p(95)' not in values:
        return 'not-checkable (metric/tag combination not recorded in summary.json)', is_blocking
    current_p95 = values['p(95)']

    if baseline is None:
        return f'advisory (no baseline; current p(95)={current_p95})', False
    if baseline.get('meta', {}).get('env_fingerprint') != summary.get('meta', {}).get('env_fingerprint'):
        return 'incomparable (env_fingerprint differs from baseline)', False

    key = row['k6_metric'].split(' + ')[0].strip()
    baseline_p95 = baseline.get('metrics', {}).get(key, {}).get('values', {}).get('p(95)')
    if baseline_p95 is None:
        return 'not-checkable (baseline has no matching metric)', is_blocking

    threshold = baseline_p95 * (1 + DELTA)
    verdict = 'pass' if current_p95 <= threshold else 'fail'
    return (
        f'{verdict} (p(95)={current_p95:.1f} vs baseline*(1+delta)={threshold:.1f})',
        is_blocking and verdict == 'fail',
    )


def class_a_verdict(row, records, client):
    if client is None:
        return 'not-checkable (no --prometheus-url given)', False

    status, details = evaluate_record(records[row['id']], client)
    if row['id'] in KNOWN_RED_IDS:
        return f'known-red ({status}: {details}; L-01 awaits D11)', False
    return f'{status} ({details})', status == 'fail'


def _verify_histogram_fixture(fixture):
    histogram = fixture.get('histogram')
    if histogram is None:
        return
    buckets = histogram.get('buckets', {})
    count = histogram.get('count')
    if buckets.get('+Inf') != count:
        raise ValueError(f'{fixture["name"]}: +Inf bucket differs from count')
    previous = -math.inf
    for value in buckets.values():
        if value < previous:
            raise ValueError(f'{fixture["name"]}: histogram buckets are not cumulative')
        previous = value


def verify_fixtures(directory):
    paths = sorted(Path(directory).glob('*.json'))
    if not paths:
        raise ValueError(f'no fixture JSON files under {directory}')

    for path in paths:
        fixture = load_json(path)
        _verify_histogram_fixture(fixture)
        status, details = evaluate_record(
            fixture['record'],
            FixturePrometheusClient(fixture['responses']),
        )
        if status != fixture['expected']:
            raise ValueError(
                f'{fixture["name"]}: expected {fixture["expected"]}, got {status}: {details}'
            )
        print(f'{fixture["name"]}: {status}')
    return 0


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--summary')
    parser.add_argument('--baseline')
    parser.add_argument('--profile', default='smoke-perf', choices=['smoke-perf', 'regression', 'lab'])
    parser.add_argument('--prometheus-url')
    parser.add_argument('--container-peak')
    parser.add_argument('--pm-max-children', type=int, default=int(os.environ.get('PM_MAX_CHILDREN', '8')))
    parser.add_argument('--verify-fixtures')
    args = parser.parse_args()

    if args.verify_fixtures:
        return verify_fixtures(args.verify_fixtures)
    if not args.summary:
        parser.error('--summary is required unless --verify-fixtures is used')

    summary = load_json(args.summary)
    baseline = load_json(args.baseline) if args.baseline and Path(args.baseline).exists() else None
    rows = parse_rows(str(SLO_GATES_MD))
    duration = summary.get('state', {}).get('testRunDurationMs', 0) / 1_000
    records = instantiate_windows(
        build_promql_map(rows, args.pm_max_children),
        duration,
    )
    client = HttpPrometheusClient(args.prometheus_url) if args.prometheus_url else None
    counts = {
        'pass': 0,
        'fail': 0,
        'not-checkable': 0,
        'not-evaluated': 0,
        'incomparable': 0,
        'known-red': 0,
        'advisory': 0,
    }
    blocking_failures = []

    for row in rows:
        blocking, _ = blocking_profiles(row)
        applies_here = row['section'] != '3.3' or args.profile in ('regression', 'lab')
        if not applies_here:
            continue

        if row['class'] == 'B':
            text, blocking_failure = class_b_verdict(row, summary, baseline, args.profile)
        else:
            text, blocking_failure = class_a_verdict(row, records, client)

        bucket = next((key for key in counts if text.startswith(key)), 'not-checkable')
        counts[bucket] += 1
        gate = 'blocking' if args.profile in blocking else 'advisory'
        print(f'{row["id"]} ({row["class"]}, {gate}): {text}')
        if blocking_failure:
            blocking_failures.append(row['id'])

    print()
    print(' '.join(f'{key}={value}' for key, value in counts.items()))
    if blocking_failures:
        print(f'BLOCKING FAILURES: {", ".join(blocking_failures)}', file=sys.stderr)
        return 1
    return 0


if __name__ == '__main__':
    sys.exit(main())
