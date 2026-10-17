"""Unit tests for scripts/ci/workflow-policy.py (PLAN3 P1.2, P1.6): fixtures per policy rule, plan and gate."""

import copy
import importlib.util
import json
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


def consumer_job():
    return {'needs': 'changes', 'if': "needs.changes.outputs.has_consumer == 'true'", 'runs-on': 'ubuntu-latest',
            'timeout-minutes': 30, 'strategy': {'fail-fast': False, 'matrix': '${{ fromJSON(needs.changes.outputs.consumer_matrix) }}'},
            'steps': [{'run': 'scripts/release/consumer-check.sh "$PACKAGE"'}]}


def ci_doc():
    lanes = [policy.lane_job(lane) for lane in policy.LANES]
    jobs = {'changes': {'runs-on': 'ubuntu-latest', 'timeout-minutes': 10, 'steps': []}}
    for lane in lanes:
        jobs[lane] = {'needs': 'changes', 'runs-on': 'ubuntu-latest', 'timeout-minutes': 10, 'steps': []}
    jobs['consumer-isolation'] = consumer_job()
    jobs['ci-gate'] = {'name': 'CI gate', 'if': 'always()', 'needs': ['changes'] + lanes,
                       'runs-on': 'ubuntu-latest', 'timeout-minutes': 5, 'steps': [{'run': 'true'}]}
    return {
        'on': {'push': {'branches': ['main']}, 'pull_request': {'branches': ['main']}, 'workflow_dispatch': {}},
        'concurrency': {'group': 'g', 'cancel-in-progress': "${{ github.event_name == 'pull_request' }}"},
        'env': {'PHP_SUPPORT': json.dumps(policy.derive_support(ROOT))},
        'jobs': jobs,
    }


def split_doc():
    return {
        'on': {'push': {'tags': ['*']}},
        'jobs': {
            'qualification': {'uses': './.github/workflows/weekly-full.yml'},
            'verify-release-sha': {'needs': 'qualification', 'timeout-minutes': 20, 'steps': [
                {'env': {'RELEASE_SHA': '${{ github.sha }}'}, 'run': "required_checks=(\n 'CI gate'\n)\ngh api checks"}]},
            'split': {'needs': 'verify-release-sha', 'timeout-minutes': 20, 'steps': []},
        },
    }


def selector(status='ok', exit_code=0, suites=(), errors=()):
    return {'status': status, 'exit': exit_code, 'errors': list(errors),
            'runs': [{'suite': suite, 'engine': 'sqlite'} for suite in suites]}


def needs(results, **outputs):
    """toJSON(needs) of ci-gate: `results` maps job to result, `outputs` are the outputs of `changes`."""
    base = {'skip_reason': '', 'has_quality': 'false', 'has_fast': 'false', 'has_db': 'false', 'has_serial': 'false',
            'has_consumer': 'false', 'has_vue': 'false', 'has_hub': 'false', 'has_repo': 'false', 'has_openapi': 'false'}
    base.update({k: v for k, v in outputs.items()})
    out = {job: {'result': result, 'outputs': {}} for job, result in results.items()}
    out['changes'] = {'result': results.get('changes', 'success'), 'outputs': base}
    return out


