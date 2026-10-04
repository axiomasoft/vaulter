import json, os, subprocess, time
from pathlib import Path
root=Path(__file__).resolve().parents[4];out=Path(__file__).resolve().parent
sq=os.environ|dict(DB_CONNECTION='sqlite',DB_DATABASE=':memory:',APP_ENV='testing')
pg=os.environ|dict(DB_CONNECTION='pgsql',DB_HOST='127.0.0.1',DB_PORT='5432',DB_DATABASE='vaulter_test',DB_USERNAME='vaulter',DB_PASSWORD='secret',APP_ENV='testing')
php=str(root/'scripts/php84')
checks=[('core-models-pint',root,sq,[php,'vendor/bin/pint','--test','packages/core/tests/Database/VaulterDatabaseTest.php']),('core-models-sqlite',root,sq,[php,'vendor/bin/pest','--ci','packages/core/tests/Database','packages/core/tests/Arch'])]
for pkg,files in [('drive',['src/Models/Uploads/UploadSession.php','src/Models/Uploads/UploadPart.php']),('documents',['src/Models/Comments/NodeComment.php','src/Models/Versions/NodeDocumentVersion.php'])]:
 checks += [(pkg+'-models-pint',root/'packages'/pkg,sq,[php,'vendor/bin/pint','--test',*files]),(pkg+'-models-phpstan',root/'packages'/pkg,sq,[php,'vendor/bin/phpstan','analyse','--no-progress','--memory-limit=512M']),(pkg+'-models-pest',root/'packages'/pkg,sq,[php,'vendor/bin/pest','--ci'])]
checks += [('drive-models-pgsql',root/'packages/drive',pg,[php,'vendor/bin/pest','--ci','tests/Uploads','tests/Concurrency'])]
receipts=[]
for name,cwd,env,cmd in checks:
 start=time.monotonic()
 with (out/(name+'.log')).open('w') as log:r=subprocess.run(cmd,cwd=cwd,env=env,stdout=log,stderr=subprocess.STDOUT)
 receipts.append(dict(name=name,command=cmd,exit=r.returncode,duration_s=round(time.monotonic()-start,2),log=name+'.log',driver=env['DB_CONNECTION']))
 (out/'model-validation.json').write_text(json.dumps(receipts,indent=2)+'\n')
 print(name,r.returncode,flush=True)
