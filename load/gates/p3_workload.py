#!/usr/bin/env python3
"""P3.2 — workload evidence builder, publisher, and artifact validator."""

from __future__ import annotations

import argparse
import hashlib
import json
import re
import sys
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parents[2]
CATALOG = REPO_ROOT / 'plans' / 'VLT-LOAD' / 'operations-catalog.md'
P8_ARTIFACTS = REPO_ROOT / 'plans' / 'VLT-LOAD' / 'artifacts' / 'P8.7'
P3_ARTIFACTS = REPO_ROOT / 'plans' / 'VLT-LOAD' / 'artifacts' / 'P3'

MANDATORY_HOTSPOT_VIEWS = (
    'v_nodes',
    'v_blobs',
    'v_upload_sessions',
    'v_upload_parts',
    'v_file_versions',
    'v_activity',
    'v_outbox',
    'v_webhook_deliveries',
)

HTTP_OP_TO_METRIC = {
    'OP-01': {'tree_list', 'upload_session'},
    'OP-02': {'tus_patch'},
    'OP-03': {'upload_session'},
    'OP-04': {'upload_session'},
    'OP-05': {'upload_session'},
    'OP-06': {'s3_putpart'},
    'OP-07': {'s3_complete'},
    'OP-08': {'download'},
    'OP-09': {'download'},
    'OP-10': {'download'},
    'OP-11': {'point_read'},
    'OP-12': {'tree_list'},
    'OP-13': {'tree_list'},
    'OP-14': {'search'},
    'OP-15': {'tree_list'},
    'OP-16': {'tree_mutate'},
    'OP-17': {'single_shot'},
    'OP-18': {'point_read'},
    'OP-19': {'tree_mutate'},
    'OP-20': {'subtree_mutate'},
    'OP-21': {'tree_mutate'},
    'OP-22': {'tree_mutate'},
    'OP-23': {'tree_mutate'},
    'OP-24': {'tree_mutate'},
    'OP-25': {'activity_feed'},
    'OP-26': {'activity_feed'},
    'OP-27': {'doc_write'},
    'OP-28': {'doc_read'},
    'OP-29': {'doc_write'},
    'OP-30': {'doc_read'},
    'OP-31': {'doc_write'},
    'OP-32': {'doc_write'},
    'OP-33': {'doc_export'},
    'OP-34': {'collab_issue'},
    'OP-35': {'doc_write'},
    'OP-36': {'doc_write'},
    'OP-37': {'comment'},
    'OP-38': {'comment'},
}

ARTISAN_OP = {
    'OP-40': 'vaulter:relay-outbox',
    'OP-42': 'vaulter:retry-webhooks',
    'OP-43': 'vaulter:prune-webhooks',
    'OP-44': 'vaulter:sweep-uploads',
    'OP-45': 'vaulter:purge-trash',
    'OP-46': 'vaulter:gc-blobs',
    'OP-47': 'vaulter:reconcile-blobs',
}

UUID_RE = re.compile(
    r'\b[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\b',
    re.I,
)
SECRET_RE = re.compile(r'(vaulter-load-secret|POSTGRES_PASSWORD|password\s*[:=])', re.I)
OP_ROW_RE = re.compile(
    r'^\|\s*(OP-\d+)\s*\|\s*([^|]+)\|\s*([^|]+)\|\s*`([^`]+)`',
    re.M,
)


class WorkloadError(ValueError):
    def __init__(self, code: str, message: str):
        super().__init__(f'{code}: {message}')
        self.code = code


def load_json(path: Path) -> dict | list:
    return json.loads(path.read_text(encoding='utf-8'))


def dump_json(path: Path, payload) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(payload, indent=2, sort_keys=True) + '\n', encoding='utf-8')


def scan_secrets(blob: str) -> list[str]:
    hits = [match.group(0) for match in UUID_RE.finditer(blob)]
    hits.extend(match.group(0) for match in SECRET_RE.finditer(blob))
    return hits


def catalog_ops() -> dict[str, dict[str, str]]:
    text = CATALOG.read_text(encoding='utf-8')
    ops: dict[str, dict[str, str]] = {}
    for match in OP_ROW_RE.finditer(text):
        op_id, title, _route, anchor = match.groups()
        ops[op_id] = {
            'title': title.strip(),
            'code_anchor': anchor.strip(),
        }
    if len(ops) != 47:
        raise WorkloadError('CATALOG_OPS', f'expected 47 OP rows, found {len(ops)}')
    return ops


