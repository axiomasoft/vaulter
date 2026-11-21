#!/usr/bin/env bash
# AtomicDocumentMutation reference on a fresh PostgreSQL 18 cluster: OWNER db (core, media, drive, documents,
# ai-documents) and a separate CORE db (ai-core). Requires PHP 8.4 with pdo_pgsql, posix, sodium. No external DSN.
set -euo pipefail
root=$(cd "$(dirname "$0")/../../.." && pwd)
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
"$bin/initdb" -D "$work/db" -U "$PGUSER" --encoding=UTF8 --no-locale --auth-local=trust --auth-host=reject >"$work/init.log"
"$bin/pg_ctl" -D "$work/db" -l "$work/server.log" -o "-k '$work' -c listen_addresses='' -c unix_socket_permissions=0700" -w start >/dev/null
started=1
psql=("$bin/psql" -X -q -v ON_ERROR_STOP=1)
"$bin/createdb" owner_db
"$bin/createdb" core_db
for module in core media drive documents ai-documents; do "${psql[@]}" -d owner_db -f "$root/plan/spec/database/sql/$module.sql"; done
"${psql[@]}" -d core_db -f "$root/plan/spec/database/sql/ai-core.sql"
"${psql[@]}" -Atc "SELECT version()"
php -r 'echo "PHP ", PHP_VERSION, PHP_EOL;'
OWNER_DSN="pgsql:host=$work;port=5432;dbname=owner_db;user=$PGUSER" \
CORE_DSN="pgsql:host=$work;port=5432;dbname=core_db;user=$PGUSER" \
    php "$root/plan/checks/atomic-mutation/scenario.php"
