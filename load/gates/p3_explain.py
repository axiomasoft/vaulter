#!/usr/bin/env python3
"""P3.3 — actual EXPLAIN capture, index inventory/review, and HypoPG candidates."""

from __future__ import annotations

import argparse
import hashlib
import json
import re
import shlex
import subprocess
import sys
import uuid
from dataclasses import dataclass
from pathlib import Path
from typing import Any

REPO_ROOT = Path(__file__).resolve().parents[2]
P3_ARTIFACTS = REPO_ROOT / 'plans' / 'VLT-LOAD' / 'artifacts' / 'P3'
SEED_GLOB = REPO_ROOT / 'load' / 'seed' / 'out'

HOT_FAMILIES = (
    'v_nodes',
    'v_blobs',
    'v_upload_sessions',
    'v_upload_parts',
    'v_file_versions',
    'v_activity',
    'v_outbox',
    'v_webhook_deliveries',
)

CODE_ANCHOR_QUERIES: tuple[dict[str, Any], ...] = (
    {
        'anchor_id': 'NodeReadRepository::candidateNodeIds',
        'code_anchor': 'packages/core/src/Repositories/Drive/NodeReadRepository.php:491',
        'op_ids': ['OP-12', 'OP-13', 'OP-14', 'OP-15'],
        'parameter_class': 'many_grant_median',
        'family': 'v_nodes',
    },
    {
        'anchor_id': 'OutboxRelay::subscribedEndpoints',
        'code_anchor': 'packages/core/src/Webhooks/OutboxRelay.php:113',
        'op_ids': ['OP-40'],
        'parameter_class': 'outbox_relay',
        'family': 'v_outbox',
    },
)

HYPOPG_CANDIDATE_SPECS: tuple[dict[str, Any], ...] = (
    {
        'candidate_id': 'nodes_parent_storage_btree',
        'families': ['v_nodes'],
        'covered_op_ids': ['OP-12', 'OP-13', 'OP-14', 'OP-15', 'OP-20'],
        'ddl': (
            'SELECT hypopg_create_index('
            "'CREATE INDEX ON public.v_nodes (storage_id, parent_id) "
            "WHERE deleted_at IS NULL AND trashed_at IS NULL'"
            ')'
        ),
        'probe_sql': (
            'EXPLAIN (FORMAT JSON, SETTINGS) '
            'SELECT id FROM v_nodes WHERE storage_id = $1 AND parent_id = $2 '
            'AND deleted_at IS NULL AND trashed_at IS NULL LIMIT 100'
        ),
        'write_hot_penalty': 'medium',
    },
    {
        'candidate_id': 'activity_storage_created_btree',
        'families': ['v_activity'],
        'covered_op_ids': ['OP-25', 'OP-26'],
        'ddl': (
            'SELECT hypopg_create_index('
            "'CREATE INDEX ON public.v_activity (storage_id, created_at DESC)'"
            ')'
        ),
        'probe_sql': (
            'EXPLAIN (FORMAT JSON, SETTINGS) '
            'SELECT id FROM v_activity WHERE storage_id = $1 '
            'ORDER BY created_at DESC LIMIT 50'
        ),
        'write_hot_penalty': 'high',
    },
    {
        'candidate_id': 'webhook_endpoints_active_btree',
        'families': ['v_outbox'],
        'covered_op_ids': ['OP-40'],
        'ddl': (
            'SELECT hypopg_create_index('
            "'CREATE INDEX ON public.v_webhook_endpoints (is_active) WHERE is_active = true'"
            ')'
        ),
        'probe_sql': (
            'EXPLAIN (FORMAT JSON, SETTINGS) '
            'SELECT id FROM v_webhook_endpoints WHERE is_active = true'
        ),
        'write_hot_penalty': 'low',
    },
)

UUID_RE = re.compile(
    r'\b[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\b',
    re.I,
)
SECRET_RE = re.compile(r'(vaulter-load-secret|POSTGRES_PASSWORD|password\s*[:=])', re.I)
DML_RE = re.compile(r'\b(INSERT|UPDATE|DELETE)\b', re.I)
PLACEHOLDER_RE = re.compile(r'\$(\d+)')

PLAN_KEYS = {
    'schema_version',
    'run_id',
    'dataset_id',
    'query_fingerprint',
    'disposable_db',
    'parameter_class',
    'op_ids',
    'code_anchors',
    'settings_fingerprint',
    'command',
    'execute_args',
    'status',
    'rejection_reason',
    'plan',
    'summary',
}


class ExplainError(ValueError):
    def __init__(self, code: str, message: str):
        super().__init__(f'{code}: {message}')
        self.code = code


