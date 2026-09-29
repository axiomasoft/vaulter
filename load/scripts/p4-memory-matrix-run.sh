#!/usr/bin/env bash
# P4.3 — host-side matrix runner (live path evidence; contract only with P4_MATRIX_CONTRACT=1).
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
LOAD_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
REPO_ROOT="$(cd "$LOAD_ROOT/.." && pwd)"
MATRIX_PY="$REPO_ROOT/load/gates/p4_memory_matrix.py"
P8_ART="${P8_ARTIFACTS:-$REPO_ROOT/plans/archive/VLT-LOAD/artifacts/P8.7}"
TIER="${TIER:-S}"
ENV_FILE="$LOAD_ROOT/env/${TIER}.env"
COMPOSE=(
    docker compose
    -f "$LOAD_ROOT/docker-compose.load.yml"
    -f "$LOAD_ROOT/docker-compose.memory.yml"
    -f "$LOAD_ROOT/docker-compose.p87-host.yml"
    --env-file "$ENV_FILE"
)
SAMPLER="$REPO_ROOT/load/scripts/p4-memory-sampler.py"
VALIDATOR="$REPO_ROOT/load/gates/p4_memory.py"
DATASET_ID="${DATASET_ID:-vlt-load-p4-matrix}"
ENTRY="/app/load/scripts/p4-memory-matrix-container-entry.sh"

usage() {
    echo "usage: TIER=S $0 --run-id <uuid> --out <artifacts-dir> [--contract]" >&2
    echo "  smoke: P4_MATRIX_SMOKE_CELL=tus_final_patch/5m P4_MATRIX_MAX_ATTEMPTS=1" >&2
    exit 1
}

RUN_ID=""
OUT=""
MODE="live"
FINGERPRINT=""
if [ "${P4_MATRIX_CONTRACT:-}" = "1" ]; then
    MODE="contract"
fi

while [ $# -gt 0 ]; do
    case "$1" in
        --run-id)
            RUN_ID="$2"
            shift 2
            ;;
        --out)
            OUT="$2"
            shift 2
            ;;
        --fingerprint)
            FINGERPRINT="$2"
            shift 2
            ;;
        --contract)
            MODE="contract"
            shift
            ;;
        --live)
            MODE="live"
            export P4_MATRIX_LIVE=1
            shift
            ;;
        *)
            usage
            ;;
    esac
done

[ -n "$RUN_ID" ] && [ -n "$OUT" ] || usage
[ -f "$ENV_FILE" ] || { echo "missing env ${ENV_FILE}" >&2; exit 1; }

python3 "$REPO_ROOT/load/gates/p8_live_gate.py" verify --artifacts "$P8_ART" >/dev/null

if [ -z "$FINGERPRINT" ]; then
    FINGERPRINT="$(python3 - "$ENV_FILE" "$DATASET_ID" <<'PY'
import hashlib, pathlib, sys
env, dataset = sys.argv[1:3]
payload = pathlib.Path(env).read_bytes() + dataset.encode()
print(hashlib.sha256(payload).hexdigest()[:16])
PY
)"
fi

if [ "$MODE" = "contract" ]; then
    rm -rf "$OUT"
    mkdir -p "$OUT"
    python3 "$MATRIX_PY" contract-write \
        --out "$OUT" \
        --run-id "$RUN_ID" \
        --tier "$TIER" \
        --fingerprint "$FINGERPRINT" \
        --dataset-id "$DATASET_ID"
    exit 0
fi

if [ -z "${P4_MATRIX_MAX_ATTEMPTS:-}" ] && [ -z "${P4_MATRIX_SMOKE_CELL:-}" ]; then
    required_bytes=$((5 * 1024 * 1024 * 1024 * 2))
    free_bytes="$(df -B1 --output=avail "$LOAD_ROOT" | tail -1 | tr -d ' ')"
    if [ "$free_bytes" -lt "$required_bytes" ]; then
        echo "p4-matrix live: need >=2×5GiB free disk on load volume (have ${free_bytes} bytes)" >&2
        exit 2
    fi
