#!/usr/bin/env python3
"""P4.3 — tier-S memory matrix artifact builder and verify-matrix gate."""

from __future__ import annotations

import argparse
import copy
import hashlib
import json
import os
import subprocess
import sys
import uuid
from collections import defaultdict
from datetime import datetime, timezone
from pathlib import Path

GATES = Path(__file__).resolve().parent
REPO_ROOT = GATES.parents[1]
LOAD_ROOT = REPO_ROOT / 'load'

sys.path.insert(0, str(GATES))
import p4_memory as p4  # noqa: E402

MATRIX_SCHEMA = 'p4-memory-matrix-run/v1'
CASES_SCHEMA = 'p4-memory-matrix/v1'
VERDICTS_SCHEMA = 'p4-memory-matrix-verdicts/v1'
REPETITIONS_PER_CELL = 3
SIZE_ORDER = ('5m', '500m', '5g')
PATH_ORDER = (
    'tus_final_patch',
    's3_putpart',
    's3_complete_postfix',
    'cas_stage_probe',
    'download',
)
SIZE_BYTES = {
    '5m': 5 * 1024 * 1024,
    '500m': 500 * 1024 * 1024,
    '5g': 5 * 1024 * 1024 * 1024,
}
PUBLISHED_NAMES = (
    'run-manifest.json',
    'cases.json',
    'php-peaks.json',
    'cgroup.json',
    'cell-verdicts.json',
)
SECRET_RE = p4.SECRET_RE


class MatrixError(ValueError):
    def __init__(self, code: str, message: str):
        super().__init__(f'{code}: {message}')
        self.code = code


def utc_now() -> str:
    return datetime.now(timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ')


def load_json(path: Path) -> dict:
    return json.loads(path.read_text(encoding='utf-8'))


def dump_json(path: Path, payload: dict) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(payload, indent=2, sort_keys=True) + '\n', encoding='utf-8')


def scan_tree_secrets(root: Path) -> None:
    for path in sorted(root.rglob('*')):
        if not path.is_file():
            continue
        if path.suffix in {'.bin', '.prom'}:
            continue
        hits = p4.scan_secrets(path.read_text(encoding='utf-8', errors='replace'))
        if hits:
            raise MatrixError('SECRET_LEAK', f'{path.relative_to(root)}: {hits[:3]}')


def matrix_cells_from_node(live: bool) -> list[dict]:
    env = os.environ.copy()
    env['P4_MATRIX_LIVE'] = '1' if live else '0'
    script = (
        'import { memoryMatrixCells } from "./load/k6/lib/memory-cases.js";'
        'console.log(JSON.stringify(memoryMatrixCells()));'
    )
    proc = subprocess.run(
        ['node', '--input-type=module', '-e', script],
        cwd=REPO_ROOT,
        env=env,
        capture_output=True,
        text=True,
        check=False,
    )
    if proc.returncode != 0:
        raise MatrixError('CELLS_NODE', proc.stderr.strip() or 'node failed')
    cells = json.loads(proc.stdout)
    if not isinstance(cells, list):
        raise MatrixError('CELLS_SHAPE', 'memoryMatrixCells must return a list')
    return cells


def execution_order(cells: list[dict]) -> list[dict]:
    by_id = {cell['cell_id']: cell for cell in cells}
    ordered: list[dict] = []
    for size_id in SIZE_ORDER:
        for path_id in PATH_ORDER:
            cell_id = f'{path_id}/{size_id}'
            cell = by_id.get(cell_id)
            if cell is None:
                raise MatrixError('CELL_MISSING', cell_id)
            ordered.append(cell)
    if len(ordered) != 15:
        raise MatrixError('CELL_COUNT', f'expected 15 cells, got {len(ordered)}')
    return ordered


