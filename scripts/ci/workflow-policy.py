#!/usr/bin/env python3
"""CI workflow policy (PLAN3 P1.2, D4).

Checks `.github/workflows/*.yml` as parsed YAML, never as text, so a comment that
mentions `--coverage` or `pull_request` is not a violation:

* load.yml is `workflow_dispatch`-only, and no job `if` waits for an event the workflow
  no longer has (the old-PR trap: such a job is skipped for ever);
* no workflow with a pull_request trigger collects coverage;
* every job has `timeout-minutes`;
* only PR testing workflows cancel in-progress runs; publishing workflows never do.

PLAN3 P1.6 adds the single-CI contract:

* ci.yml is the one PR workflow (push/pull_request/workflow_dispatch, no path filter) and its `ci-gate` job needs every
  other job; the per-package test workflows no longer exist;
* ci.yml and weekly-full.yml carry the actual PHP support matrix in `env.PHP_SUPPORT`, which must equal what the
  composer.json files of the packages declare (Laravel 13 / Testbench 11);
* weekly-full.yml runs on schedule `0 4 * * 1`, by hand and as a reusable workflow; monorepo-split.yml qualifies the
  exact tagged SHA through it and through `CI gate` before anything is published, and never polls for a green run.

PLAN3 P4.19 adds the I14 lane: `consumer-isolation` (a matrix of core, media, drive, documents, webhooks from the
planned `consumer_matrix`) runs scripts/release/consumer-check.sh for real and blocks - `ci-gate` needs it like every other job.

Two more subcommands run inside ci.yml (they are the logic the workflow would otherwise inline as shell):

  workflow-policy.py plan --workflow ci.yml --mode changed|all [--changes F --db-pgsql F --db-mysql F]
      turns `scripts/t --changed --dry-run --json` results into `name=value` job outputs (matrices, lane flags);
  workflow-policy.py gate
      the `ci-gate` verdict from NEEDS (toJSON(needs)); red on failure, cancel, an unexpected skip or an empty matrix.

Usage: workflow-policy.py [workflows-dir]    exit 0 = policy holds, 1 = violations.
"""

from __future__ import annotations

import json
import os
import re
import shlex
import sys
from collections.abc import Mapping
from pathlib import Path

COVERAGE_MARKERS = ('--coverage', 'codecov', '--coverage-clover')

# The single CI.
PHP_PACKAGES = ['core', 'integration', 'media', 'drive', 'documents', 'webhooks', 'azguard', 'filament']
TESTBENCH_TO_LARAVEL = {'10': '12', '11': '13'}  # the Testbench major that supports each Laravel major
LARAVEL_TO_TESTBENCH = {'12': '^10.0', '13': '^11.0'}
REMOVED_WORKFLOWS = [
    'tests.yml', 'drive.yml', 'media.yml', 'documents.yml', 'filament.yml', 'azguard.yml',
    'static-analysis.yml', 'code-style.yml', 'frontend.yml',
]
GATE_JOB = 'ci-gate'
GATE_CHECK_NAME = 'CI gate'
LANES = ['quality', 'fast', 'db', 'serial', 'consumer', 'vue', 'repo', 'openapi', 'contract_bc']
# Packages whose split archive must stand alone. azguard is the external gate, filament has its
# own composer check. A lane that is not named like its job maps here (job id -> `has_<lane>` / `<lane>_matrix` outputs).
CONSUMER_PACKAGES = ['core', 'media', 'drive', 'documents', 'webhooks']
# The repository variables that provision the trusted BC host; the plan step of ci.yml reads them.
BC_HOST_VARIABLES = ('BC_HOST_BOOTSTRAP', 'BC_HOST_PYTHON')
LANE_JOBS = {'consumer': 'consumer-isolation', 'contract_bc': 'contract-bc'}
REQUIRED_BC_LINES = ('php-public', 'npm-vue')


def complete_bc_lines(value: object) -> bool:
    """An exact set, including both npm lines; a duplicated line cannot stand in for a missing one."""
    return isinstance(value, list) and len(value) == len(REQUIRED_BC_LINES) and all(isinstance(line, str) for line in value) and set(value) == set(REQUIRED_BC_LINES)


def lane_job(lane: str) -> str:
    return LANE_JOBS.get(lane, lane)
WEEKLY_CRON = '0 4 * * 1'
ZERO_SHA = '0' * 40


try:
    import yaml
except ImportError:  # `plan` and `gate` run in CI without PyYAML; only reading workflows needs it
    yaml = None

Yaml12Loader = None
if yaml is not None:

    class Yaml12Loader(yaml.SafeLoader):
        """SafeLoader with YAML 1.2 booleans: only true/false, so `on` stays a string."""

    Yaml12Loader.yaml_implicit_resolvers = {
        key: [(tag, regexp) for tag, regexp in resolvers if tag != 'tag:yaml.org,2002:bool']
        for key, resolvers in yaml.SafeLoader.yaml_implicit_resolvers.items()
    }
    Yaml12Loader.add_implicit_resolver(
        'tag:yaml.org,2002:bool',
        re.compile(r'^(?:true|True|TRUE|false|False|FALSE)$'),
        list('tTfF'),
    )


def load_workflow(path: Path) -> dict:
    if yaml is None:
        raise RuntimeError('PyYAML is required to read workflows (pip install pyyaml)')
    doc = yaml.load(path.read_text(encoding='utf-8'), Loader=Yaml12Loader)  # noqa: S506 (SafeLoader subclass)
    if not isinstance(doc, dict):
        raise ValueError(f'{path.name}: not a mapping')
    # Normalised string `on`; a boolean True key would mean a YAML 1.1 loader slipped in.
    if True in doc:
        doc['on'] = doc.pop(True)
    return doc


def triggers(doc: dict) -> dict:
    on = doc.get('on')
    if isinstance(on, str):
        return {on: None}
    if isinstance(on, list):
        return {name: None for name in on}
    return on or {}


def step_commands(doc: dict) -> list[str]:
    """All `run:` strings and `with:`/`env:` values of steps — executable content only."""
    out: list[str] = []
    for job in (doc.get('jobs') or {}).values():
        for step in job.get('steps') or []:
            if 'run' in step:
                out.append(str(step['run']))
            for key in ('with', 'env'):
                for k, v in (step.get(key) or {}).items():
                    out.append(f'{k}={v}')
        for k, v in (job.get('env') or {}).items():
            out.append(f'{k}={v}')
    return out


