#!/usr/bin/env python3
"""P8.8 tracing-target reducer and verifier.

Frozen contract: plans/VLT-LOAD/observability-spec.md §20.2 / §20.6.
Selects at most six allowlisted targets from structured P3–P7 evidence.
Empty selection is a valid D41 no-op.
"""

from __future__ import annotations

import argparse
import hashlib
import json
import subprocess
import sys
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parents[2]
PLAN_DIR = REPO_ROOT / 'plans' / 'VLT-LOAD'
SPEC_PATH = PLAN_DIR / 'observability-spec.md'
DEFAULT_MANIFEST = PLAN_DIR / 'artifacts' / 'P8' / 'tracing-targets.json'
DEFAULT_EVIDENCE = Path(__file__).resolve().parent / 'fixtures' / 'p8-tracing' / 'live-evidence.json'
EVIDENCE_SCHEMA = 'p8-trace-evidence/v1'
MANIFEST_SCHEMA = 'tracing-targets/v1'
READINESS_SCHEMA = 'producer-readiness/v1'
MAX_TARGETS = 6
OWNER_DECISION_EMPTY = 'D41'

REPORTS = {
    'P3': PLAN_DIR / 'db-profile.md',
    'P4': PLAN_DIR / 'memory-profile.md',
    'P5': PLAN_DIR / 'concurrency-report.md',
    'P6': PLAN_DIR / 'queue-report.md',
    'P7': PLAN_DIR / 'chaos-report.md',
}

ALLOWLIST = {
    'tree.read': {
        'producers': frozenset({'P3', 'P5'}),
        'span_chain': 'active HTTP SERVER → vaulter.repository.tree.read',
        'source': ['NodeReadRepository'],
    },
    'tree.move': {
        'producers': frozenset({'P5', 'P7'}),
        'span_chain': 'active HTTP SERVER → vaulter.action.tree.move',
        'source': ['MoveNodeAction'],
    },
    'upload.tus': {
        'producers': frozenset({'P4', 'P5', 'P7'}),
        'span_chain': 'active HTTP SERVER → vaulter.action.upload.tus',
        'source': ['TusUploadHandler'],
    },
    'upload.s3': {
        'producers': frozenset({'P4', 'P5', 'P7'}),
        'span_chain': 'active HTTP SERVER → vaulter.action.upload.s3',
        'source': ['S3MultipartHandler'],
    },
    'download.stream': {
        'producers': frozenset({'P4', 'P7'}),
        'span_chain': 'vaulter.controller.download → existing stream closure',
        'source': ['DownloadController', 'PublicShareController'],
    },
    'cas.commit': {
        'producers': frozenset({'P4', 'P5', 'P7'}),
        'span_chain': 'caller → vaulter.repository.cas.commit → vaulter.action.cas.commit',
        'source': ['BlobStoreRepository', 'BlobStore'],
    },
    'cas.gc': {
        'producers': frozenset({'P5', 'P7'}),
        'span_chain': 'command root → vaulter.action.cas.gc',
        'source': ['BlobGarbageCollector'],
    },
    'upload.sweep': {
        'producers': frozenset({'P5', 'P7'}),
        'span_chain': 'command root → vaulter.action.upload.sweep',
        'source': ['SweepUploadsCommand'],
    },
    'webhook.pipeline': {
        'producers': frozenset({'P3', 'P6', 'P7'}),
        'span_chain': 'command/job root → relay/retry/delivery child',
        'source': ['OutboxRelay', 'WebhookRetryDispatcher', 'DeliverWebhook'],
    },
    'collaboration.session': {
        'producers': frozenset({'P5', 'P7'}),
        'span_chain': 'active HTTP SERVER → vaulter.action.collaboration.session',
        'source': ['CollaborationSessionService'],
    },
    'collaboration.snapshot': {
        'producers': frozenset({'P6', 'P7'}),
        'span_chain': 'job/root → vaulter.action.collaboration.snapshot',
        'source': ['PersistYjsSnapshotAction'],
    },
}

INTEGRITY_RANK = {
    'integrity-safety': 2,
    'availability-lifecycle': 1,
    'other': 0,
}
SEVERITY_RANK = {
    'blocker': 3,
    'major': 2,
    'minor': 1,
    'observation': 0,
}
VALIDITY_REJECT = frozenset({'inferred', 'limitation', 'evidence-invalid'})
ROW_KEYS = {
    'evidence_id', 'target_id', 'producer', 'report', 'raw_ids',
    'validity', 'severity', 'slo_red', 'integrity_class',
    'observed_duration_ns', 'observation_window_ns', 'finding_id',
}
DEFERRED_FAMILIES = {
    'blob_dedup_hit_total': 'P5',
    'webhook_dlq_total': 'P6',
}