fi

python3 "$SAMPLER" preflight >/dev/null
"${COMPOSE[@]}" config >/dev/null
if [ "${P4_MATRIX_REBUILD:-0}" = "1" ]; then
    echo "p4-matrix live: rebuilding app image (P4_MATRIX_REBUILD=1)" >&2
    "${COMPOSE[@]}" build app >/dev/null
else
    app_image="$("${COMPOSE[@]}" config --format json | python3 -c '
import json, sys
config = json.load(sys.stdin)
print(config["services"]["app"].get("image") or "{}-app".format(config["name"]))
')"
    if ! app_image_id="$(docker image inspect --format '{{.Id}}' "$app_image" 2>/dev/null)"; then
        echo "p4-matrix live: app image ${app_image} is missing; rebuild once with P4_MATRIX_REBUILD=1" >&2
        exit 2
    fi
    echo "p4-matrix live: reusing app image ${app_image} (${app_image_id}); set P4_MATRIX_REBUILD=1 after Dockerfile/build-context changes" >&2
fi
"${COMPOSE[@]}" up -d postgres pgbouncer minio redis minio-init >/dev/null
waits=0
until "${COMPOSE[@]}" exec -T minio mc ready local >/dev/null 2>&1; do
    waits=$((waits + 1))
    if [ "$waits" -gt 60 ]; then
        echo "p4-matrix live: minio not ready" >&2
        exit 2
    fi
    sleep 1
done
"${COMPOSE[@]}" exec -T minio mc alias set load http://127.0.0.1:9000 vaulter vaulter-load-secret >/dev/null
"${COMPOSE[@]}" exec -T minio mc mb --ignore-existing load/vaulter-load >/dev/null

rm -rf "$OUT"
mkdir -p "$OUT/samples" "$OUT/k6"
FIXTURE_VOL="${P4_MATRIX_FIXTURE_VOL:-}"
if [ -z "$FIXTURE_VOL" ]; then
    FIXTURE_VOL="$(mktemp -d "${TMPDIR:-/tmp}/p4-matrix-fixtures.XXXXXX")"
fi
mkdir -p "$FIXTURE_VOL"
LIST_ARGS=(--live)
if [ -n "${P4_MATRIX_SMOKE_CELL:-}" ]; then
    LIST_ARGS+=(--cell-id "$P4_MATRIX_SMOKE_CELL")
    if [ -n "${P4_MATRIX_MAX_ATTEMPTS:-}" ]; then
        LIST_ARGS+=(--max-attempts "$P4_MATRIX_MAX_ATTEMPTS")
    fi
fi
attempts_json="$(mktemp)"
attempts_ndjson="$(mktemp)"
python3 "$MATRIX_PY" list-attempts "${LIST_ARGS[@]}" >"$attempts_json"
attempt_count="$(python3 -c 'import json,sys; print(len(json.load(open(sys.argv[1]))))' "$attempts_json")"
if [ -z "${P4_MATRIX_SMOKE_CELL:-}" ] && [ "$attempt_count" -ne 45 ]; then
    echo "p4-matrix live: expected 45 attempt specs, got ${attempt_count} (clear leaked P4_MATRIX_* env)" >&2
    exit 2
fi
python3 -c 'import json,sys; [print(json.dumps(x)) for x in json.load(open(sys.argv[1]))]' "$attempts_json" >"$attempts_ndjson"
ndjson_lines="$(wc -l < "$attempts_ndjson" | tr -d ' ')"
if [ "$ndjson_lines" -ne "$attempt_count" ]; then
    echo "p4-matrix live: ndjson lines ${ndjson_lines} != attempt specs ${attempt_count}" >&2
    exit 2
fi
echo "p4-matrix live: planning ${attempt_count} attempts (args: ${LIST_ARGS[*]})" >&2
run_prefix="p4-matrix-${RUN_ID:0:8}"

