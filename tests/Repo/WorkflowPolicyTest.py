"""Unit tests for scripts/ci/workflow-policy.py (PLAN3 P1.2): fixtures per policy rule."""

import copy
import importlib.util
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location('workflow_policy', ROOT / 'scripts' / 'ci' / 'workflow-policy.py')
policy = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(policy)


def mutation_doc():
    jobs = {}
    floors = {'core': '--min=95', 'media': '--min=0\nphp bin/x 95', 'drive': '--min=43',
              'documents': '--min=95', 'filament': '--min=95'}
    for pkg, floor in floors.items():
        jobs[pkg] = {'if': f"inputs.package == '{pkg}'", 'timeout-minutes': 360,
                     'steps': [{'run': f'vendor/bin/pest --mutate --path=src {floor}'}]}
    return {'on': {'workflow_dispatch': {'inputs': {'package': {
        'type': 'choice', 'options': list(policy.MUTATION_PACKAGES)}}}}, 'jobs': jobs}


def test_doc(**extra):
    doc = {
        'on': {'push': {}, 'pull_request': {}},
        'concurrency': {'group': 'g', 'cancel-in-progress': "${{ github.event_name == 'pull_request' }}"},
        'jobs': {'t': {'runs-on': 'ubuntu-latest', 'timeout-minutes': 30,
                       'steps': [{'uses': 'shivammathur/setup-php@v2', 'with': {'coverage': 'none'}},
                                 {'run': 'vendor/bin/pest --ci'}]}},
    }
    doc.update(extra)
    return doc


class WorkflowPolicyTest(unittest.TestCase):
    def errors(self, name, doc):
        return policy.check_workflow(name, doc)

    def test_repository_workflows_pass(self):
        self.assertEqual([], policy.check_dir(ROOT / '.github' / 'workflows'))

    def test_yaml12_loader_keeps_on_as_string(self):
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / 'w.yml'
            path.write_text('on:\n  workflow_dispatch:\njobs: {}\nflag: yes\nreal: true\n')
            doc = policy.load_workflow(path)
        self.assertIn('on', doc)
        self.assertNotIn(True, doc)
        self.assertEqual('yes', doc['flag'])
        self.assertIs(True, doc['real'])

    def test_comment_is_not_a_command(self):
        with tempfile.TemporaryDirectory() as tmp:
            Path(tmp, 'a.yml').write_text(
                "on: [push]\n# pest --mutate --coverage\njobs:\n  j:\n    timeout-minutes: 5\n"
                "    steps:\n      - run: echo ok # --mutate\n")
            doc = policy.load_workflow(Path(tmp, 'a.yml'))
        self.assertEqual([], self.errors('a.yml', doc))

    def test_mutation_outside_mutation_workflow(self):
        doc = test_doc()
        doc['jobs']['t']['steps'].append({'run': 'vendor/bin/pest --mutate --min=1'})
        self.assertTrue(any('--mutate outside' in e for e in self.errors('tests.yml', doc)))

    def test_mutation_manual_only_with_package_choice(self):
        self.assertEqual([], self.errors('mutation.yml', mutation_doc()))

        doc = mutation_doc()
        doc['on']['pull_request'] = {}
        self.assertTrue(any('exactly workflow_dispatch' in e for e in self.errors('mutation.yml', doc)))

        doc = mutation_doc()
        doc['on']['workflow_dispatch']['inputs']['package']['options'].remove('drive')
        self.assertTrue(any('inputs.package must be a choice' in e for e in self.errors('mutation.yml', doc)))

    def test_mutation_floor_gate_and_parallel(self):
        doc = mutation_doc()
        doc['jobs']['drive']['steps'][0]['run'] = 'vendor/bin/pest --mutate --min=40'
        self.assertTrue(any('lost its floor' in e for e in self.errors('mutation.yml', doc)))

        doc = mutation_doc()
        doc['jobs']['core']['if'] = 'true'
        self.assertTrue(any('not gated' in e for e in self.errors('mutation.yml', doc)))

        doc = mutation_doc()
        doc['jobs']['core']['steps'][0]['run'] += ' --parallel'
        self.assertTrue(any('parallel' in e for e in self.errors('mutation.yml', doc)))

    def test_load_manual_only(self):
        doc = {'on': {'workflow_dispatch': {}, 'schedule': [{'cron': '1 1 * * *'}]},
               'jobs': {'j': {'timeout-minutes': 5, 'steps': []}}}
        self.assertTrue(any('exactly workflow_dispatch' in e for e in self.errors('load.yml', doc)))

    def test_old_pr_trap_in_job_if(self):
        doc = {'on': {'workflow_dispatch': {}},
               'jobs': {'guard': {'if': "github.event_name == 'pull_request'", 'timeout-minutes': 5, 'steps': []}}}
        self.assertTrue(any('always skipped' in e for e in self.errors('load.yml', doc)))

        doc['jobs']['guard']['if'] = "inputs.run != 'regression'"
        self.assertFalse(any('always skipped' in e for e in self.errors('load.yml', doc)))

    def test_missing_timeout(self):
        doc = test_doc()
        del doc['jobs']['t']['timeout-minutes']
        self.assertTrue(any('timeout-minutes' in e for e in self.errors('tests.yml', doc)))

    def test_pr_workflow_must_cancel(self):
        doc = test_doc()
        del doc['concurrency']
        self.assertTrue(any('must cancel' in e for e in self.errors('tests.yml', doc)))

    def test_publishing_workflow_must_not_cancel(self):
        doc = {'on': {'push': {}}, 'concurrency': {'group': 'g', 'cancel-in-progress': True},
               'jobs': {'j': {'timeout-minutes': 5, 'steps': []}}}
        self.assertTrue(any('must not cancel' in e for e in self.errors('release-npm.yml', doc)))

        doc['concurrency']['cancel-in-progress'] = False
        self.assertEqual([], self.errors('release-npm.yml', doc))

    def test_no_coverage_on_pr(self):
        base = test_doc()
        self.assertEqual([], self.errors('tests.yml', base))

        for mutate in (
            lambda d: d['jobs']['t']['steps'][1].update(run='vendor/bin/pest --coverage --min=80'),
            lambda d: d['jobs']['t']['steps'][0]['with'].update(coverage='xdebug'),
            lambda d: d['jobs']['t']['steps'][1].update(env={'XDEBUG_MODE': 'coverage'}),
            lambda d: d['jobs']['t']['steps'].append({'uses': 'codecov/codecov-action@v7', 'with': {'files': 'c.xml'}}),
        ):
            doc = copy.deepcopy(base)
            mutate(doc)
            self.assertTrue(any('coverage' in e.lower() for e in self.errors('tests.yml', doc)), doc)


if __name__ == '__main__':
    unittest.main()
