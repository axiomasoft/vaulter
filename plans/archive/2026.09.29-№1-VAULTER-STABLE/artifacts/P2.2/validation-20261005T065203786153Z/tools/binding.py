from pathlib import Path
import hashlib,json,re,sys,shutil,datetime
root=Path('/home/vostrikov/projects/packages/vaulter');base=Path(sys.argv[1]);task=Path('/home/vostrikov/projects/packages/swissknifeman/packages/task')
sys.path.insert(0,str(task/'lib'));sys.path.insert(0,str(task.parent.parent))
from task import review_packet,file_manifest,plan_gates
paths=json.loads((base/'candidate-paths.json').read_text());concrete=file_manifest.expand(root,paths,base='HEAD',required=True);members=review_packet._members(root,concrete);bound={x['path'] for x in members};candidate=hashlib.sha256(review_packet._json(members)).hexdigest();external=json.loads((base/'tools/external-tools.json').read_text());issues=[];bindings=[]
for p,sha in external.items():
 if not Path(p).is_file() or hashlib.sha256(Path(p).read_bytes()).hexdigest()!=sha: issues.append('external drift: '+p)
assert len(external)==149
# Native Files coverage, including tracked deletions.
spec=(root/'plans/2026.09.29-№1-VAULTER-STABLE/phases/P2/P2.2.md').read_text();files=spec.split('**Files:**',1)[1].split('**Required Reads:**',1)[0];declared=plan_gates._declared_file_paths(files);native=file_manifest.expand(root,declared,base='HEAD',required=True);missing=sorted(set(native)-bound)
if missing:issues.extend('native Files unbound: '+p for p in missing)
def bind(path,origin):
 path=Path(path)
 if not path.is_absolute():path=root/path
 if path.is_file():
  try:relative=path.relative_to(root).as_posix()
  except ValueError:relative=None
  if relative in bound:kind='candidate'
  elif str(path.resolve()) in external:kind='pinned-external'
  elif str(path) in external:kind='pinned-external'
  else:issues.append('unbound real file: '+str(path)+' '+origin);kind='unbound'
  bindings.append({'origin':origin,'path':str(path),'binding':kind})
checks=[]
for stage in ['affected','full']:
 stagechecks=json.loads((base/(stage+'-checks.json')).read_text());checks+=stagechecks
 for c in stagechecks:
  argv=c['argv'];cwd=root
  if 'validation' in argv and argv.index('validation')+1<len(argv):cwd=Path(argv[argv.index('validation')+1])
  for a in argv:
   if 'validation-' in a and str(base) not in a:issues.append('old validation reference: '+a)
   if a.startswith('/') or '/' in a or a.endswith(('.php','.py','.json','.neon')):bind(Path(a) if Path(a).is_absolute() else cwd/a,c['id'])
  for command in ['python3','bash','pg_isready']:
   if command in argv:bind(shutil.which(command),c['id']+' executable')
  if any(a=='vendor/bin/pest' for a in argv):
   for name in ['phpunit.xml','vendor/autoload.php','tests/Pest.php']:bind(cwd/name,c['id']+' implicit')
  if any(a=='vendor/bin/phpstan' for a in argv):
   for name in ['phpstan.neon','vendor/larastan/larastan/extension.neon']:bind(cwd/name,c['id']+' implicit')
  if any(a=='vendor/bin/pint' for a in argv):bind(cwd/'pint.json',c['id']+' implicit')
for f in (base/'tools').iterdir():
 if f.is_file():
  bind(f,'required persisted tool/runtime inventory')
  if re.search(r'validation-\d{8}T\d+Z',f.read_text()) and any(x!=base.name for x in re.findall(r'validation-\d{8}T\d+Z',f.read_text())):issues.append('old validation hardcode: '+str(f))
for path in paths:
 if 'validation-' in path and base.relative_to(root).as_posix() not in path:issues.append('old declaration '+path)
bind(task/'scripts/plan-work.py','canonical evidence runner');bind('/usr/bin/php8.4','php84 runtime')
proof={'candidate_sha256':candidate,'expanded_members':len(members),'native_files_members':len(native),'native_files_missing':missing,'external_tools_count':len(external),'argv_file_bindings':bindings,'issues':issues,'old_reference_assertion':'none in candidate declarations or either check DAG and all persisted tools' if not issues else 'red','tools_and_checks_bound':not issues,'captured_before_first_validate_evidence':True,'captured_at':datetime.datetime.now(datetime.timezone.utc).isoformat()}
(base/'structural-binding-proof.json').open('x').write(json.dumps(proof,ensure_ascii=False,indent=2)+'\n');print(json.dumps({k:v for k,v in proof.items() if k!='argv_file_bindings'},ensure_ascii=False))
if issues:sys.exit(1)
