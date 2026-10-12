from pathlib import Path
import datetime,hashlib,json,re,sys
base=Path(sys.argv[1]);receipts={};results={};counts={}
for stage in ['affected','full']:
 result=json.loads((base/(stage+'-result.json')).read_text());receipt=json.loads(Path(result['receipt']).read_text());receipts[stage]=result['receipt'];results.update(receipt['results'])
 if not receipt['complete'] or not receipt['candidate_fresh']:raise SystemExit(stage+' incomplete')
 for name,row in receipt['results'].items():
  text=(Path(result['receipt']).parent/(name+'.log')).read_text()
  for line in text.splitlines():
   try:data=json.loads(line)
   except ValueError:continue
   if data.get('tool')=='pest':counts[name]={**data,'failed':data.get('failed',0),'skipped':data.get('skipped',0),'risky':data.get('risky',0)}
  plain=re.sub(r'\x1b\[[0-9;]*m','',text);match=re.search(r'Tests:\s+([^\n]+)',plain)
  if match:
   status={k:int(v) for v,k in re.findall(r'(\d+) (passed|failed|skipped|risky)',match.group(1))};a=re.search(r'\((\d+) assertions\)',match.group(1));counts[name]={'failed':0,'skipped':0,'risky':0,**status,'assertions':int(a.group(1)) if a else None,'summary':match.group(0)}
assert counts['core-p1-pg']['passed']==40 and counts['drive-p1-pg']['passed']==174
for n in ['core-p1-pg','drive-p1-pg']:assert counts[n]['failed']==0 and counts[n]['skipped']==0
proof=json.loads((base/'structural-binding-proof.json').read_text());assert proof['candidate_sha256']==receipt['candidate_sha256'] and not proof['issues']
summary={'candidate_sha256':receipt['candidate_sha256'],'candidate_fresh':True,'affected_complete':True,'full_complete':True,'green_gates':sum(v['status']=='green' for v in results.values()),'failed_gates':sum(v['status']=='red' for v in results.values()),'dependency_skipped_gates':[k for k,v in results.items() if v['status']!='green'],'receipts':receipts,'counts':counts,'postgres_status':'exact isolated vaulter_test; core40pass and drive174pass; 0failed0skipped','structural_binding_proof':str(base/'structural-binding-proof.json'),'generation':{'run_by_validator':False,'reason':'Validation did not rerun generation; existing completed generation qualification will be bound by root after comparing actual source/golden hashes. Full core/drive golden tests completed.'},'source_fixes':[],'environment_repairs':[],'completed_at':datetime.datetime.now(datetime.timezone.utc).isoformat(),'writer_released':True}
(base/'summary.json').open('x').write(json.dumps(summary,ensure_ascii=False,indent=2)+'\n');print(json.dumps(summary,ensure_ascii=False))