def load_json(path: Path) -> Any:
    return json.loads(path.read_text(encoding='utf-8'))


def dump_json(path: Path, payload: Any) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(payload, indent=2, sort_keys=True) + '\n', encoding='utf-8')


def redact_uuid_literals(blob: str) -> str:
    return UUID_RE.sub('<uuid>', blob)


def scan_secrets(blob: str) -> list[str]:
    hits = [match.group(0) for match in UUID_RE.finditer(blob)]
    hits.extend(match.group(0) for match in SECRET_RE.finditer(blob))
    return hits


def latest_seed_manifest() -> dict:
    manifests = sorted(SEED_GLOB.glob('vlt-load-*.json'), key=lambda p: p.stat().st_mtime, reverse=True)
    if not manifests:
        raise ExplainError('SEED_MANIFEST', 'no load/seed/out/vlt-load-*.json')
    return load_json(manifests[0])


def max_placeholder_count(query: str) -> int:
    nums = [int(match.group(1)) for match in PLACEHOLDER_RE.finditer(query)]
    return max(nums) if nums else 0


def fetch_binding_samples(runner: PsqlRunner, seed: dict) -> dict[str, Any]:
    actors = seed.get('actors') or {}
    ids = seed.get('ids') or {}
    anchors = ids.get('many_grant_anchor_node_ids') or [1]
    storage_id = int(seed.get('storage_id', 1))
    sql = f"""
SELECT row_to_json(s) FROM (
  SELECT
    {anchors[0]}::bigint AS grant_parent_id,
    50::int AS max_depth,
    {storage_id}::bigint AS storage_id,
    {int(actors.get('many_grant', 900001))}::bigint AS actor_id,
    {int(actors.get('seed_owner', 1))}::bigint AS seed_owner_id,
    'load-actor'::text AS actor_type,
    'vaulter-node'::text AS target_type,
    'viewed'::text AS verb,
    '{{}}'::jsonb AS json_context,
    NOW() AS ts,
    (SELECT parent_id FROM v_nodes WHERE parent_id IS NOT NULL LIMIT 1) AS parent_id,
    (SELECT id FROM v_nodes LIMIT 1) AS node_id,
    (SELECT upload_id::text FROM v_upload_sessions LIMIT 1) AS upload_uuid,
    (SELECT id FROM v_upload_sessions LIMIT 1) AS upload_session_id,
    (SELECT id FROM v_outbox LIMIT 1) AS outbox_id,
    (SELECT id FROM v_blobs LIMIT 1) AS blob_id,
    (SELECT id FROM v_files LIMIT 1) AS file_id,
    (SELECT COALESCE(MAX(version_no), 0) + 1 FROM v_file_versions
      WHERE file_id = (SELECT id FROM v_files LIMIT 1)) AS next_version_no
) s;
"""
    out = runner.run_sql(sql)
    for line in out.splitlines():
        stripped = line.strip()
        if stripped.startswith('{'):
            row = json.loads(stripped)
            row['insert_upload_uuid'] = str(uuid.uuid4())
            return row
    raise ExplainError('BINDING_SAMPLES', 'could not load binding samples')


def expr_for_column(column: str, samples: dict[str, Any]) -> str:
    col = column.lower().strip('"')
    mapping = {
        'parent_id': f"{samples['grant_parent_id']}::bigint",
        'storage_id': f"{samples['storage_id']}::bigint",
        'actor_id': f"{samples['actor_id']}::bigint",
        'target_id': f"{samples['node_id']}::bigint",
        'actor_type': f"'{samples['actor_type']}'::text",
        'target_type': f"'{samples['target_type']}'::text",
        'verb': f"'{samples['verb']}'::text",
        'context': "'{}'::jsonb",
        'created_at': 'NOW()',
        'committed_at': 'NOW()',
        'received_at': 'NOW()',
        'updated_at': 'NOW()',
        'upload_id': f"'{samples['upload_uuid']}'::uuid",
        'id': f"{samples['node_id']}::bigint",
        'tenant_id': f"{samples['storage_id']}::bigint",
        'file_id': f"{samples['file_id']}::bigint",
        'blob_id': f"{samples['blob_id']}::bigint",
        'version_no': f"{samples['next_version_no']}::int",
        'subject_type': "'user'::text",
        'subject_id': f"{samples['actor_id']}::bigint",
        'expires_at': 'NOW()::timestamp',
        'ip': "'127.0.0.1'::inet",
        'user_agent': "'p3-probe'::text",
        'request_id': 'NULL::uuid',
        'metadata': "'{}'::json",
        'protocol': "'tus'::text",
        'status': "'open'::text",
        'target_name': "'p3-probe.bin'::text",
        'original_name': "'p3-probe.bin'::text",
        'mime_type': "'application/octet-stream'::text",
        'content_sha256': "'" + '0' * 64 + "'::char(64)",
        'expected_sha256': 'NULL::char(64)',
        'size_bytes': '1024::bigint',
        'total_size_bytes': '1024::bigint',
        'received_bytes': '0::bigint',
        'chunk_size': '1024::bigint',
    }
    if col in mapping:
        return mapping[col]
    if col.endswith('_id'):
        return f"{samples['node_id']}::bigint"
    if 'uuid' in col:
        return f"'{samples['upload_uuid']}'::uuid"
    if col.endswith('_at'):
        return 'NOW()'
    return f"{samples['storage_id']}::bigint"


