"""Close only after a complete, byte-matched Grok 4.7 GREEN verdict."""
from pathlib import Path
import json, hashlib
from task import task_contract, route_evidence, journal, terminal_policy, plan_continuity, plan_work
from task.command_render import PlanNextCarrier, render_continuity_decision
root=Path(__file__).resolve().parents[4]
plan=Path(__file__).resolve().parents[2]
art=Path(__file__).resolve().parent
item='P1.7'
def save(name,value):
    (art/name).write_text(json.dumps(value,indent=2,ensure_ascii=False)+'\n')
raw=json.loads((art/'grok-review-raw.json').read_text())
assert (art/'grok-review.exit').read_text().strip()=='0'
assert raw['stopReason']=='end_turn'
assert 'review: grok/grok-4.7 GREEN' in raw['text']
assert 'grok-4.7-build' in raw['modelUsage']
for path,digest in json.loads((art/'product-manifest.json').read_text()).items():
    assert hashlib.sha256((root/path).read_bytes()).hexdigest()==digest, path
(art/'grok-review.md').write_text(raw['text']+'\n')
start=[e for e in journal.read_authoritative_entries(plan) if e['item_id']==item and e['result']=='in-progress'][-1]
current=route_evidence.capture_launch(expected_invocation='task:plan-run '+plan.name+' '+item,expected_cwd=root)
receipt=route_evidence.run_receipt_from_journal(plan,start['run_id'])
route_evidence.verify_run_receipt(receipt,plan_id=plan.name,item_id=item,attempt=start['attempt'],session_id=start['session_id'],run_id=start['run_id'],current_evidence=current)
contract=task_contract.build(plan,item,actual_route=current,carrier='task:plan-run '+plan.name+' '+item)
context=task_contract.current_validation_context(plan,item)
prior_context=json.loads((art/'validation-context.json').read_text())
assert context['tree']==prior_context['tree'] and context['spec_sha256']==prior_context['spec_sha256']
receipts=[task_contract.build_validation_receipt(contract,e['id'],exit_code=0,context=context) for e in contract['evidence'] if e['algorithm']=='shell-command']
verdict=task_contract.evaluate(contract,validation_receipts=receipts,validation_context=context)
assert verdict['pre_mutation']=='allow' and verdict['pre_final']=='allow',verdict
save('task-contract.json',contract);save('validation-context.json',context);save('validation-receipts.json',receipts);save('write-site-verdict.json',verdict)
completion='''# P1.7 — Independent host keys

All 27 host-owned columns now use ids.host_keys (default string/varchar(64)), with four supported types and unchanged entity references, nullability and indexes. HostKeyColumns and VaulterSchema::hostKey are added; actor/tenant helpers use host types. Upgrade migration №3 uses the configured Vaulter connection and legacy table map, converts and restores actual types, skips missing tables/columns and exact target types, and leaves SQLite unchanged. No exception is swallowed or host-key value manually rewritten.

PostgreSQL declared validation: 27 passed, 305 assertions, zero skipped. Both committed v0.2 dumps preserve all fixture rows in the 11 host-column tables, all 27 columns widen, direct repeated up() emits no ALTER, repeat migrate is a no-op, rollback restores each strategy. Incompatible ULID-to-bigint conversion refuses and preserves rows. Fresh host types and helper conventions are checked. V23 fresh panel exercises UUID users, bigint project/Spatie role, actor and activity. Its temporary subject_id regression fails with SQLSTATE 22001/char(26); restored code passes.

All required core/drive/documents/media Pint/PHPStan/Pest gates exit 0 after bounded strict expectation updates: core 611 passed/20 skipped/17 risky; drive 454 passed/8 skipped/2 risky; documents 249 passed/1 skipped; media 128 passed/9 skipped/4 risky. Conditional skipped and risky cases are retained and are not claimed executed. The owner expressly authorized exactly three sibling assertions; scope-repair.md records the authorization and the additional core test-harness/snapshot updates.

## Known deviation — MySQL installation, owner PLAN2.P8.1

The declared real-MySQL fresh-install command returns exit 2 with 14 errors at the pre-existing sibling_active STORED/generated-column/cascading-FK incompatibility. The exact unchanged HEAD builder reproduces error 1215 using a harness that actually selects MySQL, rather than SQLite. This is the exception explicitly permitted by the item contract. No full MySQL installation success is claimed. Independent real-MySQL host-key DDL: 6 passed, 248 assertions, covering all 27 columns, both old strategies, all four fresh types, values, nullability, indexes, idempotency and rollback. Detailed proof is in mysql-baseline-classification.json and mysql-known-deviation.md. E10's conditional contract acceptance is satisfied by this classifier; its raw command exit remains 2 in validation.json and targeted-results.json.

## Review and closure

review: grok/grok-4.7 GREEN. Fresh independent read-only high-effort context; actual model grok-4.7-build. Verbatim verdict, raw result, route and packet retained. The prior incomplete CLI invocation reached its turn cap without a verdict; it is retained and not counted as completed review. Reviewed product bytes match product-manifest.json.

write-site: pre_mutation: allow · pre_final: allow

P1.7 closes with deviations solely for the contract's pre-existing MySQL installation gap. P1 remains open; the successor recommendation is P1.2, outside this invocation's authorized scope. Product changes and task-owned evidence will be committed in one scoped commit; shared pre-existing dirty plan/configuration carriers remain unstaged. No push, publication or unrelated edit.
'''
(art/'completion.md').write_text(completion)
validation=json.loads((art/'validation.json').read_text())
rows=[]
for e in contract['evidence']:
    if e['algorithm']=='shell-command':
        ids=[str((art/f).relative_to(root)) for f in validation['validation_evidence'][e['id']]]
        rows.append({'carrier':e['path'],'status':'green','evidence_ids':ids+[str((art/'validation-receipts.json').relative_to(root)),str((art/'completion.md').relative_to(root))]})
