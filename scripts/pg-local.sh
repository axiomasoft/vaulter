#!/usr/bin/env bash
# A PostgreSQL test server that outlives a run (local work only).
#
#   scripts/pg-local.sh up       start it in /dev/shm (idempotent), write var/pg-local.env
#   scripts/pg-local.sh down     stop it and drop its data
#   scripts/pg-local.sh status   up|down
#
# While it is up, `scripts/test-db.sh pgsql` (so `scripts/t pgsql` and `scripts/t --changed --db=pgsql`) reuses it as a
# DB_EXTERNAL server instead of initialising and stopping a private one per run (~9 s each). Same fast, throw-away
# settings as db-test-env.sh; databases still end in _test. Nothing here is for CI.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
env_file="$root/var/pg-local.env"

alive() {
    [[ -f "$env_file" ]] || return 1
    # shellcheck disable=SC1090
    ( source "$env_file"; "$PG_LOCAL_BIN/pg_isready" -q -h 127.0.0.1 -p "$DB_PORT" )
}

case "${1:-}" in
    up)
        if alive; then echo 'pg-local: already up'; exit 0; fi
        mkdir -p "$root/var"
        # shellcheck source=scripts/db-test-env.sh
        source "$root/scripts/db-test-env.sh"
        # db_up makes its own mktemp directory under VAULTER_DB_ROOT; /dev/shm keeps the data in memory. The server outlives
        # this shell on purpose: DB_PERSISTENT=1 starts no watchdog and keeps scripts/db-reap.sh away from it.
        VAULTER_DB_ROOT=/dev/shm DB_PERSISTENT=1 db_up pgsql 8
        {
            printf 'PG_LOCAL_BIN=%q\nPG_LOCAL_DIR=%q\n' "$DB_PG_BIN" "$DB_SERVER_DIR"
            printf 'export DB_HOST=%q DB_PORT=%q DB_USERNAME=%q DB_PASSWORD=%q DB_DATABASE=%q DB_CONNECTION=pgsql\n' \
                "$DB_HOST" "$DB_PORT" "$DB_USERNAME" "$DB_PASSWORD" "$DB_DATABASE"
        } > "$env_file"
        echo "pg-local: up on port $DB_PORT ($DB_SERVER_DIR)"
        ;;
    down)
        if [[ -f "$env_file" ]]; then
            # shellcheck disable=SC1090
            source "$env_file"
            # The same stop as db_down: every server process of the directory, TERM then KILL, the directory only after.
            [[ "$PG_LOCAL_DIR" == /dev/shm/vaulter-dbtest.* ]] && "$root/scripts/db-reap.sh" --stop "$PG_LOCAL_DIR"
            rm -f -- "$env_file"
        fi
        echo 'pg-local: down'
        ;;
    status) if alive; then echo up; else echo down; fi ;;
    *) echo 'usage: scripts/pg-local.sh up|down|status' >&2; exit 2 ;;
esac