def check_workflow(name: str, doc: dict) -> list[str]:
    errors: list[str] = []
    events = triggers(doc)
    jobs = doc.get('jobs') or {}

    # Load only by hand.
    if name == 'load.yml' and set(events) != {'workflow_dispatch'}:
        errors.append(f'{name}: triggers must be exactly workflow_dispatch, got {sorted(events)}')

    # Old-PR trap: `job.if` that tests an event the workflow cannot receive.
    for job_id, job in jobs.items():
        cond = job.get('if')
        if cond is None:
            continue
        for event in re.findall(r"github\.event_name\s*==\s*'([a-z_]+)'", str(cond)):
            if event not in events:
                errors.append(f'{name}: job {job_id} if waits for event {event!r} the workflow lacks (always skipped)')

    # No coverage on PR workflows.
    if 'pull_request' in events:
        for job_id, job in jobs.items():
            for step in job.get('steps') or []:
                blob = ' '.join([str(step.get('run', '')), str(step.get('uses', ''))] + [f'{k}={v}' for k, v in (step.get('with') or {}).items()])
                if any(marker in blob for marker in COVERAGE_MARKERS):
                    errors.append(f'{name}: job {job_id} collects coverage on pull_request')
                if str((step.get('with') or {}).get('coverage', 'none')) not in ('none', 'None'):
                    errors.append(f'{name}: job {job_id} installs a coverage driver on pull_request')
                if str((step.get('env') or {}).get('XDEBUG_MODE', '')) == 'coverage':
                    errors.append(f'{name}: job {job_id} sets XDEBUG_MODE=coverage on pull_request')

    # Every job is bounded (a job that only calls a reusable workflow cannot carry a timeout; its callee's jobs do).
    for job_id, job in jobs.items():
        if 'uses' in job:
            continue
        timeout = job.get('timeout-minutes')
        if not isinstance(timeout, int) or isinstance(timeout, bool) or timeout <= 0:
            errors.append(f'{name}: job {job_id} has no positive timeout-minutes')

    # Concurrency: PR testing cancels superseded runs, publishing never cancels.
    concurrency = doc.get('concurrency')
    cancel = str(concurrency.get('cancel-in-progress', 'false')) if isinstance(concurrency, dict) else 'false'
    if 'pull_request' in events:
        if not isinstance(concurrency, dict) or not ('pull_request' in cancel or cancel.lower() == 'true'):
            errors.append(f'{name}: pull_request workflow must cancel superseded runs (concurrency.cancel-in-progress)')
    elif cancel.lower() == 'true' or 'event_name' in cancel:
        errors.append(f'{name}: non-PR workflow must not cancel in-progress runs')

    return errors


def derive_support(root: Path) -> dict:
    """The actual PHP support matrix, read from composer.json / groups.php of every package."""
    support: dict = {}
    for pkg in PHP_PACKAGES:
        directory = '.' if pkg in ('core', 'integration') else f'packages/{pkg}'
        composer = json.loads((root / 'packages' / ('core' if pkg == 'integration' else pkg) / 'composer.json').read_text(encoding='utf-8'))
        dev = composer.get('require-dev') or {}
        testbench = str(dev.get('orchestra/testbench', ''))
        lines = sorted({TESTBENCH_TO_LARAVEL[m] for m in re.findall(r'\^(\d+)', testbench) if m in TESTBENCH_TO_LARAVEL})
        # core runs from the repository root, whose composer.json owns the type-coverage plugin and rector.
        tooling = json.loads((root / 'composer.json').read_text(encoding='utf-8')).get('require-dev', {}) if pkg in ('core', 'integration') else dev
        groups = root / 'tests' / 'Integration' / 'groups.php' if pkg == 'integration' else root / 'packages' / pkg / 'tests' / 'groups.php'
        text = re.sub(r'//[^\n]*|/\*.*?\*/', '', groups.read_text(encoding='utf-8') if groups.exists() else '', flags=re.S)
        serial = re.search(r"'serial'\s*=>\s*(?:\[|array\s*\()(.*?)(?:\]|\))\s*,", text, re.S)
        database = re.search(r"'database'\s*=>\s*(?:\[|array\s*\()(.*?)(?:\]|\))\s*,", text, re.S)
        support[pkg] = {
            'dir': directory,
            'lines': lines,
            # A package without database tests has no database partition for scripts/t to run, so no db lane.
            'database': bool(database and database.group(1).strip()),
            'serial': bool(serial and serial.group(1).strip()),
            'types': 'pestphp/pest-plugin-type-coverage' in tooling,
            'rector': (root / directory / 'rector.php').exists(),
            'azguard': 'axiomasoft/azguard' in (composer.get('require') or {}),
        }
    return support


def check_support(name: str, doc: dict, root: Path) -> list[str]:
    raw = (doc.get('env') or {}).get('PHP_SUPPORT')
    if raw is None:
        return [f'{name}: env.PHP_SUPPORT (the actual PHP support matrix) is missing']
    try:
        declared = json.loads(raw)
    except (TypeError, ValueError) as exc:
        return [f'{name}: env.PHP_SUPPORT is not JSON ({exc})']
    expected = derive_support(root)
    if declared == expected:
        return []
    errors = []
    for pkg in sorted(set(expected) | set(declared)):
        if declared.get(pkg) != expected.get(pkg):
            errors.append(f'{name}: PHP_SUPPORT[{pkg}] is {declared.get(pkg)}, composer.json/groups.php say {expected.get(pkg)}')
    return errors


def has_path_filter(doc: dict) -> list[str]:
    return [event for event, config in triggers(doc).items()
            if isinstance(config, dict) and ('paths' in config or 'paths-ignore' in config)]


def check_gate(name: str, doc: dict, gate_job: str, label: str) -> list[str]:
    jobs = doc.get('jobs') or {}
    gate = jobs.get(gate_job)
    if gate is None:
        return [f'{name}: no {gate_job} job']
    errors: list[str] = []
    if gate.get('name') != label:
        errors.append(f'{name}: {gate_job} must be named {label!r} (the check name the release looks for), got {gate.get("name")!r}')
    if not re.search(r'\balways\(\)', str(gate.get('if', ''))):
        errors.append(f'{name}: {gate_job} must run `if: always()`, or a skipped dependency would skip the gate green')
    needs = gate.get('needs') or []
    needs = [needs] if isinstance(needs, str) else list(needs)
    missing = sorted(set(jobs) - {gate_job} - set(needs))
    if missing:
        errors.append(f'{name}: {gate_job} does not need {missing}')
    for step in gate.get('steps') or []:
        if 'continue-on-error' in step and str(step['continue-on-error']).lower() != 'false':
            errors.append(f'{name}: {gate_job} has a step that may fail silently')
    return errors


