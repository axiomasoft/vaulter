#!/usr/bin/env bash
# Refuse non-test targets before opening a database connection.
set -euo pipefail

driver="${1:-}"
case "$driver" in
    pgsql) port=5432 ;;
    mysql) port=3306 ;;
    *) echo 'Usage: scripts/db-preflight.sh <pgsql|mysql>' >&2; exit 1 ;;
esac
database="${DB_DATABASE:-vaulter_test}"
if [[ "$database" != *_test ]]; then
    echo "db-preflight: database must end with _test: $database" >&2
    exit 1
fi
host="${DB_HOST:-127.0.0.1}"
port="${DB_PORT:-$port}"
username="${DB_USERNAME:-vaulter}"
password="${DB_PASSWORD:-secret}"

if [[ "$driver" == pgsql ]]; then
    command -v psql >/dev/null || { echo 'db-preflight: psql not found' >&2; exit 1; }
    if version="$(LC_ALL=C PGCONNECT_TIMEOUT=5 PGPASSWORD="$password" psql -X -w -h "$host" -p "$port" -U "$username" -d "$database" -Atc 'select version()' 2>&1)"; then
        :
    else
        echo "$version" >&2
        if [[ "$version" == *'authentication failed'* || "$version" == *'no password supplied'* ]]; then
            echo "db-preflight: owner gate O-1: sudo -u postgres psql -c \"CREATE ROLE vaulter LOGIN PASSWORD 'secret' CREATEDB\" && sudo -u postgres createdb -O vaulter vaulter_test" >&2
            exit 2
        fi
        exit 1
    fi
else
    command -v mysql >/dev/null || { echo 'db-preflight: mysql not found' >&2; exit 1; }
    if version="$(LC_ALL=C MYSQL_PWD="$password" mysql --no-defaults --connect-timeout=5 --protocol=TCP -h "$host" -P "$port" -u "$username" -D "$database" -N -B -e 'select version()' 2>&1)"; then
        :
    else
        echo "$version" >&2
        if [[ "$version" == *'Access denied for user'* ]]; then
            echo "db-preflight: owner gate O-2: sudo mysql -e \"CREATE USER 'vaulter'@'localhost' IDENTIFIED BY 'secret'; CREATE DATABASE vaulter_test; GRANT ALL ON vaulter_test.* TO 'vaulter'@'localhost';\"" >&2
            exit 2
        fi
        exit 1
    fi
fi
echo "db-preflight OK: $driver $database ($version)"
