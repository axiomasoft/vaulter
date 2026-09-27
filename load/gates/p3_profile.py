#!/usr/bin/env python3
"""P3.1 — bounded profiling evidence validator (manifest, trace, explain, hypopg)."""

from __future__ import annotations

import argparse
import hashlib
import json
import re
import sys
from pathlib import Path

TERMINAL_MARKER = 'p3-run-complete'
MANIFEST_KEYS = {
    'schema_version',
    'run_id',
    'tier',
    'dataset_id',
    'env_fingerprint',
    'disposable_db',
    'pg_stat_statements',
    'pg_stat_statements_info',
    'extensions',
    'track_io_timing',
    'terminal',
}
TRACE_KEYS = {
    'run_id',
    'command_id',
    'invocation_id',
    'connection',
    'sql_template_hash',
    'duration_ms',
    'ordinal',
    'in_transaction',
}
FORBIDDEN_TRACE_KEYS = {'bindings', 'sql', 'query', 'rendered_sql', 'token', 'password'}
UUID_RE = re.compile(
    r'\b[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\b',
    re.I,
)
SECRET_RE = re.compile(r'(vaulter-load-secret|POSTGRES_PASSWORD|password\s*[:=])', re.I)


class ProfileError(ValueError):
    def __init__(self, code: str, message: str):
        super().__init__(f'{code}: {message}')
        self.code = code


def load_json(path: Path) -> dict:
    return json.loads(path.read_text(encoding='utf-8'))


def load_jsonl(path: Path) -> list[dict]:
    rows = []
    for line_no, line in enumerate(path.read_text(encoding='utf-8').splitlines(), start=1):
        if not line.strip():
            continue
        try:
            rows.append(json.loads(line))
        except json.JSONDecodeError as exc:
            raise ProfileError('JSONL_PARSE', f'{path.name}:{line_no}: {exc}') from exc
    return rows


def reject_unknown_keys(payload: dict, allowed: set[str], label: str) -> None:
    unknown = sorted(set(payload) - allowed)
    if unknown:
        raise ProfileError('UNKNOWN_FIELDS', f'{label}: {unknown}')


def scan_secrets(blob: str) -> list[str]:
    hits = [match.group(0) for match in UUID_RE.finditer(blob)]
    hits.extend(match.group(0) for match in SECRET_RE.finditer(blob))
    return hits


def verify_manifest(manifest: dict) -> None:
    reject_unknown_keys(manifest, MANIFEST_KEYS, 'run-manifest')
    if manifest.get('schema_version') != 'p3-profile/v1':
        raise ProfileError('SCHEMA_VERSION', 'run-manifest.schema_version must be p3-profile/v1')
    if not manifest.get('env_fingerprint'):
        raise ProfileError('FINGERPRINT_MISSING', 'run-manifest.env_fingerprint required')
    info = manifest.get('pg_stat_statements_info') or {}
    if not isinstance(info, dict):
        raise ProfileError('PGSS_INFO', 'pg_stat_statements_info must be an object')
    for key in ('dealloc_before', 'dealloc_after', 'dealloc_delta'):
        if key not in info:
            raise ProfileError('DEALLOC_MISSING', f'pg_stat_statements_info.{key} required')
    terminal = manifest.get('terminal') or {}
    if terminal.get('marker') != TERMINAL_MARKER:
        raise ProfileError('TERMINAL_MARKER', f'terminal.marker must be {TERMINAL_MARKER}')


def verify_trace(path: Path) -> None:
    rows = load_jsonl(path)
    if not rows:
        raise ProfileError('TRACE_EMPTY', 'query trace has no rows')
    seen_ordinals: set[tuple[str, int]] = set()
    for row in rows:
        reject_unknown_keys(row, TRACE_KEYS, 'query-trace')
        forbidden = FORBIDDEN_TRACE_KEYS.intersection(row)
        if forbidden:
            raise ProfileError('TRACE_PRIVACY', f'forbidden keys in trace: {sorted(forbidden)}')
        invocation = str(row.get('invocation_id', ''))
        if not re.fullmatch(r'[a-f0-9]{16}', invocation):
            raise ProfileError('TRACE_INVOCATION', 'invocation_id must be 16 hex digits')
        ordinal = row.get('ordinal')
        identity = (invocation, ordinal)
        if identity in seen_ordinals:
            raise ProfileError('TRACE_DUPLICATE', f'duplicate invocation/ordinal {identity}')
        seen_ordinals.add(identity)
        if not re.fullmatch(r'[a-f0-9]{64}', str(row.get('sql_template_hash', ''))):
            raise ProfileError('TRACE_HASH', 'sql_template_hash must be sha256 hex')


