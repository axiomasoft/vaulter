#!/usr/bin/env python3
"""Offline behavioral controls for R05; no stand or DB writes."""
import importlib.util
import json
import subprocess
import sys
import unittest
from pathlib import Path
from unittest.mock import MagicMock, patch

GATES = Path(__file__).resolve().parent
sys.path.insert(0, str(GATES))
spec = importlib.util.spec_from_file_location('p5_r05', GATES / 'p5_live_matrix.py')
p5 = importlib.util.module_from_spec(spec)
spec.loader.exec_module(p5)


class R05CaptureTest(unittest.TestCase):
    def test_http_status_retry_after_and_actor_are_joined(self):
        metrics = {'p5_status_other': {'values': {'count': 1}}}
        rows = p5.response_evidence('P5_HTTP|101|operation|403|17|END', metrics, 101, 2)
        self.assertEqual(rows, [{'actor_id': 101, 'phase': 'operation', 'status': 403, 'retry_after': '17'}])

    def test_zero_missing_cross_actor_and_wrong_status_counts_are_rejected(self):
        for log, metrics in [
            ('', {}),
            ('P5_HTTP|101|operation|429||END', {'p5_status_2xx': {'values': {'count': 1}}}),
            ('P5_HTTP|99|operation|429||END', {'p5_status_429': {'values': {'count': 1}}}),
            ('P5_HTTP|101|undefined|429||END', {'p5_status_429': {'values': {'count': 1}}}),
        ]:
            with self.subTest(log=log), self.assertRaises(p5.gate.EvidenceInvalid):
                p5.response_evidence(log, metrics, 101, 2)

    def test_collector_samples_are_taken_before_actor_completion(self):
        future = MagicMock()
        future.done.side_effect = [False, False, True]
        future.result.return_value = subprocess.CompletedProcess([], 0, '', '')
        with patch.object(p5, 'ThreadPoolExecutor') as executor, \
             patch.object(p5, 'postgres_snapshot', return_value={'locks': []}), \
             patch.object(p5, 'pgbouncer_snapshot', return_value={'cl_waiting': 2}), \
             patch.object(p5.time, 'sleep'):
            executor.return_value.__enter__.return_value.submit.return_value = future
            _, samples = p5.capture_during(lambda: None)
        self.assertEqual(len(samples), 1)
        self.assertEqual(samples[0]['pgbouncer']['cl_waiting'], 2)
        self.assertGreater(samples[0]['monotonic_ns'], 0)

    def test_post_only_sample_is_rejected(self):
        future = MagicMock()
        future.done.side_effect = [False, True, True]
        with patch.object(p5, 'ThreadPoolExecutor') as executor, \
             patch.object(p5, 'postgres_snapshot', return_value={}), \
             patch.object(p5, 'pgbouncer_snapshot', return_value={}), \
             patch.object(p5.time, 'sleep'):
            executor.return_value.__enter__.return_value.submit.return_value = future
            with self.assertRaises(p5.gate.EvidenceInvalid):
                p5.capture_during(lambda: None)

    def test_shared_session_fixture_keeps_real_ability_gate_for_distinct_actors(self):
        upload = 'b523d6aa-7d19-4188-b3e1-bc54859f2c42'
        with patch.object(p5, 'http_json', return_value=(201, {'data': {'upload_id': upload}}, None)), \
             patch.object(p5, 'psql', return_value=upload+'\n') as query:
            result, evidence = p5.create_shared_upload({'shape': 'same-resource'}, 108)
        self.assertEqual(result, upload)
        self.assertEqual(evidence['ownership_fixture'], 'system-minted-created_by-null')
        self.assertIn('SET created_by = NULL', query.call_args.args[0])
        self.assertIn('AND created_by = 108', query.call_args.args[0])

    def test_failed_setup_preserves_actual_status_without_seeding_session(self):
        with patch.object(p5, 'http_json', return_value=(429, {}, '60')), \
             patch.object(p5, 'psql') as query:
            upload, evidence = p5.create_shared_upload({'shape': 'same-resource'}, 108)
        self.assertIsNone(upload)
        self.assertEqual(evidence['status'], 429)
        self.assertEqual(evidence['retry_after'], '60')
        query.assert_not_called()

    def test_429_only_collision_and_create_only_progress_do_not_turn_green(self):
        for cell in p5.gate.catalog():
            if cell['shape'] == 'int4-collision':
                result = p5.verdict_for(cell, [{'statuses': {'429': 1}}])
                self.assertEqual(result['collision_verdict'], 'unconfirmed')
                self.assertEqual(result['slo'], 'unevaluated')
            elif cell['plane'] == 'product-realistic':
                result = p5.verdict_for(cell, [{'statuses': {'2xx': 1, '429': 1},
                    'http_responses': [{'phase': 'create', 'status': 201}, {'phase': 'operation', 'status': 429}]}])
                self.assertEqual(result['slo'], 'red')

    def test_all_k6_paths_capture_response_objects_and_per_vu_actors(self):
        # Execute the actual k6 module against host mocks, including error statuses.
        script = r'''
import fs from 'node:fs';
import vm from 'node:vm';
const source = fs.readFileSync('load/k6/scenarios/concurrency-live.js', 'utf8');
const results = [];
for (const surface of ['upload-tus','upload-s3','cas-lock','subtree-move','collab-issuance']) {
  for (const status of [200,403,429,503]) {
    const logs = [];
    const requests = [];
    const context = vm.createContext({__ENV: {P5_SURFACE: surface, P5_ACTOR_BASE: '100', P5_DOCUMENT_ID: '1', P5_NODE_IDS: '1', P5_MOVE_TARGET: '2'},
      console: {log: value => logs.push(value)}, Uint8Array});
    const response = {status, headers: {'Retry-After': '9'}};
    const http = {post: (url, body, options) => {
      requests.push(options.headers['X-Load-Actor']);
      return url.endsWith('/resumable-uploads') ? {status:201,headers:{},json:()=> 'upload'} : response;
    }, patch: (url, body, options) => {requests.push(options.headers['X-Load-Actor']); return response;}};
    const exports = {'k6/http': {default:http}, 'k6/execution': {default:{vu:{idInTest:2}}},
      'k6/encoding': {default:{}}, 'k6/metrics': {Counter: class {add() {}}}};
    const module = new vm.SourceTextModule(source, {context});
    await module.link(name => new vm.SyntheticModule(Object.keys(exports[name]), function() {
      for (const [key,value] of Object.entries(exports[name])) this.setExport(key,value);
    }, {context}));
    await module.evaluate();
    module.namespace.run();
    results.push({surface,status,logs,requests});
  }
}
process.stdout.write(JSON.stringify(results));
'''
        completed = subprocess.run(['node', '--experimental-vm-modules', '--input-type=module', '-e', script],
            cwd=GATES.parents[1], text=True, capture_output=True, check=True)
        for row in json.loads(completed.stdout):
            with self.subTest(surface=row['surface'], status=row['status']):
                self.assertTrue(all(actor == '101' for actor in row['requests']))
                self.assertIn(f"P5_HTTP|101|operation|{row['status']}|9|END", row['logs'])


if __name__ == '__main__':
    unittest.main()
