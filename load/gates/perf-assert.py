#!/usr/bin/env python3
"""VLT-LOAD P2.5 — perf-assert.py: the SERVER plane of the two-plane gate (D18). k6
only sees the client side (RAG-8b) — this script reads the same `summary.json`
(client metrics + `meta`, written by `load/k6/lib/summary.js`'s `handleSummary`) plus a
Prometheus snapshot (server metrics, mostly absent until P8 ships) and a baseline
artifact (class B), and prints one verdict per slo-gates.md §3 row:

    pass | fail | not-checkable (metric absent) | incomparable (env_fingerprint differs)
    | known-red (L-01, §6.4) | advisory (not gated outside `lab`)

Exit code is non-zero ONLY when a row that is blocking for the given --profile verdicts
`fail`. `not-checkable`/`incomparable`/`known-red`/`advisory` never fail the gate on
their own — printing them IS the honesty requirement (D9: a silent pass on a missing
metric is not a verdict; Code Guidance "Анти-самообман").

Usage:
    python3 load/gates/perf-assert.py --summary summary.json \\
        [--baseline load/baselines/smoke-perf-S0.json] [--profile smoke-perf] \\
        [--prometheus-url http://localhost:9090] [--container-peak container-peak.json]
"""
import argparse
import json
import re
import sys
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path

sys.path.insert(0, str(Path(__file__).parent))
from _slo_table import KNOWN_RED_IDS, blocking_profiles, parse_rows, prometheus_metric_names  # noqa: E402

REPO_ROOT = Path(__file__).resolve().parents[2]
SLO_GATES_MD = REPO_ROOT / 'plans' / 'VLT-LOAD' / 'slo-gates.md'

# Baseline policy (slo-gates.md §4.3) — the ONE number this plan has not yet measured
# (P2.7, opus/high, ЗАПУСК ВРУЧНУЮ). `[гипотеза]` until then; changing it here would be
# exactly the "подгонка" Implementation Rules forbids.
DELTA_HYPOTHESIS = 0.10


def load_json(path):
    with open(path, encoding='utf-8') as f:
        return json.load(f)


def query_prometheus(prom_url, metric):
    """Query Prometheus's instant-query API for `metric`. Returns
    (unreachable: bool, value: float|None) — `unreachable=True` means Prometheus itself
    could not be reached (a different not-checkable reason than "metric has no series
    yet", so the two are never conflated in the printed verdict)."""
    url = f'{prom_url.rstrip("/")}/api/v1/query?{urllib.parse.urlencode({"query": metric})}'

    try:
        with urllib.request.urlopen(url, timeout=5) as resp:
            payload = json.loads(resp.read().decode('utf-8'))
    except (urllib.error.URLError, OSError, ValueError):
        return True, None

    result = payload.get('data', {}).get('result') or []

    if not result:
        return False, None

    try:
        return False, float(result[0]['value'][1])
    except (KeyError, IndexError, ValueError):
        return False, None


_SIMPLE_CMP = re.compile(r'(<|>|<=|>=)\s*([0-9]+(?:\.[0-9]+)?)')


def evaluate_simple_expr(expr_text, value):
    """Best-effort evaluation of a SINGLE numeric comparison extracted from a prose
    expression cell. Anything with multiple ANDed conditions or non-numeric text is
    deliberately left unevaluated (`not-evaluated`) rather than guessed — Code Guidance
    forbids inventing a passing verdict for an expression this script cannot parse
    faithfully."""
    matches = _SIMPLE_CMP.findall(expr_text)

    if len(matches) != 1 or value is None:
        return None

    op, num = matches[0]
    num = float(num)

    return {
        '<': value < num, '>': value > num,
        '<=': value <= num, '>=': value >= num,
    }[op]


