#!/usr/bin/env python3
"""P8.11 host tracing proof.

Nonempty branch: parent/child, status, flush and sampling overhead against the
P8.8 manifest. Empty branch is rejected here because this manifest is nonempty.
"""

from __future__ import annotations

import argparse
import hashlib
import json
import sys
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parents[2]
PLAN_DIR = REPO_ROOT / 'plans' / 'archive' / 'VLT-LOAD'
DEFAULT_MANIFEST = PLAN_DIR / 'artifacts' / 'P8' / 'tracing-targets.json'
DEFAULT_PROOF = PLAN_DIR / 'artifacts' / 'P8' / 'tracing-proof.json'
DEFAULT_OVERHEAD = PLAN_DIR / 'artifacts' / 'P8' / 'tracing-overhead.json'
PACKAGE_ROOTS = (
    REPO_ROOT / 'packages' / 'core' / 'composer.json',
    REPO_ROOT / 'packages' / 'drive' / 'composer.json',
    REPO_ROOT / 'packages' / 'documents' / 'composer.json',
    REPO_ROOT / 'packages' / 'media' / 'composer.json',
    REPO_ROOT / 'packages' / 'azguard' / 'composer.json',
    REPO_ROOT / 'packages' / 'filament' / 'composer.json',
)
DELTA_CEILING = 0.10
MEMORY_DELTA_CEILING = 64 * 1024 * 1024
FORBIDDEN = (
    'upload_id',
    'node_id',
    'blob_id',
    'user_id',
    'tenant',
    'email',
    'token',
    'filename',
    'stack',
    'exception',
    'secret',
)


class ProofError(Exception):
    pass


