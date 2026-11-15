#!/bin/sh
# One PostgreSQL evidence snapshot. Missing logfile stays JSON null.
# Usage:
#   ./load/scripts/p5-postgres-snapshot.sh syntax
#   DB_HOST=postgres DB_PORT=5432 DB_DATABASE=vaulter_load \
#     DB_USERNAME=vaulter DB_PASSWORD=secret \
#     ./load/scripts/p5-postgres-snapshot.sh snapshot
set -eu

mode="${1:-snapshot}"

# Blocker relation is pg_blocking_pids(). The self-join below is diagnostic only.
postgres_snapshot_sql() {
    cat <<'SQL'
SELECT json_build_object(
  'schema', 'p5-pg-snapshot/v1',
  'deadlocks', (SELECT deadlocks FROM pg_stat_database WHERE datname = current_database()),
  'log_cursor', pg_current_logfile(),
  'locks', COALESCE((
    SELECT json_agg(json_build_object(
      'pid', l.pid,
      'granted', l.granted,
      'locktype', l.locktype,
      'mode', l.mode,
      'waitstart', l.waitstart
    ))
    FROM pg_locks l
    JOIN pg_stat_activity a ON a.pid = l.pid
    WHERE a.datname = current_database()
      AND a.pid <> pg_backend_pid()
  ), '[]'::json),
  'activity', COALESCE((
    SELECT json_agg(json_build_object(
      'pid', a.pid,
      'wait_event_type', a.wait_event_type,
      'wait_event', a.wait_event,
      'state', a.state
    ))
    FROM pg_stat_activity a
    WHERE a.datname = current_database()
      AND a.pid <> pg_backend_pid()
  ), '[]'::json),
  'blockers', COALESCE((
    SELECT json_agg(json_build_object(
      'pid', l.pid,
      'blocker_pids', pg_blocking_pids(l.pid)
    ))
    FROM pg_locks l
    JOIN pg_stat_activity a ON a.pid = l.pid
    WHERE NOT l.granted
      AND a.datname = current_database()
  ), '[]'::json),
  'diagnostic_lock_join', COALESCE((
    SELECT json_agg(json_build_object(
      'waiter_pid', w.pid,
      'holder_pid', h.pid,
      'diagnostic_only', true
    ))
    FROM pg_locks w
    JOIN pg_locks h
      ON h.granted
     AND NOT w.granted
     AND h.locktype = w.locktype
     AND h.pid <> w.pid
    WHERE w.locktype = 'advisory'
  ), '[]'::json)
)::text;
SQL
}

case "$mode" in
    syntax)
        sql=$(postgres_snapshot_sql)
        printf '%s\n' "$sql" | grep -q 'pg_blocking_pids'
        printf '%s\n' "$sql" | grep -q 'pg_locks'
        printf '%s\n' "$sql" | grep -q 'pg_stat_activity'
        printf '%s\n' "$sql" | grep -q 'pg_stat_database'
        printf '%s\n' "$sql" | grep -q 'diagnostic_only'
        printf '%s\n' "$sql" | grep -q 'pg_advisory_lock(' && exit 1 || true
        printf 'p5-postgres-snapshot syntax: ok\n'
        ;;
    snapshot)
        DB_HOST="${DB_HOST:-postgres}"
        DB_PORT="${DB_PORT:-5432}"
        DB_DATABASE="${DB_DATABASE:-vaulter_load}"
        DB_USERNAME="${DB_USERNAME:-vaulter}"
        export PGPASSWORD="${DB_PASSWORD:-secret}"
        postgres_snapshot_sql | psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USERNAME" -d "$DB_DATABASE" -At -v ON_ERROR_STOP=1
        ;;
    *)
        echo "usage: $0 syntax|snapshot" >&2
        exit 1
        ;;
esac
