#!/usr/bin/env python3
"""Read-only runtime checks and in-memory verify_v2 negative controls.

The historical run has no working memory seam. A positive observed gauge is
overlaid ONLY to isolate verifier controls; this is not a repaired historical run.
"""
import hashlib
import json
from pathlib import Path
import re
import sys
import tarfile
from unittest.mock import patch

REPO = Path(__file__).resolve().parents[3]
sys.path.insert(0, str(REPO / 'load/gates'))
import p6_evidence as ev
import p6_live_matrix as live

MEMORY = 'vaulter_command_peak_memory_bytes'
RELAY = 'vaulter_outbox_relayed_total'
OLD = REPO / 'plans/VLT-LOAD/artifacts/P6-R09/bc199ced-5b0f-4e77-b5ca-1a73055c0bfa'
OLD_DIGEST = 'bbfa77d90edc2664a158b37aa7cab0a8a936c7af8bfe90cefac3e745a184a0e6'


def completed_uuids(rows, run_id, cell):
    """Independent primary-ledger count, without ev.lifecycle()."""
    jobs = {}
    for r in rows:
        if (r['run_id'], r['cell_id'], r['attempt'], r['class']) != (run_id, cell, 1, ev.WEBHOOK):
            continue
        jobs.setdefault(r['uuid'], {}).setdefault(r['source'], []).append(r['monotonic_ns'])
    assert jobs
    for sources in jobs.values():
        assert all(len(sources.get(k, [])) == 1 for k in ('queueing', 'queued', 'processing', 'processed'))
        assert sources['queued'][0] >= sources['queueing'][0]
        assert sources['processing'][0] >= sources['queueing'][0]
        assert sources['processed'][0] >= sources['processing'][0]
    return set(jobs)


def read_json(path):
    return json.loads(path.read_text())


def sample(body, name, value):
    return re.sub(r'^(' + re.escape(name) + r'(?:\{[^\n]*\})?)\s+[^\n]+$',
                  lambda m: m[1] + ' ' + str(value), body, flags=re.M)


def scalar(value):
    return json.dumps({'status': 'success', 'data': {'resultType': 'vector',
                       'result': [{'metric': {}, 'value': [0, str(value)]}]}})


def verify_runtime(root):
    manifest = read_json(root / 'run-manifest.json')
    run_id = manifest['run_id']
    assert manifest['schema'] == 'p6-b02-memory-seam/v1'
    assert not (root / 'rejected.json').exists()
    volume = read_json(root / 'database/tier-s-volume.json')
    ev.validate_volume(volume)
    rows = live.ledger_slice(root / 'queue/lifecycle.jsonl', 0)
    sink = live.ledger_slice(root / 'sink/ledger.jsonl', 0)
    assert all(r['run_id'] == run_id for r in rows + sink)
    for plane, count in (('relay', 10000), ('retry', 5000)):
        assert live.ledger_slice(root / f'database/volume-{plane}-ticks.jsonl', 0) == volume[f'{plane}_ticks']
        joined = ev.lifecycle(ev.window(rows, run_id, 'volume-' + plane, 1))
        assert joined == volume[plane + '_lifecycle']
        assert not joined['invalid_uuids']
        assert joined['queued'] == joined['processing'] == joined['processed'] == count
        assert len(completed_uuids(rows, run_id, 'volume-' + plane)) == count
        receives = ev.window(sink, run_id, 'volume-' + plane)
        assert len(receives) == len({(r['event_id'], r['slot']) for r in receives}) == count
    assert read_json(root / 'integrity-before.json') == read_json(root / 'integrity-after.json')
    assert read_json(root / 'database/failed-jobs-baseline.json') == read_json(root / 'database/failed-jobs-final.json')
    direct = ev.exposition_totals((root / 'prometheus/exposition.prom').read_text())
    scraped = {name: live.prometheus_scalar(read_json(root / 'prometheus' / (name + '.json')))
               for name in ev.P6_METRIC_NAMES}
    ev.validate_p6_metrics(direct, volume)
    ev.validate_p6_metrics(scraped, volume)
    assert all(direct[name] == scraped[name] for name in ev.P6_METRIC_NAMES)
    php_peak = max(t['peak_bytes'] for t in volume['retry_ticks'])
    assert 0 < direct[MEMORY] <= php_peak
    archive = root.parent / (root.name + '-runtime-source.tar.gz')
    with tarfile.open(archive, 'r:gz') as source:
        for name, expected in manifest['source_sha256'].items():
            assert hashlib.sha256(source.extractfile(name).read()).hexdigest() == expected
    return {'run_id': run_id, 'raw_tree_sha256': live.digest_tree(root),
            'runtime_archive_sha256': hashlib.sha256(archive.read_bytes()).hexdigest(),
            'scope': manifest['scope'], 'metric_totals': {name: direct[name] for name in ev.P6_METRIC_NAMES},
            'memory_gauge_bytes': direct[MEMORY], 'max_php_peak_bytes': php_peak,
            'max_retry_os_rss_bytes': max(t['rss_peak_bytes'] for t in volume['retry_ticks']),
            'volume_measurements': ev.volume_measurements(volume),
            'relay_completed': 10000, 'retry_completed': 5000, 'failed_jobs_delta': 0}


