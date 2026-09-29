"""Real shell/publisher controls in disposable directories, no live runner."""
import ctypes
import hashlib
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch

import p7_publish as publisher
from test_p7_chaos import write_complete

GATES = Path(__file__).resolve().parent
SCRIPT = GATES.parent / 'scripts/p7-chaos.sh'

FAKE = r'''import os, pathlib, sys, time
args = sys.argv[1:]
if args and pathlib.Path(args[0]).name == 'p7_live_matrix.py' and args[1] == 'run':
    sys.path.insert(0, os.environ['TEST_GATES'])
    from test_p7_evidence import build
    build(pathlib.Path(args[args.index('--out') + 1]))
    sys.exit(int(os.environ.get('FAIL_RUN', '0')))
if len(args) > 1 and args[1] == 'verify' and os.environ.get('FAIL_VERIFY'):
    sys.exit(7)
if len(args) > 1 and pathlib.Path(args[0]).name == 'p7_publish.py' and args[1] == 'publish':
    dest = pathlib.Path(args[args.index('--destination') + 1])
    if os.environ.get('OCCUPY_LATE'):
        dest.mkdir()
        (dest / 'sentinel').write_bytes(b'late occupant')
    if os.environ.get('BARRIER'):
        barrier = pathlib.Path(os.environ['BARRIER'])
        (barrier / str(os.getpid())).touch()
        deadline = time.monotonic() + 10
        while len(list(barrier.iterdir())) < 2:
            if time.monotonic() > deadline: sys.exit(9)
            time.sleep(.01)
os.execv(os.environ['REAL_PYTHON'], [os.environ['REAL_PYTHON'], '-B', *args])
'''


def fingerprint(root):
    return {str(p.relative_to(root)): hashlib.sha256(p.read_bytes()).hexdigest()
            for p in root.rglob('*') if p.is_file()}


class PublicationTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='p7-publication-test-')
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.source = self.root / 'source'
        write_complete(self.source)
        self.bin = self.root / 'bin'
        self.bin.mkdir()
        fake = self.bin / 'python3'
        fake.write_text('#!' + sys.executable + '\n' + FAKE)
        fake.chmod(0o755)
        self.env = dict(os.environ, PATH=str(self.bin) + ':/usr/bin:/bin', TIER='S',
                        P7_PUBLISH='1', TEST_GATES=str(GATES), REAL_PYTHON=sys.executable,
                        PYTHONDONTWRITEBYTECODE='1')

    def command(self, kind):
        return ['sh', str(SCRIPT), 'run', *(['--matrix', '--run-id', 'offline-evidence']
                if kind == 'matrix' else ['--run-dir', str(self.source)])]

    def invoke(self, kind, dest, **env):
        return subprocess.run(self.command(kind), capture_output=True, text=True, timeout=30,
                              env=dict(self.env, P7_ARTIFACTS=str(dest), **env))

    def stages(self, dest):
        return sorted(dest.parent.glob('.p7-staging-*'))

    def test_both_paths_publish_verified_bytes_and_reject_replay(self):
        for kind in ('matrix', 'lane'):
            with self.subTest(kind=kind):
                dest = self.root / kind / 'run'
                result = self.invoke(kind, dest)
                self.assertEqual(result.returncode, 0, result.stderr)
                before = fingerprint(dest)
                self.assertTrue(before)
                if kind == 'lane':
                    self.assertEqual(before, fingerprint(self.source))
                self.assertFalse(self.stages(dest))
                replay = self.invoke(kind, dest)
                self.assertNotEqual(replay.returncode, 0)
                self.assertEqual(fingerprint(dest), before)

    def test_occupied_file_directory_empty_directory_and_symlinks_are_preserved(self):
        for kind in ('matrix', 'lane'):
            for occupant in ('file', 'directory', 'empty', 'symlink', 'dangling'):
                with self.subTest(kind=kind, occupant=occupant):
                    parent = self.root / (kind + occupant)
                    parent.mkdir()
                    dest = parent / 'run'
                    if occupant == 'file': dest.write_bytes(b'historical')
                    elif occupant in ('directory', 'empty'):
                        dest.mkdir()
                        if occupant == 'directory': (dest / 'sentinel').write_bytes(b'historical')
                    else: dest.symlink_to(self.source if occupant == 'symlink' else parent / 'missing')
                    before = fingerprint(parent)
                    result = self.invoke(kind, dest)
                    self.assertNotEqual(result.returncode, 0, result.stdout)
                    self.assertTrue(os.path.lexists(dest))
                    self.assertEqual(fingerprint(parent), before)
                    if occupant in ('symlink', 'dangling'): self.assertTrue(dest.is_symlink())
                    self.assertFalse(self.stages(dest))

    def test_verify_failure_keeps_staging_and_source(self):
        for kind in ('matrix', 'lane'):
            with self.subTest(kind=kind):
                dest = self.root / kind / 'run'
                before = fingerprint(self.source)
                result = self.invoke(kind, dest, FAIL_VERIFY='1')
                self.assertNotEqual(result.returncode, 0)
                self.assertFalse(dest.exists())
                stage, = self.stages(dest)
                self.assertTrue((stage / 'run-manifest.json').exists())
                self.assertIn(str(stage), result.stderr)
                self.assertEqual(fingerprint(self.source), before)

    def test_real_lane_verify_failure_keeps_invalid_snapshot(self):
        (self.source / 'objects.jsonl').write_text('{"truncated":')
        dest = self.root / 'run'
        result = self.invoke('lane', dest)
        self.assertNotEqual(result.returncode, 0)
        stage, = self.stages(dest)
        self.assertEqual(fingerprint(stage), fingerprint(self.source))
        self.assertFalse(dest.exists())

    def test_failed_runner_keeps_staging_and_exact_exit(self):
        dest = self.root / 'run'
        result = self.invoke('matrix', dest, FAIL_RUN='23')
        self.assertEqual(result.returncode, 23, result.stderr)
        stage, = self.stages(dest)
        self.assertTrue((stage / 'run-manifest.json').exists())
        self.assertFalse(dest.exists())

    def test_late_occupant_is_neither_replaced_nor_nested_into(self):
        for kind in ('matrix', 'lane'):
            with self.subTest(kind=kind):
                dest = self.root / kind / 'run'
                result = self.invoke(kind, dest, OCCUPY_LATE='1')
                self.assertNotEqual(result.returncode, 0)
                self.assertEqual(list(dest.iterdir()), [dest / 'sentinel'])
                self.assertEqual((dest / 'sentinel').read_bytes(), b'late occupant')
                stage, = self.stages(dest)
                self.assertTrue((stage / 'run-manifest.json').exists())
                self.assertIn(str(stage), result.stderr)

    def test_concurrent_publishers_have_one_winner_and_retain_loser(self):
        for kinds in (('matrix', 'matrix'), ('lane', 'lane'), ('matrix', 'lane')):
            with self.subTest(kinds=kinds):
                parent = self.root / '-'.join(kinds)
                barrier = parent / 'barrier'
                barrier.mkdir(parents=True)
                dest = parent / 'run'
                env = dict(self.env, P7_ARTIFACTS=str(dest), BARRIER=str(barrier))
                procs = [subprocess.Popen(self.command(kind), env=env, stdout=subprocess.PIPE,
                                          stderr=subprocess.PIPE, text=True) for kind in kinds]
                outputs = [proc.communicate(timeout=30) for proc in procs]
                self.assertEqual(sorted(proc.returncode for proc in procs), [0, 1], outputs)
                stage, = self.stages(dest)
                winner = json.loads((dest / 'run-manifest.json').read_text())['run_id']
                loser = json.loads((stage / 'run-manifest.json').read_text())['run_id']
                self.assertIn(winner, ('run-complete', 'offline-evidence'))
                self.assertIn(loser, ('run-complete', 'offline-evidence'))
                self.assertFalse(list(dest.glob('.p7-staging-*')))
                before = fingerprint(dest)
                replay = self.invoke(kinds[0], dest)
                self.assertNotEqual(replay.returncode, 0)
                self.assertEqual(fingerprint(dest), before)

    def test_unsafe_run_ids_are_refused_before_destination_creation(self):
        for value in ('../escape', '/', '.', '..', 'a/b', '', 'line\nbreak'):
            with self.subTest(value=value):
                with self.assertRaises(ValueError): publisher.stage(self.root / 'new/run', value)
                self.assertFalse((self.root / 'new').exists())

    def test_default_paths_and_matrix_readback_in_disposable_repository(self):
        repo = self.root / 'repo'
        scripts = repo / 'load/scripts'
        scripts.mkdir(parents=True)
        (scripts / 'p7-chaos.sh').write_bytes(SCRIPT.read_bytes())
        (repo / 'load/env').mkdir()
        (repo / 'load/env/S.env').touch()
        gates = repo / 'load/gates'
        gates.mkdir()
        for name in ('p7_publish.py', 'p7_chaos.py', 'p7_live_matrix.py'):
            (gates / name).symlink_to(GATES / name)
        historical = repo / 'plans/VLT-LOAD/artifacts/P7'
        historical.mkdir(parents=True)
        (historical / 'sentinel').write_bytes(b'historical evidence')
        for kind, relative in (('matrix', 'runs/offline-evidence'), ('lane', 'lane-runs/run-complete')):
            command = self.command(kind)
            command[1] = str(scripts / 'p7-chaos.sh')
            result = subprocess.run(command, env=self.env, capture_output=True, text=True, timeout=30)
            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertTrue((historical / relative / 'run-manifest.json').exists())
        readback = subprocess.run(['sh', str(scripts / 'p7-chaos.sh'), 'verify', '--run-id',
                                   'offline-evidence'], env=self.env, capture_output=True, text=True, timeout=30)
        self.assertEqual(readback.returncode, 0, readback.stderr)
        self.assertEqual((historical / 'sentinel').read_bytes(), b'historical evidence')

    def test_rename_os_errors_keep_verified_staging(self):
        dest = self.root / 'run'
        before = fingerprint(self.source)
        for code in (13, 18, 38):  # EACCES, EXDEV, ENOSYS
            with self.subTest(errno=code):
                with patch.object(ctypes, 'CDLL') as library:
                    library.return_value.renameat2.return_value = -1
                    with patch.object(ctypes, 'get_errno', return_value=code):
                        with self.assertRaises(OSError):
                            publisher.publish(self.source, dest, 'run-complete')
                self.assertEqual(fingerprint(self.source), before)
                self.assertFalse(dest.exists())

    def test_identity_mismatch_keeps_staging(self):
        dest = self.root / 'run'
        with self.assertRaisesRegex(ValueError, 'mismatch'):
            publisher.publish(self.source, dest, 'another-run')
        self.assertTrue(self.source.exists())
        self.assertFalse(dest.exists())

    def test_unsupported_atomic_rename_fails_closed(self):
        dest = self.root / 'run'
        before = fingerprint(self.source)
        with patch.object(ctypes, 'CDLL', side_effect=AttributeError('renameat2 unavailable')):
            with self.assertRaises(AttributeError): publisher.publish(self.source, dest, 'run-complete')
        self.assertEqual(fingerprint(self.source), before)
        self.assertFalse(dest.exists())


if __name__ == '__main__':
    unittest.main()
