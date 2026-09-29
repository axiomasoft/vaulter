import pathlib,subprocess,os,json,datetime,time,shutil
ROOT=pathlib.Path('/home/vostrikov/projects/packages/vaulter')
STATE=json.loads(pathlib.Path('/tmp/vaulter-r03-active.json').read_text())
OUT=pathlib.Path(STATE['artifact_directory'])
ENV={**os.environ,'TIER':'S','LOAD_APP_PORT':'8183','COMPOSE_PROJECT_NAME':STATE['project']}
COMPOSE=['docker','compose','-p',STATE['project'],'-f',str(ROOT/'load/docker-compose.load.yml'),'-f',str(OUT/'compose.override.yml'),'--env-file',str(ROOT/'load/env/S.env')]
def run(name,args,env=None,check=True,input=None):
    start=datetime.datetime.now(datetime.timezone.utc).isoformat();clock=time.monotonic()
    with (OUT/(name+'.stdout')).open('w') as stdout,(OUT/(name+'.stderr')).open('w') as stderr:
        p=subprocess.run(args,cwd=ROOT,env={**ENV,**(env or {})},input=input,text=True,stdout=stdout,stderr=stderr)
    record={'name':name,'argv':args,'env_overrides':env or {},'started_at':start,'elapsed_seconds':time.monotonic()-clock,'exit':p.returncode}
    with (OUT/'commands.jsonl').open('a') as f:f.write(json.dumps(record)+'\n')
    print(json.dumps(record),flush=True)
    if check and p.returncode:raise RuntimeError(name+' failed: '+(OUT/(name+'.stderr')).read_text()[-2000:])
    return p.returncode
