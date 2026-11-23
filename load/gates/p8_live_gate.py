#!/usr/bin/env python3
"""P8.7 live-gate artifact builder and verifier.

Builds and checks the four immutable artifacts under plans/archive/VLT-LOAD/artifacts/P8.7:
run-manifest, metric-matrix, gate-matrix, overhead. Raw UUIDs/secrets are rejected.
"""

from __future__ import annotations

import argparse
import hashlib
import json
import re
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).parent))
from _slo_table import EXPECTED_CLASS_A_ROWS, EXPECTED_ROW_COUNT, KNOWN_RED_IDS, parse_rows  # noqa: E402

REPO_ROOT = Path(__file__).resolve().parents[2]
CATALOG = REPO_ROOT / 'load' / 'app' / 'Observability' / 'MetricFamilyCatalog.php'
SLO_GATES_MD = REPO_ROOT / 'plans' / 'archive' / 'VLT-LOAD' / 'slo-gates.md'
DELTA = 0.10
EXPECTED_FAMILIES = 47
UUID_RE = re.compile(r'\b[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\b', re.I)
SECRET_RE = re.compile(r'(vaulter-load-secret|MINIO_ROOT_PASSWORD|POSTGRES_PASSWORD|password\s*[:=])', re.I)
TYPE_RE = re.compile(r'^# TYPE (vaulter_[a-z0-9_]+) (counter|gauge|histogram)\s*$', re.M)
SAMPLE_RE = re.compile(r'^(vaulter_[a-z0-9_]+)(\{[^}]*\})?\s')
TIER_RE = re.compile(r'tier(?:=\"|:)(S0|S|M|L)')
ALLOWED_CLASS_A = {'pass', 'known-red', 'product-red'}
ALLOWED_SOURCES = {'promql', 'k6'}
ARTIFACT_NAMES = ('run-manifest.json', 'metric-matrix.json', 'gate-matrix.json', 'overhead.json')

TRIGGER_CLASS = {
    'vaulter_http_request_duration_seconds': 'HTTP happy-path',
    'vaulter_tree_query_duration_seconds': 'HTTP happy-path',
    'vaulter_tree_cte_anchors': 'HTTP happy-path',
    'vaulter_subtree_nodes_touched': 'HTTP happy-path',
    'vaulter_activity_feed_duration_seconds': 'HTTP happy-path',
    'vaulter_activity_write_total': 'HTTP happy-path',
    'vaulter_export_duration_seconds': 'HTTP happy-path',
    'vaulter_broadcast_queue_wait_seconds': 'Host gauges',
    'vaulter_upload_session_active': 'Host gauges',
    'vaulter_request_peak_memory_bytes': 'Host gauges',
    'vaulter_upload_bytes_total': 'HTTP happy-path',
    'vaulter_upload_chunk_duration_seconds': 'HTTP happy-path',
    'vaulter_upload_conflict_total': 'Error/conflict',
    'vaulter_assemble_bytes_total': 'HTTP happy-path',
    'vaulter_assemble_duration_seconds': 'HTTP happy-path',
    'vaulter_download_bytes_total': 'HTTP happy-path',
    'vaulter_download_stream_active': 'FPM streams',
    'vaulter_download_ttfb_seconds': 'HTTP happy-path',
    'vaulter_throttled_total': 'Error/conflict',
    'vaulter_outbox_backlog': 'Host gauges',
    'vaulter_outbox_stage_duration_seconds': 'Background',
    'vaulter_outbox_relay_duration_seconds': 'Background',
    'vaulter_outbox_relayed_total': 'Background',
    'vaulter_outbox_endpoints_scanned_total': 'Background',
    'vaulter_webhook_queue_wait_seconds': 'Background',
    'vaulter_webhook_delivery_duration_seconds': 'Background',
    'vaulter_webhook_delivered_total': 'Background',
    'vaulter_failed_jobs_total': 'Error/conflict',
    'vaulter_webhook_retry_tick_seconds': 'Background',
    'vaulter_webhook_retry_lockset_rows': 'Background',
    'vaulter_sweep_tick_seconds': 'Background',
    'vaulter_sweep_batch_rows': 'Background',
    'vaulter_sweep_expired_total': 'Background',
    'vaulter_sweep_skipped_total': 'Background',
    'vaulter_gc_duration_seconds': 'Background',
    'vaulter_gc_batch_rows': 'Background',
    'vaulter_gc_rows_scanned_total': 'Background',
    'vaulter_gc_objects_deleted_total': 'Background',
    'vaulter_purge_duration_seconds': 'Background',
    'vaulter_purge_batch_nodes': 'Background',
    'vaulter_purge_nodes_total': 'Background',
    'vaulter_prune_duration_seconds': 'Background',
    'vaulter_prune_rows_total': 'Background',
    'vaulter_reconcile_duration_seconds': 'Background',
    'vaulter_reconcile_objects_total': 'Background',
    'vaulter_command_peak_memory_bytes': 'Background',
    'vaulter_pg_lock_wait_seconds': 'Error/conflict',
}


