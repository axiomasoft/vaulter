import os,fcntl,subprocess
from common import *
base=Path(sys.argv[1]);meta=read(base/'run.json');paths=read(base/'candidate-paths.json');verify_generation(base)
with (base/'writer.lock').open('a') as lock:
 fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
 for stage in ['affected','full']:
  argv=['python3',str(TASK/'scripts/plan-work.py'),'validate-evidence','--plan-dir',str(PLAN),'--checks',str(base/(stage+'-checks.json')),'--attempt-id',meta['attempts'][stage],'--output-dir',str(base/'canonical')]
  for p in paths:argv+=['--path',p]
  write(base/(stage+'-command.json'),argv)
  with (base/(stage+'-result.json')).open('x') as result,(base/(stage+'-runner.log')).open('x') as errors:
   process=subprocess.run(argv,cwd=ROOT,stdout=result,stderr=errors,env={**os.environ,'PYTHONPATH':str(TASK/'lib')+os.pathsep+str(TASK.parent.parent),'PYTHONDONTWRITEBYTECODE':'1','PHP84_BIN':'/usr/bin/php8.4'})
  if process.returncode:raise SystemExit(process.returncode)
  result=read(base/(stage+'-result.json'));print(stage+' '+json.dumps(result),flush=True)
  if not result['complete']:raise SystemExit(1)
print('both canonical DAGs complete',flush=True)
