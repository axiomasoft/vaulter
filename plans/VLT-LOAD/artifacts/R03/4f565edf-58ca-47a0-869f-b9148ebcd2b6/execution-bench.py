from vaulter_r03_control import *
import urllib.request,hashlib,threading,gzip

def compose(tier):
    c=COMPOSE.copy();c[c.index(str(OUT/'compose.override.yml'))]=str(OUT/('compose-'+tier+'.yml'));c[-1]=str(ROOT/('load/env/'+tier+'.env'));return c

def request(path,data=None):
    req=urllib.request.Request('http://127.0.0.1:8183'+path,data=json.dumps(data).encode() if data is not None else None,headers={'Content-Type':'application/json'})
    with urllib.request.urlopen(req,timeout=30) as response:return response.read().decode()

def pg(name,sql,tier):
    run(name,['docker','exec',STATE['project']+'-postgres-1','psql','-U','vaulter','-d',STATE['database_'+tier.lower()],'-At','-c',sql])

def snapshot(name,tier):
    pg(name+'-pg',"SELECT json_build_object('wal',(SELECT row_to_json(w) FROM pg_stat_wal w),'db',(SELECT row_to_json(d) FROM pg_stat_database d WHERE datname=current_database()),'statements',(SELECT json_agg(s) FROM (SELECT query,calls,total_exec_time,rows,shared_blks_read,shared_blks_hit,blk_read_time,blk_write_time,wal_bytes FROM pg_stat_statements WHERE dbid=(SELECT oid FROM pg_database WHERE datname=current_database()) ORDER BY total_exec_time DESC LIMIT 30)s));",tier)
    (OUT/(name+'-metrics.txt')).write_text(request('/metrics'))
    run(name+'-disk',['df','-B1',str(ROOT)])

