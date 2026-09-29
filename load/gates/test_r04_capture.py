"""Execute the real capture shell with isolated fake services; never run Docker."""

import json
import os
import subprocess
import tempfile
import unittest
from pathlib import Path

CAPTURE = Path(__file__).resolve().parents[1] / 'scripts/p3-capture-workload.sh'

FAKE = r'''#!/usr/bin/env python3
import fcntl, json, os, pathlib, sys, time
root = pathlib.Path(os.environ['FAKE_ROOT'])
args = sys.argv[1:]
name = pathlib.Path(sys.argv[0]).name
with (root / 'calls.jsonl').open('a') as log:
    fcntl.flock(log, fcntl.LOCK_EX)
    log.write(json.dumps([name, args]) + '\n')

def trace(command):
    path = root / 'load/results/p3/fake-run/query-trace.jsonl'
    with path.open('a') as out:
        fcntl.flock(out, fcntl.LOCK_EX)
        out.write(json.dumps({'run_id': 'fake-run', 'command_id': command}) + '\n')

failure = os.environ.get('FAKE_FAILURE', '')
if name == 'compose':
    if 'load:activity-write' in args:
        phase = 'warmup' if '--duration=30' in args else 'measured'
        time.sleep(0.05)  # End after the fake k6; capture must join before reset.
        trace('artisan:writer-' + phase)
        sys.exit(7 if failure == 'writer-' + phase else 0)
    if 'artisan' in args:
        sys.exit('Invalid Testbench artisan argv')
    if 'vaulter:sweep-uploads' in args and failure == 'maintenance':
        sys.exit(8)
    if 'psql' in args:
        sql = sys.stdin.read() if '-f' in args else ' '.join(args)
        if 'to_regclass' in sql:
            print('v_nodes v_webhook_deliveries')
        else:
            print('{}')
elif name == 'run.sh':
    phase = 'warmup' if os.environ['DURATION'] == '30s' else 'measured'
    if failure != 'empty-trace':
        trace('http:' + phase)
    sys.exit(9 if failure == 'k6-' + phase else 0)
elif name == 'workload.py':
    if args[0] == 'build':
        path = pathlib.Path(args[args.index('--run-dir') + 1]) / 'run-manifest.json'
        path.write_text(json.dumps({'terminal': {}}))
    if args[0] == 'verify-artifacts' and failure == 'invalid-artifacts':
        sys.exit(10)
'''


class R04CaptureTest(unittest.TestCase):
    def run_capture(self, root, failure='', existing=False, duration='2s'):
        for folder in ('bin', 'load/scripts', 'load/seed/out', 'load/k6/lib', 'load/gates', 'load/sql/p3', 'plans/archive/VLT-LOAD/artifacts/P8.7'):
            (root / folder).mkdir(parents=True, exist_ok=True)
        for name in ('compose', 'docker', 'curl'):
            path = root / 'bin' / name
            path.write_text(FAKE)
            path.chmod(0o755)
        for name in ('scripts/run.sh', 'gates/workload.py'):
            path = root / 'load' / name
            path.write_text(FAKE)
            path.chmod(0o755)
        (root / 'load/env').write_text('test')
        (root / 'load/seed/out/vlt-load-fake.json').write_text('{}')
        (root / 'load/gates/thresholds.js').write_text('// fake')
        for name in ('preflight', 'dataset-profile', 'pg-stat-io', 'reset', 'pgss-export', 'hotspot-fingerprints'):
            (root / f'load/sql/p3/{name}.sql').write_text(name)
        history = root / 'plans/archive/VLT-LOAD/artifacts/P3'
        history.mkdir(parents=True, exist_ok=True)
        (history / 'run-manifest.json').write_text('historical sentinel')
        if existing:
            (history / 'fake-run').mkdir()
        (root / 'plans/archive/VLT-LOAD/artifacts/P8.7/run-manifest.json').write_text('{"run_id":"old"}')
        env = {**os.environ, 'PATH': str(root / 'bin') + ':' + os.environ['PATH'],
               'FAKE_ROOT': str(root), 'FAKE_FAILURE': failure, 'LOAD_RUN_ID': 'fake-run',
               'P3_DURATION': duration, 'LOAD_ROOT': str(root / 'load'), 'REPO_ROOT': str(root),
               'SCRIPT_DIR': str(root / 'load/scripts'), 'TIER': 'S',
               'ENV_FILE': str(root / 'load/env'), 'PROFILE_PY': 'unused',
               'WORKLOAD_PY': str(root / 'load/gates/workload.py')}
        # Avoid inheriting a developer's artifact/run override into the sandbox.
        for key in ('P3_RUN_DIR', 'P3_ARTIFACT_DIR'):
            env.pop(key, None)
        result = subprocess.run(['bash', '-c', 'COMPOSE=(compose); source "$1"', 'capture', str(CAPTURE)],
                                env=env, capture_output=True, text=True, timeout=15)
        calls = [json.loads(line) for line in (root / 'calls.jsonl').read_text().splitlines()]
        self.assertEqual((history / 'run-manifest.json').read_text(), 'historical sentinel')
        return result, calls

    def test_success_joins_warm_writer_and_uses_fresh_publication(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            result, calls = self.run_capture(root)
            self.assertEqual(result.returncode, 0, result.stderr)
            trace = (root / 'load/results/p3/fake-run/query-trace.jsonl').read_text()
            self.assertNotIn('warmup', trace)
            self.assertIn('http:measured', trace)
            self.assertIn('writer-measured', trace)
            writers = [args for name, args in calls if 'load:activity-write' in args]
            self.assertEqual(len(writers), 2)
            self.assertTrue(all('-d' not in args and '--tier=S' in args for args in writers))
            self.assertIn('--duration=2', writers[1])
            self.assertEqual([args[0] for name, args in calls if name == 'workload.py'],
                             ['preflight', 'build', 'verify-artifacts', 'publish', 'verify-artifacts'])
            publication = next(args for name, args in calls if name == 'workload.py' and args[0] == 'publish')
            self.assertEqual(publication[-1], str(root / 'plans/archive/VLT-LOAD/artifacts/P3/fake-run'))

    def test_failed_work_never_publishes(self):
        for failure in ('writer-warmup', 'writer-measured', 'k6-warmup', 'k6-measured', 'maintenance', 'empty-trace', 'invalid-artifacts'):
            with self.subTest(failure=failure), tempfile.TemporaryDirectory() as tmp:
                result, calls = self.run_capture(Path(tmp), failure)
                self.assertNotEqual(result.returncode, 0, result.stdout)
                self.assertFalse(any(name == 'workload.py' and args[0] == 'publish' for name, args in calls))

    def test_existing_destination_and_bad_duration_reject_before_services(self):
        for existing, duration in ((True, '2s'), (False, '0s'), (False, 'invalid')):
            with self.subTest(existing=existing, duration=duration), tempfile.TemporaryDirectory() as tmp:
                result, calls = self.run_capture(Path(tmp), existing=existing, duration=duration)
                self.assertNotEqual(result.returncode, 0)
                self.assertFalse(any(name == 'compose' for name, args in calls))


if __name__ == '__main__':
    unittest.main()
