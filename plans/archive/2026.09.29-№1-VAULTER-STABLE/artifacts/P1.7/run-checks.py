from pathlib import Path
import subprocess, os, json, datetime, sys
root=Path(__file__).resolve().parents[4]
art=Path(__file__).resolve().parent
pg=dict(os.environ, DB_CONNECTION='pgsql',DB_HOST='127.0.0.1',DB_PORT='5432',DB_DATABASE='vaulter_test',DB_USERNAME='vaulter',DB_PASSWORD='secret')
my=dict(pg, DB_CONNECTION='mysql',DB_PORT='3306')
sq=dict(os.environ, DB_CONNECTION='sqlite',DB_DATABASE=':memory:')
records=[]
def run(name, cmd, env=sq, cwd=root):
 with (art/(name+'.log')).open('w') as out:
  r=subprocess.run(cmd,cwd=cwd,env=env,stdout=out,stderr=subprocess.STDOUT)
 entry=dict(name=name,command=cmd,cwd=str(cwd),exit_code=r.returncode,log=str((art/(name+'.log')).relative_to(root)),executed_at=datetime.datetime.now(datetime.timezone.utc).isoformat())
 records.append(entry);(art/'checks.json').write_text(json.dumps(records,indent=2)+'\n')
 print(name,r.returncode,flush=True)
 return r.returncode
php=str(root/'scripts/php84')
if sys.argv[1]=='targeted':
 run('pgsql-final',[php,'vendor/bin/pest','--ci','packages/core/tests/IdStrategy/Ulid','packages/core/tests/IdStrategy/Bigint','packages/core/tests/Schema/HostKeyColumnsSchemaTest.php','packages/core/tests/Upgrade'],pg)
 run('mysql-final',[php,'vendor/bin/pest','--ci','packages/core/tests/IdStrategy/Ulid/HostKeysTest.php','packages/core/tests/Schema/HostKeyColumnsSchemaTest.php'],my)
 run('mysql-ddl-final',[php,'vendor/bin/pest','--ci','packages/core/tests/HostKeyMysql'],my)
 builder=root/'packages/core/src/Schema/PanelTablesBuilder.php';original=builder.read_bytes()
 try:
  s=original.decode();old="HostKeyColumns::add(table: $table, column: 'subject_id', nullable: false, index: true);";assert old in s
  builder.write_text(s.replace(old,"$ids->reference(table: $table, column: 'subject_id');"))
  rc=run('v23-negative',[php,'vendor/bin/pest','--ci','packages/core/tests/IdStrategy/Ulid/HostKeysTest.php'],pg)
  assert rc!=0, 'Negative control did not fail'
 finally: builder.write_bytes(original)
 run('v23-restored',[php,'vendor/bin/pest','--ci','packages/core/tests/IdStrategy/Ulid/HostKeysTest.php'],pg)
else:
 run('core-pint',[php,'vendor/bin/pint','--test','packages/core'])
 run('core-phpstan',[php,'vendor/bin/phpstan','analyse','-c','phpstan.neon','--memory-limit=1G','--no-progress'])
 run('core-pest',[php,'vendor/bin/pest','--ci','packages/core/tests'])
 for pkg in ['drive','documents','media']:
  cwd=root/'packages'/pkg
  run(pkg+'-pint',[php,'vendor/bin/pint','--test'],cwd=cwd)
  run(pkg+'-phpstan',[php,'vendor/bin/phpstan','analyse','--no-progress','--memory-limit=512M'],cwd=cwd)
  run(pkg+'-pest',[php,'vendor/bin/pest','--ci'],cwd=cwd)
(art/('targeted-results.json' if sys.argv[1]=='targeted' else 'gate-results.json')).write_text(json.dumps(records,indent=2)+'\n')
