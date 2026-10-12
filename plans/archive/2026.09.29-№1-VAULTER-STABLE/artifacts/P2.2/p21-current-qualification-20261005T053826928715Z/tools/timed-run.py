import datetime, hashlib, json, subprocess, sys
from pathlib import Path
base=Path(sys.argv[1]); name=sys.argv[2]; argv=sys.argv[3:]; started=list((base/'canonical').glob('*/p21-current-qualification-01/started.json')); assert len(started)==1
candidate=json.loads(started[0].read_text())['candidate_sha256']; inventory=json.loads((base/'tools/runtime-inventory.json').read_text())
def verify():
 for path,sha in inventory.items():
  if hashlib.sha256(Path(path).read_bytes()).hexdigest()!=sha: raise RuntimeError('runtime drift '+path)
verify(); before=datetime.datetime.now(datetime.timezone.utc).isoformat(); result=subprocess.run(argv); after=datetime.datetime.now(datetime.timezone.utc).isoformat(); verify()
row={'id':name,'argv':argv,'candidate_sha256':candidate,'started_at':before,'finished_at':after,'exit_code':result.returncode,'runtime_verified_before_after':True}
(base/'observed'/f'{name}.json').open('x').write(json.dumps(row,indent=2)+'\n'); print(json.dumps(row),flush=True); sys.exit(result.returncode)
