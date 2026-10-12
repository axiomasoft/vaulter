import pathlib,re,collections,subprocess,json
root=pathlib.Path('/home/vostrikov/projects/packages/vaulter')
allowed={
 'packages/core/src/Support/Config.php':[
 "        $value = config('vaulter.collaboration.driver', 'hocuspocus');",
 "        return is_string($value) ? $value : 'hocuspocus';",
 "        $value = config('vaulter.collaboration.drivers.hocuspocus.secret');",
 "        $value = config('vaulter.collaboration.drivers.hocuspocus.token_ttl', 3600);",
 "        $value = config('vaulter.collaboration.drivers.hocuspocus.url');"],
 'packages/core/config/vaulter.php':[
 "        'driver' => env('VAULTER_COLLAB_DRIVER', 'hocuspocus'),",
 "            'hocuspocus' => ["]}
offenders=[];observed=[]
for dirname in ['packages/core/src','packages/core/config']:
 for p in sorted((root/dirname).rglob('*')):
  if not p.is_file():continue
  name=str(p.relative_to(root));counts=collections.Counter();permitted=allowed.get(name,[])
  for number,line in enumerate(p.read_text(errors='replace').splitlines(),1):
   if re.search('azgard|hocuspocus|filament',line,re.I):
    counts[line]+=1;observed.append({'path':name,'line':number,'text':line})
    if line not in permitted or counts[line]>1:offenders.append({'path':name,'line':number,'text':line})
  for line in permitted:
   if counts[line]!=1:offenders.append({'path':name,'expected_line':line,'count':counts[line]})
argv=['rg','-n','-e','vaulter:(gc-blobs|reconcile-blobs)|StorageResource|ListStorages|ViewStorage','packages','docs/guide','docs/reference','docs/ru','-g','*.php','-g','*.md','-g','!**/vendor/**']
scan=subprocess.run(argv,cwd=root,text=True,capture_output=True)
print(json.dumps({'d23_exact_runtime_lines':observed,'unexpected_core_bridge_mentions':offenders,'old_name_rg':{'argv':argv,'exit':scan.returncode,'stdout':scan.stdout,'stderr':scan.stderr}},ensure_ascii=False))
if offenders or scan.returncode!=1 or scan.stdout or scan.stderr:raise SystemExit(1)
