from vaulter_r03_analyze import *
import re,math
prefix=__import__('sys').argv[1]
rows=[]
for line in (OUT/(prefix+'.stderr')).read_text().splitlines():
    m=re.search(r'msg=("(?:[^"\\]|\\.)*") source=console',line)
    if m:
        value=json.loads(json.loads(m.group(1)))
        if isinstance(value,dict):rows.append(value)
patches=[r for r in rows if r.get('kind')=='r03-tus-offset'];downloads=[r for r in rows if r.get('kind')=='r03-completed-node']
resources=[json.loads(l) for l in gzip.open(OUT/(prefix+'-resources.jsonl.gz'),'rt')];first,last=resources[0],resources[-1];elapsed=last['elapsed_s']-first['elapsed_s'];services={}
for name in first['services']:
    a=first['services'][name];b=last['services'][name]
    cpu=(fields(b['cpu.stat'])['usage_usec']-fields(a['cpu.stat'])['usage_usec'])/1e6
    services[name]={'cpu_seconds':cpu,'mean_cpu_cores':cpu/elapsed,'read_bytes':iosum(b['io.stat'],'rbytes')-iosum(a['io.stat'],'rbytes'),'write_bytes':iosum(b['io.stat'],'wbytes')-iosum(a['io.stat'],'wbytes'),'network_bytes':{k:net(b['net_dev'])[k]-net(a['net_dev'])[k] for k in ['rx','tx']}}
before=json.loads((OUT/(prefix+'-before-pg.stdout')).read_text());after=json.loads((OUT/(prefix+'-after-pg.stdout')).read_text())
wal={k:v-before['wal'][k] for k,v in after['wal'].items() if isinstance(v,(int,float)) and isinstance(before['wal'].get(k),(int,float))}
nonfinal=[r for r in patches if r['index']<19]
x=[r['offset_bytes']/1024**2 for r in nonfinal];y=[r['duration_ms'] for r in nonfinal]
xm,ym=statistics.mean(x),statistics.mean(y);num=sum((a-xm)*(b-ym) for a,b in zip(x,y));r=num/math.sqrt(sum((a-xm)**2 for a in x)*sum((b-ym)**2 for b in y))
by_index={str(i):{'n':len(p:=[r for r in patches if r['index']==i]),'median_duration_ms':statistics.median(r['duration_ms'] for r in p),'median_payload_mibs':statistics.median(8/(r['duration_ms']/1000) for r in p)} for i in range(20) if any(r['index']==i for r in patches)}
report={'run_id':json.loads((OUT/(prefix+'-run-manifest.json')).read_text())['run_id'],'kind':'bounded attribution; no mixed SLO or class-B proof','patch_count':len(patches),'statuses':{str(s):sum(r['status']==s for r in patches) for s in sorted(set(r['status'] for r in patches))},'downloads':downloads,'by_chunk_index':by_index,'offset_duration_pearson_r_nonfinal':r,'nonfinal_mean_duration_ms':statistics.mean(y),'wal_deltas':wal,'resources':{'elapsed_s':elapsed,'services':services,'min_free_bytes':min(r['free_bytes'] for r in resources)},'logical_uploaded_bytes':3*160*1024**2,'all_http_checks':json.loads((OUT/(prefix+'-summary.json')).read_text())['metrics']['checks'],'inference':'Growing accepted PATCH latency and network rewrite amplification support staged append RMW as a bottleneck; PG WAL sync time is separately observed and is not assumed to explain all waiting. This does not quantify PHP fsync or prove one exclusive cause.'}
(OUT/(prefix+'-observations.json')).write_text(json.dumps(rows,indent=2)+'\n')
(OUT/(prefix+'-analysis.json')).write_text(json.dumps(report,indent=2)+'\n')
print(json.dumps(report,indent=2))
