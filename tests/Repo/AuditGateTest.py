import copy
import datetime
import hashlib
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('audit_gate', ROOT / 'scripts/ci/audit-gate.py')
gate = importlib.util.module_from_spec(spec)
spec.loader.exec_module(gate)


def composer(): return {'advisories': [], 'abandoned': []}


def npm(nodes=None):
    nodes = nodes or {}
    counts = {k: sum(n['severity'] == k for n in nodes.values()) for k in ('info','low','moderate','high','critical')}
    return {'auditReportVersion': 2, 'vulnerabilities': nodes, 'metadata': {'vulnerabilities': dict(counts,total=len(nodes))}}


def node(name='direct', via=None, level='high'):
    return {'name': name, 'severity': level, 'via': via or [{'name': name, 'url': 'https://github.com/advisories/GHSA-abcd-1234-5678', 'severity': level}]}


class AuditGateTest(unittest.TestCase):
    def test_clean_and_medium_threshold_keep_other_scans_pending(self):
        self.assertEqual(0, gate.evaluate(composer(),0,npm(),0,{})['exit'])
        result = gate.evaluate(composer(),0,npm({'direct':node(level='moderate')}),0,{})
        self.assertEqual(0,result['exit']);self.assertEqual(1,len(result['findings']))
        self.assertEqual('pending',result['other_supply_chain_scans']['build_dev'])

    def test_direct_transitive_and_composer_high_are_blocking(self):
        report = npm({'direct':node(), 'child':node('child',['direct'])})
        result = gate.evaluate(composer(),0,report,1,{})
        self.assertEqual(1,result['exit']);self.assertEqual({'direct','child'}, {r['package'] for r in result['blocking']})
        doc = {'advisories':{'vendor/package':[{'packageName':'vendor/package','advisoryId':'PKSA-abcd-1234-5678','severity':'critical'}]},'abandoned':[]}
        self.assertEqual(1,gate.evaluate(doc,1,npm(),0,{})['exit'])

    def test_cycles_dangling_unknown_severity_schema_counts_and_error_fail_closed(self):
        broken = [npm({'a':node('a',['b']),'b':node('b',['a'])}), npm({'a':node('a',['missing'])}),
                  npm({'a':node('a',level='unknown')}), {}, {'auditReportVersion':3,'vulnerabilities':{}},
                  {'auditReportVersion':2,'vulnerabilities':{},'error':{'code':'ENETUNREACH'}}]
        mismatch = npm();mismatch['metadata']['vulnerabilities']['total']=1;broken.append(mismatch)
        for doc in broken:
            with self.assertRaises(ValueError):gate.npm_findings(doc,1)
        for doc in ({}, {'advisories':[],'abandoned':{'old':'replacement'}}, {'advisories':[],'abandoned':[],'malware':[]}):
            with self.assertRaises(ValueError):gate.composer_findings(doc,0)
        for status in (2,3,125):
            with self.assertRaises(ValueError):gate.composer_findings(composer(),status)
        with self.assertRaises(ValueError):gate.npm_findings(npm(),1)

    def test_missing_malformed_error_report_fail_closed_with_no_waivers(self):
        import contextlib,io
        with tempfile.TemporaryDirectory() as tmp:
            root=Path(tmp); policy=root/'policy';policy.write_text('[]\n');c=root/'composer';n=root/'npm'
            c.write_text(json.dumps(composer()));n.write_text(json.dumps(npm()))
            args=['--composer-report='+str(c),'--composer-exit=0','--npm-report='+str(n),'--npm-exit=0','--suppressions='+str(policy),'--format=json']
            with patch.dict('os.environ',{'AUDIT_SUPPRESSIONS_SHA256':''}),contextlib.redirect_stdout(io.StringIO()):self.assertEqual(0,gate.main(args))
            trusted=hashlib.sha256(policy.read_bytes()).hexdigest()
            with patch.dict('os.environ',{'AUDIT_SUPPRESSIONS_SHA256':trusted}),contextlib.redirect_stdout(io.StringIO()):
                self.assertEqual(0,gate.main(args))
                for text in ('', '{', '{"error": {}}','[]'):
                    n.write_text(text);self.assertEqual(2,gate.main(args))
                n.unlink();self.assertEqual(2,gate.main(args))

    def test_only_exact_trusted_bounded_suppression_and_expiry(self):
        today=datetime.date(2026,10,28)
        entry={'id':'GHSA-abcd-1234-5678','package':'direct','owner':'security-owner','reason':'bounded approved mitigation','expires':'2026-11-01'}
        with tempfile.TemporaryDirectory() as tmp:
            p=Path(tmp)/'policy'
            def load(entries):
                p.write_text(json.dumps(entries));return gate.suppressions(p,hashlib.sha256(p.read_bytes()).hexdigest(),today)
            policy=load([entry]);report=npm({'direct':node()})
            self.assertEqual(0,gate.evaluate(composer(),0,report,1,policy)['exit'])
            self.assertEqual(1,gate.evaluate(composer(),0,npm({'adjacent':node('adjacent')}),1,policy)['exit'])
            expired=load([dict(entry,expires='2026-10-28')]);self.assertEqual(1,gate.evaluate(composer(),0,npm(),0,expired)['exit'])
            self.assertEqual(1,len(gate.evaluate(composer(),0,npm(),0,policy)['unused_suppressions']))
            for edit in ({'owner':''},{'reason':''},{'package':'*'},{'id':'*'},{'expires':'not-date'}):
                with self.assertRaises(ValueError):load([dict(entry,**edit)])
            with self.assertRaises(ValueError):load([entry,entry])
            p.write_text('[]')
            self.assertEqual({},gate.suppressions(p,None,today))
            self.assertEqual({},gate.suppressions(p,'0'*64,today))

    def test_raw_report_status_cannot_be_converted_to_a_clean_verdict(self):
        with self.assertRaises(ValueError):gate.npm_findings(npm({'a':node('a')}),0)
        with self.assertRaises(ValueError):gate.composer_findings(composer(),1)
        with tempfile.TemporaryDirectory() as tmp:
            p=Path(tmp)/'report';p.write_text('{"error": {"code":"network"}}')
            with self.assertRaises(ValueError):gate.read(p)

    def test_empty_policy_has_no_authority_and_blocks_all_high_without_approval(self):
        import contextlib,io
        with tempfile.TemporaryDirectory() as tmp:
            root=Path(tmp); c=root/'composer';n=root/'npm';p=root/'policy'
            p.write_text('[]');c.write_text(json.dumps(composer()))
            for level in ('high','critical'):
                n.write_text(json.dumps(npm({'direct':node(level=level),'child':node('child',['direct'],level)})))
                args=['--composer-report='+str(c),'--composer-exit=0','--npm-report='+str(n),'--npm-exit=1','--suppressions='+str(p)]
                output=io.StringIO()
                with patch.dict('os.environ',{'AUDIT_SUPPRESSIONS_SHA256':''}),contextlib.redirect_stdout(output):
                    self.assertEqual(1,gate.main(args))
                report=json.loads(output.getvalue())
                self.assertEqual('no_suppressions',report['policy_mode'])
                self.assertEqual('no_waiver_authority',report['waiver_authority'])
                self.assertEqual(2,len(report['blocking']))
                self.assertFalse(any(row['suppressed'] for row in report['findings']))

    def test_nonempty_unapproved_policy_fails_even_for_clean_reports(self):
        import contextlib,io
        with tempfile.TemporaryDirectory() as tmp:
            root=Path(tmp);c=root/'composer';n=root/'npm';p=root/'policy'
            c.write_text(json.dumps(composer()));n.write_text(json.dumps(npm()))
            entry={'id':'GHSA-abcd-1234-5678','package':'direct','owner':'candidate-claim','reason':'unapproved candidate claim','expires':'2099-01-01'}
            p.write_text(json.dumps([entry]))
            args=['--composer-report='+str(c),'--composer-exit=0','--npm-report='+str(n),'--npm-exit=0','--suppressions='+str(p)]
            for digest in ('','0'*64):
                with patch.dict('os.environ',{'AUDIT_SUPPRESSIONS_SHA256':digest}),contextlib.redirect_stdout(io.StringIO()):
                    self.assertEqual(2,gate.main(args))
            p.write_text(json.dumps([dict(entry,package='*')]))
            # Matching hashes here are synthetic fixture inputs only, never a real approval.
            digest=hashlib.sha256(p.read_bytes()).hexdigest()
            with patch.dict('os.environ',{'AUDIT_SUPPRESSIONS_SHA256':digest}),contextlib.redirect_stdout(io.StringIO()):
                self.assertEqual(2,gate.main(args))

    def test_cycle_with_concrete_advisory_preserves_high_and_critical(self):
        direct = node('renderer')
        direct['via'].append('vue')
        report = npm({'renderer':direct, 'vue':node('vue',['renderer'])})
        result = gate.evaluate(composer(),0,report,1,{})
        self.assertEqual(1,result['exit'])
        self.assertEqual({'renderer','vue'}, {r['package'] for r in result['blocking']})
        direct['via'][0]['severity'] = 'critical'
        report = npm({'renderer':direct, 'vue':node('vue',['renderer'])})
        self.assertTrue(all(r['severity']=='critical' for r in gate.npm_findings(report,1)))
