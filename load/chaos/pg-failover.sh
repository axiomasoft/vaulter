#!/bin/sh
# P7.2 PostgreSQL 16 physical-replication failover.
# Fence the old primary BEFORE writable routing. No automatic failback.
# Usage:
#   ./load/chaos/pg-failover.sh status|rehearse|promote|fence|repoint|revert|self-test
set -eu

SCRIPT_DIR=$(CDPATH= cd "$(dirname "$0")" && pwd)
LOAD_ROOT=$(CDPATH= cd "$SCRIPT_DIR/.." && pwd)
COMPOSE_BASE=$LOAD_ROOT/docker-compose.load.yml
COMPOSE_CHAOS=$LOAD_ROOT/docker-compose.chaos.yml
ENV_FILE=${ENV_FILE:-$LOAD_ROOT/env/${TIER:-S}.env}
PRIMARY=${PRIMARY_SERVICE:-postgres-primary}
STANDBY=${STANDBY_SERVICE:-postgres-standby}
PGBOUNCER=${PGBOUNCER_SERVICE:-pgbouncer-ha}
PROJECT=${COMPOSE_PROJECT:-vaulter-load}
STATE_DIR=${P7_FAILOVER_STATE:-${TMPDIR:-/tmp}/p7-failover}

compose() {
    docker compose -p "$PROJECT" -f "$COMPOSE_BASE" -f "$COMPOSE_CHAOS" \
        --env-file "$ENV_FILE" --profile chaos-ha "$@"
}

container() {
    compose ps -q "$1"
}

psql_in() {
    service=$1
    shift
    docker exec -i "$(container "$service")" \
        psql -U vaulter -d vaulter_chaos -v ON_ERROR_STOP=1 -A -t "$@"
}

is_chaos_target() {
    case "$1" in
        postgres-primary|postgres-standby|pgbouncer-ha|127.0.0.1|localhost)
            return 0
            ;;
        *)
            return 1
            ;;
    esac
}

reject_shared() {
    target=$1
    if ! is_chaos_target "$target"; then
        echo "pg-failover: refusing shared/non-chaos target ${target}" >&2
        return 1
    fi
    case "$target" in
        postgres|pgbouncer|minio)
            echo "pg-failover: refusing product-realistic service ${target}" >&2
            return 1
            ;;
    esac
}

replay_lsn() {
    psql_in "$STANDBY" -c "select coalesce(pg_last_wal_replay_lsn()::text, '')"
}

primary_lsn() {
    psql_in "$PRIMARY" -c "select pg_current_wal_lsn()::text"
}

in_recovery() {
    service=$1
    psql_in "$service" -c "select pg_is_in_recovery()"
}

writable_count() {
    writable=0
    for service in "$PRIMARY" "$STANDBY"; do
        id=$(container "$service" || true)
        [ -n "$id" ] || continue
        running=$(docker inspect -f '{{.State.Running}}' "$id" 2>/dev/null || echo false)
        [ "$running" = true ] || continue
        recovery=$(in_recovery "$service" 2>/dev/null || echo t)
        if [ "$recovery" = "f" ]; then
            writable=$((writable + 1))
        fi
    done
    echo "$writable"
}

fence_primary() {
    reject_shared "$PRIMARY"
    mkdir -p "$STATE_DIR"
    if [ -f "$STATE_DIR/fenced" ]; then
        echo "pg-failover: primary already fenced"
        return 0
    fi
    compose stop "$PRIMARY"
    echo fenced > "$STATE_DIR/fenced"
    echo "pg-failover: fenced ${PRIMARY}"
}

promote_standby() {
    reject_shared "$STANDBY"
    if [ ! -f "$STATE_DIR/fenced" ]; then
        echo "pg-failover: refusing unfenced promotion" >&2
        return 1
    fi
    replay=$(replay_lsn)
    if [ -z "$replay" ]; then
        echo "pg-failover: standby replay position missing" >&2
        return 1
    fi
    echo "$replay" > "$STATE_DIR/replay-lsn"
    docker exec -u postgres "$(container "$STANDBY")" \
        pg_ctl promote -D /var/lib/postgresql/data
    i=0
    while [ "$i" -lt 40 ]; do
        recovery=$(in_recovery "$STANDBY" 2>/dev/null || echo t)
        if [ "$recovery" = "f" ]; then
            echo promoted > "$STATE_DIR/promoted"
            echo "pg-failover: promoted ${STANDBY} replay=${replay}"
            return 0
        fi
        i=$((i + 1))
        sleep 0.25
    done
    echo "pg-failover: promote did not yield a writable primary" >&2
    return 1
}