class GateError(ValueError):
    def __init__(self, code: str, message: str):
        super().__init__(f'{code}: {message}')
        self.code = code


def sha256_text(value: str) -> str:
    return hashlib.sha256(value.encode('utf-8')).hexdigest()


def load_json(path: Path):
    return json.loads(path.read_text(encoding='utf-8'))


def dump_json(path: Path, payload) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(payload, ensure_ascii=False, indent=2, sort_keys=True) + '\n', encoding='utf-8')


def expected_families(catalog: Path = CATALOG) -> dict[str, str]:
    names = re.findall(r"'(vaulter_[a-z0-9_]+)' => \['type' => '([a-z]+)'", catalog.read_text(encoding='utf-8'))
    families = dict(names)
    if len(families) != EXPECTED_FAMILIES:
        raise GateError('CATALOG_PARITY', f'expected {EXPECTED_FAMILIES} families, found {len(families)}')
    return families


def parse_exposition(text: str) -> dict[str, dict]:
    types = {name: kind for name, kind in TYPE_RE.findall(text)}
    samples: dict[str, list[str]] = {}
    for line in text.splitlines():
        match = SAMPLE_RE.match(line)
        if not match:
            continue
        samples.setdefault(match.group(1), []).append(line)
    families = {}
    for name, kind in types.items():
        related = []
        if kind == 'histogram':
            related = [key for key in (f'{name}_bucket', f'{name}_sum', f'{name}_count') if key in samples]
        else:
            related = [name] if name in samples else []
        families[name] = {
            'type': kind,
            'series_present': bool(related),
            'sample_count': sum(len(samples.get(key, [])) for key in related),
        }
    return families


def run_id_digest(run_id: str) -> str:
    if UUID_RE.search(run_id) or len(run_id) < 8:
        return sha256_text(run_id)
    return sha256_text(run_id)


def env_fingerprint(env_file: Path, image_versions: str, dataset_id: str) -> str:
    payload = env_file.read_bytes() + f'{image_versions} {dataset_id}'.encode('utf-8')
    return hashlib.sha256(payload).hexdigest()[:16]


def secret_hits(blob: str) -> list[str]:
    hits = [match.group(0) for match in UUID_RE.finditer(blob)]
    hits.extend(match.group(0) for match in SECRET_RE.finditer(blob))
    return hits


def scan_secrets(directory: Path) -> None:
    hits = []
    for name in ARTIFACT_NAMES:
        path = directory / name
        if not path.is_file():
            continue
        found = secret_hits(path.read_text(encoding='utf-8'))
        if found:
            hits.append(f'{path.name}: {found[:3]}')
    if hits:
        raise GateError('SECRET_LEAK', '; '.join(hits))


def row_tier(row: dict) -> str | None:
    blob = ' '.join(row.get(key, '') for key in ('k6_metric', 'expr', 'prom_metric'))
    match = TIER_RE.search(blob)
    return match.group(1) if match else None


def applicable(row: dict, *, profile: str, tier: str) -> bool:
    if row['section'] == '3.3' and profile not in {'regression', 'lab'}:
        return False
    declared = row_tier(row)
    if declared and declared != tier:
        return False
    return True


def class_a_status(text: str) -> str:
    if text.startswith('known-red'):
        return 'known-red'
    if text.startswith('fail'):
        return 'product-red'
    if text.startswith('pass'):
        return 'pass'
    if text.startswith('not-evaluated'):
        return 'not-evaluated'
    if text.startswith('not-checkable'):
        return 'not-checkable'
    return 'not-checkable'


