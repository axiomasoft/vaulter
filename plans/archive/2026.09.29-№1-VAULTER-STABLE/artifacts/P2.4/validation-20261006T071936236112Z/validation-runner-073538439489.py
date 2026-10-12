import os,json,hashlib,subprocess,datetime,pathlib,re,sys
ROOT=pathlib.Path('/home/vostrikov/projects/packages/vaulter'); PLAN=ROOT/'plans/2026.09.29-№1-VAULTER-STABLE'
STAMP=datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%S%fZ')
OUT=pathlib.Path('/tmp/vaulter-p24-validation-dir').read_text() if os.environ.get('VALIDATION_REUSE') else PLAN/'artifacts/P2.4'/('validation-'+STAMP)
OUT=pathlib.Path(OUT)
if not os.environ.get('VALIDATION_REUSE'):
    OUT.mkdir(parents=True,exist_ok=False)
    pathlib.Path('/tmp/vaulter-p24-validation-dir').write_text(str(OUT))
def sha(b):return hashlib.sha256(b).hexdigest()
def manifest():
    names=subprocess.check_output(['git','ls-files','-z','--cached','--others','--exclude-standard'],cwd=ROOT).decode().split('\0')
    excluded=('plans/','.agents/','.codex/','.maind/','.codegraph/')
    rows=[]
    for name in sorted(set(names)):
        if not name or name.startswith(excluded):continue
        p=ROOT/name
        if p.is_symlink():rows.append({'path':name,'kind':'symlink','target':os.readlink(p),'sha256':sha(p.read_bytes()) if p.exists() and p.is_file() else None})
        elif p.is_file():rows.append({'path':name,'sha256':sha(p.read_bytes())})
        else:rows.append({'path':name,'deleted':True})
    for base in [ROOT]+[ROOT/'packages'/n for n in ['azgard','media','filament','drive','documents']]:
        for rel in ['vendor/composer/installed.json','vendor/composer/autoload_psr4.php','vendor/composer/autoload_classmap.php']:
            p=base/rel
            if p.exists():rows.append({'path':str(p.relative_to(ROOT)),'sha256':sha(p.read_bytes())})
        vp=base/'vendor/axioma-studio'
        if vp.exists():
            for p in sorted(vp.iterdir()):
                if p.is_symlink():rows.append({'path':str(p.relative_to(ROOT)),'kind':'dependency-symlink','target':os.readlink(p),'resolved':str(p.resolve())})
    return {'sha256':sha(json.dumps(rows,sort_keys=True,separators=(',',':')).encode()),'files':rows}
def norms():
    names=['phases/P2/P2.4.json','decisions/D23.json','knowledge/artifacts-P2-design-P2.4-opus.json','phases/P2/phase.json','artifacts/P2.4/rename-map.php']
    return [{'path':str(PLAN/n),'sha256':sha((PLAN/n).read_bytes())} for n in names]
def run(label,argv,cwd=ROOT,pgsql=False,extra=None,expected=0,mutable=False):
    before=manifest(); env=os.environ.copy();env.update({'APP_ENV':'testing','DB_CONNECTION':'pgsql' if pgsql else 'sqlite','VAULTER_TEST_DB_CONNECTION':'pgsql' if pgsql else 'sqlite','DB_DATABASE':'vaulter_test' if pgsql else ':memory:','CACHE_STORE':'array','SESSION_DRIVER':'array','QUEUE_CONNECTION':'sync'})
    if pgsql:env.update({'DB_HOST':'127.0.0.1','DB_PORT':'5432','DB_USERNAME':'vaulter','DB_PASSWORD':env.get('DB_PASSWORD','secret')})
    if extra:env.update(extra)
    prefix=OUT/(datetime.datetime.now(datetime.timezone.utc).strftime('%H%M%S%f')+'-'+label)
    prefix.with_suffix('.candidate-before.json').write_text(json.dumps(before,indent=2))
    started=datetime.datetime.now(datetime.timezone.utc).isoformat()
    with prefix.with_suffix('.log').open('w') as log:
        proc=subprocess.run(argv,cwd=cwd,env=env,stdout=log,stderr=subprocess.STDOUT)
    after=manifest();prefix.with_suffix('.candidate-after.json').write_text(json.dumps(after,indent=2))
    output=prefix.with_suffix('.log').read_text(errors='replace'); totals=re.findall(r'Tests:\s*([^\n]+)|(?:FAIL|PASS)\s*([^\n]*)',re.sub(r'\x1b\[[0-9;]*m','',output))
    pao=[]
    for line in output.splitlines():
        try:
            row=json.loads(line)
            if isinstance(row,dict) and row.get('tool'):pao.append(row)
        except json.JSONDecodeError:pass
    result={'pao':pao,'label':label,'argv':argv,'cwd':str(cwd),'started':started,'finished':datetime.datetime.now(datetime.timezone.utc).isoformat(),'exit_code':proc.returncode,'expected_exit':expected,'candidate_before':before['sha256'],'candidate_after':after['sha256'],'normative_inputs':norms(),'log':str(prefix.with_suffix('.log')),'totals':totals[-8:],'environment':{k:v for k,v in env.items() if k in ['APP_ENV','DB_CONNECTION','DB_DATABASE','VAULTER_TEST_DB_CONNECTION','DB_HOST','DB_PORT','DB_USERNAME','UPDATE_SNAPSHOT']}}
    prefix.with_suffix('.json').write_text(json.dumps(result,indent=2));print(json.dumps({k:result[k] for k in ['label','exit_code','candidate_after','pao','totals','log']}),flush=True)
    if proc.returncode!=expected or (not mutable and before['sha256']!=after['sha256']):raise SystemExit(1)
    return result
PHP=str(ROOT/'scripts/php84')
if __name__=='__main__':
    run('generation-core-public-api',[PHP,'vendor/bin/pest','--ci','packages/core/tests/Arch/PublicApiSnapshotTest.php'],extra={'UPDATE_SNAPSHOT':'1'},mutable=True)
    m=manifest();(OUT/'stable-candidate.json').write_text(json.dumps(m,indent=2));print('STABLE_CANDIDATE '+m['sha256'],flush=True)
