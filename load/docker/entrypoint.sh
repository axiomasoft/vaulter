#!/bin/sh
# VLT-LOAD P2.2 — SUT entrypoint: render the FPM pool for this tier, migrate against
# Postgres DIRECTLY (bypassing PgBouncer — transaction-mode pooling and DDL/prepared-
# statement reuse don't mix, a well-known PgBouncer caveat; request-serving below still
# goes through DB_HOST=pgbouncer as set by docker-compose.load.yml), then run nginx+fpm.
set -eu

: "${PM_MAX_CHILDREN:?PM_MAX_CHILDREN must be set — see load/env/<tier>.env}"

envsubst '${PM_MAX_CHILDREN}' \
    < /usr/local/etc/php-fpm.d/www.conf.template \
    > /usr/local/etc/php-fpm.d/www.conf

cd /app

if [ "${LOAD_SKIP_MIGRATIONS:-0}" != "1" ]; then
    DB_HOST="${DB_MIGRATE_HOST:-postgres}" DB_PORT="${DB_MIGRATE_PORT:-5432}" \
        vendor/bin/testbench migrate:fresh --force
fi

if [ "$#" -gt 0 ]; then
    exec "$@"
fi

# `user = root` in the pool (chosen so the bind-mounted, host-uid-owned repo under /app
# stays writable without a uid dance — this is throwaway bench tooling, not prod) needs
# an explicit opt-in past FPM's root safety check.
php-fpm -D --allow-to-run-as-root
exec nginx -g 'daemon off;'
