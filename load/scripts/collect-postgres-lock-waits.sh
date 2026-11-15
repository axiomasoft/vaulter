#!/bin/sh
# Sample PostgreSQL lock wait durations for `vaulter_pg_lock_wait_seconds`.
# Writes Prometheus text exposition lines to stdout (harness sink / §7).
#
# Usage:
#   DB_HOST=postgres DB_PORT=5432 DB_DATABASE=vaulter_load DB_USERNAME=vaulter DB_PASSWORD=secret \
#     ./load/scripts/collect-postgres-lock-waits.sh
set -eu

DB_HOST="${DB_HOST:-postgres}"
DB_PORT="${DB_PORT:-5432}"
DB_DATABASE="${DB_DATABASE:-vaulter_load}"
DB_USERNAME="${DB_USERNAME:-vaulter}"
export PGPASSWORD="${DB_PASSWORD:-secret}"

psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USERNAME" -d "$DB_DATABASE" -At -F',' -c "
SELECT COALESCE(EXTRACT(EPOCH FROM (now() - a.query_start)), 0)
FROM pg_locks l
JOIN pg_stat_activity a ON a.pid = l.pid
WHERE NOT l.granted
  AND a.datname = current_database()
  AND a.query_start IS NOT NULL
" | while IFS= read -r seconds; do
    [ -z "$seconds" ] && continue
    printf '# TYPE vaulter_pg_lock_wait_seconds histogram\n'
    printf 'vaulter_pg_lock_wait_seconds_bucket{le=\"1\"} 0\n'
    printf 'vaulter_pg_lock_wait_seconds_bucket{le=\"+Inf\"} 1\n'
    printf 'vaulter_pg_lock_wait_seconds_sum %s\n' "$seconds"
    printf 'vaulter_pg_lock_wait_seconds_count 1\n'
done
