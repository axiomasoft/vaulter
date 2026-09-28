from vaulter_r03_bench import *
import gzip,urllib.error
man=next(json.loads(p.read_text()) for p in (ROOT/'load/seed/out').glob('*.json') if json.loads(p.read_text())['tier']=='S')
(OUT/'s-baseline-seed-manifest.json').write_text(json.dumps(man,indent=2)+'\n')
tables=['nodes','files','file_versions','blobs','activity','upload_sessions','upload_parts','outbox','webhook_endpoints','webhook_deliveries','node_comments','node_document_versions']
pg('s-baseline-cardinality',"SELECT json_build_object("+','.join("'%s',(SELECT count(*) FROM v_%s)"%(t,t) for t in tables)+");",'S')
wide=man['counts']['wide_node_id']
pg('s-seed-primary-blob-readback',f"SELECT json_agg(a) FROM (SELECT n.id,n.name,f.current_version_id,fv.blob_id AS primary_blob_id,e.blob_id AS edge_blob_id,fv.size_bytes,b.storage_key FROM v_nodes n JOIN v_files f ON f.id=n.file_id JOIN v_file_versions fv ON fv.id=f.current_version_id LEFT JOIN v_file_version_blobs e ON e.file_version_id=fv.id AND e.role='data' AND e.ordinal=0 LEFT JOIN v_blobs b ON b.id=e.blob_id WHERE n.parent_id={wide} ORDER BY n.id LIMIT 50)a;",'S')
rows=json.loads((OUT/'s-seed-primary-blob-readback.stdout').read_text())
for row in rows[:3]:
    run('s-seed-object-stat-'+str(row['id']),['docker','exec',STATE['project']+'-minio-1','mc','stat','--json','r03/'+STATE['bucket_s']+'/'+row['storage_key']])
    req=urllib.request.Request('http://127.0.0.1:8183/api/vaulter/nodes/'+str(row['id'])+'/download',headers={'X-Load-Actor':'1'})
    try:
        with urllib.request.urlopen(req,timeout=30) as res:status=res.status;body=res.read().decode()
    except urllib.error.HTTPError as e:status=e.code;body=e.read().decode()
    (OUT/('s-seed-download-'+str(row['id'])+'.json')).write_text(json.dumps({'status':status,'body':body,'source_row':row},indent=2)+'\n')
run('s-baseline-bucket-catalog',['docker','exec',STATE['project']+'-minio-1','mc','ls','--recursive','--json','r03/'+STATE['bucket_s']])
# One compressed baseline enables a clean retry without rewriting 20 GiB of hot objects.
args=['docker','exec',STATE['project']+'-postgres-1','pg_dump','-U','vaulter','--no-owner',STATE['database_s']]
start=time.monotonic()
with (OUT/'s-baseline-pg-dump.stderr').open('wb') as err,gzip.open(OUT/'s-baseline.sql.gz','wb',compresslevel=3) as out:
    p=subprocess.Popen(args,stdout=subprocess.PIPE,stderr=err)
    shutil.copyfileobj(p.stdout,out)
    code=p.wait()
with (OUT/'commands.jsonl').open('a') as f:f.write(json.dumps({'name':'s-baseline-pg-dump','argv':args,'exit':code,'elapsed_seconds':time.monotonic()-start})+'\n')
assert code==0
print('BASELINE SAVED',flush=True)
