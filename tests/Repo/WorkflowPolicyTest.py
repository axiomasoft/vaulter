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
                "on: [push, pull_request]\nconcurrency: {group: test, cancel-in-progress: true}\n# pest --coverage\njobs:\n  j:\n    timeout-minutes: 5\n"
                "    steps:\n      - run: echo ok # --coverage\n")
            doc = policy.load_workflow(Path(tmp, 'a.yml'))
        self.assertEqual([], self.errors('a.yml', doc))

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


def contract_job():
    return {'needs': 'changes', 'if': "needs.changes.outputs.has_contract_bc == 'true'", 'timeout-minutes': 15,
            'env': {**{key: '${{ vars.' + key + ' }}' for key in ('BC_HOST_BOOTSTRAP', 'BC_TOOL_ROOT', 'BC_TOOL_REV', 'BC_RUNTIME_APPROVAL', 'BC_SHA256SUMS')},
                    'BC_REQUIRED_LINES': ','.join(policy.REQUIRED_BC_LINES), 'BC_SELECTED_LINES': 'all'},
            'steps': [{'uses': 'actions/checkout@v4', 'with': {'fetch-depth': 0, 'fetch-tags': True, 'persist-credentials': False}},
                      {'run': '"$BC_HOST_PYTHON" -I "$BC_HOST_BOOTSTRAP"'}]}


def npm_job(workspace, lane):
    steps = [{'uses': 'actions/checkout@v4', 'with': {'persist-credentials': False}},
             {'run': f'npm run api:surface:check --workspace {workspace}'}]
    if workspace == '@vaulter/vue':
        steps.append({'run': 'npm run api:check --workspace @vaulter/vue'})
    return {'needs': 'changes', 'if': f"needs.changes.outputs.has_{lane} == 'true'", 'timeout-minutes': 30,
            'permissions': {'contents': 'read'}, 'steps': steps}


def ci_doc():
    lanes = [policy.lane_job(lane) for lane in policy.LANES]
    jobs = {'changes': {'runs-on': 'ubuntu-latest', 'timeout-minutes': 10, 'steps': []}}
    for lane in lanes:
        jobs[lane] = {'needs': 'changes', 'runs-on': 'ubuntu-latest', 'timeout-minutes': 10, 'steps': []}
    jobs['sca'] = copy.deepcopy(policy.load_workflow(ROOT / '.github/workflows/ci.yml')['jobs']['sca'])
    jobs['consumer-isolation'] = consumer_job()
    jobs['contract-bc'] = contract_job()
    jobs['vue'] = npm_job('@vaulter/vue', 'vue')
    jobs['vue-collaboration'] = npm_job('@vaulter/vue-collaboration', 'vue_collaboration')
    jobs['changes']['outputs'] = {output: '${{ steps.plan.outputs.' + output + ' }}' for output in ('has_contract_bc', 'contract_bc_required_lines', 'contract_bc_selected_lines')}
    jobs['ci-gate'] = {'name': 'CI gate', 'if': 'always()', 'needs': ['changes'] + lanes + ['sca'],
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
            'route': {'needs': 'verify-release-sha', 'timeout-minutes': 10, 'steps': [
                {'run': 'php scripts/release/route-release.php --tag="$T" --evidence-dir=release-evidence'}]},
            'split': {'needs': 'route', 'timeout-minutes': 20, 'steps': [{'env': {'T': '${{ secrets.GH_ACCESS_TOKEN }}'}}]},
        },
    }


def publisher_doc(publisher, credential):
    return {
        'on': {'push': {'tags': ['*']}},
        'jobs': {
            'route': {'timeout-minutes': 10, 'steps': [
                {'run': 'php scripts/release/route-release.php --tag="$T" --evidence-dir=release-evidence'}]},
            publisher: {'needs': 'route', 'timeout-minutes': 20, 'steps': [{'uses': credential}]},
        },
    }


def selector(status='ok', exit_code=0, suites=(), errors=()):
    return {'status': status, 'exit': exit_code, 'errors': list(errors),
            'runs': [{'suite': suite, 'engine': 'sqlite'} for suite in suites]}