repoint_pgbouncer() {
    reject_shared "$PGBOUNCER"
    if [ ! -f "$STATE_DIR/promoted" ]; then
        echo "pg-failover: refusing to route before promotion" >&2
        return 1
    fi
    compose stop "$PGBOUNCER"
    DATABASE_URL=postgres://vaulter:secret@${STANDBY}:5432/vaulter_chaos \
        compose up -d --no-deps "$PGBOUNCER"
    i=0
    while [ "$i" -lt 40 ]; do
        if docker exec "$(container "$PGBOUNCER")" \
            pg_isready -h 127.0.0.1 -p 6432 -U vaulter -d vaulter_chaos >/dev/null 2>&1; then
            echo repointed > "$STATE_DIR/repointed"
            echo "pg-failover: pgbouncer -> ${STANDBY}"
            return 0
        fi
        i=$((i + 1))
        sleep 0.25
    done
    echo "pg-failover: pgbouncer did not reconnect" >&2
    return 1
}

status() {
    echo "primary_recovery=$(in_recovery "$PRIMARY" 2>/dev/null || echo down)"
    echo "standby_recovery=$(in_recovery "$STANDBY" 2>/dev/null || echo down)"
    echo "writable=$(writable_count)"
    echo "fenced=$([ -f "$STATE_DIR/fenced" ] && echo yes || echo no)"
    echo "promoted=$([ -f "$STATE_DIR/promoted" ] && echo yes || echo no)"
}

rehearse() {
    mkdir -p "$STATE_DIR"
    reject_shared "$PRIMARY"
    reject_shared "$STANDBY"
    reject_shared "$PGBOUNCER"
    if [ "$(writable_count)" -ne 1 ]; then
        echo "pg-failover: expected exactly one writable primary before rehearsal" >&2
        return 1
    fi
    primary_lsn > "$STATE_DIR/primary-lsn"
    replay_lsn > "$STATE_DIR/replay-before"
    fence_primary
    promote_standby
    repoint_pgbouncer
    if [ "$(writable_count)" -ne 1 ]; then
        echo "pg-failover: split-brain after promotion" >&2
        return 1
    fi
    echo "pg-failover: rehearsal one-writable-primary pgbouncer-reconnected"
}

clear_state() {
    if [ -d "$STATE_DIR" ]; then
        find "$STATE_DIR" -mindepth 1 -maxdepth 1 -exec rm -f {} +
    fi
}

revert() {
    clear_state
    mkdir -p "$STATE_DIR"
    compose stop "$PGBOUNCER" "$STANDBY" "$PRIMARY" || true
    compose rm -f -v "$PGBOUNCER" "$STANDBY" "$PRIMARY" || true
    docker volume rm -f "${PROJECT}_pg-chaos-primary" "${PROJECT}_pg-chaos-standby" >/dev/null 2>&1 || true
    compose up -d --wait --wait-timeout 180 "$PRIMARY" "$STANDBY" "$PGBOUNCER"
    echo "pg-failover: reverted isolated HA lab (no automatic failback)"
}

self_test() {
    reject_shared "$PRIMARY"
    if [ "$(writable_count)" -eq 1 ]; then
        echo "pg-failover self-test: one writable primary"
        return 0
    fi
    echo "pg-failover self-test: expected one writable primary" >&2
    return 1
}

COMMAND=${1:-}
case "$COMMAND" in
    status) status ;;
    fence) fence_primary ;;
    promote) promote_standby ;;
    repoint) repoint_pgbouncer ;;
    rehearse) rehearse ;;
    revert) revert ;;
    self-test) self_test ;;
    reject-shared)
        reject_shared "${2:-s3.amazonaws.com}"
        ;;
    *)
        echo "usage: $0 status|rehearse|promote|fence|repoint|revert|self-test" >&2
        exit 1
        ;;
esac
