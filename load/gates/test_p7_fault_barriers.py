"""Offline ACK and held-lock ordering: controlled HTTP/state and local PHP peers."""

import copy
import json
import os
import subprocess
import sys
import tempfile
import unittest
from contextlib import ExitStack
from pathlib import Path
from unittest.mock import patch

import p7_chaos as gate
import p7_live_matrix as live
import p7_lock_holder as holder
import test_p7_evidence as evidence


def cell(cid):
    return next(row for row in gate.catalog() if row['cell_id'] == cid)


class FaultAckTest(unittest.TestCase):
    def setUp(self):
        self.run = 'offline-ack'
        self.pg = cell('product-realistic.lock-holder.disconnect')

    def proxy(self, current):
        spec = live.fault_spec(current, self.run)
        pg = spec['target'] == 'pgbouncer'
        return {'name': spec['target'], 'upstream': 'pgbouncer:6432' if pg else 'minio:9000',
                'listen': '[::]:16432' if pg else '[::]:19001', 'enabled': spec['enabled'],
                'toxics': [spec['toxic']] if spec['enabled'] else []}

    def ack(self, current, state, injector='toxiproxy'):
        with patch.object(live, 'app_http', return_value={'status': 200, 'body': json.dumps(state)}):
            return live.barrier_ack(injector, self.run, current)

    def test_http_200_without_requested_fault_is_rejected(self):
        state = self.proxy(self.pg)
        state['enabled'] = True
        with self.assertRaises(live.LiveError):
            self.ack(self.pg, state)

    def test_disabled_pg_ack_requires_exact_proxy_route_and_boolean_state(self):
        state = self.proxy(self.pg)
        self.assertEqual(self.ack(self.pg, state)['target'], 'pgbouncer')
        for key, value in (('name', 'minio'), ('upstream', 'foreign:6432'), ('listen', '[::]:1'),
                           ('enabled', 'false'), ('enabled', 0), ('toxics', [{}])):
            with self.subTest(field=key, value=value), self.assertRaises(live.LiveError):
                self.ack(self.pg, {**state, key: value})

    def test_transport_toxic_type_attributes_scope_stream_and_toxicity_are_exact(self):
        for cid in ('transport-control.tus-patch.latency', 'transport-control.tus-patch.timeout',
                    'transport-control.tus-patch.reset', 'transport-control.upload-commit.reset'):
            current = cell(cid)
            state = self.proxy(current)
            self.ack(current, state)
            for field, value in (('name', 'p7-foreign'), ('type', 'wrong'), ('attributes', {}),
                                 ('stream', 'upstream'), ('toxicity', 0.0)):
                if state['toxics'][0][field] == value:
                    continue
                bad = copy.deepcopy(state)
                bad['toxics'][0][field] = value
                with self.subTest(cell=cid, field=field), self.assertRaises(live.LiveError):
                    self.ack(current, bad)
            with self.assertRaises(live.LiveError):
                self.ack(current, {**state, 'toxics': []})

    def test_toxic_name_is_run_and_cell_bound(self):
        current = cell('transport-control.tus-patch.reset')
        state = self.proxy(current)
        other = live.fault_spec(current, 'foreign-run')
        state['toxics'][0]['name'] = other['toxic']['name']
        with self.assertRaises(live.LiveError):
            self.ack(current, state)

    def test_arm_and_revert_use_only_the_requested_proxy_and_toxic(self):
        for current in (self.pg, cell('transport-control.tus-patch.reset')):
            with patch.object(live, 'app_http', return_value={'status': 200, 'body': '{}'}) as peer:
                spec = live.fault_spec(current, self.run)
                live.arm(current, self.run)
                live.revert(current, self.run, 'toxiproxy')
                self.assertTrue(all(f'/proxies/{spec["target"]}' in call.args[1] for call in peer.call_args_list))
                self.assertIn(spec['toxic']['name'], peer.call_args_list[-2].args[1])
                if spec['enabled']:
                    self.assertEqual(json.loads(peer.call_args_list[0].args[2]), spec['toxic'])

    def test_arm_rejects_an_existing_toxic_instead_of_claiming_a_new_fault(self):
        with patch.object(live, 'app_http', return_value={'status': 409, 'body': '{}'}):
            with self.assertRaises(live.LiveError):
                live.arm(cell('transport-control.tus-patch.reset'), self.run)

    def test_s3_ack_requires_exact_run_target_and_expected_mode(self):
        current = cell('protocol-control.s3-complete.503.after-accept')
        good = {'run_id': self.run, 'target': 'minio', 'mode': 'after-accept-503'}
        self.ack(current, good, 's3-fault-proxy')
        for key, value in (('run_id', 'prefix-' + self.run), ('target', 'pgbouncer'), ('mode', 'passthrough'),
                           ('mode', 'pre-upstream-503')):
            with self.subTest(field=key), self.assertRaises(live.LiveError):
                self.ack(current, {**good, key: value}, 's3-fault-proxy')

    def test_malformed_unknown_injector_ack_is_rejected(self):
        with patch.object(live, 'app_http', return_value={'status': 200, 'body': 'not-json'}):
            with self.assertRaises(live.LiveError):
                live.barrier_ack('toxiproxy', self.run, self.pg)
        with self.assertRaises(live.LiveError):
            live.barrier_ack('unknown', self.run, self.pg)

    def test_promotion_ack_requires_one_writable_fence_repoint_and_exact_identity(self):
        current = cell('primary-transition.lock-holder.fence-then-promote')
        good = evidence.ack_for(current, self.run)
        good = {key: value for key, value in good.items() if key not in {'target', 'injector'}}
        def check(state):
            result = subprocess.CompletedProcess([], 0, ''.join(f'{key}={value}\n' for key, value in state.items()), '')
            with patch.object(live, '_run', return_value=result):
                return live.barrier_ack('pg-failover', self.run, current)
        check(good)
        for key, value in (('run_id', 'foreign'), ('primary', 'postgres'), ('writable', '10'),
                           ('fenced', 'no'), ('promoted', 'no'), ('repointed', 'no'), ('standby_recovery', 't')):
            with self.subTest(field=key), self.assertRaises(live.LiveError):
                check({**good, key: value})

    def test_enospc_ack_requires_scoped_tmpfs_and_matching_measured_fill(self):
        current = cell('storage-control.write.enospc')
        good = {'run_id': self.run, 'target': '/var/tmp/vaulter-chaos', 'fstype': 'tmpfs',
                'free_bytes_after': 0, 'write_length': 8192}
        with patch.object(live.arm, 'enospc', {'free_bytes_before': 8192, 'free_bytes_after': 0, 'write_length': 8192}):
            for change in ({}, {'run_id': 'foreign'}, {'fstype': 'ext4'}, {'target': '/tmp'},
                           {'write_length': 1}, {'free_bytes_after': 1024}, {'free_bytes_after': False}):
                peer = subprocess.CompletedProcess([], 0, json.dumps({**good, **change}), '')
                with patch.object(live, '_run', return_value=peer):
                    if change:
                        with self.assertRaises(live.LiveError):
                            live.barrier_ack('tmpfs-enospc', self.run, current)
                    else:
                        live.barrier_ack('tmpfs-enospc', self.run, current)


class HeldLockPeerTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='p7-held-peer-')
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.log = self.root / 'peer.jsonl'
        self.failure = self.root / 'disconnect'
        self.environment = dict(os.environ, P7_PEER_LOG=str(self.log), P7_PEER_FAILURE=str(self.failure))
        self.fixture = {'marker': 'offline-held', 'requested_bytes': 16}

    def launch(self, command, **kwargs):
        return subprocess.Popen(['php', str(live.LOAD / 'gates/fixtures/peers/lock-holder.php'),
                                 str(live.LOAD / 'app/Console/ChaosOperationCommand.php'), self.fixture['marker']],
                                env=self.environment, **kwargs)

    def events(self):
        return [json.loads(line)['event'] for line in self.log.read_text().splitlines()]

    def start(self):
        session = holder.LockHolderSession(self.fixture, database='vaulter_load', launch=self.launch, timeout=2)
        self.addCleanup(session.close)
        return session

    def test_real_php_command_acquires_lock_before_ready_and_defers_mutation_until_release(self):
        session = self.start()
        self.assertEqual(self.events(), ['transaction-open', 'lock-acquired'])
        result = session.finish()
        self.assertEqual(result['status'], 200)
        self.assertEqual(result['lock_boundary'], session.ready)
        self.assertEqual(self.events(), ['transaction-open', 'lock-acquired', 'outbox-stage', 'commit'])

    def test_connection_loss_after_boundary_is_a_measured_failure_and_rolls_back(self):
        session = self.start()
        self.failure.touch()
        result = session.finish()
        self.assertEqual(result['status'], 500)
        self.assertEqual(result['error_class'], 'PDOException')
        self.assertEqual(result['lock_boundary'], session.ready)
        self.assertEqual(self.events(), ['transaction-open', 'lock-acquired', 'rollback'])

    def test_cancelled_holder_rolls_back_without_mutation(self):
        session = self.start()
        session.close()
        self.assertEqual(self.events(), ['transaction-open', 'lock-acquired', 'rollback'])

    def test_foreign_release_rolls_back(self):
        session = self.start()
        message = {'schema': 'p7-lock-holder-control/v1', 'run_prefix': 'foreign', 'ordinal': 1,
                   'event_id': 'foreign:lock-holder:1', 'action': 'release'}
        stdout, _ = session.process.communicate((json.dumps(message) + '\n').encode(), timeout=2)
        self.assertEqual(session.process.returncode, 1)
        self.assertEqual(json.loads(stdout)['lock_boundary'], session.ready)
        self.assertNotIn('outbox-stage', self.events())

    def test_partial_control_is_bounded_and_rolls_back(self):
        self.environment['P7_PEER_TIMEOUT'] = '1'
        session = self.start()
        session.process.stdin.write(b'{')
        session.process.stdin.flush()
        session.process.wait(timeout=3)
        self.assertEqual(session.process.returncode, 1)
        self.assertNotIn('outbox-stage', self.events())

    def test_wrong_database_and_non_pg_boundary_are_rejected(self):
        self.environment['P7_PEER_DATABASE'] = 'foreign'
        with self.assertRaises(holder.HolderError):
            self.start()
        self.environment['P7_PEER_DRIVER'] = 'sqlite'
        with self.assertRaises(holder.HolderError):
            self.start()

    def test_unheld_or_foreign_ready_frame_is_rejected(self):
        ready = {'schema': 'p7-lock-holder-ready/v1', 'run_prefix': self.fixture['marker'], 'ordinal': 1,
                 'event_id': 'offline-held:lock-holder:1', 'database': 'vaulter_load',
                 'backend_pid': 123, 'lock_acquired': True}
        for key, value in (('run_prefix', 'foreign'), ('lock_acquired', False), ('backend_pid', 0), ('ordinal', 2)):
            bad = {**ready, key: value}
            def peer(command, **kwargs):
                return subprocess.Popen([sys.executable, '-c',
                                         'import sys,json; print(json.dumps(' + repr(bad) + '),flush=True); sys.stdin.readline()'], **kwargs)
            with self.subTest(field=key), self.assertRaises(holder.HolderError):
                holder.LockHolderSession(self.fixture, database='vaulter_load', launch=peer, timeout=1)

    def test_readiness_timeout_closes_the_peer(self):
        children = []
        def peer(command, **kwargs):
            proc = subprocess.Popen([sys.executable, '-c', 'import sys; sys.stdin.readline()'], **kwargs)
            children.append(proc)
            return proc
        with self.assertRaises(holder.HolderError):
            holder.LockHolderSession(self.fixture, database='vaulter_load', launch=peer, timeout=0.05)
        self.assertIsNotNone(children[0].poll())

    def test_exited_holder_cannot_reach_injection(self):
        session = self.start()
        session.process.terminate()
        session.process.wait(timeout=2)
        with self.assertRaises(holder.HolderError):
            session.ensure_held()

    def test_terminal_outcome_identity_and_measurements_are_required(self):
        ready = {'schema': 'p7-lock-holder-ready/v1', 'run_prefix': self.fixture['marker'], 'ordinal': 1,
                 'event_id': 'offline-held:lock-holder:1', 'database': 'vaulter_load',
                 'backend_pid': 123, 'lock_acquired': True}
        terminal = {'schema': 'p7-lock-holder-result/v1', 'operation': 'lock-holder',
                    'run_prefix': self.fixture['marker'], 'ordinal': 1, 'lock_boundary': ready,
                    'requested_bytes': 16, 'written_bytes': 16, 'entity_id': ready['event_id'], 'error_class': None}
        for key, value in (('run_prefix', 'foreign'), ('requested_bytes', 99), ('requested_bytes', None),
                           ('written_bytes', None), ('written_bytes', 0), ('entity_id', 'foreign'),
                           ('error_class', 'Error'), ('ordinal', True), ('missing-error', None)):
            bad = {**terminal, key: value}
            if key == 'missing-error':
                del bad['error_class']
            def peer(command, **kwargs):
                return subprocess.Popen([sys.executable, '-c', 'import sys,json; print(json.dumps(' + repr(ready)
                                         + '),flush=True); sys.stdin.readline(); print(json.dumps(' + repr(bad) + '))'], **kwargs)
            with self.subTest(field=key):
                session = holder.LockHolderSession(self.fixture, database='vaulter_load', launch=peer, timeout=1)
                try:
                    with self.assertRaises(holder.HolderError):
                        session.finish()
                finally:
                    session.close()

    def window(self, current, *, bad_ack=False, missing_ready=False, execution_error=False):
        run_id = 'offline-window'
        self.fixture = {'marker': live.product_ops.marker_for(run_id, current['cell_id'], 1),
                        'requested_bytes': 16, 'run_id': run_id, 'payload_sha256': 'b' * 64}
        self.environment['P7_PEER_DATABASE'] = 'vaulter_chaos' if current['plane'] == 'primary-transition' else 'vaulter_load'
        streams = {name: [] for name in gate.JSONL_FILES}
        session_type = holder.LockHolderSession
        def start(fixture, *, database):
            if missing_ready:
                raise holder.HolderError('Controlled missing boundary')
            return session_type(fixture, database=database, launch=self.launch, timeout=2)
        def log(event):
            with self.log.open('a') as out:
                out.write(json.dumps({'event': event}) + '\n')
        def arm(current, run_id):
            self.assertEqual(self.events()[-1], 'lock-acquired')
            log('fault')
            self.failure.touch()
            return live.fault_spec(current, run_id)['injector']
        def ack(injector, run_id, current):
            log('ack')
            if bad_ack:
                raise live.LiveError('Controlled wrong ACK')
            return evidence.ack_for(current, run_id)
        with ExitStack() as stack:
            peers = {'host_status': 200, 'host_probe': (200, 'vaulter_metric 1'),
                     'bootstrap_ha_schema': None, 'route_app': None, 'cleanup_fixture': None,
                     'sample_entities': [{'entity': 'outbox', 'entity_id': self.fixture['marker'], 'status': 'absent'}]}
            for name, value in peers.items():
                stack.enter_context(patch.object(live, name, return_value=value))
            stack.enter_context(patch.object(live.lock_holder, 'LockHolderSession', side_effect=start))
            stack.enter_context(patch.object(live.product_ops.ProductOperationRunner, 'prepare', return_value=self.fixture))
            execute = stack.enter_context(patch.object(live.product_ops.ProductOperationRunner, 'execute', side_effect=AssertionError('late holder')))
            stack.enter_context(patch.object(live, 'arm', side_effect=arm))
            stack.enter_context(patch.object(live, 'barrier_ack', side_effect=ack))
            stack.enter_context(patch.object(live, 'revert', side_effect=lambda *args: log('revert')))
            if execution_error:
                stack.enter_context(patch.object(session_type, 'finish', side_effect=holder.HolderError('Controlled finish error')))
            try:
                result = live.run_window(streams, live.Clock(), current, 1, run_id, gate.config_fingerprint(), 'a' * 64)
            finally:
                execute.assert_not_called()
        return result, streams

    def test_actual_window_prepares_held_peer_before_disconnect_and_promotion(self):
        for cid in ('product-realistic.lock-holder.disconnect', 'transport-control.lock-holder.reset',
                    'primary-transition.lock-holder.fence-then-promote'):
            with self.subTest(cell=cid):
                if self.log.exists():
                    self.log.unlink()
                if self.failure.exists():
                    self.failure.unlink()
                result, streams = self.window(cell(cid))
                self.assertEqual(self.events(), ['transaction-open', 'lock-acquired', 'fault', 'ack', 'rollback', 'revert'])
                ready = streams['barriers.jsonl'][0]['holder_ready']
                self.assertEqual(result['product_results'][0]['lock_boundary'], ready)
                self.assertEqual(result['product_results'][0]['status'], 500)
                self.assertEqual(streams['barriers.jsonl'][0]['ack'], streams['injectors.jsonl'][0]['ack'])
                self.assertTrue(all(row['target'] == live.fault_spec(cell(cid), 'offline-window')['target']
                                    for events in streams.values() for row in events))
                self.assertEqual(result['evidence'], 'valid')

    def test_bad_fault_ack_cancels_held_peer_and_reverts_without_operation(self):
        with self.assertRaises(live.LiveError):
            self.window(cell('product-realistic.lock-holder.disconnect'), bad_ack=True)
        self.assertEqual(self.events(), ['transaction-open', 'lock-acquired', 'fault', 'ack', 'rollback', 'revert'])

    def test_missing_ready_prevents_any_fault_or_promotion(self):
        with self.assertRaises(holder.HolderError):
            self.window(cell('primary-transition.lock-holder.fence-then-promote'), missing_ready=True)
        self.assertFalse(self.log.exists())

    def test_failed_finish_still_cancels_held_peer_and_reverts(self):
        with self.assertRaises(holder.HolderError):
            self.window(cell('product-realistic.lock-holder.disconnect'), execution_error=True)
        self.assertEqual(self.events(), ['transaction-open', 'lock-acquired', 'fault', 'ack', 'rollback', 'revert'])