exhausted={'classifier':'recoverable-validation-gap/v1','failed_carrier':next(e['path'] for e in contract['evidence'] if e['id']=='E10'),'verdict':'outside-scope','reason':'Declared MySQL installation command is red on unchanged HEAD because sibling_active STORED conflicts with cascading parent_id FK. The P1.7 contract explicitly assigns this existing defect to P8.1; real MySQL host-key DDL is proven separately. Repairing the tree-generated-index contract is outside this item and owner authorization.','continuation':'PLAN2.P8.1: resolve the pre-existing MySQL installation incompatibility and rerun the full fresh-install carrier.','evidence_ids':[str((art/f).relative_to(root)) for f in ['mysql-final.log','mysql-baseline.log','mysql-baseline-classification.json','mysql-ddl-final.log','mysql-known-deviation.md']]}
decision=terminal_policy.build_decision(plan,item,result='closed-deviations',validation=rows,repair_exhausted=exhausted)
batch=dict(start['batch_receipt'],completed_prefix=[item])
entry=journal.new_entry(plan_id=plan.name,item_id=item,command='plan-run',actor='codex/'+current['model']+'/'+current['effort'],result='closed-deviations',session_id=start['session_id'],run_id=start['run_id'],attempt=start['attempt'],friction=['deviation'],note='P1.7 implemented and validated; known MySQL fresh-install baseline deviation retained for PLAN2.P8.1. See artifacts/P1.7/completion.md. review: grok/grok-4.7 GREEN\nwrite-site: pre_mutation: allow · pre_final: allow',decision=decision,batch_receipt=batch)
journal.append_idempotent_terminal(plan,entry);save('terminal-event.json',entry)
estimate=plan_continuity.WorkEstimate(route_attested=True,capabilities_attested=True,capabilities_sufficient=True,context_attested=False,context_health='unavailable',batch_receipt_valid=True,remaining_batch_digest=hashlib.sha256(b'[]').hexdigest(),loaded_inputs_reusable=True,cold_start_cost=0,lifecycle='scope-complete',checkpoint='artifacts/P1.7/completion.md')
continuity=plan_continuity.reduce(estimate)
next_carrier=PlanNextCarrier(kind='run-items',command_id='task:plan-run',plan_id=plan.name,arguments=(plan.name,'P1.2'),context_kind=continuity.context,item_ids=('P1.2',),continuity=continuity)
save('continuity.json',{'estimate':estimate.as_dict(),'decision':continuity.as_dict(),'next':next_carrier.as_dict()})
(plan/'handoff.md').write_text('''# HANDOFF — 2026-10-03 — after P1.7

**Next:** run-items: task:plan-run '''+plan.name+''' P1.2

| Parameter | Meaning |
|:--|:--|
| Batch | solo |
| Model class | frontier |
| Model | gpt-6-astra |
| Effort | high |
| Context | '''+continuity.context+''' |
| Essence | P1.2: tree invariants, locking and root repair. |

'''+render_continuity_decision(continuity)+'''

**Done:** P1.7 Done with deviations; review: grok/grok-4.7 GREEN. Evidence: artifacts/P1.7/completion.md.

**Remaining:** P1.2, P1.3, P1.5, P1.6. P1.2 is a recommendation outside this invocation.

**Open risks:** MySQL full-install error 1215 remains owned by P8.1. Conditional SQLite skips/risky retained. Prior owner dirty plan/configuration carriers remain unstaged. No push or release.

**Sources of truth:** phases/P1/P1.7.md; journal.jsonl; artifacts/P1.7/completion.md; roadmap.md execution-sheet/v1.
''')
result=plan_work.finalize(plan,item);save('finalization.json',result);save('terminal-delivery-receipt.json',result['receipt']);print({'result':'closed-deviations','next':result['receipt']['next_exact']})
