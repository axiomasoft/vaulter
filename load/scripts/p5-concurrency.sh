#!/bin/sh
# P5.1 — tier-S concurrency lane: dry-run | run | verify | check-stand
# Product fixtures and the live matrix belong to P5.2/P5.3.
# Usage:
#   TIER=S ./load/scripts/p5-concurrency.sh dry-run
#   TIER=S ./load/scripts/p5-concurrency.sh check-stand
#   TIER=S ./load/scripts/p5-concurrency.sh verify --run-dir load/gates/fixtures/p5/complete
#   P5_SQL_LIVE=1 TIER=S ./load/scripts/p5-concurrency.sh run \
#       --cell primitive-control.advisory-primitive.try-lock-nowait.n1
set -eu

SCRIPT_DIR=$(CDPATH= cd "$(dirname "$0")" && pwd)
LOAD_ROOT=$(CDPATH= cd "$SCRIPT_DIR/.." && pwd)
REPO_ROOT=$(CDPATH= cd "$LOAD_ROOT/.." && pwd)
TIER=${TIER:-S}
ENV_FILE=$LOAD_ROOT/env/${TIER}.env
GATE=$REPO_ROOT/load/gates/p5_concurrency.py
COMPOSE_FILE=$LOAD_ROOT/docker-compose.load.yml
PG_SNAPSHOT=$SCRIPT_DIR/p5-postgres-snapshot.sh
PGBOUNCER_SNAPSHOT=$SCRIPT_DIR/p5-pgbouncer-snapshot.sh
RESULTS_ROOT=$LOAD_ROOT/results/p5

usage() {
    echo "usage: TIER=S $0 dry-run|run|verify|check-stand [--run-dir DIR|--cell ID]" >&2
    exit 1
}

COMMAND=
RUN_DIR=
CELL=

while [ $# -gt 0 ]; do
    case "$1" in
        dry-run|run|verify|check-stand)
            COMMAND=$1
            shift
            ;;
        --run-dir)
            RUN_DIR=$2
            shift 2
            ;;
        --cell)
            CELL=$2
            shift 2
            ;;
        *)
            usage
            ;;
    esac
done

[ -n "$COMMAND" ] || usage
[ -f "$ENV_FILE" ] || {
    echo "Unknown TIER=${TIER} (missing ${ENV_FILE})" >&2
    exit 1
}

case "$COMMAND" in
    dry-run)
        python3 "$GATE" axes --tier "$TIER" --env "$ENV_FILE"
        python3 "$GATE" catalog
        echo "p5-concurrency dry-run: ok tier=${TIER} docker=no db-write=no"
        ;;
    verify)
        [ -n "$RUN_DIR" ] || usage
        python3 "$GATE" verify --run-dir "$RUN_DIR"
        ;;
    check-stand)
        docker compose -f "$COMPOSE_FILE" --env-file "$ENV_FILE" config > /tmp/p5-compose-config.$$
        config=/tmp/p5-compose-config.$$
        grep -q 'log_lock_waits: "on"' "$config" || grep -q 'log_lock_waits=on' "$config"
        grep -q 'deadlock_timeout: 1s' "$config" || grep -q 'deadlock_timeout=1s' "$config"
        grep -q 'POOL_MODE: transaction' "$config"
        grep -q 'ADMIN_USERS: vaulter' "$config"
        grep -q 'edoburu/pgbouncer' "$config"
        rm -f "$config"
        "$PG_SNAPSHOT" syntax
        "$PGBOUNCER_SNAPSHOT" syntax
        echo "p5-concurrency check-stand: ok tier=${TIER}"
        ;;
    run)
        [ -n "$CELL" ] || CELL=primitive-control.advisory-primitive.try-lock-nowait.n1
        case "$CELL" in
            primitive-control.*)
                ;;
            *)
                echo "p5-concurrency run: product cell ${CELL} is P5.2; P5.1 executes primitive-control only" >&2
                exit 1
                ;;
        esac
        if [ "${P5_SQL_LIVE:-0}" != 1 ]; then
            echo "p5-concurrency run: set P5_SQL_LIVE=1 to open disposable SQL actors; dry-run does not write" >&2
            exit 1
        fi
        db=${P5_SQL_DATABASE:-}
        if [ -z "$db" ] || [ "$db" = "vaulter_load" ]; then
            echo "p5-concurrency run: P5_SQL_DATABASE must be a disposable database" >&2
            exit 1
        fi
        shape=${CELL#*.}
        shape=${shape#*.}
        shape=${shape%.*}
        n=${CELL##*.n}
        staging=$(mktemp -d)
        trap 'rm -rf "$staging"' EXIT INT TERM
        python3 "$GATE" barrier --n "$n" --out "$staging/barrier"
        sql=$(python3 "$GATE" print-sql --shape "$shape")
        printf '%s\n' "$sql" | grep -q 'pg_advisory_xact_'
        if printf '%s\n' "$sql" | grep -E -q 'pg_advisory_lock\(|pg_try_advisory_lock\(|pg_advisory_unlock\('; then
            echo "p5-concurrency run: session lock SQL refused" >&2
            exit 1
        fi
        i=0
        while [ "$i" -lt "$n" ]; do
            printf '%s\n' "$sql" | PGPASSWORD="${DB_PASSWORD:-secret}" \
                psql -h "${DB_HOST:-127.0.0.1}" -p "${DB_PORT:-5432}" \
                -U "${DB_USERNAME:-vaulter}" -d "$db" -v ON_ERROR_STOP=1 -At >/dev/null &
            i=$((i + 1))
        done
        wait
        echo "p5-concurrency run: barrier n=${n} shape=${shape}; not published"
        rm -rf "$staging"
        trap - EXIT INT TERM
        ;;
esac
