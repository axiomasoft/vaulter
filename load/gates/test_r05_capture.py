#!/usr/bin/env python3
"""Offline behavioral controls for R05; no stand or DB writes."""
import copy
import importlib.util
import json
import subprocess
import sys
import tempfile
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

    def test_sample_started_during_a_short_actor_is_kept(self):
        future = MagicMock()
        future.done.side_effect = [False, True]
        future.result.return_value = subprocess.CompletedProcess([], 0, '', '')
        with patch.object(p5, 'ThreadPoolExecutor') as executor, \
             patch.object(p5, 'postgres_snapshot', return_value={'locks': []}), \
             patch.object(p5, 'pgbouncer_snapshot', return_value={'cl_waiting': 0}), \
             patch.object(p5.time, 'sleep'):
            executor.return_value.__enter__.return_value.submit.return_value = future
            _, samples = p5.capture_during(lambda: None)
        self.assertEqual(len(samples), 1)

    def test_post_only_sample_is_rejected(self):
        future = MagicMock()
        future.done.return_value = True
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

    def test_missing_actor_and_setup_only_are_rejected_by_verdict(self):
        for cell in p5.gate.catalog():
            if cell['plane'] in {'product-realistic', 'product-worst-case'}:
                with self.subTest(cell=cell['cell_id']), self.assertRaises(p5.gate.EvidenceInvalid):
                    p5.verdict_for(cell, [{'statuses': {'2xx': 1},
                        'http_responses': [{'actor_id': 101, 'phase': 'setup', 'status': 201}]}])

    def test_complete_iterations_join_actual_responses_and_metrics(self):
        responses = [{'actor_id': a, 'phase': 'operation', 'status': 200} for a in (101, 102)]
        log = '\n'.join(f'P5_ITER|{a}|{phase}|END' for a in (101, 102) for phase in ('started', 'completed'))
        metrics = {'iterations': {'values': {'count': 2}}}
        p = p5.iteration_evidence(log, metrics, 101, 2, responses)
        self.assertEqual(p['observed_actor_ids'], [101, 102])
        for bad in (log.replace('P5_ITER|102|completed|END', ''), log.replace('102', '101'),
                    log.replace('completed', 'aborted')):
            with self.assertRaises(p5.gate.EvidenceInvalid):
                p5.iteration_evidence(bad, metrics, 101, 2, responses)
        with self.assertRaises(p5.gate.EvidenceInvalid):
            p5.iteration_evidence(log, metrics, 101, 2, responses[:1])

    def test_no_sweep_and_no_overlap_controls(self):
        overlap = {'upload_id': 'u', 'backend_pid': 1, 'sweep_exit': 0,
            'target_refused': True, 'sweepable': True, 'lock_observed': True, 'lock_after_sweep': True,
            'held_ns': 1, 'sweep_start_ns': 2, 'sweep_end_ns': 3, 'release_ns': 4,
            'final_state': {'received_bytes': 4}, 'retry_response': {'status': 204}}
        p5.validate_overlap({'overlap': overlap})
        for field, value in [('target_refused', False), ('sweep_exit', None), ('release_ns', 2), ('lock_after_sweep', False)]:
            with self.assertRaises(p5.gate.EvidenceInvalid):
                p5.validate_overlap({'overlap': {**overlap, field: value}})

    def test_real_overlap_orchestrator_runs_sweep_and_releases_patch(self):
        upload = 'b523d6aa-7d19-4188-b3e1-bc54859f2c42'
        completed = subprocess.CompletedProcess([], 0, 'P5_HTTP|101|operation|204||END', '')
        def command(*args, **kwargs):
            if 'cat' in args:
                return subprocess.CompletedProcess([], 0, json.dumps({'upload_id': upload, 'backend_pid': 7}), '')
            return subprocess.CompletedProcess([], 0, 'Swept 0 stale upload sessions (1 skipped)', f'P5_SWEEP_TARGET|{upload}|refused|END')
        with tempfile.TemporaryDirectory() as tmp, \
             patch.object(p5, 'capture_during', return_value=(completed, [])), \
             patch.object(p5, 'compose', side_effect=command) as compose, \
             patch.object(p5, 'psql', side_effect=[upload + '\nUPDATE 1\n', '1', '1', json.dumps({'upload_id':upload,'status':'uploading','received_bytes':4}), '']), \
             patch.object(p5, 'http_json', return_value=(204, {}, None)) as retry:
            root = Path(tmp)
            (root / 'sweeper').mkdir()
            _, _, overlap = p5.run_patch_overlap(lambda: completed, upload, root,
                {'cell_id':'overlap'}, 1, 101)
            self.assertEqual(overlap['sweep_exit'], 0)
            self.assertTrue(overlap['sweepable'])
            sweep_calls = [c.args for c in compose.call_args_list if 'vaulter:uploads:sweep' in c.args]
            self.assertEqual(len(sweep_calls), 1)
            self.assertNotIn('artisan', sweep_calls[0])
            self.assertTrue(any('touch' in c.args for c in compose.call_args_list))
            self.assertEqual(retry.call_args.args[2], bytes([0x70, 0x35, 1, 0x0a]))
            self.assertTrue((root / 'sweeper' / 'overlap.a1.json').is_file())

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