# Bootstrap (migrate/FPM) + fixture prepare; floor 4 MiB/s for large blobs (see capture stderr stages).
k6_ready_wait_limit_secs() {
    python3 - "$1" <<'PY'
import math, sys
size_bytes = int(sys.argv[1])
overhead_s = 300
# Observed tier-S lab: cold 500m prepare can exceed 35m; use 2s/256KiB + size floors.
prepare_s = math.ceil(size_bytes / (256 * 1024)) * 2
if size_bytes >= 5 * 1024 ** 3:
    floor_s = 6 * 3600
elif size_bytes >= 500 * 1024 ** 2:
    floor_s = 5400
else:
    floor_s = 600
print(max(overhead_s + prepare_s, floor_s))
PY
}

run_fpm_attempt() {
    local staging="$1"
    local cell_id="$2"
    local fixture_id="$3"
    local attempt_id="$4"
    local repetition="$5"
    local size_bytes="$6"
    local path_id="${cell_id%%/*}"
    local wait_limit
    wait_limit="$(k6_ready_wait_limit_secs "$size_bytes")"

    export P4_MATRIX_REPETITION="$repetition"
    echo "p4-matrix live: cell=${cell_id} wait .k6-ready budget=${wait_limit}s size_bytes=${size_bytes}" >&2
    local cid
    cid="$("${COMPOSE[@]}" run -d --no-deps \
        -e "P4_MATRIX_REPETITION=${repetition}" \
        -v "${staging}:/out" \
        -v "${FIXTURE_VOL}:/app/storage/app/p4-memory-fixtures" \
        app sh "${ENTRY}" \
        /out "$cell_id" "$fixture_id" "$run_prefix" "$FINGERPRINT" "$TIER" "$RUN_ID" "$attempt_id")"

    local elapsed=0
    while [ ! -f "${staging}/.k6-ready" ]; do
        if ! docker inspect -f '{{.State.Running}}' "$cid" 2>/dev/null | grep -qx true; then
            local exit_code
            exit_code="$(docker inspect -f '{{.State.ExitCode}}' "$cid" 2>/dev/null || echo unknown)"
            echo "p4-matrix live: FATAL capture container exited before .k6-ready cell=${cell_id} attempt_id=${attempt_id} exit=${exit_code} container=${cid}" >&2
            docker logs "$cid" 2>&1 | tail -80 >&2 || true
            docker rm -f "$cid" >/dev/null 2>&1 || true
            return 1
        fi
        if [ "$elapsed" -ge "$wait_limit" ]; then
            echo "p4-matrix live: TIMEOUT .k6-ready cell=${cell_id} attempt_id=${attempt_id} size_bytes=${size_bytes} budget=${wait_limit}s container=${cid}" >&2
            docker logs "$cid" 2>&1 | tail -80 >&2 || true
            docker rm -f "$cid" >/dev/null 2>&1 || true
            return 1
        fi
        if [ $((elapsed % 60)) -eq 0 ] && [ "$elapsed" -gt 0 ]; then
            echo "p4-matrix live: still waiting .k6-ready cell=${cell_id} elapsed=${elapsed}s budget=${wait_limit}s" >&2
        fi
        sleep 1
        elapsed=$((elapsed + 1))
    done

    local manifest_compact
    manifest_compact="$(python3 -c 'import json; print(json.dumps(json.load(open("'"${staging}/fixture-manifest.json"'"))))')"

    if ! docker run --rm \
        --user 0:0 \
        --network "container:${cid}" \
        -w /results \
        -v "${LOAD_ROOT}/k6:/scripts:ro" \
        -v "${staging}:/results" \
        -e BASE_URL=http://127.0.0.1:8080 \
        -e TIER="${TIER}" \
        -e MEMORY_CELL="$cell_id" \
        -e MEMORY_FIXTURE_MANIFEST="$manifest_compact" \
        -e P4_MATRIX_K6_OUT=k6-summary.json \
        grafana/k6:0.55.0 run /scripts/scenarios/memory-paths.js; then
        docker rm -f "$cid" >/dev/null 2>&1 || true
        return 1
    fi

    if [ ! -f "${staging}/k6-summary.json" ]; then
        echo "p4-matrix live: k6-summary.json missing after k6 run" >&2
        docker rm -f "$cid" >/dev/null 2>&1 || true
        return 1
    fi

    if ! python3 -c 'import json,sys; d=json.load(open(sys.argv[1])); sys.exit(0 if d.get("ok") else 1)' "${staging}/k6-summary.json"; then
        echo "p4-matrix live: k6-summary.json not ok:" >&2
        cat "${staging}/k6-summary.json" >&2
        docker rm -f "$cid" >/dev/null 2>&1 || true
        return 1
    fi

    touch "${staging}/.k6-done"
    exit_code="$(docker wait "$cid")"
    if [ "$exit_code" != "0" ]; then
        echo "p4-matrix live: capture container exit ${exit_code}" >&2
        docker logs "$cid" 2>&1 | tail -80 >&2 || true
        ls -la "${staging}" >&2 || true
        docker rm -f "$cid" >/dev/null 2>&1 || true
        return 1
    fi
    docker rm -f "$cid" >/dev/null 2>&1 || true

    if [ ! -f "${staging}/run-manifest.json" ]; then
        echo "p4-matrix live: run-manifest.json missing after capture" >&2
        ls -la "${staging}" >&2 || true
        return 1
    fi
}