def summary_metric_values(summary, metric_ref):
    """`metric_ref` is a slo-gates.md k6-метрика cell, e.g.
    `http_req_duration{scenario:point_read,tier:S}` or a ` + `-joined pair
    (`ttfb_ms{...} + http_req_duration{...}`). Returns the FIRST ref's `values` dict from
    `summary['metrics']` if k6 registered that exact tag-filtered sub-metric (only true
    for names thresholds.js actually put in options.thresholds — advisory rows never
    get a tag-split sub-metric from k6, so this legitimately returns None for almost
    every class-B row today; that is reported as not-checkable, not invented)."""
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
        return f'no baseline yet (current p(95)={current_p95})', False

    if baseline.get('meta', {}).get('env_fingerprint') != summary.get('meta', {}).get('env_fingerprint'):
        return 'incomparable (env_fingerprint differs from baseline)', False

    baseline_values = baseline.get('metrics', {}).get(row['k6_metric'].split(' + ')[0].strip(), {}).get('values', {})
    baseline_p95 = baseline_values.get('p(95)')

    if baseline_p95 is None:
        return 'not-checkable (baseline has no matching metric)', is_blocking

    threshold = baseline_p95 * (1 + DELTA_HYPOTHESIS)
    verdict = 'pass' if current_p95 <= threshold else 'fail'

    return f'{verdict} (p(95)={current_p95:.1f} vs baseline*(1+δ)={threshold:.1f})', is_blocking and verdict == 'fail'


def class_a_verdict(row, summary, prom_url, container_peak):
    if row['id'] in KNOWN_RED_IDS and row['class'] == 'A':
        return 'known-red (L-01, awaits D11 — §6.4)', False

    metrics = prometheus_metric_names(row['prom_metric'])

    if not metrics:
        return 'not-checkable (no Prometheus metric named for this row)', False

    if not prom_url:
        note = ''

        if container_peak and any('peak_memory' in m for m in metrics):
            peak = container_peak.get('app', {}).get('memory_peak_bytes', 'n/a')
            note = f' (container-level peak available as a coarse stand-in: {peak} bytes — NOT per-operation, informational only)'

        return f'not-checkable (no --prometheus-url given){note}', False

    results = {m: query_prometheus(prom_url, m) for m in metrics}

    if any(unreachable for unreachable, _ in results.values()):
        return 'not-checkable (Prometheus unreachable)', False

    values = [value for _, value in results.values()]

    if any(v is None for v in values):
        return 'not-checkable (metric has no series yet — expected pre-P8)', False

    # Metric exists (post-P8 future) — only single-condition expressions are
    # auto-evaluated; compound ones are flagged for manual review rather than guessed.
    outcome = evaluate_simple_expr(row['expr'], values[0])

    if outcome is None:
        return 'not-evaluated (compound/non-numeric expression — manual review required)', False

    return ('pass' if outcome else 'fail'), not outcome


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument('--summary', required=True)
    parser.add_argument('--baseline')
    parser.add_argument('--profile', default='smoke-perf', choices=['smoke-perf', 'regression', 'lab'])
    parser.add_argument('--prometheus-url', default=None)
    parser.add_argument('--container-peak')
    args = parser.parse_args()

    summary = load_json(args.summary)
    baseline = load_json(args.baseline) if args.baseline and Path(args.baseline).exists() else None
    container_peak = load_json(args.container_peak) if args.container_peak and Path(args.container_peak).exists() else None

    rows = parse_rows(str(SLO_GATES_MD))

    counts = {'pass': 0, 'fail': 0, 'not-checkable': 0, 'incomparable': 0, 'known-red': 0, 'advisory': 0}
    blocking_failures = []

    for row in rows:
        blocking, known_red = blocking_profiles(row)
        applies_here = row['section'] != '3.3' or args.profile in ('regression', 'lab')

        if not applies_here:
            continue

        if row['class'] == 'B':
            text, is_blocking_fail = class_b_verdict(row, summary, baseline, args.profile)
        else:
            text, is_blocking_fail = class_a_verdict(row, summary, args.prometheus_url, container_peak)

        bucket = next((k for k in counts if text.startswith(k)), 'not-checkable')
        counts[bucket] += 1

        print(f'{row["id"]} ({row["class"]}, {"blocking" if args.profile in blocking else "advisory"}): {text}')

        if is_blocking_fail:
            blocking_failures.append(row['id'])

    print()
    print(f'pass={counts["pass"]} fail={counts["fail"]} not-checkable={counts["not-checkable"]} '
          f'incomparable={counts["incomparable"]} known-red={counts["known-red"]}')

    if blocking_failures:
        print(f'BLOCKING FAILURES: {", ".join(blocking_failures)}', file=sys.stderr)
        return 1

    return 0


if __name__ == '__main__':
    sys.exit(main())
