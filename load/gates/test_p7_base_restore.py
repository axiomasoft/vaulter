"""Offline P7 route safety: real Compose rendering and shell, fake runtime peers."""

import io
import json
import os
import shutil
import subprocess
import sys
import tempfile
import unittest
from contextlib import ExitStack, redirect_stdout
from pathlib import Path
from unittest.mock import patch

import p7_live_matrix as live


class BaseRestoreTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='p7-base-restore-')
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.bin = self.root / 'bin'
        self.bin.mkdir()
        self.app = self.root / 'app'
        (self.app / 'vendor/bin').mkdir(parents=True)
        pool = self.root / 'pool'
        pool.mkdir()
        (pool / 'www.conf.template').write_text('pm.max_children = ${PM_MAX_CHILDREN}\n')
        # Relocate only container filesystem paths; execute the migration guard
        # and command dispatch from the actual entrypoint unchanged.
        entrypoint = (live.LOAD / 'docker/entrypoint.sh').read_text()
        self.entrypoint = self.root / 'entrypoint.sh'
        self.entrypoint.write_text(entrypoint.replace('/usr/local/etc/php-fpm.d', str(pool))
                                   .replace('cd /app', 'cd ' + str(self.app)))
        self.sentinel = self.root / 'vaulter_load'
        self.sentinel.write_text('owner rows: preserve these bytes\n')
        (self.root / 'vaulter_chaos').write_text('isolated HA schema\n')
        self.log = self.root / 'commands.jsonl'
        self.real_docker = shutil.which('docker')
        if self.real_docker is None:
            self.fail('Docker CLI is required for offline Compose rendering (no daemon used)')
        self.executable(self.bin / 'envsubst', '#!/bin/sh\ncat\n')
        self.executable(self.app / 'vendor/bin/testbench', '''#!/bin/sh
printf '%s\\n' reset > "$P7_TEST_ROOT/$DB_DATABASE"
printf '%s\\n' "$DB_DATABASE:$DB_HOST:$*" >> "$P7_TEST_ROOT/migrations"
''')
        self.executable(self.bin / 'docker', '#!' + sys.executable + '\n' + '''
import json, os, subprocess, sys
from pathlib import Path
root = Path(os.environ['P7_TEST_ROOT'])
args = sys.argv[1:]
with (root / 'commands.jsonl').open('a') as log:
    log.write(json.dumps(args) + '\\n')
if args[0] == 'compose':
    action = next((a for a in ('up', 'stop') if a in args), None)
    if action == 'up':
        config = subprocess.check_output(
            [os.environ['P7_TEST_DOCKER'], *args[:args.index(action)], 'config', '--format', 'json'],
            env=os.environ, text=True)
        services = json.loads(config)['services']
        for service in ('app', 'queue-worker'):
            if service not in args[args.index(action) + 1:]:
                continue
            if os.environ.get('P7_TEST_FAIL_RESTORE') == '1' and service == 'queue-worker':
                sys.exit(1)
            environment = dict(os.environ, **services[service]['environment'])
            with (root / 'routes.jsonl').open('a') as log:
                log.write(json.dumps({'service': service, 'host': environment['DB_HOST'],
                                      'database': environment['DB_DATABASE'],
                                      'skip': environment.get('LOAD_SKIP_MIGRATIONS', 'unset')}) + '\\n')
            subprocess.run(['sh', str(root / 'entrypoint.sh'), 'true'], env=environment, check=True)
elif args[0] == 'exec':
    # Only the explicit isolated bootstrap reaches a runtime command here.
    environment = dict(os.environ)
    for i, arg in enumerate(args):
        if arg == '-e':
            name, value = args[i + 1].split('=', 1)
            environment[name] = value
    if args[-4:] != ['php', 'vendor/bin/testbench', 'migrate:fresh', '--force']:
        raise SystemExit('unexpected bootstrap command')
    subprocess.run([str(root / 'app/vendor/bin/testbench'), *args[-2:]],
                   env=environment, check=True)
else:
    raise SystemExit('unexpected fake docker boundary')
''')
        environment = {
            'PATH': str(self.bin) + os.pathsep + os.environ['PATH'],
            'P7_TEST_ROOT': str(self.root), 'P7_TEST_DOCKER': self.real_docker,
            'PM_MAX_CHILDREN': '1', 'DB_DATABASE': 'vaulter_load',
        }
        self.env = patch.dict(os.environ, environment)
        self.env.start()
        self.addCleanup(self.env.stop)

    def executable(self, path, text):
        path.write_text(text)
        path.chmod(0o755)

    def assert_preserved(self):
        self.assertEqual(self.sentinel.read_text(), 'owner rows: preserve these bytes\n')

    def commands(self):
        return [json.loads(row) for row in self.log.read_text().splitlines()]

    def fake_failover(self, *, fail=False):
        real_run = live._run

        def run(command, **kwargs):
            if command[0] == str(live.FAILOVER):
                if fail:
                    raise live.LiveError('isolated HA revert failed')
                return subprocess.CompletedProcess(command, 0, '', '')
            return real_run(command, **kwargs)

        return patch.object(live, '_run', side_effect=run)

    def shell(self, **values):
        environment = dict(os.environ)
        for name in ('LOAD_SKIP_MIGRATIONS', 'LOAD_CHAOS_PROXIED', 'P7_APP_HA_ROUTED'):
            environment.pop(name, None)
        environment.update(values)
        return subprocess.run(['sh', str(self.entrypoint), 'true'], env=environment,
                              capture_output=True, text=True)

    def test_entrypoint_default_preserves_base_database(self):
        self.assertEqual(self.shell().returncode, 0)
        self.assert_preserved()
        self.assertFalse((self.root / 'migrations').exists())

    def test_entrypoint_refuses_p7_migration_opt_in_even_with_ha_database(self):
        for flag in ('LOAD_CHAOS_PROXIED', 'P7_APP_HA_ROUTED'):
            for database in ('vaulter_load', 'vaulter_chaos'):
                with self.subTest(flag=flag, database=database):
                    result = self.shell(LOAD_SKIP_MIGRATIONS='0', DB_DATABASE=database, **{flag: '1'})
                    self.assertNotEqual(result.returncode, 0)
                    self.assertIn('P7 routes cannot run migrations', result.stderr)
                    self.assert_preserved()
        self.assertFalse((self.root / 'migrations').exists())

    def test_explicit_non_p7_stand_initialization_still_migrates(self):
        self.assertEqual(self.shell(LOAD_SKIP_MIGRATIONS='0').returncode, 0)
        self.assertEqual(self.sentinel.read_text(), 'reset\n')

    def test_enable_routes_and_restore_preserve_sentinel_with_hostile_environment(self):
        with patch.dict(os.environ, {
            'LOAD_SKIP_MIGRATIONS': '0', 'P7_APP_SKIP_MIGRATIONS': '0',
            'P7_APP_DB_DATABASE': 'vaulter_chaos', 'P7_APP_DB_HOST': 'pgbouncer-ha',
            'P7_APP_HA_ROUTED': '1',
        }), self.fake_failover(), patch.object(live, 'app_http', return_value={'status': 200}):
            live.enable_chaos()
            live.route_app(ha=True)
            live.route_app(ha=False)
            live.restore_base('offline-sentinel')
        self.assert_preserved()
        self.assertFalse((self.root / 'migrations').exists())
        self.assertTrue(any('queue-worker' in args and 'up' in args for args in self.commands()))
        routes = [json.loads(row) for row in (self.root / 'routes.jsonl').read_text().splitlines()]
        self.assertEqual([(row['host'], row['database'], row['skip']) for row in routes], [
            ('toxiproxy', 'vaulter_load', '1'), ('pgbouncer-ha', 'vaulter_chaos', '1'),
            ('toxiproxy', 'vaulter_load', '1'), ('pgbouncer', 'vaulter_load', '1'),
            ('pgbouncer', 'vaulter_load', '1'),
        ])

    def test_bare_chaos_compose_recreate_cannot_migrate_either_service(self):
        with patch.dict(os.environ, {'LOAD_SKIP_MIGRATIONS': '0', 'P7_APP_SKIP_MIGRATIONS': '0'}):
            live.compose('up', '-d', 'app', 'queue-worker')
        self.assert_preserved()
        self.assertFalse((self.root / 'migrations').exists())

    def test_base_worker_recreate_never_migrates(self):
        with patch.dict(os.environ, {'LOAD_SKIP_MIGRATIONS': '0'}):
            live.compose_base('up', '-d', 'queue-worker')
        self.assert_preserved()
        self.assertFalse((self.root / 'migrations').exists())

    def test_only_explicit_ha_bootstrap_resets_isolated_database(self):
        live.bootstrap_ha_schema()
        self.assert_preserved()
        self.assertEqual((self.root / 'vaulter_chaos').read_text(), 'reset\n')
        self.assertEqual((self.root / 'migrations').read_text(),
                         'vaulter_chaos:postgres-primary:migrate:fresh --force\n')

    def test_restore_attempts_base_route_even_after_failed_ha_revert(self):
        with self.fake_failover(fail=True), patch.object(live, 'app_http', side_effect=live.LiveError('down')):
            with self.assertRaisesRegex(live.LiveError, 'isolated HA revert failed'):
                live.restore_base('offline-sentinel')
        self.assert_preserved()
        self.assertTrue(any('queue-worker' in args and 'up' in args for args in self.commands()))
        self.assertFalse((self.root / 'migrations').exists())

    def matrix(self, *, failure=None, damage_on_restore=False):
        out = self.root / 'evidence'
        events = []
        real_restore = live.restore_base

        def restore(run_id):
            events.append('restore')
            self.assertFalse((out / 'terminal.json').exists())
            self.assertFalse((out / 'database/integrity-after.json').exists())
            real_restore(run_id)
            if damage_on_restore:
                self.sentinel.write_text('unexpected final state\n')

        def snapshot():
            events.append('snapshot')
            if failure == 'readback' and 'restore' in events:
                raise live.LiveError('final readback failed')
            return {'sentinel': self.sentinel.read_text()}

        def locks(sql):
            self.assertEqual(events[-2:], ['restore', 'snapshot'])
            events.append('locks')
            if failure == 'locks':
                raise live.LiveError('lock readback failed')
            return '0'

        with ExitStack() as stack:
            stack.enter_context(self.fake_failover())
            stack.enter_context(patch.object(live, 'app_http', return_value={'status': 200}))
            stack.enter_context(patch.object(live, 'restore_base', side_effect=restore))
            stack.enter_context(patch.object(live, 'counts_checksum', side_effect=snapshot))
            stack.enter_context(patch.object(live, 'psql', side_effect=locks))
            stack.enter_context(patch.object(live, 'remove_prefix'))
            stack.enter_context(patch.object(live.gate, 'catalog', return_value=[]))
            stack.enter_context(patch.object(live, 'preflight',
                side_effect=live.LiveError('preflight failed') if failure == 'preflight' else None,
                return_value={'config_fingerprint': 'fixture', 'injector_config_digest': 'fixture'}))
            if failure == 'enable':
                real_enable = live.enable_chaos

                def enable():
                    real_enable()
                    raise live.LiveError('enable failed after recreate')

                stack.enter_context(patch.object(live, 'enable_chaos', side_effect=enable))
            if failure == 'window':
                stack.enter_context(patch.object(live.gate, 'catalog', return_value=[{
                    'cell_id': 'offline-cell', 'plane': 'product-realistic',
                }]))
                stack.enter_context(patch.object(live, 'run_window', side_effect=live.LiveError('window failed')))
            stack.enter_context(redirect_stdout(io.StringIO()))
            if failure:
                with self.assertRaises(live.LiveError):
                    live.run_matrix(out, 'offline-sentinel')
            else:
                live.run_matrix(out, 'offline-sentinel')
        return out, events

    def test_success_readback_and_terminal_follow_actual_restore(self):
        out, events = self.matrix()
        self.assertEqual(events, ['snapshot', 'restore', 'snapshot', 'locks'])
        self.assert_preserved()
        self.assertEqual((out / 'database/integrity-before.json').read_bytes(),
                         (out / 'database/integrity-after.json').read_bytes())
        self.assertTrue((out / 'terminal.json').exists())

    def test_exception_finally_preserves_sentinel_and_captures_final_state(self):
        out, events = self.matrix(failure='window')
        self.assert_preserved()
        self.assertEqual(events, ['snapshot', 'restore', 'snapshot', 'locks'])
        self.assertTrue((out / 'database/integrity-after.json').exists())
        self.assertFalse((out / 'terminal.json').exists())

    def test_preflight_failure_also_restores_without_terminal(self):
        out, events = self.matrix(failure='preflight')
        self.assertEqual(events, ['restore', 'snapshot', 'locks'])
        self.assert_preserved()
        self.assertFalse((out / 'terminal.json').exists())

    def test_enable_failure_also_restores_without_terminal(self):
        out, events = self.matrix(failure='enable')
        self.assertEqual(events, ['restore', 'snapshot', 'locks'])
        self.assert_preserved()
        self.assertFalse((out / 'terminal.json').exists())

    def test_failed_final_readback_cannot_emit_terminal(self):
        out, _ = self.matrix(failure='readback')
        self.assert_preserved()
        self.assertFalse((out / 'database/integrity-after.json').exists())
        self.assertFalse((out / 'terminal.json').exists())

    def test_failed_lock_readback_cannot_emit_terminal(self):
        out, _ = self.matrix(failure='locks')
        self.assert_preserved()
        self.assertFalse((out / 'database/locks.json').exists())
        self.assertFalse((out / 'terminal.json').exists())

    def test_final_snapshot_observes_changes_made_during_restore(self):
        out, _ = self.matrix(damage_on_restore=True)
        self.assertNotEqual((out / 'database/integrity-before.json').read_bytes(),
                            (out / 'database/integrity-after.json').read_bytes())
        self.assertEqual(json.loads((out / 'database/integrity-after.json').read_text()),
                         {'sentinel': 'unexpected final state\n'})

    def test_failed_restore_cannot_emit_final_snapshot_or_terminal(self):
        with patch.dict(os.environ, {'P7_TEST_FAIL_RESTORE': '1'}):
            out, _ = self.matrix(failure='preflight')
        self.assert_preserved()
        self.assertFalse((out / 'database/integrity-after.json').exists())
        self.assertFalse((out / 'terminal.json').exists())


if __name__ == '__main__':
    unittest.main()
