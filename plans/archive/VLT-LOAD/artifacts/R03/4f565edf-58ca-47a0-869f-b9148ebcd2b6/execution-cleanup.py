from vaulter_r03_bench import *
import gzip,hashlib
run('s-post-workload-bucket-catalog-repeat',['docker','exec',STATE['project']+'-minio-1','mc','ls','--recursive','--json','r03/'+STATE['bucket_s']])
def catalog(name):
    rows=[json.loads(l) for l in (OUT/name).read_text().splitlines()]
    assert all(r.get('status')=='success' for r in rows)
    return {r['key']:r for r in rows if r.get('type')=='file'}
before=catalog('s-baseline-bucket-catalog.stdout');after=catalog('s-post-workload-bucket-catalog-repeat.stdout')
assert len(before)>=20000
assert not(set(before)-set(after)), 'baseline object unexpectedly missing'
assert all(before[k]['etag']==after[k]['etag'] and before[k]['size']==after[k]['size'] for k in before),'baseline object changed'
added=sorted(set(after)-set(before));bucket=STATE['bucket_s']
assert bucket.startswith('vaulter-r03-4f565edf-')
assert all(k and '\n' not in k and not k.startswith('/') and '..' not in k.split('/') for k in added)
(OUT/'cleanup-added-object-keys.json').write_text(json.dumps({'bucket':bucket,'preserved_baseline_count':len(before),'added_keys':added,'added_bytes':sum(after[k]['size'] for k in added)},indent=2)+'\n')
if added:
    run('s-added-objects-cleanup-repeat',['docker','exec','-i',STATE['project']+'-minio-1','mc','rm','--force','--stdin','--json'],input=''.join('r03/'+bucket+'/'+k+'\n' for k in added))
run('s-cleanup-readback-catalog',['docker','exec',STATE['project']+'-minio-1','mc','ls','--recursive','--json','r03/'+bucket])
final=catalog('s-cleanup-readback-catalog.stdout')
assert set(final)==set(before)
assert all(final[k]['etag']==before[k]['etag'] and final[k]['size']==before[k]['size'] for k in before)
pg('s-final-cardinality',"SELECT json_build_object('nodes',(SELECT count(*) FROM v_nodes),'blobs',(SELECT count(*) FROM v_blobs),'activity',(SELECT count(*) FROM v_activity),'uploads',(SELECT count(*) FROM v_upload_sessions),'db_bytes',pg_database_size(current_database()));",'S')
(OUT/'cleanup-readback.json').write_text(json.dumps({'campaign_id':STATE['run_id'],'only_this_campaign_added_objects_removed':True,'removed_object_count':len(added),'removed_logical_bytes':sum(after[k]['size'] for k in added),'baseline_object_count':len(final),'baseline_logical_bytes':sum(r['size'] for r in final.values()),'baseline_etags_and_sizes_unchanged':True,'baseline_sql_dump':'s-baseline.sql.gz','databases_retained':'post-workload rows retained for readback; restore baseline dump before retry','owner_containers_deleted':False,'owner_buckets_modified':False},indent=2)+'\n')
run('stand-stop',compose('S')+['stop'])
run('final-disk',['df','-B1',str(ROOT)])
run('final-containers',['docker','ps','-a','--format','{{.Names}}\t{{.Status}}'])
# Preserve raw catalogs, compressed, without duplicate copies.
for name in ['s-baseline-bucket-catalog.stdout','s-post-workload-bucket-catalog-repeat.stdout','s-cleanup-readback-catalog.stdout']:
    p=OUT/name
    with p.open('rb') as source,gzip.open(str(p)+'.gz','wb') as target:shutil.copyfileobj(source,target)
    p.unlink()
