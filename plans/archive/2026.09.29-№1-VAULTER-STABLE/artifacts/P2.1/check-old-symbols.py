from pathlib import Path
import json,re,sys
root=Path(__file__).resolve().parents[4];art=Path(__file__).resolve().parent
pairs=json.loads((art/'rename-map.json').read_text())
shorts=['HostRoleDriver','NullRoleDriver','AclScope','HandlerRegistry','NodeHandlerResolver','HandlerUi','HandlerDescriptor','HandlerCapabilities','PanelTablesBuilder','HasVaulterPanel','StoragePanelProvider','DefaultStoragePanel','TenantIsolationNotConfiguredException','PanelMigrationGenerator','PanelTableResolver','PanelMigrationsCommand','PermissionLevel','GrantNodeAccessData','CreateShareLinkData','RegisterWebhookEndpointData','UpdateWebhookEndpointData']
pat=re.compile(r'(?<![A-Za-z0-9_])(?:'+'|'.join(shorts)+r')(?![A-Za-z0-9_])')
fqns=[re.compile(re.escape('Vaulter\\'+a.replace('/','\\'))+r'(?![A-Za-z0-9_])') for a,b in pairs]
paths=[]
for pkg in ['core','drive','documents','media','filament','azgard']:
 for part in ['src','tests','config','database','routes']:
  base=root/'packages'/pkg/part
  if base.exists():paths+=list(base.rglob('*.php'))
for part in ['guide','reference','ru']:paths+=list((root/'docs'/part).rglob('*.md'))
hits=[]
for p in paths:
 for i,line in enumerate(p.read_text().splitlines(),1):
  if pat.search(line) or any(f.search(line) for f in fqns):hits.append(f'{p.relative_to(root)}:{i}:{line}')
(art/'old-symbols.log').write_text('\n'.join(hits)+'\n' if hits else '')
print(json.dumps({'scanned_files':len(paths),'old_symbol_hits':len(hits)}))
sys.exit(bool(hits))