def infer_execute_exprs(query: str, samples: dict[str, Any]) -> list[str]:
    count = max_placeholder_count(query)
    if count == 0:
        return []
    exprs = ['NULL::bigint'] * count
    for index in range(1, count + 1):
        for match in re.finditer(rf'"([^"]+)"\s*(?:<=|>=|=|<|>)\s*\${index}\b', query):
            exprs[index - 1] = expr_for_column(match.group(1), samples)
        if f'depth < ${index}' in query or f'"depth" < ${index}' in query:
            exprs[index - 1] = f"{samples['max_depth']}::int"
    insert = re.search(r'insert into\s+"?(\w+)"?\s*\(([^)]+)\)\s*values\s*', query, re.I)
    if insert:
        columns = [part.strip().strip('"') for part in insert.group(2).split(',')]
        values = re.split(r'\bon conflict\b|\breturning\b', query[insert.end():], maxsplit=1, flags=re.I)[0]
        tuples = re.findall(r'\((\s*\$\d+(?:\s*,\s*\$\d+)*\s*)\)', values)
        for item in tuples:
            placeholders = [int(number) for number in re.findall(r'\$(\d+)', item)]
            if len(placeholders) != len(columns):
                continue
            for index, column in zip(placeholders, columns):
                exprs[index - 1] = expr_for_column(column, samples)
                if insert.group(1).lower() == 'v_upload_sessions' and column == 'upload_id':
                    exprs[index - 1] = f"'{samples['insert_upload_uuid']}'::uuid"
                if insert.group(1).lower() == 'v_file_versions' and column == 'status':
                    exprs[index - 1] = "'pending'::text"
    if 'grant_parent_id' in samples and exprs[0] == 'NULL::bigint':
        exprs[0] = f"{samples['grant_parent_id']}::bigint"
    if count >= 2 and exprs[1] == 'NULL::bigint' and 'recursive' in query.lower():
        exprs[1] = f"{samples['max_depth']}::int"
    return exprs


def bind_placeholders(query: str, values: list[Any]) -> str:
    """Legacy helper for HypoPG probes with explicit SQL (no $1 placeholders)."""
    max_n = max_placeholder_count(query)
    if max_n == 0:
        return query
    padded = list(values)
    while len(padded) < max_n:
        padded.append(padded[-1] if padded else 1)

    def repl(match: re.Match[str]) -> str:
        idx = int(match.group(1)) - 1
        value = padded[idx]
        if isinstance(value, str):
            return "'" + value.replace("'", "''") + "'"
        return str(value)

    return PLACEHOLDER_RE.sub(repl, query)


def settings_fingerprint(dataset_profile: dict) -> str:
    settings = dataset_profile.get('settings') or []
    blob = json.dumps(settings, sort_keys=True)
    return hashlib.sha256(blob.encode('utf-8')).hexdigest()[:16]


def collect_targets(artifact_dir: Path) -> list[dict[str, Any]]:
    top20 = load_json(artifact_dir / 'pgss-top20.json')
    manifest = load_json(artifact_dir / 'run-manifest.json')
    run_id = str(manifest['run_id'])
    dataset_id = str(manifest['dataset_id'])
    targets: dict[str, dict[str, Any]] = {}
    for row in top20.get('fingerprints') or []:
        fp = str(row['query_fingerprint'])
        targets[fp] = {
            'query_fingerprint': fp,
            'parameter_class': 'top20',
            'op_ids': [],
            'code_anchors': [],
            'source': 'pgss-top20',
            'run_id': run_id,
            'dataset_id': dataset_id,
        }
    for row in top20.get('mandatory_hotspots') or []:
        fp = str(row.get('query_fingerprint') or '')
        if not fp:
            continue
        entry = targets.setdefault(
            fp,
            {
                'query_fingerprint': fp,
                'parameter_class': 'mandatory_hotspot',
                'op_ids': [],
                'code_anchors': [],
                'source': 'mandatory-hotspot',
                'run_id': run_id,
                'dataset_id': dataset_id,
            },
        )
        entry['parameter_class'] = 'mandatory_hotspot'
        entry['view'] = row.get('view')
    for anchor in CODE_ANCHOR_QUERIES:
        entry = {
            'query_fingerprint': f"code-anchor:{anchor['anchor_id']}",
            'parameter_class': anchor['parameter_class'],
            'op_ids': list(anchor['op_ids']),
            'code_anchors': [anchor['code_anchor']],
            'source': 'code-anchor',
            'run_id': run_id,
            'dataset_id': dataset_id,
            'family': anchor['family'],
            'anchor_id': anchor['anchor_id'],
        }
        targets[entry['query_fingerprint']] = entry
    return list(targets.values())


