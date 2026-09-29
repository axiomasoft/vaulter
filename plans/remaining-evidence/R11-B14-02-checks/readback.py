#!/usr/bin/env python3
"""Replay B14-02 ordinary controls against current or read-only original source."""

import argparse
import json
import subprocess
import sys
import tempfile
from pathlib import Path

REPO = Path(__file__).resolve().parents[3]
GATES = REPO / 'load/gates'


def run(source_dir):
    sys.path.insert(0, str(GATES))
    sys.path.insert(0, str(source_dir))
    import p7_live_matrix as live
    import test_p7_evidence as fixture

    def missing(root):
        for name in live.gate.JSONL_FILES:
            fixture.write_rows(root / name, [row for row in fixture.rows(root / name)
                                            if row['cell_id'] == fixture.CELL and row['attempt'] == 1])

    def recovery(root):
        fixture.write_rows(root / 'readiness.jsonl', [row for row in fixture.rows(root / 'readiness.jsonl')
                                                    if row['phase'] == 'baseline'])

    def attempt(root):
        path = root / f'attempts/{fixture.CELL}.attempt-2.json'
        row = fixture.read(path)
        row.update(cell_id='wrong-cell', attempt=99, evidence='invalid')
        fixture.write(path, row)

    def checksum(root):
        path = root / 'objects.jsonl'
        values = fixture.rows(path)
        values[0]['checksum'] = 'c' * 64
        fixture.write_rows(path, values)

    def prometheus(root):
        for path in (root / 'prometheus').iterdir():
            value = fixture.read(path)
            value['data']['result'][0]['metric']['job'] = 'prometheus'
            fixture.write(path, value)

    outcomes = []
    for label, change in (('missing-76-attempt-streams', missing), ('baseline-only-recovery', recovery),
                          ('foreign-attempt-identity-and-invalid-verdict', attempt),
                          ('object-checksum-disagrees-with-database', checksum), ('wrong-prometheus-job', prometheus)):
        with tempfile.TemporaryDirectory(prefix='p7-b14-replay-') as tmp:
            root = Path(tmp)
            fixture.build(root)
            before = live.digest_tree(root)
            assert live.verify_artifacts(root) == before
            assert live.digest_tree(root) == before
            change(root)
            try:
                live.verify_artifacts(root)
                outcomes.append({'control': label, 'admission': 'ACCEPTED'})
            except live.gate.EvidenceInvalid as exc:
                outcomes.append({'control': label, 'admission': 'REJECTED', 'reason': str(exc)})
    return outcomes


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--original-ref')
    args = parser.parse_args()
    if args.original_ref:
        with tempfile.TemporaryDirectory(prefix='p7-original-source-') as tmp:
            gates = Path(tmp) / 'load/gates'
            gates.mkdir(parents=True)
            for name in ('p7_live_matrix.py', 'p7_chaos.py', 'p7_injectors.py', 'p7_product_ops.py'):
                raw = subprocess.check_output(['git', 'show', f'{args.original_ref}:load/gates/{name}'], cwd=REPO)
                (gates / name).write_bytes(raw)
            results = run(gates)
        assert all(row['admission'] == 'ACCEPTED' for row in results), results
        kind = 'original false admissions reproduced; expected RED'
    else:
        results = run(GATES)
        assert all(row['admission'] == 'REJECTED' for row in results), results
        kind = 'current ordinary controls; all rejected'
    print(json.dumps({'source': args.original_ref or 'current', 'result': kind,
                      'positive_fixture': '51 cells / 77 attempts admitted byte-stably', 'controls': results}, indent=2))


if __name__ == '__main__':
    main()