class SingleCiTest(unittest.TestCase):
    support = policy.derive_support(ROOT)

    def plan(self, changes, pgsql=None, mysql=None):
        return policy.plan_outputs(self.support, 'changed', changes, {'pgsql': pgsql or selector(), 'mysql': mysql or selector()})

    # -- policy over the workflow documents -----------------------------------------------------------------------

    def test_ci_fixture_holds_and_the_repository_ci_passes(self):
        self.assertEqual([], policy.check_ci(ci_doc(), ROOT))

    def test_ci_has_no_path_filter(self):
        for event in ('push', 'pull_request'):
            doc = ci_doc()
            doc['on'][event]['paths'] = ['packages/**']
            self.assertTrue(any('paths filter' in e for e in policy.check_ci(doc, ROOT)), event)
        doc = ci_doc()
        doc['on']['pull_request']['paths-ignore'] = ['docs/**']
        self.assertTrue(any('paths filter' in e for e in policy.check_ci(doc, ROOT)))

    def test_gate_needs_every_job_and_always_runs(self):
        doc = ci_doc()
        doc['jobs']['ci-gate']['needs'].remove('db')
        self.assertTrue(any("does not need ['db']" in e for e in policy.check_ci(doc, ROOT)))

        doc = ci_doc()
        del doc['jobs']['ci-gate']['if']
        self.assertTrue(any('always()' in e for e in policy.check_ci(doc, ROOT)))

        doc = ci_doc()
        doc['jobs']['ci-gate']['name'] = 'Gate'
        self.assertTrue(any("must be named 'CI gate'" in e for e in policy.check_ci(doc, ROOT)))

        doc = ci_doc()
        doc['jobs']['extra'] = {'needs': 'changes', 'runs-on': 'x', 'timeout-minutes': 5, 'steps': []}
        self.assertTrue(any("does not need ['extra']" in e for e in policy.check_ci(doc, ROOT)))

    def test_every_lane_depends_on_changes(self):
        doc = ci_doc()
        del doc['jobs']['fast']['needs']
        self.assertTrue(any('fast does not need `changes`' in e for e in policy.check_ci(doc, ROOT)))

    def test_support_matrix_follows_composer(self):
        support = self.support
        self.assertEqual(['12', '13'], support['core']['lines'])
        self.assertEqual(['13'], support['filament']['lines'], 'filament requires Testbench ^11 only')
        self.assertEqual(['12', '13'], support['azgard']['lines'])
        self.assertTrue(support['azgard']['azguard'])
        self.assertFalse(support['azgard']['serial'])
        self.assertTrue(support['drive']['serial'])

        doc = ci_doc()
        declared = json.loads(doc['env']['PHP_SUPPORT'])
        declared['filament']['lines'] = ['12', '13']
        doc['env']['PHP_SUPPORT'] = json.dumps(declared)
        self.assertTrue(any('PHP_SUPPORT[filament]' in e for e in policy.check_ci(doc, ROOT)))

        doc = ci_doc()
        del doc['env']
        self.assertTrue(any('PHP_SUPPORT' in e for e in policy.check_ci(doc, ROOT)))

    def test_removed_workflows_are_reported(self):
        with tempfile.TemporaryDirectory() as tmp:
            workflows = Path(tmp, '.github', 'workflows')
            workflows.mkdir(parents=True)
            for name in ('tests.yml', 'frontend.yml'):
                (workflows / name).write_text('on: [push]\njobs: {}\n')
            errors = policy.check_dir(workflows)
        self.assertTrue(any(e.startswith('tests.yml: a per-package') for e in errors))
        self.assertTrue(any(e.startswith('frontend.yml: a per-package') for e in errors))

    def test_weekly_triggers_and_contents(self):
        doc = policy.load_workflow(ROOT / '.github' / 'workflows' / 'weekly-full.yml')
        self.assertEqual([], policy.check_weekly(doc, ROOT))

        broken = copy.deepcopy(doc)
        broken['on']['schedule'] = [{'cron': '0 4 * * 2'}]
        self.assertTrue(any('schedule must be' in e for e in policy.check_weekly(broken, ROOT)))

        broken = copy.deepcopy(doc)
        del broken['on']['workflow_call']
        self.assertTrue(any('triggers must be exactly' in e for e in policy.check_weekly(broken, ROOT)))

        broken = copy.deepcopy(doc)
        broken['jobs']['stand']['steps'] = []
        self.assertTrue(any('scripts/t stand' in e for e in policy.check_weekly(broken, ROOT)))

        broken = copy.deepcopy(doc)
        broken['jobs']['full-gate']['needs'].remove('stand')
        self.assertTrue(any("does not need ['stand']" in e for e in policy.check_weekly(broken, ROOT)))

    # -- the release handshake ------------------------------------------------------------------------------------

    def test_release_handshake(self):
        self.assertEqual([], policy.check_split(split_doc()))

        doc = split_doc()
        doc['jobs']['verify-release-sha']['needs'] = []
        self.assertTrue(any('must need qualification' in e for e in policy.check_split(doc)))

        doc = split_doc()
        del doc['jobs']['qualification']
        self.assertTrue(any('exactly one job must call' in e for e in policy.check_split(doc)))

        doc = split_doc()
        doc['jobs']['verify-release-sha']['steps'][0]['env'] = {'RELEASE_SHA': 'HEAD'}
        self.assertTrue(any('exact tagged SHA' in e for e in policy.check_split(doc)))

        doc = split_doc()
        doc['jobs']['verify-release-sha']['steps'][0]['run'] += '\nuntil gh run list; do sleep 30; done'
        self.assertTrue(any('must not poll' in e for e in policy.check_split(doc)))

        doc = split_doc()
        doc['jobs']['verify-release-sha']['steps'][0]['run'] = "required_checks=('Laravel Pint')"
        errors = policy.check_split(doc)
        self.assertTrue(any("'CI gate'" in e for e in errors))
        self.assertTrue(any('pre-P1.6' in e for e in errors))

        doc = split_doc()
        doc['jobs']['split']['needs'] = 'qualification'
        self.assertTrue(any('split must need verify-release-sha' in e for e in policy.check_split(doc)))

    # -- plan: selector JSON -> job outputs -----------------------------------------------------------------------

    def test_docs_only_is_an_explicit_skip(self):
        out = self.plan(selector('docs_only', 0))
        self.assertEqual('docs-only', out['skip_reason'])
        for lane in policy.LANES:
            self.assertEqual('false', out[f'has_{lane}'])
        self.assertEqual({'include': []}, json.loads(out['fast_matrix']))
        self.assertEqual('docs-only', self.plan(selector('no_changes', 0))['skip_reason'])

    def test_stand_only_is_an_explicit_skip(self):
        self.assertEqual('stand-only', self.plan(selector('ok', 0, ['load']))['skip_reason'])

    def test_changed_drive_plans_both_laravel_lines_and_serial(self):
        out = self.plan(selector('ok', 0, ['drive', 'documents', 'filament']),
                        pgsql=selector('ok', 0, ['drive']), mysql=selector('ok', 0, ['drive']))
        fast = json.loads(out['fast_matrix'])['include']
        self.assertEqual({('drive', '12.*', '^10.0'), ('drive', '13.*', '^11.0'), ('documents', '12.*', '^10.0'),
                          ('documents', '13.*', '^11.0'), ('filament', '13.*', '^11.0')},
                         {(r['package'], r['laravel'], r['testbench']) for r in fast})
        self.assertEqual([('drive', 'pgsql', '5432', 'vaulter'), ('drive', 'mysql', '3306', 'root')],
                         [(r['package'], r['engine'], r['port'], r['username']) for r in json.loads(out['db_matrix'])['include']])
        self.assertEqual(['drive', 'documents'], [r['package'] for r in json.loads(out['serial_matrix'])['include']],
                         'serial runs the affected suites that have serial cases; filament has none')
        self.assertEqual('', out['skip_reason'])
        self.assertEqual('true', out['has_quality'])
        self.assertEqual('false', out['has_vue'])
        self.assertEqual('true', out['has_openapi'])

    def test_exit_2_plan_is_the_full_affected_set(self):
        suites = ['core', 'media', 'drive', 'documents', 'webhooks', 'azgard', 'filament', 'load', 'repo', 'hub', 'vue']
        out = self.plan(selector('unknown', 2, suites))
        self.assertEqual('true', out['has_vue'])
        self.assertEqual('true', out['has_hub'])
        self.assertEqual('true', out['has_repo'])
        self.assertEqual(13, len(json.loads(out['fast_matrix'])['include']))  # 6 packages x 2 lines + filament x 1
        self.assertEqual(7, len(json.loads(out['quality_matrix'])['include']))
        self.assertEqual(['core', 'media', 'drive', 'documents', 'webhooks'],
                         [r['package'] for r in json.loads(out['consumer_matrix'])['include']])

    def test_harness_change_still_runs_the_repository_wide_pint(self):
        out = self.plan(selector('ok', 0, ['repo']))
        self.assertEqual(['core'], [r['package'] for r in json.loads(out['quality_matrix'])['include']])
        self.assertEqual('true', out['has_repo'])
        self.assertEqual('false', out['has_fast'])

    def test_unusable_selector_plan_is_an_error_not_a_skip(self):
        for bad in (selector('invalid_map', 2), selector('error', 2), selector('ok', 3), selector('ok', 0, ['core'], ['boom'])):
            with self.assertRaises(ValueError):
                self.plan(bad)
        with self.assertRaises(ValueError):
            self.plan(selector('ok', 0, ['core']), pgsql=selector('error', 2))

    def test_no_base_plans_everything(self):
        out = policy.plan_outputs(self.support, 'all')
        self.assertEqual('', out['skip_reason'])
        self.assertEqual('true', out['has_fast'])
        self.assertEqual(14, len(json.loads(out['db_matrix'])['include']))
        self.assertEqual(5, len(json.loads(out['serial_matrix'])['include']))
        self.assertEqual(5, len(json.loads(out['consumer_matrix'])['include']))
        with self.assertRaises(ValueError):
            policy.plan_outputs(self.support, 'changed')

    # -- webhooks and the I14 consumer lane (PLAN3 P4.19) ---------------------------------------------------------------

    def test_webhooks_is_a_php_package_of_every_lane(self):
        support = self.support
        self.assertIn('webhooks', policy.PHP_PACKAGES)
        self.assertEqual(['12', '13'], support['webhooks']['lines'])
        self.assertTrue(support['webhooks']['serial'], 'its groups.php declares serial cases (forked races)')
        self.assertFalse(support['webhooks']['azguard'])

        out = self.plan(selector('ok', 0, ['webhooks']), pgsql=selector('ok', 0, ['webhooks']), mysql=selector('ok', 0, ['webhooks']))
        self.assertEqual({('webhooks', '12.*'), ('webhooks', '13.*')},
                         {(r['package'], r['laravel']) for r in json.loads(out['fast_matrix'])['include']})
        self.assertEqual(['webhooks'], [r['package'] for r in json.loads(out['serial_matrix'])['include']])
        self.assertEqual([('webhooks', 'pgsql'), ('webhooks', 'mysql')],
                         [(r['package'], r['engine']) for r in json.loads(out['db_matrix'])['include']])

    def test_consumer_lane_follows_the_changed_consumer_packages(self):
        out = self.plan(selector('ok', 0, ['drive', 'documents', 'filament', 'azgard']))
        self.assertEqual('true', out['has_consumer'])
        self.assertEqual(['drive', 'documents'], [r['package'] for r in json.loads(out['consumer_matrix'])['include']],
                         'filament and azgard are not consumer packages (external gate D20 / own composer check)')

        out = self.plan(selector('ok', 0, ['azgard', 'filament']))
        self.assertEqual('false', out['has_consumer'])
        self.assertEqual({'include': []}, json.loads(out['consumer_matrix']))

    def test_harness_or_manifest_change_rechecks_every_consumer(self):
        # scripts/release/*, tests/ or the manifest (an unknown path plans the whole set)
        out = self.plan(selector('ok', 0, ['repo']))
        self.assertEqual(['core', 'media', 'drive', 'documents', 'webhooks'],
                         [r['package'] for r in json.loads(out['consumer_matrix'])['include']])
        self.assertEqual('true', out['has_consumer'])

    def test_docs_only_runs_no_consumer(self):
        out = self.plan(selector('docs_only', 0))
        self.assertEqual('false', out['has_consumer'])
        self.assertEqual({'include': []}, json.loads(out['consumer_matrix']))

    def test_repository_ci_has_a_blocking_consumer_isolation_job(self):
        doc = policy.load_workflow(ROOT / '.github' / 'workflows' / 'ci.yml')
        job = doc['jobs']['consumer-isolation']
        self.assertEqual([], policy.check_consumer_isolation('ci.yml', job))
        self.assertIn('consumer-isolation', doc['jobs']['ci-gate']['needs'])
        self.assertIn('has_consumer', doc['jobs']['changes']['outputs'])
        self.assertIn('consumer_matrix', doc['jobs']['changes']['outputs'])

    def test_consumer_isolation_policy_rejects_a_soft_or_dry_lane(self):
        self.assertEqual([], policy.check_consumer_isolation('ci.yml', consumer_job()))

        soft = consumer_job()
        soft['continue-on-error'] = True
        self.assertTrue(any('blocking' in e for e in policy.check_consumer_isolation('ci.yml', soft)))

        soft = consumer_job()
        soft['steps'][0]['continue-on-error'] = True
        self.assertTrue(any('blocking' in e for e in policy.check_consumer_isolation('ci.yml', soft)))

        dry = consumer_job()
        dry['steps'][0]['run'] += ' --dry-run'
        self.assertTrue(any('--dry-run' in e for e in policy.check_consumer_isolation('ci.yml', dry)))

        fixed = consumer_job()
        fixed['strategy']['matrix'] = {'package': ['core']}
        self.assertTrue(any('consumer_matrix' in e for e in policy.check_consumer_isolation('ci.yml', fixed)))

        other = consumer_job()
        other['steps'] = [{'run': 'composer test'}]
        self.assertTrue(any('consumer-check.sh' in e for e in policy.check_consumer_isolation('ci.yml', other)))

        ungated = consumer_job()
        del ungated['if']
        self.assertTrue(any('has_consumer' in e for e in policy.check_consumer_isolation('ci.yml', ungated)))

    def test_ci_requires_the_consumer_job_and_the_gate_to_need_it(self):
        doc = ci_doc()
        del doc['jobs']['consumer-isolation']
        self.assertTrue(any('no consumer-isolation job' in e for e in policy.check_ci(doc, ROOT)))

        doc = ci_doc()
        doc['jobs']['ci-gate']['needs'].remove('consumer-isolation')
        self.assertTrue(any("does not need ['consumer-isolation']" in e for e in policy.check_ci(doc, ROOT)))

    def test_gate_is_red_when_an_expected_consumer_lane_did_not_succeed(self):
        for result in ('failure', 'cancelled', 'skipped'):
            red = needs({'quality': 'skipped', 'fast': 'skipped', 'db': 'skipped', 'serial': 'skipped', 'consumer-isolation': result,
                         'vue': 'skipped', 'hub': 'skipped', 'repo': 'skipped', 'openapi': 'success'},
                        has_consumer='true', has_openapi='true', consumer_matrix=self.matrix(2))
            self.assertEqual([f'consumer: required lane is {result}'], policy.gate_errors(red), result)

        empty = needs({'quality': 'skipped', 'fast': 'skipped', 'db': 'skipped', 'serial': 'skipped', 'consumer-isolation': 'skipped',
                       'vue': 'skipped', 'hub': 'skipped', 'repo': 'skipped', 'openapi': 'success'},
                      has_consumer='true', has_openapi='true', consumer_matrix=self.matrix(0))
        self.assertIn('consumer: required lane has an empty matrix', policy.gate_errors(empty))

    # -- the release manifest and the split matrix carry webhooks -----------------------------------------------------

    def test_release_manifest_and_split_matrix_list_webhooks_once(self):
        manifest = json.loads((ROOT / 'release' / 'packages.json').read_text(encoding='utf-8'))
        entries = [p for p in manifest['php'] if p['path'] == 'packages/webhooks']
        self.assertEqual(1, len(entries))
        self.assertEqual('axioma-studio/vaulter-webhooks', entries[0]['name'])
        self.assertEqual(entries[0]['name'], entries[0]['split'])
        self.assertFalse(entries[0].get('metapackage', False))
        self.assertFalse(entries[0].get('excluded_unless_tagged', False))

        split = policy.load_workflow(ROOT / '.github' / 'workflows' / 'monorepo-split.yml')
        cells = split['jobs']['split']['strategy']['matrix']['package']
        self.assertEqual(1, cells.count('webhooks'))
        self.assertEqual(sorted(Path(p['path']).name for p in manifest['php']), sorted(cells))

    def test_consumer_packages_are_releasable_php_packages(self):
        manifest = json.loads((ROOT / 'release' / 'packages.json').read_text(encoding='utf-8'))
        paths = {Path(p['path']).name for p in manifest['php'] if not p.get('metapackage')}
        self.assertEqual(set(), set(policy.CONSUMER_PACKAGES) - paths)
        self.assertEqual(set(), set(policy.CONSUMER_PACKAGES) - set(policy.PHP_PACKAGES))

    # -- gate: fake changes + needs fixtures ----------------------------------------------------------------------

    def matrix(self, rows):
        return json.dumps({'include': [{'package': 'core'}] * rows})

    def test_gate_green_when_every_expected_lane_succeeded(self):
        green = needs({'quality': 'success', 'fast': 'success', 'db': 'skipped', 'serial': 'skipped', 'consumer-isolation': 'skipped', 'vue': 'skipped',
                       'hub': 'skipped', 'repo': 'skipped', 'openapi': 'success'},
                      has_quality='true', has_fast='true', has_openapi='true',
                      quality_matrix=self.matrix(1), fast_matrix=self.matrix(2))
        self.assertEqual([], policy.gate_errors(green))

    def test_gate_red_on_failure_cancel_and_unexpected_skip(self):
        for result in ('failure', 'cancelled', 'skipped'):
            red = needs({'quality': result, 'fast': 'success', 'db': 'skipped', 'serial': 'skipped', 'consumer-isolation': 'skipped', 'vue': 'skipped',
                         'hub': 'skipped', 'repo': 'skipped', 'openapi': 'success'},
                        has_quality='true', has_fast='true', has_openapi='true',
                        quality_matrix=self.matrix(1), fast_matrix=self.matrix(2))
            self.assertEqual([f'quality: required lane is {result}'], policy.gate_errors(red), result)

    def test_gate_red_when_an_unexpected_lane_failed_or_changes_failed(self):
        red = needs({'quality': 'success', 'fast': 'skipped', 'db': 'failure', 'serial': 'skipped', 'consumer-isolation': 'skipped', 'vue': 'skipped',
                     'hub': 'skipped', 'repo': 'skipped', 'openapi': 'success'},
                    has_quality='true', has_openapi='true', quality_matrix=self.matrix(1))
        self.assertEqual(['db: failure'], policy.gate_errors(red))
        self.assertTrue(policy.gate_errors(needs({'changes': 'failure'})))
        self.assertTrue(policy.gate_errors(needs({'changes': 'cancelled'})))

    def test_gate_red_on_an_empty_required_matrix(self):
        red = needs({'quality': 'success', 'fast': 'skipped', 'db': 'skipped', 'serial': 'skipped', 'consumer-isolation': 'skipped', 'vue': 'skipped',
                     'hub': 'skipped', 'repo': 'skipped', 'openapi': 'success'},
                    has_quality='true', has_fast='true', has_openapi='true', quality_matrix=self.matrix(1), fast_matrix=self.matrix(0))
        errors = policy.gate_errors(red)
        self.assertIn('fast: required lane has an empty matrix', errors)
        self.assertIn('fast: required lane is skipped', errors)

    def test_gate_accepts_only_an_explicit_skip_when_nothing_ran(self):
        skipped = {policy.lane_job(lane): 'skipped' for lane in policy.LANES}
        self.assertEqual([], policy.gate_errors(needs(skipped, skip_reason='docs-only')))
        self.assertEqual(['changes: no lane expected and no explicit skip reason (an empty plan is not a pass)'],
                         policy.gate_errors(needs(skipped)))
        self.assertTrue(any('yet lanes are expected' in e for e in
                            policy.gate_errors(needs(skipped, skip_reason='docs-only', has_vue='true'))))

    def test_plan_then_gate_round_trip_for_docs_only(self):
        out = self.plan(selector('docs_only', 0))
        skipped = {policy.lane_job(lane): 'skipped' for lane in policy.LANES}
        self.assertEqual([], policy.gate_errors(needs(skipped, **out)))


if __name__ == '__main__':
    unittest.main()
