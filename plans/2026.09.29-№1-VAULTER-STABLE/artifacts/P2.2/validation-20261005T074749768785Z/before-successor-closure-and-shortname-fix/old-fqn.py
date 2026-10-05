import re
from common import *
base=Path(sys.argv[1]);mapping=read(base/'rename-map.json');errors=[];files=[]
for p in (ROOT/'packages').iterdir():
 for d in ['src','tests','config','routes','database']:
  if (p/d).exists():files.extend(x for x in (p/d).rglob('*') if x.is_file())
for d in ['docs/guide','docs/reference','docs/ru','load/app','tests/fixtures']:
 if (ROOT/d).exists():files.extend(x for x in (ROOT/d).rglob('*') if x.is_file())
files += [ROOT/'UPGRADE.md',ROOT/'CHANGELOG.md']
oldshorts={x.rsplit('\\',1)[-1] for x in mapping}
# Exact complete class tokens keep CreateNamedVersionRequest etc. intact.
short=re.compile(r'(?<![A-Za-z0-9_])(?:'+'|'.join(map(re.escape,sorted(oldshorts)))+r')(?![A-Za-z0-9_])')
full=[re.compile(r'(?<![A-Za-z0-9_])'+re.escape(x)+r'(?![A-Za-z0-9_\\])') for x in mapping]
reverb=re.compile(r'\bReverbNodeCollaborationDriver\b|\bCollaborationDriverType\s*::\s*Reverb\b|\breverbConnection\s*\(')
for f in files:
 try:lines=f.read_text().splitlines()
 except UnicodeError:continue
 for n,line in enumerate(lines,1):
  if f.name=='UPGRADE.md' and line.startswith('|') and len(line.split('|'))>=4:line='|'.join(line.split('|')[2:])
  normalized=re.sub(r'\\+',lambda m:'\\',line)
  if short.search(normalized) or any(p.search(normalized) for p in full) or reverb.search(normalized):errors.append(f'{f.relative_to(ROOT)}:{n}:{line}')
for old in mapping:
 if old.startswith('Vaulter\\Documents\\'):
  path=ROOT/'packages/documents/src'/(old[len('Vaulter\\Documents\\'):].replace('\\','/')+'.php')
  if path.exists():errors.append('obsolete source remains: '+str(path.relative_to(ROOT)))
for path in ['packages/core/config/vaulter.php','packages/documents/config/vaulter-documents.php']:
 text=(ROOT/path).read_text()
 if re.search(r"['\"]reverb['\"]\s*=>",text):errors.append(path+': Reverb driver config remains')
enum=(ROOT/'packages/documents/src/Enums/Collaboration/CollaborationDriverType.php').read_text()
if re.search(r'\bcase\s+Reverb\b',enum):errors.append('Reverb enum branch remains')
if errors:print('\n'.join(errors));sys.exit(1)
print(json.dumps({'scanned_files':len(files),'exact_class_renames':len(mapping),'old_class_matches':0,'reverb_driver_removed':True,'retained_Request_shortnames_allowed':True,'historical_UPGRADE_before_column_allowed':True}))
