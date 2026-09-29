"""Offline run.sh controls: a fake Docker command never launches a workload."""
import json
import os
import shutil
import subprocess
import tempfile
import unittest
from pathlib import Path

LOAD = Path(__file__).resolve().parents[1]


class R03RunnerTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.load = self.root / 'load'
        for folder in ['scripts', 'gates', 'env', 'k6/lib', 'seed/out', 'results']:
            (self.load / folder).mkdir(parents=True)
        for name in ['scripts/run.sh', 'gates/thresholds.js', 'gates/r03_lab.py', 'gates/r03_summary.py']:
            shutil.copyfile(LOAD / name, self.load / name)
        (self.load / 'env/S.env').write_text('TIER=S\n')
        (self.load / 'seed/out/vlt-load-test.json').write_text('{}')
        (self.root / '.gitignore').write_text('load/results/\nload/k6/dataset.json\nload/k6/lib/thresholds.js\nload/gates/__pycache__/\n')
        subprocess.run(['git', 'init', '-q', str(self.root)], check=True, capture_output=True)
        self.bin = self.root / 'bin'
        self.bin.mkdir()
        docker = self.bin / 'docker'
        docker.write_text('''#!/usr/bin/env python3
import json, os, pathlib, sys
args = sys.argv[1:]
if 'config' in args:
    print(json.dumps({'name': 'test', 'services': {}}))
elif args[:2] == ['ps', '-q']:
    pass
else:
    pathlib.Path('results/workload-started').touch()
    meta = {}
    names = {'RUN_ID': 'run_id', 'SOURCE_DIGEST': 'source_digest', 'GIT_COMMIT_SHA': 'commit',
             'TIER': 'tier', 'DURATION': 'declared_duration', 'LOAD_PROFILE': 'profile'}
    for i, arg in enumerate(args):
        if arg == '-e':
            key, value = args[i+1].split('=', 1)
            if key in names: meta[names[key]] = value
    if os.environ.get('FAKE_SUMMARY') == 'stale': meta['run_id'] = 'previous-run'
    pathlib.Path('results/summary.json').write_text(json.dumps({'meta': meta}))
    if os.environ.get('FAKE_SOURCE_CHANGE'):
        pathlib.Path('k6/changed.js').write_text('// changed during run')
    sys.exit(99)
''')
        docker.chmod(0o755)

    def run_script(self, profile='regression', **extra):
        env = {**os.environ, 'PATH': f'{self.bin}:{os.environ["PATH"]}', 'TIER': 'S',
               'RUN_ID': 'offline-test', 'DURATION': '180s', 'PYTHONDONTWRITEBYTECODE': '1', **extra}
        return subprocess.run(['sh', str(self.load / 'scripts/run.sh'), profile], cwd=self.root,
                              env=env, text=True, capture_output=True)

    def test_exact_k6_exit_and_current_identity_are_preserved(self):
        result = self.run_script()
        self.assertEqual(result.returncode, 99, result.stderr)
        out = self.load / 'results/offline-test'
        self.assertEqual((out / 'k6-exit.txt').read_text(), '99\n')
        meta = json.loads((out / 'summary.json').read_text())['meta']
        self.assertEqual(meta['run_id'], 'offline-test')
        self.assertEqual(meta['declared_duration'], '180s')
        self.assertEqual(len(meta['source_digest']), 64)

    def test_stale_summary_cannot_be_archived_as_current(self):
        result = self.run_script(FAKE_SUMMARY='stale')
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('previous run', result.stderr)
        self.assertFalse((self.load / 'results/offline-test/summary.json').exists())

    def test_source_change_during_window_rejects_preserved_summary(self):
        result = self.run_script(FAKE_SOURCE_CHANGE='1')
        self.assertEqual(result.returncode, 2)
        self.assertIn('Source changed', result.stderr)
        self.assertTrue((self.load / 'results/offline-test/summary.json').exists())

    def test_profile_label_without_lab_facts_never_launches(self):
        result = self.run_script('lab', LAB_FACTS='')
        self.assertEqual(result.returncode, 2)
        self.assertFalse((self.load / 'results/workload-started').exists())


if __name__ == '__main__':
    unittest.main()