class PromotionShellPeerTest(unittest.TestCase):
    def test_real_shell_rehearsal_publishes_exact_scoped_fence_promotion_route_ack(self):
        with tempfile.TemporaryDirectory(prefix='p7-promotion-peer-') as tmp:
            root = Path(tmp)
            binary = root / 'docker'
            binary.write_text('#!' + sys.executable + '\n' + '''
import json, os, sys
from pathlib import Path
a = sys.argv[1:]
root = Path(os.environ['P7_FAILOVER_STATE'])
root.mkdir(exist_ok=True)
with (root.parent / 'peer-run-ids').open('a') as out:
    out.write(os.environ.get('P7_RUN_ID', '') + '\\n')
with (root / 'peer-events').open('a') as out:
    out.write(json.dumps(a) + '\\n')
if a[0] == 'compose':
    if 'ps' in a:
        print(a[-1])
elif a[0] == 'inspect':
    print('false' if a[-1] == 'postgres-primary' and (root / 'fenced').exists() else 'true')
elif a[0] == 'exec':
    if 'promote' in a:
        (root / 'peer-promoted').touch()
    elif '-c' in a:
        if 'postgres-primary' in a and (root / 'fenced').exists():
            sys.exit(1)
        if 'pg_is_in_recovery' in a[-1]:
            print('t' if 'postgres-standby' in a and not (root / 'peer-promoted').exists() else 'f')
        else:
            print('0/10')
''')
            binary.chmod(0o755)
            env = {'PATH': str(root) + os.pathsep + os.environ['PATH'],
                   'P7_FAILOVER_STATE': str(root / 'state'), 'P7_RUN_ID': 'offline-shell', 'TIER': 'S'}
            with patch.dict(os.environ, env):
                result = subprocess.run(['sh', str(live.FAILOVER), 'rehearse'], capture_output=True, text=True, timeout=5)
                self.assertEqual(result.returncode, 0, result.stderr)
                original = live._run
                def status(command, **kwargs):
                    self.assertEqual(command, [str(live.FAILOVER), 'status'])
                    kwargs['env'] = dict(kwargs['env'], P7_FAILOVER_STATE=env['P7_FAILOVER_STATE'])
                    return original(command, **kwargs)
                with patch.object(live, '_run', side_effect=status):
                    ack = live.barrier_ack('pg-failover', 'offline-shell',
                                           cell('primary-transition.lock-holder.fence-then-promote'))
                self.assertEqual(ack['fenced'], 'yes')
                self.assertEqual(ack['repointed'], 'yes')
                calls = [json.loads(line) for line in (root / 'state/peer-events').read_text().splitlines()]
                fenced = next(index for index, args in enumerate(calls) if 'stop' in args and args[-1] == 'postgres-primary')
                promoted = next(index for index, args in enumerate(calls) if 'promote' in args)
                repointed = next(index for index, args in enumerate(calls) if 'up' in args and args[-1] == 'pgbouncer-ha')
                self.assertLess(fenced, promoted)
                self.assertLess(promoted, repointed)
                wrapper = live.FAILOVER.parent.parent / 'scripts/p7-injectors.sh'
                wrapper_result = subprocess.run(['sh', str(wrapper), 'promotion-rehearsal'],
                    capture_output=True, text=True, timeout=5,
                    env={**{key: value for key, value in os.environ.items() if key != 'P7_RUN_ID'},
                         'TMPDIR': tmp, 'COMPOSE_PROJECT': 'offline-peer'})
                self.assertEqual(wrapper_result.returncode, 0, wrapper_result.stderr)
                self.assertTrue(any(run.startswith('p7.2-promotion-')
                                    for run in (root / 'peer-run-ids').read_text().splitlines()))
                (root / 'state/run-id').write_text('foreign\n')
                with patch.object(live, '_run', side_effect=status), self.assertRaises(live.LiveError):
                    live.barrier_ack('pg-failover', 'offline-shell',
                                     cell('primary-transition.lock-holder.fence-then-promote'))


class FaultProofAdmissionTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='p7-fault-admission-')
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        evidence.build(self.root)
        live.verify_artifacts(self.root)

    def mutate(self, cid, stream, action):
        path = self.root / stream
        values = evidence.rows(path)
        for row in values:
            if row['cell_id'] == cid and row['attempt'] == 1:
                action(row)
        evidence.write_rows(path, values)

    def test_ack_is_rechecked_even_when_barrier_and_injector_agree(self):
        cid = 'product-realistic.lock-holder.disconnect'
        for stream in ('barriers.jsonl', 'injectors.jsonl'):
            self.mutate(cid, stream, lambda row: row['ack'].__setitem__('enabled', True))
        with self.assertRaises(gate.EvidenceInvalid):
            live.verify_artifacts(self.root)

    def test_missing_held_boundary_cannot_be_admitted_as_a_labelled_lock_operation(self):
        cid = 'primary-transition.lock-holder.fence-then-promote'
        self.mutate(cid, 'barriers.jsonl', lambda row: row.pop('holder_ready'))
        with self.assertRaises(gate.EvidenceInvalid):
            live.verify_artifacts(self.root)

    def test_numeric_disabled_state_is_not_a_boolean_ack(self):
        cid = 'product-realistic.lock-holder.disconnect'
        for stream in ('barriers.jsonl', 'injectors.jsonl'):
            self.mutate(cid, stream, lambda row: row['ack'].__setitem__('enabled', 0))
        with self.assertRaises(gate.EvidenceInvalid):
            live.verify_artifacts(self.root)

    def test_numeric_acquired_state_is_not_a_held_boundary(self):
        cid = 'product-realistic.lock-holder.disconnect'
        self.mutate(cid, 'barriers.jsonl', lambda row: row['holder_ready'].__setitem__('lock_acquired', 1))
        self.mutate(cid, 'attempts.jsonl', lambda row: row['product_results'][0]['lock_boundary'].__setitem__('lock_acquired', 1))
        with self.assertRaises(gate.EvidenceInvalid):
            live.verify_artifacts(self.root)

    def test_foreign_lock_boundary_rejected_even_with_matching_terminal_result(self):
        cid = 'product-realistic.lock-holder.disconnect'
        self.mutate(cid, 'barriers.jsonl', lambda row: row['holder_ready'].__setitem__('database', 'foreign'))
        self.mutate(cid, 'attempts.jsonl', lambda row: row['product_results'][0]['lock_boundary'].__setitem__('database', 'foreign'))
        with self.assertRaises(gate.EvidenceInvalid):
            live.verify_artifacts(self.root)

    def test_terminal_lock_outcome_cannot_borrow_another_boundary(self):
        cid = 'transport-control.lock-holder.reset'
        self.mutate(cid, 'attempts.jsonl', lambda row: row['product_results'][0]['lock_boundary'].__setitem__('backend_pid', 999))
        with self.assertRaises(gate.EvidenceInvalid):
            live.verify_artifacts(self.root)


if __name__ == '__main__':
    unittest.main()