def summarize_plan(plan_root: list[dict] | dict) -> dict[str, Any]:
    if isinstance(plan_root, dict):
        plan_root = [plan_root]
    if not plan_root:
        return {}
    plan = plan_root[0].get('Plan') or plan_root[0]
    seq_scans: list[dict[str, Any]] = []
    temp_bytes = 0
    loops_max = 1
    est_rows = plan.get('Plan Rows')
    act_rows = plan.get('Actual Rows')

    def walk(node: dict, depth: int = 0) -> None:
        nonlocal temp_bytes, loops_max
        node_type = node.get('Node Type', '')
        loops = int(node.get('Actual Loops') or node.get('Plan Rows') or 1)
        loops_max = max(loops_max, loops)
        if node_type == 'Seq Scan':
            rel = node.get('Relation Name') or node.get('Alias') or ''
            seq_scans.append(
                {
                    'relation': rel,
                    'actual_rows': node.get('Actual Rows'),
                    'plan_rows': node.get('Plan Rows'),
                    'filter': node.get('Filter'),
                    'loops': node.get('Actual Loops'),
                }
            )
        for key in ('Plans', 'Inner Plans', 'Outer Plans'):
            for child in node.get(key) or []:
                walk(child, depth + 1)
        temp_bytes += int(node.get('Temp Written Blocks') or 0)

    walk(plan)
    cardinality_ratio = None
    if est_rows and act_rows:
        try:
            cardinality_ratio = float(act_rows) / max(float(est_rows), 1.0)
        except (TypeError, ValueError):
            cardinality_ratio = None
    return {
        'root_node_type': plan.get('Node Type'),
        'seq_scans': seq_scans,
        'seq_scan_count': len(seq_scans),
        'max_loops': loops_max,
        'plan_rows': est_rows,
        'actual_rows': act_rows,
        'cardinality_ratio': cardinality_ratio,
        'temp_written_blocks': temp_bytes,
        'planning_time_ms': plan_root[0].get('Planning Time'),
        'execution_time_ms': plan_root[0].get('Execution Time'),
    }


def seq_scan_verdict(summary: dict[str, Any], table_bytes: int | None) -> str:
    scans = summary.get('seq_scans') or []
    if not scans:
        return 'no-seq-scan'
    ratio = summary.get('cardinality_ratio')
    if table_bytes and table_bytes > 50_000_000 and (ratio is None or ratio > 0.25):
        return 'unjustified-candidate'
    if summary.get('max_loops', 1) > 5 and len(scans) > 0:
        return 'unjustified-loops'
    if ratio is not None and ratio < 0.1 and (scans[0].get('actual_rows') or 0) < 5000:
        return 'justified-selective'
    return 'review-needed'


