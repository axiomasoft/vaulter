#!/usr/bin/env python3
"""CI workflow policy (PLAN3 P1.2, D3/D4).

Checks `.github/workflows/*.yml` as parsed YAML, never as text, so a comment that
mentions `--mutate` or `pull_request` is not a violation:

* mutation testing (`--mutate` in a step command) lives only in mutation.yml, which is
  `workflow_dispatch`-only with a package choice and keeps its `--min` floors;
* load.yml is `workflow_dispatch`-only, and no job `if` waits for an event the workflow
  no longer has (the old-PR trap: such a job is skipped for ever);
* no workflow with a pull_request trigger collects coverage;
* every job has `timeout-minutes`;
* only PR testing workflows cancel in-progress runs; publishing workflows never do.

PLAN3 P1.6 adds the single-CI contract:

* ci.yml is the one PR workflow (push/pull_request/workflow_dispatch, no path filter) and its `ci-gate` job needs every
  other job; the per-package test workflows no longer exist;
* ci.yml and weekly-full.yml carry the actual PHP support matrix in `env.PHP_SUPPORT`, which must equal what the
  composer.json files of the packages declare (Laravel 12 / Testbench 10, Laravel 13 / Testbench 11);
* weekly-full.yml runs on schedule `0 4 * * 1`, by hand and as a reusable workflow; monorepo-split.yml qualifies the
  exact tagged SHA through it and through `CI gate` before anything is published, and never polls for a green run.

PLAN3 P4.19 adds the I14 lane: `consumer-isolation` (a matrix of core, media, drive, documents, webhooks from the planned
`consumer_matrix`) runs scripts/release/consumer-check.sh for real and blocks - `ci-gate` needs it like every other job.

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
import sys
from pathlib import Path

MUTATION_PACKAGES = ['core', 'media', 'drive', 'documents', 'filament']
MUTATION_FLOORS = {
    'core': ['--min=95'],
    'media': ['--min=0', ' 95'],
    'drive': ['--min=43'],
    'documents': ['--min=95'],
    'filament': ['--min=95'],
}
COVERAGE_MARKERS = ('--coverage', 'codecov', '--coverage-clover')

# PLAN3 P1.6: the single CI.
PHP_PACKAGES = ['core', 'media', 'drive', 'documents', 'webhooks', 'azgard', 'filament']
TESTBENCH_TO_LARAVEL = {'10': '12', '11': '13'}  # the Testbench major that supports each Laravel major
LARAVEL_TO_TESTBENCH = {'12': '^10.0', '13': '^11.0'}
REMOVED_WORKFLOWS = [
    'tests.yml', 'drive.yml', 'media.yml', 'documents.yml', 'filament.yml', 'azgard.yml',
    'static-analysis.yml', 'code-style.yml', 'frontend.yml',
]
GATE_JOB = 'ci-gate'
GATE_CHECK_NAME = 'CI gate'
LANES = ['quality', 'fast', 'db', 'serial', 'consumer', 'vue', 'hub', 'repo', 'openapi']
# PLAN3 P4.19 (I14): packages whose split archive must stand alone. azgard is the external gate (D20), filament has its
# own composer check. A lane that is not named like its job maps here (job id -> `has_<lane>` / `<lane>_matrix` outputs).
CONSUMER_PACKAGES = ['core', 'media', 'drive', 'documents', 'webhooks']
LANE_JOBS = {'consumer': 'consumer-isolation'}


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
    commands = step_commands(doc)

    # D3: mutation only in mutation.yml, only by hand.
    if name != 'mutation.yml' and any('--mutate' in c for c in commands):
        errors.append(f'{name}: --mutate outside mutation.yml')
    if name == 'mutation.yml':
        errors += check_mutation(doc, events)

    # D4: load only by hand.
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


def check_mutation(doc: dict, events: dict) -> list[str]:
    errors: list[str] = []
    if set(events) != {'workflow_dispatch'}:
        errors.append(f'mutation.yml: triggers must be exactly workflow_dispatch, got {sorted(events)}')
    package = ((events.get('workflow_dispatch') or {}).get('inputs') or {}).get('package') or {}
    if package.get('type') != 'choice' or list(package.get('options') or []) != MUTATION_PACKAGES:
        errors.append(f'mutation.yml: inputs.package must be a choice of {MUTATION_PACKAGES}')
    jobs = doc.get('jobs') or {}
    for pkg in MUTATION_PACKAGES:
        job = jobs.get(pkg)
        if job is None:
            errors.append(f'mutation.yml: no job for package {pkg}')
            continue
        if f"inputs.package == '{pkg}'" not in str(job.get('if', '')):
            errors.append(f'mutation.yml: job {pkg} is not gated by inputs.package')
        runs = ' '.join(str(s.get('run', '')) for s in job.get('steps') or [])
        for floor in MUTATION_FLOORS[pkg]:
            if floor not in runs:
                errors.append(f'mutation.yml: job {pkg} lost its floor {floor.strip()!r}')
        if '--parallel' in runs:
            errors.append(f'mutation.yml: job {pkg} runs Pest --mutate in parallel (forbidden)')
    extra = set(jobs) - set(MUTATION_PACKAGES)
    if extra:
        errors.append(f'mutation.yml: unexpected jobs {sorted(extra)}')
    return errors


def derive_support(root: Path) -> dict:
    """The actual PHP support matrix, read from composer.json / groups.php of every package."""
    support: dict = {}
    for pkg in PHP_PACKAGES:
        directory = '.' if pkg == 'core' else f'packages/{pkg}'
        composer = json.loads((root / 'packages' / pkg / 'composer.json').read_text(encoding='utf-8'))
        dev = composer.get('require-dev') or {}
        testbench = str(dev.get('orchestra/testbench', ''))
        lines = sorted({TESTBENCH_TO_LARAVEL[m] for m in re.findall(r'\^(\d+)', testbench) if m in TESTBENCH_TO_LARAVEL})
        # core runs from the repository root, whose composer.json owns the type-coverage plugin and rector.
        tooling = json.loads((root / 'composer.json').read_text(encoding='utf-8')).get('require-dev', {}) if pkg == 'core' else dev
        groups = root / 'packages' / pkg / 'tests' / 'groups.php'
        text = re.sub(r'//[^\n]*|/\*.*?\*/', '', groups.read_text(encoding='utf-8') if groups.exists() else '', flags=re.S)
        serial = re.search(r"'serial'\s*=>\s*\[(.*?)\]\s*,", text, re.S)
        support[pkg] = {
            'dir': directory,
            'lines': lines,
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
    errors += check_support(name, doc, root)
    return errors


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
    split_needs = (jobs.get('split') or {}).get('needs')
    if split_needs != 'verify-release-sha' and split_needs != ['verify-release-sha']:
        errors.append(f'{name}: split must need verify-release-sha')
    return errors


def check_dir(directory: Path) -> list[str]:
    errors: list[str] = []
    root = directory.resolve().parents[1]
    files = sorted(directory.glob('*.yml'))
    names = {f.name for f in files}
    for required in ('mutation.yml', 'load.yml', 'ci.yml', 'weekly-full.yml', 'monorepo-split.yml'):
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
        if path.name == 'ci.yml':
            errors += check_ci(doc, root)
        elif path.name == 'weekly-full.yml':
            errors += check_weekly(doc, root)
        elif path.name == 'monorepo-split.yml':
            errors += check_split(doc)
    return errors


# --- plan: `scripts/t --changed --dry-run --json` -> job outputs -------------------------------------------------

def _suites(plan: dict) -> set[str]:
    return {run['suite'] for run in plan.get('runs') or []}


def _bool(value: bool) -> str:
    return 'true' if value else 'false'


def plan_outputs(support: dict, mode: str, changes: dict | None = None, db: dict | None = None) -> dict[str, str]:
    """Matrices and lane flags of ci.yml. `all` (no usable base commit) is every default lane; `changed` follows the selector."""
    php = [pkg for pkg in PHP_PACKAGES if pkg in support]
    skip_reason = ''
    if mode == 'all':
        suites = set(php) | {'repo', 'vue', 'hub'}
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
            skip_reason = 'stand-only'  # the load stand is never a PR lane (D4)
    else:
        raise ValueError(f'unknown mode {mode!r}')

    affected = [pkg for pkg in php if pkg in suites]
    quality = [pkg for pkg in affected]
    if 'repo' in suites and 'core' not in quality and 'core' in support:
        quality.insert(0, 'core')  # core's quality is the repository-wide Pint (+ core Larastan), so a harness change needs it

    def package(pkg: str) -> dict:
        return {'package': pkg, 'dir': support[pkg]['dir'], 'azguard': support[pkg]['azguard']}

    fast = [{**package(pkg), 'laravel': f'{line}.*', 'testbench': LARAVEL_TO_TESTBENCH[line], 'types': support[pkg]['types']}
            for pkg in affected for line in support[pkg]['lines']]
    quality_matrix = [{**package(pkg), 'rector': support[pkg]['rector']} for pkg in quality]
    db_matrix = [{**package(pkg), 'engine': engine, 'port': '5432' if engine == 'pgsql' else '3306',
                  'username': 'vaulter' if engine == 'pgsql' else 'root'}
                 for engine in ('pgsql', 'mysql') for pkg in php if pkg in db_suites[engine]]
    serial = [package(pkg) for pkg in affected if support[pkg]['serial']]
    # I14: a change to a consumer package re-checks it alone; a harness change (scripts/release, the manifest, CI) re-checks all.
    harness = 'repo' in suites
    consumer = [package(pkg) for pkg in CONSUMER_PACKAGES if pkg in support and (pkg in affected or harness)]

    flags = {
        'quality': bool(quality_matrix), 'fast': bool(fast), 'db': bool(db_matrix), 'serial': bool(serial),
        'consumer': bool(consumer),
        'vue': 'vue' in suites, 'hub': 'hub' in suites, 'repo': 'repo' in suites,
        'openapi': skip_reason == '',
    }
    out = {'skip_reason': skip_reason, 'mode': mode}
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
    outputs = changes.get('outputs') or {}
    skip_reason = outputs.get('skip_reason', '')
    expected = {lane: outputs.get(f'has_{lane}') == 'true' for lane in LANES}
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


def main_plan(argv: list[str]) -> int:
    options = dict(zip(argv[::2], argv[1::2]))
    mode = options.get('--mode', 'changed')
    support = json.loads(os.environ['PHP_SUPPORT'])

    def read(flag: str) -> dict:
        return json.loads(Path(options[flag]).read_text(encoding='utf-8'))

    changes = read('--changes') if mode == 'changed' else None
    db = {'pgsql': read('--db-pgsql'), 'mysql': read('--db-mysql')} if mode == 'changed' else None
    try:
        outputs = plan_outputs(support, mode, changes, db)
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
    reason = (json.loads(os.environ['NEEDS']).get('changes') or {}).get('outputs', {}).get('skip_reason', '')
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
