import unittest
from unittest.mock import patch
from pathlib import Path
import tempfile
import p6_evidence as ev
import p6_live_matrix as live


def row(uuid, source, ns, **overrides):
    return {**dict(uuid=uuid, source=source, monotonic_ns=ns, run_id='r', cell_id='c', attempt=1),
            'class': ev.WEBHOOK, **overrides}


class EvidenceTest(unittest.TestCase):
    def test_join_wait_is_enqueue_to_start_not_delivery_age(self):
        result = ev.lifecycle([row('a', 'queueing', 1000000000), row('a', 'queued', 1001000000), row('a', 'processing', 3000000000), row('a', 'processed', 3050000000)])
        self.assertEqual(result['queue_wait_p95_seconds'], 2)
        self.assertEqual(result['process_p95_seconds'], .05)
        self.assertEqual(result['processed'], 1)

    def test_missing_or_duplicate_enqueue_cannot_be_valid(self):
        for rows in ([row('a', 'processing', 2)], [row('a', 'queued', 1)] * 2,
                     [row('a', 'queued', 3), row('a', 'processing', 2)]):
            self.assertEqual(ev.lifecycle(rows)['invalid_uuids'], ['a'])

    def test_old_failures_do_not_become_new_cell_failures(self):
        self.assertEqual(ev.failure_delta([{'uuid': 'old'}], [{'uuid': 'old'}, {'uuid': 'new'}]), [{'uuid': 'new'}])

    def test_serial_concurrent_and_attempt_windows_are_disjoint(self):
        rows = [row('a', 'queued', 1), row('b', 'queued', 2, cell_id='next'), row('c', 'queued', 3, attempt=2)]
        self.assertEqual([r['uuid'] for r in ev.window(rows, 'r', 'c', 1)], ['a'])

    def test_later_green_cannot_erase_red_attempt(self):
        self.assertEqual(ev.aggregate_slo([{'slo': 'red'}, {'slo': 'green'}]), 'red')

    def test_single_tick_cannot_prove_10k_drain(self):
        with self.assertRaises(ValueError):
            ev.validate_volume({'relay_ticks': [{'relayed': 100, 'ok': True, 'elapsed_ns': 10}], 'outbox_remaining': 9900})

    def test_context_passed_to_dispatch_process(self):
        import subprocess
        with patch.object(live, 'compose', return_value=subprocess.CompletedProcess([], 0, '{}', '')) as call:
            live.probe('relay', run_id='current-run')
        self.assertIn('LOAD_QUEUE_CONTEXT=/app/load/seed/out/p6-queue-context.json', call.call_args.args)
        self.assertIn('LOAD_RUN_ID=current-run', call.call_args.args)

    def test_cleanup_waits_for_workers_before_flush_and_delete(self):
        calls = []
        with patch.object(live, 'set_workers', side_effect=lambda _: calls.append('pause')), \
             patch.object(live, 'flush_redis', side_effect=lambda: calls.append('flush')), \
             patch.object(live, 'probe', side_effect=lambda *a, **k: calls.append('delete') or {'ok': True}):
            live.clean_cell('r')
        self.assertEqual(calls, ['pause', 'flush', 'delete'])

    def test_failed_pause_never_deletes_fixture(self):
        with patch.object(live, 'set_workers', side_effect=live.gate.EvidenceInvalid('busy')), \
             patch.object(live, 'probe') as probe, patch.object(live, 'flush_redis') as flush:
            with self.assertRaises(live.gate.EvidenceInvalid):
                live.clean_cell('r')
        probe.assert_not_called()
        flush.assert_not_called()

    def test_worker_can_start_before_enqueue_ack_observer(self):
        result = ev.lifecycle([row('a', 'queueing', 1), row('a', 'processing', 2),
                               row('a', 'queued', 3), row('a', 'processed', 4)])
        self.assertEqual(result['invalid_uuids'], [])
        self.assertEqual(result['processed'], 1)
        self.assertEqual(result['queue_wait_p95_seconds'], 1e-9)

    def test_queued_only_is_not_a_complete_enqueue_measurement(self):
        self.assertEqual(ev.lifecycle([row('a', 'queued', 1)])['invalid_uuids'], ['a'])

    def test_undrained_queue_cannot_be_volume_proof(self):
        volume = {'relay_ticks': [{'relayed': 10000, 'elapsed_ns': 1, 'ok': True}],
                  'retry_ticks': [{'retried': 5000, 'elapsed_ns': 1, 'ok': True}],
                  'outbox_remaining': 0, 'due_remaining': 0, 'active_endpoints': 20,
                  'relay_drained': False, 'retry_drained': False}
        with self.assertRaisesRegex(ValueError, 'undrained'):
            ev.validate_volume(volume)

    def test_recovery_timeout_cannot_be_green_with_zero_failed_jobs(self):
        self.assertEqual(ev.product_verdict({'failed_jobs': 0, 'recovered': False}), 'red')
        self.assertEqual(ev.product_verdict({'failed_jobs': 0, 'recovered': True}), 'unevaluated')

    def test_missing_enqueue_confirmation_is_not_a_successful_dispatch(self):
        self.assertEqual(ev.lifecycle([row('a', 'queueing', 1)])['invalid_uuids'], ['a'])

    def test_stale_volume_summary_is_rejected_and_cannot_keep_slo_green(self):
        volume = self.volume()
        for tick in volume['retry_ticks']:
            tick['elapsed_ns'] = 3_000_000_000
        with self.assertRaisesRegex(ValueError, 'stale volume summary'):
            ev.validate_volume(volume)
        attempts = self.slo_attempts()
        self.assertEqual(ev.slo_report(volume, attempts)['SLO-64'], 'red')

    def test_p6_metrics_must_cover_volume_ticks_and_processed_lifecycle(self):
        volume = self.volume()
        metrics = {
            'vaulter_outbox_relayed_total': 10000,
            'vaulter_outbox_relay_duration_seconds_count': 101,
            'vaulter_outbox_endpoints_scanned_total': 10000,
            'vaulter_webhook_queue_wait_seconds_count': 15000,
            'vaulter_webhook_delivery_duration_seconds_count': 15000,
            'vaulter_webhook_delivered_total': 15000,
            'vaulter_webhook_retry_tick_seconds_count': 6,
            'vaulter_webhook_retry_lockset_rows_count': 6,
            'vaulter_webhook_retry_lockset_rows_sum': 5000,
            'vaulter_command_peak_memory_bytes': 32_000_000,
        }
        ev.validate_p6_metrics(metrics, volume)
        metrics['vaulter_outbox_relayed_total'] = 0
        with self.assertRaisesRegex(ValueError, 'below ledger/tick minimum'):
            ev.validate_p6_metrics(metrics, volume)

    def test_memory_seam_requires_positive_retry_command_series(self):
        volume = self.volume()
        metrics = {name: 100000 for name in ev.P6_METRIC_NAMES}
        for value in (0, -1, float('nan'), float('inf')):
            with self.subTest(value=value), self.assertRaisesRegex(ValueError, 'peak_memory'):
                ev.validate_p6_metrics({**metrics, 'vaulter_command_peak_memory_bytes': value}, volume)
        del metrics['vaulter_command_peak_memory_bytes']
        with self.assertRaisesRegex(ValueError, 'missing P6 metrics'):
            ev.validate_p6_metrics(metrics, volume)
        body = 'vaulter_command_peak_memory_bytes{command="vaulter:relay-outbox"} 90000000\n'
        self.assertNotIn('vaulter_command_peak_memory_bytes', ev.exposition_totals(body))
        body += 'vaulter_command_peak_memory_bytes{command="vaulter:retry-webhooks"} 32000000\n'
        self.assertEqual(ev.exposition_totals(body)['vaulter_command_peak_memory_bytes'], 32000000)
        self.assertIn('command="vaulter:retry-webhooks"', live.P6_PROMETHEUS_QUERIES['vaulter_command_peak_memory_bytes'])

    def test_relay_minimum_includes_disjoint_completed_steady_uuids(self):
        rows = [row(uuid, source, ns, cell_id=cell)
                for uuid, cell in [('volume', 'volume-relay'),
                                   ('steady', live.STEADY_CELL)]
                for ns, source in enumerate(('queueing', 'queued', 'processing', 'processed'), 1)]
        self.assertEqual(ev.confirmed_relay_rows(rows, 'r'), 2)
        metrics = {name: 100000 for name in ev.P6_METRIC_NAMES}
        metrics['vaulter_outbox_relayed_total'] = 10000
        with self.assertRaisesRegex(ValueError, 'minimum 46000'):
            ev.validate_p6_metrics(metrics, self.volume(), 46000)
        metrics['vaulter_outbox_relayed_total'] = 46000
        ev.validate_p6_metrics(metrics, self.volume(), 46000)
        with self.assertRaisesRegex(ValueError, 'incomplete confirmed relay ledger'):
            ev.confirmed_relay_rows(rows[:-1], 'r')
        for r in rows:
            r['uuid'] = 'shared'
        with self.assertRaisesRegex(ValueError, 'overlap'):
            ev.confirmed_relay_rows(rows, 'r')

    @staticmethod
    def volume():
        relay_ticks = [{'relayed': 100, 'elapsed_ns': 100_000_000, 'ok': True} for _ in range(100)]
        relay_ticks.append({'relayed': 0, 'elapsed_ns': 100_000_000, 'ok': True})
        retry_ticks = [{'retried': 1000, 'elapsed_ns': 100_000_000, 'rss_peak_bytes': 1, 'ok': True}
                       for _ in range(5)]
        retry_ticks.append({'retried': 0, 'elapsed_ns': 100_000_000, 'rss_peak_bytes': 1, 'ok': True})
        return {
            'relay_ticks': relay_ticks,
            'retry_ticks': retry_ticks,
            'relay_seconds': 20,
            'relay_rows_per_second': 500,
            'relay_tick_p95_seconds': .1,
            'retry_rows_per_second': 5000 / .6,
            'retry_tick_p95_seconds': .1,
            'outbox_remaining': 0,
            'due_remaining': 0,
            'active_endpoints': 20,
            'relay_drained': True,
            'retry_drained': True,
            'relay_lifecycle': {'processed': 10000},
            'retry_lifecycle': {'processed': 5000},
            'retry_failed_jobs_delta': [],
        }

    @staticmethod
    def slo_attempts():
        steady = {
            'cell_id': 'product-realistic.relay.i20.f1.2xx',
            'attempt': 1,
            'backlog_slope': 0,
            'events_sent': 20,
            'burst_seconds': 1,
            'failed_jobs': 0,
            'plane': 'product-realistic',
            'sink': '2xx',
            'fanout': '1',
            'ingress_per_s': 20,
            'jobs_per_second': 100,
            'recovered': True,
            'lifecycle': {'queue_wait_p95_seconds': 1, 'process_p95_seconds': 1},
        }
        latency = [{
            **steady,
            'cell_id': f'latency-{attempt}',
            'attempt': attempt,
            'sink': 'slow-under-timeout',
            'ingress_per_s': 200,
        } for attempt in range(1, 4)]
        return [steady, *latency]