@dataclass
class PsqlRunner:
    compose_cmd: list[str]

    def run_sql(self, sql: str) -> str:
        proc = subprocess.run(
            self.compose_cmd
            + [
                'exec',
                '-T',
                'postgres',
                'env',
                'PGPASSWORD=secret',
                'psql',
                '-U',
                'vaulter',
                '-d',
                'vaulter_load',
                '-v',
                'ON_ERROR_STOP=1',
                '-qAt',
                '-f',
                '-',
            ],
            input=sql,
            capture_output=True,
            text=True,
            check=False,
        )
        if proc.returncode != 0:
            raise ExplainError('PSQL', proc.stderr.strip() or proc.stdout.strip())
        return proc.stdout

    def run_file(self, path: Path) -> str:
        proc = subprocess.run(
            self.compose_cmd
            + [
                'exec',
                '-T',
                'postgres',
                'env',
                'PGPASSWORD=secret',
                'psql',
                '-U',
                'vaulter',
                '-d',
                'vaulter_load',
                '-v',
                'ON_ERROR_STOP=1',
                '-qAt',
                '-f',
                '-',
            ],
            input=path.read_text(encoding='utf-8'),
            capture_output=True,
            text=True,
            check=False,
        )
        if proc.returncode != 0:
            raise ExplainError('PSQL_FILE', proc.stderr.strip() or proc.stdout.strip())
        return proc.stdout

    def explain_analyze(
        self,
        query: str,
        *,
        is_dml: bool,
        execute_exprs: list[str] | None = None,
        analyze: bool = True,
    ) -> tuple[list | dict | None, str | None]:
        stmt = query
        if stmt.upper().startswith('EXPLAIN'):
            stmt = re.sub(r'^EXPLAIN\s*(\([^)]*\))?\s*', '', stmt, count=1, flags=re.I)
        plan_name = 'p3_explain_plan'
        prefix = "SET statement_timeout = '60s'; SET lock_timeout = '5s'; "
        if analyze:
            explain = 'EXPLAIN (ANALYZE, BUFFERS, VERBOSE, SETTINGS, WAL, FORMAT JSON) '
        else:
            explain = 'EXPLAIN (FORMAT JSON, SETTINGS) '
        if execute_exprs:
            args = ', '.join(execute_exprs)
            core = f"PREPARE {plan_name} AS {stmt}; {explain}EXECUTE {plan_name}({args}); DEALLOCATE {plan_name};"
        else:
            core = f"{explain}{stmt};"
        if is_dml:
            body = f"{prefix}BEGIN; {core} ROLLBACK;"
        else:
            body = f"{prefix}{core}"
        try:
            out = self.run_sql(body)
        except ExplainError as exc:
            return None, str(exc)
        start = out.find('[')
        if start == -1:
            return None, 'missing JSON plan'
        try:
            payload, _end = json.JSONDecoder().raw_decode(out[start:])
            return payload, None
        except json.JSONDecodeError as exc:
            return None, f'json parse: {exc}'


def resolve_query_text(target: dict[str, Any], query_export: dict[str, str], seed: dict) -> str | None:
    fp = target['query_fingerprint']
    if fp.startswith('code-anchor:'):
        anchor_id = target.get('anchor_id', '')
        if anchor_id == 'NodeReadRepository::candidateNodeIds':
            anchor = (seed.get('ids') or {}).get('many_grant_anchor_node_ids', [12])[0]
            return (
                'WITH RECURSIVE laravel_cte AS ('
                f'(SELECT v_nodes.*, 1 AS depth, ARRAY[id] AS path FROM v_nodes '
                f'WHERE v_nodes.parent_id = {anchor} AND v_nodes.parent_id IS NOT NULL) '
                'UNION ALL '
                '(SELECT v_nodes.*, depth + 1, path || v_nodes.id FROM v_nodes '
                'INNER JOIN laravel_cte ON laravel_cte.id = v_nodes.parent_id '
                "WHERE NOT (v_nodes.id = ANY(path)) AND depth < 50) "
                'SELECT id FROM laravel_cte WHERE laravel_cte.deleted_at IS NULL'
            )
        if anchor_id == 'OutboxRelay::subscribedEndpoints':
            return 'SELECT id, events, is_active FROM v_webhook_endpoints WHERE is_active = true'
        return None
    return query_export.get(fp)


def export_query_map(runner: PsqlRunner, sql_path: Path) -> dict[str, str]:
    out = runner.run_file(sql_path)
    for line in out.splitlines():
        stripped = line.strip()
        if stripped.startswith('[{'):
            rows = json.loads(stripped)
            return {str(row['query_fingerprint']): row['query'] for row in rows}
    raise ExplainError('QUERY_EXPORT', 'pgss export missing JSON array')