def check_consumer_isolation(name: str, job: dict) -> list[str]:
    """The I14 lane runs scripts/release/consumer-check.sh once per consumer package, from the planned matrix, and blocks."""
    if not job:
        return []
    errors: list[str] = []
    matrix = (job.get('strategy') or {}).get('matrix')
    if 'consumer_matrix' not in str(matrix):
        errors.append(f'{name}: consumer-isolation must take its matrix from the planned consumer_matrix output')
    if 'has_consumer' not in str(job.get('if', '')):
        errors.append(f'{name}: consumer-isolation must be gated by the planned has_consumer output')
    commands = ' '.join(str(step.get('run', '')) for step in job.get('steps') or [])
    if 'scripts/release/consumer-check.sh' not in commands:
        errors.append(f'{name}: consumer-isolation must run scripts/release/consumer-check.sh')
    if '--dry-run' in commands:
        errors.append(f'{name}: consumer-isolation must run the harness for real, not as a --dry-run')
    for step in job.get('steps') or []:
        if str(step.get('continue-on-error', 'false')).lower() != 'false':
            errors.append(f'{name}: consumer-isolation is blocking; a step may not continue-on-error')
    if str(job.get('continue-on-error', 'false')).lower() != 'false':
        errors.append(f'{name}: consumer-isolation is blocking; the job may not continue-on-error')
    return errors


def check_ci(doc: dict, root: Path) -> list[str]:
    name = 'ci.yml'
    errors: list[str] = []
    events = triggers(doc)
    if set(events) != {'push', 'pull_request', 'workflow_dispatch'}:
        errors.append(f'{name}: triggers must be exactly push, pull_request, workflow_dispatch, got {sorted(events)}')
    for event in has_path_filter(doc):
        errors.append(f'{name}: {event} has a paths filter; the required workflow must always run (docs-only is decided by `changes`)')
    errors += check_gate(name, doc, GATE_JOB, GATE_CHECK_NAME)
    jobs = doc.get('jobs') or {}
    for lane in ['changes'] + LANES:
        if lane_job(lane) not in jobs:
            errors.append(f'{name}: no {lane_job(lane)} job')
    errors += check_consumer_isolation(name, jobs.get(lane_job('consumer')) or {})
    for job_id, job in jobs.items():
        if job_id in ('changes', GATE_JOB):
            continue
        if 'changes' not in ([job['needs']] if isinstance(job.get('needs'), str) else (job.get('needs') or [])):
            errors.append(f'{name}: job {job_id} does not need `changes`')
    errors += check_contract_bc(jobs.get('contract-bc') or {})
    errors += check_sca(jobs.get('sca') or {})
    errors += check_npm_declaration_job('vue', '@vaulter/vue', 'vue', jobs.get('vue') or {})
    for output in ('has_contract_bc', 'contract_bc_provisioned', 'contract_bc_required_lines', 'contract_bc_selected_lines'):
        if (jobs.get('changes', {}).get('outputs') or {}).get(output) != '${{ steps.plan.outputs.' + output + ' }}':
            errors.append(f'{name}: changes must expose {output} from the actual plan')
    plan_env = next((step.get('env') or {} for step in (jobs.get('changes') or {}).get('steps', []) if step.get('id') == 'plan'), {})
    for variable in BC_HOST_VARIABLES:
        if plan_env.get(variable) != '${{ vars.' + variable + ' }}':
            errors.append(f'{name}: the plan step must read {variable} from the repository variables')
    errors += check_bc_release_policy(root)
    errors += check_support(name, doc, root)
    return errors


def check_sca(job: dict) -> list[str]:
    errors = []
    if not job or job.get('if') or job.get('continue-on-error') or job.get('permissions') != {'contents': 'read'}:
        errors.append('ci.yml: sca must be unconditional and blocking with read-only permissions')
    if (job.get('env') or {}).get('AUDIT_SUPPRESSIONS_SHA256') != '${{ vars.AUDIT_SUPPRESSIONS_SHA256 }}':
        errors.append('ci.yml: sca suppression approval must come from protected external variable')
    commands = '\n'.join(str(step.get('run','')) for step in job.get('steps', []))
    for required in ('composer audit --locked --format=json', 'npm audit --omit=dev --audit-level=high --json', 'input-locks.sha256', 'composer.version', 'npm.version', 'composer.exit', 'npm.exit', 'composer.stderr', 'npm.stderr', 'scripts/ci/audit-gate.py', 'scripts/ci/engineering-rails.py', 'scripts/ci/postgres-fks.sh', '--composer-exit=', '--npm-exit=', '--suppressions=release/audit-suppressions.json'):
        if required not in commands: errors.append('ci.yml: sca missing ' + required)
    for step in job.get('steps', []):
        command = str(step.get('run',''))
        if any(f'scripts/ci/{check}' in command for check in ('audit-gate.py', 'engineering-rails.py', 'postgres-fks.sh')) and (step.get('if') or step.get('continue-on-error') or '||' in command):
            errors.append('ci.yml: sca gate may not be bypassed')
    return errors


def check_contract_bc(job: dict) -> list[str]:
    errors = []
    blob = json.dumps(job)
    for token in ('has_contract_bc', 'BC_HOST_BOOTSTRAP', 'BC_TOOL_ROOT', 'BC_TOOL_REV', 'BC_RUNTIME_APPROVAL', 'BC_SHA256SUMS', 'fetch-depth', 'fetch-tags', '-I'):
        if token not in blob:
            errors.append('ci.yml: contract-bc missing trusted boundary ' + token)
    if 'secrets.' in blob or 'scripts/php84' in blob or str(job.get('continue-on-error', False)).lower() != 'false':
        errors.append('ci.yml: contract-bc must be blocking, without candidate runtime or secrets')
    for step in job.get('steps', []):
        if str(step.get('continue-on-error', False)).lower() != 'false':
            errors.append('ci.yml: contract-bc step may not hide tool errors')
        if 'actions/checkout@' in step.get('uses', ''):
            config = step.get('with', {})
            if config.get('fetch-depth') != 0 or config.get('fetch-tags') is not True or config.get('persist-credentials') is not False:
                errors.append('ci.yml: contract-bc needs full tags/history without persisted credentials')
    env = job.get('env') or {}
    required = str(env.get('BC_REQUIRED_LINES', '')).split(',')
    selected = env.get('BC_SELECTED_LINES')
    if not complete_bc_lines(required):
        errors.append('ci.yml: contract-bc BC_REQUIRED_LINES must include both PHP/npm lines')
    if selected != 'all' and not complete_bc_lines(str(selected).split(',')):
        errors.append('ci.yml: contract-bc BC_SELECTED_LINES must select both PHP/npm lines')
    if job.get('if') != "needs.changes.outputs.has_contract_bc == 'true'":
        errors.append('ci.yml: contract-bc must follow the actual planned condition')
    return errors