def verify_explain_dir(run_dir: Path, manifest: dict) -> None:
    explain_dir = run_dir / 'explain'
    if not explain_dir.is_dir():
        return
    for path in explain_dir.glob('*.json'):
        payload = load_json(path)
        reject_unknown_keys(
            payload,
            {'query_hash', 'disposable_db', 'command', 'plan'},
            path.name,
        )
        command = str(payload.get('command', ''))
        if re.search(r'\b(INSERT|UPDATE|DELETE)\b', command, re.I):
            if not payload.get('disposable_db'):
                raise ProfileError('EXPLAIN_DISPOSABLE', f'{path.name}: write EXPLAIN requires disposable_db')
        if not manifest.get('disposable_db') and re.search(r'\b(INSERT|UPDATE|DELETE)\b', command, re.I):
            raise ProfileError('EXPLAIN_DISPOSABLE', 'manifest.disposable_db required for write EXPLAIN')


def verify_hypopg(run_dir: Path) -> None:
    path = run_dir / 'hypopg-session.json'
    if not path.is_file():
        return
    payload = load_json(path)
    reject_unknown_keys(
        payload,
        {'baseline_plan', 'candidate_plan', 'hypopg_reset', 'session_id'},
        'hypopg-session',
    )
    blob = json.dumps(payload)
    if re.search(r'ANALYZE', blob, re.I) and 'hypopg' in blob.lower():
        raise ProfileError('HYPOPG_ANALYZE', 'HypoPG session must not use EXPLAIN ANALYZE')
    if not payload.get('hypopg_reset'):
        raise ProfileError('HYPOPG_RESET', 'hypopg_reset proof required')


def redacted_json_blob(path: Path) -> str:
    payload = json.loads(path.read_text(encoding='utf-8'))
    if isinstance(payload, dict):
        payload = dict(payload)
        payload.pop('run_id', None)
    return json.dumps(payload)


def redacted_jsonl_blob(path: Path) -> str:
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


def verify_run_dir(run_dir: Path) -> None:
    manifest_path = run_dir / 'run-manifest.json'
    if not manifest_path.is_file():
        raise ProfileError('MANIFEST_MISSING', 'run-manifest.json missing')
    manifest = load_json(manifest_path)
    verify_manifest(manifest)
    trace_path = run_dir / 'query-trace.jsonl'
    if trace_path.is_file():
        verify_trace(trace_path)
        if any(row.get('run_id') != manifest['run_id'] for row in load_jsonl(trace_path)):
            raise ProfileError('TRACE_RUN_ID', 'trace row run_id differs from manifest')
    verify_explain_dir(run_dir, manifest)
    verify_hypopg(run_dir)
    for name, redactor in (
        ('run-manifest.json', redacted_json_blob),
        ('query-trace.jsonl', redacted_jsonl_blob),
        ('hypopg-session.json', lambda p: p.read_text(encoding='utf-8')),
    ):
        path = run_dir / name
        if path.is_file():
            hits = scan_secrets(redactor(path))
            if hits:
                raise ProfileError('SECRET_LEAK', f'{name}: {hits[:3]}')


def env_fingerprint(env_file: Path, image_tag: str, dataset_id: str) -> str:
    payload = env_file.read_bytes() + f'{image_tag} {dataset_id}'.encode('utf-8')
    return hashlib.sha256(payload).hexdigest()[:16]


def cmd_verify(args: argparse.Namespace) -> int:
    verify_run_dir(Path(args.run_dir))
    print('verify: ok')
    return 0


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description='P3 profiling evidence validator')
    sub = parser.add_subparsers(dest='command', required=True)
    verify = sub.add_parser('verify', help='validate a run directory')
    verify.add_argument('--run-dir', required=True)
    verify.set_defaults(func=cmd_verify)
    args = parser.parse_args(argv)
    try:
        return args.func(args)
    except ProfileError as exc:
        print(str(exc), file=sys.stderr)
        return 1


if __name__ == '__main__':
    raise SystemExit(main())