def capture_plans(
    *,
    artifact_dir: Path,
    explain_dir: Path,
    runner: PsqlRunner,
    sql_dir: Path,
) -> None:
    explain_dir.mkdir(parents=True, exist_ok=True)
    dataset_profile = load_json(artifact_dir / 'dataset-profile.json')
    settings_fp = settings_fingerprint(dataset_profile)
    seed = latest_seed_manifest()
    samples = fetch_binding_samples(runner, seed)
    query_export = export_query_map(runner, sql_dir / 'pgss-query-export.sql')
    table_bytes = {
        row['relation']: int(row.get('total_bytes') or 0)
        for row in dataset_profile.get('tables') or []
    }
    for target in collect_targets(artifact_dir):
        fp = target['query_fingerprint']
        safe_name = re.sub(r'[^a-zA-Z0-9._-]+', '_', fp)[:120]
        out_path = explain_dir / f'{safe_name}.json'
        raw_query = resolve_query_text(target, query_export, seed)
        payload: dict[str, Any] = {
            'schema_version': 'p3-explain-plan/v1',
            'run_id': target['run_id'],
            'dataset_id': target['dataset_id'],
            'query_fingerprint': fp,
            'disposable_db': True,
            'parameter_class': target['parameter_class'],
            'op_ids': target.get('op_ids') or [],
            'code_anchors': target.get('code_anchors') or [],
            'settings_fingerprint': settings_fp,
            'command': raw_query or '',
            'status': 'pending',
            'rejection_reason': None,
            'plan': None,
            'summary': {},
        }
        if not raw_query:
            payload['status'] = 'rejected'
            payload['rejection_reason'] = 'query text missing from pg_stat_statements export'
            dump_json(out_path, payload)
            continue
        execute_exprs = infer_execute_exprs(raw_query, samples)
        payload['command'] = raw_query
        payload['execute_args'] = execute_exprs
        is_dml = bool(DML_RE.search(raw_query))
        plan, err = runner.explain_analyze(
            raw_query,
            is_dml=is_dml,
            execute_exprs=execute_exprs if execute_exprs else None,
        )
        if err:
            payload['status'] = 'rejected'
            payload['rejection_reason'] = err
        else:
            payload['status'] = 'ok'
            payload['plan'] = json.loads(redact_uuid_literals(json.dumps(plan)))
            summary = summarize_plan(payload['plan'] if isinstance(payload['plan'], list) else [])
            rel = (summary.get('seq_scans') or [{}])[0].get('relation') if summary.get('seq_scans') else None
            bytes_for_rel = table_bytes.get(rel or '', 0)
            summary['seq_scan_verdict'] = seq_scan_verdict(summary, bytes_for_rel)
            payload['summary'] = summary
        dump_json(out_path, payload)


def build_index_inventory(artifact_dir: Path, runner: PsqlRunner, sql_dir: Path) -> dict:
    manifest = load_json(artifact_dir / 'run-manifest.json')
    out = runner.run_file(sql_dir / 'index-inventory.sql')
    rows: list[dict] = []
    for line in out.splitlines():
        stripped = line.strip()
        if stripped.startswith('[{'):
            rows = json.loads(stripped)
            break
    families: dict[str, list[dict]] = {name: [] for name in HOT_FAMILIES}
    for row in rows:
        family = row.get('family')
        if family in families:
            families[family].append(row)
    missing = [name for name, items in families.items() if not items]
    if missing:
        raise ExplainError('INDEX_INVENTORY', f'missing families: {missing}')
    return {
        'schema_version': 'p3-index-inventory/v1',
        'run_id': manifest['run_id'],
        'dataset_id': manifest['dataset_id'],
        'families': [
            {'family': family, 'indexes': families[family]}
            for family in HOT_FAMILIES
        ],
    }


def build_index_review(artifact_dir: Path, explain_dir: Path, inventory: dict) -> dict:
    manifest = load_json(artifact_dir / 'run-manifest.json')
    plans = []
    for path in sorted(explain_dir.glob('*.json')):
        row = load_json(path)
        if row.get('status') == 'ok':
            plans.append(row)
    by_family: dict[str, list[dict]] = {name: [] for name in HOT_FAMILIES}
    for plan in plans:
        for scan in plan.get('summary', {}).get('seq_scans') or []:
            rel = scan.get('relation')
            if rel in by_family:
                by_family[rel].append({'plan': plan['query_fingerprint'], 'scan': scan})
    family_reviews = []
    for block in inventory['families']:
        family = block['family']
        indexes = block['indexes']
        unused = [idx['index_name'] for idx in indexes if int(idx.get('idx_scan') or 0) == 0 and not idx.get('is_primary')]
        family_reviews.append(
            {
                'family': family,
                'index_count': len(indexes),
                'total_index_bytes': sum(int(idx.get('index_bytes') or 0) for idx in indexes),
                'unused_non_primary_indexes': unused,
                'seq_scan_observations': by_family.get(family, []),
            }
        )
    return {
        'schema_version': 'p3-index-review/v1',
        'run_id': manifest['run_id'],
        'dataset_id': manifest['dataset_id'],
        'families': family_reviews,
        'redundant_index_hypotheses': [
            {
                'family': 'v_activity',
                'indexes': ['v_activity_storage_id_index', 'v_activity_storage_idx'],
                'hypothesis': 'duplicate storage_id access paths; debt-only, no drop in P3',
            }
        ],
    }


def extract_plan_cost(plan_json: list | dict | None) -> float | None:
    if not plan_json:
        return None
    root = plan_json[0] if isinstance(plan_json, list) else plan_json
    plan = root.get('Plan') or root
    return plan.get('Total Cost')


