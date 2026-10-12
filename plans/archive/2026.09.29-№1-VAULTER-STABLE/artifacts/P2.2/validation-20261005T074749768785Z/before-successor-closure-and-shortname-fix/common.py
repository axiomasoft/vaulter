import hashlib,json,sys
from pathlib import Path
ROOT=Path('/home/vostrikov/projects/packages/vaulter')
PLAN=ROOT/'plans/2026.09.29-№1-VAULTER-STABLE'
TASK=Path('/home/vostrikov/projects/packages/swissknifeman/packages/task')
sys.path[:0]=[str(TASK/'lib'),str(TASK.parent.parent)]
from task import file_manifest,review_packet,plan_evidence

def sha(p):return hashlib.sha256(Path(p).read_bytes()).hexdigest()
def read(p):return json.loads(Path(p).read_text())
def write(p,v):Path(p).open('x').write(json.dumps(v,ensure_ascii=False,indent=2)+'\n')
def members(paths):return review_packet._members(ROOT,file_manifest.expand(ROOT,paths,base='HEAD',required=True))
def digest(rows):return hashlib.sha256(review_packet._json(rows)).hexdigest()
def native_paths():
 return [plan_evidence._repository_path(p,plan_dir=PLAN,repo_root=ROOT) for item in ['P2.2','P2.3'] for p in plan_evidence.material_product_paths(PLAN,item)]
def verify_generation(base):
 proof=read(base/'generation-binding.json')
 for p,h in proof['evidence_hashes'].items():
  if sha(p)!=h:raise RuntimeError('Generation evidence drift '+p)
 for p,h in proof['source_hashes'].items():
  if not (ROOT/p).is_file() or sha(ROOT/p)!=h:raise RuntimeError('Generation source drift '+p)
 for p,h in proof['golden_hashes'].items():
  if sha(ROOT/p)!=h:raise RuntimeError('Generated golden drift '+p)
 return proof

def identity(p):
 p=Path(p);s=p.lstat()
 return [s.st_dev,s.st_ino,s.st_mode,s.st_size,s.st_mtime_ns,s.st_ctime_ns]
def verify_runtime(base,content=False):
 inventory=read(base/'tools/dependency-runtime.json')
 for p,row in inventory['files'].items():
  target=ROOT/p
  if not target.exists() and not target.is_symlink():raise RuntimeError('Dependency missing '+p)
  if identity(target)!=row['identity']:raise RuntimeError('Dependency identity drift '+p)
  if target.is_symlink() and target.readlink().as_posix()!=row.get('target'):raise RuntimeError('Dependency symlink drift '+p)
  if content and row.get('sha256') is not None and sha(target)!=row['sha256']:raise RuntimeError('Dependency content drift '+p)
 for p,row in inventory['directories'].items():
  if identity(ROOT/p)!=row:raise RuntimeError('Dependency directory membership drift '+p)
 return {'files':len(inventory['files']),'directories':len(inventory['directories']),'content_sha256_verified':content,'identity_verified':True,'qualified_content_manifest':str(base/'tools/dependency-runtime.json')}

def task_provenance():
 return {str(p):sha(p) for p in [*(TASK/'lib').rglob('*.py'),TASK/'scripts/plan-work.py']}
