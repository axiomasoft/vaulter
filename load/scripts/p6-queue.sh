#!/bin/sh
# P6.1 — tier-S queue lane: dry-run | run | verify | check-stand
# The controlled sink, product fixtures and authoritative run belong to P6.2/P6.3.
# Usage:
#   TIER=S ./load/scripts/p6-queue.sh dry-run
#   TIER=S ./load/scripts/p6-queue.sh check-stand
#   TIER=S ./load/scripts/p6-queue.sh verify --run-dir load/gates/fixtures/p6/complete
#   P6_PUBLISH=1 TIER=S ./load/scripts/p6-queue.sh run --run-dir DIR
set -eu

SCRIPT_DIR=$(CDPATH= cd "$(dirname "$0")" && pwd)
LOAD_ROOT=$(CDPATH= cd "$SCRIPT_DIR/.." && pwd)
REPO_ROOT=$(CDPATH= cd "$LOAD_ROOT/.." && pwd)
TIER=${TIER:-S}
ENV_FILE=$LOAD_ROOT/env/${TIER}.env
GATE=$REPO_ROOT/load/gates/p6_queue.py
COMPOSE_FILE=$LOAD_ROOT/docker-compose.load.yml
PROVIDER=$LOAD_ROOT/app/LoadServiceProvider.php
SNAPSHOT=$SCRIPT_DIR/p6-queue-snapshot.sh

usage() {
    echo "usage: TIER=S $0 dry-run|run|verify|check-stand [--run-dir DIR]" >&2
    exit 1
}

COMMAND=
RUN_DIR=

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
        ;;
    verify)
        [ -n "$RUN_DIR" ] || usage
        python3 "$GATE" verify --run-dir "$RUN_DIR"
        ;;
    check-stand)
        python3 "$GATE" worker-route --compose "$COMPOSE_FILE" --provider "$PROVIDER"
        "$SNAPSHOT" syntax
        rendered=$(mktemp)
        trap 'rm -f "$rendered"' EXIT INT TERM
        docker compose -f "$COMPOSE_FILE" --env-file "$ENV_FILE" config > "$rendered"
        grep -q 'queue:work' "$rendered"
        grep -q 'load-health,default' "$rendered"
        grep -q 'tries=1' "$rendered"
        grep -q 'LOAD_QUEUE_LEDGER' "$rendered"
        rm -f "$rendered"
        trap - EXIT INT TERM
        echo "p6-queue check-stand: ok tier=${TIER}"
        ;;
    run)
        if [ -z "$RUN_DIR" ] || [ "${P6_PUBLISH:-0}" != 1 ]; then
            echo "p6-queue run: authoritative tier-S family is P6.3; set P6_PUBLISH=1 and --run-dir to publish already verified evidence. This command does not start workers or write the database." >&2
            exit 1
        fi
        staging=$(mktemp -d "${TMPDIR:-/tmp}/p6-queue.XXXXXX")
        trap 'rm -rf "$staging"' EXIT INT TERM
        cp -a "$RUN_DIR"/. "$staging"/
        if ! python3 "$GATE" verify --run-dir "$staging"; then
            echo "p6-queue run: verify failed; destination unchanged" >&2
            exit 1
        fi
        dest=${P6_ARTIFACTS:-$REPO_ROOT/plans/VLT-LOAD/artifacts/P6/lane}
        rm -rf "$dest"
        mkdir -p "$(dirname "$dest")"
        mv "$staging" "$dest"
        trap - EXIT INT TERM
        python3 "$GATE" verify --run-dir "$dest"
        echo "p6-queue run: published ${dest}"
        ;;
esac
