from common import *
base=Path(sys.argv[1]);result=read(base/'affected-result.json');receipt=read(result['receipt']);current=digest(members(read(base/'candidate-paths.json')))
assert receipt['complete'] and receipt['candidate_fresh'] and current==receipt['candidate_sha256']
ids=[p+'-'+t for p in ['core','drive','documents','filament'] for t in ['pint','phpstan']]
for name in ids:assert receipt['results'][name].get('exit_code')==0 and receipt['results'][name].get('status')=='green',name
print(json.dumps({'candidate_sha256':current,'reused':ids,'receipt':result['receipt']}))
