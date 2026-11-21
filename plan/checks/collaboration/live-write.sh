#!/usr/bin/env bash
# Plan 3.5: a live-room command committed by the owner, then the room process killed before the apply.
# Fresh PostgreSQL 18 cluster (like plan/checks/schema.sh), the pinned Node sample of
# plan/examples/reference/live-write (npm ci from its lockfile), separate Node processes. No external DSN.
set -euo pipefail
root=$(cd "$(dirname "$0")/../../.." && pwd)
sample="$root/plan/examples/reference/live-write"
bin=${PG_BIN:-$(pg_config --bindir)}
work=$(mktemp -d)
started=0
finish() {
    if (( started )); then "$bin/pg_ctl" -D "$work/db" -m fast -w stop >/dev/null; fi
    rm -rf -- "$work"
}
trap finish EXIT
unset PGSERVICE PGOPTIONS PGDATABASE PGHOSTADDR
export PGHOST="$work" PGPORT=5432 PGUSER=spec_owner PGDATABASE=postgres
"$bin/initdb" -D "$work/db" -U "$PGUSER" --encoding=UTF8 --no-locale --auth-local=trust --auth-host=reject > "$work/init.log"
"$bin/pg_ctl" -D "$work/db" -l "$work/server.log" -o "-k '$work' -c listen_addresses='' -c unix_socket_permissions=0700" -w start >/dev/null
started=1
psql=("$bin/psql" -X -q -v ON_ERROR_STOP=1)
"${psql[@]}" -Atc "SELECT version()"
"$bin/createdb" live
for module in core media drive documents webhooks collaboration collaboration-agent; do
    "${psql[@]}" -d live -f "$root/plan/spec/database/sql/$module.sql"
done
"${psql[@]}" -d live -f "$root/plan/checks/collaboration/live-write-owner.sql"

echo "node $(node --version)"
npm ci --prefix "$sample" --ignore-scripts --no-audit --no-fund --prefer-offline --loglevel=error
# Every package of the sample lock must carry a version the Hub lockfile pins.
python3 - "$sample/package-lock.json" "$root/packages/collaboration/hub/package-lock.json" <<'PY'
import json, sys
mine = json.load(open(sys.argv[1]))['packages']
hub = {}
for key, value in json.load(open(sys.argv[2]))['packages'].items():
    if 'node_modules/' in key:
        hub.setdefault(key.split('node_modules/')[-1], set()).add(value.get('version'))
foreign = [(key, value['version']) for key, value in mine.items()
           if key and value['version'] not in hub.get(key.split('node_modules/')[-1], set())]
if foreign:
    sys.exit(f'versions outside the Hub lock: {foreign}')
print(f'OK sample lock: {len(mine) - 1} packages, all at Hub lock versions')
PY

PSQL="$bin/psql" PGDATABASE=live node "$root/plan/checks/collaboration/live-write.test.mjs"
node "$sample/hocuspocus-paths.mjs"