class GateError(ValueError):
    def __init__(self, code: str, message: str):
        super().__init__(f'{code}: {message}')
        self.code = code


def sha256_bytes(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def sha256_text(value: str) -> str:
    return sha256_bytes(value.encode('utf-8'))


def canonical_json(payload) -> str:
    return json.dumps(payload, ensure_ascii=False, indent=2, sort_keys=True) + '\n'


def load_json(path: Path):
    return json.loads(path.read_text(encoding='utf-8'))


def dump_json(path: Path, payload) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(canonical_json(payload), encoding='utf-8')


def section_text(path: Path, heading: str) -> str:
    text = path.read_text(encoding='utf-8')
    marker = f'## {heading}'
    start = text.find(marker)
    if start < 0:
        raise GateError('SPEC_SECTION_MISSING', f'{path}: missing {marker!r}')
    rest = text[start + len(marker):]
    next_same = rest.find('\n## ')
    body = rest if next_same < 0 else rest[:next_same]
    return marker + body


def report_digests(reports: dict[str, Path] | None = None) -> dict[str, str]:
    mapping = reports or REPORTS
    return {path.name: sha256_bytes(path.read_bytes()) for path in mapping.values()}


def spec_digests(spec_path: Path = SPEC_PATH) -> dict[str, str]:
    return {
        'observability_spec_sha256': sha256_bytes(spec_path.read_bytes()),
        'section_20_sha256': sha256_text(section_text(spec_path, '20. P8.8–P8.11 tracing and deferred-metric contract (`PLAN1.D41`)')),
    }


def git_head(repo: Path = REPO_ROOT) -> str:
    return subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=repo, text=True).strip()


def candidate_paths_digest(target_ids: list[str]) -> str:
    payload = ''.join(f'trace-target/{item}\n' for item in target_ids)
    return sha256_text(payload)


def resolve_raw_id(raw_id: str, *, repo: Path = REPO_ROOT) -> Path:
    path = Path(raw_id)
    if not path.is_absolute():
        path = repo / path
    if not path.is_file():
        raise GateError('EVIDENCE_UNRESOLVED', f'raw id does not resolve to a file: {raw_id}')
    return path


def _score_tuple(score: dict) -> tuple:
    return (
        score['integrity_rank'],
        score['slo_rank'],
        score['severity_rank'],
        score['impact_ppm'],
    )


def score_row(row: dict) -> dict:
    duration = row['observed_duration_ns']
    window = row['observation_window_ns']
    if duration is None:
        if window is not None:
            raise GateError('EVIDENCE_INVALID', f"{row['evidence_id']}: window without duration")
        impact_ppm = 0
    else:
        if not isinstance(duration, int) or isinstance(duration, bool) or duration < 0:
            raise GateError('EVIDENCE_INVALID', f"{row['evidence_id']}: duration must be integer ns")
        if not isinstance(window, int) or isinstance(window, bool) or window <= 0:
            raise GateError('EVIDENCE_INVALID', f"{row['evidence_id']}: missing/ambiguous window")
        impact_ppm = (1_000_000 * duration) // window
    return {
        'integrity_rank': INTEGRITY_RANK[row['integrity_class']],
        'slo_rank': 1 if row['slo_red'] is True else 0,
        'severity_rank': SEVERITY_RANK[row['severity']],
        'impact_ppm': impact_ppm,
    }