def _json_lines(text: str) -> list[dict]:
    objects: list[dict] = []
    for line in text.splitlines():
        line = line.strip()
        if line.startswith('{') and line.endswith('}'):
            objects.append(json.loads(line))
        elif line.startswith('[{'):
            # Some psql formatters wrap arrays; keep as pseudo-row container.
            objects.append({'__array__': json.loads(line)})
    return objects


def parse_reset_output(text: str) -> dict[str, int | str]:
    objects = _json_lines(text)
    before_obj = next((obj for obj in objects if 'dealloc_before' in obj), None)
    after_obj = next((obj for obj in objects if 'dealloc_after' in obj), None)
    if before_obj is None or after_obj is None:
        raise WorkloadError('RESET_PARSE', 'reset output missing dealloc snapshots')
    before = int(before_obj['dealloc_before'])
    after = int(after_obj['dealloc_after'])
    return {
        'dealloc_before': before,
        'dealloc_after': after,
        'dealloc_delta': after - before,
        'stats_reset': after_obj.get('stats_reset'),
    }


def parse_pgss_export(text: str) -> tuple[list[dict], int]:
    rows: list[dict] | None = None
    dealloc_after = None
    for line in text.splitlines():
        stripped = line.strip()
        if stripped.startswith('[{'):
            rows = json.loads(stripped)
        elif stripped.isdigit():
            dealloc_after = int(stripped)
    if rows is None:
        raise WorkloadError('PGSS_EXPORT', 'pgss export missing JSON array')
    if dealloc_after is None:
        raise WorkloadError('PGSS_EXPORT', 'pgss export missing dealloc line')
    return rows, dealloc_after


def build_pgss_top20(
    *,
    run_id: str,
    dataset_id: str,
    ranked_rows: list[dict],
    mandatory_hotspots: list[dict],
) -> dict:
    seen: set[str] = set()
    top: list[dict] = []
    for row in ranked_rows:
        fp = str(row['query_fingerprint'])
        if fp in seen:
            continue
        seen.add(fp)
        top.append(row)
        if len(top) == 20:
            break
    if len(top) != 20:
        raise WorkloadError('PGSS_TOP20', f'need 20 unique fingerprints, got {len(top)}')
    return {
        'schema_version': 'p3-pgss-top20/v1',
        'run_id': run_id,
        'dataset_id': dataset_id,
        'fingerprints': top,
        'mandatory_hotspots': mandatory_hotspots,
    }


def pick_mandatory_hotspots(ranked_rows: list[dict]) -> list[dict]:
    """Attach highest-ranked fingerprint rows whose SQL mentions mandatory view names."""
    picked: list[dict] = []
    seen_views: set[str] = set()
    for view in MANDATORY_HOTSPOT_VIEWS:
        for row in ranked_rows:
            # fingerprint rows do not carry query text — caller may pass enriched rows.
            hint = str(row.get('view_hint', ''))
            if view in hint and view not in seen_views:
                picked.append({'view': view, 'query_fingerprint': row['query_fingerprint']})
                seen_views.add(view)
                break
    return picked


def load_trace_rows(path: Path) -> list[dict]:
    if not path.is_file():
        return []
    rows = []
    for line in path.read_text(encoding='utf-8').splitlines():
        if line.strip():
            rows.append(json.loads(line))
    return rows


def metric_ops_from_trace(rows: list[dict]) -> dict[str, set[str]]:
    seen: dict[str, set[str]] = {}
    for row in rows:
        command_id = str(row.get('command_id', ''))
        if not command_id.startswith('http:'):
            continue
        metric = command_id.removeprefix('http:')
        for op_id, metrics in HTTP_OP_TO_METRIC.items():
            if metric in metrics:
                seen.setdefault(op_id, set()).add(str(row.get('sql_template_hash', '')))
    for row in rows:
        command_id = str(row.get('command_id', ''))
        if not command_id.startswith('artisan:'):
            continue
        cmd = command_id.removeprefix('artisan:')
        for op_id, artisan_cmd in ARTISAN_OP.items():
            if cmd == artisan_cmd:
                seen.setdefault(op_id, set()).add(str(row.get('sql_template_hash', '')))
    return seen