def check_npm_declaration_job(job_id: str, workspace: str, lane: str, job: dict) -> list[str]:
    errors = []
    command = f'npm run api:surface:check --workspace {workspace}'
    steps = [step for step in job.get('steps', []) if str(step.get('run', '')).strip() == command]
    if len(steps) != 1 or any('if' in step or str(step.get('continue-on-error', False)).lower() != 'false' for step in steps):
        errors.append(f'{job_id}: declaration guard must run the exact api:surface:check command unconditionally and block errors')
    if job.get('if') != f"needs.changes.outputs.has_{lane} == 'true'" or str(job.get('continue-on-error', False)).lower() != 'false':
        errors.append(f'{job_id}: declaration guard must follow the actual lane condition and block errors')
    if job.get('permissions') != {'contents': 'read'} or 'secrets.' in json.dumps(job):
        errors.append(f'{job_id}: declaration guard runs candidate code only in an unprivileged job without secrets')
    checkouts = [step for step in job.get('steps', []) if 'actions/checkout@' in step.get('uses', '')]
    if not checkouts or any((step.get('with') or {}).get('persist-credentials') is not False for step in checkouts):
        errors.append(f'{job_id}: declaration guard must not retain checkout credentials')
    if job_id == 'vue' and not any(step.get('run') == 'npm run api:check --workspace @vaulter/vue' for step in job.get('steps', [])):
        errors.append('vue: OpenAPI api:check must remain distinct from the declaration guard')
    return errors


def check_bc_release_policy(root: Path) -> list[str]:
    """Compare trusted policy pins with the actual independent package lines, never with candidate approvals."""
    try:
        policy = json.loads((root / 'scripts/release/Contract/bc-trust-policy.json').read_text())
        manifest = json.loads((root / 'release/packages.json').read_text())
        lines = policy['lines']
        if set(lines) != set(REQUIRED_BC_LINES):
            return ['ci.yml: trusted BC policy must require both PHP/npm release lines']
        errors = []
        for entry in manifest['php'] + manifest['npm']:
            definition = lines.get(entry['release_line'], {})
            if definition.get('prefix') != entry['tag_prefix'] or type(definition.get('major')) is not int or definition['major'] < 0:
                errors.append(f"ci.yml: trusted BC policy differs from release/packages.json for {entry['release_line']}")
        for entry in manifest['npm']:
            version = json.loads((root / entry['path'] / 'package.json').read_text())['version']
            if lines[entry['release_line']]['major'] != int(version.split('.')[0]):
                errors.append(f"ci.yml: trusted BC policy must pin the independent npm major for {entry['release_line']}")
        return errors
    except (OSError, ValueError, KeyError, TypeError) as error:
        return [f'ci.yml: unreadable trusted BC release-line policy: {error}']


def check_weekly(doc: dict, root: Path) -> list[str]:
    name = 'weekly-full.yml'
    errors: list[str] = []
    events = triggers(doc)
    if set(events) != {'schedule', 'workflow_dispatch', 'workflow_call'}:
        errors.append(f'{name}: triggers must be exactly schedule, workflow_dispatch, workflow_call, got {sorted(events)}')
    crons = [item.get('cron') for item in (events.get('schedule') or [])]
    if crons != [WEEKLY_CRON]:
        errors.append(f'{name}: schedule must be {WEEKLY_CRON!r}, got {crons}')
    errors += check_gate(name, doc, 'full-gate', 'Full qualification gate')
    errors += check_support(name, doc, root)
    commands = ' '.join(step_commands(doc))
    for needle, why in (('--full', 'full + serial + extended in one pass'), ('--coverage', 'pcov coverage'),
                        ('scripts/t stand', 'the stand')):
        if needle not in commands:
            errors.append(f'{name}: no step runs {needle} ({why})')
    if 'pcov' not in json.dumps(doc):
        errors.append(f'{name}: coverage must use the pcov driver')
    return errors


def check_split(doc: dict) -> list[str]:
    """The release handshake: qualify the exact tagged SHA first, publish only after it, never poll."""
    name = 'monorepo-split.yml'
    errors: list[str] = []
    jobs = doc.get('jobs') or {}
    qualification = [job_id for job_id, job in jobs.items() if str(job.get('uses', '')).endswith('/weekly-full.yml')]
    if len(qualification) != 1:
        errors.append(f'{name}: exactly one job must call ./.github/workflows/weekly-full.yml (full qualification of the tag)')
        return errors
    verify = jobs.get('verify-release-sha') or {}
    needs = verify.get('needs') or []
    needs = [needs] if isinstance(needs, str) else list(needs)
    if qualification[0] not in needs:
        errors.append(f'{name}: verify-release-sha must need {qualification[0]} (the tag is qualified before anything is published)')
    runs = ' '.join(str(step.get('run', '')) for step in verify.get('steps') or [])
    env = ' '.join(f'{k}={v}' for step in verify.get('steps') or [] for k, v in (step.get('env') or {}).items())
    if GATE_CHECK_NAME not in runs:
        errors.append(f'{name}: verify-release-sha must require the {GATE_CHECK_NAME!r} check')
    if 'github.sha' not in env:
        errors.append(f'{name}: verify-release-sha must check the exact tagged SHA (github.sha)')
    if re.search(r'\b(sleep|until|while)\b', runs):
        errors.append(f'{name}: verify-release-sha must not poll for a green run (a single check of the exact SHA)')
    for stale in ('Laravel Pint', 'Larastan', 'PHP 8.4 /', '@vaulter/vue (Node'):
        if stale in runs:
            errors.append(f'{name}: verify-release-sha still requires the pre-P1.6 check {stale!r}')
    route_needs = (jobs.get('route') or {}).get('needs')
    if route_needs != 'verify-release-sha' and route_needs != ['verify-release-sha']:
        errors.append(f'{name}: route must need verify-release-sha')
    split_needs = (jobs.get('split') or {}).get('needs')
    split_needs = [split_needs] if isinstance(split_needs, str) else list(split_needs or [])
    if 'route' not in split_needs:
        errors.append(f'{name}: split must need route (routing + qualification before the split credential)')
    errors += check_route_before_credentials(name, doc, 'split', 'route', ('GH_ACCESS_TOKEN',))
    return errors


# Every publisher routes the tag and checks the qualification evidence in a job that holds no
# publishing credential; the credentialed job needs that job.
PUBLISHERS = {
    'release-npm.yml': ('release', ('NPM_TOKEN',)),
}


def check_route_before_credentials(name: str, doc: dict, publisher: str, route: str, credentials: tuple[str, ...]) -> list[str]:
    errors: list[str] = []
    jobs = doc.get('jobs') or {}
    route_job = jobs.get(route)
    publish_job = jobs.get(publisher)
    if not isinstance(route_job, dict) or not isinstance(publish_job, dict):
        return [f'{name}: needs a {route} job (routing + qualification) and a {publisher} job']
    commands = ' '.join(str(step.get('run', '')) for step in route_job.get('steps') or [])
    if 'scripts/release/route-release.php' not in commands:
        errors.append(f'{name}: {route} must run scripts/release/route-release.php')
    if 'evidence-dir' not in commands and '--evidence' not in commands:
        errors.append(f'{name}: {route} must pass the qualification evidence to route-release.php')
    dumped = json.dumps(route_job)
    for credential in credentials:
        if credential in dumped:
            errors.append(f'{name}: {route} must not hold the publisher credential {credential}')
    needs = publish_job.get('needs') or []
    needs = [needs] if isinstance(needs, str) else list(needs)
    if route not in needs:
        errors.append(f'{name}: {publisher} must need {route} (no credential before routing + qualification)')
    if not any(credential in json.dumps(publish_job) for credential in credentials):
        errors.append(f'{name}: {publisher} no longer shows the credential this policy guards {credentials}')
    return errors


