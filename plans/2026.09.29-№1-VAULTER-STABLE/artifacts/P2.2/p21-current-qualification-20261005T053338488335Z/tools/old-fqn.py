import json, re, sys
from pathlib import Path
root=Path.cwd(); base=Path(sys.argv[1]); art=root/'plans/2026.09.29-№1-VAULTER-STABLE/artifacts/P2.1'
pairs=json.loads((art/'rename-map.json').read_text())
shorts=['HostRoleDriver','NullRoleDriver','AclScope','HandlerRegistry','NodeHandlerResolver','HandlerUi','HandlerDescriptor','HandlerCapabilities','PanelTablesBuilder','HasVaulterPanel','StoragePanelProvider','DefaultStoragePanel','TenantIsolationNotConfiguredException','PanelMigrationGenerator','PanelTableResolver','PanelMigrationsCommand','PermissionLevel','GrantNodeAccessData','CreateShareLinkData','RegisterWebhookEndpointData','UpdateWebhookEndpointData']
pat=re.compile(r'(?<![A-Za-z0-9_])(?:'+'|'.join(shorts)+r')(?![A-Za-z0-9_])')
fqns=[re.compile(r'(?<![A-Za-z0-9_])'+re.escape('Vaulter\\'+a.replace('/','\\'))+r'(?![A-Za-z0-9_\\])') for a,b in pairs]
files=[]
for pkg in ['core','drive','documents','media','filament','azgard']:
 for part in ['src','tests','config','database','routes']:
  directory=root/'packages'/pkg/part
  if directory.exists(): files.extend(directory.rglob('*.php'))
files.extend((root/'scripts').rglob('*.php')); files.append(root/'scripts/php84')
for part in ['guide','reference','ru']: files.extend((root/'docs'/part).rglob('*.md'))
files += [root/'UPGRADE.md',root/'CHANGELOG.md']
hits=[]
for p in sorted(set(files)):
 for i,line in enumerate(p.read_text().splitlines(),1):
  if p.name=='UPGRADE.md' and line.startswith('|') and len(line.split('|'))>=4: line='|'.join(line.split('|')[2:])
  normalized=re.sub(r'\\+',lambda m:'\\',line)
  if pat.search(normalized) or any(f.search(normalized) for f in fqns): hits.append(f'{p.relative_to(root)}:{i}:{line}')
(base/'observed/old-symbols.log').open('x').write('\n'.join(hits)+'\n' if hits else '')
print(json.dumps({'scanned_files':len(set(files)),'core_old_fqns':len(pairs),'core_short_removed_symbols':len(shorts),'matches':len(hits),'guidance':'empty' if not hits else 'red','map':str(art/'rename-map.json'),'historical_exception':'UPGRADE Before column only','scope':'P2.1 Code Guidance only; no future Documents rename claims'}))
if hits: print('\n'.join(hits))
sys.exit(bool(hits))
