#!/bin/sh
# P4.3 — migrate (via entrypoint) then FPM + matrix capture for one attempt.
set -eu

RUN_DIR="${1:?}"
CELL_ID="${2:?}"
FIXTURE_ID="${3:?}"
RUN_PREFIX="${4:?}"
FINGERPRINT="${5:?}"
TIER="${6:?}"
RUN_ID="${7:?}"
ATTEMPT_ID="${8:?}"

: "${PM_MAX_CHILDREN:?PM_MAX_CHILDREN must be set}"

envsubst '${PM_MAX_CHILDREN}' \
    < /usr/local/etc/php-fpm.d/www.conf.template \
    > /usr/local/etc/php-fpm.d/www.conf

# Memory lane template replaces [www]; drop stock pool fragments that still declare pool "www".
for extra in /usr/local/etc/php-fpm.d/*.conf; do
    case "$extra" in
        */www.conf) ;;
        *) rm -f "$extra" ;;
    esac
done

php-fpm -D --allow-to-run-as-root
nginx -g 'daemon off;' &
for i in $(seq 1 60); do
    wget -q -O- 'http://127.0.0.1:8081/fpm-status?json' >/dev/null 2>&1 && break
    sleep 1
done
python3 /app/load/scripts/p4-memory-sampler.py preflight >/dev/null
exec sh /app/load/scripts/p4-memory-matrix-capture-in-container.sh \
    "$RUN_DIR" "$CELL_ID" "$FIXTURE_ID" "$RUN_PREFIX" "$FINGERPRINT" "$TIER" "$RUN_ID" "$ATTEMPT_ID"
