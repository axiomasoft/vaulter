#!/usr/bin/env bash
# Isolated memory lane: dry-run | run | verify | matrix
# Usage:
#   TIER=S0 ./load/scripts/p4-memory.sh dry-run
#   TIER=S0 ./load/scripts/p4-memory.sh run --case lane_selftest
#   TIER=S0 ./load/scripts/p4-memory.sh verify --run-dir load/results/p4/<run_id>
#   TIER=S ./load/scripts/p4-memory.sh run --matrix
#   TIER=S ./load/scripts/p4-memory.sh verify --run-id <uuid>
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
LOAD_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
REPO_ROOT="$(cd "$LOAD_ROOT/.." && pwd)"
TIER="${TIER:-S0}"
ENV_FILE="$LOAD_ROOT/env/${TIER}.env"
COMPOSE=(
    docker compose
    -f "$LOAD_ROOT/docker-compose.load.yml"
    -f "$LOAD_ROOT/docker-compose.memory.yml"
    --env-file "$ENV_FILE"
)
SAMPLER="$REPO_ROOT/load/scripts/p4-memory-sampler.py"
VALIDATOR="$REPO_ROOT/load/gates/p4_memory.py"
MATRIX_PY="$REPO_ROOT/load/gates/p4_memory_matrix.py"
MATRIX_RUN="$REPO_ROOT/load/scripts/p4-memory-matrix-run.sh"
CAPTURE="$REPO_ROOT/load/scripts/p4-memory-capture-in-container.sh"
DATASET_ID="${DATASET_ID:-vlt-load-p4-lane}"
MATRIX_DATASET_ID="${P4_MATRIX_DATASET_ID:-vlt-load-p4-matrix}"
RESULTS_ROOT="$LOAD_ROOT/results/p4"
P4_ART="${P4_ARTIFACTS:-$REPO_ROOT/plans/archive/VLT-LOAD/artifacts/P4}"

usage() {
    echo "usage: TIER=S0 $0 dry-run|run|verify [options]" >&2
    echo "       TIER=S  $0 run --matrix  (P4_MATRIX_CONTRACT=1 for contract-only)" >&2
    echo "       TIER=S  $0 verify --run-id <uuid>" >&2
    exit 1
}

env_fingerprint() {
    local dataset="${1:-$DATASET_ID}"
    python3 - "$ENV_FILE" "$dataset" <<'PY'
import hashlib, pathlib, sys
env, dataset = sys.argv[1:3]
payload = pathlib.Path(env).read_bytes() + dataset.encode()
print(hashlib.sha256(payload).hexdigest()[:16])
PY
}

new_run_id() {
    python3 -c 'import uuid; print(uuid.uuid4())'
}

publish_run_dir() {
    local src="$1"
    local dest="$2"
    local tmp="${dest}.tmp.$$"
    rm -rf "$tmp"
    cp -a "$src" "$tmp"
    python3 "$VALIDATOR" verify --run-dir "$tmp"
    rm -rf "$dest"
    mv "$tmp" "$dest"
}

COMMAND=""
RUN_DIR=""
RUN_ID=""
CASE_ID="lane_selftest"
FIXTURE_ID="synthetic-bounded"
MATRIX=0

while [ $# -gt 0 ]; do
    case "$1" in
        --tier)
            TIER="$2"
            ENV_FILE="$LOAD_ROOT/env/${TIER}.env"
            shift 2
            ;;
        --run-dir)
            RUN_DIR="$2"
            shift 2
            ;;
        --run-id)
            RUN_ID="$2"
            shift 2
            ;;
        --case)
            CASE_ID="$2"
            shift 2
            ;;
        --fixture-id)
            FIXTURE_ID="$2"
            shift 2
            ;;
        --matrix)
            MATRIX=1
            shift
            ;;
        dry-run|run|verify)
            COMMAND="$1"
            shift
            ;;
        *)
            usage
            ;;
    esac
done

[ -n "$COMMAND" ] || usage

if [ ! -f "$ENV_FILE" ]; then
    echo "Unknown TIER=${TIER} (missing ${ENV_FILE})" >&2
    exit 1
fi

chmod +x "$SAMPLER" "$CAPTURE" "$MATRIX_RUN" 2>/dev/null || true

preflight_host() {
    if ! python3 "$SAMPLER" preflight >/dev/null; then
        echo "p4-memory: host preflight failed (need Linux cgroup v2 + smaps_rollup)" >&2
        exit 1
    fi
}

compose_config() {
    "${COMPOSE[@]}" config >/dev/null
}

publish_matrix_artifacts() {
    local src="$1"
    local dest="$2"
    local tmp="${dest}.tmp.$$"
    rm -rf "$tmp"
    cp -a "$src" "$tmp"
    python3 "$MATRIX_PY" verify-matrix --artifacts "$tmp"
    rm -rf "$dest"
    mkdir -p "$dest"
    cp -a "$tmp/." "$dest/"
    rm -rf "$tmp"
}

