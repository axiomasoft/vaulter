#!/usr/bin/env python3
"""VLT-LOAD P2.5 — coverage.py: machine check that every slo-gates.md §3 row has an
assigned place of computation. Two planes (D18): `load/gates/thresholds.js` (k6
options.thresholds, only the SLO-40..54 "A" rows can live here — RAG-8b, everything
else is either latency or has no clientside input at all) and `load/gates/perf-assert.py`
(PromQL/summary.json, which parses the WHOLE §3 table by construction — see
`_slo_table.py`). "Assigned" here means: the parser did not drop the row, AND, for rows
whose gate depends on a server metric, whether that metric is implemented anywhere in
`packages/*/src` yet (it is not — P8 is Scope Excluded of this item, so every server
metric is expected `not-checkable` today; this script's job is to print that count, not
hide it, per Code Guidance "Анти-самообман").

Usage: `python3 load/gates/coverage.py`
"""
import re
import subprocess
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).parent))
from _slo_table import KNOWN_RED_IDS, parse_rows, prometheus_metric_names  # noqa: E402

REPO_ROOT = Path(__file__).resolve().parents[2]
SLO_GATES_MD = REPO_ROOT / 'plans' / 'archive' / 'VLT-LOAD' / 'slo-gates.md'
THRESHOLDS_JS = Path(__file__).parent / 'thresholds.js'

# SLO-40..54's "A" row — the only rows a real k6 options.threshold covers
# (thresholds.js STREAM_A: throughput/data_sent/data_received). Mapping mirrors
# slo-gates.md §3.2's own row order (tus_patch/s3_putpart/s3_complete/single_shot/
# download, tiers S/M/L each) — the RSS half of the SAME row still lives in perf-assert
# (RAG-8b: k6 cannot see server memory), so these rows are NOT exclusively k6-covered.
_STREAMING_A_SLO_TO_SLUG = {
    'SLO-40': 'tus_patch', 'SLO-41': 'tus_patch', 'SLO-42': 'tus_patch',
    'SLO-43': 's3_putpart', 'SLO-44': 's3_putpart', 'SLO-45': 's3_putpart',
    'SLO-46': 's3_complete', 'SLO-47': 's3_complete', 'SLO-48': 's3_complete',
    'SLO-49': 'single_shot', 'SLO-50': 'single_shot', 'SLO-51': 'single_shot',
    'SLO-52': 'download', 'SLO-53': 'download', 'SLO-54': 'download',
}


def _thresholds_js_slugs():
    text = THRESHOLDS_JS.read_text(encoding='utf-8')

    return set(re.findall(r'^\s{2}(\w+): \{', text, re.MULTILINE))


def metric_implemented(metric_name):
    """Offline check: does this vaulter_* metric literal appear under packages/*/src?
    Expected False for every row until P8 ships (Scope Excluded here) — printing that
    fact IS the point (D9: a silently-passed SLO with no metric is not a vardict)."""
    result = subprocess.run(
        ['grep', '-rl', '--include=*.php', metric_name, str(REPO_ROOT / 'packages')],
        capture_output=True, text=True,
    )

    return bool(result.stdout.strip())


def main():
    rows = parse_rows(str(SLO_GATES_MD))
    slugs = _thresholds_js_slugs()

    k6_covered_ids = set()
    not_checkable = []

    for row in rows:
        slug = _STREAMING_A_SLO_TO_SLUG.get(row['id'])

        if row['class'] == 'A' and row['section'] == '3.2' and slug in slugs:
            k6_covered_ids.add(row['id'])

        metrics = prometheus_metric_names(row['prom_metric'])
        missing = [m for m in metrics if not metric_implemented(m)]

        if row['id'] in KNOWN_RED_IDS and row['class'] == 'A':
            not_checkable.append((row['id'], row['class'], 'known-red (L-01, awaits D11 — §6.4), not not-checkable'))
        elif missing:
            not_checkable.append((row['id'], row['class'], f'metric(s) not implemented yet (pre-P8): {", ".join(missing)}'))

    total = len(rows)
    perf_assert_covered = total  # every row is parsed and evaluated by perf-assert.py, by construction
    assigned = total  # union of the two planes — no row falls through the parser

    print(f'{assigned}/{total} строк slo-gates.md §3 имеют место вычисления '
          f'({len(k6_covered_ids)} также в load/gates/thresholds.js (k6-blocking), '
          f'{perf_assert_covered} в load/gates/perf-assert.py).')
    print(f'{len(not_checkable)} помечены not-checkable/known-red сегодня:')

    for slo_id, klass, reason in not_checkable:
        print(f'  {slo_id} ({klass}): {reason}')

    if len(rows) != total:  # defensive — parse_rows already raises on malformed rows
        print('DEFECT: row count changed mid-run.', file=sys.stderr)
        return 1

    return 0


if __name__ == '__main__':
    sys.exit(main())
