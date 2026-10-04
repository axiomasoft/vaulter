"""Persist P1.8 acceptance through Task owners after the independent Grok verdict."""
import hashlib
import json
from datetime import datetime, timezone
from pathlib import Path
from task import task_contract, terminal_policy, journal, plan_continuity, command_render

root = Path(__file__).resolve().parents[2]
out = Path(__file__).resolve().parent
repo = root.parents[1]
start = next(json.loads(x) for x in (root/'journal.jsonl').read_text().splitlines()
             if json.loads(x).get('item_id') == 'P1.8' and json.loads(x)['result'] == 'in-progress')
review = (out/'grok-review.md').read_text()
assert 'GREEN' in review and 'findings:' not in review, 'A GREEN Grok 4.7 verdict is required'
assert json.loads((out/'review-exit.json').read_text())['exit'] == 0
assert all(hashlib.sha256((repo/f).read_bytes()).hexdigest() == digest
           for f,digest in json.loads((out/'review-candidate.json').read_text()).items())
checks = json.loads((out/'validation-current.json').read_text())
assert len(checks) == 15 and all(x['exit'] == 0 for x in checks)
assert all(x['passed'] for x in json.loads((out/'negative-controls.json').read_text()))
assert json.loads((out/'code-guidance.json').read_text())['matches'] == 0
route = start['decision']['route_receipt']['evidence']
contract = task_contract.build(root, 'P1.8', actual_route=route)
context = task_contract.current_validation_context(root, 'P1.8')
receipts = [task_contract.build_validation_receipt(contract, x['id'], exit_code=0, context=context)
            for x in contract['evidence'] if x.get('algorithm') == 'shell-command']
verdict = task_contract.evaluate(contract, validation_receipts=receipts, validation_context=context)
assert verdict['pre_mutation'] == verdict['pre_final'] == 'allow', verdict
for name,data in [('task-contract-final',contract),('task-validation-context',context),('task-validation-receipts',receipts),('task-contract-verdict',verdict)]:
    (out/(name+'.json')).write_text(json.dumps(data,ensure_ascii=False,indent=2)+'\n')
evidence = {
 'E7':['artifacts/P1.8/v24-restored.log'],
 'E8':['artifacts/P1.8/negative-controls.json'],
 'E9':['artifacts/P1.8/code-guidance.json'],
 'E10':['artifacts/P1.8/validation-current.json'],
}
validation = [dict(carrier=x['path'], status='green', evidence_ids=evidence[x['id']])
              for x in contract['evidence'] if x.get('algorithm') == 'shell-command']
validation.append(dict(carrier='Owner-requested independent review grok/grok-4.7/high',status='green',evidence_ids=['artifacts/P1.8/grok-review.md']))
decision = terminal_policy.build_decision(root,'P1.8',result='closed-green',validation=validation)
batch = dict(start['batch_receipt']);batch['completed_prefix']=['P1.8']
entry = {key:start[key] for key in journal.REQUIRED_FIELDS}
entry.update(ts=datetime.now(timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ'), actor='codex/'+route['model']+'/'+route['effort'], result='closed-green', friction=[], batch_receipt=batch, decision=decision,
             note='V24 20 passed, negative controls and 15 package gates GREEN. See artifacts/P1.8/completion.md. review: grok/grok-4.7 GREEN\nwrite-site: pre_mutation: allow · pre_final: allow')
journal.append_idempotent_terminal(root,entry)
estimate = plan_continuity.WorkEstimate(route_attested=True,capabilities_attested=True,capabilities_sufficient=True,context_attested=False,context_health='unavailable',batch_receipt_valid=True,remaining_batch_digest=batch['invocation_digest'],loaded_inputs_reusable=True,cold_start_cost=0,lifecycle='scope-complete',checkpoint='artifacts/P1.8/completion.md')
continuity = plan_continuity.reduce(estimate)
carrier = command_render.PlanNextCarrier(kind='run-items',command_id='task:plan-run',plan_id=root.name,arguments=(root.name,'P1.4'),item_ids=('P1.4',),context_kind=continuity.context,continuity=continuity)
(out/'continuity.json').write_text(json.dumps({'estimate':estimate.as_dict(),'decision':continuity.as_dict()},indent=2)+'\n')
(root/'handoff.md').write_text(f'''# HANDOFF — 2026-10-03 — after P1.8

**Next:** run-items: task:plan-run {root.name} P1.4

| Parameter | Meaning |
|:--|:--|
| Batch | solo |
| Model class | implementation |
| Model | gpt-6.1-sol |
| Effort | medium |
| Thinking | medium |
| Context | {carrier.context_kind} |
| The bottom line | P1.8 complete; P1.4 adds event identity for outbox delivery. |

{command_render.render_continuity_decision(continuity)}

**Done:** ProblemException, 20 domain error codes/statuses, central conditional JSON rendering, preserved extras/tus headers, V24 and architecture guards, API golden and upgrade docs. review: grok/grok-4.7 GREEN. Evidence: artifacts/P1.8/completion.md.

**Remaining:** P1.4 and the remaining P1 items. This invocation admitted P1.8 only; no successor executed.

**Open risks:** Existing SQLite suite skips/risky annotations are retained; P1.8 requires package gate exit 0, not a PG concurrency run. The mandated Exceptions/Contracts directory is permitted only with the single ProblemException.php file, with a negative control. Prior unrelated dirty plan changes and concurrent .gitignore/.swissknife.json edits are preserved. Mutation follow-up remains stopped by D14. Push/release is not authorized.

**Sources of truth:** phases/P1/P1.8.md; phases/P1/P1.8.state.md; artifacts/P1.8/completion.md; artifacts/P1.8/grok-review.md.
''')
print(json.dumps({'terminal':entry['result'],'write_site':verdict,'next':'P1.4'},ensure_ascii=False))
