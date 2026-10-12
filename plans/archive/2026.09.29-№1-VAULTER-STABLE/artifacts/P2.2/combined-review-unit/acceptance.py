#!/usr/bin/env python3
"""Read-only qualification of actual combined-workset evidence.

Behavioral checks execute once in the native DAG. These acceptance carriers
verify their authentic process receipts and current inputs at the close boundary.
"""
import argparse
import datetime as dt
import hashlib
import importlib.util
import json
import re
import subprocess
import sys
from pathlib import Path

TASK = Path('/home/vostrikov/projects/packages/swissknifeman/packages/task')
sys.path[:0] = [str(TASK / 'lib'), str(TASK.parent.parent)]
from task import (plan_bundle, plan_decisions, plan_item_state, plan_layout,
                  plan_recovery, plan_status, plan_weight, review_packet)


def need(value, reason):
    if not value:
        raise ValueError(reason)


def read(path):
    return json.loads(Path(path).read_text())


def sha(path):
    return hashlib.sha256(Path(path).read_bytes()).hexdigest()


def located(root, value):
    path = Path(value)
    if not path.is_absolute():
        path = root / path
    need(path.resolve().is_relative_to(root.resolve()), 'evidence outside repository: ' + str(path))
    return path


def pest_counts(log):
    plain = re.sub(r'\x1b\[[0-9;]*m', '', Path(log).read_text())
    counts = None
    for line in plain.splitlines():
        try:
            value = json.loads(line)
        except ValueError:
            continue
        if isinstance(value, dict) and value.get('tool') == 'pest':
            counts = {**value, 'failed': value.get('failed', 0), 'skipped': value.get('skipped', 0)}
    match = re.search(r'Tests:\s+([^\n]+)', plain)
    if match:
        counts = {'failed': 0, 'skipped': 0, **{key: int(number) for number, key in
                  re.findall(r'(\d+) (passed|failed|skipped|risky|warnings|incomplete)', match.group(1))}}
    need(isinstance(counts, dict), 'actual Pest counts missing: ' + str(log))
    return counts


def behavioral(root, base, config):
    summary = read(base / 'summary.json')
    need(summary.get('review_workset') == ['P2.2', 'P2.3'], 'behavioral evidence is not the complete workset')
    need(all(summary.get(name) is True for name in ['candidate_fresh', 'affected_complete', 'full_complete']),
         'behavioral validation incomplete/stale')
    need(summary.get('failed_gates') == 0 and summary.get('dependency_skipped_gates') == [],
         'failed/skipped behavioral gates')
    paths = read(base / 'candidate-paths.json')
    # This is an already expanded inventory. Preserve deleted members after the
    # producer commit instead of expanding them against the changed HEAD again.
    need(isinstance(paths, list) and all(isinstance(value, str) for value in paths), 'invalid concrete candidate inventory')
    members = review_packet._members(root, paths)
    digest = hashlib.sha256(review_packet._json(members)).hexdigest()
    need(digest == summary['candidate_sha256'], 'current behavioral candidate differs')
    structural = read(located(root, summary['structural_binding_proof']))
    need(structural.get('issues') == [] and structural.get('tools_and_checks_bound') is True
         and structural.get('candidate_sha256') == digest, 'structural binding incomplete/stale')
    for value, expected in read(base / 'tools/external-tools.json').items():
        need(sha(value) == expected, 'external runtime changed: ' + value)
    runtime_spec = importlib.util.spec_from_file_location('combined_acceptance_runtime', base / 'tools/common.py')
    runtime_module = importlib.util.module_from_spec(runtime_spec)
    runtime_spec.loader.exec_module(runtime_module)
    runtime_module.verify_runtime(base, content=True)
    generation = read(base / 'generation-binding.json')
    for value, expected in generation['evidence_hashes'].items():
        need(sha(located(root, value)) == expected, 'generation evidence changed: ' + value)
    for group in ['source_hashes', 'golden_hashes']:
        for value, expected in generation[group].items():
            need(sha(located(root, value)) == expected, 'generation input/output changed: ' + value)
    observed = {}
    receipts = []
    now = dt.datetime.now(dt.timezone.utc)
    for stage in ['affected', 'full']:
        path = located(root, summary['receipts'][stage])
        receipt = read(path)
        need(receipt.get('complete') is True and receipt.get('candidate_fresh') is True
             and receipt.get('candidate_sha256') == digest and receipt.get('candidate') == members,
             stage + ' native receipt incomplete/stale')
        checks = read(base / (stage + '-checks.json'))
        need(receipt.get('checks') == checks, stage + ' native check declarations differ')
        need(set(receipt['results']) == {check['id'] for check in checks}, stage + ' native result coverage differs')
        receipts.append(str(path))
        for check in checks:
            name = check['id']
            result = receipt['results'][name]
            need(result.get('exit_code') == 0 and result.get('status') == 'green', 'actual gate not GREEN: ' + name)
            row = read(base / 'observed' / (stage + '-' + name + '.json'))
            need(row.get('id') == name and row.get('stage') == stage and row.get('exit_code') == 0
                 and row.get('candidate_sha256') == digest and row.get('runtime_verified_before_after') is True,
                 'actual process not candidate-bound: ' + name)
            need(check['argv'] == ['python3', str(base / 'tools/timed-run.py'), str(base), stage, name, *row['argv']],
                 'actual process argv differs: ' + name)
            start = dt.datetime.fromisoformat(row['started_at'].replace('Z', '+00:00'))
            finish = dt.datetime.fromisoformat(row['finished_at'].replace('Z', '+00:00'))
            need(start <= finish <= now, 'invalid actual timestamps: ' + name)
            need(name not in observed, 'duplicate actual gate: ' + name)
            observed[name] = {'process': row, 'log': path.parent / (name + '.log')}
    need(summary.get('green_gates') == len(observed), 'summary gate count differs')
    for package in ['core', 'drive', 'documents', 'filament']:
        for tool in ['pint', 'phpstan', 'pest-sqlite']:
            need(package + '-' + tool in observed, 'full package gate missing: ' + package + '-' + tool)
        counts = pest_counts(observed[package + '-pest-sqlite']['log'])
        need(counts.get('failed') == 0, 'full package Pest failure: ' + package)
    for name, passed in [('core-p1-pg', 40), ('drive-p1-pg', 174)]:
        need(name in observed, 'exact PG matrix missing: ' + name)
        counts = pest_counts(observed[name]['log'])
        need(counts.get('passed') == passed and counts.get('failed') == 0 and counts.get('skipped') == 0,
             'exact PG matrix counts invalid: ' + name)
        need(summary['counts'][name].get('passed') == passed and summary['counts'][name].get('failed') == 0
             and summary['counts'][name].get('skipped') == 0, 'summary PG counts differ: ' + name)
    for name in ['pg-readiness', 'pg-exact-target']:
        need(name in observed, 'isolated PG qualification missing: ' + name)
    for name, expected in config['original_specs'].items():
        need(sha(Path(__file__).parent / name) == expected, 'original acceptance changed: ' + name)
    return {'candidate_sha256': digest, 'native_receipts': receipts, 'gate_count': len(observed)}, observed


