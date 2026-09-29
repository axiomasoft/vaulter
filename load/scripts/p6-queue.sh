#!/bin/sh
# P6.1 — tier-S queue lane: dry-run | run | verify | check-stand
# The controlled sink, product fixtures and authoritative run belong to P6.2/P6.3.
# Usage:
#   TIER=S ./load/scripts/p6-queue.sh dry-run
#   TIER=S ./load/scripts/p6-queue.sh check-stand
#   TIER=S ./load/scripts/p6-queue.sh verify --run-dir load/gates/fixtures/p6/complete
#   TIER=S ./load/scripts/p6-queue.sh run --matrix
#   TIER=S ./load/scripts/p6-queue.sh verify --run-id <id>
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
    echo "usage: TIER=S $0 dry-run|run|verify|check-stand [--run-dir DIR|--run-id ID|--matrix]" >&2
    exit 1
}

COMMAND=
RUN_DIR=
RUN_ID=
MATRIX=0

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
        --run-id)
            RUN_ID=$2
            shift 2
            ;;
        --matrix)
            MATRIX=1
            shift
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
        if [ -n "$RUN_ID" ]; then
            dest=${P6_ARTIFACTS:-$REPO_ROOT/plans/archive/VLT-LOAD/artifacts/P6}
            manifest_run=$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["run_id"])' "$dest/run-manifest.json")
            if [ "$manifest_run" != "$RUN_ID" ]; then
                echo "p6-queue verify: run_id mismatch (manifest=${manifest_run})" >&2
                exit 1
            fi
            digest_line=$(python3 "$REPO_ROOT/load/gates/p6_live_matrix.py" verify --artifacts "$dest")
            digest=${digest_line##*digest=}
            python3 "$REPO_ROOT/load/gates/p6_live_matrix.py" verify --artifacts "$dest" --prior-digest "$digest"
            echo "$digest_line"
            exit 0
        fi
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
        if [ "$MATRIX" -eq 1 ]; then
            if [ "$TIER" != "S" ]; then
                echo "p6-queue matrix requires TIER=S" >&2
                exit 1
            fi
            run_id=${RUN_ID:-$(python3 -c 'import uuid; print(uuid.uuid4())')}
            staging=$(mktemp -d "${TMPDIR:-/tmp}/p6-matrix.XXXXXX")
            trap 'rm -rf "$staging"' EXIT INT TERM
            set +e
            python3 "$REPO_ROOT/load/gates/p6_live_matrix.py" run --run-id "$run_id" --out "$staging"
            run_code=$?
            set -e
            if [ "$run_code" -ne 0 ]; then
                echo "p6-queue matrix: run failed, staging kept at ${staging}" >&2
                trap - EXIT INT TERM
                exit "$run_code"
            fi
            if ! python3 "$REPO_ROOT/load/gates/p6_live_matrix.py" verify --artifacts "$staging"; then
                echo "p6-queue matrix: verify failed, staging kept at ${staging}" >&2
                trap - EXIT INT TERM
                exit 1
            fi
            dest=${P6_ARTIFACTS:-$REPO_ROOT/plans/archive/VLT-LOAD/artifacts/P6}
            rm -rf "$dest"
            mkdir -p "$(dirname "$dest")"
            mv "$staging" "$dest"
            trap - EXIT INT TERM
            python3 "$REPO_ROOT/load/gates/p6_live_matrix.py" verify --artifacts "$dest"
            echo "p6-queue run: published ${dest} run_id=${run_id}"
            exit 0
        fi
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
        dest=${P6_ARTIFACTS:-$REPO_ROOT/plans/archive/VLT-LOAD/artifacts/P6/lane}
        rm -rf "$dest"
        mkdir -p "$(dirname "$dest")"
        mv "$staging" "$dest"
        trap - EXIT INT TERM
        python3 "$GATE" verify --run-dir "$dest"
        echo "p6-queue run: published ${dest}"
        ;;
esac