# --- ONE accepted artifact per release flow ---------------------------------------------------------------------
NPM_CREDENTIALS = ('NPM_TOKEN', 'NODE_AUTH_TOKEN')


def _needs(job: dict) -> list[str]:
    needs = job.get('needs') or []
    return [needs] if isinstance(needs, str) else list(needs)


def _runs(job: dict) -> str:
    return '\n'.join(str(step.get('run', '')) for step in job.get('steps') or [])


def _uses(job: dict) -> str:
    return ' '.join(str(step.get('uses', '')) for step in job.get('steps') or [])


def _check_approval_gate(name: str, step: dict, variable: str, credentials: tuple[str, ...]) -> list[str]:
    """The gate authenticates the external approval bytes (protected variable only); compare-only is not an approval gate."""
    errors: list[str] = []
    if (step.get('env') or {}).get(variable) != '${{ vars.' + variable + ' }}':
        errors.append(f'{name}: the approval hash comes only from vars.{variable}')
    run = str(step.get('run', ''))
    if 'artifact-gate.py' in run and ('--approval' not in run or '--source-sha' not in run):
        errors.append(f'{name}: artifact-gate.py without --approval/--source-sha is a compare-only check, not an approval gate')
    if re.search(r'--expect-|--tested-|--bind', run):
        errors.append(f'{name}: expected values supplied on the command line are not authority')
    if any(credential in json.dumps(step) for credential in credentials):
        errors.append(f'{name}: the verify step holds a publisher credential')
    return errors


def check_artifacts(name: str, doc: dict, text: str = '') -> list[str]:
    """npm: pack once without a credential, publish that tgz. OCI: promote the tested digest. PHP: consume the prepared record."""
    errors: list[str] = []
    jobs = doc.get('jobs') or {}
    if name == 'release-npm.yml':
        pack, release, version = jobs.get('pack'), jobs.get('release'), jobs.get('version-pr')
        if not isinstance(pack, dict) or not isinstance(release, dict):
            return [f'{name}: needs a credential-free pack job and a release job that publishes the accepted tgz']
        dumped = json.dumps(pack)
        if any(token in dumped for token in NPM_CREDENTIALS) or 'id-token' in dumped:
            errors.append(f'{name}: pack must hold no registry credential and mint no OIDC token')
        if 'npm pack' not in _runs(pack):
            errors.append(f'{name}: pack must run npm pack once')
        if (pack.get('permissions') or {}).get('contents') == 'write':
            errors.append(f'{name}: pack must not write repository contents')
        if 'pack' not in _needs(release):
            errors.append(f'{name}: release must need pack (it publishes the accepted tgz)')
        runs = _runs(release)
        if re.search(r'\bnpm\s+(pack|ci|install|i|run\s+(build|release|version))\b|changeset\s+publish', runs) or 'changesets/action' in _uses(release):
            errors.append(f'{name}: release must not pack, install, build or let Changesets publish; it publishes the accepted tgz only')
        if 'download-artifact' not in _uses(release):
            errors.append(f'{name}: release must download the accepted tgz artifact')
        steps = release.get('steps') or []
        gate = next((i for i, step in enumerate(steps) if 'artifact-gate.py npm' in str(step.get('run', ''))), None)
        publish = next((i for i, step in enumerate(steps) if 'npm publish' in str(step.get('run', ''))), None)
        if gate is None or publish is None or gate > publish:
            errors.append(f'{name}: release must verify the tgz with artifact-gate.py npm before npm publish')
        else:
            errors += _check_approval_gate(name, steps[gate], 'VAULTER_ARTIFACT_APPROVAL_SHA256', NPM_CREDENTIALS)
            if any(token in json.dumps(step) for step in steps[:gate + 1] for token in NPM_CREDENTIALS):
                errors.append(f'{name}: no npm credential may appear before or in the verify step')
            verify_run = str(steps[gate].get('run', ''))
            if '--channel' not in verify_run or '--visibility' not in verify_run:
                errors.append(f'{name}: the verify step must pass --channel and --visibility (private needs a probed private destination)')
            publish_run = str(steps[publish].get('run', ''))
            if '--access "${access}"' not in publish_run or 'public|restricted' not in publish_run:
                errors.append(f'{name}: npm access is derived from the verified record only (public|restricted)')
            if '--ignore-scripts' not in publish_run or re.search(r'artifact-gate|python|\bnpm\s+(pack|ci|install|run)\b|\bnode\b|\bbash\b\s+\S+\.sh', publish_run):
                errors.append(f'{name}: the secret step only runs npm publish --ignore-scripts of the verified tgz')
            if len(steps) > publish + 1 and any(token in json.dumps(step) for step in steps[publish + 1:] for token in NPM_CREDENTIALS):
                errors.append(f'{name}: the npm credential belongs to the publish step only')
        if isinstance(version, dict) and any(token in json.dumps(version) for token in NPM_CREDENTIALS):
            errors.append(f'{name}: version-pr must not hold the registry token')
    elif name == 'monorepo-split.yml':
        prepare, split = jobs.get('prepare'), jobs.get('split')
        if not isinstance(prepare, dict) or not isinstance(split, dict):
            return [f'{name}: needs a prepare job and a split job that consumes its record']
        if 'GH_ACCESS_TOKEN' in json.dumps(prepare):
            errors.append(f'{name}: prepare must hold no publisher credential')
        if 'prepare' not in _needs(split):
            errors.append(f'{name}: split must need prepare (one prepared artifact, no second build)')
        if re.search(r'--force|push\s+-f\b|\+refs/', text or json.dumps(doc)):
            errors.append(f'{name}: publication is never forced')
        if re.search(r'build-artifacts\.php\s+--version', _runs(split)):
            errors.append(f'{name}: split must not build again; it consumes the prepared record')
        steps = split.get('steps') or []
        gate = next((i for i, step in enumerate(steps) if '--require-approved' in str(step.get('run', ''))), None)
        push = next((i for i, step in enumerate(steps) if '--materialize-split' in str(step.get('run', ''))), None)
        if gate is None or push is None or gate > push:
            errors.append(f'{name}: --require-approved must run before the push of the prepared commit')
        else:
            if (steps[gate].get('env') or {}).get('VAULTER_PREPARED_APPROVAL_SHA256') != '${{ vars.VAULTER_PREPARED_APPROVAL_SHA256 }}':
                errors.append(f'{name}: the approval hash comes only from vars.VAULTER_PREPARED_APPROVAL_SHA256')
            if any('GH_ACCESS_TOKEN' in json.dumps(step) for step in steps[:gate + 1]):
                errors.append(f'{name}: no publisher credential may be read before the approval gate')
            if '--atomic' not in str(steps[push].get('run', '')):
                errors.append(f'{name}: the prepared commit is pushed atomically')
    return errors


