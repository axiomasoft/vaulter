"""Prove spec-only bookkeeping equivalence; never relabel or modify old executions."""
from pathlib import Path
import argparse,datetime as dt,hashlib,json,re,shutil,subprocess,sys
NATIVE=Path('/home/vostrikov/projects/packages/swissknifeman/packages/task')
sys.path[:0]=[str(NATIVE/'lib'),str(NATIVE.parent.parent)]
from task import file_manifest,review_packet,plan_gates,task_contract,plan_evidence
SPEC='plans/2026.09.29-№1-VAULTER-STABLE/phases/P2/P2.2.md'
SCHEMA='bookkeeping-validation-equivalence/v1'
def need(ok,detail):
 if not ok:raise ValueError(detail)
def read(p):return json.loads(Path(p).read_text())
def sha(p):return hashlib.sha256(Path(p).read_bytes()).hexdigest()
def digest(rows):return hashlib.sha256(review_packet._json(rows)).hexdigest()
def field(text,name):
 m=re.search(r'^\*\*'+re.escape(name)+r':\*\*\s*(.*?)(?=^\*\*[A-Za-z ]+:\*\*|\Z)',text,re.M|re.S);need(m is not None,'missing field '+name);return m.group(1).strip()
def semantics(root,before,after):
 left=plan_gates._declared_file_paths(field(before,'Files'));right=plan_gates._declared_file_paths(field(after,'Files'))
 a=file_manifest.expand(root,left,base='HEAD',required=True);b=file_manifest.expand(root,right,base='HEAD',required=True)
 need(a==b and len(a)==203,'native Files membership differs from original 203')
 identity={}
 for name in ['Inputs','Required Reads']:
  plan=root/'plans/2026.09.29-№1-VAULTER-STABLE'
  def normalized(text):return tuple(sorted({plan_evidence._repository_path(path,plan_dir=plan,repo_root=root) for declaration in plan_evidence._declared_paths(field(text,name),allow_prose=True) for path in plan_gates._expand_braces(declaration)}))
  av=normalized(before);bv=normalized(after);need(av==bv,name+' normalized path set changed');identity[name]=list(av)
 for name in ['Validation','Authority']:
  av=field(before,name);bv=field(after,name);need(av==bv,name+' exact literal changed');identity[name]=av
 need(len([x for x in identity['Validation'].splitlines() if x.startswith('- ')])==3,'three Validation carriers required')
 return {'expanded_files':list(a),'expanded_files_count':203,'normalized_input_sets':identity}
def verify(root,validation,original_spec):
 root=Path(root).resolve();validation=Path(validation).resolve();s=read(validation/'summary.json');need(s['candidate_fresh'] and s['affected_complete'] and s['full_complete'] and s['green_gates']==28 and not s['failed_gates'] and not s['dependency_skipped_gates'],'original full validation not complete GREEN')
 receipts={stage:(Path(path),read(path)) for stage,path in s['receipts'].items()}
 old=receipts['full'][1]['candidate'];oldsha=s['candidate_sha256'];need(digest(old)==oldsha,'original candidate digest corrupted')
 for stage,(p,r) in receipts.items():need(r['candidate']==old and r['candidate_sha256']==oldsha and r['complete'] and r['candidate_fresh'] and all(v=={'exit_code':0,'status':'green'} for v in r['results'].values()),'original '+stage+' receipt invalid')
 current=review_packet._members(root,file_manifest.expand(root,read(validation/'candidate-paths.json'),base='HEAD',required=True));oldmap={r['path']:r for r in old};newmap={r['path']:r for r in current};need(set(oldmap)==set(newmap),'candidate member inventory changed')
 delta=[{'path':p,'before':oldmap[p],'after':newmap[p]} for p in sorted(oldmap) if oldmap[p]!=newmap[p]];need([r['path'] for r in delta]==[SPEC],'only current P2.2 spec byte change is permitted: '+str([r['path'] for r in delta]));need(oldmap[SPEC]['mode']==newmap[SPEC]['mode'],'spec mode changed')
 need(sha(original_spec)==oldmap[SPEC]['sha256'],'original spec copy does not bind original b0 receipt')
 sem=semantics(root,Path(original_spec).read_text(),(root/SPEC).read_text());product_before=[oldmap[p] for p in sem['expanded_files']];product_after=[newmap[p] for p in sem['expanded_files']];need(product_before==product_after,'native material product members changed')
 externals=read(validation/'tools/external-tools.json');need(len(externals)==149,'original runtime pins not 149');need(all(Path(p).is_file() and sha(p)==h for p,h in externals.items()),'pinned external runtime drift')
 context=task_contract.current_validation_context(root/'plans/2026.09.29-№1-VAULTER-STABLE','P2.2')
 return {'schema_version':SCHEMA,'status':'green','original_candidate_sha256':oldsha,'current_candidate_sha256':digest(current),'original_member_count':len(old),'current_member_count':len(current),'delta':delta,'allowed_delta':[SPEC],'behavioral_members_unchanged':len(old)-1,'material_product_identity_sha256':digest(product_after),'material_product_members':203,'native_declarations':sem,'runtime_pins':{'count':149,'inventory':str(validation/'tools/external-tools.json'),'inventory_sha256':sha(validation/'tools/external-tools.json'),'current_hashes_match':True},'original_receipts':{stage:{'path':str(p),'sha256':sha(p),'candidate_sha256':oldsha,'complete':True,'actual_execution_preserved':True} for stage,(p,r) in receipts.items()},'current_validation_context':context,'tests_rerun':False,'generation_rerun':False,'statement':'Original genuine test/process receipts retain original candidate and timestamps. Current evidence qualifies syntax-only item-spec bookkeeping; it does not assert tests executed on the new spec candidate.'}
