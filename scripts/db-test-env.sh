#!/usr/bin/env bash
# Disposable database servers for test runs — no Docker, no root.
#
#   source scripts/db-test-env.sh
#   db_up <pgsql|mysql> [workers]   start a private server, create vaulter_test and vaulter_w1_test..vaulter_wN_test,
#                                   export DB_CONNECTION/DB_HOST/DB_PORT/DB_DATABASE/DB_USERNAME/DB_PASSWORD
#   db_create <name>                one more database (must end in _test), e.g. a lane of its own
#   db_down                         stop the server and delete its data directory
#   db_use_external <pgsql|mysql> [workers]
#                                   DB_EXTERNAL=1 mode (CI services, a server you run yourself): no server is started
#                                   or stopped; DB_DATABASE must end in _test, the worker databases
#                                   <base>_w1_test..<base>_wN_test are created when missing. DB_CONNECTION, if set,
#                                   must match the engine.
#
# The server runs as the current user on a free loopback port. Durability is traded for speed — fsync off,
# no binlog, no doublewrite — which is fine for data that is thrown away with the run; it changes no test
# behaviour. The `_test` suffix guard of the suites still applies to every database created here.
#
# Environment: DB_FAST=0 keeps durable settings. VAULTER_DB_ROOT sets the parent of the data directory
# (default /tmp; MySQL's AppArmor profile does not allow /dev/shm or /run).

DB_ENGINE_UP=''
DB_SERVER_DIR=''
DB_PG_BIN=''

_db_free_port() {
    python3 - <<'PY'
import socket
s = socket.socket()
s.bind(('127.0.0.1', 0))
print(s.getsockname()[1])
PY
}

db_up() {
    local engine="$1" workers="${2:-0}" n
    [[ -z "$DB_ENGINE_UP" ]] || { echo 'db_up: a server is already up' >&2; return 1; }
    DB_SERVER_DIR="$(mktemp -d "${VAULTER_DB_ROOT:-/tmp}/vaulter-dbtest.XXXXXX")" || return
    export DB_HOST=127.0.0.1 DB_USERNAME=vaulter DB_PASSWORD=secret DB_DATABASE=vaulter_test
    DB_PORT="$(_db_free_port)"
    export DB_PORT
    case "$engine" in
        pgsql) _db_up_pgsql || { db_down; return 1; } ;;
        mysql) _db_up_mysql || { db_down; return 1; } ;;
        *) echo "db_up: unknown engine [$engine]" >&2; db_down; return 1 ;;
    esac
    DB_ENGINE_UP="$engine"
    export DB_CONNECTION="$engine"
    db_create vaulter_test || return
    for ((n = 1; n <= workers; n++)); do
        db_create "vaulter_w${n}_test" || return
    done
}

_db_up_pgsql() {
    local fast=(-c fsync=off -c synchronous_commit=off -c full_page_writes=off)
    [[ "${DB_FAST:-1}" == 1 ]] || fast=()
    DB_PG_BIN="$(ls -d /usr/lib/postgresql/*/bin 2>/dev/null | sort -V | tail -1)"
    [[ -x "$DB_PG_BIN/initdb" ]] || { echo 'db_up: PostgreSQL server binaries not found' >&2; return 1; }
    "$DB_PG_BIN/initdb" -D "$DB_SERVER_DIR/data" -U "$DB_USERNAME" --auth=trust -E UTF8 >"$DB_SERVER_DIR/init.log" 2>&1 || return
    "$DB_PG_BIN/pg_ctl" -D "$DB_SERVER_DIR/data" -l "$DB_SERVER_DIR/server.log" -w start \
        -o "-p $DB_PORT -c listen_addresses=127.0.0.1 -c unix_socket_directories=$DB_SERVER_DIR -c max_connections=400 -c shared_buffers=256MB ${fast[*]}" >/dev/null || return
}