def needs(results, **outputs):
    """toJSON(needs) of ci-gate: `results` maps job to result, `outputs` are the outputs of `changes`."""
    base = {'skip_reason': '', 'has_quality': 'false', 'has_fast': 'false', 'has_db': 'false', 'has_serial': 'false',
            'has_consumer': 'false', 'has_vue': 'false', 'has_vue_collaboration': 'false', 'has_hub': 'false', 'has_repo': 'false', 'has_openapi': 'false', 'has_contract_bc': 'false'}
    base.update({k: v for k, v in outputs.items()})
    if not base['skip_reason'] and any(base.get('has_' + lane) == 'true' for lane in policy.LANES):
        base['has_contract_bc'] = outputs.get('has_contract_bc', 'true')
    for field in ('contract_bc_required_lines', 'contract_bc_selected_lines'):
        base.setdefault(field, json.dumps(list(policy.REQUIRED_BC_LINES)))
    out = {job: {'result': result, 'outputs': {}} for job, result in results.items()}
    out.setdefault('contract-bc', {'result': 'success' if base['has_contract_bc'] == 'true' else 'skipped', 'outputs': {}})
    out.setdefault('sca', {'result': 'success', 'outputs': {}})
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
        self.assertEqual(['13'], support['core']['lines'])
        self.assertEqual(['13'], support['filament']['lines'], 'filament requires Testbench ^11 only')
        self.assertEqual(['13'], support['azguard']['lines'])
        self.assertTrue(support['azguard']['azguard'])
        self.assertFalse(support['azguard']['serial'])
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
        doc['jobs']['split']['needs'] = 'verify-release-sha'
        self.assertTrue(any('split must need route' in e for e in policy.check_split(doc)))

        doc = split_doc()
        doc['jobs']['route']['needs'] = 'qualification'
        self.assertTrue(any('route must need verify-release-sha' in e for e in policy.check_split(doc)))

        doc = split_doc()
        doc['jobs']['route']['steps'] = [{'run': 'echo ok'}]
        self.assertTrue(any('must run scripts/release/route-release.php' in e for e in policy.check_split(doc)))

        doc = split_doc()
        doc['jobs']['route']['steps'][0]['env'] = {'T': '${{ secrets.GH_ACCESS_TOKEN }}'}
        self.assertTrue(any('must not hold the publisher credential GH_ACCESS_TOKEN' in e for e in policy.check_split(doc)))

    def test_npm_and_image_publishers_route_before_any_credential(self):
        for name, (publisher, credentials) in policy.PUBLISHERS.items():
            doc = publisher_doc(publisher, credentials[0])
            self.assertEqual([], policy.check_publisher(name, doc), name)

            missing = publisher_doc(publisher, credentials[0])
            missing['jobs'][publisher]['needs'] = []
            self.assertTrue(any('must need route' in e for e in policy.check_publisher(name, missing)), name)

            leaking = publisher_doc(publisher, credentials[0])
            leaking['jobs']['route']['steps'].append({'uses': credentials[0]})
            self.assertTrue(any('must not hold the publisher credential' in e for e in policy.check_publisher(name, leaking)), name)

            bare = publisher_doc(publisher, credentials[0])
            bare['jobs']['route']['steps'] = [{'run': 'echo ok'}]
            self.assertTrue(any('route-release.php' in e for e in policy.check_publisher(name, bare)), name)

    def test_repository_publishers_hold_credentials_only_after_route(self):
        workflows = ROOT / '.github' / 'workflows'
        for name in policy.PUBLISHERS:
            self.assertEqual([], policy.check_publisher(name, policy.load_workflow(workflows / name)), name)

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

    def test_changed_drive_plans_the_laravel_13_line_and_serial(self):
        out = self.plan(selector('ok', 0, ['drive', 'documents', 'filament']),
                        pgsql=selector('ok', 0, ['drive']), mysql=selector('ok', 0, ['drive']))
        fast = json.loads(out['fast_matrix'])['include']
        self.assertEqual({('drive', '13.*', '^11.0'), ('documents', '13.*', '^11.0'), ('filament', '13.*', '^11.0')},
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
        suites = ['core', 'integration', 'media', 'drive', 'documents', 'webhooks', 'collaboration', 'azguard', 'filament', 'load', 'repo', 'hub', 'vue',
                  'vue-collaboration']
        out = self.plan(selector('unknown', 2, suites))
        self.assertEqual('true', out['has_vue'])
        self.assertEqual('true', out['has_vue_collaboration'])
        self.assertEqual('true', out['has_hub'])
        self.assertEqual('true', out['has_repo'])
        self.assertEqual(9, len(json.loads(out['fast_matrix'])['include']))  # one Laravel 13 line for each package
        self.assertEqual(9, len(json.loads(out['quality_matrix'])['include']))
        self.assertEqual(['core', 'media', 'drive', 'documents', 'webhooks', 'collaboration'],
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
        self.assertEqual('true', out['has_vue_collaboration'])
        self.assertEqual(18, len(json.loads(out['db_matrix'])['include']))
        self.assertEqual(7, len(json.loads(out['serial_matrix'])['include']))
        self.assertEqual(6, len(json.loads(out['consumer_matrix'])['include']))
        with self.assertRaises(ValueError):
            policy.plan_outputs(self.support, 'changed')

    # -- webhooks and the I14 consumer lane (PLAN3 P4.19) ---------------------------------------------------------------

    def test_integration_is_a_root_suite_of_every_test_lane(self):
        support = policy.derive_support(ROOT)
        self.assertEqual('.', support['integration']['dir'])
        self.assertEqual(['13'], support['integration']['lines'])
        self.assertTrue(support['integration']['serial'])
        out = self.plan(selector('ok', 0, ['integration']), pgsql=selector('ok', 0, ['integration']), mysql=selector('ok', 0, ['integration']))
        self.assertEqual({('integration', '13.*')},
                         {(r['package'], r['laravel']) for r in json.loads(out['fast_matrix'])['include']})
        self.assertEqual(['integration'], [r['package'] for r in json.loads(out['serial_matrix'])['include']])
        self.assertEqual([('integration', 'pgsql'), ('integration', 'mysql')],
                         [(r['package'], r['engine']) for r in json.loads(out['db_matrix'])['include']])
        for workflow in ('ci.yml', 'weekly-full.yml'):
            doc = policy.load_workflow(ROOT / '.github' / 'workflows' / workflow)
            declared = json.loads(doc['env']['PHP_SUPPORT'])
            del declared['integration']
            doc['env']['PHP_SUPPORT'] = json.dumps(declared)
            self.assertTrue(any('PHP_SUPPORT[integration]' in error for error in policy.check_support(workflow, doc, ROOT)))
        matrix = (ROOT / 'scripts' / 't-matrix').read_text()
        for package in ('integration', 'webhooks', 'collaboration'):
            self.assertIn(package, matrix.split('packages=', 1)[1].splitlines()[0].split(','))

    def test_webhooks_is_a_php_package_of_every_lane(self):
        support = self.support
        self.assertIn('webhooks', policy.PHP_PACKAGES)
        self.assertEqual(['13'], support['webhooks']['lines'])
        self.assertTrue(support['webhooks']['serial'], 'its groups.php declares serial cases (forked races)')
        self.assertFalse(support['webhooks']['azguard'])

        out = self.plan(selector('ok', 0, ['webhooks']), pgsql=selector('ok', 0, ['webhooks']), mysql=selector('ok', 0, ['webhooks']))
        self.assertEqual({('webhooks', '13.*')},
                         {(r['package'], r['laravel']) for r in json.loads(out['fast_matrix'])['include']})
        self.assertEqual(['webhooks'], [r['package'] for r in json.loads(out['serial_matrix'])['include']])
        self.assertEqual([('webhooks', 'pgsql'), ('webhooks', 'mysql')],
                         [(r['package'], r['engine']) for r in json.loads(out['db_matrix'])['include']])

    def test_consumer_lane_follows_the_changed_consumer_packages(self):
        out = self.plan(selector('ok', 0, ['drive', 'documents', 'filament', 'azguard']))
        self.assertEqual('true', out['has_consumer'])
        self.assertEqual(['drive', 'documents'], [r['package'] for r in json.loads(out['consumer_matrix'])['include']],
                         'filament and azguard are not consumer packages (external gate D20 / own composer check)')

        out = self.plan(selector('ok', 0, ['azguard', 'filament']))
        self.assertEqual('false', out['has_consumer'])
        self.assertEqual({'include': []}, json.loads(out['consumer_matrix']))

    def test_harness_or_manifest_change_rechecks_every_consumer(self):
        # scripts/release/*, tests/ or the manifest (an unknown path plans the whole set)
        out = self.plan(selector('ok', 0, ['repo']))
        self.assertEqual(['core', 'media', 'drive', 'documents', 'webhooks', 'collaboration'],
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
                         'vue': 'skipped', 'vue-collaboration': 'skipped', 'hub': 'skipped', 'repo': 'skipped', 'openapi': 'success'},
                        has_consumer='true', has_openapi='true', consumer_matrix=self.matrix(2))
            self.assertEqual([f'consumer: required lane is {result}'], policy.gate_errors(red), result)

        empty = needs({'quality': 'skipped', 'fast': 'skipped', 'db': 'skipped', 'serial': 'skipped', 'consumer-isolation': 'skipped',
                       'vue': 'skipped', 'vue-collaboration': 'skipped', 'hub': 'skipped', 'repo': 'skipped', 'openapi': 'success'},
                      has_consumer='true', has_openapi='true', consumer_matrix=self.matrix(0))
        self.assertIn('consumer: required lane has an empty matrix', policy.gate_errors(empty))

    # -- collaboration: PHP package, hub image, @vaulter/vue-collaboration (PLAN3 P5.14) -------------------------------

    def test_collaboration_is_a_php_package_of_every_lane(self):
        support = self.support
        self.assertEqual(['13'], support['collaboration']['lines'])
        self.assertTrue(support['collaboration']['serial'], 'its groups.php declares serial cases (pgsql races)')
        self.assertTrue(support['collaboration']['types'])
        self.assertFalse(support['collaboration']['azguard'])

        out = self.plan(selector('ok', 0, ['collaboration']), pgsql=selector('ok', 0, ['collaboration']), mysql=selector('ok', 0, ['collaboration']))
        self.assertEqual({('collaboration', '13.*')},
                         {(r['package'], r['laravel']) for r in json.loads(out['fast_matrix'])['include']})
        self.assertEqual(['collaboration'], [r['package'] for r in json.loads(out['serial_matrix'])['include']])
        self.assertEqual(['collaboration'], [r['package'] for r in json.loads(out['consumer_matrix'])['include']])

    def test_vue_collaboration_lane_follows_its_suite(self):
        out = self.plan(selector('ok', 0, ['vue-collaboration']))
        self.assertEqual('true', out['has_vue_collaboration'])
        self.assertEqual('false', out['has_vue'])
        self.assertEqual('false', out['has_hub'])
        out = self.plan(selector('ok', 0, ['vue']))
        self.assertEqual('false', out['has_vue_collaboration'])

    def test_repository_ci_has_the_collaboration_node_jobs(self):
        for name in ('ci.yml', 'weekly-full.yml'):
            doc = policy.load_workflow(ROOT / '.github' / 'workflows' / name)
            self.assertIn('vue-collaboration', doc['jobs'], name)
            self.assertIn('@vaulter/vue-collaboration', doc['jobs']['vue-collaboration']['name'], name)
            self.assertEqual('@vaulter/collaboration-hub (Node 22)' if name == 'weekly-full.yml' else '@vaulter/collaboration-hub (Node ${{ matrix.node }})',
                             doc['jobs']['hub']['name'], name)
        ci = policy.load_workflow(ROOT / '.github' / 'workflows' / 'ci.yml')
        self.assertIn('vue-collaboration', ci['jobs']['ci-gate']['needs'])
        self.assertIn('has_vue_collaboration', ci['jobs']['changes']['outputs'])
        weekly = policy.load_workflow(ROOT / '.github' / 'workflows' / 'weekly-full.yml')
        self.assertIn('vue-collaboration', weekly['jobs']['full-gate']['needs'])

    def test_hub_image_workflow_is_named_for_the_collaboration_hub(self):
        workflows = ROOT / '.github' / 'workflows'
        self.assertFalse((workflows / 'hocuspocus-image.yml').exists())
        doc = policy.load_workflow(workflows / 'collaboration-hub-image.yml')
        self.assertEqual(['collaboration-hub-v*'], doc['on']['push']['tags'])
        self.assertTrue(doc['env']['IMAGE_NAME'].endswith('/vaulter-collaboration-hub'))
        smoke = doc['jobs']['smoke']
        self.assertEqual("github.event_name == 'workflow_dispatch'", smoke['if'])
        build = next(s for s in smoke['steps'] if 'docker/build-push-action@' in s.get('uses', ''))
        self.assertEqual('./packages/collaboration/hub', build['with']['context'])
        self.assertIs(False, build['with']['push'])
        self.assertEqual({'contents': 'read'}, smoke['permissions'])
        steps = doc['jobs']['image']['steps']
        self.assertFalse(any('build-push-action' in s.get('uses', '') for s in steps))
        self.assertTrue(any('imagetools create' in s.get('run', '') and '@${DIGEST}' in s['run'] for s in steps))
        manifest = json.loads((ROOT / 'release' / 'packages.json').read_text(encoding='utf-8'))
        self.assertEqual(['ghcr.io/axiomasoft/vaulter-collaboration-hub'], [i['name'] for i in manifest['images']])
        self.assertEqual(('private', 'collaboration-hub-v'), (manifest['images'][0]['channel'], manifest['images'][0]['tag_prefix']))

    def test_release_manifest_lists_collaboration_once_and_the_npm_package(self):
        manifest = json.loads((ROOT / 'release' / 'packages.json').read_text(encoding='utf-8'))
        entries = [p for p in manifest['php'] if p['path'] == 'packages/collaboration']
        self.assertEqual(1, len(entries))
        self.assertEqual('axioma-studio/vaulter-collaboration', entries[0]['name'])
        self.assertEqual(entries[0]['name'], entries[0]['split'])
        npm = [p for p in manifest['npm'] if p['name'] == '@vaulter/vue-collaboration']
        self.assertEqual([('packages/vue-collaboration', '1', 'private', 'restricted')],
                         [(p['path'], p['api'], p['channel'], p['access']) for p in npm])
        self.assertEqual('private', entries[0]['channel'])
        meta = json.loads((ROOT / 'packages' / 'vaulter' / 'composer.json').read_text(encoding='utf-8'))
        self.assertEqual([], [name for name in meta.get('require', {}) if 'collaboration' in name], 'D6: the metapackage does not include collaboration')
        split = policy.load_workflow(ROOT / '.github' / 'workflows' / 'monorepo-split.yml')
        self.assertEqual('${{ fromJSON(needs.route.outputs.matrix) }}', split['jobs']['split']['strategy']['matrix']['package'])
        self.assertEqual('collaboration-v', entries[0]['tag_prefix'])

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
        # the matrix is computed from the manifest by route-release.php; webhooks is a public php entry of the v1 line
        self.assertEqual('${{ fromJSON(needs.route.outputs.matrix) }}', split['jobs']['split']['strategy']['matrix']['package'])
        self.assertEqual(['v'], [e['tag_prefix'] for e in json.loads((ROOT / 'release' / 'packages.json').read_text(encoding='utf-8'))['php'] if e['name'] == 'axioma-studio/vaulter-webhooks'])
        self.assertIn('packages/webhooks', [p['path'] for p in manifest['php']])

    def test_consumer_packages_are_releasable_php_packages(self):
        manifest = json.loads((ROOT / 'release' / 'packages.json').read_text(encoding='utf-8'))
        paths = {Path(p['path']).name for p in manifest['php'] if not p.get('metapackage')}
        self.assertEqual(set(), set(policy.CONSUMER_PACKAGES) - paths)
        self.assertEqual(set(), set(policy.CONSUMER_PACKAGES) - set(policy.PHP_PACKAGES))

    # -- gate: fake changes + needs fixtures ----------------------------------------------------------------------

    def matrix(self, rows):
        return json.dumps({'include': [{'package': 'core'}] * rows})

    def test_gate_green_when_every_expected_lane_succeeded(self):
        green = needs({'quality': 'success', 'fast': 'success', 'db': 'skipped', 'serial': 'skipped', 'consumer-isolation': 'skipped', 'vue': 'skipped', 'vue-collaboration': 'skipped',
                       'hub': 'skipped', 'repo': 'skipped', 'openapi': 'success'},
                      has_quality='true', has_fast='true', has_openapi='true',
                      quality_matrix=self.matrix(1), fast_matrix=self.matrix(2))
        self.assertEqual([], policy.gate_errors(green))

    def test_gate_red_on_failure_cancel_and_unexpected_skip(self):
        for result in ('failure', 'cancelled', 'skipped'):
            red = needs({'quality': result, 'fast': 'success', 'db': 'skipped', 'serial': 'skipped', 'consumer-isolation': 'skipped', 'vue': 'skipped', 'vue-collaboration': 'skipped',
                         'hub': 'skipped', 'repo': 'skipped', 'openapi': 'success'},
                        has_quality='true', has_fast='true', has_openapi='true',
                        quality_matrix=self.matrix(1), fast_matrix=self.matrix(2))
            self.assertEqual([f'quality: required lane is {result}'], policy.gate_errors(red), result)

    def test_gate_red_when_an_unexpected_lane_failed_or_changes_failed(self):
        red = needs({'quality': 'success', 'fast': 'skipped', 'db': 'failure', 'serial': 'skipped', 'consumer-isolation': 'skipped', 'vue': 'skipped', 'vue-collaboration': 'skipped',
                     'hub': 'skipped', 'repo': 'skipped', 'openapi': 'success'},
                    has_quality='true', has_openapi='true', quality_matrix=self.matrix(1))
        self.assertEqual(['db: failure'], policy.gate_errors(red))
        self.assertTrue(policy.gate_errors(needs({'changes': 'failure'})))
        self.assertTrue(policy.gate_errors(needs({'changes': 'cancelled'})))

    def test_gate_red_on_an_empty_required_matrix(self):
        red = needs({'quality': 'success', 'fast': 'skipped', 'db': 'skipped', 'serial': 'skipped', 'consumer-isolation': 'skipped', 'vue': 'skipped', 'vue-collaboration': 'skipped',
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


class ContractBoundaryTest(unittest.TestCase):
    def test_review_npm_both_ci_jobs_require_a_blocking_declaration_guard(self):
        actual = policy.load_workflow(ROOT / '.github/workflows/ci.yml')
        for job_id, workspace in (('vue', '@vaulter/vue'), ('vue-collaboration', '@vaulter/vue-collaboration')):
            command = f'npm run api:surface:check --workspace {workspace}'
            configured = copy.deepcopy(actual)
            configured['jobs'][job_id]['steps'].append({'run': command})
            for broken_step in (None, {'run': command, 'if': 'false'}, {'run': command, 'continue-on-error': True},
                                {'run': command + ' --if-present'}, {'run': command + ' || true'}):
                broken = copy.deepcopy(configured)
                broken['jobs'][job_id]['steps'] = [step for step in broken['jobs'][job_id]['steps'] if step.get('run') != command]
                if broken_step is not None:
                    broken['jobs'][job_id]['steps'].append(broken_step)
                errors = policy.check_ci(broken, ROOT)
                self.assertTrue(any(job_id + ': declaration guard' in e for e in errors), (job_id, broken_step, errors))

    def test_review_npm_php_only_line_selection_cannot_succeed_at_the_real_gate(self):
        planned = policy.plan_outputs(policy.derive_support(ROOT), 'all')
        green_jobs = {policy.lane_job(lane): 'success' for lane in policy.LANES}
        for field in ('contract_bc_required_lines', 'contract_bc_selected_lines'):
            outputs = copy.deepcopy(planned)
            outputs[field] = json.dumps(['php-public', 'php-collaboration'])
            errors = policy.gate_errors(needs(green_jobs, **outputs))
            self.assertTrue(any(field in e for e in errors), (field, errors))

        for field in ('contract_bc_required_lines', 'contract_bc_selected_lines'):
            for missing in (None, 'not-json', '[]', json.dumps(['php-public'] * 4), '[{}, {}, {}, {}]'):
                outputs = dict(planned, **{field: missing})
                self.assertTrue(any(field in e for e in policy.gate_errors(needs(green_jobs, **outputs))))
        bypass = dict(planned, has_contract_bc='false')
        self.assertTrue(any('four-line contract gate' in e for e in policy.gate_errors(needs(green_jobs, **bypass))))
        self.assertEqual([], policy.gate_errors(needs(green_jobs, **planned)))

    def test_review_npm_trusted_policy_has_four_independent_release_lines(self):
        policy_doc = json.loads((ROOT / 'scripts/release/Contract/bc-trust-policy.json').read_text())
        self.assertEqual({'php-public', 'php-collaboration', 'npm-vue', 'npm-vue-collaboration'}, set(policy_doc['lines']))
        manifest = json.loads((ROOT / 'release/packages.json').read_text())
        for entry in manifest['npm']:
            version = json.loads((ROOT / entry['path'] / 'package.json').read_text())['version']
            self.assertEqual({'prefix': entry['tag_prefix'], 'major': int(version.split('.')[0])}, policy_doc['lines'][entry['release_line']])

    def test_review_npm_real_workflow_preserves_guards_conditions_and_gate_dependencies(self):
        actual = policy.load_workflow(ROOT / '.github/workflows/ci.yml')
        self.assertEqual([], policy.check_ci(actual, ROOT))
        for job_id in ('vue', 'vue-collaboration', 'contract-bc'):
            broken = copy.deepcopy(actual)
            broken['jobs']['ci-gate']['needs'].remove(job_id)
            self.assertTrue(any('does not need' in e for e in policy.check_ci(broken, ROOT)))
            broken = copy.deepcopy(actual)
            broken['jobs'][job_id]['if'] = 'false'
            self.assertTrue(policy.check_ci(broken, ROOT))
            broken = copy.deepcopy(actual)
            broken['jobs'][job_id]['continue-on-error'] = True
            self.assertTrue(policy.check_ci(broken, ROOT))
        for field in ('BC_REQUIRED_LINES', 'BC_SELECTED_LINES'):
            broken = copy.deepcopy(actual)
            broken['jobs']['contract-bc']['env'][field] = 'php-public,php-collaboration'
            self.assertTrue(any(field in e for e in policy.check_ci(broken, ROOT)))
        for output in ('has_contract_bc', 'contract_bc_required_lines', 'contract_bc_selected_lines'):
            broken = copy.deepcopy(actual)
            del broken['jobs']['changes']['outputs'][output]
            self.assertTrue(any(output in e for e in policy.check_ci(broken, ROOT)))
        for job_id in ('vue', 'vue-collaboration'):
            for mutate in (lambda j: j.update(permissions={'contents': 'write'}),
                           lambda j: j.update(env={'TOKEN': '${{ secrets.NPM_TOKEN }}'}),
                           lambda j: j['steps'][0]['with'].update({'persist-credentials': True})):
                broken = copy.deepcopy(actual)
                mutate(broken['jobs'][job_id])
                self.assertTrue(policy.check_ci(broken, ROOT))

    def test_review_npm_trusted_host_requires_selected_lines_and_preserves_aggregate_failure(self):
        import contextlib
        import io
        import os
        import subprocess
        from unittest.mock import patch
        spec = importlib.util.spec_from_file_location('trusted_host_lines', ROOT / 'scripts/release/Contract/trusted-host.py')
        host = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(host)
        policy_doc = json.loads((ROOT / 'scripts/release/Contract/bc-trust-policy.json').read_text())
        self.assertEqual(tuple(policy.REQUIRED_BC_LINES), host.selected_lines(policy_doc))
        for field in ('required', 'selected'):
            for value in ('php-public,php-collaboration', '', 'php-public,php-public,php-public,php-public'):
                with self.assertRaises(ValueError):
                    host.selected_lines(policy_doc, **{field: value})
        for line in ('npm-vue', 'npm-vue-collaboration'):
            broken = copy.deepcopy(policy_doc)
            del broken['lines'][line]
            with self.assertRaises(ValueError): host.selected_lines(broken)
        report = {'exit': 0, 'lines': {line: {'admission': 'compared', 'base': 'release-base'} for line in policy.REQUIRED_BC_LINES}, 'changes': []}
        host.validate_line_report(report, policy.REQUIRED_BC_LINES, 0)
        for name in policy.REQUIRED_BC_LINES:
            broken = copy.deepcopy(report)
            del broken['lines'][name]
            with self.assertRaises(ValueError): host.validate_line_report(broken, policy.REQUIRED_BC_LINES, 0)
            for edit in ({'admission': 'not_checked'}, {'admission': 'initial_bootstrap'}, {'selected': False}, {'required': False}, {'status': 'pending'}):
                broken = copy.deepcopy(report)
                broken['lines'][name].update(edit)
                with self.assertRaises(ValueError): host.validate_line_report(broken, policy.REQUIRED_BC_LINES, 0)
        with self.assertRaises(ValueError): host.validate_line_report(report, policy.REQUIRED_BC_LINES, 1)
        # Stubbed coordinator/OpenAPI processes prove line/exit plumbing only; no runtime or compatibility pass.
        with tempfile.TemporaryDirectory() as tmp:
            tool = Path(tmp) / 'T'
            (tool / 'scripts/release/Contract').mkdir(parents=True)
            (tool / 'scripts/release/Contract/bc-trust-policy.json').write_text(json.dumps(policy_doc))
            env = {'BC_TOOL_ROOT': str(tool), 'BC_TOOL_REV': 'a' * 40, 'BC_SHA256SUMS': 'external', 'BC_RUNTIME_APPROVAL': 'external', 'BC_RUNTIME_APPROVAL_SHA256': 'external', 'BC_SOURCE_ROOT': '/candidate-data-only', 'BC_SOURCE_SHA': 'c' * 40}
            for coordinator_exit, openapi_exit, expected in ((0, 0, 0), (1, 0, 1), (0, 1, 1), (1, 2, 2), (0, 125, 2)):
                stub_report = dict(report, exit=coordinator_exit)
                processes = [subprocess.CompletedProcess([], coordinator_exit, json.dumps(stub_report).encode()), subprocess.CompletedProcess([], openapi_exit)]
                output = io.StringIO()
                with patch.dict(os.environ, env, clear=True), patch.object(host, 'verify', return_value=('/approved/php8.4', {'PATH': '/usr/bin:/bin'})), patch.object(host.subprocess, 'check_output', return_value='b' * 40), patch.object(host.subprocess, 'run', side_effect=processes) as run, contextlib.redirect_stdout(output):
                    self.assertEqual(expected, host.main())
                    self.assertEqual('/approved/php8.4', run.call_args_list[0].args[0][0])
                    self.assertEqual(str(tool) + '/scripts/release/bc-check.php', run.call_args_list[0].args[0][1])
                observed = json.loads(output.getvalue())
                self.assertEqual(list(policy.REQUIRED_BC_LINES), observed['required_release_lines'])
                self.assertEqual(list(policy.REQUIRED_BC_LINES), observed['selected_release_lines'])

    def test_contract_lane_is_selected_and_blocks_every_non_success(self):
        support = policy.derive_support(ROOT)
        out = policy.plan_outputs(support, 'all')
        self.assertEqual('true', out['has_contract_bc'])
        for result in ('failure', 'cancelled', 'skipped', 'missing'):
            errors = policy.gate_errors(needs({'contract-bc': result}, has_contract_bc='true'))
            self.assertTrue(any('contract_bc: required lane' in e for e in errors))
        for key in ('fetch-depth', 'fetch-tags', 'persist-credentials'):
            job = contract_job()
            del job['steps'][0]['with'][key]
            self.assertTrue(policy.check_contract_bc(job))
        job = contract_job()
        job['steps'][-1]['continue-on-error'] = True
        self.assertTrue(policy.check_contract_bc(job))
        self.assertEqual([], policy.check_contract_bc(contract_job()))

    def test_host_exact_inventory_runtime_and_readonly_tool(self):
        import hashlib
        import os
        import subprocess
        import sys
        spec = importlib.util.spec_from_file_location('trusted_host', ROOT / 'scripts/release/Contract/trusted-host.py')
        host = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(host)
        with tempfile.TemporaryDirectory() as tmp:
            d = Path(tmp)
            tool = d / 'tool'
            tool.mkdir()
            subprocess.run(['git', 'init', '-q', str(tool)], check=True)
            (tool / 'helper').write_text('approved')
            (tool / 'composer.lock').write_text('lock')
            (tool / 'vendor').mkdir()
            (tool / 'vendor/autoload.php').write_text('<?php')
            import shutil
            release = tool / 'scripts/release'
            (release / 'Contract').mkdir(parents=True)
            shutil.copy(ROOT / 'scripts/release/bc-check.php', release / 'bc-check.php')
            for file in (ROOT / 'scripts/release/Contract').glob('*.php'):
                shutil.copy(file, release / 'Contract' / file.name)
            for args in (['add', '.'], ['-c', 'user.name=t', '-c', 'user.email=t@t', '-c', 'commit.gpgsign=false', 'commit', '-qm', 'fixture']):
                subprocess.run(['git', '-C', str(tool)] + args, check=True)
            rev = subprocess.check_output(['git', '-C', str(tool), 'rev-parse', 'HEAD'], text=True).strip()
            # Fixture setup uses source php84; bootstrap itself receives a separately approved absolute binary.
            php = subprocess.check_output([str(ROOT / 'scripts/php84'), 'php', '-r', 'echo PHP_BINARY;'], text=True)
            php = str(Path(php).resolve())
            env = {'PATH': '/usr/bin:/bin', 'BC_PYTHON': sys.executable}
            probe = 'echo json_encode(["version"=>PHP_VERSION,"ini"=>php_ini_loaded_file(),"scanned"=>php_ini_scanned_files(),"extensions"=>get_loaded_extensions()]);'
            config = json.loads(subprocess.check_output([php, '-r', probe], env=env))
            names = [config['ini']] if config['ini'] else []
            names += [n.strip() for n in (config['scanned'] or '').split(',') if n.strip()]
            approval = d / 'runtime.json'
            approval.write_text(json.dumps({'php': php, 'sha256': host.digest(Path(php)), 'configuration': config, 'config_files': {n: host.digest(Path(n)) for n in names}, 'environment': env}))
            sums = d / 'SHA256SUMS'
            names = sorted(p.relative_to(tool).as_posix() for p in tool.rglob('*') if p.is_file() and '.git' not in p.parts)
            sums.write_text(''.join(host.digest(tool / n) + '  ' + n + '\n' for n in names))
            approved_runtime = host.digest(approval)
            verify = lambda: host.verify(str(tool), rev, str(sums), str(approval), approved_runtime)
            for path in (tool / 'helper', tool / 'composer.lock', tool / 'vendor/autoload.php'):
                original = path.read_bytes()
                path.write_bytes(b'tampered')
                with self.assertRaises(ValueError): verify()
                path.write_bytes(original)
            extra = tool / 'untracked'
            extra.write_text('not listed')
            with self.assertRaises(ValueError): verify()
            extra.unlink()
            extra.symlink_to(tool / 'helper')
            with self.assertRaises(ValueError): verify()
            extra.unlink()
            helper = tool / 'helper'
            helper.unlink()
            with self.assertRaises(ValueError): verify()
            helper.write_text('approved')
            original = approval.read_bytes()
            approval.write_bytes(original + b' ')
            with self.assertRaises(ValueError): verify()
            approval.unlink()
            with self.assertRaises(FileNotFoundError): verify()
            approval.write_bytes(original)
            # Read-only tool files/directories: verification and direct PHP execution need no shim writes.
            for path in tool.rglob('*'):
                if '.git' not in path.parts: path.chmod(0o555 if path.is_dir() else 0o444)
            tool.chmod(0o555)
            binary, approved_env = verify()
            self.assertEqual('8.4', subprocess.check_output([binary, '-r', 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;'], env=approved_env, cwd=tmp, text=True))
            checked = subprocess.run([binary, str(release / 'bc-check.php')], env=approved_env, cwd=tmp, capture_output=True, text=True)
            self.assertEqual(2, checked.returncode)
            self.assertIn('missing --root', checked.stdout)
            self.assertFalse((tool / 'build').exists())
            tool.chmod(0o755)
            for path in tool.rglob('*'):
                if path.is_dir() and '.git' not in path.parts: path.chmod(0o755)
            candidate = d / 'candidate'
            candidate.mkdir()
            marker = d / 'executed'
            (candidate / 'hashlib.py').write_text('raise RuntimeError("candidate module executed")')
            attack_env = dict(os.environ, PYTHONPATH=str(candidate), BC_TOOL_ROOT=str(tool), BC_TOOL_REV=rev, BC_SHA256SUMS=str(d / 'missing'), BC_RUNTIME_APPROVAL=str(approval), BC_RUNTIME_APPROVAL_SHA256=approved_runtime)
            isolated = subprocess.run([sys.executable, '-I', str(ROOT / 'scripts/release/Contract/trusted-host.py')], cwd=candidate, env=attack_env, capture_output=True, text=True)
            self.assertEqual(2, isolated.returncode)
            self.assertIn('untrusted_checker', isolated.stdout)
            self.assertNotIn('candidate module executed', isolated.stderr)


if __name__ == '__main__':
    unittest.main()


class PinPolicyTest(unittest.TestCase):
    sha = '1234567890abcdef1234567890abcdef12345678'
    digest = 'sha256:' + '1a' * 32

    def doc(self, step=None):
        return {'permissions': {'contents': 'read'}, 'jobs': {'check': {'steps': [step] if step else []}}}

    def test_pin_annotated_chain_ends_in_commit(self):
        chain = [{'url': 'https://api.github.com/repos/o/r/git/ref/tags/v1', 'object': {'type': 'tag', 'sha': 'a' * 40}},
                 {'url': 'https://api.github.com/repos/o/r/git/tags/' + 'a' * 40, 'object': {'type': 'tag', 'sha': 'b' * 40}},
                 {'url': 'https://api.github.com/repos/o/r/git/tags/' + 'b' * 40, 'object': {'type': 'commit', 'sha': self.sha}}]
        self.assertEqual(self.sha, policy.pin_commit(chain))
        foreign = copy.deepcopy(chain)
        foreign[1]['url'] = foreign[1]['url'].replace('/o/r/', '/other/repo/')
        with self.assertRaises(ValueError): policy.pin_commit(foreign)
        for broken in (chain[:-1], [], [dict(chain[-1], object={'type': 'tree', 'sha': self.sha})], [chain[0], chain[-1]]):
            with self.assertRaises(ValueError): policy.pin_commit(broken)

    def test_pin_local_uses_and_remote_reusable(self):
        for ref in ('./local/action', './.github/workflows/reuse.yml', 'owner/repo/.github/workflows/x.yml@' + self.sha):
            self.assertEqual([], policy.check_pins('x', self.doc({'uses': ref})))
        for ref in ('owner/repo@v4', 'owner/repo@' + self.sha[:12], './a/../bad'):
            self.assertTrue(policy.check_pins('x', self.doc({'uses': ref})))

    def test_pin_docker_action_digest(self):
        self.assertEqual([], policy.check_pins('x', self.doc({'uses': 'docker://alpine@' + self.digest})))
        self.assertTrue(policy.check_pins('x', self.doc({'uses': 'docker://alpine:latest'})))

    def test_pin_services_and_job_containers(self):
        doc = self.doc(); job = doc['jobs']['check']
        job['services'] = {'db': {'image': 'postgres@' + self.digest}}
        job['container'] = 'node@' + self.digest
        self.assertEqual([], policy.check_pins('x', doc))
        job['services']['db']['image'] = 'postgres:16'
        self.assertTrue(policy.check_pins('x', doc))
        pinned = 'postgres:18-alpine@sha256:' + 'ab' * 32
        other = 'mysql:9.7@sha256:' + 'cd' * 32
        job['services']['db']['image'] = "${{ matrix.engine == 'pgsql' && '%s' || '%s' }}" % (pinned, other)
        self.assertEqual([], policy.check_pins('x', doc))
        job['services']['db']['image'] = "${{ matrix.engine == 'pgsql' && 'postgres:16' || '%s' }}" % other
        self.assertTrue(policy.check_pins('x', doc))
        job['container'] = {'image': 'node:22'}
        self.assertEqual(2, len(policy.check_pins('x', doc)))

    def test_pin_multistage_repeated_from_and_malformed_digest(self):
        good = 'FROM node@' + self.digest + ' AS deps\nFROM node@' + self.digest + ' AS runtime\nFROM deps AS reused\nFROM scratch\n'
        self.assertEqual([], policy.check_docker_pins(good))
        self.assertEqual(1, len(policy.check_docker_pins(good.replace('FROM node@' + self.digest + ' AS runtime', 'FROM node:22 AS runtime'))))
        for suffix in ('abc', 'g' * 64, 'a' * 63, 'a' * 65, '${DIGEST}'):
            self.assertFalse(policy.pinned_image('node@sha256:' + suffix))
            self.assertTrue(policy.check_docker_pins('FROM node@sha256:' + suffix))

    def test_pin_checkout_permissions_and_npm_lock_command(self):
        checkout = {'uses': 'actions/checkout@' + self.sha, 'with': {'persist-credentials': False}}
        doc = self.doc(checkout)
        self.assertEqual([], policy.check_pins('x', doc))
        checkout['with']['persist-credentials'] = True
        self.assertTrue(policy.check_pins('x', doc))
        doc['jobs']['check']['steps'].append({'uses': 'changesets/action@' + self.sha})
        self.assertEqual([], policy.check_pins('x', doc))
        doc['permissions'] = {'contents': 'write'}
        self.assertTrue(policy.check_pins('x', doc))
        doc['permissions'] = {}
        doc['jobs']['check']['permissions'] = {'id-token': 'write'}
        self.assertTrue(policy.check_pins('x', doc))
        self.assertTrue(policy.check_pins('x', self.doc({'run': 'npm install'})))
        self.assertEqual([], policy.check_pins('x', self.doc({'run': 'npm ci'})))

    def test_pin_version_comment_and_docker_run_shortcuts(self):
        ref = 'owner/repo@' + self.sha
        self.assertTrue(policy.check_pins('x', self.doc({'uses': ref}), 'uses: ' + ref))
        self.assertEqual([], policy.check_pins('x', self.doc({'uses': ref}), 'uses: ' + ref + ' # v1.2.3'))
        self.assertTrue(policy.check_pins('x', self.doc({'run': 'docker run --rm rhysd/actionlint:1.7.7'})))
        self.assertEqual([], policy.check_pins('x', self.doc({'run': 'docker run --rm rhysd/actionlint:1.7.7@' + self.digest})))


    def test_pin_docker_run_image_argument_short_names_and_options(self):
        for command in (
            'docker run --rm node:22-slim node --version',
            'docker run postgres',
            'docker run --name pg --network host postgres:16-alpine',
            'docker run -it -v "$PWD:/repo" -w /repo node:22-slim node --version',
            'docker run --env IMAGE=example/unrelated:tag --entrypoint /bin/sh node',
            'docker run --platform=linux/amd64 --name=lint rhysd/actionlint:1.7.7',
            'docker run -- example/unqualified',
        ):
            with self.subTest(command=command):
                self.assertTrue(policy.check_pins('x', self.doc({'run': command})), command)
        for prefix in (
            'docker run --rm ',
            'docker run --platform linux/amd64 --network=host ',
            'docker run -itv "$PWD:/repo" -w/repo ',
            'docker run --env IMAGE=example/unrelated:tag --entrypoint /bin/sh ',
            'docker run -- ',
        ):
            for image in ('node', 'node:22-slim', 'registry.example:5000/team/tool:v1'):
                command = prefix + image + '@' + self.digest + ' node --version'
                with self.subTest(command=command):
                    self.assertEqual([], policy.check_pins('x', self.doc({'run': command})))

    def test_pin_docker_run_checks_each_actual_image_and_not_command_arguments(self):
        good = 'docker run --rm node@' + self.digest + ' echo rhysd/actionlint:1.7.7'
        self.assertEqual([], policy.check_pins('x', self.doc({'run': good})))
        self.assertTrue(policy.check_pins('x', self.doc({'run': good + ' && docker run --rm postgres'})))
        for command in ('docker run --unknown-option value node@' + self.digest, 'docker run --rm', 'docker run -v'):
            with self.subTest(command=command):
                self.assertTrue(policy.check_pins('x', self.doc({'run': command})))


class TrustedManifestPinTest(unittest.TestCase):
    """Synthetic TOOL/candidate fixtures prove source selection and failure handling only."""

    def wrapper(self):
        spec = importlib.util.spec_from_file_location('pin_openapi', ROOT / 'scripts/release/Contract/openapi-check.py')
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        return module

    def manifest(self):
        return json.loads((ROOT / 'release/tool-pins.json').read_text())

    def put(self, root, content):
        path = root / 'release/tool-pins.json'
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(content)
        return path

    def test_pin_manifest_approved_tool_source_ignores_candidate_and_environment(self):
        import os
        from unittest.mock import patch
        module = self.wrapper()
        approved = self.manifest()
        expected = approved['images']['tufin/oasdiff:v1.33.0']['image']
        with tempfile.TemporaryDirectory() as tmp:
            tool, candidate = Path(tmp) / 'T', Path(tmp) / 'S'
            self.put(tool, json.dumps(approved))
            bad = copy.deepcopy(approved)
            bad['images']['tufin/oasdiff:v1.33.0']['image'] = 'attacker/fake:latest'
            candidate_manifest = self.put(candidate, json.dumps(bad))
            with patch.object(module, 'TOOL', tool), patch.dict(os.environ, {'BC_TOOL_ROOT': str(candidate), 'BC_SOURCE_ROOT': str(candidate), 'BC_TOOL_PINS': str(candidate_manifest)}):
                self.assertEqual(expected, module.tool_image())
                (tool / 'release/tool-pins.json').unlink()
                with self.assertRaises((ValueError, OSError)): module.tool_image()

    def test_pin_manifest_missing_malformed_and_schema_fail_closed(self):
        from unittest.mock import patch
        module = self.wrapper()
        invalid = ('not json', '[]', 'null', '{}', '{"schema_version": true, "images": {}}',
                   '{"schema_version": 2, "images": {}}', '{"schema_version": 1, "images": []}')
        with tempfile.TemporaryDirectory() as tmp, patch.object(module, 'TOOL', Path(tmp)):
            with self.assertRaises((ValueError, OSError)): module.tool_image()
            for text in invalid:
                self.put(Path(tmp), text)
                with self.assertRaises(ValueError, msg=text): module.tool_image()

    def test_pin_manifest_digest_image_tag_and_missing_entry_negatives(self):
        from unittest.mock import patch
        module = self.wrapper()
        with tempfile.TemporaryDirectory() as tmp, patch.object(module, 'TOOL', Path(tmp)):
            changes = ({'digest': 'sha256:bad'}, {'digest': 'sha256:' + 'g' * 64},
                       {'digest': 'sha256:' + 'a' * 64}, {'image': 'tufin/oasdiff:v1.33.0'},
                       {'image': 'attacker/oasdiff@sha256:' + 'a' * 64}, {'tag': 'tufin/oasdiff:latest'},
                       {'digest': None}, {'image': None})
            for change in changes:
                doc = self.manifest()
                doc['images']['tufin/oasdiff:v1.33.0'].update(change)
                self.put(Path(tmp), json.dumps(doc))
                with self.assertRaises(ValueError, msg=str(change)): module.tool_image()
            for entry in (None, [], {}, 'floating'):
                doc = self.manifest()
                doc['images'] = {} if entry is None else {'tufin/oasdiff:v1.33.0': entry}
                self.put(Path(tmp), json.dumps(doc))
                with self.assertRaises(ValueError): module.tool_image()
            doc = self.manifest()
            doc['images']['tufin/oasdiff:v1.32.0'] = copy.deepcopy(doc['images']['tufin/oasdiff:v1.33.0'])
            self.put(Path(tmp), json.dumps(doc))
            with self.assertRaises(ValueError): module.tool_image()

    def test_pin_manifest_symlink_cannot_redirect_to_candidate(self):
        from unittest.mock import patch
        module = self.wrapper()
        with tempfile.TemporaryDirectory() as tmp:
            tool, candidate = Path(tmp) / 'T', Path(tmp) / 'S'
            path = self.put(candidate, json.dumps(self.manifest()))
            (tool / 'release').mkdir(parents=True)
            (tool / 'release/tool-pins.json').symlink_to(path)
            with patch.object(module, 'TOOL', tool):
                with self.assertRaises(ValueError): module.tool_image()

    def test_pin_manifest_parser_origin_guard_precedes_import_and_manifest(self):
        import types
        from unittest.mock import patch
        module = self.wrapper()
        with patch.dict('os.environ', {'BC_REQUIRE_TOOL_PARSER': '1'}), \
             patch('importlib.util.find_spec', return_value=types.SimpleNamespace(origin='/candidate/yaml/__init__.py')), \
             patch.object(module, 'tool_image') as image, patch.object(module.subprocess, 'run') as process:
            with self.assertRaisesRegex(ValueError, 'parser is outside approved T'):
                module.run('/candidate', 'a' * 40, {'exit': 0, 'lines': {}})
            image.assert_not_called()
            process.assert_not_called()

    def test_pin_manifest_real_consumer_argv_and_error_exit_without_docker(self):
        import subprocess
        import sys
        from unittest.mock import patch
        module = self.wrapper()
        expected = self.manifest()['images']['tufin/oasdiff:v1.33.0']['image']
        report = {'exit': 0, 'lines': {line: {'admission': 'compared', 'base': 'approved-base'} for line in module.SPECS}}
        with patch.dict('os.environ', {'BC_REQUIRE_TOOL_PARSER': '0', 'BC_OASDIFF_BIN': ''}), \
             patch.object(module, 'closure'), patch.object(module.subprocess, 'check_output', return_value='b' * 40), \
             patch.object(module.subprocess, 'run', return_value=subprocess.CompletedProcess([], 0)) as process:
            observed = module.run('/candidate', 'a' * 40, report)
            self.assertEqual(0, observed['exit'])
            self.assertEqual(2, process.call_count)
            for call in process.call_args_list:
                argv = call.args[0]
                self.assertEqual(['docker', 'run', '--rm'], argv[:3])
                self.assertEqual(expected, argv[5])
        # Only run the wrapper's error path; a missing TOOL manifest must prevent any Docker/Git operation.
        with tempfile.TemporaryDirectory() as tmp:
            tool = Path(tmp) / 'T'
            script = tool / 'scripts/release/Contract/openapi-check.py'
            script.parent.mkdir(parents=True)
            script.write_bytes((ROOT / 'scripts/release/Contract/openapi-check.py').read_bytes())
            candidate = Path(tmp) / 'S'
            self.put(candidate, json.dumps(self.manifest()))
            report_path = Path(tmp) / 'report.json'
            report_path.write_text(json.dumps(report))
            result = subprocess.run([sys.executable, '-I', str(script), str(candidate), 'a' * 40, str(report_path)],
                                    capture_output=True, text=True, env={'PATH': '/usr/bin:/bin'})
            self.assertEqual(2, result.returncode)
            error = json.loads(result.stdout)
            self.assertEqual('not_checked', error['status'])
            self.assertFalse(error['actual_runtime_qualification'])


class ScaWorkflowTest(unittest.TestCase):
    def test_sca_required_even_for_docs_only_and_blocks_all_non_success(self):
        for status in ('failure', 'cancelled', 'skipped', 'missing'):
            doc = needs({'sca': status}, skip_reason='docs-only')
            self.assertTrue(any('sca:' in e for e in policy.gate_errors(doc)))
        skipped = {policy.lane_job(lane): 'skipped' for lane in policy.LANES}
        self.assertEqual([], policy.gate_errors(needs(skipped, skip_reason='docs-only')))

    def test_sca_approval_capture_and_gate_cannot_be_removed_or_bypassed(self):
        actual = policy.load_workflow(ROOT / '.github/workflows/ci.yml')['jobs']['sca']
        self.assertEqual([], policy.check_sca(actual))
        for key,value in (('if','false'), ('continue-on-error',True), ('env',{})):
            broken = copy.deepcopy(actual); broken[key] = value
            self.assertTrue(policy.check_sca(broken))
        for bypass in ({'if':'false'}, {'continue-on-error':True}):
            broken = copy.deepcopy(actual)
            for step in broken['steps']:
                if 'scripts/ci/audit-gate.py' in str(step.get('run','')): step.update(bypass)
            self.assertTrue(policy.check_sca(broken))

    # --- P7.3/U3: one accepted artifact per release flow (npm tgz, OCI digest, PHP prepared record) -----------------
    def artifact_doc(self, name):
        return policy.load_workflow(ROOT / '.github/workflows' / name)

    def artifact_errors(self, name, doc):
        return policy.check_artifacts(name, doc, json.dumps(doc))

    def test_artifact_repository_publishers_satisfy_the_single_artifact_rules(self):
        for name in ('monorepo-split.yml', 'release-npm.yml', 'collaboration-hub-image.yml'):
            with self.subTest(workflow=name):
                self.assertEqual([], self.artifact_errors(name, self.artifact_doc(name)))

    def test_artifact_npm_is_packed_once_and_the_publisher_consumes_the_same_tgz(self):
        base = self.artifact_doc('release-npm.yml')
        self.assertIn('pack', base['jobs'])
        for label, mutate in {
            'publisher repacks': lambda d: d['jobs']['release']['steps'].append({'run': 'npm pack --workspace @vaulter/vue'}),
            'publisher rebuilds': lambda d: d['jobs']['release']['steps'].append({'run': 'npm run build'}),
            'publisher installs': lambda d: d['jobs']['release']['steps'].append({'run': 'npm ci'}),
            'publisher publishes without the gate': lambda d: d['jobs']['release']['steps'].__setitem__(slice(None), [s for s in d['jobs']['release']['steps'] if 'artifact-gate.py' not in json.dumps(s)]),
            'secret before verify': lambda d: d['jobs']['release']['steps'].insert(0, {'run': 'true', 'env': {'NODE_AUTH_TOKEN': '${{ secrets.NPM_TOKEN }}'}}),
            'secret in the verify step': lambda d: d['jobs']['release']['steps'][next(i for i, s in enumerate(d['jobs']['release']['steps']) if 'artifact-gate.py npm' in str(s.get('run', '')))]['env'].update(NODE_AUTH_TOKEN='${{ secrets.NPM_TOKEN }}'),
            'lifecycle scripts run with the secret': lambda d: [s.update(run=s['run'].replace(' --ignore-scripts', '')) for s in d['jobs']['release']['steps'] if 'npm publish' in str(s.get('run', ''))],
            'publish step also builds': lambda d: [s.update(run=s['run'] + '\nnpm run build') for s in d['jobs']['release']['steps'] if 'npm publish' in str(s.get('run', ''))],
            'compare-only gate without the approval': lambda d: [s.update(run=s['run'].replace('--approval release-evidence/artifact-approval.json', '--expect-sha256 abc')) for s in d['jobs']['release']['steps'] if 'artifact-gate.py npm' in str(s.get('run', ''))],
            'approval hash computed in the job': lambda d: [s['env'].update(VAULTER_ARTIFACT_APPROVAL_SHA256='${{ env.COMPUTED }}') for s in d['jobs']['release']['steps'] if 'artifact-gate.py npm' in str(s.get('run', ''))],
            'verify step drops channel and visibility': lambda d: [s.update(run=s['run'].replace('--channel', '--nochannel').replace('--visibility', '--novisibility')) for s in d['jobs']['release']['steps'] if 'artifact-gate.py npm' in str(s.get('run', ''))],
            'publish ignores the verified access': lambda d: [s.update(run=s['run'].replace('--access "${access}"', '--access public')) for s in d['jobs']['release']['steps'] if 'npm publish' in str(s.get('run', ''))],
            'approval hash missing': lambda d: [s['env'].pop('VAULTER_ARTIFACT_APPROVAL_SHA256') for s in d['jobs']['release']['steps'] if 'artifact-gate.py npm' in str(s.get('run', ''))],
            'publisher publishes by changesets': lambda d: d['jobs']['release']['steps'].append({'uses': 'changesets/action@a45c4d594aa4e2c509dc14a9f2b3b67ba3780d0d', 'with': {'publish': 'npm run release'}}),
            'pack job holds the registry token': lambda d: d['jobs']['pack'].update(env={'NODE_AUTH_TOKEN': '${{ secrets.NPM_TOKEN }}'}),
            'pack job may mint an OIDC token': lambda d: d['jobs']['pack'].update(permissions={'contents': 'read', 'id-token': 'write'}),
            'publisher does not need pack': lambda d: d['jobs']['release'].update(needs='route'),
            'version job holds the registry token': lambda d: d['jobs']['version-pr'].update(env={'NODE_AUTH_TOKEN': '${{ secrets.NPM_TOKEN }}'}),
        }.items():
            with self.subTest(case=label):
                doc = copy.deepcopy(base)
                mutate(doc)
                self.assertTrue(self.artifact_errors('release-npm.yml', doc), label)

    def test_artifact_oci_promotes_the_exact_tested_digest_without_rebuild_or_tag(self):
        base = self.artifact_doc('collaboration-hub-image.yml')
        for label, mutate in {
            'publisher builds': lambda d: d['jobs']['image']['steps'].append({'uses': 'docker/build-push-action@10e90e3645eae34f1e60eeb005ba3a3d33f178e8', 'with': {'push': True}}),
            'publisher promotes a tag': lambda d: d['jobs']['image']['steps'].append({'run': 'docker buildx imagetools create -t ghcr.io/x/y:1.0.0 ghcr.io/x/y:latest'}),
            'oci gate is compare-only': lambda d: [s.update(run=s['run'].replace('--approval release-evidence/artifact-approval.json', '--tested-digest sha256:x')) for s in d['jobs']['image']['steps'] if 'artifact-gate.py oci' in str(s.get('run', ''))],
            'oci approval hash from the candidate': lambda d: [s['env'].update(VAULTER_ARTIFACT_APPROVAL_SHA256='${{ env.COMPUTED }}') for s in d['jobs']['image']['steps'] if 'artifact-gate.py oci' in str(s.get('run', ''))],
            'secret before the oci gate': lambda d: d['jobs']['image']['steps'].insert(0, {'run': 'true', 'env': {'T': '${{ secrets.GITHUB_TOKEN }}'}}),
            'publisher skips the digest gate': lambda d: d['jobs']['image']['steps'].__setitem__(slice(None), [s for s in d['jobs']['image']['steps'] if 'artifact-gate.py' not in json.dumps(s)]),
            'attestation is unconditional': lambda d: [s.pop('if', None) for s in d['jobs']['image']['steps'] if 'attest-build-provenance' in str(s.get('uses', ''))],
            'attestation flag false is not reported unavailable': lambda d: d['jobs']['image']['steps'].__setitem__(slice(None), [s for s in d['jobs']['image']['steps'] if 'unavailable' not in json.dumps(s)]),
        }.items():
            with self.subTest(case=label):
                doc = copy.deepcopy(base)
                mutate(doc)
                self.assertTrue(self.artifact_errors('collaboration-hub-image.yml', doc), label)

    def test_artifact_php_publisher_consumes_the_prepared_record_after_the_external_approval(self):
        base = self.artifact_doc('monorepo-split.yml')
        steps = lambda d: d['jobs']['split']['steps']
        gate = lambda d: next(i for i, s in enumerate(steps(d)) if '--require-approved' in str(s.get('run', '')))
        for label, mutate in {
            'second build in the publisher': lambda d: steps(d).append({'run': 'php scripts/release/build-artifacts.php --version=1.0.0 --out=x'}),
            'force push': lambda d: steps(d).append({'run': 'git push --force origin main'}),
            'no approval gate': lambda d: steps(d).pop(gate(d)),
            'credential before the gate': lambda d: steps(d).insert(0, {'run': 'true', 'env': {'T': '${{ secrets.GH_ACCESS_TOKEN }}'}}),
            'hash derived in the job': lambda d: steps(d)[gate(d)]['env'].update(VAULTER_PREPARED_APPROVAL_SHA256='${{ env.COMPUTED }}'),
            'prepare job holds the credential': lambda d: d['jobs']['prepare'].update(env={'T': '${{ secrets.GH_ACCESS_TOKEN }}'}),
            'split does not need prepare': lambda d: d['jobs']['split'].update(needs='route'),
        }.items():
            with self.subTest(case=label):
                doc = copy.deepcopy(base)
                mutate(doc)
                self.assertTrue(self.artifact_errors('monorepo-split.yml', doc), label)

    def run_artifact_gate(self, *args, pin=None, extra_env=None):
        import os, subprocess
        env = {k: v for k, v in os.environ.items() if k != 'VAULTER_ARTIFACT_APPROVAL_SHA256'}
        if pin is not None:
            env['VAULTER_ARTIFACT_APPROVAL_SHA256'] = pin
        env.update(extra_env or {})
        if args and args[0] == 'npm' and '--channel' not in args:
            args = (*args, '--channel', 'public', '--visibility', 'public')
        return subprocess.run(['python3', '-I', str(ROOT / 'scripts/release/artifact-gate.py'), *args], capture_output=True, text=True, env=env)

    def synthetic_tgz(self, directory, name='@vaulter/vue', version='1.0.0', extra=b''):
        import hashlib, base64, io, tarfile
        path = Path(directory) / 'pkg.tgz'
        with tarfile.open(path, 'w:gz') as tar:
            body = json.dumps({'name': name, 'version': version}).encode() + extra
            info = tarfile.TarInfo('package/package.json'); info.size = len(body)
            tar.addfile(info, io.BytesIO(body))
        data = path.read_bytes()
        return path, hashlib.sha256(data).hexdigest(), 'sha512-' + base64.b64encode(hashlib.sha512(data).digest()).decode()

    SOURCE = '5' * 40

    def approval_file(self, directory, artifacts, **top):
        """SYNTHETIC approval: the positive case proves the protocol only, it is never an owner grant."""
        import hashlib
        path = Path(directory) / 'approval.json'
        path.write_text(json.dumps({'status': 'approved', 'source_sha': self.SOURCE, 'artifacts': artifacts, **top}))
        return path, hashlib.sha256(path.read_bytes()).hexdigest()

    def npm_entry(self, sha, integrity, version='1.0.0'):
        return {'kind': 'npm', 'channel': 'public', 'visibility': 'public', 'version': version, 'tgz_sha256': sha, 'integrity': integrity, 'consumer_evidence_sha256': 'c' * 64}

    def test_artifact_gate_npm_publishes_only_the_tgz_the_authenticated_approval_accepted(self):
        with tempfile.TemporaryDirectory() as tmp:
            tgz, sha, integrity = self.synthetic_tgz(tmp)
            approval, pin = self.approval_file(tmp, {'npm': {'@vaulter/vue': self.npm_entry(sha, integrity)}})
            args = ('npm', '--tgz', str(tgz), '--approval', str(approval), '--source-sha', self.SOURCE)
            self.assertEqual(0, self.run_artifact_gate(*args, pin=pin).returncode)  # protocol positive, not an owner grant
            for label, kwargs in {'no protected hash': {'pin': None}, 'malformed hash': {'pin': 'xyz'}, 'uppercase hash': {'pin': pin.upper()},
                                  'wrong hash': {'pin': '0' * 64}, 'hash of the tgz instead of A': {'pin': sha}}.items():
                with self.subTest(case=label):
                    result = self.run_artifact_gate(*args, **kwargs)
                    self.assertEqual(1, result.returncode, result.stdout)
                    self.assertIn('refused', result.stderr)
            # forged BOTH candidate payload and matching command-line expectations, but no protected A: nothing to authenticate
            forged = self.run_artifact_gate('npm', '--tgz', str(tgz), '--expect-sha256', sha, '--expect-integrity', integrity)
            self.assertNotEqual(0, forged.returncode)
            self.assertNotIn('accepted', forged.stdout)
            # a self-asserted matching A without the protected hash of THOSE bytes
            self.assertEqual(1, self.run_artifact_gate(*args, pin=pin[::-1]).returncode)
            # same source, repacked bytes (a second pack after the approval): the old A cannot be inherited
            repacked, _, _ = self.synthetic_tgz(tmp, extra=b' ')
            self.assertEqual(1, self.run_artifact_gate('npm', '--tgz', str(repacked), '--approval', str(approval), '--source-sha', self.SOURCE, pin=pin).returncode)
            # A tampered after it was pinned
            approval.write_bytes(approval.read_bytes() + b' ')
            self.assertEqual(1, self.run_artifact_gate(*args, pin=pin).returncode)

    def test_artifact_gate_npm_refuses_incomplete_or_mismatched_approval_even_when_authenticated(self):
        with tempfile.TemporaryDirectory() as tmp:
            tgz, sha, integrity = self.synthetic_tgz(tmp)
            good = self.npm_entry(sha, integrity)
            cases = {
                'source SHA only (no artifact slots)': ({}, {}),
                'other package only': ({'npm': {'@vaulter/other': good}}, {}),
                'missing integrity': ({'npm': {'@vaulter/vue': {k: v for k, v in good.items() if k != 'integrity'}}}, {}),
                'missing consumer evidence': ({'npm': {'@vaulter/vue': {k: v for k, v in good.items() if k != 'consumer_evidence_sha256'}}}, {}),
                'other version': ({'npm': {'@vaulter/vue': self.npm_entry(sha, integrity, '1.0.1')}}, {}),
                'other sha256': ({'npm': {'@vaulter/vue': self.npm_entry('0' * 64, integrity)}}, {}),
                'other integrity': ({'npm': {'@vaulter/vue': self.npm_entry(sha, 'sha512-AAAA')}}, {}),
                'other kind': ({'npm': {'@vaulter/vue': {**good, 'kind': 'oci'}}}, {}),
                'pending status': ({'npm': {'@vaulter/vue': good}}, {'status': 'pending'}),
                'other source': ({'npm': {'@vaulter/vue': good}}, {'source_sha': '6' * 40}),
            }
            for label, (artifacts, top) in cases.items():
                with self.subTest(case=label):
                    approval, pin = self.approval_file(tmp, artifacts, **top)
                    result = self.run_artifact_gate('npm', '--tgz', str(tgz), '--approval', str(approval), '--source-sha', self.SOURCE, pin=pin)
                    self.assertEqual(1, result.returncode, result.stdout)
                    self.assertIn('refused', result.stderr)

    def test_artifact_gate_oci_promotes_only_the_digest_the_authenticated_approval_accepted(self):
        digest = 'sha256:' + 'a' * 64
        entry = {'kind': 'oci', 'digest': digest, 'platforms': ['linux/amd64', 'linux/arm64'], 'sbom_sha256': 'c' * 64, 'policy_sha256': 'd' * 64, 'tool_sha256': 'e' * 64, 'visibility': 'private'}
        base = ['oci', '--source-sha', self.SOURCE, '--digest', digest, '--platforms', 'linux/arm64,linux/amd64', '--sbom-sha256', 'c' * 64,
                '--policy-sha256', 'd' * 64, '--tool-sha256', 'e' * 64, '--channel', 'private', '--visibility', 'private']
        with tempfile.TemporaryDirectory() as tmp:
            approval, pin = self.approval_file(tmp, {'oci': entry})
            args = base + ['--approval', str(approval)]
            self.assertEqual(0, self.run_artifact_gate(*args, pin=pin).returncode)  # protocol positive, not an owner grant
            self.assertEqual(1, self.run_artifact_gate(*args).returncode)
            self.assertEqual(1, self.run_artifact_gate(*args, pin='0' * 64).returncode)
            mutated = lambda flag, value: [value if i > 0 and args[i - 1] == flag else a for i, a in enumerate(args)]
            for label, argv in {
                'tag instead of digest': mutated('--digest', 'ghcr.io/x/y:1.0.0'), 'other digest': mutated('--digest', 'sha256:' + 'b' * 64),
                'platform set': mutated('--platforms', 'linux/amd64'), 'sbom': mutated('--sbom-sha256', 'f' * 64),
                'policy': mutated('--policy-sha256', 'f' * 64), 'tool': mutated('--tool-sha256', 'f' * 64),
                'private to a public destination': mutated('--visibility', 'public'), 'private to an unprobed destination': mutated('--visibility', 'unknown'),
                'other source': mutated('--source-sha', '6' * 40),
            }.items():
                with self.subTest(case=label):
                    self.assertEqual(1, self.run_artifact_gate(*argv, pin=pin).returncode, label)
            for label, artifacts in {'source SHA only': {}, 'incomplete entry': {'oci': {k: v for k, v in entry.items() if k != 'sbom_sha256'}},
                                     'accepted public, run claims private': {'oci': {**entry, 'visibility': 'public'}}}.items():
                with self.subTest(case=label):
                    other, other_pin = self.approval_file(tmp, artifacts)
                    argv = base + ['--approval', str(other)]
                    self.assertEqual(1, self.run_artifact_gate(*argv, pin=other_pin).returncode, label)

    def test_artifact_gate_npm_private_needs_a_probed_private_destination_even_with_an_authenticated_approval(self):
        with tempfile.TemporaryDirectory() as tmp:
            tgz, sha, integrity = self.synthetic_tgz(tmp)
            private = {**self.npm_entry(sha, integrity), 'channel': 'private', 'visibility': 'private'}
            approval, pin = self.approval_file(tmp, {'npm': {'@vaulter/vue': private}})
            base = ('npm', '--tgz', str(tgz), '--approval', str(approval), '--source-sha', self.SOURCE)
            verified = Path(tmp) / 'verified.txt'
            ok = self.run_artifact_gate(*base, '--channel', 'private', '--visibility', 'private', '--emit-verified', str(verified), pin=pin)
            self.assertEqual(0, ok.returncode, ok.stderr)  # probed-private positive control (synthetic protocol only)
            self.assertEqual(f'{tgz}\trestricted\n', verified.read_text())
            for label, flags in {'public destination': ('--channel', 'private', '--visibility', 'public'),
                                 'unprobed destination': ('--channel', 'private', '--visibility', 'unknown'),
                                 'empty visibility': ('--channel', 'private', '--visibility', ''),
                                 'public channel claimed for a private approval': ('--channel', 'public', '--visibility', 'private')}.items():
                with self.subTest(case=label):
                    result = self.run_artifact_gate(*base, *flags, pin=pin)
                    self.assertEqual(1, result.returncode, result.stdout)
                    self.assertRegex(result.stderr, 'channel|private|visibility')
            for label, entry in {'approval accepted a public destination for a private channel': {**private, 'visibility': 'public'},
                                 'approval without channel': {k: v for k, v in private.items() if k != 'channel'},
                                 'approval without visibility': {k: v for k, v in private.items() if k != 'visibility'}}.items():
                with self.subTest(case=label):
                    other, other_pin = self.approval_file(tmp, {'npm': {'@vaulter/vue': entry}})
                    argv = ('npm', '--tgz', str(tgz), '--approval', str(other), '--source-sha', self.SOURCE, '--channel', 'private', '--visibility', 'private')
                    self.assertEqual(1, self.run_artifact_gate(*argv, pin=other_pin).returncode, label)
            # no channel/visibility at all is not a valid invocation, and a control character cannot reach the verified record
            self.assertNotEqual(0, __import__('subprocess').run(['python3', '-I', str(ROOT / 'scripts/release/artifact-gate.py'), *base], capture_output=True, env={**__import__('os').environ, 'VAULTER_ARTIFACT_APPROVAL_SHA256': pin}).returncode)
            approval, pin = self.approval_file(tmp, {'npm': {'@vaulter/vue': private}})
            odd = Path(tmp) / 'odd\nname.tgz'
            odd.write_bytes(tgz.read_bytes())
            result = self.run_artifact_gate('npm', '--tgz', str(odd), '--approval', str(approval), '--source-sha', self.SOURCE, '--channel', 'private', '--visibility', 'private', '--emit-verified', str(Path(tmp) / 'v2.txt'), pin=pin)
            self.assertEqual(1, result.returncode)
            self.assertIn('control characters', result.stderr)


class OciPromotionPermissionTest(unittest.TestCase):
    def doc(self):
        return policy.load_workflow(ROOT / '.github/workflows/collaboration-hub-image.yml')

    def permission_errors(self, doc):
        return [e for e in policy.check_pins('collaboration-hub-image.yml', doc)
                if 'unjustified write permissions' in e]

    def test_authenticated_same_digest_publisher_has_required_registry_permission(self):
        doc = self.doc()
        self.assertEqual('write', doc['jobs']['image']['permissions']['packages'])
        self.assertTrue(policy.authenticated_oci_promotion('collaboration-hub-image.yml', doc, 'image'))
        self.assertEqual([], self.permission_errors(doc))

    def test_permission_requires_authentication_order_route_digest_and_no_rebuild(self):
        def gate(d):
            return next(s for s in d['jobs']['image']['steps'] if 'artifact-gate.py oci' in s.get('run', ''))

        def promote(d):
            return next(s for s in d['jobs']['image']['steps'] if 'imagetools create' in s.get('run', ''))

        mutations = {
            'fork candidate event': lambda d: d['on'].update(pull_request={}),
            'unqualified publisher': lambda d: d['jobs']['image'].pop('if'),
            'missing route dependency': lambda d: d['jobs']['image'].pop('needs'),
            'unprotected approval hash': lambda d: gate(d)['env'].update(VAULTER_ARTIFACT_APPROVAL_SHA256='candidate'),
            'compare only': lambda d: gate(d).update(run=gate(d)['run'].replace('--approval', '--expect-approval')),
            'gate may skip': lambda d: gate(d).update({'if': 'false'}),
            'gate may fail': lambda d: gate(d).update({'continue-on-error': True}),
            'tag replaces digest': lambda d: promote(d).update(run=promote(d)['run'].replace('@${DIGEST}', ':latest')),
            'different digest output': lambda d: promote(d)['env'].update(DIGEST='sha256:' + 'a' * 64),
            'rebuild in publisher': lambda d: d['jobs']['image']['steps'].append({'run': 'docker build .'}),
            'login precedes approval': lambda d: d['jobs']['image']['steps'].insert(0, {'uses': 'docker/login-action@' + 'a' * 40}),
            'unconditional attestation': lambda d: [s.pop('if', None) for s in d['jobs']['image']['steps'] if 'attest-build-provenance' in s.get('uses', '')],
        }
        for label, mutate in mutations.items():
            with self.subTest(label=label):
                doc = self.doc()
                mutate(doc)
                self.assertTrue(self.permission_errors(doc), label)

    def test_route_smoke_preparer_and_candidate_jobs_cannot_gain_publisher_writes(self):
        for job in ('route', 'smoke', 'prepare', 'candidate'):
            for permission in ('packages', 'id-token', 'contents', 'attestations'):
                with self.subTest(job=job, permission=permission):
                    doc = self.doc()
                    doc['jobs'].setdefault(job, {'steps': []}).setdefault('permissions', {})[permission] = 'write'
                    self.assertTrue(self.permission_errors(doc))
