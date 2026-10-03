"""Persist verified P1.1 completion through Task owners after independent review."""
import json
from datetime import datetime, timezone
from pathlib import Path
from task import task_contract, terminal_policy, journal, plan_continuity, command_render

root = Path(__file__).resolve().parents[2]
out = Path(__file__).resolve().parent
start = next(json.loads(x) for x in (root/'journal.jsonl').read_text().splitlines()
             if json.loads(x).get('run_id') == 'a50ce543a4811d5421f203304d07e13d363d92a6e26963134830e7acb34a7df9'
             and json.loads(x)['result'] == 'in-progress')
review = (out/'grok-review.md').read_text()
assert 'GREEN' in review, 'Independent Grok 4.7 review must be GREEN before closing'
checks = json.loads((out/'final-validation.json').read_text())
assert all(x['exit'] == 0 for x in checks)
assert all(x['exit'] == 0 for x in json.loads((out/'model-validation.json').read_text()))
assert 'findings:1' in (out/'grok-review-initial.md').read_text()
assert json.loads((out/'inventory-after.json').read_text())['count'] == 0
route = start['decision']['route_receipt']['evidence']
contract = task_contract.build(root, 'P1.1', actual_route=route)
context = task_contract.current_validation_context(root, 'P1.1')
receipts = [task_contract.build_validation_receipt(contract, x['id'], exit_code=0, context=context)
            for x in contract['evidence'] if x.get('algorithm') == 'shell-command']
verdict = task_contract.evaluate(contract, validation_receipts=receipts, validation_context=context)
assert verdict['pre_mutation'] == verdict['pre_final'] == 'allow', verdict
for name,data in [('task-contract-final',contract),('task-validation-context',context),('task-validation-receipts',receipts),('task-contract-verdict',verdict)]:
    (out/(name+'.json')).write_text(json.dumps(data,ensure_ascii=False,indent=2)+'\n')
validation = [dict(carrier=x['path'], status='green', evidence_ids=['artifacts/P1.1/final-validation.json'])
              for x in contract['evidence'] if x.get('algorithm') == 'shell-command']
validation.append(dict(carrier='Independent full review grok/grok-4.7/high',status='green',evidence_ids=['artifacts/P1.1/grok-review.md']))
decision = terminal_policy.build_decision(root,'P1.1',result='closed-green',validation=validation)
batch = dict(start['batch_receipt']);batch['completed_prefix']=['P1.1']
entry = {key:start[key] for key in journal.REQUIRED_FIELDS}
entry.update(ts=datetime.now(timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ'), actor='codex/'+route['model']+'/'+route['effort'], result='closed-green', friction=[], batch_receipt=batch, decision=decision,
             note='Database/model routing, package gates, V17 and negative controls GREEN. See artifacts/P1.1/completion.md. review: grok/grok-4.7 GREEN\nwrite-site: pre_mutation: allow · pre_final: allow')
journal.append_idempotent_terminal(root,entry)
estimate = plan_continuity.WorkEstimate(route_attested=True,capabilities_attested=True,capabilities_sufficient=True,context_attested=False,context_health='unavailable',batch_receipt_valid=True,remaining_batch_digest=batch['invocation_digest'],loaded_inputs_reusable=True,cold_start_cost=0,lifecycle='scope-complete',checkpoint='artifacts/P1.1/completion.md')
continuity = plan_continuity.reduce(estimate)
carrier = command_render.PlanNextCarrier(kind='run-items',command_id='task:plan-run',plan_id=root.name,arguments=(root.name,'P1.8'),item_ids=('P1.8',),context_kind=continuity.context,continuity=continuity)
(out/'continuity.json').write_text(json.dumps({'estimate':estimate.as_dict(),'decision':continuity.as_dict()},indent=2)+'\n')
launch = command_render.render_codex_launch('$ task:plan-run '+root.name+' P1.8','gpt-6-sol','low',root.parents[1])
handoff = f'''# HANDOFF — 2026-10-03 — after P1.1

**Next:** run-items: task:plan-run {root.name} P1.8

| Parameter | Meaning |
|:--|:--|
| Batch | solo |
| Model class | implementation |
| Model | gpt-6-sol |
| Effort | low |
| Thinking | low |
| Context | {carrier.context_kind} |
| The bottom line | P1.1 complete; P1.8 introduces the domain exception contract. |

{command_render.render_continuity_decision(continuity)}

```bash
{launch}
```

**Done:** VaulterDatabase; dedicated transaction/query/lock routing; arch guards; V17 and negative controls; package gates; public API golden and upgrade docs. review: grok/grok-4.7 GREEN. Evidence: artifacts/P1.1/completion.md.

**Remaining:** P1.8 and the remaining P1 items. This invocation admitted P1.1 only; no successor executed.

**Open risks:** Upload/Document model connection gap fixed after Grok finding; four additional model files and rollback tests documented. P0 mutation follow-up remains stopped by D14. Original unrelated dirty plan changes preserved. Owner requires reviews only on Grok 4.7. Push/release not authorized.
'''
handoff += '\n**Sources of truth:** phases/P1/P1.1.md; phases/P1/P1.1.state.md; artifacts/P1.1/completion.md; artifacts/P1.1/grok-review.md.\n'
(root/'handoff.md').write_text(handoff)
print(json.dumps({'terminal':entry['result'],'write_site':verdict,'next':'P1.8'},ensure_ascii=False))
