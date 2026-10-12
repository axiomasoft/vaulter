import re,shutil,datetime
from common import *
base=Path(sys.argv[1]);paths=read(base/'candidate-paths.json');rows=members(paths);bound={r['path'] for r in rows};external=read(base/'tools/external-tools.json');runtime=read(base/'tools/dependency-runtime.json');issues=[];bindings=[]
native=file_manifest.expand(ROOT,native_paths(),base='HEAD',required=True);missing=sorted(set(native)-bound)
issues += ['native material product unbound: '+p for p in missing]
def bind(p,origin):
 p=Path(p)
 if not p.is_absolute():p=ROOT/p
 if not p.is_file():return
 try:relative=p.relative_to(ROOT).as_posix()
 except ValueError:relative=None
 if relative in bound:kind='candidate'
 elif str(p) in external or str(p.resolve()) in external:kind='pinned-external'
 elif p.is_relative_to(TASK):kind='owner-authorized-live-task-runtime'
 elif relative in runtime['files']:kind='qualified-runtime'
 else:issues.append('unbound real file: '+str(p)+' '+origin);kind='unbound'
 bindings.append({'origin':origin,'path':str(p),'binding':kind})
for p,h in external.items():
 if not Path(p).is_file() or sha(p)!=h:issues.append('external drift: '+p)
for stage in ['affected','full']:
 for c in read(base/(stage+'-checks.json')):
  argv=c['argv'];cwd=ROOT
  if 'validation' in argv:cwd=Path(argv[argv.index('validation')+1])
  for a in argv:
   if 'validation-' in a and str(base) not in a:issues.append('old validation reference '+a)
   if a.startswith('/') or '/' in a or a.endswith(('.php','.py','.json','.neon')):bind(Path(a) if Path(a).is_absolute() else cwd/a,c['id'])
  for exe in ['python3','bash','env','pg_isready']:
   if exe in argv:bind(shutil.which(exe),c['id']+' executable')
  if 'vendor/bin/pest' in argv:
   for name in ['phpunit.xml','vendor/autoload.php','tests/Pest.php']:bind(cwd/name,c['id']+' implicit')
  if 'vendor/bin/phpstan' in argv:
   for name in ['phpstan.neon','vendor/larastan/larastan/extension.neon']:bind(cwd/name,c['id']+' implicit')
  if 'vendor/bin/pint' in argv:bind(cwd/'pint.json',c['id']+' implicit')
for f in (base/'tools').iterdir():
 if f.is_file():
  bind(f,'persisted runtime')
  if f.suffix in ['.php','.py','.json']:
   text=f.read_text()
   if any(s!=base.name for s in re.findall(r'validation-\d{8}T\d+Z',text)):issues.append('old validation hardcode '+str(f))
for p in paths:
 if 'validation-' in p and base.relative_to(ROOT).as_posix() not in p:issues.append('old candidate declaration '+p)
bind(TASK/'scripts/plan-work.py','canonical runner');verify_generation(base);runtime_proof=verify_runtime(base,content=True)
proof={'candidate_sha256':digest(rows),'expanded_members':len(rows),'native_material_product_declarations':native_paths(),'native_files_members':len(native),'native_files_missing':missing,'external_tools_count':len(external),'owner_authorized_live_task_runtime_provenance':task_provenance(),'dependency_runtime_qualification':runtime_proof,'argv_file_bindings':bindings,'issues':issues,'tools_and_checks_bound':not issues,'captured_before_first_validate_evidence':True,'captured_at':datetime.datetime.now(datetime.timezone.utc).isoformat()}
write(base/'structural-binding-proof.json',proof);print(json.dumps({k:v for k,v in proof.items() if k not in ['argv_file_bindings','native_material_product_declarations']},ensure_ascii=False))
if issues:sys.exit(1)
