import datetime,json,subprocess,sys,hashlib
from pathlib import Path
root=Path.cwd(); base=Path(sys.argv[1]); task=Path('/home/vostrikov/projects/packages/swissknifeman/packages/task'); sys.path.insert(0,str(task/'lib'))
from task import file_manifest,review_packet
paths=json.loads((base/'candidate-paths.json').read_text()); argv=json.loads((base/'command.json').read_text()); plan=root/'plans/2026.09.29-№1-VAULTER-STABLE'
def capture(label):
 members=review_packet._members(root,file_manifest.expand(root,paths,base='HEAD',required=True))
 context={str(p.relative_to(root)):hashlib.sha256(p.read_bytes()).hexdigest() for p in [root/'AGENTS.md',plan/'journal.jsonl',plan/'session-routing.md',plan/'phases/P2/P2.1.md',plan/'artifacts/P2.1/rename-map.php',plan/'artifacts/P2.1/rename-map.json',plan/'artifacts/P2.1/rector-dry-run.txt']}
 row={'captured_at':datetime.datetime.now(datetime.timezone.utc).isoformat(),'head':subprocess.check_output(['git','rev-parse','HEAD'],text=True).strip(),'candidate':members,'candidate_sha256':hashlib.sha256(review_packet._json(members)).hexdigest(),'context_hashes':context,'git_status':subprocess.check_output(['git','status','--porcelain=v1'],text=True),'context_scope':'PRESENT only; no historical completion assertion'}
 (base/f'context-{label}.json').open('x').write(json.dumps(row,ensure_ascii=False,indent=2)+'\n'); return row
before=capture('before')
with (base/'result.json').open('xb') as out,(base/'runner.log').open('xb') as errors: process=subprocess.run(argv,stdout=out,stderr=errors)
after=capture('after')
result=json.loads((base/'result.json').read_text()) if process.returncode==0 else {'runner_exit':process.returncode}
summary={'actual_started_at':before['captured_at'],'actual_finished_at':after['captured_at'],'native':result,'context_unchanged':before['context_hashes']==after['context_hashes'],'candidate_unchanged':before['candidate']==after['candidate'],'p21_overall_green_claim':False,'PG_constraint':'Prior native full core-p1-pg 1 failure; drive-p1-pg dependency-skipped; this supplement executes no PG','coverage':'Supplemental E10, media/azgard part of E11, E13, E14 only'}
(base/'summary.json').open('x').write(json.dumps(summary,ensure_ascii=False,indent=2)+'\n'); print(json.dumps(summary,ensure_ascii=False),flush=True); sys.exit(process.returncode or (0 if result['complete'] else 1))
