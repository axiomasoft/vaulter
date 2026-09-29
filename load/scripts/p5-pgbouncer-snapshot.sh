#!/bin/sh
# P5.1 — PgBouncer SHOW POOLS / SHOW STATS / SHOW CONFIG.
# A failed admin query exits non-zero. Fields are copied from named columns
# and never replaced with zero when the column is absent.
# Usage:
#   ./load/scripts/p5-pgbouncer-snapshot.sh syntax
#   PGBOUNCER_HOST=127.0.0.1 PGBOUNCER_PORT=6432 PGBOUNCER_USER=vaulter \
#     PGBOUNCER_PASSWORD=secret ./load/scripts/p5-pgbouncer-snapshot.sh snapshot
set -eu

admin_queries() {
    cat <<'SQL'
SHOW POOLS;
SHOW STATS;
SHOW CONFIG;
SQL
}

mode="${1:-snapshot}"

case "$mode" in
    syntax)
        queries=$(admin_queries)
        printf '%s\n' "$queries" | grep -qx 'SHOW POOLS;'
        printf '%s\n' "$queries" | grep -qx 'SHOW STATS;'
        printf '%s\n' "$queries" | grep -qx 'SHOW CONFIG;'
        printf 'p5-pgbouncer-snapshot syntax: ok\n'
        exit 0
        ;;
    snapshot)
        ;;
    *)
        echo "usage: $0 syntax|snapshot" >&2
        exit 1
        ;;
esac

PGBOUNCER_HOST="${PGBOUNCER_HOST:-127.0.0.1}"
PGBOUNCER_PORT="${PGBOUNCER_PORT:-6432}"
PGBOUNCER_USER="${PGBOUNCER_USER:-vaulter}"
PGBOUNCER_ADMIN_DB="${PGBOUNCER_ADMIN_DB:-pgbouncer}"
export PGPASSWORD="${PGBOUNCER_PASSWORD:-${DB_PASSWORD:-secret}}"

tmpdir=$(mktemp -d)
trap 'rm -rf "$tmpdir"' EXIT INT TERM
admin_queries > "$tmpdir/queries"

index=0
while IFS= read -r query; do
    index=$((index + 1))
    psql -h "$PGBOUNCER_HOST" -p "$PGBOUNCER_PORT" -U "$PGBOUNCER_USER" \
        -d "$PGBOUNCER_ADMIN_DB" --csv -v ON_ERROR_STOP=1 -c "$query" \
        > "$tmpdir/$index.csv"
done < "$tmpdir/queries"

python3 - "$tmpdir/1.csv" "$tmpdir/2.csv" "$tmpdir/3.csv" <<'PY'
import csv, json, sys

def table(path):
    with open(path, newline='') as handle:
        return list(csv.DictReader(handle))

pools, stats, config = (table(path) for path in sys.argv[1:])
if not pools or not stats or not config:
    sys.stderr.write('evidence-invalid: PgBouncer admin returned no row\n')
    sys.exit(1)
cfg = {}
for row in config:
    key = row.get('key') or row.get('Key')
    value = row.get('value') if 'value' in row else row.get('Value')
    if key:
        cfg[key] = value
pool = pools[0]
stat = stats[0]

def need(source, name):
    if name not in source or source[name] is None or source[name] == '':
        sys.stderr.write(f'evidence-invalid: missing PgBouncer field {name}\n')
        sys.exit(1)
    return source[name]

payload = {
    'schema': 'p5-pgbouncer-snapshot/v1',
    'cl_waiting': int(need(pool, 'cl_waiting')),
    'sv_active': int(need(pool, 'sv_active')),
    'maxwait': int(need(pool, 'maxwait')),
    'avg_wait_time': int(need(stat, 'avg_wait_time')),
    'pool_mode': need(pool, 'pool_mode'),
    'default_pool_size': int(need(cfg, 'default_pool_size')),
    'max_client_conn': int(need(cfg, 'max_client_conn')),
    'query_wait_timeout': int(need(cfg, 'query_wait_timeout')),
}
sys.stdout.write(json.dumps(payload, sort_keys=True) + '\n')
PY