def build_operation_coverage(
    *,
    run_id: str,
    dataset_id: str,
    trace_rows: list[dict],
    workload_executed: bool,
) -> dict:
    catalog = catalog_ops()
    trace_map = metric_ops_from_trace(trace_rows)
    operations = []
    for op_id in sorted(catalog, key=lambda value: int(value.split('-')[1])):
        anchor = catalog[op_id]['code_anchor']
        hashes = sorted(trace_map.get(op_id, set()))
        if op_id == 'OP-39':
            verdict = 'trace-only' if hashes else 'no-db'
            reason = 'embedded outbox write path; observed only via correlated SQL templates'
        elif op_id in ARTISAN_OP:
            if hashes:
                verdict = 'trace-only'
                reason = f"artisan {ARTISAN_OP[op_id]} executed during capture window"
            else:
                verdict = 'no-db'
                reason = f"background command {ARTISAN_OP[op_id]} not triggered in this run"
        elif op_id == 'OP-41':
            verdict = 'trace-only' if hashes else 'no-db'
            reason = 'queued DeliverWebhook job; SQL visible only when queue worker runs'
        elif op_id in HTTP_OP_TO_METRIC:
            if hashes:
                verdict = 'trace-only'
                reason = 'HTTP workload produced privacy-safe SQL templates for this OP'
            elif workload_executed:
                verdict = 'db-explain-covered'
                reason = 'tier-S mixed workload exercises this OP; P3.3 owns actual EXPLAIN'
            else:
                verdict = 'excluded-with-reason'
                reason = 'workload window did not execute'
        else:
            verdict = 'excluded-with-reason'
            reason = 'catalog entry outside automated capture mapping'
        entry = {
            'op_id': op_id,
            'verdict': verdict,
            'code_anchor': anchor,
            'sql_template_hashes': hashes,
        }
        if verdict in {'no-db', 'excluded-with-reason'}:
            entry['reason'] = reason
        operations.append(entry)
    if len(operations) != 47:
        raise WorkloadError('COVERAGE_COUNT', f'expected 47 operations, got {len(operations)}')
    return {
        'schema_version': 'p3-operation-coverage/v1',
        'run_id': run_id,
        'dataset_id': dataset_id,
        'operations': operations,
    }


def verify_p8_preflight() -> None:
    gate_py = REPO_ROOT / 'load' / 'gates' / 'p8_live_gate.py'
    if not gate_py.is_file():
        raise WorkloadError('P87_MISSING', 'p8_live_gate.py missing')
    import subprocess

    proc = subprocess.run(
        [sys.executable, str(gate_py), 'verify', '--artifacts', str(P8_ARTIFACTS)],
        capture_output=True,
        text=True,
        check=False,
    )
    if proc.returncode != 0:
        raise WorkloadError('P87_PREFLIGHT', proc.stderr.strip() or proc.stdout.strip() or 'P8.7 verify failed')


def redact_run_id_blob(path: Path) -> str:
    if path.suffix == '.jsonl':
        rows = []
        for line in path.read_text(encoding='utf-8').splitlines():
            if not line.strip():
                continue
            row = json.loads(line)
            if isinstance(row, dict):
                row = dict(row)
                row.pop('run_id', None)
            rows.append(row)
        return json.dumps(rows)
    payload = json.loads(path.read_text(encoding='utf-8'))
    if isinstance(payload, dict):
        payload = dict(payload)
        payload.pop('run_id', None)
        if 'p87_preflight_run_id' in payload:
            payload['p87_preflight_run_id'] = '<redacted>'
    return json.dumps(payload)


def verify_artifacts(artifact_dir: Path) -> None:
    names = [
        'run-manifest.json',
        'dataset-profile.json',
        'pgss-top20.json',
        'pg-stat-io.json',
        'query-trace.jsonl',
        'operation-coverage.json',
    ]
    for name in names:
        path = artifact_dir / name
        if not path.is_file():
            raise WorkloadError('ARTIFACT_MISSING', name)
    manifest = load_json(artifact_dir / 'run-manifest.json')
    run_id = str(manifest.get('run_id', ''))
    dataset_id = str(manifest.get('dataset_id', ''))
    for name in names[1:]:
        blob = artifact_dir / name
        if name.endswith('.jsonl'):
            payload = redact_run_id_blob(blob)
        else:
            payload_obj = load_json(blob)
            if isinstance(payload_obj, dict):
                if str(payload_obj.get('run_id', run_id)) != run_id:
                    raise WorkloadError('RUN_ID_MISMATCH', name)
                if str(payload_obj.get('dataset_id', dataset_id)) != dataset_id:
                    raise WorkloadError('DATASET_MISMATCH', name)
            payload = redact_run_id_blob(blob)
        hits = scan_secrets(payload)
        if hits:
            raise WorkloadError('SECRET_LEAK', f'{name}: {hits[:3]}')
    trace_rows = load_trace_rows(artifact_dir / 'query-trace.jsonl')
    if not trace_rows:
        raise WorkloadError('TRACE_EMPTY', 'query-trace.jsonl has no rows')
    if any(row.get('run_id') != run_id or not row.get('invocation_id') for row in trace_rows):
        raise WorkloadError('TRACE_IDENTITY', 'trace rows must match run and invocation')
    if not any(str(row.get('command_id', '')).startswith('http:') for row in trace_rows):
        raise WorkloadError('TRACE_HTTP_EMPTY', 'measured HTTP workload produced no SQL trace rows')
    info = manifest.get('pg_stat_statements_info') or {}
    if int(info.get('dealloc_delta', -1)) != 0:
        raise WorkloadError('DEALLOC_DELTA', 'dealloc_delta must be 0 for valid P3.2 run')
    top20 = load_json(artifact_dir / 'pgss-top20.json')
    fps = top20.get('fingerprints') or []
    uniq = {row['query_fingerprint'] for row in fps}
    if len(fps) != 20 or len(uniq) != 20:
        raise WorkloadError('TOP20', 'pgss-top20 must contain 20 unique fingerprints')
    coverage = load_json(artifact_dir / 'operation-coverage.json')
    ops = coverage.get('operations') or []
    op_ids = [row['op_id'] for row in ops]
    if len(op_ids) != len(set(op_ids)) or len(op_ids) != 47:
        raise WorkloadError('COVERAGE', 'operation-coverage must list 47 unique OP ids')


