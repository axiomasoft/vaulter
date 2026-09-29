#!/usr/bin/env python3
"""Recompute P3 plan coverage and repeated SQL per correlated invocation."""

from __future__ import annotations

import argparse
import json
from collections import Counter
from pathlib import Path

from p3_explain import ExplainError, verify_plan_file
from p3_profile import ProfileError, verify_trace


class TraceReportError(ValueError):
    pass


def build_report(artifact_dir: Path) -> dict:
    manifest = json.loads((artifact_dir / 'run-manifest.json').read_text(encoding='utf-8'))
    top20 = json.loads((artifact_dir / 'pgss-top20.json').read_text(encoding='utf-8'))
    run_id = str(manifest['run_id'])
    rows = [json.loads(line) for line in (artifact_dir / 'query-trace.jsonl').read_text(encoding='utf-8').splitlines() if line.strip()]
    if not rows:
        raise TraceReportError('TRACE_EMPTY: no N+1 verdict from zero rows')
    try:
        verify_trace(artifact_dir / 'query-trace.jsonl')
    except ProfileError as exc:
        raise TraceReportError(str(exc)) from exc
    if top20.get('run_id') != run_id or top20.get('dataset_id') != manifest['dataset_id']:
        raise TraceReportError('TOP20_IDENTITY: run/dataset mismatched')
    if any(row.get('run_id') != run_id or not row.get('invocation_id') for row in rows):
        raise TraceReportError('TRACE_IDENTITY: run_id/invocation_id missing or mismatched')
    if not any(str(row.get('command_id', '')).startswith('http:') for row in rows):
        raise TraceReportError('TRACE_HTTP_EMPTY: no SQL rows from measured HTTP workload')

    fingerprints = [str(row['query_fingerprint']) for row in top20['fingerprints']]
    if len(fingerprints) != 20 or len(set(fingerprints)) != 20:
        raise TraceReportError('TOP20: expected 20 distinct fingerprints')
    statuses = {}
    for fp in fingerprints:
        path = artifact_dir / 'explain' / f'{fp}.json'
        if path.is_file():
            try:
                verify_plan_file(path, manifest)
            except ExplainError as exc:
                raise TraceReportError(str(exc)) from exc
            plan = json.loads(path.read_text(encoding='utf-8'))
            if plan.get('run_id') != run_id or str(plan.get('query_fingerprint')) != fp:
                raise TraceReportError(f'PLAN_IDENTITY: {fp}')
            statuses[fp] = plan.get('status')
        else:
            statuses[fp] = 'missing'

    multiplicity = Counter(
        (row['command_id'], row['invocation_id'], row['sql_template_hash']) for row in rows
    )
    candidates = [
        {'command_id': command, 'invocation_id': invocation, 'sql_template_hash': sql_hash, 'count': count}
        for (command, invocation, sql_hash), count in multiplicity.items() if count > 1
    ]
    candidates.sort(key=lambda row: (-row['count'], row['command_id'], row['invocation_id']))
    return {
        'schema_version': 'p3-trace-analysis/v1',
        'run_id': run_id,
        'dataset_id': manifest['dataset_id'],
        'trace_rows': len(rows),
        'invocations': len({row['invocation_id'] for row in rows}),
        'top20_coverage': {
            'attempted': sum(status != 'missing' for status in statuses.values()),
            'analyzed': sum(status == 'ok' for status in statuses.values()),
            'statuses': statuses,
        },
        'n_plus_one': {
            'status': 'candidate-review-required' if candidates else 'no-repeated-template-observed',
            'candidates': candidates,
            'note': 'Repeated hash within one invocation is a candidate, not an automatic product verdict.',
        },
    }


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--artifact-dir', type=Path, required=True)
    parser.add_argument('--output', type=Path, required=True)
    args = parser.parse_args()
    report = build_report(args.artifact_dir)
    args.output.write_text(json.dumps(report, indent=2, sort_keys=True) + '\n', encoding='utf-8')
    print(f"trace-analysis: {report['trace_rows']} rows; top20 analyzed={report['top20_coverage']['analyzed']}/20")
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
