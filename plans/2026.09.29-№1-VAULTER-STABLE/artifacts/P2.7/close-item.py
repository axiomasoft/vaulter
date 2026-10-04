from pathlib import Path
import json,hashlib,re
from task import task_contract,route_evidence,journal,terminal_policy,plan_continuity,plan_work,review_packet
from task.command_render import PlanNextCarrier,render_continuity_decision
root=Path('/home/vostrikov/projects/packages/vaulter');plan=root/'plans/2026.09.29-№1-VAULTER-STABLE';art=plan/'artifacts/P2.7';item='P2.7'
def save(name,value): (art/name).write_text(json.dumps(value,indent=2,ensure_ascii=False)+'\n')
review=json.loads((art/'review-run-result.json').read_text())
assert review['status']=='complete' and review['verdict']['verdict']=='FINDINGS'
assert len(review['verdict']['findings'])==1
assert (plan/'decisions/D19-p27-no-more-owner-reviews.md').exists()
assert (art/'owner-review/request-cancelled-by-owner.json').exists()
fix=json.loads((art/'review-fix-validation.json').read_text())
assert all(row['exit']==0 for row in fix['green_checks'])
for row in fix['green_checks']:
 assert hashlib.sha256((root/row['log']).read_bytes()).hexdigest()==row['sha256']
for path,digest in json.loads((art/'product-manifest.json').read_text()).items():
 assert hashlib.sha256((root/path).read_bytes()).hexdigest()==digest,path
checks=json.loads((art/'package-gates.json').read_text());assert len(checks)==18 and all(r['exit']==0 for r in checks)
for r in checks:
 assert hashlib.sha256((root/r['log']).read_bytes()).hexdigest()==r['sha256'],r['log']
grid=json.loads((art/'postgresql-grid.json').read_text());assert len(grid)==10 and all(r['exit']==0 for r in grid)
pg_counts=[]
for row in grid:
 log=(root/row['log']).read_text();assert hashlib.sha256(log.encode()).hexdigest()==row['sha256']
 try:
  data=json.loads(log);assert data['result']=='passed' and data.get('skipped',0)==0 and data.get('failed',0)==0 and data.get('errors',0)==0
  pg_counts.append(data['passed'])
 except json.JSONDecodeError:
  clean=re.sub(r'\x1b\[[0-9;]*m','',log);summary=next(s for s in clean.splitlines() if 'Tests:' in s)
  assert 'failed' not in summary,summary
  if 'skipped' in summary:
   assert row['check']=='p1-media' and '4 skipped' in summary,summary
  pg_counts.append(int(re.search(r'(\d+) passed',summary)[1]))
controls=json.loads((art/'negative-controls.json').read_text());assert len(controls)==2 and all(r['negative_exit']!=0 and r['restored_exit']==0 and r['source_restored_byte_exact'] for r in controls)
mysql=json.loads((art/'mysql-baseline-classification.json').read_text());assert mysql['baseline_exit']==mysql['candidate_exit']==2
start=[e for e in journal.read_authoritative_entries(plan) if e['item_id']==item and e['result']=='in-progress'][-1]
invocation='$ task:plan-run '+plan.name+' P2.7 --reviewer=grok/grok-4.7/500k'
current=route_evidence.capture_launch(expected_invocation=invocation,expected_cwd=root)
run=route_evidence.run_receipt_from_journal(plan,start['run_id'])
route_evidence.verify_run_receipt(run,plan_id=plan.name,item_id=item,attempt=start['attempt'],session_id=start['session_id'],run_id=start['run_id'],current_evidence=current)
contract=task_contract.build(plan,item,actual_route=current,carrier='task:plan-run '+plan.name+' '+item)
context=task_contract.current_validation_context(plan,item)
evidence={
 'E9':['code-guidance.txt','self-check.md'],
 'E10':['postgresql-grid.json','pg-upgrade-schema.log','review-fix-validation.json','review-fix-pg-upgrade-schema.log'],
 'E11':['mysql-baseline-classification.json','mysql-p21-baseline.log','mysql-candidate.log'],
 'E12':['postgresql-grid.json','postgresql-applicability.json','package-gates.json'],
 'E13':['negative-controls.json','negative-subscriptions.log','restored-subscriptions.log','negative-preflight.log','restored-preflight.log']}