def check_publisher(name: str, doc: dict) -> list[str]:
    publisher, credentials = PUBLISHERS[name]
    errors = check_route_before_credentials(name, doc, publisher, 'route', credentials)
    if name == 'release-npm.yml' and 'NPM_TOKEN' in json.dumps((doc.get('jobs') or {}).get('version-pr', {})):
        errors.append(f'{name}: version-pr must not hold the registry token')
    return errors


def pinned_image(ref: object) -> bool:
    """A digest pin, or a GitHub expression whose every image reference is digest-pinned.

    A database cell selects one engine. The workflow writes both images in one expression
    (`matrix.engine == 'pgsql' && 'postgres…@sha256:…' || 'mysql…@sha256:…'`) so the pin stays
    in the file a reviewer reads, and the job starts only the engine that cell tests.
    """
    if not isinstance(ref, str):
        return False
    if re.fullmatch(r'[^\s@]+@sha256:[0-9a-f]{64}', ref) is not None:
        return True
    text = ref.strip()
    if not (text.startswith('${{') and text.endswith('}}')):
        return False
    pins = re.findall(r'[A-Za-z0-9_./-]+:[A-Za-z0-9_.-]+@sha256:[0-9a-f]{64}', text)
    if not pins:
        return False
    remainder = text
    for pin in pins:
        remainder = remainder.replace(pin, '')
    bare = re.search(r'(?:^|[^A-Za-z0-9_./-])(?:[A-Za-z0-9_.-]+/)?[A-Za-z0-9_.-]+:[A-Za-z0-9_.-]+', remainder)
    return bare is None


def docker_run_images(command: str) -> list[str]:
    """Read the image positional argument, never option values or container command arguments."""
    if not re.search(r'\bdocker\s+run\b', command):
        return []
    lexer = shlex.shlex(command.replace('\\\n', ''), posix=True, punctuation_chars=';&|()')
    lexer.whitespace_split = True
    tokens = list(lexer)
    values = set(('add-host annotation attach blkio-weight blkio-weight-device cap-add cap-drop cgroup-parent cgroupns cidfile '
                  'cpu-count cpu-percent cpu-period cpu-quota cpu-rt-period cpu-rt-runtime cpu-shares cpus cpuset-cpus cpuset-mems '
                  'device device-cgroup-rule device-read-bps device-read-iops device-write-bps device-write-iops dns dns-option '
                  'dns-search domainname entrypoint env env-file expose gpus group-add health-cmd health-interval health-retries '
                  'health-start-interval health-start-period health-timeout hostname init-path io-maxbandwidth io-maxiops ip ip6 '
                  'ipc isolation label label-file link link-local-ip log-driver log-opt mac-address memory memory-reservation '
                  'memory-swap memory-swappiness mount name network network-alias oom-score-adj pid pids-limit platform publish '
                  'pull restart runtime security-opt shm-size stop-signal stop-timeout storage-opt sysctl tmpfs ulimit user userns '
                  'uts volume volume-driver volumes-from workdir').split())
    flags = set('detach disable-content-trust help init interactive no-healthcheck oom-kill-disable privileged publish-all read-only rm sig-proxy tty'.split())
    images = []
    for i, token in enumerate(tokens[:-1]):
        if token.rsplit('/', 1)[-1] != 'docker' or tokens[i + 1] != 'run':
            continue
        index = i + 2
        while index < len(tokens) and tokens[index].startswith('-'):
            option = tokens[index]
            index += 1
            if option == '--':
                break
            if option.startswith('--'):
                name, equal, _ = option[2:].partition('=')
                if name in values:
                    if not equal: index += 1
                elif name not in flags:
                    raise ValueError('unsupported Docker run option')
            else:
                # Boolean short flags can cluster (-it); value flags can attach their value (-w/repo).
                for offset, short in enumerate(option[1:], 1):
                    if short in 'a c e h l m p u v w'.split():
                        if offset == len(option) - 1: index += 1
                        break
                    if short not in 'ditP':
                        raise ValueError('unsupported Docker run short option')
        if index >= len(tokens) or tokens[index] in (';', '&&', '||', '|', '&', '(', ')'):
            raise ValueError('missing Docker run image argument')
        images.append(tokens[index])
    return images


def pin_commit(chain: list[dict]) -> str:
    """Validate a resolver's complete annotated-tag chain, ending at a commit."""
    if not chain or len(chain) > 9:
        raise ValueError('missing/too deep tag chain')
    origin = re.fullmatch(r'https://api.github.com/repos/([^/]+/[^/]+)/git/ref/tags/[^\s]+', chain[0].get('url', ''))
    if origin is None:
        raise ValueError('invalid primary ref URL')
    prefix = 'https://api.github.com/repos/' + origin[1] + '/git/tags/'
    for i, row in enumerate(chain):
        obj = row.get('object', {})
        if not re.fullmatch(r'[0-9a-f]{40}', str(obj.get('sha', ''))):
            raise ValueError('invalid object SHA')
        if obj.get('type') != ('commit' if i == len(chain) - 1 else 'tag'):
            raise ValueError('tag chain must end at commit')
        if i and row.get('url') != prefix + chain[i-1]['object']['sha']:
            raise ValueError('disconnected tag chain')
    return chain[-1]['object']['sha']


def pushes_checkout(job: dict) -> bool:
    # Only actions that actually commit/push need checkout's saved credential.
    return any(str(step.get('uses', '')).startswith('changesets/action@')
               for step in job.get('steps', []))