def validate_row(row: dict, *, repo: Path = REPO_ROOT, resolve_raw: bool = True) -> None:
    if not isinstance(row, dict) or set(row) - ROW_KEYS - {'notes'} != set() or not ROW_KEYS <= set(row):
        raise GateError('EVIDENCE_INVALID', f'row keys must be closed: {sorted(ROW_KEYS)}')
    if not isinstance(row['evidence_id'], str) or not row['evidence_id']:
        raise GateError('EVIDENCE_INVALID', 'evidence_id required')
    if row['target_id'] not in ALLOWLIST:
        raise GateError('TARGET_NOT_ALLOWLISTED', row['target_id'])
    if row['producer'] not in {'P3', 'P4', 'P5', 'P6', 'P7'}:
        raise GateError('EVIDENCE_INVALID', f"{row['evidence_id']}: unknown producer")
    if row['producer'] not in ALLOWLIST[row['target_id']]['producers']:
        raise GateError('EVIDENCE_INVALID', f"{row['evidence_id']}: producer {row['producer']} not allowed for {row['target_id']}")
    if row['validity'] not in {'valid', 'inferred', 'limitation', 'evidence-invalid'}:
        raise GateError('EVIDENCE_INVALID', f"{row['evidence_id']}: unknown validity")
    if row['severity'] not in SEVERITY_RANK:
        raise GateError('EVIDENCE_INVALID', f"{row['evidence_id']}: severity is not a closed rank")
    if row['integrity_class'] not in INTEGRITY_RANK:
        raise GateError('EVIDENCE_INVALID', f"{row['evidence_id']}: integrity_class is not closed")
    if not isinstance(row['slo_red'], bool):
        raise GateError('EVIDENCE_INVALID', f"{row['evidence_id']}: slo_red must be boolean")
    if not isinstance(row['raw_ids'], list) or not row['raw_ids'] or len(row['raw_ids']) != len(set(row['raw_ids'])):
        raise GateError('EVIDENCE_INVALID', f"{row['evidence_id']}: exact unique raw_ids required")
    if not isinstance(row['finding_id'], str) or not row['finding_id']:
        raise GateError('EVIDENCE_INVALID', f"{row['evidence_id']}: finding_id required")
    if not isinstance(row['report'], str) or not row['report']:
        raise GateError('EVIDENCE_INVALID', f"{row['evidence_id']}: report required")
    if resolve_raw:
        for raw_id in row['raw_ids']:
            resolve_raw_id(raw_id, repo=repo)
        report_path = Path(row['report'])
        if not report_path.is_absolute():
            report_path = repo / report_path
        if not report_path.is_file():
            raise GateError('EVIDENCE_UNRESOLVED', f"report does not resolve: {row['report']}")


def eligible_rows(rows: list[dict], *, repo: Path = REPO_ROOT, resolve_raw: bool = True) -> list[dict]:
    seen = set()
    eligible = []
    for row in rows:
        validate_row(row, repo=repo, resolve_raw=resolve_raw)
        if row['evidence_id'] in seen:
            raise GateError('EVIDENCE_INVALID', f"duplicate evidence_id {row['evidence_id']}")
        seen.add(row['evidence_id'])
        if row['validity'] in VALIDITY_REJECT:
            continue
        if row['validity'] != 'valid':
            raise GateError('EVIDENCE_INVALID', f"{row['evidence_id']}: unknown validity")
        score = score_row(row)
        eligible.append({'row': row, 'score': score})
    return eligible


def authoritative_by_target(eligible: list[dict]) -> dict[str, dict]:
    winners: dict[str, dict] = {}
    for item in eligible:
        target_id = item['row']['target_id']
        key = (_score_tuple(item['score']), item['row']['evidence_id'])
        current = winners.get(target_id)
        if current is None:
            winners[target_id] = item
            continue
        current_key = (_score_tuple(current['score']), current['row']['evidence_id'])
        # max score, then lexicographically smaller evidence_id
        if key[0] > current_key[0] or (key[0] == current_key[0] and key[1] < current_key[1]):
            winners[target_id] = item
    return winners


def rank_targets(winners: dict[str, dict]) -> list[dict]:
    ranked = sorted(
        winners.values(),
        key=lambda item: (
            -item['score']['integrity_rank'],
            -item['score']['slo_rank'],
            -item['score']['severity_rank'],
            -item['score']['impact_ppm'],
            item['row']['target_id'],
        ),
    )
    return ranked[:MAX_TARGETS]


def exclusion_reason(target_id: str, rows: list[dict]) -> str:
    related = [row for row in rows if row['target_id'] == target_id]
    if not related:
        return 'no valid observed hotspot/debt evidence'
    order = {'evidence-invalid': 0, 'inferred': 1, 'limitation': 2, 'valid': 3}
    related.sort(key=lambda row: (order.get(row['validity'], 9), row['evidence_id']))
    row = related[0]
    if row['validity'] == 'valid':
        return 'ranked below the first six selected targets'
    return f"{row['validity']}: {row.get('notes') or row['finding_id']}"


