from vaulter_r03_bench import *
import urllib.error
man=json.loads((OUT/'s-baseline-seed-manifest.json').read_text());root=man['root_node_id'];storage=man['storage_id']
pg('s-docs-discovery-db',f"SELECT json_build_object('root_children',(SELECT count(*) FROM v_nodes WHERE parent_id={root}),'documents',(SELECT json_agg(n) FROM (SELECT id,name,parent_id,kind FROM v_nodes WHERE name='documents')n),'document_nodes',(SELECT count(*) FROM v_nodes WHERE kind='document'));",'S')
url=f'http://127.0.0.1:8183/api/vaulter/storages/{storage}/nodes?parent_id={root}&per_page=200'
req=urllib.request.Request(url,headers={'X-Load-Actor':'1','Accept':'application/json'})
try:
    with urllib.request.urlopen(req,timeout=30) as res:status=res.status;body=res.read().decode()
except urllib.error.HTTPError as e:status=e.code;body=e.read().decode()
(OUT/'s-docs-discovery-root-http.json').write_text(json.dumps({'url':url,'status':status,'body':json.loads(body)},indent=2)+'\n')
print(json.dumps({'status':status,'root_children':json.loads((OUT/'s-docs-discovery-db.stdout').read_text()),'returned_count':len(json.loads(body).get('data',[])),'documents_in_page':any(n['name']=='documents' for n in json.loads(body).get('data',[]))}))