def verify_existing(root,validation,qualification,*,require_structural_receipt=True):
 q=Path(qualification).resolve();stored=read(q/'qualification.json');fresh=verify(root,validation,q/'original-spec.md')
 for key in fresh:need(stored[key]==fresh[key],'bookkeeping qualification drift '+key)
 need(sha(q/'normative-equivalence.json')==stored['normative_equivalence_sha256'],'normative equivalence attestation changed')
 if require_structural_receipt:
  result=read(q/'structural-result.json');r=read(result['receipt']);need(r['complete'] and r['candidate_fresh'] and all(v=={'exit_code':0,'status':'green'} for v in r['results'].values()),'fresh structural DAG not GREEN')
  actual=review_packet._members(Path(root),file_manifest.expand(Path(root),read(q/'structural-paths.json'),base='HEAD',required=True));need(r['candidate']==actual and r['candidate_sha256']==digest(actual),'structural receipt candidate changed')
 return fresh

def main():
 a=argparse.ArgumentParser();a.add_argument('--root',type=Path,default=Path('/home/vostrikov/projects/packages/vaulter'));a.add_argument('--validation',type=Path,required=True);a.add_argument('--install',action='store_true');a.add_argument('--qualification',type=Path);a.add_argument('--norm-proof',type=Path);a.add_argument('--original-spec',type=Path,default=Path('/tmp/p22-bookkeeping-prepare/original-spec.md'));z=a.parse_args();root=z.root.resolve();validation=z.validation.resolve()
 if not z.install:
  need(z.qualification is not None,'--qualification required');print(json.dumps(verify_existing(root,validation,z.qualification,require_structural_receipt=False),ensure_ascii=False));return
 need(z.norm_proof is not None,'explicit syntax-only normative equivalence proof required')
 stamp=dt.datetime.now(dt.timezone.utc).strftime('%Y%m%dT%H%M%S%fZ');q=root/'plans/2026.09.29-№1-VAULTER-STABLE/artifacts/P2.2'/('qualification-bookkeeping-'+stamp);q.mkdir();(q/'tools').mkdir();shutil.copyfile(__file__,q/'tools/bookkeeping.py');shutil.copyfile(z.original_spec,q/'original-spec.md');shutil.copyfile(z.norm_proof,q/'normative-equivalence.json')
 proof=verify(root,validation,q/'original-spec.md');norm=read(q/'normative-equivalence.json');need(norm['original_sha256']==proof['delta'][0]['before']['sha256'] and norm['candidate_sha256']==proof['delta'][0]['after']['sha256'],'normative proof spec SHA mismatch');need(norm['Validation_literal_equal'] and norm['Authority_equal'],'normative proof must retain Validation and Authority');proof.update({'captured_at':dt.datetime.now(dt.timezone.utc).isoformat(),'normative_equivalence_sha256':sha(q/'normative-equivalence.json'),'qualification_dir':str(q),'writer_released':False});(q/'qualification.json').write_text(json.dumps(proof,ensure_ascii=False,indent=2)+'\n')
 # Native structural evidence binds the current behavioral candidate AND the new qualification tool/control closure.
 paths=read(validation/'candidate-paths.json')+[str((q/'tools/bookkeeping.py').relative_to(root)),str((q/'qualification.json').relative_to(root)),str((q/'original-spec.md').relative_to(root)),str((q/'normative-equivalence.json').relative_to(root)),str((q/'structural-checks.json').relative_to(root)),str((q/'structural-paths.json').relative_to(root))]
 env=['env','-u','UPDATE_SNAPSHOT','APP_ENV=testing','DB_CONNECTION=sqlite','DB_DATABASE=:memory:','CACHE_STORE=array','SESSION_DRIVER=array','QUEUE_CONNECTION=sync',str(root/'scripts/php84')]
 pg=['env','-u','UPDATE_SNAPSHOT','APP_ENV=testing','DB_CONNECTION=pgsql','DB_HOST=127.0.0.1','DB_PORT=5432','DB_DATABASE=vaulter_test','DB_USERNAME=vaulter','DB_PASSWORD=secret',str(root/'scripts/php84')]
 checks=[{'id':'current-bookkeeping-equivalence','argv':['python3',str(q/'tools/bookkeeping.py'),'--root',str(root),'--validation',str(validation),'--qualification',str(q)],'depends_on':[]},{'id':'current-php84-preflight','argv':[str(root/'scripts/php84'),'--check'],'depends_on':['current-bookkeeping-equivalence']},{'id':'current-sqlite-environment','argv':env+['php',str(validation/'tools/environment.php')],'depends_on':['current-php84-preflight']},{'id':'current-pg-readiness','argv':['pg_isready','-h','127.0.0.1','-p','5432','-U','vaulter','-d','vaulter_test'],'depends_on':['current-sqlite-environment']},{'id':'current-pg-exact-target','argv':pg+['php',str(validation/'tools/pg-preflight.php')],'depends_on':['current-pg-readiness']}]
 (q/'structural-checks.json').write_text(json.dumps(checks,ensure_ascii=False,indent=2)+'\n');(q/'structural-paths.json').write_text(json.dumps(paths,ensure_ascii=False,indent=2)+'\n')
 # Capture proof before first structural process, preserving the original behavioral candidate identity separately.
 members=review_packet._members(root,file_manifest.expand(root,paths,base='HEAD',required=True));bound={r['path'] for r in members};externals=read(validation/'tools/external-tools.json');argv_bindings=[]
 for node in checks:
  for value in node['argv']:
   path=Path(value)
   if path.is_absolute() and path.is_file():
    relative=path.relative_to(root).as_posix() if path.is_relative_to(root) else None
    kind='current-structural-candidate' if relative in bound else 'pinned-external' if str(path.resolve()) in externals or str(path) in externals else None
    need(kind is not None,'unbound actual structural argv file '+str(path));argv_bindings.append({'check':node['id'],'path':str(path),'binding':kind})
 need(str(NATIVE/'scripts/plan-work.py') in externals,'native DAG runner not pinned')
 binding={'captured_before_first_validate_evidence':True,'current_behavioral_candidate_sha256':proof['current_candidate_sha256'],'structural_candidate_sha256':digest(members),'structural_tool_sha256':sha(q/'tools/bookkeeping.py'),'runtime_pins_count':149,'old_receipts_unchanged':True,'all_gate_files_bound':True,'argv_file_bindings':argv_bindings,'native_runner_pinned':str(NATIVE/'scripts/plan-work.py')};(q/'structural-binding-proof.json').write_text(json.dumps(binding,indent=2)+'\n')
 cmd=['python3',str(NATIVE/'scripts/plan-work.py'),'validate-evidence','--plan-dir',str(root/'plans/2026.09.29-№1-VAULTER-STABLE'),'--checks',str(q/'structural-checks.json'),'--attempt-id','p22-bookkeeping-'+stamp,'--output-dir',str(q/'canonical')]
 for p in paths:cmd+=['--path',p]
 started=dt.datetime.now(dt.timezone.utc).isoformat()
 with (q/'structural-result.json').open('x') as out,(q/'structural-runner.log').open('x') as err:r=subprocess.run(cmd,cwd=root,stdout=out,stderr=err)
 finished=dt.datetime.now(dt.timezone.utc).isoformat();(q/'structural-execution.json').write_text(json.dumps({'argv':cmd,'started_at':started,'finished_at':finished,'exit_code':r.returncode,'operation':'fresh structural/environment equivalence qualification; no tests'},ensure_ascii=False,indent=2)+'\n')
 need(r.returncode==0,'native structural evidence process failed');verify_existing(root,validation,q)
 # Released status remains outside the structural candidate; do not change captured qualification.json.
 (q/'summary.json').write_text(json.dumps({'schema_version':'bookkeeping-qualification-summary/v1','status':'green','qualification':str(q/'qualification.json'),'original_candidate_sha256':proof['original_candidate_sha256'],'current_candidate_sha256':proof['current_candidate_sha256'],'original_full_green_gates':28,'structural_result':str(q/'structural-result.json'),'structural_check_count':5,'actual_test_runs':'unchanged original b0 receipts','tests_rerun':False,'generation_rerun':False,'writer_released':True},ensure_ascii=False,indent=2)+'\n');print(json.dumps({'qualification_dir':str(q),'current_candidate_sha256':proof['current_candidate_sha256'],'writer_released':True}))
if __name__=='__main__':main()
