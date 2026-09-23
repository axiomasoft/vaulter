#!/bin/sh
# P7.1 — tier-S chaos lane: dry-run | run | revert | verify
# Injectors, isolated topology and the authoritative run belong to P7.2/P7.3.
# Usage:
#   TIER=S ./load/scripts/p7-chaos.sh dry-run
#   TIER=S ./load/scripts/p7-chaos.sh verify --run-dir load/gates/fixtures/p7/complete
#   TIER=S ./load/scripts/p7-chaos.sh revert --run-dir DIR
#   TIER=S ./load/scripts/p7-chaos.sh interrupt-fixture --out DIR
#   P7_PUBLISH=1 TIER=S ./load/scripts/p7-chaos.sh run --run-dir DIR
set -eu

SCRIPT_DIR=$(CDPATH= cd "$(dirname "$0")" && pwd)
LOAD_ROOT=$(CDPATH= cd "$SCRIPT_DIR/.." && pwd)
REPO_ROOT=$(CDPATH= cd "$LOAD_ROOT/.." && pwd)
TIER=${TIER:-S}
ENV_FILE=$LOAD_ROOT/env/${TIER}.env
GATE=$REPO_ROOT/load/gates/p7_chaos.py
SNAPSHOT=$SCRIPT_DIR/p7-chaos-snapshot.sh

usage() {
    echo "usage: TIER=S $0 dry-run|run|revert|verify|interrupt-fixture [--run-dir DIR|--run-id ID|--out DIR]" >&2
    exit 1
}

emergency_revert() {
    target=$1
    [ -n "$target" ] || return 0
    [ -d "$target" ] || return 0
    python3 "$GATE" revert --run-dir "$target" >/dev/null 2>&1 || true
    if [ -f "$target/armed" ]; then
        rm -f "$target/armed"
    fi
    printf '%s\n' '{"schema":"p7-emergency-revert/v1","scope":"run","wildcard":false}' > "$target/reverted"
    echo "p7-chaos interrupt-fixture: revert-executed" >> "$target/reverted"
}

COMMAND=
RUN_DIR=
RUN_ID=
OUT_DIR=

while [ $# -gt 0 ]; do
    case "$1" in
        dry-run|run|revert|verify|interrupt-fixture)
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
        --out)
            OUT_DIR=$2
            shift 2
            ;;
        *)
            usage
            ;;
    esac
done

[ -n "$COMMAND" ] || usage

if [ "$COMMAND" != "interrupt-fixture" ]; then
    [ -f "$ENV_FILE" ] || {
        echo "Unknown TIER=${TIER} (missing ${ENV_FILE})" >&2
        exit 1
    }
fi

case "$COMMAND" in
    dry-run)
        python3 "$GATE" axes --tier "$TIER" --env "$ENV_FILE"
        ;;
    verify)
        if [ -n "$RUN_ID" ]; then
            dest=${P7_ARTIFACTS:-$REPO_ROOT/plans/VLT-LOAD/artifacts/P7}
            manifest_run=$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["run_id"])' "$dest/run-manifest.json")
            if [ "$manifest_run" != "$RUN_ID" ]; then
                echo "p7-chaos verify: run_id mismatch (manifest=${manifest_run})" >&2
                exit 1
            fi
            python3 "$GATE" verify --run-dir "$dest"
            exit 0
        fi
        [ -n "$RUN_DIR" ] || usage
        python3 "$GATE" verify --run-dir "$RUN_DIR"
        ;;
    revert)
        [ -n "$RUN_DIR" ] || usage
        trap 'emergency_revert "$RUN_DIR"' INT TERM
        python3 "$GATE" revert --run-dir "$RUN_DIR"
        "$SNAPSHOT" syntax >/dev/null
        trap - INT TERM
        ;;
    interrupt-fixture)
        [ -n "$OUT_DIR" ] || usage
        mkdir -p "$OUT_DIR"
        trap 'emergency_revert "$OUT_DIR"; exit 0' INT TERM
        : > "$OUT_DIR/armed"
        echo "p7-chaos interrupt-fixture: armed"
        sleep 30
        emergency_revert "$OUT_DIR"
        ;;
    run)
        if [ -z "$RUN_DIR" ] || [ "${P7_PUBLISH:-0}" != 1 ]; then
            echo "p7-chaos run: authoritative tier-S family is P7.3; set P7_PUBLISH=1 and --run-dir to publish already verified evidence. This command does not start Docker or write the database or object store." >&2
            exit 1
        fi
        staging=$(mktemp -d "${TMPDIR:-/tmp}/p7-chaos.XXXXXX")
        trap 'emergency_revert "$staging"; rm -rf "$staging"' EXIT INT TERM
        cp -a "$RUN_DIR"/. "$staging"/
        if ! python3 "$GATE" verify --run-dir "$staging"; then
            echo "p7-chaos run: verify failed; destination unchanged" >&2
            exit 1
        fi
        dest=${P7_ARTIFACTS:-$REPO_ROOT/plans/VLT-LOAD/artifacts/P7/lane}
        rm -rf "$dest"
        mkdir -p "$(dirname "$dest")"
        mv "$staging" "$dest"
        trap - EXIT INT TERM
        python3 "$GATE" verify --run-dir "$dest"
        echo "p7-chaos run: published ${dest}"
        ;;
esac