def green(plan, root, config, *, committed=False):
    item = config['producer_item']
    if committed:
        from task import terminal_policy
        event = plan_item_state.material_terminal_evidence(plan, item)
        need(event['result'] == 'closed-green', 'combined producer not closed GREEN')
        terminal_policy.validate_predecessor(event['decision'], result=event['result'], plan_dir=plan, item_id=item)
        receipt = review_packet.require_committed_green(plan, item, config['reviewer'],
                  effort=config['review_effort'], decision=event['decision'], result=event['result'])
    else:
        receipt = review_packet.require_green(plan, item, config['reviewer'], effort=config['review_effort'])
    manifest = receipt['manifest']
    need(review_packet._members(root, manifest['paths']) == manifest['snapshot']['product'],
         'current product differs from authentic entire-workset review')
    need(config['consumer_item'] not in {manifest['item_id']}, 'producer review relabeled as consumer')
    need(review_packet.review_request(plan, config['consumer_item']) is None, 'separate P2.3 reviewer request forbidden by D21')
    return {'candidate_sha256': manifest['candidate_sha256'], 'reviewer_session': receipt['reviewer_session'],
            'manifest_item': manifest['item_id'], 'committed_producer': committed}


def main():
    cli = argparse.ArgumentParser(description=__doc__)
    cli.add_argument('mode', choices=['code-guidance', 'packages-pg', 'drive-arch', 'documents-arch',
                     'combined-review', 'producer-delivery', 'freshness'])
    args = cli.parse_args()
    config = read(Path(__file__).with_name('acceptance-config.json'))
    plan = Path(__file__).resolve().parents[3]
    need(plan.name == config['plan_id'], 'acceptance script installed outside declared plan')
    root = plan_weight.repo_root_for(plan).resolve()
    base = located(root, config['validation_dir'])
    proof, observed = behavioral(root, base, config)
    if args.mode == 'code-guidance':
        for name in config['code_guidance_gates']:
            need(name in observed, 'original Code Guidance actual gate missing: ' + name)
    elif args.mode in ['drive-arch', 'documents-arch']:
        name = args.mode.removesuffix('-arch') + '-affected'
        need(name in observed, 'Arch/Providers actual gate missing: ' + name)
        argv = observed[name]['process']['argv']
        need('tests/Arch' in argv and 'tests/Providers' in argv, 'actual gate omitted Arch/Providers: ' + name)
        counts = pest_counts(observed[name]['log'])
        need(counts.get('failed') == 0 and counts.get('passed', 0) > 0, 'Arch/Providers actual counts invalid: ' + name)
    elif args.mode in ['combined-review', 'producer-delivery', 'freshness']:
        proof['owner_review'] = green(plan, root, config, committed=args.mode != 'combined-review')
        if args.mode == 'freshness':
            item = config['consumer_item']
            need(plan_recovery.inspect(plan, item)['allowed'], 'consumer recovery not admitted')
            plan_item_state.build_states(plan, item_id=item, check=True, materialize_successor=False)
            plan_decisions.build_views(plan, check=True)
            plan_status.build_status(plan, check=True)
            plan_bundle.build(plan, item, check=True)
            check = subprocess.run([sys.executable, str(TASK / 'scripts/plan-lint.py'), str(plan), '--item', item],
                                   capture_output=True, text=True)
            need(check.returncode == 0, 'mandatory selected lint failed: ' + check.stdout + check.stderr)
    print(json.dumps({'mode': args.mode, 'status': 'GREEN', 'proof': proof}, ensure_ascii=False))


if __name__ == '__main__':
    try:
        main()
    except (OSError, ValueError, KeyError, TypeError, StopIteration) as error:
        print(json.dumps({'status': 'FAILED', 'error': getattr(error, 'code', type(error).__name__),
                          'detail': str(error)}, ensure_ascii=False), file=sys.stderr)
        sys.exit(1)