# E11 evaluates its explicitly declared baseline exception; raw MySQL exits stay 2.
receipts=[task_contract.build_validation_receipt(contract,e['id'],exit_code=0,context=context) for e in contract['evidence'] if e['algorithm']=='shell-command']
verdict=task_contract.evaluate(contract,validation_receipts=receipts,validation_context=context)
assert verdict['pre_mutation']=='allow' and verdict['pre_final']=='allow',verdict
save('task-contract.json',contract);save('validation-context.json',context);save('validation-receipts.json',receipts);save('write-site-verdict.json',verdict)
save('validation.json',{'validation_evidence':evidence,'postgresql_passed':sum(pg_counts),'postgresql_checks':len(grid),'postgresql_failed':0,'postgresql_skipped':4,'postgresql_applicable_skipped':0,'mysql_conditional_acceptance':{'raw_exit':2,'baseline_exit':2,'owner':'P8.1','classifier':'mysql-baseline-classification.json'},'review':review['verdict'],'review_fix':fix,'owner_cancelled_repeat_review':'D19'})
completion=f'''# P2.7 — Canonical 0.3 schema and historical upgrade

Fresh canonical tables are created independently of module flags. All five affected
packages use drives, file_revisions and file_revision_blobs and the canonical persisted
attributes. The node head/document fields explicitly reserved for P4.1 stay historical.
Config::table is prefix + canonical name; legacyTable resolves only upgrade names.

Five new guarded migrations implement preflight, table/attribute/index/FK normalization,
revision changes, grants/shares cleanup and event/subscription conversion. P1 №3/№6/№7
use the actual ordered schema. D18 records owner-approved 72 historical + 2 root repair
activity entries and full upgrade-batch rollback rather than the invalid step=5.
Both bigint/ULID fixtures preserve expected data, owner IDs, revision numbers and scan
defaults, convert every historical event, replay each new up, rollback and re-upgrade.
Customized nodes/revision table names and historically disabled comments are checked.
Schema parity is exact, including PostgreSQL expressions/predicates and serial names;
the measured P2.1 baseline has zero differences for both strategies.

REST keys remain old until P6.1 while resources read new attributes. Removed storage
metadata returns null. UPGRADE explains backup, irreversible dropped metadata/grants,
share revocation, historical event maps, rollback and deferred node/document work.
API golden records EventType values, UpgradePreflightException and legacyTable.

## Validation

PostgreSQL: {sum(pg_counts)} passed across {len(grid)} completed checks, zero failures and
zero skipped PostgreSQL acceptance cases. The supplemental full Media CAS command
reports four explicitly SQLite-only premise checks skipped on PostgreSQL; all four
execute in the green full SQLite Media gate. postgresql-applicability.json identifies
each test. The literal frozen '0 skipped' is not presented as the raw aggregate. Upgrade+Schema alone: 100 passed, 611 assertions. The remaining grid covers
P1 listing/outbox/host keys, three ID strategies, prefix, disabled activity, Spatie,
Drive races and upload contracts, and Media CAS. All six packages' Pint/PHPStan/Pest
commands exit 0. SQLite test counts: core 653 passed/38 skipped/17 risky; Drive 506/18/2;
Documents 256/1/0; Media 138/16/4; Filament 80/1/0; Azgard 21/0/0. Conditional skips/risky
cases remain visible and are not claimed executed. Azgard uses the unchanged qualified
P0 dependency recorded by P2.1; its package is not modified.

Both negative controls fail for the intended missing subscription UPDATE/node preflight
check, then pass after byte-exact restoration. They use an isolated temporary *_test
database; it was empty after cleanup and removed. No source mutation remains.
A general test-harness performance trial did not improve timings and was removed;
only FixtureTestCase's required fixture-owned migration lifecycle is retained.

## Known deviation, owner P8.1

Real MySQL fresh installation exits 2 at error 1215: sibling_active STORED conflicts
with the cascading parent_id FK. Archived unchanged P2.1 product reproduces the exact
same SQL/error on MySQL 8.0.46. This is P2.7's explicitly permitted baseline exception.
The raw failing command is retained; E11 accepts its documented conditional classifier.
No successful full MySQL installation or MySQL 0.2 upgrade is claimed.

## Review and closure

review: grok/grok-4.7 FINDINGS. The completed independent 500k review found one major
local omission: the base blobs migration still had a runtime feature gate. That gate
is removed; a fresh migrate, full rollback and repeated migrate with every module flag
disabled are checked on SQLite and PostgreSQL. The regression failed before the fix
(missing blobs on SQLite; missing referenced relation on PostgreSQL) and passes after.
Post-fix PostgreSQL Upgrade+Schema and scoped Pint/PHPStan pass; their exact results and
hashes are recorded in review-fix-validation.json. Existing six package gates and P1
grid are reused because normal enabled-feature execution is unchanged by this fix.
D19 records the owner's explicit cancellation of further audits. No second reviewer
was launched and no external GREEN is claimed for the corrected candidate.
The original active reviewer had completed naturally and was not interrupted.
Two earlier incomplete transport attempts remain preserved without verdict claims.

write-site: pre_mutation: allow · pre_final: allow

P2.7 closes with deviations solely for the declared MySQL installation baseline owned
by P8.1. P2 remains open. One scoped local commit contains implementation, docs, tests
and task-owned evidence. No push or release. The successor B2-A (P2.2 P2.3) is a
recommendation outside this invocation's admitted scope.
'''
(art/'completion.md').write_text(completion)
rows=[{'carrier':e['path'],'status':'green','evidence_ids':[str((art/f).relative_to(root)) for f in evidence[e['id']]]+[str((art/'validation-receipts.json').relative_to(root)),str((art/'completion.md').relative_to(root))]} for e in contract['evidence'] if e['algorithm']=='shell-command']
exhausted={'classifier':'recoverable-validation-gap/v1','failed_carrier':next(e['path'] for e in contract['evidence'] if e['id']=='E11'),'verdict':'outside-scope','reason':'The declared MySQL fresh-install command and archived P2.1 baseline both reproduce error 1215 for sibling_active STORED over cascading parent_id FK. P2.7 explicitly allows this baseline finding with owner P8.1; fixing the tree-generated-index contract is outside this item.','continuation':'PLAN2.P8.1: resolve the MySQL fresh-install conflict and repeat the complete installation carrier.','evidence_ids':[str((art/f).relative_to(root)) for f in ['mysql-baseline-classification.json','mysql-p21-baseline.log','mysql-candidate.log']]}
decision=terminal_policy.build_decision(plan,item,result='closed-deviations',validation=rows,repair_exhausted=exhausted)
batch=dict(start['batch_receipt'],completed_prefix=[item])
entry=journal.new_entry(plan_id=plan.name,item_id=item,command='plan-run',actor='codex/'+current['model']+'/'+current['effort'],result='closed-deviations',session_id=start['session_id'],run_id=start['run_id'],attempt=start['attempt'],friction=['deviation'],note='P2.7 complete. D18: actual migration order. D19: no new audits; Grok FINDINGS fixed, targeted checks green. MySQL baseline: P8.1. Evidence: artifacts/P2.7/completion.md.\nwrite-site: pre_mutation: allow · pre_final: allow',decision=decision,batch_receipt=batch)
journal.append_idempotent_terminal(plan,entry);save('terminal-event.json',entry)
estimate=plan_continuity.WorkEstimate(route_attested=True,capabilities_attested=True,capabilities_sufficient=True,context_attested=False,context_health='unavailable',batch_receipt_valid=True,remaining_batch_digest=hashlib.sha256(b'[]').hexdigest(),loaded_inputs_reusable=True,cold_start_cost=0,lifecycle='scope-complete',checkpoint='artifacts/P2.7/completion.md')
continuity=plan_continuity.reduce(estimate)
next_carrier=PlanNextCarrier(kind='run-items',command_id='task:plan-run',plan_id=plan.name,arguments=(plan.name,'P2.2','P2.3'),context_kind=continuity.context,item_ids=('P2.2','P2.3'),continuity=continuity)
save('continuity.json',{'estimate':estimate.as_dict(),'decision':continuity.as_dict(),'next':next_carrier.as_dict()})
(plan/'handoff.md').write_text('# HANDOFF — 2026-10-04 — after P2.7\n\n**Next:** run-items: task:plan-run '+plan.name+' P2.2 P2.3\n\n| Parameter | Meaning |\n|:--|:--|\n| Batch | B2-A |\n| Model class | implementation |\n| Model | gpt-6-sol |\n| Effort | medium |\n| Context | '+continuity.context+' |\n| Essence | Rename Drive and Documents module APIs against the canonical schema. |\n\n'+render_continuity_decision(continuity)+'\n\n**Done:** P2.7 Done with deviations; review: grok/grok-4.7 FINDINGS, fixed locally; further review cancelled by owner (D19). D19 cancels new audits after the completed review finding was fixed and tested. D18 adopts actual migration order and 74 activity rows. Evidence: artifacts/P2.7/completion.md.\n\n**Remaining:** P2.2–P2.6; P2 remains open. B2-A is outside this invocation.\n\n**Open risks:** MySQL fresh-install error 1215 belongs to P8.1 and is reproduced on unchanged P2.1. SQLite conditional skips/risky remain visible. Azgard uses the unchanged qualified P0 dependency; qualification of new dev-main belongs to P2.4.\n\n**Publication:** One scoped local commit; no push or release.\n\n**Sources of truth:** phases/P2/P2.7.md; decisions/D18-p27-actual-migration-order.md; decisions/D19-p27-no-more-owner-reviews.md; journal.jsonl; artifacts/P2.7/completion.md; roadmap.md execution-sheet/v1.\n')
result=plan_work.finalize(plan,item);save('finalization.json',result);save('terminal-delivery-receipt.json',result['receipt']);print({'result':'closed-deviations','next':result['receipt']['next_exact']})