def controls(memory):
    assert live.digest_tree(OLD) == OLD_DIGEST
    manifest = read_json(OLD / 'run-manifest.json')
    verdicts = read_json(OLD / 'cell-verdicts.json')
    volume = read_json(OLD / 'database/tier-s-volume.json')
    rows = live.ledger_slice(OLD / 'queue/lifecycle.jsonl', 0)
    minimum = ev.confirmed_relay_rows(rows, manifest['run_id'])
    volume_uuids = completed_uuids(rows, manifest['run_id'], 'volume-relay')
    steady_uuids = completed_uuids(rows, manifest['run_id'], live.STEADY_CELL)
    assert not volume_uuids & steady_uuids
    assert len(volume_uuids) == 10000 and len(steady_uuids) == 36000
    assert minimum == len(volume_uuids | steady_uuids)
    assert minimum == 46000
    original_read = Path.read_text
    exposition_path = OLD / 'prometheus/exposition.prom'
    body = exposition_path.read_text()
    memory_path = OLD / 'prometheus' / (MEMORY + '.json')
    # Replace only the retry series; other command series remain historical zeros.
    positive = re.sub(r'^' + MEMORY + r'\{command="vaulter:retry-webhooks"\} .+$',
                      MEMORY + '{command="vaulter:retry-webhooks"} ' + str(memory), body, flags=re.M)
    base = {exposition_path: positive, memory_path: scalar(memory)}
    cases = []

    def check(name, overrides, rejected=True, overlay=True):
        files = {**(base if overlay else {}), **overrides}

        def read(path, *args, **kwargs):
            if path in files:
                if files[path] is None:
                    raise FileNotFoundError(str(path))
                return files[path]
            return original_read(path, *args, **kwargs)

        with patch.object(Path, 'read_text', read):
            try:
                live.verify_v2(OLD, manifest, verdicts, volume)
            except live.gate.EvidenceInvalid as exc:
                if not rejected:
                    raise
                cases.append({'control': name, 'result': 'rejected', 'reason': str(exc)})
            else:
                if rejected:
                    raise AssertionError('negative control accepted: ' + name)
                cases.append({'control': name, 'result': 'accepted'})

    check('unchanged historical run is not memory proof', {}, overlay=False)
    check('isolated positive baseline (memory overlay only)', {}, rejected=False)
    check('absent memory family', {exposition_path: '\n'.join(
        line for line in positive.splitlines() if not line.startswith(MEMORY))})
    check('other commands cannot cover absent retry memory', {exposition_path: '\n'.join(
        line for line in positive.splitlines() if not line.startswith(MEMORY + '{command="vaulter:retry-webhooks"}'))})
    check('zero memory direct and query', {exposition_path: sample(positive, MEMORY, 0), memory_path: scalar(0)})
    check('zero memory query with positive direct', {memory_path: scalar(0)})
    check('missing memory query', {memory_path: None})
    check('non-finite memory', {exposition_path: sample(positive, MEMORY, 'NaN')})
    relay_path = OLD / 'prometheus' / (RELAY + '.json')
    for value, rejected in ((0, True), (10000, True), (45999, True), (46000, False)):
        check('relay direct/query/legacy = ' + str(value), {
            exposition_path: sample(positive, RELAY, value), relay_path: scalar(value),
            OLD / 'prometheus/relayed.json': scalar(value)}, rejected=rejected)
    check('relay query only = volume 10000', {relay_path: scalar(10000)})
    # Retain the already-closed B02-02 regression checks without changing summaries.
    bad_volume = json.loads(json.dumps(volume))
    for tick in bad_volume['retry_ticks']:
        tick['elapsed_ns'] = 3_000_000_000
    try:
        ev.validate_volume(bad_volume)
    except ValueError as exc:
        cases.append({'control': 'B02-02 stale retry summary', 'result': 'rejected', 'reason': str(exc)})
    else:
        raise AssertionError('stale summary accepted')
    assert live.digest_tree(OLD) == OLD_DIGEST
    return {'historical_raw_tree_sha256': OLD_DIGEST, 'confirmed_volume_plus_steady': minimum,
            'independent_uuid_counts': {'volume': len(volume_uuids), 'steady': len(steady_uuids)},
            'overlay_is_test_only': True, 'controls': cases}


if __name__ == '__main__':
    runtime = verify_runtime(Path(sys.argv[1]).resolve())
    print(json.dumps({'runtime': runtime, 'verifier_controls': controls(runtime['memory_gauge_bytes'])}, indent=2))