def check_pins(name: str, doc: dict, text: str = '') -> list[str]:
    errors = []
    top = doc.get('permissions')
    if not isinstance(top, dict) or any(v != 'read' for v in top.values()):
        errors.append(f'{name}: pin permissions must be explicit read/empty at workflow level')
    def uses(ref):
        if not isinstance(ref, str):
            errors.append(f'{name}: pin invalid uses')
        elif ref.startswith('./'):
            if '..' in Path(ref).parts or '@' in ref:
                errors.append(f'{name}: pin unsafe local uses')
        elif ref.startswith('docker://'):
            if not pinned_image(ref[9:]): errors.append(f'{name}: pin docker action digest required')
        elif not re.fullmatch(r'[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+(?:/[A-Za-z0-9_./-]+)?@[0-9a-f]{40}', ref):
            errors.append(f'{name}: pin full action commit required: {ref}')
        elif text and not re.search(re.escape(ref) + r"['\"]?\s+# v[^\s]+", text):
            errors.append(f'{name}: pin version comment required: {ref}')
    for job_id, job in (doc.get('jobs') or {}).items():
        if 'uses' in job: uses(job['uses'])
        steps = job.get('steps') or []
        refs = ' '.join(str(step.get('uses', '')) for step in steps)
        # Narrow capability grants require their actual consuming action.
        allowed = set()
        if 'changesets/action@' in refs: allowed |= {'contents', 'pull-requests'}
        if 'actions/deploy-pages@' in refs: allowed |= {'pages', 'id-token'}
        if 'docker/build-push-action@' in refs: allowed |= {'packages'}
        if 'actions/attest-build-provenance@' in refs: allowed |= {'attestations', 'id-token'}
        if 'CodelyTV/pr-size-labeler@' in refs: allowed |= {'pull-requests'}
        permissions = job.get('permissions', {})
        if not isinstance(permissions, dict) or any(v not in ('read', 'none', 'write') or (v == 'write' and k not in allowed) for k,v in permissions.items()):
            errors.append(f'{name}:{job_id}: pin unjustified write permissions')
        containers = [job.get('container')] + list((job.get('services') or {}).values())
        for container in containers:
            if container is not None:
                image = container.get('image') if isinstance(container, dict) else container
                if not pinned_image(image): errors.append(f'{name}:{job_id}: pin container digest required')
        for step in steps:
            ref = str(step.get('uses', ''))
            if ref: uses(ref)
            if ref.startswith('actions/checkout@') and not pushes_checkout(job) and (step.get('with') or {}).get('persist-credentials') is not False:
                errors.append(f'{name}:{job_id}: pin checkout persist-credentials must be false')
            command = str(step.get('run', ''))
            try:
                for image in docker_run_images(command):
                    if not pinned_image(image):
                        errors.append(f'{name}:{job_id}: pin docker run digest required')
            except ValueError:
                errors.append(f'{name}:{job_id}: pin cannot determine Docker run image argument')
            if re.search(r'\bnpm\s+(?:install|i)\b', command):
                errors.append(f'{name}:{job_id}: pin use npm ci with committed lock')
    return errors


def check_docker_pins(text: str) -> list[str]:
    errors, stages = [], set()
    for line in text.splitlines():
        match = re.match(r'\s*FROM\s+(?:--platform=\S+\s+)?(\S+)(?:\s+AS\s+(\S+))?\s*$', line, re.I)
        if not match: continue
        ref, alias = match.groups()
        if ref.lower() not in stages and ref != 'scratch' and not pinned_image(ref):
            errors.append('Dockerfile: pin external FROM digest required: ' + ref)
        if alias: stages.add(alias.lower())
    return errors


def check_dir(directory: Path) -> list[str]:
    errors: list[str] = []
    root = directory.resolve().parents[1]
    files = sorted(directory.glob('*.yml'))
    names = {f.name for f in files}
    for required in ('load.yml', 'ci.yml', 'weekly-full.yml', 'monorepo-split.yml'):
        if required not in names:
            errors.append(f'{required}: missing')
    for removed in REMOVED_WORKFLOWS:
        if removed in names:
            errors.append(f'{removed}: a per-package/per-tool test workflow; its checks live in ci.yml')
    for path in files:
        try:
            doc = load_workflow(path)
        except (yaml.YAMLError, ValueError) as exc:
            errors.append(f'{path.name}: unparsable ({exc})')
            continue
        errors += check_workflow(path.name, doc)
        errors += check_pins(path.name, doc, path.read_text())
        if path.name == 'ci.yml':
            errors += check_ci(doc, root)
        elif path.name == 'weekly-full.yml':
            errors += check_weekly(doc, root)
        elif path.name == 'monorepo-split.yml':
            errors += check_split(doc)
        elif path.name in PUBLISHERS:
            errors += check_publisher(path.name, doc)
        if path.name in ('monorepo-split.yml', 'release-npm.yml'):
            errors += check_artifacts(path.name, doc, path.read_text())
    return errors


# --- plan: `scripts/t --changed --dry-run --json` -> job outputs -------------------------------------------------

def _suites(plan: dict) -> set[str]:
    return {run['suite'] for run in plan.get('runs') or []}


def _bool(value: bool) -> str:
    return 'true' if value else 'false'


def plan_outputs(support: dict, mode: str, changes: dict | None = None, db: dict | None = None, bc_provisioned: bool = True) -> dict[str, str]:
    """Matrices and lane flags of ci.yml. `all` (no usable base commit) is every default lane; `changed` follows the selector.
    `bc_provisioned` is false on a repository without the trusted BC host: contract-bc is then not planned."""
    php = [pkg for pkg in PHP_PACKAGES if pkg in support]
    skip_reason = ''
    if mode == 'all':
        suites = set(php) | {'repo', 'vue'}
        db_suites = {'pgsql': set(php), 'mysql': set(php)}
    elif mode == 'changed':
        if changes is None or db is None:
            raise ValueError('mode changed needs the changes and the database plans')
        for label, result in (('changes', changes), *((f'db {e}', db[e]) for e in ('pgsql', 'mysql'))):
            if result.get('exit') not in (0, 2) or result.get('errors') or result.get('status') in ('error', 'invalid_map', 'invalid_request'):
                raise ValueError(f'selector plan [{label}] is unusable: status={result.get("status")} exit={result.get("exit")} errors={result.get("errors")}')
        suites = _suites(changes)
        db_suites = {engine: _suites(db[engine]) for engine in ('pgsql', 'mysql')}
        if changes.get('status') in ('docs_only', 'no_changes'):
            skip_reason = 'docs-only'
        elif suites <= {'load'}:
            skip_reason = 'stand-only'  # the load stand is never a PR lane
    else:
        raise ValueError(f'unknown mode {mode!r}')

    affected = [pkg for pkg in php if pkg in suites]
    quality = [pkg for pkg in affected]
    if (quality or 'repo' in suites) and 'core' not in quality and 'core' in support:
        quality.insert(0, 'core')  # Root PHPStan and Pint enforce the shared rules against every PHP package.

    def package(pkg: str) -> dict:
        return {'package': pkg, 'dir': support[pkg]['dir'], 'azguard': support[pkg]['azguard']}

    fast = [{**package(pkg), 'laravel': f'{line}.*', 'testbench': LARAVEL_TO_TESTBENCH[line], 'types': support[pkg]['types']}
            for pkg in affected for line in support[pkg]['lines']]
    quality_matrix = [{**package(pkg), 'rector': support[pkg]['rector']} for pkg in quality]
    db_matrix = [{**package(pkg), 'engine': engine, 'port': '5432' if engine == 'pgsql' else '3306',
                  'username': 'vaulter' if engine == 'pgsql' else 'root', 'version': version}
                 for engine, version in (('pgsql', '18.7'), ('pgsql', '19.0'), ('mysql', '9.7')) for pkg in php if pkg in db_suites[engine] and support[pkg]['database']]
    serial = [{**package(pkg), 'version': version}
              for version in ('18.7', '19.0') for pkg in affected if support[pkg]['serial']]
    # A change to a consumer package re-checks it alone; a harness change (scripts/release, the manifest, CI) re-checks all.
    harness = 'repo' in suites
    consumer = [package(pkg) for pkg in CONSUMER_PACKAGES if pkg in support and (pkg in affected or harness)]

    flags = {
        'quality': bool(quality_matrix), 'fast': bool(fast), 'db': bool(db_matrix), 'serial': bool(serial),
        'consumer': bool(consumer),
        'vue': 'vue' in suites, 'repo': 'repo' in suites,
        'openapi': skip_reason == '', 'contract_bc': skip_reason == '' and bc_provisioned,
    }
    out = {'skip_reason': skip_reason, 'mode': mode}
    out['contract_bc_provisioned'] = _bool(bc_provisioned)
    out['contract_bc_required_lines'] = json.dumps(list(REQUIRED_BC_LINES), separators=(',', ':'))
    out['contract_bc_selected_lines'] = json.dumps(list(REQUIRED_BC_LINES) if flags['contract_bc'] else [], separators=(',', ':'))
    for lane, value in flags.items():
        out[f'has_{lane}'] = _bool(value and skip_reason == '')
    for lane, rows in (('quality', quality_matrix), ('fast', fast), ('db', db_matrix), ('serial', serial), ('consumer', consumer)):
        out[f'{lane}_matrix'] = json.dumps({'include': rows if skip_reason == '' else []}, separators=(',', ':'))
    return out