class R05PersistedParticipationTest(unittest.TestCase):
    """Read-only regression controls on the admitted 56-cell R05 capture."""

    ROOT = GATES.parents[1] / 'plans/archive/VLT-LOAD/artifacts/R05/adcdcdb7-2c7f-49dc-a0d3-8ac704bb51fe'
    DIGEST = 'a248913b1c49555b4cd397b31df2044cd1a54585410c7362987cb2899dde2074'

    @classmethod
    def setUpClass(cls):
        cls.cells = json.loads((cls.ROOT / 'cells.json').read_text())
        cls.verdicts = json.loads((cls.ROOT / 'cell-verdicts.json').read_text())
        cls.products = [cell for cell in p5.gate.catalog()
                        if cell['surface'] == 'subtree-move' and cell['n'] == 200]
        representatives = {row['cell_id']: row['attempt'] for row in cls.cells}
        cls.attempts = {cell['cell_id']: json.loads((cls.ROOT / 'attempts' /
                        f"{cell['cell_id']}.attempt-{representatives[cell['cell_id']]}.json").read_text())
                        for cell in cls.products}

    def readback(self, cell, row, *, cells=True, attempts=True, verdict=False):
        """Replace persisted JSON reads in memory; never write the raw run."""
        original = Path.read_text
        cid = cell['cell_id']
        attempt_path = self.ROOT / 'attempts' / f"{cid}.attempt-{row['attempt']}.json"

        def read(path, *args, **kwargs):
            if cells and path == self.ROOT / 'cells.json':
                return json.dumps([row if item['cell_id'] == cid else item for item in self.cells])
            if attempts and path == attempt_path:
                return json.dumps(row)
            if verdict and path == self.ROOT / 'cell-verdicts.json':
                updated = copy.deepcopy(self.verdicts)
                rows = [row if a == row['attempt'] else json.loads(original(
                        self.ROOT / 'attempts' / f'{cid}.attempt-{a}.json'))
                        for a in range(1, p5.repeat_count(cell) + 1)]
                updated['cells'] = [p5.verdict_for(cell, rows) if item['cell_id'] == cid else item
                                    for item in updated['cells']]
                return json.dumps(updated)
            return original(path, *args, **kwargs)

        return patch.object(Path, 'read_text', read)

    def invalid_rows(self, cell):
        source = self.attempts[cell['cell_id']]
        participation = source['participation']
        actor = source['actor_ids'][0]
        cases = [
            ('missing-actor', {'actor_ids': source['actor_ids'][1:],
                'http_responses': [r for r in source['http_responses'] if r['actor_id'] != actor]}),
            ('null-participation', {'participation': None}),
            ('empty-participation', {'participation': {}}),
            ('incomplete-started', {'participation': {**participation, 'started': participation['started'][:-1]}}),
            ('incomplete-completed', {'participation': {**participation, 'completed': participation['completed'][:-1]}}),
            ('incomplete-iterations', {'participation': {**participation, 'iterations': cell['n'] - 1}}),
            ('aborted-iteration', {'participation': {**participation, 'aborted': [actor]}}),
            ('setup-only', {'http_responses': [{**r, 'phase': 'setup'} for r in source['http_responses']]}),
            ('create-without-operation', {'http_responses': [{**r, 'phase': 'create', 'status': 201}
                                                            for r in source['http_responses']]}),
            ('original-b07-02', {'actor_ids': [actor], 'participation': None, 'http_5xx': 0,
                'statuses': {'2xx': 1, '409': 0, '429': 0, '5xx': 0, 'other': 0},
                'http_responses': [{'actor_id': actor, 'phase': 'operation', 'status': 200, 'retry_after': None}]}),
        ]
        for name, changes in cases:
            row = copy.deepcopy(source)
            row.update(changes)
            yield name, row
        row = copy.deepcopy(source)
        del row['participation']
        yield 'missing-participation', row

    def test_structural_rejects_incomplete_product_participation(self):
        for cell in self.products:
            for name, row in self.invalid_rows(cell):
                with self.subTest(cell=cell['cell_id'], control=name), self.readback(cell, row), \
                     self.assertRaises(p5.gate.EvidenceInvalid):
                    p5.gate.verify_run_dir(self.ROOT)

    def test_verdict_rejects_incomplete_product_participation(self):
        for cell in self.products:
            for name, row in self.invalid_rows(cell):
                with self.subTest(cell=cell['cell_id'], control=name), self.assertRaises(p5.gate.EvidenceInvalid):
                    p5.verdict_for(cell, [row])

    def test_full_verifier_rejects_incomplete_cells_and_attempts_independently(self):
        for cell in self.products:
            for name, row in self.invalid_rows(cell):
                for cells, attempts in ((True, False), (False, True), (True, True)):
                    with self.subTest(cell=cell['cell_id'], control=name, cells=cells, attempts=attempts), \
                         self.readback(cell, row, cells=cells, attempts=attempts), \
                         self.assertRaises(p5.gate.EvidenceInvalid):
                        p5.verify_artifacts(self.ROOT)

    def test_complete_product_planes_and_raw_replay_remain_valid(self):
        self.assertEqual({cell['plane'] for cell in self.products}, {'product-realistic', 'product-worst-case'})
        for cell in self.products:
            self.assertEqual(p5.verdict_for(cell, [self.attempts[cell['cell_id']]])['evidence'], 'valid')
        self.assertEqual(p5.digest_tree(self.ROOT), self.DIGEST)
        self.assertEqual(p5.verify_artifacts(self.ROOT, prior_digest=self.DIGEST), self.DIGEST)
        self.assertEqual(p5.digest_tree(self.ROOT), self.DIGEST)

    def test_explicit_create_failures_remain_observed_outcomes(self):
        for cell in self.products:
            row = copy.deepcopy(self.attempts[cell['cell_id']])
            row['http_responses'] = [{**r, 'phase': 'create', 'status': 403} for r in row['http_responses']]
            row['statuses'] = {'2xx': 0, '409': 0, '429': 0, '5xx': 0, 'other': cell['n']}
            with self.subTest(cell=cell['cell_id']), self.readback(cell, row, verdict=True):
                self.assertEqual(p5.verdict_for(cell, [row])['evidence'], 'valid')
                p5.gate.verify_run_dir(self.ROOT)
                self.assertEqual(p5.verify_artifacts(self.ROOT, prior_digest=self.DIGEST), self.DIGEST)


if __name__ == '__main__':
    unittest.main()
