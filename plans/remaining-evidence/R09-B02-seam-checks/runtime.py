#!/usr/bin/env python3
"""Scoped B02-01 runtime: real tier-S volume, no new matrix/SLO admission.

Run from the repository root with python3 -B. Refuses nonempty evidence and stand.
Uses the existing disposable stand only; never recreates containers or migrates.
"""
import json
from pathlib import Path
import sys
import tarfile
import urllib.request
import uuid

REPO = Path(__file__).resolve().parents[3]
sys.path.insert(0, str(REPO / 'load/gates'))
import p6_live_matrix as live
import p6_evidence as ev


def run(out):
    if out.exists():
        raise ValueError('refusing to overwrite evidence')
    before = live.counts_checksum()
    if any(before[k] for k in ('outbox', 'endpoints', 'deliveries')) or any(
            any(d.values()) for d in live.redis_depth().values()):
        raise ValueError('stand is not empty; existing data preserved')
    out.mkdir(parents=True)
    for name in ('database', 'queue', 'sink', 'prometheus'):
        (out / name).mkdir()
    run_id = out.name
    uuid.UUID(run_id)
    saved = {p: p.read_bytes() if p.exists() else None
             for p in (live.LEDGER, live.SINK_LEDGER, live.CONTEXT)}
    prior = out.parent / (run_id + '-prior-ledgers')
    prior.mkdir()
    for path, body in saved.items():
        if body is not None:
            (prior / path.name).write_bytes(body)
    manifest = live.preflight(out, run_id)
    manifest['schema'] = 'p6-b02-memory-seam/v1'
    manifest['scope'] = '10k relay / 5k retry volume only; no steady or SLO verdict'
    (out / 'run-manifest.json').write_text(json.dumps(manifest, indent=2, sort_keys=True) + '\n')
    archive = out.parent / (run_id + '-runtime-source.tar.gz')
    with tarfile.open(archive, 'x:gz') as target:
        for name in manifest['source_sha256']:
            target.add(REPO / name, arcname=name)
        target.add(Path(__file__), arcname=str(Path(__file__).relative_to(REPO)))
    (out / 'integrity-before.json').write_text(json.dumps(before, sort_keys=True))
    failed_before = live.failures()
    (out / 'database/failed-jobs-baseline.json').write_text(json.dumps(failed_before, indent=2))
    try:
        for path in (live.LEDGER, live.SINK_LEDGER):
            path.write_bytes(b'')
        live.metrics_open(run_id)
        print('scoped runtime: 10k relay / 5k retry', flush=True)
        volume = live.run_volume(out, run_id, manifest['config_fingerprint'])
        (out / 'database/tier-s-volume.json').write_text(json.dumps(volume, indent=2) + '\n')
        ev.validate_volume(volume)
        if not live.wait_until(lambda: live.p6_metrics_reached(volume), 20):
            raise ValueError('volume metrics did not reach Prometheus')
        for name, query in live.P6_PROMETHEUS_QUERIES.items():
            (out / 'prometheus' / (name + '.json')).write_text(
                json.dumps(live.query_prometheus(query), indent=2) + '\n')
        with urllib.request.urlopen(live.APP_URL + '/metrics?run_id=' + run_id, timeout=10) as response:
            (out / 'prometheus/exposition.prom').write_bytes(response.read())
        (out / 'queue/lifecycle.jsonl').write_bytes(live.LEDGER.read_bytes())
        (out / 'sink/ledger.jsonl').write_bytes(live.SINK_LEDGER.read_bytes())
        (out / 'database/failed-jobs-final.json').write_text(json.dumps(live.failures(), indent=2))
        live.clean_cell(run_id)
        live.set_workers(True)
        (out / 'integrity-after.json').write_text(json.dumps(live.counts_checksum(), sort_keys=True))
        if live.secret_scan(out):
            raise ValueError('raw secret scan failed')
        print('runtime captured: ' + str(out), flush=True)
    except Exception as exc:
        (out / 'rejected.json').write_text(json.dumps({'reason': str(exc)}, indent=2))
        raise
    finally:
        # Restore preexisting capture carriers; raw evidence above remains immutable.
        for path, body in saved.items():
            if body is not None:
                path.write_bytes(body)
            elif path.exists():
                path.unlink()


if __name__ == '__main__':
    run(Path(sys.argv[1]).resolve())