run_cas_attempt() {
    local staging="$1"
    local cell_id="$2"
    local fixture_id="$3"
    local attempt_id="$4"
    local repetition="$5"

    export P4_MATRIX_REPETITION="$repetition"
    "${COMPOSE[@]}" run --rm --no-deps \
        -e "P4_MATRIX_REPETITION=${repetition}" \
        -v "${staging}:/out" \
        -v "${FIXTURE_VOL}:/app/storage/app/p4-memory-fixtures" \
        app sh "${ENTRY}" \
        /out "$cell_id" "$fixture_id" "$run_prefix" "$FINGERPRINT" "$TIER" "$RUN_ID" "$attempt_id"
}

attempts_meta="[]"
idx=0
mapfile -t _p4_attempt_specs < "$attempts_ndjson"
if [ -z "${P4_MATRIX_SMOKE_CELL:-}" ] && [ "${#_p4_attempt_specs[@]}" -ne 45 ]; then
    echo "p4-matrix live: expected 45 attempt records, got ${#_p4_attempt_specs[@]}" >&2
    exit 2
fi
for spec_line in "${_p4_attempt_specs[@]}"; do
    [ -n "$spec_line" ] || continue
    idx=$((idx + 1))
    attempt_id="$(python3 -c 'import json,sys; print(json.loads(sys.argv[1])["attempt_id"])' "$spec_line")"
    cell_id="$(python3 -c 'import json,sys; print(json.loads(sys.argv[1])["cell_id"])' "$spec_line")"
    repetition="$(python3 -c 'import json,sys; print(json.loads(sys.argv[1])["repetition"])' "$spec_line")"
    path_id="$(python3 -c 'import json,sys; print(json.loads(sys.argv[1])["path_id"])' "$spec_line")"
    size_id="$(python3 -c 'import json,sys; print(json.loads(sys.argv[1])["size_id"])' "$spec_line")"
    size_bytes="$(python3 -c 'import json,sys; print(json.loads(sys.argv[1])["size_bytes"])' "$spec_line")"
    fixture_id="${run_prefix}-${size_id}"
    dest="$OUT/samples/${attempt_id}"
    staging="$(mktemp -d)"
    chmod 777 "$staging"
    echo "p4-matrix live: attempt ${idx} cell=${cell_id} rep=${repetition}" >&2

    if [ "$path_id" = "cas_stage_probe" ]; then
        if ! run_cas_attempt "$staging" "$cell_id" "$fixture_id" "$attempt_id" "$repetition"; then
            echo "p4-matrix live: cas attempt failed cell=${cell_id}" >&2
            exit 1
        fi
    else
        if ! run_fpm_attempt "$staging" "$cell_id" "$fixture_id" "$attempt_id" "$repetition" "$size_bytes"; then
            echo "p4-matrix live: FATAL fpm attempt cell=${cell_id} rep=${repetition} attempt_id=${attempt_id}" >&2
            exit 1
        fi
    fi

    mkdir -p "$dest"
    for artifact in run-manifest.json samples.jsonl case-manifest.json; do
        if [ ! -f "${staging}/${artifact}" ]; then
            echo "p4-matrix live: missing ${artifact} in staging for ${attempt_id}" >&2
            exit 1
        fi
    done
    python3 - "${staging}/run-manifest.json" "$attempt_id" "$repetition" <<'PY'
