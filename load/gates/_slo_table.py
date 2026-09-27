"""VLT-LOAD P2.5 — shared parser for `slo-gates.md` §3, used by both perf-assert.py and
coverage.py so the 96-row gate table is read from ONE place (the plan document itself is
the SSOT — plan.md/harness.md discipline against duplicating numbers into a second file
that can silently drift). Not in the item's original **Files** list — added the same way
`load/scripts/run.sh` was added in P2.4 (mechanically required, documented as a
deviation, not a scope expansion).

Row schema per slo-gates.md §3 (8 pipe-delimited columns, verified 96/96 rows conform,
`awk -F'|' '{print NF}' | sort | uniq -c` -> all 10 fields incl. leading/trailing empties):
SLO | Класс | k6-метрика+тег | Выражение порога | abortOnFail/delayAbortEval |
Baseline-политика | Prometheus-метрика | Гейт
"""
import re

SECTIONS = {
    '### 3.1': '3.1',
    '### 3.2': '3.2',
    '### 3.3': '3.3',
}

# §6.4: SLO-46..48's "A" row bundles a k6-visible throughput floor (thresholds.js,
# already blocking) with a perf-assert-only RSS ceiling that is KNOWN to be red today
# (L-01 — S3MultipartHandler::complete() holds the whole file in RAM). The RSS half must
# not silently pass, and must not count as a plain "fail" either (that would make the
# gate un-mergeable for a finding this plan explicitly defers to a dev-slice, D11) — a
# third verdict, `known-red`, is what §6.4 asks for ("вердикт known-red, а не || true").
KNOWN_RED_IDS = {'SLO-46', 'SLO-47', 'SLO-48'}
EXPECTED_ROW_COUNT = 96
EXPECTED_CLASS_A_ROWS = 42

# §7: profile `lab` blocks BOTH classes (D20's second escape hatch). A row's own Гейт
# column reflects behaviour on `ubuntu-latest` (smoke-perf/regression) — a plain
# "advisory" class-B row (tier S/M, i.e. one a lab run would actually produce) flips to
# blocking in `lab`. Rows already qualified "(лаб.)" are tier L — never actually run
# (D25: "L — экстраполяция"), so they stay advisory even under profile=lab; rows
# qualified "(§6.3)" are a deliberate permanent carve-out (maintenance ops) and never flip.
def _lab_flip_eligible(row):
    return row['class'] == 'B' and row['gate_raw'].strip() == 'advisory'


def blocking_profiles(row):
    """Return (set-of-profiles-where-this-row-blocks, is_known_red)."""
    text = row['gate_raw'].strip().strip('*').strip()

    if row['id'] in KNOWN_RED_IDS and row['class'] == 'A':
        return {'smoke-perf', 'regression', 'lab'}, True

    if text.startswith('advisory'):
        return ({'lab'} if _lab_flip_eligible(row) else set()), False

    if text == 'блокирующий':
        return {'smoke-perf', 'regression', 'lab'}, False

    if text == 'блокирующий (regression)':
        return {'regression', 'lab'}, False

    if text == 'блокирующий (лаб.)':
        return {'lab'}, False

    # Unrecognised gate text is a defect in this parser (or a new value added to
    # slo-gates.md that the parser was never updated for) — surfaced, not swallowed.
    return set(), False


# §3.3's table literally writes "те же" ("same as above") in the Prometheus-метрика
# column for rows that repeat the previous row's metric set (SLO-58/59/60 all share the
# outbox-relay trio, etc.) — a markdown-table shorthand, not a missing value.
_SAME_AS_ABOVE = 'те же'


def parse_rows(md_path):
    rows = []
    section = None
    prev_prom_metric = None

    with open(md_path, encoding='utf-8') as f:
        for line in f:
            stripped = line.rstrip('\n')

            for marker, name in SECTIONS.items():
                if stripped.startswith(marker):
                    section = name
                    prev_prom_metric = None

            if stripped.startswith('## 4.'):
                section = None

            if section and stripped.startswith('| SLO-'):
                cells = [c.strip() for c in stripped.strip().strip('|').split('|')]

                if len(cells) != 8:
                    raise ValueError(f'unexpected column count in row: {stripped!r}')

                slo_id, klass, k6_metric, expr, abort, baseline_policy, prom_metric, gate = cells

                if prom_metric == _SAME_AS_ABOVE:
                    if prev_prom_metric is None:
                        raise ValueError(f'{slo_id}: "те же" with no preceding row to inherit from')

                    prom_metric = prev_prom_metric
                else:
                    prev_prom_metric = prom_metric

                rows.append({
                    'id': slo_id,
                    'section': section,
                    'class': klass,
                    'k6_metric': k6_metric,
                    'expr': expr,
                    'abort': abort,
                    'baseline_policy': baseline_policy,
                    'prom_metric': prom_metric,
                    'gate_raw': gate,
                })

    validate_rows(rows)

    return rows


def validate_rows(rows):
    """Fail closed when the Markdown SSOT drifts from the P8.7 reader contract."""
    if len(rows) != EXPECTED_ROW_COUNT:
        raise ValueError(f'expected {EXPECTED_ROW_COUNT} SLO rows, found {len(rows)}')

    ids_and_classes = [(row['id'], row['class']) for row in rows]
    if len(ids_and_classes) != len(set(ids_and_classes)):
        raise ValueError('duplicate SLO id/class row in slo-gates.md section 3')

    class_a = [row for row in rows if row['class'] == 'A']
    if len(class_a) != EXPECTED_CLASS_A_ROWS:
        raise ValueError(
            f'expected {EXPECTED_CLASS_A_ROWS} Class-A rows, found {len(class_a)}'
        )


def prometheus_metric_names(prom_metric_cell):
    """Extract bare `vaulter_*` (or built-in) metric identifiers referenced by a
    Prometheus-metrика cell — used to probe whether the metric exists at all yet."""
    return sorted(set(re.findall(r'\bvaulter_[a-z0-9_]+', prom_metric_cell)))
