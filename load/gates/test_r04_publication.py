"""Publish only complete validated snapshots, without replacing prior evidence."""

import json
import tempfile
import unittest
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path
from unittest.mock import patch

from p3_workload import WorkloadError, build_workload_manifest, publish_run_dir, verify_artifacts


class R04PublicationTest(unittest.TestCase):
    def fixture(self, root):
        source = root / 'source'
        source.mkdir()
        manifest = build_workload_manifest(
            run_id='fixture-run', dataset_id='fixture-dataset', tier='S',
            env_fingerprint='a' * 16, git_sha='b' * 40,
            cache_declaration='test-only',
            pgss_info={'dealloc_before': 0, 'dealloc_after': 0, 'dealloc_delta': 0},
            extensions={'pg_stat_statements': '1.10', 'hypopg': '1.4.1'},
            p87_run_id='prerequisite-fixture', workload={'profile': 'mixed', 'duration': '120s'},
        )
        (source / 'run-manifest.json').write_text(json.dumps(manifest))
        for name in ('dataset-profile', 'pg-stat-io'):
            (source / f'{name}.json').write_text(json.dumps(manifest))
        (source / 'pgss-top20.json').write_text(json.dumps({**manifest, 'fingerprints': [
            {'query_fingerprint': str(number)} for number in range(20)
        ]}))
        (source / 'operation-coverage.json').write_text(json.dumps({**manifest, 'operations': [
            {'op_id': f'OP-{number:02}'} for number in range(1, 48)
        ]}))
        (source / 'query-trace.jsonl').write_text(json.dumps({
            'run_id': 'fixture-run', 'command_id': 'http:tree_list', 'invocation_id': 'a' * 16,
            'sql_template_hash': 'b' * 64, 'connection': 'pgsql', 'duration_ms': 1.0,
            'ordinal': 1, 'in_transaction': False,
        }) + '\n')
        verify_artifacts(source)
        return source

    def test_complete_snapshot_is_valid_and_byte_identical(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            source = self.fixture(root)
            target = root / 'published/run'
            publish_run_dir(source, target)
            verify_artifacts(target)
            self.assertEqual({p.name: p.read_bytes() for p in source.iterdir()},
                             {p.name: p.read_bytes() for p in target.iterdir()})

    def test_missing_invalid_and_failed_copy_never_expose_partial_run(self):
        for failure in ('missing', 'empty', 'duplicate', 'dataset', 'copy'):
            with self.subTest(failure=failure), tempfile.TemporaryDirectory() as tmp:
                root = Path(tmp)
                source = self.fixture(root)
                target = root / 'published/run'
                trace = source / 'query-trace.jsonl'
                if failure == 'missing':
                    (source / 'operation-coverage.json').unlink()
                elif failure == 'empty':
                    trace.write_text('')
                elif failure == 'duplicate':
                    trace.write_text(trace.read_text() * 2)
                elif failure == 'dataset':
                    (source / 'dataset-profile.json').write_text('{"dataset_id":"foreign"}')
                if failure == 'copy':
                    with patch('p3_workload.shutil.copyfile', side_effect=OSError('disk full')):
                        with self.assertRaises(OSError):
                            publish_run_dir(source, target)
                else:
                    with self.assertRaises(WorkloadError):
                        publish_run_dir(source, target)
                self.assertFalse(target.exists())
                self.assertFalse(list(target.parent.glob('.p3-publish-*')))

    def test_existing_evidence_empty_directory_and_symlink_are_preserved(self):
        for kind in ('evidence', 'empty', 'symlink'):
            with self.subTest(kind=kind), tempfile.TemporaryDirectory() as tmp:
                root = Path(tmp)
                source = self.fixture(root)
                target = root / 'published'
                if kind == 'symlink':
                    target.symlink_to(root / 'missing')
                else:
                    target.mkdir()
                    if kind == 'evidence':
                        (target / 'old.json').write_text('old evidence')
                with self.assertRaisesRegex(WorkloadError, 'PUBLISH_EXISTS'):
                    publish_run_dir(source, target)
                if kind == 'evidence':
                    self.assertEqual((target / 'old.json').read_text(), 'old evidence')
                elif kind == 'empty':
                    self.assertEqual(list(target.iterdir()), [])
                else:
                    self.assertTrue(target.is_symlink())

    def test_competing_publishers_accept_exactly_one_snapshot(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            source = self.fixture(root)
            target = root / 'published/run'
            def publish(_):
                try:
                    publish_run_dir(source, target)
                    return 'published'
                except WorkloadError as exc:
                    return exc.code
            with ThreadPoolExecutor(max_workers=2) as executor:
                self.assertCountEqual(list(executor.map(publish, range(2))), ['published', 'PUBLISH_EXISTS'])
            verify_artifacts(target)

    def test_missing_manifest_and_artifact_identity_are_rejected(self):
        for filename, key in (('run-manifest.json', 'schema_version'), ('run-manifest.json', 'terminal'),
                              ('run-manifest.json', 'env_fingerprint'), ('run-manifest.json', 'disposable_db'),
                              ('dataset-profile.json', 'run_id'), ('dataset-profile.json', 'dataset_id')):
            with self.subTest(filename=filename, key=key), tempfile.TemporaryDirectory() as tmp:
                root = Path(tmp)
                source = self.fixture(root)
                path = source / filename
                data = json.loads(path.read_text())
                data.pop(key)
                path.write_text(json.dumps(data))
                target = root / 'published'
                with self.assertRaises(WorkloadError):
                    publish_run_dir(source, target)
                self.assertFalse(target.exists())


if __name__ == '__main__':
    unittest.main()
