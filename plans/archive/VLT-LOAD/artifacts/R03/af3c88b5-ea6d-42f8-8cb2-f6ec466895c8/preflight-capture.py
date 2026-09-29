import datetime, hashlib, json, os, subprocess, sys, tarfile, uuid
from pathlib import Path
root=Path.cwd()
sys.path.insert(0,str(root/'load/gates'))
from r03_lab import observe_host, source_digest
from r03_summary import lab_admission
run_id=os.environ.get('R03_PREFLIGHT_RUN_ID',str(uuid.uuid4()))
out=root/'plans/VLT-LOAD/artifacts/R03'/run_id
out.mkdir(parents=True,exist_ok=True)
commands=[]
def capture(label,argv):
 p=subprocess.run(argv,cwd=root,capture_output=True,text=True)
 (out/(label+'.stdout')).write_text(p.stdout)
 (out/(label+'.stderr')).write_text(p.stderr)
 commands.append({'label':label,'argv':argv,'exit':p.returncode})
 return p
revision=capture('source-revision',['git','rev-parse','HEAD']).stdout.strip()
capture('worktree-before',['git','status','--short'])
# Compose may contain credentials: retain only resource facts, not environment values.
p=subprocess.run(['docker','compose','-f','load/docker-compose.load.yml','--env-file','load/env/S.env','--profile','bench','config','--format','json'],cwd=root,capture_output=True,text=True)
commands.append({'label':'stand-resources','argv':p.args,'exit':p.returncode,'retained_fields':['name','services.resource_limits']})
if p.returncode: raise SystemExit(p.stderr)
config=json.loads(p.stdout)
stand={'name':config['name'],'services':{k:{f:v for f,v in row.items() if f in ['cpuset','cpu_quota','cpus','mem_limit','memswap_limit']} for k,row in config['services'].items()}}
(out/'stand-resources.json').write_text(json.dumps(stand,indent=2,sort_keys=True)+'\n')
try:
 observed=observe_host(stand['name'],stand)
except Exception as error:
 (out/'lab-helper-error.txt').write_text(type(error).__name__+': '+str(error)+'\n')
 observed={'helper_error':{'ok':False,'evidence':type(error).__name__+': '+str(error)}}
(out/'host-observations.json').write_text(json.dumps(observed,indent=2,sort_keys=True)+'\n')
for label,argv in [
 ('docker-running',['docker','ps','--format','{{json .}}']),
 ('disks',['lsblk','-J','-o','NAME,TYPE,SIZE,FSTYPE,MOUNTPOINTS']),
 ('filesystems',['df','-B1',str(root),'/mnt/d0acf944-5348-40c2-94f2-7f55978df8b5']),
 ('memory',['free','-b']),
 ('swaps',['cat','/proc/swaps']),
 ('host-processes',['ps','-eo','pid,ppid,comm,%cpu,%mem','--sort=-%cpu']),
 ('load-containers',['docker','ps','-a','--filter','label=com.docker.compose.project=vaulter-load','--format','{{.Names}} {{.Status}}']),
 ('python-focused',['python3','-B','-m','unittest','discover','-s','load/gates','-p','test_r03_*.py']),
 ('js-focused',['node','--experimental-vm-modules','--test','load/gates/tests/r03-k6.test.mjs']),
 ('runner-syntax',['sh','-n','load/scripts/run.sh']),
]: capture(label,argv)
names=subprocess.check_output(['git','ls-files','-z','--cached','--others','--exclude-standard','packages','load'],cwd=root)
files=sorted(set(names.split(b'\0'))-{b''})
files=[os.fsdecode(n) for n in files if (root/os.fsdecode(n)).is_file()]
source_hash=source_digest(root)
manifest={'run_id':run_id,'phase':'16c','kind':'preflight','source_revision':revision,'source_digest':source_hash,'created_at':datetime.datetime.now(datetime.timezone.utc).isoformat(),'declared_window_seconds':180,'status':'PREFLIGHT_REJECTED','live_started':False,'qualified_product_proof':'unevaluated','stand_digest':hashlib.sha256((out/'stand-resources.json').read_bytes()).hexdigest(),'rejected_host_conditions':[k for k,v in observed.items() if not v['ok']], 'missing_evidence':['L-3 quiet host/reserved cores','L-4 separated storage layout','L-6 declared warm/cold cache','L-7 reset outputs'], 'source_files':len(files)}
with tarfile.open(out/'source.tar.gz','w:gz') as archive:
 for name in files: archive.add(root/name,arcname=name,recursive=False)
(out/'source-files.sha256.json').write_text(json.dumps({n:hashlib.sha256((root/n).read_bytes()).hexdigest() for n in files},indent=2,sort_keys=True)+'\n')
(out/'commands.json').write_text(json.dumps(commands,indent=2)+'\n')
(out/'run-manifest.json').write_text(json.dumps(manifest,indent=2,sort_keys=True)+'\n')
checksums={str(p.relative_to(out)):hashlib.sha256(p.read_bytes()).hexdigest() for p in out.rglob('*') if p.is_file()}
(out/'sha256.json').write_text(json.dumps(checksums,indent=2,sort_keys=True)+'\n')
print(json.dumps({'directory':str(out),'manifest':manifest,'validation':[c for c in commands if c['label'].endswith('focused') or c['label']=='runner-syntax']},indent=2))