def plain_explain(runner: PsqlRunner, sql: str) -> list | dict | None:
    stmt = sql
    if stmt.upper().startswith('EXPLAIN'):
        stmt = re.sub(r'^EXPLAIN\s*(\([^)]*\))?\s*', '', stmt, count=1, flags=re.I)
    out = runner.run_sql(f"SET statement_timeout = '30s'; EXPLAIN (FORMAT JSON, SETTINGS) {stmt};")
    start = out.find('[')
    if start == -1:
        return None
    payload, _end = json.JSONDecoder().raw_decode(out[start:])
    return payload


def run_hypopg(runner: PsqlRunner, artifact_dir: Path, seed: dict) -> dict:
    manifest = load_json(artifact_dir / 'run-manifest.json')
    session_id = hashlib.sha256(f"{manifest['run_id']}:hypopg".encode()).hexdigest()[:16]
    runner.run_sql('SELECT hypopg_reset();')
    candidates: list[dict] = []
    for spec in HYPOPG_CANDIDATE_SPECS:
        samples = fetch_binding_samples(runner, seed)
        probe = spec['probe_sql']
        execute_exprs = infer_execute_exprs(probe, samples) or [
            f"{samples['storage_id']}::bigint",
            f"{samples['parent_id']}::bigint",
        ]
        baseline_plan = runner.explain_analyze(
            probe,
            is_dml=False,
            execute_exprs=execute_exprs,
            analyze=False,
        )[0]
        runner.run_sql(spec['ddl'])
        candidate_plan = runner.explain_analyze(
            probe,
            is_dml=False,
            execute_exprs=execute_exprs,
            analyze=False,
        )[0]
        runner.run_sql('SELECT hypopg_reset();')
        base_cost = extract_plan_cost(baseline_plan)
        cand_cost = extract_plan_cost(candidate_plan)
        if base_cost is None or cand_cost is None:
            verdict = 'planner-rejected'
        elif cand_cost < base_cost * 0.95:
            verdict = 'planner-supported'
        else:
            verdict = 'planner-rejected'
        if 'Index Scan' in json.dumps(baseline_plan) and 'Index Scan' in json.dumps(candidate_plan):
            verdict = 'superseded-by-existing'
        candidates.append(
            {
                'candidate_id': spec['candidate_id'],
                'session_id': session_id,
                'covered_op_ids': spec['covered_op_ids'],
                'families': spec['families'],
                'ddl': spec['ddl'],
                'probe_sql': probe,
                'baseline_plan': baseline_plan,
                'candidate_plan': candidate_plan,
                'baseline_total_cost': base_cost,
                'candidate_total_cost': cand_cost,
                'verdict': verdict,
                'maintenance_risk': {'write_hot_penalty': spec['write_hot_penalty']},
                'hypopg_reset': True,
            }
        )
    return {
        'schema_version': 'p3-hypopg-candidates/v1',
        'run_id': manifest['run_id'],
        'dataset_id': manifest['dataset_id'],
        'session_id': session_id,
        'candidates': candidates,
    }


def verify_plan_file(path: Path, manifest: dict) -> None:
    payload = load_json(path)
    unknown = set(payload) - PLAN_KEYS
    if unknown:
        raise ExplainError('PLAN_UNKNOWN', f'{path.name}: {sorted(unknown)}')
    if payload.get('schema_version') != 'p3-explain-plan/v1':
        raise ExplainError('PLAN_SCHEMA', path.name)
    if str(payload.get('run_id')) != str(manifest['run_id']):
        raise ExplainError('RUN_ID', path.name)
    if payload.get('status') == 'ok' and not payload.get('plan'):
        raise ExplainError('PLAN_EMPTY', path.name)
    redacted = dict(payload)
    redacted.pop('run_id', None)
    hits = scan_secrets(redact_uuid_literals(json.dumps(redacted)))
    if hits:
        raise ExplainError('SECRET', f'{path.name}: {hits[:3]}')