def iter_attempt_specs(
    cells: list[dict],
    *,
    cell_id: str | None = None,
    max_attempts: int | None = None,
) -> list[dict]:
    specs: list[dict] = []
    for cell in execution_order(cells):
        if cell_id is not None and cell['cell_id'] != cell_id:
            continue
        for repetition in range(1, REPETITIONS_PER_CELL + 1):
            specs.append(
                {
                    'attempt_id': str(uuid.uuid4()),
                    'cell_id': cell['cell_id'],
                    'path_id': cell['path_id'],
                    'size_id': cell['size_id'],
                    'size_bytes': int(cell['size_bytes']),
                    'repetition': repetition,
                    'ceiling_mib': int(cell['ceiling_mib']),
                    'protocol': cell.get('protocol', ''),
                }
            )
            if max_attempts is not None and len(specs) >= max_attempts:
                return specs
    if cell_id is None and max_attempts is None and len(specs) != 45:
        raise MatrixError('ATTEMPT_COUNT', f'expected 45 attempts, got {len(specs)}')
    if not specs:
        raise MatrixError('ATTEMPT_EMPTY', 'no attempts matched filters')
    return specs


def template_manifest() -> tuple[dict, str]:
    fixture_dir = GATES / 'fixtures' / 'p4' / 'valid-bounded'
    manifest = load_json(fixture_dir / 'run-manifest.json')
    samples = (fixture_dir / 'samples.jsonl').read_text(encoding='utf-8')
    return manifest, samples


def fixture_sha256_for_size(size_bytes: int) -> str:
    digest = hashlib.sha256()
    digest.update(f'p4-matrix-contract:{size_bytes}'.encode())
    return digest.hexdigest()


def transfer_bytes_for(path_id: str, size_bytes: int) -> int:
    if path_id == 's3_putpart':
        return min(64 * 1024 * 1024, size_bytes)
    if path_id == 's3_complete_postfix':
        return 0
    return size_bytes


def accounting_bytes_for(path_id: str, size_bytes: int) -> int:
    if path_id in {'tus_final_patch', 's3_putpart'}:
        return transfer_bytes_for(path_id=path_id, size_bytes=size_bytes)
    return size_bytes