def mixed(tier,duration=180):
    cid=str(__import__('uuid').uuid4());prefix=tier.lower()+'-mixed-'+cid[:8]
    meta={'run_id':cid,'campaign_id':STATE['run_id'],'tier':tier,'declared_duration_seconds':duration,'profile':'lab' if tier=='S' else 'smoke-perf','fixture_single_bytes':5242880,'lab_admitted':False,'environment_limitations':['governor powersave','turbo enabled','host swap enabled','other owner containers running; no authorization to clear them']}
    manifests=sorted((ROOT/'load/seed/out').glob('vlt-load-*.json'),key=lambda p:p.stat().st_mtime)
    manifest=next(p for p in reversed(manifests) if json.loads(p.read_text())['tier']==tier)
    shutil.copy2(manifest,OUT/(prefix+'-seed-manifest.json'));shutil.copy2(manifest,ROOT/'load/k6/dataset.json');shutil.copy2(ROOT/'load/gates/thresholds.js',ROOT/'load/k6/lib/thresholds.js')
    data=json.loads(manifest.read_text());fingerprint=hashlib.sha256((ROOT/('load/env/'+tier+'.env')).read_bytes()+('postgres:16-alpine minio/minio:latest php:8.4-fpm-alpine grafana/k6:0.55.0 '+data['dataset_id']).encode()).hexdigest()[:16]
    meta['env_fingerprint']=fingerprint
    (OUT/(prefix+'-run-manifest.json')).write_text(json.dumps({**meta,'state':'preparing','product_run_started':False},indent=2)+'\n')
    # Use the existing run.sh wrapper verbatim, preserving all threshold values.
    wrapper=(ROOT/'load/scripts/run.sh').read_text().split("<<'JS'\n",1)[1].split('\nJS\n',1)[0]
    (OUT/(prefix+'-wrapper.js')).write_text(wrapper+'\n')
    (OUT/(prefix+'-metrics-open.json')).write_text(request('/metrics/run/open',{'run_id':cid,'ttl_seconds':7200}))
    snapshot(prefix+'-before',tier)
    c=compose(tier)
    writer_args=c+['exec','-T','app','php','vendor/bin/testbench','load:activity-write','--tier='+tier,'--duration='+str(duration+45)]
    writer_out=(OUT/(prefix+'-activity.stdout')).open('w');writer_err=(OUT/(prefix+'-activity.stderr')).open('w')
    writer_start=time.monotonic();writer=subprocess.Popen(writer_args,cwd=ROOT,env={**ENV,'TIER':tier},stdout=writer_out,stderr=writer_err)
    kargs=c+['--profile','bench','run','--name',STATE['project']+'-'+prefix,'-T','--no-deps','--workdir','/scripts','-e','ENV_FINGERPRINT='+fingerprint,'-e','GIT_COMMIT_SHA='+subprocess.check_output(['git','rev-parse','--short','HEAD'],cwd=ROOT).decode().strip(),'-e','LOAD_PROFILE='+meta['profile'],'-e','TIER='+tier,'-e','DURATION='+str(duration)+'s','-e','SINGLE_SHOT_FILE_SIZE=5242880','-e','GOMEMLIMIT='+('768MiB' if tier=='S0' else '1536MiB'),'-e','GOGC=50','k6','run','--out','experimental-prometheus-rw','-']
    (ROOT/'load/results').chmod(0o777)
    with (OUT/(prefix+'.stdout')).open('w') as stdout,(OUT/(prefix+'.stderr')).open('w') as stderr:
        began=time.monotonic();p=subprocess.Popen(kargs,cwd=ROOT,env={**ENV,'TIER':tier},stdin=subprocess.PIPE,stdout=stdout,stderr=stderr,text=True);p.stdin.write(wrapper);p.stdin.close()
        with gzip.open(OUT/(prefix+'-resources.jsonl.gz'),'wt') as samples:
            while p.poll() is None:
                row={'utc':datetime.datetime.now(datetime.timezone.utc).isoformat(),'elapsed_s':time.monotonic()-began,'free_bytes':shutil.disk_usage(ROOT).free,'loadavg':pathlib.Path('/proc/loadavg').read_text().strip(),'services':{}}
                for service in ['app','postgres','minio','pgbouncer']:
                    cp=subprocess.run(['docker','inspect','--format','{{.State.Pid}}',STATE['project']+'-'+service+'-1'],capture_output=True,text=True)
                    try:
                        pid=int(cp.stdout);cg=pathlib.Path('/proc/'+str(pid)+'/cgroup').read_text().split('0::')[1].strip();base=pathlib.Path('/sys/fs/cgroup')/cg.lstrip('/')
                        row['services'][service]={key:(base/key).read_text().strip() for key in ['cpu.stat','io.stat','memory.current','memory.stat']}
                        row['services'][service]['net_dev']=pathlib.Path('/proc/'+str(pid)+'/net/dev').read_text()
                    except (ValueError,FileNotFoundError,IndexError) as e:row['services'][service]={'error':str(e)}
                q=subprocess.run(['docker','exec',STATE['project']+'-postgres-1','psql','-U','vaulter','-d',STATE['database_'+tier.lower()],'-At','-c',"SELECT coalesce(json_agg(a),'[]'::json) FROM (SELECT state,wait_event_type,wait_event,extract(epoch from (clock_timestamp()-query_start)) AS query_seconds FROM pg_stat_activity WHERE datname=current_database() AND pid<>pg_backend_pid() AND state='active')a;"],capture_output=True,text=True)
                row['postgres_activity']=q.stdout.strip();row['postgres_sample_exit']=q.returncode
                samples.write(json.dumps(row)+'\n');samples.flush()
                if row['free_bytes']<8*1024**3:
                    meta['disk_guard_triggered']=True;p.terminate();break
                time.sleep(2)
        code=p.wait(timeout=60)
    meta['k6_exit']=code;meta['elapsed_wall_seconds']=time.monotonic()-began
    meta['state']='k6-completed';meta['product_run_started']=True
    (OUT/(prefix+'-run-manifest.json')).write_text(json.dumps(meta,indent=2)+'\n')
    run(prefix+'-container-inspect',['docker','inspect',STATE['project']+'-'+prefix],check=False)
    with (OUT/'commands.jsonl').open('a') as f:f.write(json.dumps({'name':prefix,'argv':kargs,'exit':code,'tier':tier,'duration_seconds':duration})+'\n')
    summary=ROOT/'load/results/summary.json';shutil.copy2(summary,OUT/(prefix+'-summary.json'))
    actual=json.loads(summary.read_text())
    if actual['meta']['dataset_id']!=data['dataset_id'] or actual['meta']['tier']!=tier or actual['meta']['env_fingerprint']!=fingerprint:
        meta['summary_rejected']='wrong identity; stale summary is not product evidence'
        (OUT/(prefix+'-run-manifest.json')).write_text(json.dumps(meta,indent=2)+'\n')
        writer.wait(timeout=240);writer_out.close();writer_err.close()
        raise RuntimeError(meta['summary_rejected'])
    snapshot(prefix+'-after',tier)
    (OUT/(prefix+'-metrics-finalize.json')).write_text(request('/metrics/run/finalize',{'run_id':cid}))
    writer.wait(timeout=60);writer_out.close();writer_err.close()
    meta['activity_exit']=writer.returncode;meta['activity_wall_seconds']=time.monotonic()-writer_start
    run(prefix+'-summary-audit',['python3','load/gates/r03_summary.py','--summary',str(OUT/(prefix+'-summary.json')),'--declared-duration',str(duration)+'s'],check=False)
    with (OUT/'commands.jsonl').open('a') as f:
        for name,args,exit_code in [(prefix,kargs,code),(prefix+'-activity',writer_args,writer.returncode)]:f.write(json.dumps({'name':name,'argv':args,'exit':exit_code,'tier':tier,'duration_seconds':duration})+'\n')
    (OUT/(prefix+'-run-manifest.json')).write_text(json.dumps(meta,indent=2)+'\n')
    print(json.dumps({'prefix':prefix,**meta}),flush=True)
    return prefix

if __name__=='__main__':
    import sys;mixed(sys.argv[1],int(sys.argv[2]) if len(sys.argv)>2 else 180)