def build_dataset_profile(*, run_id: str, dataset_id: str, profile_blob: dict) -> dict:
    return {
        'schema_version': 'p3-dataset-profile/v1',
        'run_id': run_id,
        'dataset_id': dataset_id,
        **profile_blob,
    }


def build_pg_stat_io(*, run_id: str, dataset_id: str, before: list, after: list) -> dict:
    return {
        'schema_version': 'p3-pg-stat-io/v1',
        'run_id': run_id,
        'dataset_id': dataset_id,
        'before': before,
        'after': after,
        'attribution': 'cluster_context_only',
    }


def build_workload_manifest(
    *,
    run_id: str,
    tier: str,
    dataset_id: str,
    env_fingerprint: str,
    git_sha: str,
    cache_declaration: str,
    pgss_info: dict,
    extensions: dict,
    p87_run_id: str | None,
    workload: dict,
) -> dict:
    return {
        'schema_version': 'p3-profile/v1',
        'run_id': run_id,
        'tier': tier,
        'dataset_id': dataset_id,
        'env_fingerprint': env_fingerprint,
        'git_commit_sha': git_sha,
        'disposable_db': True,
        'cache_declaration': cache_declaration,
        'p87_preflight_run_id': p87_run_id,
        'workload': workload,
        'pg_stat_statements': {'track': 'all', 'max': 10000},
        'pg_stat_statements_info': pgss_info,
        'extensions': extensions,
        'track_io_timing': {'enabled': False, 'pg_test_timing_us': None},
        'terminal': {'marker': 'p3-run-complete', 'verified_at': None},
    }


def load_json_array_file(path: Path) -> list:
    text = path.read_text(encoding='utf-8')
    for line in text.splitlines():
        stripped = line.strip()
        if stripped.startswith('['):
            return json.loads(stripped)
    raise WorkloadError('JSON_ARRAY', f'{path.name} missing JSON array line')


def load_json_object_line(path: Path) -> dict:
    text = path.read_text(encoding='utf-8')
    for line in text.splitlines():
        stripped = line.strip()
        if stripped.startswith('{') and stripped.endswith('}'):
            return json.loads(stripped)
    raise WorkloadError('JSON_OBJECT', f'{path.name} missing JSON object line')