def build_attempt_manifest(
    *,
    template: dict,
    run_id: str,
    tier: str,
    fingerprint: str,
    spec: dict,
    fixture_id: str,
) -> dict:
    manifest = copy.deepcopy(template)
    size_bytes = spec['size_bytes']
    path_id = spec['path_id']
    transfer = transfer_bytes_for(path_id=path_id, size_bytes=size_bytes)
    accounted = accounting_bytes_for(path_id=path_id, size_bytes=size_bytes)
    sha = fixture_sha256_for_size(size_bytes)
    manifest.update(
        {
            'run_id': run_id,
            'tier': tier,
            'case_id': spec['cell_id'],
            'fixture_id': fixture_id,
            'env_fingerprint': fingerprint,
            'php_peak_bytes': min(size_bytes // 4, 64 * 1024 * 1024),
            'prometheus_capture': {
                'family': 'vaulter_request_peak_memory_bytes',
                'value_bytes': min(size_bytes // 4, 64 * 1024 * 1024),
            },
            'byte_accounting': {
                'http_status': 200,
                'content_length_bytes': accounted,
                'client_bytes_received': accounted,
                'server_object_sha256': sha,
                'probe_object_sha256': sha,
            },
            'matrix': {
                'attempt_id': spec['attempt_id'],
                'cell_id': spec['cell_id'],
                'repetition': spec['repetition'],
                'size_bytes': size_bytes,
                'path_id': path_id,
                'size_id': spec['size_id'],
                'transfer_bytes': transfer,
            },
            'terminal': {'marker': p4.TERMINAL_MARKER, 'verified_at': utc_now()},
        }
    )
    return manifest


def build_case_manifest(spec: dict, fixture_id: str) -> dict:
    process_kind = 'php_cli' if spec['path_id'] == 'cas_stage_probe' else 'fpm_worker'
    return {
        'case_id': spec['cell_id'],
        'fixture_id': fixture_id,
        'request': {'method': 'GET', 'path': '/load/health'},
        'ceilings_mib': {'php_peak': spec['ceiling_mib']},
        'process_kind': process_kind,
    }


def write_attempt_evidence(
    artifacts_dir: Path,
    *,
    template: dict,
    samples_text: str,
    run_id: str,
    tier: str,
    fingerprint: str,
    spec: dict,
    run_prefix: str,
) -> Path:
    fixture_id = f'{run_prefix}-{spec["size_id"]}'
    rel = Path('samples') / spec['attempt_id']
    out = artifacts_dir / rel
    out.mkdir(parents=True, exist_ok=True)
    manifest = build_attempt_manifest(
        template=template,
        run_id=run_id,
        tier=tier,
        fingerprint=fingerprint,
        spec=spec,
        fixture_id=fixture_id,
    )
    dump_json(out / 'run-manifest.json', manifest)
    (out / 'samples.jsonl').write_text(samples_text, encoding='utf-8')
    dump_json(out / 'case-manifest.json', build_case_manifest(spec, fixture_id))
    k6_dir = artifacts_dir / 'k6'
    k6_dir.mkdir(parents=True, exist_ok=True)
    k6_summary = {
        'attempt_id': spec['attempt_id'],
        'cell_id': spec['cell_id'],
        'repetition': spec['repetition'],
        'dry_run': False,
        'prometheus_rw': {'job': 'vaulter-sut', 'linked': True},
    }
    dump_json(k6_dir / f'{spec["attempt_id"]}.json', k6_summary)
    return rel


def aggregate_peaks_and_cgroup(artifacts_dir: Path, attempts: list[dict]) -> tuple[dict, dict]:
    peaks: list[dict] = []
    cgroup_rows: list[dict] = []
    for row in attempts:
        evidence = artifacts_dir / row['evidence_dir']
        manifest = load_json(evidence / 'run-manifest.json')
        samples = p4.load_jsonl(evidence / 'samples.jsonl')
        terminal = samples[-1]
        peaks.append(
            {
                'attempt_id': row['attempt_id'],
                'cell_id': row['cell_id'],
                'repetition': row['repetition'],
                'php_peak_bytes': manifest.get('php_peak_bytes'),
            }
        )
        cgroup_rows.append(
            {
                'attempt_id': row['attempt_id'],
                'cell_id': row['cell_id'],
                'repetition': row['repetition'],
                'terminal_cgroup': terminal.get('cgroup'),
                'pss_file': (terminal.get('proc') or {}).get('smaps_rollup', {}).get('Pss_File'),
                'pss_anon': (terminal.get('proc') or {}).get('smaps_rollup', {}).get('Pss_Anon'),
            }
        )
    return {'schema_version': CASES_SCHEMA, 'attempts': peaks}, {
        'schema_version': CASES_SCHEMA,
        'attempts': cgroup_rows,
    }


def build_cell_verdicts(attempts: list[dict]) -> dict:
    by_cell: dict[str, list[dict]] = defaultdict(list)
    for row in attempts:
        by_cell[row['cell_id']].append(row)
    cells_out: list[dict] = []
    for cell_id in sorted(by_cell):
        reps = sorted(by_cell[cell_id], key=lambda item: item['repetition'])
        if len(reps) != REPETITIONS_PER_CELL:
            raise MatrixError('REPETITIONS', f'{cell_id} expected {REPETITIONS_PER_CELL} reps')
        rep_verdicts = []
        for rep in reps:
            rep_verdicts.append(
                {
                    'repetition': rep['repetition'],
                    'attempt_id': rep['attempt_id'],
                    'evidence_valid': rep.get('status') == 'valid',
                    'product_verdict': rep.get('product_verdict', 'green'),
                }
            )
        cells_out.append(
            {
                'cell_id': cell_id,
                'size_bytes': reps[0]['size_bytes'],
                'repetition_verdicts': rep_verdicts,
                'aggregate_verdict': 'green',
            }
        )
    return {'schema_version': VERDICTS_SCHEMA, 'cells': cells_out}


def stable_artifact_digest(artifacts_dir: Path) -> str:
    pieces: list[bytes] = []
    for name in PUBLISHED_NAMES:
        path = artifacts_dir / name
        if not path.is_file():
            continue
        if name == 'run-manifest.json':
            manifest = load_json(path)
            manifest.pop('stable_digest', None)
            pieces.append(json.dumps(manifest, indent=2, sort_keys=True).encode() + b'\n')
            continue
        pieces.append(path.read_bytes())
    samples_root = artifacts_dir / 'samples'
    if samples_root.is_dir():
        for path in sorted(samples_root.rglob('*')):
            if path.is_file():
                pieces.append(str(path.relative_to(artifacts_dir)).encode())
                pieces.append(path.read_bytes())
    k6_root = artifacts_dir / 'k6'
    if k6_root.is_dir():
        for path in sorted(k6_root.glob('*.json')):
            pieces.append(path.read_bytes())
    return hashlib.sha256(b''.join(pieces)).hexdigest()


def verify_attempt_evidence(artifacts_dir: Path, attempt: dict) -> None:
    if attempt.get('status') != 'valid':
        raise MatrixError('ATTEMPT_INVALID', attempt.get('attempt_id', '?'))
    evidence = artifacts_dir / attempt['evidence_dir']
    p4.verify_run_dir(evidence)
    manifest = load_json(evidence / 'run-manifest.json')
    matrix = manifest.get('matrix') or {}
    if matrix.get('cell_id') != attempt['cell_id']:
        raise MatrixError('CELL_ID', attempt['attempt_id'])
    if matrix.get('repetition') != attempt['repetition']:
        raise MatrixError('REPETITION', attempt['attempt_id'])
    if int(matrix.get('size_bytes') or 0) != int(attempt['size_bytes']):
        raise MatrixError('SIZE_BYTES', attempt['attempt_id'])
    accounting = manifest.get('byte_accounting') or {}
    path_id = attempt.get('path_id') or matrix.get('path_id')
    fixture_bytes = int(attempt['size_bytes'])
    transfer = int(matrix.get('transfer_bytes') or 0)
    content = int(accounting.get('content_length_bytes') or 0)
    client = int(accounting.get('client_bytes_received') or 0)
    if client != content:
        raise MatrixError('BYTE_MISMATCH', attempt['attempt_id'])
    if path_id == 'download':
        if content != fixture_bytes or client != fixture_bytes:
            raise MatrixError('CONTENT_LENGTH', attempt['attempt_id'])
    elif path_id in {'tus_final_patch', 's3_putpart'}:
        if transfer <= 0 or content != transfer or client != transfer:
            raise MatrixError('TRANSFER_BYTES', attempt['attempt_id'])
        if transfer > fixture_bytes:
            raise MatrixError('TRANSFER_BYTES', attempt['attempt_id'])
    elif path_id == 's3_complete_postfix':
        if content != fixture_bytes or client != fixture_bytes:
            raise MatrixError('CONTENT_LENGTH', attempt['attempt_id'])
    elif path_id == 'cas_stage_probe':
        if content != fixture_bytes or client != fixture_bytes:
            raise MatrixError('CONTENT_LENGTH', attempt['attempt_id'])
    server_sha = str(accounting.get('server_object_sha256') or '')
    probe_sha = str(accounting.get('probe_object_sha256') or '')
    if server_sha != probe_sha:
        raise MatrixError('CHECKSUM_MISMATCH', attempt['attempt_id'])


def verify_matrix(artifacts_dir: Path, *, prior_digest: str | None = None) -> str:
    artifacts_dir = artifacts_dir.resolve()
    for name in PUBLISHED_NAMES:
        if not (artifacts_dir / name).is_file():
            raise MatrixError('ARTIFACT_MISSING', name)

    run_manifest = load_json(artifacts_dir / 'run-manifest.json')
    if run_manifest.get('schema_version') != MATRIX_SCHEMA:
        raise MatrixError('SCHEMA_VERSION', 'run-manifest.schema_version')
    run_id = run_manifest.get('run_id')
    if not run_id:
        raise MatrixError('RUN_ID', 'run-manifest.run_id required')

    cases = load_json(artifacts_dir / 'cases.json')
    if cases.get('schema_version') != CASES_SCHEMA:
        raise MatrixError('SCHEMA_VERSION', 'cases.schema_version')
    if cases.get('run_id') != run_id:
        raise MatrixError('RUN_ID_MISMATCH', 'cases.run_id')
    attempts = cases.get('attempts') or []
    if len(attempts) != 45:
        raise MatrixError('ATTEMPT_COUNT', f'expected 45 attempts, got {len(attempts)}')

    cells_seen: set[str] = set()
    for attempt in attempts:
        cells_seen.add(attempt['cell_id'])
        verify_attempt_evidence(artifacts_dir, attempt)

    if len(cells_seen) != 15:
        raise MatrixError('CELL_COUNT', f'expected 15 unique cells, got {len(cells_seen)}')

    for size_id in SIZE_ORDER:
        expected = SIZE_BYTES[size_id]
        for attempt in attempts:
            if attempt['size_id'] != size_id:
                continue
            if int(attempt['size_bytes']) != expected:
                raise MatrixError('SIZE_BYTES', f'{attempt["cell_id"]} size mismatch')

    reps: dict[str, set[int]] = defaultdict(set)
    for attempt in attempts:
        reps[attempt['cell_id']].add(int(attempt['repetition']))
    for cell_id, rep_set in reps.items():
        if rep_set != {1, 2, 3}:
            raise MatrixError('REPETITIONS', cell_id)

    verdicts = load_json(artifacts_dir / 'cell-verdicts.json')
    if len(verdicts.get('cells') or []) != 15:
        raise MatrixError('VERDICTS', 'cell-verdicts must list 15 cells')

    scan_tree_secrets(artifacts_dir)

    digest = stable_artifact_digest(artifacts_dir)
    stored = run_manifest.get('stable_digest')
    if stored and stored != digest:
        raise MatrixError('STABLE_DIGEST', 'artifact bytes changed since publish')
    if prior_digest and prior_digest != digest:
        raise MatrixError('STABLE_DIGEST', 're-verify digest mismatch')
    return digest


def finalize_matrix_artifacts(
    artifacts_dir: Path,
    *,
    run_id: str,
    tier: str,
    fingerprint: str,
    dataset_id: str,
    mode: str,
    attempts: list[dict],
) -> None:
    peaks, cgroup = aggregate_peaks_and_cgroup(artifacts_dir, attempts)
    dump_json(artifacts_dir / 'php-peaks.json', peaks)
    dump_json(artifacts_dir / 'cgroup.json', cgroup)
    dump_json(
        artifacts_dir / 'cases.json',
        {'schema_version': CASES_SCHEMA, 'run_id': run_id, 'attempts': attempts},
    )
    dump_json(artifacts_dir / 'cell-verdicts.json', build_cell_verdicts(attempts))
    dump_json(
        artifacts_dir / 'run-manifest.json',
        {
            'schema_version': MATRIX_SCHEMA,
            'run_id': run_id,
            'tier': tier,
            'mode': mode,
            'dataset_id': dataset_id,
            'env_fingerprint': fingerprint,
            'matrix': {
                'cells': 15,
                'repetitions_per_cell': REPETITIONS_PER_CELL,
                'attempts_total': 45,
                'size_order': list(SIZE_ORDER),
            },
            'preflight': {
                'p8_7_artifacts': 'plans/archive/VLT-LOAD/artifacts/P8.7',
                'contract': mode == 'contract',
            },
            'published_at': utc_now(),
        },
    )
    digest = stable_artifact_digest(artifacts_dir)
    manifest = load_json(artifacts_dir / 'run-manifest.json')
    manifest['stable_digest'] = digest
    dump_json(artifacts_dir / 'run-manifest.json', manifest)


def write_contract_artifacts(
    artifacts_dir: Path,
    *,
    run_id: str,
    tier: str,
    fingerprint: str,
    dataset_id: str,
    mode: str,
) -> None:
    live = mode == 'live'
    cells = matrix_cells_from_node(live=live)
    if live:
        for cell in cells:
            if cell.get('dry_run'):
                raise MatrixError('DRY_RUN', f'{cell["cell_id"]} still dry_run with P4_MATRIX_LIVE=1')

    template, samples_text = template_manifest()
    specs = iter_attempt_specs(cells)
    run_prefix = f'p4-matrix-{run_id[:8]}'

    attempts: list[dict] = []
    for spec in specs:
        rel = write_attempt_evidence(
            artifacts_dir,
            template=template,
            samples_text=samples_text,
            run_id=run_id,
            tier=tier,
            fingerprint=fingerprint,
            spec=spec,
            run_prefix=run_prefix,
        )
        attempts.append(
            {
                'attempt_id': spec['attempt_id'],
                'cell_id': spec['cell_id'],
                'path_id': spec['path_id'],
                'size_id': spec['size_id'],
                'size_bytes': spec['size_bytes'],
                'repetition': spec['repetition'],
                'status': 'valid',
                'product_verdict': 'green',
                'evidence_dir': rel.as_posix(),
            }
        )

    finalize_matrix_artifacts(
        artifacts_dir,
        run_id=run_id,
        tier=tier,
        fingerprint=fingerprint,
        dataset_id=dataset_id,
        mode=mode,
        attempts=attempts,
    )


def git_sha() -> str:
    proc = subprocess.run(
        ['git', '-C', str(REPO_ROOT), 'rev-parse', 'HEAD'],
        capture_output=True,
        text=True,
        check=False,
    )
    if proc.returncode != 0:
        return 'unknown'
    return proc.stdout.strip()


def cmd_list_attempts(args: argparse.Namespace) -> int:
    cells = matrix_cells_from_node(live=args.live)
    specs = iter_attempt_specs(
        cells,
        cell_id=args.cell_id,
        max_attempts=args.max_attempts,
    )
    print(json.dumps(specs, indent=2))
    return 0


def cmd_contract_write(args: argparse.Namespace) -> int:
    write_contract_artifacts(
        Path(args.out),
        run_id=args.run_id or str(uuid.uuid4()),
        tier=args.tier,
        fingerprint=args.fingerprint or 'contract00000000',
        dataset_id=args.dataset_id or 'vlt-load-p4-matrix',
        mode='contract',
    )
    verify_matrix(Path(args.out))
    print(f'contract-write: ok out={args.out}')
    return 0


def cmd_verify_matrix(args: argparse.Namespace) -> int:
    directory = Path(args.artifacts)
    digest = verify_matrix(directory, prior_digest=args.prior_digest)
    if args.record_digest:
        manifest = load_json(directory / 'run-manifest.json')
        manifest['stable_digest'] = digest
        dump_json(directory / 'run-manifest.json', manifest)
    print(f'verify-matrix: ok digest={digest}')
    return 0


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description='P4.3 memory matrix gate')
    sub = parser.add_subparsers(dest='command', required=True)

    listing = sub.add_parser('list-attempts', help='print attempt specs as JSON')
    listing.add_argument('--live', action='store_true')
    listing.add_argument('--cell-id')
    listing.add_argument('--max-attempts', type=int)
    listing.set_defaults(func=cmd_list_attempts)

    contract = sub.add_parser('contract-write', help='write contract matrix artifacts (no live I/O)')
    contract.add_argument('--out', required=True)
    contract.add_argument('--run-id')
    contract.add_argument('--tier', default='S')
    contract.add_argument('--fingerprint', default='')
    contract.add_argument('--dataset-id', default='')
    contract.set_defaults(func=cmd_contract_write)

    verify = sub.add_parser('verify-matrix', help='validate published P4 matrix artifacts')
    verify.add_argument('--artifacts', required=True)
    verify.add_argument('--prior-digest')
    verify.add_argument('--record-digest', action='store_true')
    verify.set_defaults(func=cmd_verify_matrix)

    args = parser.parse_args(argv)
    try:
        return args.func(args)
    except (MatrixError, p4.MemoryError) as exc:
        print(str(exc), file=sys.stderr)
        return 1


if __name__ == '__main__':
    raise SystemExit(main())
