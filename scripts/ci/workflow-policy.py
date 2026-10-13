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

Usage: workflow-policy.py [workflows-dir]    exit 0 = policy holds, 1 = violations.
"""

from __future__ import annotations

import re
import sys
from pathlib import Path

import yaml

MUTATION_PACKAGES = ['core', 'media', 'drive', 'documents', 'filament']
MUTATION_FLOORS = {
    'core': ['--min=95'],
    'media': ['--min=0', ' 95'],
    'drive': ['--min=43'],
    'documents': ['--min=95'],
    'filament': ['--min=95'],
}
COVERAGE_MARKERS = ('--coverage', 'codecov', '--coverage-clover')


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

    # Every job is bounded.
    for job_id, job in jobs.items():
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


def check_dir(directory: Path) -> list[str]:
    errors: list[str] = []
    files = sorted(directory.glob('*.yml'))
    names = {f.name for f in files}
    for required in ('mutation.yml', 'load.yml'):
        if required not in names:
            errors.append(f'{required}: missing')
    for path in files:
        try:
            doc = load_workflow(path)
        except (yaml.YAMLError, ValueError) as exc:
            errors.append(f'{path.name}: unparsable ({exc})')
            continue
        errors += check_workflow(path.name, doc)
    return errors


def main(argv: list[str]) -> int:
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