_db_up_mysql() {
    local fast=(--skip-log-bin --innodb-flush-log-at-trx-commit=0 --innodb-doublewrite=0 --innodb-flush-method=O_DIRECT_NO_FSYNC --innodb-file-per-table=OFF --innodb-buffer-pool-size=1G)
    [[ "${DB_FAST:-1}" == 1 ]] || fast=()
    command -v mysqld >/dev/null || { echo 'db_up: mysqld not found' >&2; return 1; }
    mkdir "$DB_SERVER_DIR/data" || return
    mysqld --no-defaults --initialize-insecure --datadir="$DB_SERVER_DIR/data" --user="$(id -un)" >"$DB_SERVER_DIR/init.log" 2>&1 || {
        echo 'db_up: mysqld --initialize failed (see '"$DB_SERVER_DIR"'/init.log; AppArmor may refuse this directory — set VAULTER_DB_ROOT=/tmp)' >&2
        return 1
    }
    (mysqld --no-defaults --datadir="$DB_SERVER_DIR/data" --user="$(id -un)" --port="$DB_PORT" --bind-address=127.0.0.1 \
        --socket="$DB_SERVER_DIR/my.sock" --mysqlx=OFF --max-connections=400 --log-bin-trust-function-creators=1 \
        --pid-file="$DB_SERVER_DIR/my.pid" "${fast[@]}" >"$DB_SERVER_DIR/server.log" 2>&1 &)
    local i
    for ((i = 0; i < 120; i++)); do
        mysqladmin --no-defaults -uroot -S "$DB_SERVER_DIR/my.sock" ping >/dev/null 2>&1 && break
        sleep 0.5
    done
    mysqladmin --no-defaults -uroot -S "$DB_SERVER_DIR/my.sock" ping >/dev/null 2>&1 || { echo 'db_up: mysqld did not start' >&2; return 1; }
    mysql --no-defaults -uroot -S "$DB_SERVER_DIR/my.sock" -e "CREATE USER '$DB_USERNAME'@'%' IDENTIFIED BY '$DB_PASSWORD'; GRANT ALL ON *.* TO '$DB_USERNAME'@'%'" || return
}

db_create() {
    local name="$1"
    [[ "$name" =~ ^[a-z0-9_]+_test$ ]] || { echo "db_create: [$name] must match [a-z0-9_]+_test" >&2; return 1; }
    case "$DB_ENGINE_UP" in
        pgsql) "$DB_PG_BIN/psql" -q -X -h 127.0.0.1 -p "$DB_PORT" -U "$DB_USERNAME" -d postgres -c "CREATE DATABASE \"$name\"" >/dev/null ;;
        mysql) mysql --no-defaults -uroot -S "$DB_SERVER_DIR/my.sock" -e "CREATE DATABASE \`$name\`" ;;
        *) echo 'db_create: no server is up' >&2; return 1 ;;
    esac
}

db_down() {
    case "$DB_ENGINE_UP" in
        pgsql) "$DB_PG_BIN/pg_ctl" -D "$DB_SERVER_DIR/data" -m immediate stop >/dev/null 2>&1 || true ;;
        mysql) mysqladmin --no-defaults -uroot -S "$DB_SERVER_DIR/my.sock" shutdown >/dev/null 2>&1 || true ;;
    esac
    if [[ -n "$DB_SERVER_DIR" && "$DB_SERVER_DIR" == */vaulter-dbtest.* ]]; then
        rm -rf -- "$DB_SERVER_DIR"
    fi
    DB_ENGINE_UP=''
    DB_SERVER_DIR=''
}

db_use_external() {
    local engine="$1" workers="${2:-0}" base n name
    case "$engine" in pgsql | mysql) ;; *) echo "db_use_external: unknown engine [$engine]" >&2; return 1 ;; esac
    if [[ -n "${DB_CONNECTION:-}" && "$DB_CONNECTION" != "$engine" ]]; then
        echo "db_use_external: DB_CONNECTION=$DB_CONNECTION does not match requested engine $engine" >&2
        return 2
    fi
    base="${DB_DATABASE:-vaulter_test}"
    [[ "$base" =~ ^[a-z0-9_]+_test$ ]] || { echo "db_use_external: DB_DATABASE [$base] must match [a-z0-9_]+_test" >&2; return 2; }
    export DB_CONNECTION="$engine" DB_DATABASE="$base"
    export DB_HOST="${DB_HOST:-127.0.0.1}" DB_USERNAME="${DB_USERNAME:-vaulter}" DB_PASSWORD="${DB_PASSWORD:-secret}"
    export DB_PORT="${DB_PORT:-$([[ "$engine" == pgsql ]] && echo 5432 || echo 3306)}"
    for ((n = 1; n <= workers; n++)); do
        name="${base%_test}_w${n}_test"
        case "$engine" in
            pgsql)
                PGPASSWORD="$DB_PASSWORD" psql -q -X -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USERNAME" -d postgres -tAc \
                    "SELECT 1 FROM pg_database WHERE datname = '$name'" | grep -q 1 \
                    || PGPASSWORD="$DB_PASSWORD" psql -q -X -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USERNAME" -d postgres -c "CREATE DATABASE \"$name\"" >/dev/null || return
                ;;
            mysql)
                MYSQL_PWD="$DB_PASSWORD" mysql --no-defaults --protocol=TCP -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USERNAME" \
                    -e "CREATE DATABASE IF NOT EXISTS \`$name\`" || return
                ;;
        esac
    done
}
