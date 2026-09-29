import datetime,hashlib,json,os,subprocess,uuid
from pathlib import Path
root=Path.cwd(); run_id=str(uuid.uuid4()); out=root/'plans/VLT-LOAD/artifacts/R04'/run_id; out.mkdir(parents=True)
commands=[]
def capture(label,argv,env=None,expected=None):
 p=subprocess.run(argv,cwd=root,capture_output=True,text=True,env=env)
 (out/(label+'.stdout')).write_text(p.stdout); (out/(label+'.stderr')).write_text(p.stderr)
 record={'label':label,'argv':argv,'exit':p.returncode}
 if expected: record['interpretation']=expected
 commands.append(record)
 return p
revision=capture('source-revision',['git','rev-parse','HEAD']).stdout.strip()
source_digest=capture('source-digest',['python3','-B','load/gates/r03_lab.py','--source-digest']).stdout.strip()
capture('r04-focused',['python3','-B','-m','unittest','discover','-s','load/gates','-p','test_r04_*.py'])
capture('p3-focused',['python3','-B','-m','unittest','discover','-s','load/gates','-p','test_p3_*.py'])
capture('shell-syntax',['bash','-n','load/scripts/p3-profile.sh','load/scripts/p3-capture-workload.sh','load/scripts/p3-explain-capture.sh'])
env={**os.environ,'DB_CONNECTION':'sqlite','DB_DATABASE':':memory:'}
capture('php-trace',['composer','test','--','load/tests/Observability/QueryTraceRecorderTest.php','--no-coverage'],env=env)
capture('compose-dry-run',['bash','load/scripts/p3-profile.sh','prepare','--dry-run'],env={**os.environ,'TIER':'S'})
capture('p8-prerequisite',['python3','-B','load/gates/p3_workload.py','preflight'])
capture('historical-p3-verification',['python3','-B','load/gates/p3_workload.py','verify-artifacts','--artifact-dir','plans/VLT-LOAD/artifacts/P3'],expected='Historical invalid trace must remain rejected; this is not a failed new capture.')
capture('historical-trace-report',['python3','-B','load/gates/p3_trace_report.py','--artifact-dir','plans/VLT-LOAD/artifacts/P3','--output',str(out/'historical-trace-analysis.json')],expected='Historical empty trace must not produce an N+1 report.')
capture('load-containers',['docker','ps','--filter','label=com.docker.compose.project=vaulter-load','--format','{{.Names}} {{.Status}}'])
old=root/'plans/VLT-LOAD/artifacts/P3'
reference_files=['run-manifest.json','query-trace.jsonl','pgss-top20.json']
references={name:hashlib.sha256((old/name).read_bytes()).hexdigest() for name in reference_files}
manifest={'run_id':run_id,'item':'R04','step':'17','kind':'readiness','source_revision':revision,'source_digest':source_digest,'created_at':datetime.datetime.now(datetime.timezone.utc).isoformat(),'status':'READINESS_ONLY','live_started':False,'product_proof':'unevaluated','historical_p3_files':references,'historical_trace_bytes':(old/'query-trace.jsonl').stat().st_size,'dependency':'R03 16c owner-deferred; no valid current-source qualified evidence','owner_clarification':'pending: diagnostic separate run vs readiness only'}
(out/'commands.json').write_text(json.dumps(commands,indent=2)+'\n')
(out/'run-manifest.json').write_text(json.dumps(manifest,indent=2,sort_keys=True)+'\n')
(out/'readiness-capture.py').write_bytes(Path('/tmp/vaulter-r04-readiness.py').read_bytes())
hashes={str(p.relative_to(out)):hashlib.sha256(p.read_bytes()).hexdigest() for p in out.rglob('*') if p.is_file()}
(out/'sha256.json').write_text(json.dumps(hashes,indent=2,sort_keys=True)+'\n')
print(json.dumps({'directory':str(out),'commands':commands,'manifest':manifest},indent=2))