def build_metric_matrix(*, families: dict[str, str], scraped: dict[str, dict], triggers: dict[str, dict], job='vaulter-sut') -> dict:
    rows = []
    for name, kind in sorted(families.items()):
        scrape = scraped.get(name) or {}
        trigger = triggers.get(name) or {}
        present = bool(scrape.get('series_present'))
        zero_ok = bool(trigger.get('zero_ok'))
        if not present:
            status = 'missing'
        elif scrape.get('sample_count', 0) == 0 and not zero_ok:
            status = 'zero-unproven'
        else:
            status = 'present'
        if trigger.get('ran') and scrape.get('type') == kind:
            type_ok = True
        else:
            type_ok = scrape.get('type') == kind if present else False
        rows.append({
            'name': name,
            'type': kind,
            'trigger_class': TRIGGER_CLASS[name],
            'trigger': {
                'ran': bool(trigger.get('ran')),
                'command': trigger.get('command', ''),
                'zero_ok': zero_ok,
            },
            'scrape': {
                'job': job,
                'present': present,
                'type_ok': type_ok,
                'stale': bool(scrape.get('stale', False)),
                'status': status,
            },
        })
    missing = [row['name'] for row in rows if row['scrape']['status'] != 'present']
    stale = [row['name'] for row in rows if row['scrape']['stale']]
    untriggered = [row['name'] for row in rows if not row['trigger']['ran']]
    verdict = 'green'
    if missing or stale or untriggered or any(not row['scrape']['type_ok'] for row in rows):
        verdict = 'evidence-invalid'
    return {
        'expected': EXPECTED_FAMILIES,
        'observed': sum(1 for row in rows if row['scrape']['present']),
        'verdict': verdict,
        'families': rows,
    }


def build_gate_matrix(*, rows: list[dict], verdicts: dict[str, dict], profile: str, tier: str) -> dict:
    out = []
    for row in rows:
        record = verdicts.get(row['id'], {})
        source = 'promql' if row['class'] == 'A' else 'k6'
        applies = applicable(row, profile=profile, tier=tier)
        status = record.get('status', 'not-checkable')
        if row['class'] == 'A' and row['id'] in KNOWN_RED_IDS and status in {'fail', 'pass', 'known-red'}:
            status = 'known-red'
        if row['class'] == 'A' and status == 'fail':
            status = 'product-red'
        out.append({
            'id': row['id'],
            'class': row['class'],
            'source': source,
            'applicable': applies,
            'status': status,
            'detail': record.get('detail', ''),
        })
    invalid = [
        item for item in out
        if item['applicable'] and (
            item['source'] not in ALLOWED_SOURCES
            or (item['class'] == 'A' and item['status'] not in ALLOWED_CLASS_A)
            or (item['class'] == 'B' and item['status'] in {'not-checkable', 'not-evaluated'})
        )
    ]
    return {
        'profile': profile,
        'tier': tier,
        'rows': EXPECTED_ROW_COUNT,
        'class_a': EXPECTED_CLASS_A_ROWS,
        'verdict': 'evidence-invalid' if invalid else 'green',
        'invalid': [item['id'] for item in invalid],
        'items': out,
    }


def build_overhead(*, on: dict, off: dict, ceilings: dict) -> dict:
    mismatches = []
    for key in ('env_fingerprint', 'dataset_id', 'image_versions', 'cpuset'):
        if on.get(key) != off.get(key):
            mismatches.append(key)
    latency_on = float(on['http_p95_ms'])
    latency_off = float(off['http_p95_ms'])
    rss_on = int(on['app_rss_bytes'])
    rss_off = int(off['app_rss_bytes'])
    latency_delta = 0.0 if latency_off == 0 else (latency_on - latency_off) / latency_off
    rss_ceiling = int(ceilings.get('app_rss_bytes', 67_108_864))
    verdict = 'green'
    if mismatches:
        verdict = 'evidence-invalid'
    elif latency_delta > DELTA or rss_on > rss_ceiling:
        verdict = 'red'
    return {
        'delta': DELTA,
        'fingerprint_equal': not mismatches,
        'mismatches': mismatches,
        'latency_delta': latency_delta,
        'rss_on_bytes': rss_on,
        'rss_off_bytes': rss_off,
        'rss_ceiling_bytes': rss_ceiling,
        'on': {key: on[key] for key in ('env_fingerprint', 'dataset_id', 'image_versions')},
        'off': {key: off[key] for key in ('env_fingerprint', 'dataset_id', 'image_versions')},
        'verdict': verdict,
    }