def verify_artifacts(artifact_dir: Path) -> None:
    manifest = load_json(artifact_dir / 'run-manifest.json')
    top20 = load_json(artifact_dir / 'pgss-top20.json')
    fps = {str(row['query_fingerprint']) for row in top20.get('fingerprints') or []}
    explain_dir = artifact_dir / 'explain'
    if not explain_dir.is_dir():
        raise ExplainError('EXPLAIN_DIR', 'explain/ missing')
    covered = set()
    for path in explain_dir.glob('*.json'):
        verify_plan_file(path, manifest)
        row = load_json(path)
        covered.add(str(row['query_fingerprint']))
    for fp in fps:
        if fp not in covered:
            raise ExplainError('TOP20_COVERAGE', f'missing explain for {fp}')
    for row in top20.get('mandatory_hotspots') or []:
        fp = str(row.get('query_fingerprint') or '')
        if fp and fp not in covered:
            raise ExplainError('HOTSPOT_COVERAGE', f'missing explain for hotspot {fp}')
    for anchor in CODE_ANCHOR_QUERIES:
        key = f"code-anchor:{anchor['anchor_id']}"
        if key not in covered:
            raise ExplainError('ANCHOR_COVERAGE', key)
    inv = load_json(artifact_dir / 'index-inventory.json')
    if len(inv.get('families') or []) != 8:
        raise ExplainError('INVENTORY_FAMILIES', 'expected 8 families')
    review = load_json(artifact_dir / 'index-review.json')
    if len(review.get('families') or []) != 8:
        raise ExplainError('REVIEW_FAMILIES', 'expected 8 families')
    hyp = load_json(artifact_dir / 'hypopg-candidates.json')
    for cand in hyp.get('candidates') or []:
        if not cand.get('hypopg_reset'):
            raise ExplainError('HYPOPG_RESET', cand.get('candidate_id', '?'))
        blob = json.dumps(cand)
        if re.search(r'EXPLAIN\s+ANALYZE', blob, re.I):
            raise ExplainError('HYPOPG_ANALYZE', cand.get('candidate_id', '?'))


def cmd_capture(args: argparse.Namespace) -> int:
    artifact_dir = Path(args.artifact_dir)
    explain_dir = Path(args.explain_dir)
    sql_dir = Path(args.sql_dir)
    runner = PsqlRunner(compose_cmd=shlex.split(args.compose_cmd))
    capture_plans(artifact_dir=artifact_dir, explain_dir=explain_dir, runner=runner, sql_dir=sql_dir)
    print('capture: ok')
    return 0


def cmd_build_index(args: argparse.Namespace) -> int:
    artifact_dir = Path(args.artifact_dir)
    runner = PsqlRunner(compose_cmd=shlex.split(args.compose_cmd))
    inventory = build_index_inventory(artifact_dir, runner, Path(args.sql_dir))
    dump_json(artifact_dir / 'index-inventory.json', inventory)
    review = build_index_review(artifact_dir, Path(args.explain_dir), inventory)
    dump_json(artifact_dir / 'index-review.json', review)
    print('build-index: ok')
    return 0


def cmd_hypopg(args: argparse.Namespace) -> int:
    artifact_dir = Path(args.artifact_dir)
    runner = PsqlRunner(compose_cmd=shlex.split(args.compose_cmd))
    seed = latest_seed_manifest()
    payload = run_hypopg(runner, artifact_dir, seed)
    dump_json(artifact_dir / 'hypopg-candidates.json', payload)
    print('hypopg: ok')
    return 0


def cmd_verify(args: argparse.Namespace) -> int:
    verify_artifacts(Path(args.artifact_dir))
    print('verify-artifacts: ok')
    return 0


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description='P3.3 EXPLAIN and index evidence')
    parser.add_argument(
        '--compose-cmd',
        default='docker compose -f load/docker-compose.load.yml --env-file load/env/S.env',
    )
    sub = parser.add_subparsers(dest='command', required=True)
    capture = sub.add_parser('capture')
    capture.add_argument('--artifact-dir', default=str(P3_ARTIFACTS))
    capture.add_argument('--explain-dir', default=str(P3_ARTIFACTS / 'explain'))
    capture.add_argument('--sql-dir', default=str(REPO_ROOT / 'load' / 'sql' / 'p3'))
    capture.set_defaults(func=cmd_capture)
    build = sub.add_parser('build-index')
    build.add_argument('--artifact-dir', default=str(P3_ARTIFACTS))
    build.add_argument('--explain-dir', default=str(P3_ARTIFACTS / 'explain'))
    build.add_argument('--sql-dir', default=str(REPO_ROOT / 'load' / 'sql' / 'p3'))
    build.set_defaults(func=cmd_build_index)
    hyp = sub.add_parser('hypopg')
    hyp.add_argument('--artifact-dir', default=str(P3_ARTIFACTS))
    hyp.set_defaults(func=cmd_hypopg)
    verify = sub.add_parser('verify-artifacts')
    verify.add_argument('--artifact-dir', default=str(P3_ARTIFACTS))
    verify.set_defaults(func=cmd_verify)
    args = parser.parse_args(argv)
    args.compose_cmd = args.compose_cmd.strip()
    try:
        return args.func(args)
    except ExplainError as exc:
        print(str(exc), file=sys.stderr)
        return 1


if __name__ == '__main__':
    raise SystemExit(main())
