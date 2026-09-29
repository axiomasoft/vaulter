from vaulter_r03_bench import *
import uuid,gzip
rid=str(uuid.uuid4());prefix='s-tus-offset-'+rid[:8]
script=(OUT/'tus-offset-diagnostic.js').read_text()
(OUT/(prefix+'-metrics-open.json')).write_text(request('/metrics/run/open',{'run_id':rid,'ttl_seconds':7200}))
snapshot(prefix+'-before','S')
args=compose('S')+['--profile','bench','run','--name',STATE['project']+'-'+prefix,'-T','--no-deps','--workdir','/scripts','-e','TIER=S','-e','GOMEMLIMIT=1536MiB','-e','GOGC=50','-e','R03_SUMMARY_PATH=/results/'+prefix+'.json','k6','run','-']
start=time.monotonic()
with (OUT/(prefix+'.stdout')).open('w') as stdout,(OUT/(prefix+'.stderr')).open('w') as stderr:
    p=subprocess.Popen(args,cwd=ROOT,env=ENV,text=True,stdin=subprocess.PIPE,stdout=stdout,stderr=stderr);p.stdin.write(script);p.stdin.close()
    with gzip.open(OUT/(prefix+'-resources.jsonl.gz'),'wt') as samples:
        while p.poll() is None:
            row={'utc':datetime.datetime.now(datetime.timezone.utc).isoformat(),'elapsed_s':time.monotonic()-start,'free_bytes':shutil.disk_usage(ROOT).free,'services':{}}
            for service in ['app','postgres','minio','pgbouncer']:
                cp=subprocess.run(['docker','inspect','--format','{{.State.Pid}}',STATE['project']+'-'+service+'-1'],capture_output=True,text=True)
                try:
                    pid=int(cp.stdout);cg=pathlib.Path('/proc/'+str(pid)+'/cgroup').read_text().split('0::')[1].strip();base=pathlib.Path('/sys/fs/cgroup')/cg.lstrip('/')
                    row['services'][service]={key:(base/key).read_text().strip() for key in ['cpu.stat','io.stat','memory.current','memory.stat']}
                    row['services'][service]['net_dev']=pathlib.Path('/proc/'+str(pid)+'/net/dev').read_text()
                except (ValueError,FileNotFoundError,IndexError) as e:row['services'][service]={'error':str(e)}
            samples.write(json.dumps(row)+'\n');samples.flush()
            if row['free_bytes']<8*1024**3:p.terminate();break
            time.sleep(1)
    code=p.wait(timeout=60)
shutil.copy2(ROOT/'load/results'/(prefix+'.json'),OUT/(prefix+'-summary.json'))
run(prefix+'-container-inspect',['docker','inspect',STATE['project']+'-'+prefix])
snapshot(prefix+'-after','S')
(OUT/(prefix+'-metrics-finalize.json')).write_text(request('/metrics/run/finalize',{'run_id':rid}))
with (OUT/'commands.jsonl').open('a') as f:f.write(json.dumps({'name':prefix,'argv':args,'exit':code,'elapsed_seconds':time.monotonic()-start})+'\n')
meta={'run_id':rid,'campaign_id':STATE['run_id'],'tier':'S','kind':'bounded staged-append attribution; not SLO proof','attempts':3,'session_bytes':160*1024**2,'chunk_bytes':8*1024**2,'k6_exit':code,'elapsed_seconds':time.monotonic()-start}
(OUT/(prefix+'-run-manifest.json')).write_text(json.dumps(meta,indent=2)+'\n')
print(json.dumps({'prefix':prefix,**meta}),flush=True)