def finalize_run_dir(run_dir: Path, *, tier: str, env_fingerprint: str, git_sha: str, p87_run_id: str | None) -> None:
    manifest_path = run_dir / 'run-manifest.json'
    skeleton = load_json(manifest_path)
    run_id = str(skeleton['run_id'])
    dataset_id = str(skeleton['dataset_id'])
    reset_info = parse_reset_output((run_dir / 'pgss-reset.txt').read_text(encoding='utf-8'))
    ranked_rows, _dealloc_export = parse_pgss_export((run_dir / 'pgss-export.txt').read_text(encoding='utf-8'))
    hotspot_rows = load_json_array_file(run_dir / 'hotspot-fingerprints.json')
    mandatory = [
        {
            'view': row['view_name'],
            'query_fingerprint': row.get('query_fingerprint'),
        }
        for row in hotspot_rows
        if row.get('query_fingerprint')
    ]
    profile_blob = load_json_object_line(run_dir / 'dataset-profile.raw.json')
    io_before = load_json_array_file(run_dir / 'pg-stat-io-before.json')
    io_after = load_json_array_file(run_dir / 'pg-stat-io-after.json')
    trace_rows = load_trace_rows(run_dir / 'query-trace.jsonl')
    workload_executed = (run_dir / 'workload.ok').is_file()
    dump_json(run_dir / 'dataset-profile.json', build_dataset_profile(run_id=run_id, dataset_id=dataset_id, profile_blob=profile_blob))
    dump_json(
        run_dir / 'pgss-top20.json',
        build_pgss_top20(
            run_id=run_id,
            dataset_id=dataset_id,
            ranked_rows=ranked_rows,
            mandatory_hotspots=mandatory,
        ),
    )
    dump_json(
        run_dir / 'pg-stat-io.json',
        build_pg_stat_io(run_id=run_id, dataset_id=dataset_id, before=io_before, after=io_after),
    )
    dump_json(
        run_dir / 'operation-coverage.json',
        build_operation_coverage(
            run_id=run_id,
            dataset_id=dataset_id,
            trace_rows=trace_rows,
            workload_executed=workload_executed,
        ),
    )
    extensions = {'pg_stat_statements': '1.10', 'hypopg': '1.4.1'}
    workload = json.loads((run_dir / 'workload.meta.json').read_text(encoding='utf-8'))
    manifest = build_workload_manifest(
        run_id=run_id,
        tier=tier,
        dataset_id=dataset_id,
        env_fingerprint=env_fingerprint,
        git_sha=git_sha,
        cache_declaration=str(workload.get('cache_declaration', 'shared_buffers_warm_page_cache_warm')),
        pgss_info=reset_info,
        extensions=extensions,
        p87_run_id=p87_run_id,
        workload=workload,
    )
    dump_json(manifest_path, manifest)


def publish_run_dir(run_dir: Path, artifact_dir: Path) -> None:
    artifact_dir.mkdir(parents=True, exist_ok=True)
    mapping = {
        'run-manifest.json': 'run-manifest.json',
        'dataset-profile.json': 'dataset-profile.json',
        'pgss-top20.json': 'pgss-top20.json',
        'pg-stat-io.json': 'pg-stat-io.json',
        'query-trace.jsonl': 'query-trace.jsonl',
        'operation-coverage.json': 'operation-coverage.json',
    }
    for src_name, dst_name in mapping.items():
        src = run_dir / src_name
        if not src.is_file():
            raise WorkloadError('PUBLISH_MISSING', src_name)
        dst = artifact_dir / dst_name
        dst.write_bytes(src.read_bytes())


def cmd_build(args: argparse.Namespace) -> int:
    finalize_run_dir(
        Path(args.run_dir),
        tier=args.tier,
        env_fingerprint=args.env_fingerprint,
        git_sha=args.git_sha,
        p87_run_id=args.p87_run_id or None,
    )
    print('build: ok')
    return 0


def cmd_publish(args: argparse.Namespace) -> int:
    publish_run_dir(Path(args.run_dir), Path(args.artifact_dir))
    print('publish: ok')
    return 0


def cmd_verify(args: argparse.Namespace) -> int:
    verify_artifacts(Path(args.artifact_dir))
    print('verify-artifacts: ok')
    return 0


def cmd_preflight(args: argparse.Namespace) -> int:
    verify_p8_preflight()
    print('preflight: ok')
    return 0


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description='P3.2 workload artifacts')
    sub = parser.add_subparsers(dest='command', required=True)
    build = sub.add_parser('build', help='finalize JSON artifacts in a run directory')
    build.add_argument('--run-dir', required=True)
    build.add_argument('--tier', required=True)
    build.add_argument('--env-fingerprint', required=True)
    build.add_argument('--git-sha', required=True)
    build.add_argument('--p87-run-id', default='')
    build.set_defaults(func=cmd_build)
    publish = sub.add_parser('publish', help='copy validated run_dir into plans/VLT-LOAD/artifacts/P3')
    publish.add_argument('--run-dir', required=True)
    publish.add_argument('--artifact-dir', default=str(P3_ARTIFACTS))
    publish.set_defaults(func=cmd_publish)
    preflight = sub.add_parser('preflight', help='verify P8.7 artifacts before P3 workload')
    preflight.set_defaults(func=cmd_preflight)
    verify = sub.add_parser('verify-artifacts', help='validate published P3.2 artifacts')
    verify.add_argument('--artifact-dir', default=str(P3_ARTIFACTS))
    verify.set_defaults(func=cmd_verify)
    args = parser.parse_args(argv)
    try:
        return args.func(args)
    except WorkloadError as exc:
        print(str(exc), file=sys.stderr)
        return 1


if __name__ == '__main__':
    raise SystemExit(main())
