import subprocess,sys
from pathlib import Path
root=Path.cwd()
files=['load/app/LoadServiceProvider.php', 'load/app/Console/PrepareMemoryFixturesCommand.php', 'load/app/Console/SeedLoadDatasetCommand.php']
for path in files:
 result=subprocess.run([str(root/'scripts/php84'),'php','-l',path])
 if result.returncode:sys.exit(result.returncode)
print('All 3 existing load/app compatibility callers linted')
