import json,sys,hashlib
from pathlib import Path
task=Path('/home/vostrikov/projects/packages/swissknifeman/packages/task'); sys.path.insert(0,str(task/'lib')); sys.path.insert(0,str(task.parent.parent))
from task import review_packet,file_manifest
base=Path(sys.argv[1]); root=Path.cwd(); result=json.loads((base/'affected-result.json').read_text()); receipt=json.loads(Path(result['receipt']).read_text())
paths=json.loads((base/'candidate-paths.json').read_text()); members=review_packet._members(root,file_manifest.expand(root,paths,base='HEAD',required=True)); digest=hashlib.sha256(review_packet._json(members)).hexdigest()
if not receipt['complete'] or not receipt['candidate_fresh'] or digest!=receipt['candidate_sha256']: raise SystemExit('Affected receipt missing/current candidate changed')
ids=['core-pint','core-phpstan','drive-pint','drive-phpstan']
for id in ids:
    if receipt['results'][id]!={'exit_code':0,'status':'green'}: raise SystemExit('Affected gate red: '+id)
print(json.dumps({'candidate_sha256':digest,'reused':ids,'receipt':result['receipt']}))
