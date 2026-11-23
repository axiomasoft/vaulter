#!/usr/bin/env bash
set -euo pipefail

# Check the canonical SQL modules in a disposable server of the matrix version.
docker run --rm -i --network none --user postgres --entrypoint bash \
    -v "$PWD/plan:/spec:ro" "$1" -se <<'SQL_CHECK'
work=$(mktemp -d)
trap 'pg_ctl -D "$work/db" -m fast -w stop >/dev/null' EXIT
initdb -D "$work/db" --no-locale --encoding=UTF8 --auth-local=trust --auth-host=reject >/dev/null
pg_ctl -D "$work/db" -l "$work/log" -o "-k $work -c listen_addresses=''" -w start >/dev/null
export PGHOST="$work" PGDATABASE=postgres
for module in core media drive documents webhooks; do
    psql -Xq -v ON_ERROR_STOP=1 -f "/spec/spec/database/sql/$module.sql"
done
gate=/spec/checks/database/unindexed-fks.sql
psql -Xq -v ON_ERROR_STOP=1 -f "$gate"

# Confirm the catalog guard reports a real unindexed FK.
psql -Xq -v ON_ERROR_STOP=1 <<'DDL'
CREATE TABLE rail_parent (id bigint primary key);
CREATE TABLE rail_child (parent_id bigint references rail_parent(id));
DDL
if psql -Xq -v ON_ERROR_STOP=1 -f "$gate" >"$work/negative.log" 2>&1; then
    echo 'Expected the FK-index gate to reject rail_child.' >&2
    exit 1
fi
grep 'Unindexed foreign keys detected:.*rail_child' "$work/negative.log"
SQL_CHECK