def build_manifest(**fields) -> dict:
    required = (
        'run_id_sha256', 'tier', 'profile', 'dataset_id', 'env_fingerprint',
        'image_versions', 'prometheus_job', 'metrics_enabled',
    )
    missing = [key for key in required if not fields.get(key)]
    if missing:
        raise GateError('MANIFEST_INCOMPLETE', ','.join(missing))
    fields.setdefault('rejected_attempts', [])
    return fields


def verify_artifacts(directory: Path) -> dict:
    scan_secrets(directory)
    artifacts = {name: load_json(directory / name) for name in ARTIFACT_NAMES}
    families = expected_families()
    matrix = artifacts['metric-matrix.json']
    if matrix.get('expected') != EXPECTED_FAMILIES or len(matrix.get('families', [])) != EXPECTED_FAMILIES:
        raise GateError('MATRIX_COUNT', f'metric matrix is not {EXPECTED_FAMILIES}/{EXPECTED_FAMILIES}')
    names = [row['name'] for row in matrix['families']]
    if names != sorted(families):
        raise GateError('MATRIX_NAMES', 'metric matrix names drift from catalog')
    if matrix.get('verdict') != 'green':
        raise GateError('MATRIX_INVALID', str(matrix.get('verdict')))
    for row in matrix['families']:
        if row['name'] not in families:
            raise GateError('MATRIX_UNKNOWN', row['name'])
        if not row['trigger']['ran']:
            raise GateError('TRIGGER_MISSING', row['name'])
        if row['scrape'].get('stale') or row['scrape'].get('job') != 'vaulter-sut':
            raise GateError('SCRAPE_INVALID', row['name'])
        if row['scrape']['status'] != 'present' or not row['scrape']['type_ok']:
            raise GateError('SCRAPE_INVALID', row['name'])

    gate = artifacts['gate-matrix.json']
    if gate.get('rows') != EXPECTED_ROW_COUNT or len(gate.get('items', [])) != EXPECTED_ROW_COUNT:
        raise GateError('GATE_COUNT', 'gate matrix is not 96 rows')
    if gate.get('verdict') != 'green' or gate.get('invalid'):
        raise GateError('GATE_INVALID', ','.join(gate.get('invalid') or ['verdict']))
    sources = {item['source'] for item in gate['items']}
    if sources != ALLOWED_SOURCES:
        raise GateError('GATE_SOURCE', f'sources={sorted(sources)}')

    overhead = artifacts['overhead.json']
    if overhead.get('verdict') == 'evidence-invalid':
        raise GateError('OVERHEAD_INVALID', ','.join(overhead.get('mismatches') or ['fingerprint']))
    if overhead.get('verdict') != 'green':
        raise GateError('OVERHEAD_RED', f"latency_delta={overhead.get('latency_delta')}")

    manifest = artifacts['run-manifest.json']
    for key in ('run_id_sha256', 'dataset_id', 'env_fingerprint'):
        if not manifest.get(key):
            raise GateError('MANIFEST_INCOMPLETE', key)
    if UUID_RE.search(json.dumps(manifest)):
        raise GateError('SECRET_LEAK', 'run-manifest contains a raw UUID')

    return {'status': 'green', 'artifacts': str(directory)}


