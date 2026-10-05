import json,subprocess,sys,os
from pathlib import Path
base=Path(sys.argv[1]); paths=json.loads((base/'candidate-paths.json').read_text()); root=Path.cwd(); task=Path('/home/vostrikov/projects/packages/swissknifeman/packages/task'); plan=root/'plans/2026.09.29-№1-VAULTER-STABLE'
for stage in ['affected','full']:
    argv=['python3',str(task/'scripts/plan-work.py'),'validate-evidence','--plan-dir',str(plan),'--checks',str(base/(stage+'-checks.json')),'--attempt-id','p22-'+stage+'-01','--output-dir',str(base/'canonical')]
    for path in paths: argv+=['--path',path]
    (base/(stage+'-command.json')).write_text(json.dumps(argv,ensure_ascii=False,indent=2)+'\n')
    with (base/(stage+'-result.json')).open('x') as result, (base/(stage+'-runner.log')).open('x') as errors:
        process=subprocess.run(argv,stdout=result,stderr=errors)
    if process.returncode: print(stage+' runner failed '+str(process.returncode),flush=True); sys.exit(process.returncode)
    result=json.loads((base/(stage+'-result.json')).read_text()); print(stage+' '+json.dumps(result),flush=True)
    if not result['complete']: sys.exit(1)
print('both canonical DAGs complete',flush=True)