case "$COMMAND" in
    dry-run)
        compose_config
        preflight_host
        fingerprint="$(env_fingerprint)"
        if [ "$MATRIX" -eq 1 ]; then
            echo "p4-memory dry-run: ok tier=${TIER} matrix=15x3 contract/live via P4_MATRIX_LIVE"
        fi
        echo "p4-memory dry-run: ok tier=${TIER} case=${CASE_ID} fixture=${FIXTURE_ID} env_fingerprint=${fingerprint}"
        echo "p4-memory dry-run: compose overlay validated; stand not started; dataset unchanged"
        ;;

    run)
        if [ "$MATRIX" -eq 1 ]; then
            if [ "$TIER" != "S" ] && [ -z "${P4_MATRIX_SMOKE_CELL:-}" ]; then
                echo "p4-memory matrix requires TIER=S (authoritative tier-S run)" >&2
                exit 1
            fi
            if [ -z "${P4_MATRIX_SMOKE_CELL:-}" ]; then
                unset P4_MATRIX_MAX_ATTEMPTS
                unset P4_MATRIX_SMOKE_CELL
            fi
            export P4_MATRIX_LIVE=1
            compose_config
            preflight_host
            run_id="${RUN_ID:-$(new_run_id)}"
            fingerprint="$(env_fingerprint "$MATRIX_DATASET_ID")"
            staging="$(mktemp -d "${TMPDIR:-/tmp}/p4-matrix-staging.XXXXXX")"
            fixture_vol="$(mktemp -d "${TMPDIR:-/tmp}/p4-matrix-fixtures.XXXXXX")"
            export P4_MATRIX_FIXTURE_VOL="$fixture_vol"
            trap 'rm -rf "$staging" "$fixture_vol"' EXIT
            if [ "${P4_MATRIX_CONTRACT:-}" = "1" ]; then
                "$MATRIX_RUN" --contract --run-id "$run_id" --out "$staging" --fingerprint "$fingerprint"
            else
                "$MATRIX_RUN" --live --run-id "$run_id" --out "$staging" --fingerprint "$fingerprint"
            fi
            if [ -n "${P4_MATRIX_MAX_ATTEMPTS:-}" ] || [ -n "${P4_MATRIX_SMOKE_CELL:-}" ]; then
                echo "p4-memory matrix smoke: ok run_id=${run_id} artifacts=${staging}"
                exit 0
            fi
            dest="$P4_ART"
            publish_matrix_artifacts "$staging" "$dest"
            python3 "$MATRIX_PY" verify-matrix --artifacts "$dest"
            echo "p4-memory matrix: ok run_id=${run_id} artifacts=${dest}"
            exit 0
        fi

        compose_config
        preflight_host
        run_id="$(new_run_id)"
        fingerprint="$(env_fingerprint)"
        staging="$(mktemp -d "${TMPDIR:-/tmp}/p4-memory-staging.XXXXXX")"
        trap 'rm -rf "$staging"' EXIT
        artifact_vol="$staging/run"
        mkdir -p "$artifact_vol"

        "${COMPOSE[@]}" up -d postgres pgbouncer minio redis minio-init >/dev/null
        "${COMPOSE[@]}" run --rm --no-deps \
            -v "$artifact_vol:/out" \
            app sh -c "
                set -eu
                php-fpm -D --allow-to-run-as-root
                nginx -g \"daemon off;\" &
                for i in \$(seq 1 30); do
                    wget -q -O- http://127.0.0.1:8081/fpm-status?json >/dev/null 2>&1 && break
                    sleep 1
                done
                python3 /app/load/scripts/p4-memory-sampler.py preflight >/dev/null
                sh /app/load/scripts/p4-memory-capture-in-container.sh \
                    /out ${CASE_ID} ${FIXTURE_ID} ${fingerprint} ${TIER} ${run_id}
            "

        dest="$RESULTS_ROOT/$run_id"
        publish_run_dir "$artifact_vol" "$dest"
        echo "p4-memory run: ok run_id=${run_id} artifact_dir=${dest}"
        ;;

    verify)
        if [ -n "$RUN_ID" ]; then
            dest="$P4_ART"
            manifest_run="$(python3 - "$dest/run-manifest.json" <<'PY'
import json, pathlib, sys
print(json.loads(pathlib.Path(sys.argv[1]).read_text())["run_id"])
PY
)"
            if [ "$manifest_run" != "$RUN_ID" ]; then
                echo "p4-memory verify: run_id mismatch (manifest=${manifest_run})" >&2
                exit 1
            fi
            digest_line="$(python3 "$MATRIX_PY" verify-matrix --artifacts "$dest")"
            digest="${digest_line##*digest=}"
            python3 "$MATRIX_PY" verify-matrix --artifacts "$dest" --prior-digest "$digest"
            echo "$digest_line"
            exit 0
        fi
        [ -n "$RUN_DIR" ] || usage
        python3 "$VALIDATOR" verify --run-dir "$RUN_DIR"
        ;;
esac