def collect_verdicts(*, summary_path: Path, prometheus_url: str, profile: str,
                     pm_max_children: int, baseline_path: Path | None) -> dict:
    spec = __import__('importlib.util').util.spec_from_file_location(
        'perf_assert', Path(__file__).with_name('perf-assert.py'))
    perf_assert = __import__('importlib.util').util.module_from_spec(spec)
    spec.loader.exec_module(perf_assert)
    from promql_map import build_promql_map, instantiate_windows

    summary = load_json(summary_path)
    baseline = load_json(baseline_path) if baseline_path and baseline_path.exists() else None
    rows = parse_rows(str(SLO_GATES_MD))
    duration = summary.get('state', {}).get('testRunDurationMs', 0) / 1_000
    records = instantiate_windows(build_promql_map(rows, pm_max_children), duration)
    client = perf_assert.HttpPrometheusClient(prometheus_url) if prometheus_url else None
    verdicts = {}
    for row in rows:
        applies_here = row['section'] != '3.3' or profile in ('regression', 'lab')
        if not applies_here:
            verdicts[row['id']] = {'status': 'not-applicable', 'detail': 'section 3.3'}
            continue
        if row['class'] == 'B':
            text, _ = perf_assert.class_b_verdict(row, summary, baseline, profile)
        else:
            text, _ = perf_assert.class_a_verdict(row, records, client)
        verdicts[row['id']] = {'status': class_a_status(text) if row['class'] == 'A' else text.split(' ', 1)[0], 'detail': text}
    return verdicts


def main(argv=None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    sub = parser.add_subparsers(dest='command', required=True)
    verify = sub.add_parser('verify')
    verify.add_argument('--artifacts', required=True, type=Path)
    families = sub.add_parser('families')
    families.add_argument('--catalog', type=Path, default=CATALOG)
    metric = sub.add_parser('metric-matrix')
    metric.add_argument('--exposition', required=True, type=Path)
    metric.add_argument('--triggers', required=True, type=Path)
    metric.add_argument('--out', required=True, type=Path)
    gate = sub.add_parser('gate-matrix')
    gate.add_argument('--verdicts', required=True, type=Path)
    gate.add_argument('--profile', required=True)
    gate.add_argument('--tier', required=True)
    gate.add_argument('--out', required=True, type=Path)
    overhead = sub.add_parser('overhead')
    overhead.add_argument('--on', required=True, type=Path)
    overhead.add_argument('--off', required=True, type=Path)
    overhead.add_argument('--out', required=True, type=Path)
    overhead.add_argument('--rss-ceiling', type=int, default=67_108_864)
    manifest = sub.add_parser('manifest')
    manifest.add_argument('--out', required=True, type=Path)
    manifest.add_argument('--fields', required=True, type=Path)
    verdicts = sub.add_parser('verdicts')
    verdicts.add_argument('--summary', required=True, type=Path)
    verdicts.add_argument('--prometheus-url', required=True)
    verdicts.add_argument('--profile', required=True)
    verdicts.add_argument('--pm-max-children', type=int, default=4)
    verdicts.add_argument('--baseline', type=Path)
    verdicts.add_argument('--out', required=True, type=Path)
    args = parser.parse_args(argv)

    try:
        if args.command == 'verify':
            result = verify_artifacts(args.artifacts)
            print(json.dumps(result, ensure_ascii=False, sort_keys=True))
            return 0
        if args.command == 'families':
            print(json.dumps(expected_families(args.catalog), ensure_ascii=False, sort_keys=True))
            return 0
        if args.command == 'metric-matrix':
            families_map = expected_families()
            scraped = parse_exposition(args.exposition.read_text(encoding='utf-8'))
            triggers = load_json(args.triggers)
            dump_json(args.out, build_metric_matrix(families=families_map, scraped=scraped, triggers=triggers))
            return 0
        if args.command == 'gate-matrix':
            rows = parse_rows(str(SLO_GATES_MD))
            dump_json(args.out, build_gate_matrix(
                rows=rows, verdicts=load_json(args.verdicts),
                profile=args.profile, tier=args.tier,
            ))
            return 0
        if args.command == 'overhead':
            dump_json(args.out, build_overhead(
                on=load_json(args.on), off=load_json(args.off),
                ceilings={'app_rss_bytes': args.rss_ceiling},
            ))
            return 0
        if args.command == 'verdicts':
            dump_json(args.out, collect_verdicts(
                summary_path=args.summary,
                prometheus_url=args.prometheus_url,
                profile=args.profile,
                pm_max_children=args.pm_max_children,
                baseline_path=args.baseline,
            ))
            return 0
        dump_json(args.out, build_manifest(**load_json(args.fields)))
        return 0
    except GateError as exc:
        print(json.dumps({'status': 'evidence-invalid', 'error': str(exc)}, ensure_ascii=False), file=sys.stderr)
        return 2


if __name__ == '__main__':
    sys.exit(main())