def validate_deferred(bundle: dict, rows: list[dict]) -> dict[str, bool]:
    deferred = bundle.get('deferred_metrics')
    if not isinstance(deferred, dict) or set(deferred) != set(DEFERRED_FAMILIES):
        raise GateError('DEFERRED_INVALID', 'deferred_metrics must contain exactly the two frozen families')
    flags = {}
    by_id = {row['evidence_id']: row for row in rows}
    for family, required_producer in DEFERRED_FAMILIES.items():
        record = deferred[family]
        required = {'enabled', 'producer', 'evidence_ids', 'reason'}
        if not isinstance(record, dict) or set(record) != required:
            raise GateError('DEFERRED_INVALID', f'{family}: closed keys required')
        if record['producer'] != required_producer:
            raise GateError('DEFERRED_INVALID', f'{family}: producer must be {required_producer}')
        if not isinstance(record['enabled'], bool):
            raise GateError('DEFERRED_INVALID', f'{family}: enabled must be boolean')
        if not isinstance(record['reason'], str) or not record['reason']:
            raise GateError('DEFERRED_INVALID', f'{family}: reason required')
        ids = record['evidence_ids']
        if not isinstance(ids, list) or not ids or len(ids) != len(set(ids)):
            raise GateError('DEFERRED_INVALID', f'{family}: unique evidence_ids required')
        cited = []
        for evidence_id in ids:
            if evidence_id not in by_id:
                raise GateError('EVIDENCE_UNRESOLVED', f'{family}: {evidence_id}')
            cited.append(by_id[evidence_id])
        if record['enabled'] is True:
            if any(row['validity'] != 'valid' or row['producer'] != required_producer for row in cited):
                raise GateError(
                    'DEFERRED_INVALID',
                    f'{family}: enabled requires valid {required_producer} evidence',
                )
        flags[family] = record['enabled']
    return flags


def build_readiness(
    *,
    selected_ids: list[str],
    excluded_ids: list[str],
    source_commit: str,
    manifest_rel: str,
) -> dict:
    if selected_ids:
        record = {
            'schema_version': READINESS_SCHEMA,
            'producer_item': 'P8.8',
            'consumer_item': 'P8.9',
            'mode': 'nonempty-candidates',
            'roots': [manifest_rel],
            'command': f'python3 load/gates/p8_trace_targets.py verify --manifest {manifest_rel}',
            'candidate_count': len(selected_ids),
            'candidate_paths_sha256': candidate_paths_digest(selected_ids),
            'source_commit': source_commit,
            'predicate': 'observability-spec.md §20.2 closed score tuple; allowlisted observed hotspot/debt only',
            'exclusions': excluded_ids,
        }
    else:
        record = {
            'schema_version': READINESS_SCHEMA,
            'producer_item': 'P8.8',
            'consumer_item': 'P8.9',
            'mode': 'empty-by-decision',
            'roots': [manifest_rel],
            'command': f'python3 load/gates/p8_trace_targets.py verify --manifest {manifest_rel}',
            'candidate_count': 0,
            'candidate_paths_sha256': candidate_paths_digest([]),
            'source_commit': source_commit,
            'predicate': 'observability-spec.md §20.2 closed score tuple; D41 empty no-op',
            'exclusions': excluded_ids,
            'owner_decision': OWNER_DECISION_EMPTY,
        }
    return record


def select_targets(
    bundle: dict,
    *,
    repo: Path = REPO_ROOT,
    resolve_raw: bool = True,
    source_commit: str | None = None,
    manifest_rel: str = 'plans/VLT-LOAD/artifacts/P8/tracing-targets.json',
    spec_path: Path = SPEC_PATH,
    reports: dict[str, Path] | None = None,
) -> dict:
    if bundle.get('schema_version') != EVIDENCE_SCHEMA:
        raise GateError('EVIDENCE_INVALID', f'schema_version must be {EVIDENCE_SCHEMA}')
    rows = bundle.get('rows')
    if not isinstance(rows, list):
        raise GateError('EVIDENCE_INVALID', 'rows must be a list')
    eligible = eligible_rows(rows, repo=repo, resolve_raw=resolve_raw)
    winners = authoritative_by_target(eligible)
    ranked = rank_targets(winners)
    selected_ids = [item['row']['target_id'] for item in ranked]
    if len(selected_ids) != len(set(selected_ids)):
        raise GateError('TARGET_NOT_UNIQUE', 'selected target set is not unique')
    flags = validate_deferred(bundle, rows)
    excluded_ids = [target_id for target_id in ALLOWLIST if target_id not in selected_ids]
    selected = []
    for item in ranked:
        target_id = item['row']['target_id']
        allow = ALLOWLIST[target_id]
        selected.append({
            'target_id': target_id,
            'score': item['score'],
            'authoritative_evidence_id': item['row']['evidence_id'],
            'finding_id': item['row']['finding_id'],
            'producer': item['row']['producer'],
            'span_chain': allow['span_chain'],
            'source': list(allow['source']),
        })
    excluded = [
        {
            'target_id': target_id,
            'reason': exclusion_reason(target_id, rows),
        }
        for target_id in excluded_ids
    ]
    commit = source_commit or git_head(repo)
    mode = 'nonempty-candidates' if selected_ids else 'no-op'
    readiness = build_readiness(
        selected_ids=selected_ids,
        excluded_ids=excluded_ids,
        source_commit=commit,
        manifest_rel=manifest_rel,
    )
    spec = spec_digests(spec_path)
    return {
        'schema_version': MANIFEST_SCHEMA,
        'mode': mode,
        'max_targets': MAX_TARGETS,
        'observability_spec_sha256': spec['observability_spec_sha256'],
        'section_20_sha256': spec['section_20_sha256'],
        'report_digests': report_digests(reports),
        'evidence_schema': EVIDENCE_SCHEMA,
        'selected': selected,
        'excluded': excluded,
        'deferred_metrics': {
            'blob_dedup_hit_total': flags['blob_dedup_hit_total'],
            'webhook_dlq_total': flags['webhook_dlq_total'],
        },
        'producer_readiness': readiness,
    }


