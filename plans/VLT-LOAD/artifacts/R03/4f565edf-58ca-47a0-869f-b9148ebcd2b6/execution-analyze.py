from vaulter_r03_control import *
import gzip,re,statistics,hashlib

def fields(text):
    return {line.split()[0]:int(line.split()[1]) for line in text.splitlines() if len(line.split())==2}
def iosum(text,key):
    return sum(int(value.split('=')[1]) for line in text.splitlines() for value in line.split()[1:] if value.startswith(key+'='))
def net(text):
    line=next(line for line in text.splitlines() if 'eth0:' in line).split(':')[1].split();return {'rx':int(line[0]),'tx':int(line[8])}
def analyze(prefix):
    meta=json.loads((OUT/(prefix+'-run-manifest.json')).read_text())
    summary=json.loads((OUT/(prefix+'-summary.json')).read_text());metrics=summary['metrics']
    resources=[json.loads(line) for line in gzip.open(OUT/(prefix+'-resources.jsonl.gz'),'rt')]
    first,last=resources[0],resources[-1];elapsed=last['elapsed_s']-first['elapsed_s'];services={}
    for name in first['services']:
        if 'cpu.stat' not in first['services'][name] or 'cpu.stat' not in last['services'][name]:continue
        start=fields(first['services'][name]['cpu.stat'])['usage_usec'];end=fields(last['services'][name]['cpu.stat'])['usage_usec'];cpu=(end-start)/1e6
        cores=[]
        for a,b in zip(resources,resources[1:]):
            try:cores.append((fields(b['services'][name]['cpu.stat'])['usage_usec']-fields(a['services'][name]['cpu.stat'])['usage_usec'])/1e6/(b['elapsed_s']-a['elapsed_s']))
            except KeyError:pass
        services[name]={'cpu_seconds':cpu,'mean_cpu_cores':cpu/elapsed,'p95_cpu_cores':sorted(cores)[int((len(cores)-1)*0.95)],'max_cpu_cores':max(cores),'read_bytes':iosum(last['services'][name]['io.stat'],'rbytes')-iosum(first['services'][name]['io.stat'],'rbytes'),'write_bytes':iosum(last['services'][name]['io.stat'],'wbytes')-iosum(first['services'][name]['io.stat'],'wbytes'),'peak_container_anon':max(fields(r['services'][name]['memory.stat']).get('anon',0) for r in resources),'peak_container_memory_current':max(int(r['services'][name]['memory.current']) for r in resources)}
        if 'net_dev' in first['services'][name] and 'net_dev' in last['services'][name]:
            a=net(first['services'][name]['net_dev']);b=net(last['services'][name]['net_dev']);services[name]['network_bytes']={key:b[key]-a[key] for key in a}
    before=json.loads((OUT/(prefix+'-before-pg.stdout')).read_text());after=json.loads((OUT/(prefix+'-after-pg.stdout')).read_text())
    pg={}
    for part in ['wal','db']:
        pg[part]={key:value-before[part].get(key,0) for key,value in after[part].items() if isinstance(value,(int,float)) and isinstance(before[part].get(key),(int,float))}
    latency={key:{'values':metric.get('values'),'thresholds':metric.get('thresholds'),'sample_quality':'dense' if metric.get('values',{}).get('count',0)>=100 else 'sparse; no p95 verdict','lab_admitted':meta['lab_admitted']} for key,metric in metrics.items() if key.startswith('http_req_duration{op:')}
    stream={key:{'values':metric.get('values'),'thresholds':metric.get('thresholds')} for key,metric in metrics.items() if key.startswith('stream_throughput_mibs{') or key.startswith('data_sent{') or key.startswith('data_received{')}
    failed={key:[expr for expr,threshold in metric.get('thresholds',{}).items() if threshold.get('ok') is False] for key,metric in metrics.items() if any(t.get('ok') is False for t in metric.get('thresholds',{}).values())}
    report={'run_id':meta['run_id'],'tier':meta['tier'],'k6_exit':meta['k6_exit'],'declared_ms':meta['declared_duration_seconds']*1000,'observed_ms':summary['state']['testRunDurationMs'],'full_window':summary['state']['testRunDurationMs']>=meta['declared_duration_seconds']*1000,'lab_admitted':meta['lab_admitted'],'class_b':latency,'streaming':stream,'failed_thresholds':failed,'resources':{'samples':len(resources),'elapsed_s':elapsed,'min_free_bytes':min(r['free_bytes'] for r in resources),'services':services},'postgres_deltas':pg,'checks':metrics.get('checks'),'http_req_failed':metrics.get('http_req_failed'),'evidence_limits':meta['environment_limitations']}
    (OUT/(prefix+'-analysis.json')).write_text(json.dumps(report,indent=2)+'\n')
    return report
if __name__=='__main__':
    import sys
    r=analyze(sys.argv[1]);print(json.dumps({'run_id':r['run_id'],'full_window':r['full_window'],'observed_ms':r['observed_ms'],'streaming':r['streaming'],'dense_B':sum(x['sample_quality']=='dense' for x in r['class_b'].values()),'total_B':len(r['class_b']),'resources':r['resources']},indent=2))
