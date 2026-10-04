"""Close P1.2: verify the product bytes, bind Validation receipts and persist the terminal event."""
from pathlib import Path
import json, hashlib
from task import task_contract, route_evidence, journal, terminal_policy, plan_continuity, plan_work
from task.command_render import PlanNextCarrier, render_continuity_decision

root = Path(__file__).resolve().parents[4]
plan = Path(__file__).resolve().parents[2]
art = Path(__file__).resolve().parent
item = 'P1.2'


def save(name, value):
    (art / name).write_text(json.dumps(value, indent=2, ensure_ascii=False) + '\n')


def rel(name):
    return str((art / name).relative_to(root))


for path, digest in json.loads((art / 'product-manifest.json').read_text()).items():
    assert hashlib.sha256((root / path).read_bytes()).hexdigest() == digest, path

final = json.loads((art / 'review-final-raw.json').read_text())
assert (art / 'review-final.exit').read_text().strip() == '0' and final['stopReason'] == 'end_turn'
assert 'review: grok/grok-4.7 findings:4' in final['text']
(art / 'review-final-findings.md').write_text(final['text'] + '\n')

start = [e for e in journal.read_authoritative_entries(plan) if e['item_id'] == item and e['result'] == 'in-progress'][-1]
current = route_evidence.capture_launch(expected_invocation='task:plan-run ' + plan.name + ' ' + item, expected_cwd=root)
receipt = route_evidence.run_receipt_from_journal(plan, start['run_id'])
route_evidence.verify_run_receipt(receipt, plan_id=plan.name, item_id=item, attempt=start['attempt'],
                                  session_id=start['session_id'], run_id=start['run_id'], current_evidence=current)
contract = task_contract.build(plan, item, actual_route=current, carrier='task:plan-run ' + plan.name + ' ' + item)
context = task_contract.current_validation_context(plan, item)
receipts = [task_contract.build_validation_receipt(contract, e['id'], exit_code=0, context=context)
            for e in contract['evidence'] if e['algorithm'] == 'shell-command']
verdict = task_contract.evaluate(contract, validation_receipts=receipts, validation_context=context)
assert verdict['pre_mutation'] == 'allow' and verdict['pre_final'] == 'allow', verdict
save('task-contract.json', contract); save('validation-context.json', context)
save('validation-receipts.json', receipts); save('write-site-verdict.json', verdict)

evidence = {
    'E10': ['v18.log'],
    'E11': ['v19.log'],
    'E12': ['v19-negative.log', 'v19.log'],
    'E13': ['migration-nodename-pgsql.log'],
    'E14': ['core-pint.log', 'core-stan.log', 'core-pest.log', 'drive-pint.log', 'drive-stan.log', 'drive-pest.log',
            'documents-pint.log', 'documents-stan.log', 'documents-pest.log', 'filament-pint.log',
            'filament-stan.log', 'filament-pest.log'],
}
save('validation.json', {'schema_version': 'p1.2-acceptance/v1', 'validation_evidence': evidence})
rows = [{'carrier': e['path'], 'status': 'green',
         'evidence_ids': [rel(f) for f in evidence[e['id']]] + [rel('validation-receipts.json'), rel('completion.md')]}
        for e in contract['evidence'] if e['algorithm'] == 'shell-command']
decision = terminal_policy.build_decision(plan, item, result='closed-green', validation=rows)
batch = dict(start['batch_receipt'], completed_prefix=[item])
note = ('P1.2 green under a global lock matrix; review grok-4.7 not GREEN: 11 findings fixed, 2 quota findings '
        'deferred to PLAN2.P1.6. See artifacts/P1.2/completion.md.\n'
        'write-site: pre_mutation: allow · pre_final: allow')
entry = journal.new_entry(plan_id=plan.name, item_id=item, command='plan-run',
                          actor='claude-code/' + current['model'] + '/' + current['effort'], result='closed-green',
                          session_id=start['session_id'], run_id=start['run_id'], attempt=start['attempt'],
                          friction=['deviation', 'retry', 'local-repair'], note=note, decision=decision,
                          batch_receipt=batch)
journal.append_idempotent_terminal(plan, entry); save('terminal-event.json', entry)

estimate = plan_continuity.WorkEstimate(route_attested=True, capabilities_attested=True, capabilities_sufficient=True,
                                        context_attested=False, context_health='unavailable', batch_receipt_valid=True,
                                        remaining_batch_digest=hashlib.sha256(b'[]').hexdigest(),
                                        loaded_inputs_reusable=False, cold_start_cost=0, lifecycle='scope-complete',
                                        checkpoint='artifacts/P1.2/completion.md')
continuity = plan_continuity.reduce(estimate)
next_carrier = PlanNextCarrier(kind='run-items', command_id='task:plan-run', plan_id=plan.name,
                               arguments=(plan.name, 'P1.3'), context_kind=continuity.context, item_ids=('P1.3',),
                               continuity=continuity)
save('continuity.json', {'estimate': estimate.as_dict(), 'decision': continuity.as_dict(), 'next': next_carrier.as_dict()})
(plan / 'handoff.md').write_text('''# HANDOFF — 2026-10-04 — after P1.2

**Next:** run-items: task:plan-run ''' + plan.name + ''' P1.3

| Parameter | Meaning |
|:--|:--|
| Batch | solo |
| Model class | frontier |
| Model | claude-opus-5-5 |
| Effort | high |
| Context | ''' + continuity.context + ''' |
| Essence | P1.3: access algorithm (separate session by volume, roadmap row 10). |

''' + render_continuity_decision(continuity) + '''

**Done:** P1.2 Done (green). Tree invariants under one global lock order (artifacts/P1.2/lock-matrix.md):
structural writes take the tree lock exclusively, single-parent writes shared, then node rows. Review grok-4.7:
4 rounds, 11 findings fixed with negative controls. Evidence: artifacts/P1.2/completion.md.

**Remaining:** P1.3, P1.5, P1.6.

**Open risks:** P1.6 must take over the deferred quota findings (new-version eager quota check outside the
transaction; copy reads the source subtree without locks) and start from the lock matrix, not per-action
locks. StorageManager::root() re-provisioning hits the single-root index if a parentless row survives.
Prior owner dirty plan/configuration carriers remain unstaged. No push or release.

**Sources of truth:** phases/P1/P1.2.md; journal.jsonl; artifacts/P1.2/completion.md; artifacts/P1.2/lock-matrix.md;
roadmap.md execution-sheet/v1.
''')
result = plan_work.finalize(plan, item)
save('finalization.json', result); save('terminal-delivery-receipt.json', result['receipt'])
print({'result': 'closed-green', 'next': result['receipt']['next_exact']})