# --- gate ----------------------------------------------------------------------------------------------------------

def gate_errors(needs: dict) -> list[str]:
    """The verdict of ci-gate. `needs` is toJSON(needs): {job: {result, outputs}}. Empty list = green."""
    errors: list[str] = []
    changes = needs.get('changes') or {}
    if changes.get('result') != 'success':
        return [f'changes: {changes.get("result", "missing")} (nothing can be trusted without it)']
    if (needs.get('sca') or {}).get('result') != 'success':
        errors.append('sca: required advisory gate is ' + (needs.get('sca') or {}).get('result', 'missing'))
    outputs = changes.get('outputs') or {}
    skip_reason = outputs.get('skip_reason', '')
    expected = {lane: outputs.get(f'has_{lane}') == 'true' for lane in LANES}
    # Only an explicit `false` from the plan excuses the contract gate: a missing output still requires it.
    bc_provisioned = outputs.get('contract_bc_provisioned') != 'false'
    if not skip_reason and any(expected.values()) and not expected['contract_bc'] and bc_provisioned:
        errors.append('contract_bc: every active CI plan requires the required contract gate')
    if expected['contract_bc']:
        for field in ('contract_bc_required_lines', 'contract_bc_selected_lines'):
            try:
                value = json.loads(outputs.get(field, 'null'))
            except (TypeError, ValueError):
                value = None
            if not complete_bc_lines(value):
                errors.append(f'contract_bc: {field} must include both PHP/npm release lines')
    if skip_reason and any(expected.values()):
        errors.append(f'changes: skip_reason {skip_reason!r} yet lanes are expected: {[lane for lane, on in expected.items() if on]}')
    if not skip_reason and not any(expected.values()):
        errors.append('changes: no lane expected and no explicit skip reason (an empty plan is not a pass)')
    for lane in ('quality', 'fast', 'db', 'serial', 'consumer'):
        try:
            rows = len(json.loads(outputs.get(f'{lane}_matrix') or '{"include":[]}').get('include', []))
        except ValueError:
            errors.append(f'{lane}: matrix output is not JSON')
            continue
        if expected[lane] and rows == 0:
            errors.append(f'{lane}: required lane has an empty matrix')
        if not expected[lane] and rows:
            errors.append(f'{lane}: matrix has {rows} rows but the lane is not expected')
    for lane in LANES:
        result = (needs.get(lane_job(lane)) or {}).get('result', 'missing')
        if expected[lane] and result != 'success':
            errors.append(f'{lane}: required lane is {result}')
        elif not expected[lane] and result not in ('skipped', 'success'):
            errors.append(f'{lane}: {result}')
    return errors


def bc_host_provisioned(env: Mapping[str, str]) -> bool:
    """Whether the owner provisioned the trusted BC host: any BC_HOST_* variable set. A partial setup still counts, so
    contract-bc runs and fails on what is missing instead of being skipped."""
    return any(env.get(name, '').strip() for name in BC_HOST_VARIABLES)


def main_plan(argv: list[str]) -> int:
    options = dict(zip(argv[::2], argv[1::2]))
    mode = options.get('--mode', 'changed')
    support = json.loads(os.environ['PHP_SUPPORT'])
    bc_provisioned = bc_host_provisioned(os.environ)

    def read(flag: str) -> dict:
        return json.loads(Path(options[flag]).read_text(encoding='utf-8'))

    changes = read('--changes') if mode == 'changed' else None
    db = {'pgsql': read('--db-pgsql'), 'mysql': read('--db-mysql')} if mode == 'changed' else None
    try:
        outputs = plan_outputs(support, mode, changes, db, bc_provisioned)
    except ValueError as exc:
        print(f'plan: {exc}', file=sys.stderr)
        return 1
    for key, value in outputs.items():
        print(f'{key}={value}')
    return 0


def main_gate() -> int:
    errors = gate_errors(json.loads(os.environ['NEEDS']))
    for error in errors:
        print(f'FAIL {error}', file=sys.stderr)
    if errors:
        return 1
    outputs = (json.loads(os.environ['NEEDS']).get('changes') or {}).get('outputs', {})
    reason = outputs.get('skip_reason', '')
    if not reason and outputs.get('contract_bc_provisioned') == 'false':
        print('::notice::contract-bc skipped: the trusted BC host (BC_HOST_*) is not provisioned on this repository')
    print(f'ci gate OK{f" (intended skip: {reason})" if reason else ""}')
    return 0


def main(argv: list[str]) -> int:
    if len(argv) > 1 and argv[1] == 'plan':
        return main_plan(argv[2:])
    if len(argv) > 1 and argv[1] == 'gate':
        return main_gate()
    root = Path(argv[1]) if len(argv) > 1 else Path(__file__).resolve().parents[2] / '.github' / 'workflows'
    errors = check_dir(root)
    for error in errors:
        print(f'FAIL {error}', file=sys.stderr)
    if errors:
        return 1
    print(f'workflow policy OK ({len(list(root.glob("*.yml")))} workflows)')
    return 0


if __name__ == '__main__':
    sys.exit(main(sys.argv))
