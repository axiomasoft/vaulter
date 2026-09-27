#!/usr/bin/env python3
"""P4.1 — memory lane evidence schema validator."""

from __future__ import annotations

import argparse
import hashlib
import json
import re
import sys
from pathlib import Path

TERMINAL_MARKER = 'p4-run-complete'
SCHEMA_VERSION = 'p4-memory/v1'

MANIFEST_KEYS = {
    'schema_version',
    'run_id',
    'tier',
    'case_id',
    'fixture_id',
    'env_fingerprint',
    'cgroup_mode',
    'fpm',
    'worker_pid_identity',
    'byte_accounting',
    'php_peak_bytes',
    'prometheus_capture',
    'preflight',
    'terminal',
    'matrix',
}

SAMPLE_KEYS = {
    'monotonic_ns',
    'phase',
    'php_peak_bytes',
    'proc',
    'cgroup',
    'pid_identity',
}

BYTE_ACCOUNTING_KEYS = {
    'http_status',
    'content_length_bytes',
    'client_bytes_received',
    'server_object_sha256',
    'probe_object_sha256',
}

SECRET_RE = re.compile(r'(vaulter-load-secret|POSTGRES_PASSWORD|password\s*[:=])', re.I)
SHA256_RE = re.compile(r'^[a-f0-9]{64}$')


class MemoryError(ValueError):
    def __init__(self, code: str, message: str):
        super().__init__(f'{code}: {message}')
        self.code = code


def load_json(path: Path) -> dict:
    return json.loads(path.read_text(encoding='utf-8'))


def load_jsonl(path: Path) -> list[dict]:
    rows: list[dict] = []
    for line_no, line in enumerate(path.read_text(encoding='utf-8').splitlines(), start=1):
        if not line.strip():
            continue
        try:
            rows.append(json.loads(line))
        except json.JSONDecodeError as exc:
            raise MemoryError('JSONL_PARSE', f'{path.name}:{line_no}: {exc}') from exc
    return rows


def reject_unknown_keys(payload: dict, allowed: set[str], label: str) -> None:
    unknown = sorted(set(payload) - allowed)
    if unknown:
        raise MemoryError('UNKNOWN_FIELDS', f'{label}: {unknown}')


def scan_secrets(blob: str) -> list[str]:
    return [match.group(0) for match in SECRET_RE.finditer(blob)]


def verify_manifest(manifest: dict) -> None:
    reject_unknown_keys(manifest, MANIFEST_KEYS, 'run-manifest')
    if manifest.get('schema_version') != SCHEMA_VERSION:
        raise MemoryError('SCHEMA_VERSION', f'schema_version must be {SCHEMA_VERSION}')
    if manifest.get('cgroup_mode') != 'v2':
        raise MemoryError('CGROUP_MODE', 'cgroup_mode must be v2')
    if not manifest.get('env_fingerprint'):
        raise MemoryError('FINGERPRINT_MISSING', 'env_fingerprint required')

    fpm = manifest.get('fpm') or {}
    if not isinstance(fpm, dict):
        raise MemoryError('FPM', 'fpm must be an object')
    if fpm.get('max_children') != 1:
        raise MemoryError('FPM_CHILDREN', 'fpm.max_children must be 1 for memory lane')

    identity = manifest.get('worker_pid_identity') or {}
    if not isinstance(identity, dict):
        raise MemoryError('PID_IDENTITY', 'worker_pid_identity must be an object')
    for key in ('pid', 'starttime_ticks'):
        if key not in identity:
            raise MemoryError('PID_IDENTITY', f'worker_pid_identity.{key} required')

    preflight = manifest.get('preflight') or {}
    for key in ('smaps_rollup', 'cgroup_v2', 'worker_pid_unique'):
        if preflight.get(key) is not True:
            raise MemoryError('PREFLIGHT', f'preflight.{key} must be true')

    terminal = manifest.get('terminal') or {}
    if terminal.get('marker') != TERMINAL_MARKER:
        raise MemoryError('TERMINAL_MARKER', f'terminal.marker must be {TERMINAL_MARKER}')
    if not terminal.get('verified_at'):
        raise MemoryError('TERMINAL_TIME', 'terminal.verified_at required')

    verify_byte_accounting(manifest.get('byte_accounting') or {})


def verify_byte_accounting(payload: dict) -> None:
    reject_unknown_keys(payload, BYTE_ACCOUNTING_KEYS, 'byte_accounting')
    for key in BYTE_ACCOUNTING_KEYS:
        if key not in payload:
            raise MemoryError('BYTE_ACCOUNTING', f'byte_accounting.{key} required')
    status = payload.get('http_status')
    if not isinstance(status, int) or status < 100 or status > 599:
        raise MemoryError('HTTP_STATUS', 'http_status must be a valid HTTP code')
    clen = payload.get('content_length_bytes')
    client = payload.get('client_bytes_received')
    if not isinstance(clen, int) or clen < 0:
        raise MemoryError('CONTENT_LENGTH', 'content_length_bytes must be non-negative int')
    if not isinstance(client, int) or client < 0:
        raise MemoryError('CLIENT_BYTES', 'client_bytes_received must be non-negative int')
    if client != clen:
        raise MemoryError('BYTE_MISMATCH', 'client_bytes_received must equal content_length_bytes')
    for key in ('server_object_sha256', 'probe_object_sha256'):
        value = str(payload.get(key, ''))
        if not SHA256_RE.fullmatch(value):
            raise MemoryError('CHECKSUM', f'{key} must be sha256 hex')
    if payload['server_object_sha256'] != payload['probe_object_sha256']:
        raise MemoryError('CHECKSUM_MISMATCH', 'server/probe object sha256 must match')


