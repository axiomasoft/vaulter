#!/usr/bin/env bash
# Compile the specification on fresh PostgreSQL 18 databases. No external DSN.
set -euo pipefail
root=$(cd "$(dirname "$0")/../.." && pwd)
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
install_modules() {
    local db=$1 module
    shift
    for module in "$@"; do
        "${psql[@]}" -d "$db" -f "$root/plan/spec/database/sql/$module.sql"
    done
}
count_tables() {
    local observed
    observed=$("${psql[@]}" -d "$1" -Atc "SELECT count(*) FROM pg_tables WHERE schemaname='public'")
    [[ "$observed" == "$2" ]] || { echo "$1: expected $2 tables, found $observed" >&2; return 1; }
    echo "OK $1: $observed tables ($3)"
}
"${psql[@]}" -Atc "SELECT version()"
base=(core media drive documents)
"$bin/createdb" office
install_modules office "${base[@]}"
count_tables office 31 documents-basic
"${psql[@]}" -d office -f "$root/plan/checks/database/documents.sql"
python3 "$root/plan/checks/database/document-race.py" "$bin/psql"
install_modules office webhooks collaboration
"${psql[@]}" -d office -f "$root/plan/checks/collaboration/lease-schema.sql"
echo 'OK collaboration before agent extension: checkpoint 41 preserves pending update 42'
install_modules office collaboration-agent sheets sheets-exchange sheets-projections sheets-realtime ai-documents ai-core ai-chat editor-pro-review editor-pro-exchange
count_tables office 71 all-packages
"${psql[@]}" -d office -f "$root/plan/checks/collaboration/command-schema.sql"
"${psql[@]}" -d office -f "$root/plan/checks/database/sheets.sql"
"${psql[@]}" -d office -f "$root/plan/checks/database/ai.sql"
"${psql[@]}" -d office -f "$root/plan/checks/database/unindexed-fks.sql"
"$bin/createdb" ai_service
install_modules ai_service ai-core
count_tables ai_service 10 independent-ai-core
install_modules ai_service ai-chat
count_tables ai_service 14 independent-ai-core-with-chat
"${psql[@]}" -d ai_service -f "$root/plan/checks/database/unindexed-fks.sql"
"$bin/createdb" editor_pro_owner
install_modules editor_pro_owner "${base[@]}" editor-pro-review editor-pro-exchange
count_tables editor_pro_owner 35 editor-pro-without-ai-or-sheets
"${psql[@]}" -d editor_pro_owner -f "$root/plan/checks/database/unindexed-fks.sql"
"$bin/createdb" content_owner
install_modules content_owner "${base[@]}" ai-documents
count_tables content_owner 35 text-owner-without-ai-core
"${psql[@]}" -d content_owner -f "$root/plan/checks/database/unindexed-fks.sql"
"$bin/createdb" book_owner
install_modules book_owner "${base[@]}" sheets
count_tables book_owner 32 book-commands-only
install_modules book_owner sheets-projections
count_tables book_owner 39 book-projections-without-exchange-ai-or-realtime
install_modules book_owner sheets-exchange
count_tables book_owner 40 book-owner-with-exchange-without-ai-and-realtime
"${psql[@]}" -d book_owner -f "$root/plan/checks/database/sheets.sql"
"${psql[@]}" -d book_owner -f "$root/plan/checks/database/unindexed-fks.sql"
echo 'OK documents, live commands, books, AI constraints, and 0 unindexed FKs; isolated installations.'
