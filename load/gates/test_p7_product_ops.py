import importlib.util
import json
import subprocess
import sys
import unittest
from pathlib import Path

GATES = Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location('p7_product_ops', GATES / 'p7_product_ops.py')
ops = importlib.util.module_from_spec(spec)
sys.modules['p7_product_ops'] = ops
spec.loader.exec_module(ops)


class FakeHttp:
    def __init__(self):
        self.calls = []

    def __call__(self, method, path, body, headers):
        self.calls.append((method, path, body, headers))
        if path == '/load/storage':
            return ops.HttpResponse(200, json.dumps({
                'storage_id': 17,
                'root_node_id': 23,
            }).encode(), {})
        if path.endswith('/resumable-uploads'):
            return ops.HttpResponse(201, b'{"data":{"upload_id":"fixture-upload"}}', {})
        if '/parts?' in path:
            return ops.HttpResponse(201, b'{"part_no":1}', {})
        if method == 'PATCH':
            return ops.HttpResponse(204, b'', {'upload-offset': str(len(body))})
        if path.endswith('/complete'):
            return ops.HttpResponse(201, b'{"data":{"kind":"file"}}', {})
        if method == 'DELETE':
            return ops.HttpResponse(204, b'', {})
        if path.endswith('/folders'):
            return ops.HttpResponse(201, b'{"data":{"kind":"folder"}}', {})
        return ops.HttpResponse(500, b'', {})


class ProductOperationRunnerTest(unittest.TestCase):
    def test_tus_fixture_and_patch_use_real_authenticated_routes(self):
        http = FakeHttp()
        runner = ops.ProductOperationRunner(request=http)
        cell = {'cell_id': 'product-realistic.tus-patch.503', 'operation': 'tus-patch'}

        fixture = runner.prepare(cell, '12345678-run', 1)
        result = runner.execute(cell, fixture)

        self.assertEqual(result['status'], 204)
        self.assertEqual(result['transport'], 'http')
        self.assertEqual(result['written_bytes'], ops.NORMAL_BYTES)
        self.assertEqual(http.calls[-1][0], 'PATCH')
        self.assertEqual(http.calls[-1][1], '/api/vaulter/resumable-uploads/fixture-upload')
        self.assertEqual(http.calls[-1][3]['X-Load-Actor'], str(fixture['actor_id']))
        self.assertNotIn('s3-fault-proxy', http.calls[-1][1])

    def test_complete_stages_a_real_part_before_the_fault_operation(self):
        http = FakeHttp()
        runner = ops.ProductOperationRunner(request=http)
        cell = {'cell_id': 'protocol-control.s3-complete.503.pre-upstream', 'operation': 's3-complete'}

        fixture = runner.prepare(cell, '12345678-run', 1)
        result = runner.execute(cell, fixture)

        paths = [call[1] for call in http.calls]
        self.assertTrue(any('/parts?part_no=1' in path for path in paths))
        self.assertTrue(paths[-1].endswith('/complete'))
        self.assertEqual(result['status'], 201)

    def test_sweeper_fixture_is_due_and_dispatches_the_real_artisan_command(self):
        http = FakeHttp()
        sql = []

        def execute_sql(statement):
            sql.append(statement)
            if 'FROM public.v_storages' in statement:
                return ''
            return 'fixture-upload'

        artisan_calls = []

        def artisan(arguments):
            artisan_calls.append(arguments)
            return subprocess.CompletedProcess(arguments, 0, '{}\n', '')

        runner = ops.ProductOperationRunner(request=http, artisan=artisan, sql=execute_sql)
        cell = {'cell_id': 'product-realistic.sweeper.503', 'operation': 'sweeper'}

        fixture = runner.prepare(cell, '12345678-run', 1)
        result = runner.execute(cell, fixture)

        self.assertTrue(any("expires_at = now() - interval '1 minute'" in statement for statement in sql))
        self.assertEqual(artisan_calls, [['vaulter:sweep-uploads']])
        self.assertEqual(result['command_exit'], 0)

    def test_enospc_retry_has_a_distinct_request_identity(self):
        measured = '{"requested_bytes":4096,"written_bytes":0,"error_class":"RuntimeException"}\n'

        def artisan(arguments):
            return subprocess.CompletedProcess(arguments, 1, measured, '')

        runner = ops.ProductOperationRunner(artisan=artisan)
        cell = {'cell_id': 'storage-control.cas-stage.enospc-then-retry', 'operation': 'cas-stage'}
        fixture = runner.prepare(cell, '12345678-run', 1)

        first = runner.execute(cell, fixture, ordinal=1)
        retry = runner.execute(cell, fixture, ordinal=2)

        self.assertNotEqual(first['request_id'], retry['request_id'])
        self.assertEqual(first['requested_bytes'], 4096)
        self.assertEqual(first['written_bytes'], 0)

    def test_actor_identity_is_distinct_by_cell_and_attempt(self):
        left = ops.actor_for('run', 'cell-a', 1)
        right = ops.actor_for('run', 'cell-a', 2)
        other = ops.actor_for('run', 'cell-b', 1)

        self.assertEqual(len({left, right, other}), 3)

    def test_refuses_to_reuse_an_existing_actor_storage(self):
        http = FakeHttp()
        runner = ops.ProductOperationRunner(request=http, sql=lambda statement: 'existing-storage')
        cell = {'cell_id': 'product-realistic.tus-patch.503', 'operation': 'tus-patch'}

        with self.assertRaisesRegex(ops.ProductOperationError, 'already owns'):
            runner.prepare(cell, '12345678-run', 1)

        self.assertEqual(http.calls, [])

    def test_every_named_operation_dispatches_its_product_seam(self):
        operations = {
            'tus-patch', 's3-put-part', 's3-complete', 'cas-promote', 'abort', 'sweeper',
            'upload-patch', 'upload-commit', 'outbox-mutation', 'lock-holder', 'pgbouncer',
            'spill', 'cas-stage', 'write',
        }

        for operation in operations:
            with self.subTest(operation=operation):
                http = FakeHttp()
                artisan = lambda arguments: subprocess.CompletedProcess(
                    arguments,
                    0,
                    '{"requested_bytes":4096,"written_bytes":4096,"entity_id":"probe"}\n',
                    '',
                )
                runner = ops.ProductOperationRunner(
                    request=http,
                    artisan=artisan,
                    sql=lambda statement: '' if 'FROM public.v_storages' in statement else 'fixture-upload',
                )
                cell = {'cell_id': f'cell.{operation}', 'operation': operation}

                fixture = runner.prepare(cell, '12345678-run', 1)
                result = runner.execute(cell, fixture)

                self.assertIn(result['transport'], {'http', 'artisan'})
                self.assertNotEqual(result['request_id'], '')
                self.assertTrue(all('s3-fault-proxy' not in call[1] for call in http.calls))


if __name__ == '__main__':
    unittest.main()