def verify_sample_row(row: dict, identity: dict) -> None:
    reject_unknown_keys(row, SAMPLE_KEYS, 'sample')
    extra = row.get('pid_identity')
    if extra is not None:
        if not isinstance(extra, dict):
            raise MemoryError('PID_IDENTITY', 'pid_identity must be an object when present')
        if extra.get('pid') != identity.get('pid'):
            raise MemoryError('PID_REUSE', 'sample pid_identity.pid mismatch')
        if extra.get('starttime_ticks') != identity.get('starttime_ticks'):
            raise MemoryError('PID_REUSE', 'sample pid_identity.starttime_ticks mismatch')
    phase = row.get('phase')
    if phase not in {'before', 'during', 'after'}:
        raise MemoryError('PHASE', f'invalid phase {phase!r}')
    proc = row.get('proc') or {}
    if not isinstance(proc, dict):
        raise MemoryError('PROC', 'proc must be an object')
    smaps = proc.get('smaps_rollup')
    if not isinstance(smaps, dict):
        raise MemoryError('SMAPS', 'proc.smaps_rollup required')
    if smaps.get('Pss_Anon') is None and smaps.get('Pss') is None:
        raise MemoryError('PSS', 'proc.smaps_rollup must expose Pss or Pss_Anon (not coerced zero)')
    cgroup = row.get('cgroup') or {}
    if not isinstance(cgroup, dict):
        raise MemoryError('CGROUP', 'cgroup must be an object')
    if 'memory.stat' in json.dumps(cgroup):
        raise MemoryError('CGROUP_SHAPE', 'cgroup must use stat object, not v1 memory.stat key')
    stat = cgroup.get('stat') or {}
    if not isinstance(stat, dict):
        raise MemoryError('CGROUP_STAT', 'cgroup.stat must be an object')
    if cgroup.get('current') is None and cgroup.get('peak') is None:
        raise MemoryError('CGROUP_BYTES', 'cgroup.current or cgroup.peak required')


def verify_samples(path: Path, manifest: dict) -> None:
    rows = load_jsonl(path)
    if len(rows) < 3:
        raise MemoryError('SAMPLES_SHORT', 'samples.jsonl needs before/during/after rows')
    phases = [row.get('phase') for row in rows]
    if phases[0] != 'before' or phases[-1] != 'after':
        raise MemoryError('SAMPLE_ORDER', 'first sample must be before, last must be after')
    if 'during' not in phases:
        raise MemoryError('SAMPLE_DURING', 'missing during sample')
    identity = manifest.get('worker_pid_identity') or {}
    for row in rows:
        verify_sample_row(row, identity)
    if rows[-1].get('phase') != 'after':
        raise MemoryError('TERMINAL_SAMPLE', 'missing terminal after sample')

    first = rows[0]
    last = rows[-1]
    anon_before = (first.get('proc') or {}).get('smaps_rollup', {}).get('Pss_Anon')
    anon_after = (last.get('proc') or {}).get('smaps_rollup', {}).get('Pss_Anon')
    file_before = (first.get('proc') or {}).get('smaps_rollup', {}).get('Pss_File')
    file_after = (last.get('proc') or {}).get('smaps_rollup', {}).get('Pss_File')
    if isinstance(anon_before, int) and isinstance(anon_after, int):
        if anon_after > anon_before + 100 * 1024 * 1024:
            if isinstance(file_before, int) and isinstance(file_after, int):
                if file_after <= file_before + 4096:
                    raise MemoryError(
                        'O_FILE_SIZE_ANON',
                        'large Pss_Anon delta without Pss_File growth (buffered whole-file read)',
                    )


def verify_case_manifest(path: Path, manifest: dict) -> None:
    if not path.is_file():
        return
    case = load_json(path)
    allowed = {'case_id', 'fixture_id', 'request', 'ceilings_mib', 'process_kind'}
    reject_unknown_keys(case, allowed, 'case-manifest')
    if case.get('case_id') != manifest.get('case_id'):
        raise MemoryError('CASE_ID', 'case-manifest.case_id must match run-manifest')


def verify_run_dir(run_dir: Path) -> None:
    manifest_path = run_dir / 'run-manifest.json'
    samples_path = run_dir / 'samples.jsonl'
    if not manifest_path.is_file():
        raise MemoryError('MANIFEST_MISSING', 'run-manifest.json missing')
    if not samples_path.is_file():
        raise MemoryError('SAMPLES_MISSING', 'samples.jsonl missing')
    manifest = load_json(manifest_path)
    verify_manifest(manifest)
    verify_samples(samples_path, manifest)
    verify_case_manifest(run_dir / 'case-manifest.json', manifest)
    for name in ('run-manifest.json', 'samples.jsonl', 'case-manifest.json'):
        path = run_dir / name
        if path.is_file():
            hits = scan_secrets(path.read_text(encoding='utf-8'))
            if hits:
                raise MemoryError('SECRET_LEAK', f'{name}: {hits[:3]}')


def env_fingerprint(env_file: Path, dataset_id: str) -> str:
    payload = env_file.read_bytes() + dataset_id.encode('utf-8')
    return hashlib.sha256(payload).hexdigest()[:16]


def cmd_verify(args: argparse.Namespace) -> int:
    verify_run_dir(Path(args.run_dir))
    print('verify: ok')
    return 0


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description='P4 memory lane evidence validator')
    sub = parser.add_subparsers(dest='command', required=True)
    verify = sub.add_parser('verify', help='validate a run directory')
    verify.add_argument('--run-dir', required=True)
    verify.set_defaults(func=cmd_verify)
    args = parser.parse_args(argv)
    try:
        return args.func(args)
    except MemoryError as exc:
        print(str(exc), file=sys.stderr)
        return 1


if __name__ == '__main__':
    raise SystemExit(main())
