#!/bin/sh
# Physical standby for the promotion lab. Fail closed until primary is ready.
set -eu

DATA=${PGDATA:-/var/lib/postgresql/data}
PRIMARY_HOST=${PRIMARY_HOST:-postgres-primary}
PRIMARY_PORT=${PRIMARY_PORT:-5432}

if [ "$(id -u)" = 0 ]; then
    mkdir -p "$DATA"
    chown -R postgres:postgres "$DATA"
    exec su-exec postgres "$0" "$@"
fi

if [ ! -f "$DATA/standby.signal" ] && [ ! -f "$DATA/PG_VERSION" ]; then
    until pg_isready -h "$PRIMARY_HOST" -p "$PRIMARY_PORT" -U vaulter -d vaulter_chaos; do
        sleep 1
    done
    rm -rf "$DATA"/*
    PGPASSWORD=secret pg_basebackup \
        -h "$PRIMARY_HOST" \
        -p "$PRIMARY_PORT" \
        -U replicator \
        -D "$DATA" \
        -Fp -Xs -P -R
fi

chmod 0700 "$DATA"
exec postgres \
    -c hot_standby=on \
    -c wal_level=replica
