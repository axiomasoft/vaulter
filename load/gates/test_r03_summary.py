"""R03 evidence admission keeps RED separate from absent/sparse/rejected proof."""
import copy
import unittest

from r03_summary import audit, LAB_B_S, stream_contract

OPS = ['OP-18', 'OP-12', 'OP-14', 'OP-16', 'OP-20', 'OP-25', 'OP-28', 'OP-29',
       'OP-34', 'OP-31', 'OP-38', 'OP-01', 'OP-02', 'OP-06', 'OP-17']
STREAMS = [('tus-upload', 'OP-02'), ('s3-multipart', 'OP-06'),
           ('s3-multipart', 'OP-07'), ('single-shot', 'OP-17'), ('download', None)]


def qualified():
    keys = [f'http_req_duration{{op:{op},tier:S}}' for op in OPS]
    meta = {'profile': 'lab', 'tier': 'S', 'expected_class_b_keys': keys,
            'run_id': 'test-run', 'commit': 'test-sha', 'source_digest': 'test-digest',
            'env_fingerprint': 'test-stand', 'dataset_id': 'test-seed', 'declared_duration': '180s'}
    metrics = {key: {'values': {'count': 120, 'p(95)': 200},
                     'thresholds': {f'p(95)<{LAB_B_S[op]}': {'ok': True}}} for key, op in zip(keys, OPS)}
    for key, limits in stream_contract('S').items():
        metrics[key] = {'values': {'count': 12, 'med': 50, 'min': 20, 'rate': 52428800},
                        'thresholds': {limit: {'ok': True} for limit in limits}}
    for op in ['OP-08', 'OP-09', 'OP-10']:
        metrics[f'http_reqs{{op:{op},tier:S}}'] = {'values': {'count': 10}}
    lab = {'identity': {k: v for k, v in meta.items() if k not in ['expected_class_b_keys', 'profile']},
           'checks': {f'L-{i}': {'ok': True, 'evidence': f'raw fact {i}'} for i in range(1, 8)}}
    return {'meta': meta, 'state': {'testRunDurationMs': 181000}, 'metrics': metrics}, lab


class R03SummaryTest(unittest.TestCase):
    def test_premature_abort_does_not_become_proof(self):
        summary, lab = qualified()
        summary['state']['testRunDurationMs'] = 32000
        report = audit(summary, '180s', lab)
        self.assertFalse(report['full_window'])
        self.assertFalse(report['proof_ready'])

    def test_sparse_trend_is_unevaluated(self):
        summary, lab = qualified()
        key = 'http_req_duration{op:OP-12,tier:S}'
        summary['metrics'][key]['values']['count'] = 20
        report = audit(summary, '180s', lab)
        self.assertEqual(report['class_b'][key]['status'], 'unevaluated')
        self.assertFalse(report['proof_ready'])

    def test_dense_full_window_can_prove_a_red_product_metric(self):
        summary, lab = qualified()
        key = 'http_req_duration{op:OP-12,tier:S}'
        summary['metrics'][key]['thresholds']['p(95)<300']['ok'] = False
        report = audit(summary, '180s', lab)
        self.assertTrue(report['proof_ready'])
        self.assertEqual(report['class_b'][key]['status'], 'fail')

    def test_zero_or_absent_stream_is_unevaluated_even_with_failed_threshold(self):
        for count in [0, None]:
            summary, lab = qualified()
            key = 'stream_throughput_mibs{scenario:download,tier:S}'
            summary['metrics'][key]['values']['count'] = count
            summary['metrics'][key]['thresholds']['med>30']['ok'] = False
            report = audit(summary, '180s', lab)
            self.assertEqual(report['streaming'][key]['status'], 'unevaluated')
            self.assertFalse(report['proof_ready'])

    def test_no_lab_facts_bad_checks_or_foreign_identity_cannot_admit(self):
        summary, lab = qualified()
        for bad in [None, {}, {**lab, 'checks': {}},
                    {**lab, 'identity': {**lab['identity'], 'run_id': 'foreign'}}]:
            self.assertFalse(audit(summary, '180s', bad)['proof_ready'])
        for field in ['ok', 'evidence']:
            bad = copy.deepcopy(lab)
            bad['checks']['L-1'][field] = False
            self.assertFalse(audit(summary, '180s', bad)['proof_ready'])

    def test_missing_expected_keys_or_download_participation_cannot_admit(self):
        for removal in ['expected', 'download', 'bytes']:
            summary, lab = qualified()
            if removal == 'expected':
                summary['meta']['expected_class_b_keys'] = summary['meta']['expected_class_b_keys'][:1]
            elif removal == 'download':
                del summary['metrics']['http_reqs{op:OP-10,tier:S}']
            else:
                summary['metrics']['accepted_sent_bytes{scenario:tus-upload,op:OP-02,tier:S}']['values']['count'] = 0
            self.assertFalse(audit(summary, '180s', lab)['proof_ready'])

    def test_threshold_reader_matches_original_k6_floors_for_all_tiers(self):
        import json
        import subprocess
        from pathlib import Path
        js = """
import fs from 'node:fs'; import vm from 'node:vm';
const mod = new vm.SourceTextModule(fs.readFileSync('load/gates/thresholds.js', 'utf8'));
await mod.link(() => {}); await mod.evaluate();
console.log(JSON.stringify(Object.fromEntries(['S0','S','M','L'].map(t => [t, mod.namespace.thresholdsFor('lab', t)]))));
"""
        result = subprocess.run(['node', '--experimental-vm-modules', '--input-type=module', '-e', js],
                                cwd=Path(__file__).resolve().parents[2], check=True, capture_output=True, text=True)
        thresholds = json.loads(result.stdout)
        for tier in thresholds:
            for key, floor in stream_contract(tier).items():
                self.assertEqual(thresholds[tier][key], floor)
        for op, floor in LAB_B_S.items():
            self.assertEqual(thresholds['S'][f'http_req_duration{{op:{op},tier:S}}'], [f'p(95)<{floor}'])

    def test_missing_threshold_cannot_prove_and_short_declared_window_is_rejected(self):
        summary, lab = qualified()
        self.assertFalse(audit(summary, '60s', lab)['proof_ready'])
        summary['metrics']['http_req_duration{op:OP-12,tier:S}']['thresholds'] = {}
        self.assertFalse(audit(summary, '180s', lab)['proof_ready'])


if __name__ == '__main__':
    unittest.main()
