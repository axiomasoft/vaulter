import json,re,sys
from pathlib import Path
root=Path.cwd(); pairs=json.loads((root/'plans/2026.09.29-№1-VAULTER-STABLE/artifacts/P2.1/rename-map.json').read_text()); file=root/'packages/core/tests/Arch/__snapshots__/public-api.txt'; text=file.read_text()
old=[a for a,b in pairs if re.search(re.escape('Vaulter\\'+a.replace('/','\\'))+r'(?![A-Za-z0-9_\\])',text)]
required=['Vaulter\\Contracts\\Access\\PermissionDriver','Vaulter\\Contracts\\Handlers\\FileHandlerRegistry','Vaulter\\Contracts\\Handlers\\NodePresenter','Vaulter\\Enums\\Events\\EventType','Vaulter\\ValueObjects\\Handlers\\ViewerDescriptor']
missing=[n for n in required if n not in text]
print(json.dumps({'golden':str(file),'old_fqns':old,'missing_current_fqns':missing,'UPDATE_SNAPSHOT':'unset by test argv; existing golden compared without generation'})); sys.exit(bool(old or missing))