import json, pathlib, sys
manifest_path, expected_attempt, expected_repetition = sys.argv[1:4]
matrix = json.loads(pathlib.Path(manifest_path).read_text(encoding="utf-8"))["matrix"]
if matrix.get("attempt_id") != expected_attempt:
    raise SystemExit(f"attempt_id transport mismatch: {matrix.get('attempt_id')} != {expected_attempt}")
if int(matrix.get("repetition", 0)) != int(expected_repetition):
    raise SystemExit(f"repetition transport mismatch: {matrix.get('repetition')} != {expected_repetition}")
PY
    cp -a "${staging}/run-manifest.json" "${staging}/samples.jsonl" "${staging}/case-manifest.json" "$dest/"
    if [ -f "${staging}/k6-summary.json" ]; then
        cp "${staging}/k6-summary.json" "$OUT/k6/${attempt_id}.json"
    fi
    rm -rf "$staging"

    python3 "$VALIDATOR" verify --run-dir "$dest"

    attempts_meta="$(python3 - "$attempts_meta" "$spec_line" "$attempt_id" <<'PY'
import json, sys
meta = json.loads(sys.argv[1])
spec = json.loads(sys.argv[2])
attempt_id = sys.argv[3]
meta.append({
    "attempt_id": attempt_id,
    "cell_id": spec["cell_id"],
    "path_id": spec["path_id"],
    "size_id": spec["size_id"],
    "size_bytes": spec["size_bytes"],
    "repetition": spec["repetition"],
    "status": "valid",
    "product_verdict": "green",
    "evidence_dir": f"samples/{attempt_id}",
})
print(json.dumps(meta))
PY
)"
done

rm -f "$attempts_json" "$attempts_ndjson"

if [ "$idx" -eq 45 ]; then
    python3 - "$attempts_meta" "$OUT" "$RUN_ID" "$TIER" "$FINGERPRINT" "$MATRIX_PY" <<'PY'
import importlib.util, json, pathlib, sys
meta = json.loads(sys.argv[1])
out, run_id, tier, fingerprint, matrix_py = sys.argv[2:7]
spec = importlib.util.spec_from_file_location("p4_memory_matrix", matrix_py)
mod = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)
mod.finalize_matrix_artifacts(
    pathlib.Path(out),
    run_id=run_id,
    tier=tier,
    fingerprint=fingerprint,
    dataset_id="vlt-load-p4-matrix",
    mode="live",
    attempts=meta,
)
mod.verify_matrix(pathlib.Path(out))
PY
elif [ -n "${P4_MATRIX_SMOKE_CELL:-}" ] || [ -n "${P4_MATRIX_MAX_ATTEMPTS:-}" ]; then
    echo "p4-matrix live: smoke run complete attempts=${idx} (no full matrix finalize)" >&2
else
    echo "p4-matrix live: FATAL incomplete matrix ${idx}/45 attempts run_id=${RUN_ID}" >&2
    exit 1
fi

echo "p4-matrix live: ok run_id=${RUN_ID} artifacts=${OUT} attempts=${idx}"
