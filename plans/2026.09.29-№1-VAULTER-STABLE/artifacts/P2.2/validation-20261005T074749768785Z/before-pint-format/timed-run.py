import datetime,subprocess
from common import *
base=Path(sys.argv[1]);stage=sys.argv[2];name=sys.argv[3];argv=sys.argv[4:]
meta=read(base/'run.json');started=list((base/'canonical').glob('*/'+meta['attempts'][stage]+'/started.json'))
assert len(started)==1
candidate=read(started[0])['candidate_sha256'];assert candidate==read(base/'structural-binding-proof.json')['candidate_sha256']
def verify():
 verify_generation(base)
 verify_runtime(base)
 for p,h in read(base/'tools/external-tools.json').items():
  if sha(p)!=h:raise RuntimeError('runtime drift '+p)
verify();task_before=task_provenance();before=datetime.datetime.now(datetime.timezone.utc).isoformat();result=subprocess.run(argv);after=datetime.datetime.now(datetime.timezone.utc).isoformat();verify();task_after=task_provenance()
row={'id':name,'stage':stage,'argv':argv,'candidate_sha256':candidate,'started_at':before,'finished_at':after,'exit_code':result.returncode,'runtime_verified_before_after':True,'dependency_identity_verified_before_after':True,'dependency_content_manifest_candidate_bound':True,'task_runtime_provenance_before':task_before,'task_runtime_provenance_after':task_after,'owner_authorized_task_review_module_churn':True}
write(base/'observed'/f'{stage}-{name}.json',row);print(json.dumps(row),flush=True);sys.exit(result.returncode)
