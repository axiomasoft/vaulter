import sys,copy
sys.path.insert(0,'load/gates');import p6_evidence as e
volume={'relay_rows_per_second':250,'relay_tick_p95_seconds':1,'relay_drained':True,
'retry_rows_per_second':600,'retry_tick_p95_seconds':1,'retry_drained':True,
'retry_ticks':[{'retried':1000,'rss_peak_bytes':64000000}], 'retry_failed_jobs_delta':[]}
steady={'cell_id':'product-realistic.relay.i20.f1.2xx','attempt':1,'plane':'product-realistic','sink':'2xx','fanout':'1','ingress_per_s':20,'backlog_slope':0,'events_sent':36000,'burst_seconds':1800,'failed_jobs':0}
def sample(n):
 return {'cell_id':'product-realistic.relay.i200.f1.slow-under-timeout','attempt':n,'plane':'product-realistic','sink':'slow-under-timeout','fanout':'1','ingress_per_s':200,'jobs_per_second':60+n,'recovered':True,'lifecycle':{'queue_wait_p95_seconds':1,'process_p95_seconds':.05},'failed_jobs':0}
rows=[steady,*[sample(i) for i in range(1,4)]]
assert all(e.slo_report(volume,rows)[k]=='green' for k in ('SLO-58','SLO-61','SLO-64'))
rows[2]['lifecycle']['queue_wait_p95_seconds']=6
assert e.slo_report(volume,rows)['SLO-61']=='red', 'faster bad repeat cannot hide behind slowest repeat'
volume['retry_ticks'][0]['retried']=1001
assert e.slo_report(volume,rows)['SLO-64']=='red'
volume['relay_rows_per_second']=199
assert e.slo_report(volume,rows)['SLO-58']=='red'
print('SLO positive control and three deliberate threshold regressions: PASS')
