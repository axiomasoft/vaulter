#!/bin/sh
# P7.2 — injectors, compose overlay, S0 contract, promotion rehearsal.
# Usage:
#   ./load/scripts/p7-injectors.sh static-validate
#   ./load/scripts/p7-injectors.sh compose-config
#   ./load/scripts/p7-injectors.sh s0
#   ./load/scripts/p7-injectors.sh promotion-rehearsal
set -eu

SCRIPT_DIR=$(CDPATH= cd "$(dirname "$0")" && pwd)
LOAD_ROOT=$(CDPATH= cd "$SCRIPT_DIR/.." && pwd)
REPO_ROOT=$(CDPATH= cd "$LOAD_ROOT/.." && pwd)
GATE=$REPO_ROOT/load/gates/p7_injectors.py
FAILOVER=$LOAD_ROOT/chaos/pg-failover.sh
COMPOSE_BASE=$LOAD_ROOT/docker-compose.load.yml
COMPOSE_CHAOS=$LOAD_ROOT/docker-compose.chaos.yml
ENV_FILE=$LOAD_ROOT/env/${TIER:-S}.env
PROJECT=${COMPOSE_PROJECT:-vaulter-load-p72}

usage() {
    echo "usage: $0 static-validate|compose-config|s0|promotion-rehearsal|guards" >&2
    exit 1
}

COMMAND=${1:-}
[ -n "$COMMAND" ] || usage

compose_config() {
    docker compose -f "$COMPOSE_BASE" -f "$COMPOSE_CHAOS" --env-file "$ENV_FILE" \
        --profile chaos-ha config > /tmp/p72-compose.yml
    python3 "$GATE" inspect-compose --file /tmp/p72-compose.yml
    echo "p7-injectors compose-config: ok"
}

guards() {
    python3 "$GATE" guard shared --target s3.amazonaws.com >/tmp/p72-guard.out 2>&1 && {
        echo "p7-injectors: shared endpoint was accepted" >&2
        exit 1
    }
    python3 "$GATE" guard unfenced >/tmp/p72-guard.out 2>&1 && {
        echo "p7-injectors: unfenced promotion was accepted" >&2
        exit 1
    }
    python3 "$GATE" guard tmpfs --target /tmp/escape --root /tmp >/tmp/p72-guard.out 2>&1 && {
        echo "p7-injectors: tmpfs escape was accepted" >&2
        exit 1
    }
    echo "p7-injectors guards: reject shared/unfenced/tmpfs-escape"
}

promotion() {
    STATE=${TMPDIR:-/tmp}/p7-failover-$PROJECT
    mkdir -p "$STATE"
    compose_ha() {
        docker compose -p "$PROJECT" -f "$COMPOSE_BASE" -f "$COMPOSE_CHAOS" \
            --env-file "$ENV_FILE" --profile chaos-ha "$@"
    }
    cleanup_ha() {
        P7_FAILOVER_STATE=$STATE COMPOSE_PROJECT=$PROJECT ENV_FILE=$ENV_FILE \
            "$FAILOVER" revert || true
        compose_ha down --remove-orphans || true
        docker volume rm -f "${PROJECT}_pg-chaos-primary" "${PROJECT}_pg-chaos-standby" >/dev/null 2>&1 || true
    }
    trap cleanup_ha EXIT INT TERM
    P7_FAILOVER_STATE=$STATE COMPOSE_PROJECT=$PROJECT ENV_FILE=$ENV_FILE \
        compose_ha up -d --wait --wait-timeout 180 \
            postgres-primary postgres-standby pgbouncer-ha
    P7_RUN_ID=${P7_RUN_ID:-p7.2-promotion-$$} \
        P7_FAILOVER_STATE=$STATE COMPOSE_PROJECT=$PROJECT ENV_FILE=$ENV_FILE \
        "$FAILOVER" rehearse
    trap - EXIT INT TERM
    cleanup_ha
    echo "p7-injectors promotion-rehearsal: one writable primary, pgbouncer reconnected, reverted"
}

case "$COMMAND" in
    static-validate)
        python3 "$GATE" static-validate
        ;;
    compose-config)
        compose_config
        ;;
    guards)
        guards
        ;;
    s0)
        python3 "$GATE" s0 --run-id p7.2-s0
        ;;
    promotion-rehearsal)
        promotion
        ;;
    *)
        usage
        ;;
esac
