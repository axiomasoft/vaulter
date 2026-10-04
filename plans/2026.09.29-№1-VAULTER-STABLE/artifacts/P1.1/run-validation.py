import json, os, subprocess, time
from pathlib import Path
root = Path(__file__).resolve().parents[4]
out = Path(__file__).resolve().parent
sq = os.environ | dict(DB_CONNECTION="sqlite", DB_DATABASE=":memory:", APP_ENV="testing")
pg = os.environ | dict(DB_CONNECTION="pgsql", DB_HOST="127.0.0.1", DB_PORT="5432", DB_DATABASE="vaulter_test", DB_USERNAME="vaulter", DB_PASSWORD="secret", APP_ENV="testing")
php = str(root / "scripts/php84")
checks = []
for pkg in ["core", "drive", "documents", "media", "filament"]:
    cwd = root if pkg == "core" else root / "packages" / pkg
    checks += [(pkg+"-pint", cwd, sq, [php,"vendor/bin/pint","--test", "packages/core"] if pkg=="core" else [php,"vendor/bin/pint","--test"]),
               (pkg+"-phpstan", cwd, sq, [php,"vendor/bin/phpstan","analyse","--no-progress","--memory-limit="+("1G" if pkg=="core" else "512M")] + (["-c","phpstan.neon"] if pkg=="core" else [])),
               (pkg+"-pest", cwd, sq, [php,"vendor/bin/pest","--ci"] + (["packages/core/tests"] if pkg=="core" else []))]
checks += [("database-pgsql", root, pg, [php,"vendor/bin/pest","--ci","packages/core/tests/Database"]),
           ("drive-uploads-concurrency-pgsql", root/"packages/drive", pg, [php,"vendor/bin/pest","--ci","tests/Uploads","tests/Concurrency"]),
           ("media-cas-pgsql", root/"packages/media", pg, [php,"vendor/bin/pest","--ci","tests/Cas"])]
receipts=[]
for name,cwd,env,cmd in checks:
    started=time.monotonic()
    with (out/(name+".log")).open("w") as log:
        run=subprocess.run(cmd,cwd=cwd,env=env,stdout=log,stderr=subprocess.STDOUT)
    receipt=dict(name=name,command=cmd,cwd=str(cwd),database=env["DB_DATABASE"],driver=env["DB_CONNECTION"],exit=run.returncode,duration_s=round(time.monotonic()-started,2),log=name+".log")
    receipts.append(receipt)
    (out/"validation-receipts.json").write_text(json.dumps(receipts,indent=2)+"\n")
    print(name,run.returncode,receipt["duration_s"],flush=True)
