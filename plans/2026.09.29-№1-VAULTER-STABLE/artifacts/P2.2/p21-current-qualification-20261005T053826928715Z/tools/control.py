import datetime, json, subprocess, sys
from pathlib import Path
base=Path(sys.argv[1]); root=Path.cwd(); started=list((base/'canonical').glob('*/p21-current-qualification-01/started.json')); assert len(started)==1
candidate=json.loads(started[0].read_text())['candidate_sha256']
rows=[]
for label,key in [('negative','Preview'),('restored','test/preview')]:
 argv=[str(root/'scripts/php84'),'php',str(base/'tools/handler-control.php'),key]
 before=datetime.datetime.now(datetime.timezone.utc).isoformat(); log=base/'control'/f'{label}.log'
 with log.open('xb') as output: result=subprocess.run(argv,stdout=output,stderr=subprocess.STDOUT)
 after=datetime.datetime.now(datetime.timezone.utc).isoformat(); content=log.read_text()
 valid=(result.returncode==255 and 'Uncaught InvalidArgumentException: File handler key must have the format vendor/name.' in content and 'checkedInstance' in content) if label=='negative' else (result.returncode==0 and content.strip()=='registered test/preview')
 row={'control':label,'key':key,'argv':argv,'candidate_sha256':candidate,'started_at':before,'finished_at':after,'exit_code':result.returncode,'log':str(log),'expected_red_witness':label=='negative','expected_result_valid':valid}; rows.append(row)
 (base/'control'/f'{label}.json').open('x').write(json.dumps(row,indent=2)+'\n')
 print(json.dumps(row),flush=True)
 if not valid: sys.exit(1)
(base/'control/control-summary.json').open('x').write(json.dumps({'negative_is_expected_RED_not_passed_test':True,'controls':rows},indent=2)+'\n')
