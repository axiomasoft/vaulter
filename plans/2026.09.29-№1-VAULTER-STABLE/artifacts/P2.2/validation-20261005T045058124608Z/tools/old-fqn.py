import json, re, sys
from pathlib import Path
root=Path.cwd(); plan='plans/2026.09.29-№1-VAULTER-STABLE'
old=list(json.loads((root/plan/'artifacts/P2.2/rename-paths.json').read_text()))+['ValueObjects\\WorkspaceRef','Enums\\BulkAction']
patterns=[re.compile(r'(?<![A-Za-z0-9_])'+re.escape('Vaulter\\Drive\\'+name)+r'(?![A-Za-z0-9_\\])') for name in old]
# Code Guidance additionally bars flat classes left under layers.
patterns.append(re.compile(r'Vaulter\\Drive\\(?:Actions\\[A-Z][A-Za-z]+Action\b|Data\\[A-Z][A-Za-z]+Data\b|Events\\(?:StorageCreated|NodeCreated|NodeForceDeleted|NodeVersionCommitted|UploadSession)|Exceptions\\[A-Z][A-Za-z]+Exception\b)'))
files=[]
for package in (root/'packages').iterdir():
    for name in ['src','tests','routes','config','database']:
        directory=package/name
        if directory.exists(): files.extend(p for p in directory.rglob('*') if p.is_file())
for name in ['guide','reference','ru']:
    directory=root/'docs'/name
    if directory.exists(): files.extend(p for p in directory.rglob('*') if p.is_file())
errors=[]
for file in files:
    try: text=file.read_text()
    except (UnicodeError,OSError): continue
    for number,line in enumerate(text.splitlines(),1):
        normalized=re.sub(r'\\+',lambda m:'\\',line)
        if any(pattern.search(normalized) for pattern in patterns): errors.append(f'{file.relative_to(root)}:{number}:{line}')
for name in old:
    path=root/'packages/drive/src'/(name.replace('\\','/')+'.php')
    if path.exists(): errors.append('obsolete source remains: '+str(path.relative_to(root)))
for name in ['packages/core/tests/ValueObjects/WorkspaceRefTest.php','packages/drive/tests/ValueObjects/WorkspaceRefTest.php']:
    if (root/name).exists(): errors.append('dead orphan test: '+name)
if errors:
    print('\n'.join(errors)); sys.exit(1)
print(json.dumps({'scanned_files':len(files),'old_fqns':len(old),'matches':0,'obsolete_paths':0,'dead_classes':0,'guidance':'empty'}))