def verify_manifest(
    manifest: dict,
    evidence: dict,
    *,
    repo: Path = REPO_ROOT,
    resolve_raw: bool = True,
    spec_path: Path = SPEC_PATH,
    reports: dict[str, Path] | None = None,
) -> dict:
    expected = select_targets(
        evidence,
        repo=repo,
        resolve_raw=resolve_raw,
        source_commit=manifest.get('producer_readiness', {}).get('source_commit'),
        spec_path=spec_path,
        reports=reports,
    )
    if canonical_json(manifest) != canonical_json(expected):
        raise GateError('MANIFEST_DRIFT', 'manifest is not the byte-stable reducer output')
    selected_ids = [row['target_id'] for row in manifest['selected']]
    if len(selected_ids) > MAX_TARGETS:
        raise GateError('TARGET_LIMIT', f'{len(selected_ids)} > {MAX_TARGETS}')
    if len(selected_ids) != len(set(selected_ids)):
        raise GateError('TARGET_NOT_UNIQUE', 'duplicate selected target')
    unknown = [target_id for target_id in selected_ids if target_id not in ALLOWLIST]
    if unknown:
        raise GateError('TARGET_NOT_ALLOWLISTED', ','.join(unknown))
    spec = spec_digests(spec_path)
    if manifest['observability_spec_sha256'] != spec['observability_spec_sha256']:
        raise GateError('SPEC_DRIFT', 'observability-spec.md digest mismatch')
    if manifest['section_20_sha256'] != spec['section_20_sha256']:
        raise GateError('SPEC_DRIFT', '§20 digest mismatch')
    if manifest['report_digests'] != report_digests(reports):
        raise GateError('REPORT_DRIFT', 'P3–P7 report digest mismatch')
    return {
        'status': 'green',
        'mode': manifest['mode'],
        'selected': selected_ids,
        'manifest_sha256': sha256_text(canonical_json(manifest)),
        'readiness_digest': sha256_text(canonical_json(manifest['producer_readiness'])),
    }


def default_reports_for(repo: Path) -> dict[str, Path]:
    return {key: repo / path.relative_to(REPO_ROOT) for key, path in REPORTS.items()}


def main(argv=None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    sub = parser.add_subparsers(dest='command', required=True)
    select_cmd = sub.add_parser('select')
    select_cmd.add_argument('--evidence', type=Path, default=DEFAULT_EVIDENCE)
    select_cmd.add_argument('--out', type=Path, default=DEFAULT_MANIFEST)
    select_cmd.add_argument('--source-commit')
    verify = sub.add_parser('verify')
    verify.add_argument('--manifest', type=Path, default=DEFAULT_MANIFEST)
    verify.add_argument('--evidence', type=Path, default=DEFAULT_EVIDENCE)
    args = parser.parse_args(argv)
    try:
        if args.command == 'select':
            bundle = load_json(args.evidence)
            manifest = select_targets(bundle, source_commit=args.source_commit)
            dump_json(args.out, manifest)
            print(canonical_json({
                'status': 'green',
                'mode': manifest['mode'],
                'selected': [row['target_id'] for row in manifest['selected']],
                'out': str(args.out),
            }), end='')
            return 0
        manifest = load_json(args.manifest)
        evidence = load_json(args.evidence)
        result = verify_manifest(manifest, evidence)
        print(canonical_json(result), end='')
        return 0
    except GateError as exc:
        print(canonical_json({'status': 'evidence-invalid', 'error': str(exc)}), end='', file=sys.stderr)
        return 2


if __name__ == '__main__':
    sys.exit(main())