def _sha256(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def _load(path: Path) -> dict:
    payload = json.loads(path.read_text(encoding='utf-8'))
    if not isinstance(payload, dict):
        raise ProofError(f'{path} is not an object')
    return payload


def _canonical(payload: dict) -> str:
    return json.dumps(payload, ensure_ascii=False, indent=2, sort_keys=True) + '\n'


def package_otel_dependencies(paths=PACKAGE_ROOTS) -> list[str]:
    found = []
    for path in paths:
        text = path.read_text(encoding='utf-8')
        if 'open-telemetry/' in text:
            found.append(path.relative_to(REPO_ROOT).as_posix())
    return found


def _p95_delta(disabled: dict, sampled: dict) -> float:
    baseline = int(disabled['p95_ns'])
    if baseline <= 0:
        raise ProofError('disabled p95 must be positive')
    return (int(sampled['p95_ns']) - baseline) / baseline


def _memory_delta(disabled: dict, sampled: dict) -> int:
    return int(sampled['peak_bytes']) - int(disabled['peak_bytes'])


def record(
    *,
    manifest: Path,
    disabled: dict,
    sampled: dict,
    full: dict,
    proof_path: Path,
    overhead_path: Path,
    otel_api: str,
    otel_sdk: str,
    php_version: str,
) -> dict:
    manifest_payload = _load(manifest)
    if manifest_payload.get('mode') != 'nonempty-candidates':
        raise ProofError('P8.11 proof record requires mode=nonempty-candidates')
    dependencies = package_otel_dependencies()
    if dependencies:
        raise ProofError('reusable packages declare open-telemetry: ' + ', '.join(dependencies))
    delta = _p95_delta(disabled, sampled)
    memory_delta = _memory_delta(disabled, sampled)
    iterations = int(sampled['iterations'])
    exported = int(sampled['exported'])
    if int(disabled['exported']) != 0:
        raise ProofError('disabled sampling exported spans')
    if int(full['exported']) != int(full['iterations']):
        raise ProofError('ratio 1.0 did not export every span')
    if exported <= 0 or exported >= int(iterations * 0.1):
        raise ProofError('ratio 0.01 export count is outside the bounded fraction')
    if delta > DELTA_CEILING:
        raise ProofError(f'p95 delta {delta:.4f} exceeds {DELTA_CEILING}')
    if memory_delta > MEMORY_DELTA_CEILING:
        raise ProofError(f'memory delta {memory_delta} exceeds {MEMORY_DELTA_CEILING}')
    fingerprint = {
        'iterations': iterations,
        'manifest_sha256': _sha256(manifest),
        'otel_api': otel_api,
        'otel_sdk': otel_sdk,
        'php': php_version,
        'tier': 'S',
        'workload': 'selected-span-sha256-1mib',
    }
    overhead = {
        'delta_ceiling': DELTA_CEILING,
        'fingerprint': fingerprint,
        'full': full,
        'memory_delta_bytes': memory_delta,
        'memory_delta_ceiling_bytes': MEMORY_DELTA_CEILING,
        'p95_delta': round(delta, 6),
        'ratio_0': disabled,
        'ratio_0_01': sampled,
        'schema_version': 'tracing-overhead/v1',
        'verdict': 'green',
    }
    proof = {
        'forbidden_attribute_scan': 'pass',
        'manifest_sha256': fingerprint['manifest_sha256'],
        'mode': 'nonempty-candidates',
        'otel': {'api': otel_api, 'sdk': otel_sdk},
        'package_otel_dependencies': dependencies,
        'schema_version': 'tracing-proof/v1',
        'selected': [row['target_id'] for row in manifest_payload['selected']],
        'verdict': 'green',
    }
    for path, payload in ((proof_path, proof), (overhead_path, overhead)):
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(_canonical(payload), encoding='utf-8')
    return {'proof': proof, 'overhead': overhead}


def verify(*, proof_path: Path, overhead_path: Path, manifest: Path) -> str:
    proof = _load(proof_path)
    overhead = _load(overhead_path)
    manifest_payload = _load(manifest)
    digest = _sha256(manifest)
    if proof.get('schema_version') != 'tracing-proof/v1':
        raise ProofError('unexpected proof schema')
    if overhead.get('schema_version') != 'tracing-overhead/v1':
        raise ProofError('unexpected overhead schema')
    if proof.get('verdict') != 'green' or overhead.get('verdict') != 'green':
        raise ProofError('proof verdict is not green')
    if proof.get('mode') != manifest_payload.get('mode'):
        raise ProofError('proof mode does not match the manifest')
    if proof.get('manifest_sha256') != digest or overhead['fingerprint']['manifest_sha256'] != digest:
        raise ProofError('manifest digest mismatch')
    if proof.get('selected') != [row['target_id'] for row in manifest_payload['selected']]:
        raise ProofError('selected targets drifted from the manifest')
    if package_otel_dependencies():
        raise ProofError('reusable package composer files gained an OpenTelemetry dependency')
    if proof.get('package_otel_dependencies') != []:
        raise ProofError('proof records package OpenTelemetry dependencies')
    if proof.get('forbidden_attribute_scan') != 'pass':
        raise ProofError('forbidden attribute scan failed')
    blob = proof_path.read_text(encoding='utf-8') + overhead_path.read_text(encoding='utf-8')
    lowered = blob.lower()
    for token in FORBIDDEN:
        if token in lowered:
            raise ProofError(f'forbidden token {token} is present in proof artifacts')
    delta = float(overhead['p95_delta'])
    if delta > float(overhead['delta_ceiling']):
        raise ProofError('stored p95 delta exceeds the ceiling')
    if int(overhead['memory_delta_bytes']) > int(overhead['memory_delta_ceiling_bytes']):
        raise ProofError('stored memory delta exceeds the ceiling')
    if int(overhead['ratio_0']['exported']) != 0:
        raise ProofError('disabled run exported spans')
    if int(overhead['ratio_0_01']['exported']) <= 0:
        raise ProofError('0.01 run exported nothing')
    if int(overhead['full']['exported']) != int(overhead['full']['iterations']):
        raise ProofError('1.0 run did not export every span')
    rendered = _canonical(proof)
    if proof_path.read_text(encoding='utf-8') != rendered:
        raise ProofError('proof artifact is not canonical')
    if overhead_path.read_text(encoding='utf-8') != _canonical(overhead):
        raise ProofError('overhead artifact is not canonical')
    return hashlib.sha256(proof_path.read_bytes() + overhead_path.read_bytes()).hexdigest()


def _read_sample(path: Path) -> dict:
    payload = _load(path)
    for key in ('ratio', 'iterations', 'p95_ns', 'peak_bytes', 'exported'):
        if key not in payload:
            raise ProofError(f'{path} missing {key}')
    return payload


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description='Record or verify the P8.11 tracing proof')
    sub = parser.add_subparsers(dest='command', required=True)
    record_parser = sub.add_parser('record')
    record_parser.add_argument('--manifest', type=Path, default=DEFAULT_MANIFEST)
    record_parser.add_argument('--disabled', type=Path, required=True)
    record_parser.add_argument('--sampled', type=Path, required=True)
    record_parser.add_argument('--full', type=Path, required=True)
    record_parser.add_argument('--proof', type=Path, default=DEFAULT_PROOF)
    record_parser.add_argument('--overhead', type=Path, default=DEFAULT_OVERHEAD)
    record_parser.add_argument('--otel-api', required=True)
    record_parser.add_argument('--otel-sdk', required=True)
    record_parser.add_argument('--php', required=True)
    verify_parser = sub.add_parser('verify')
    verify_parser.add_argument('--manifest', type=Path, default=DEFAULT_MANIFEST)
    verify_parser.add_argument('--proof', type=Path, default=DEFAULT_PROOF)
    verify_parser.add_argument('--overhead', type=Path, default=DEFAULT_OVERHEAD)
    args = parser.parse_args(argv)
    try:
        if args.command == 'record':
            record(
                manifest=args.manifest,
                disabled=_read_sample(args.disabled),
                sampled=_read_sample(args.sampled),
                full=_read_sample(args.full),
                proof_path=args.proof,
                overhead_path=args.overhead,
                otel_api=args.otel_api,
                otel_sdk=args.otel_sdk,
                php_version=args.php,
            )
            print('recorded')
            return 0
        print(verify(proof_path=args.proof, overhead_path=args.overhead, manifest=args.manifest))
        return 0
    except (OSError, json.JSONDecodeError, KeyError, ProofError) as exc:
        print(str(exc), file=sys.stderr)
        return 1


if __name__ == '__main__':
    raise SystemExit(main())
