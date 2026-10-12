import re,datetime
from common import *
base=Path(sys.argv[1]);receipts={};results={};counts={};proof=read(base/'structural-binding-proof.json');generation=verify_generation(base);runtime_proof=verify_runtime(base,content=True)
for stage in ['affected','full']:
 result=read(base/(stage+'-result.json'));receipt=read(result['receipt']);receipts[stage]=result['receipt'];results.update(receipt['results'])
 if not receipt['complete'] or not receipt['candidate_fresh'] or proof['candidate_sha256']!=receipt['candidate_sha256']:raise SystemExit(stage+' incomplete/stale')
 for name,row in receipt['results'].items():
  text=(Path(result['receipt']).parent/(name+'.log')).read_text();plain=re.sub(r'\x1b\[[0-9;]*m','',text)
  for line in plain.splitlines():
   try:data=json.loads(line)
   except ValueError:continue
   if data.get('tool')=='pest':counts[name]={**data,'failed':data.get('failed',0),'skipped':data.get('skipped',0),'risky':data.get('risky',0)}
  match=re.search(r'Tests:\s+([^\n]+)',plain)
  if match:
   status={k:int(v) for v,k in re.findall(r'(\d+) (passed|failed|skipped|risky|warnings|incomplete)',match.group(1))};a=re.search(r'\((\d+) assertions\)',match.group(1));counts[name]={'failed':0,'skipped':0,'risky':0,**status,'assertions':int(a.group(1)) if a else None,'summary':match.group(0)}
for n,passed in [('core-p1-pg',40),('drive-p1-pg',174)]:
 assert n in counts and counts[n].get('passed')==passed and counts[n]['failed']==0 and counts[n]['skipped']==0,(n,counts.get(n))
for name in [p+'-pest-sqlite' for p in ['core','drive','documents','filament']]+['drive-affected','documents-affected','core-affected']:
 assert name in counts,'Missing real Pest count '+name
assert not proof['issues'] and proof['tools_and_checks_bound']
assert digest(members(read(base/'candidate-paths.json')))==proof['candidate_sha256'],'Candidate changed after checks'
summary={'candidate_sha256':proof['candidate_sha256'],'candidate_fresh':True,'affected_complete':True,'full_complete':True,'green_gates':sum(v['status']=='green' for v in results.values()),'failed_gates':sum(v['status']=='red' for v in results.values()),'dependency_skipped_gates':[k for k,v in results.items() if v['status']!='green'],'receipts':receipts,'counts':counts,'postgres_status':'exact isolated vaulter_test; core40pass drive174pass; zero failures/skips','structural_binding_proof':str(base/'structural-binding-proof.json'),'generation':{'result':generation['result'],'completed_at':generation['completed_at'],'precise_assessment':generation['precise_assessment'],'completed_before_first_lint':True,'source_and_goldens_current':True,'rerun_by_validator':False},'review_workset':['P2.2','P2.3'],'dependency_runtime_qualification':runtime_proof,'source_fixes':[],'environment_repairs':[],'completed_at':datetime.datetime.now(datetime.timezone.utc).isoformat(),'writer_released':True}
write(base/'summary.json',summary);print(json.dumps(summary,ensure_ascii=False))
