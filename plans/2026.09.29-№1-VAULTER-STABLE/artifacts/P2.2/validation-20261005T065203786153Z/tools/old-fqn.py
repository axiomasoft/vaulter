import json, re, sys
from pathlib import Path
root=Path.cwd(); plan='plans/2026.09.29-№1-VAULTER-STABLE'
old=list(json.loads((root/plan/'artifacts/P2.2/rename-paths.json').read_text()))+['ValueObjects\\WorkspaceRef','Enums\\BulkAction']
patterns=[re.compile(r'(?<![A-Za-z0-9_])'+re.escape('Vaulter\\Drive\\'+name)+r'(?![A-Za-z0-9_\\])') for name in old]
# Code Guidance additionally bars flat classes left under layers.
patterns.append(re.compile(r'Vaulter\\Drive\\(?:Actions\\[A-Z][A-Za-z]+Action\b|Data\\[A-Z][A-Za-z]+Data\b|Events\\(?:StorageCreated|NodeCreated|NodeForceDeleted|NodeVersionCommitted|UploadSession)|Exceptions\\[A-Z][A-Za-z]+Exception\b)'))
files=[]
for package in [root/'packages'/name for name in ['core','drive','documents','filament','media','azgard']]:
    for name in ['src','tests','routes','config','database']:
        directory=package/name
        if directory.exists(): files.extend(p for p in directory.rglob('*') if p.is_file())
for name in ['guide','reference','ru']:
    directory=root/'docs'/name
    if directory.exists(): files.extend(p for p in directory.rglob('*') if p.is_file())
files += [root/'UPGRADE.md', root/'CHANGELOG.md']
files.extend(p for p in (root/'load/app').rglob('*.php'))
shorts=['DriveContext','DriveManager','StorageManager','StorageCreated','NodeCreated','NodeForceDeleted','NodeVersionCommitted','UploadSessionInitiated','UploadSessionAborted','UploadSessionExpired','CrossStorageMoveException','CycleException','FileVersionResource','MagicByteSniffer','CommitUploadAction','UploadInitData','CreateDriveFolderData','UploadFileData']
shortpat=re.compile(r'\b(?:'+'|'.join(shorts)+r')\b|ShowDriveController::show')
errors=[]
for file in files:
    try: text=file.read_text()
    except (UnicodeError,OSError): continue
    for number,line in enumerate(text.splitlines(),1):
        if file.name == 'UPGRADE.md' and line.startswith('|') and len(line.split('|')) >= 4:
            line='|'.join(line.split('|')[2:]) # historical Before column only excluded
        normalized=re.sub(r'\\+',lambda m:'\\',line)
        if any(pattern.search(normalized) for pattern in patterns) or (file.is_relative_to(root/'docs') and shortpat.search(normalized)): errors.append(f'{file.relative_to(root)}:{number}:{line}')
for name in old:
    path=root/'packages/drive/src'/(name.replace('\\','/')+'.php')
    if path.exists(): errors.append('obsolete source remains: '+str(path.relative_to(root)))
for name in ['packages/core/tests/ValueObjects/WorkspaceRefTest.php','packages/drive/tests/ValueObjects/WorkspaceRefTest.php']:
    if (root/name).exists(): errors.append('dead orphan test: '+name)
# Current prose must agree with the actual handle contract and resource wire keys.
prose_checks=[]
for path in ['docs/guide/drive.md','docs/ru/guide/drive.md']:
    doc=(root/path).read_text()
    profile_lines=[line for line in doc.splitlines() if '->profile()' in line]
    if not profile_lines or any('Vaulter\\Profiles\\Profile' not in line for line in profile_lines):
        errors.append(path+': profile() documentation must return Vaulter\\Profiles\\Profile')
    prose_checks.append({'path':path,'profile_return_type':'Vaulter\\Profiles\\Profile','matches':len(profile_lines)})
resource=(root/'packages/drive/src/Http/Resources/DriveResource.php').read_text().split('private function abilities',1)[0]
keys=set(re.findall(r"^            '([^']+)' =>",resource,re.M))
for path in ['docs/reference/rest-api.md','docs/ru/reference/rest-api.md']:
    doc=(root/path).read_text();block=doc.split('(`DriveResource`):',1)[1].split('```json',1)[1].split('```',1)[0]
    observed=set(json.loads(block)['data'])
    if observed!=keys:errors.append(path+': DriveResource wire keys differ: '+str(sorted(observed^keys)))
    if 'ShowDriveController::__invoke' not in doc:errors.append(path+': ShowDriveController invocation missing')
    prose_checks.append({'path':path,'DriveResource_wire_keys':sorted(observed),'source_consistent':observed==keys})
print(json.dumps({'prose_contract_consistency':prose_checks,'StorageResource_filament_is_live_and_allowed':True}))
if errors:
    print('\n'.join(errors)); sys.exit(1)
print(json.dumps({'scanned_files':len(files),'old_fqns':len(old),'matches':0,'obsolete_paths':0,'dead_classes':0,'guidance':'empty'}))
