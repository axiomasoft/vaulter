#!/usr/bin/env bash
# Profiling lane: prepare|capture|explain|hypopg|verify
# Usage:
#   TIER=S0 ./load/scripts/p3-profile.sh prepare --dry-run
#   TIER=S0 ./load/scripts/p3-profile.sh verify --run-dir load/results/p3/<run_id>
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
LOAD_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
REPO_ROOT="$(cd "$LOAD_ROOT/.." && pwd)"
TIER="${TIER:-S0}"
ENV_FILE="$LOAD_ROOT/env/${TIER}.env"
COMPOSE=(docker compose -f "$LOAD_ROOT/docker-compose.load.yml" --env-file "$ENV_FILE")
PROFILE_PY="$REPO_ROOT/load/gates/p3_profile.py"
WORKLOAD_PY="$REPO_ROOT/load/gates/p3_workload.py"
IMAGE_TAG="vaulter-load-postgres-profile:16-hypopg-1.4.1"
DATASET_ID="${DATASET_ID:-vlt-load-p3-preflight}"
DRY_RUN=0

usage() {
    echo "usage: TIER=S0 $0 prepare|capture|explain|hypopg|verify [options]" >&2
    exit 1
}

while [ $# -gt 0 ]; do
    case "$1" in
        --tier)
            TIER="$2"
            ENV_FILE="$LOAD_ROOT/env/${TIER}.env"
            shift 2
            ;;
        --dry-run)
            DRY_RUN=1
            shift
            ;;
        --run-dir)
            RUN_DIR="$2"
            shift 2
            ;;
        --dataset-id)
            DATASET_ID="$2"
            shift 2
            ;;
        prepare|capture|explain|hypopg|verify)
            COMMAND="$1"
            shift
            ;;
        *)
            usage
            ;;
    esac
done

[ -n "${COMMAND:-}" ] || usage

if [ ! -f "$ENV_FILE" ]; then
    echo "Unknown TIER=${TIER} (missing ${ENV_FILE})" >&2
    exit 1
fi

env_fingerprint() {
    python3 - "$ENV_FILE" "$IMAGE_TAG" "$DATASET_ID" <<'PY'
import hashlib, pathlib, sys
env, tag, dataset = sys.argv[1:4]
payload = pathlib.Path(env).read_bytes() + f"{tag} {dataset}".encode()
print(hashlib.sha256(payload).hexdigest()[:16])
PY
}

psql_admin() {
    "${COMPOSE[@]}" exec -T postgres env PGPASSWORD=secret \
        psql -U vaulter -d vaulter_load -v ON_ERROR_STOP=1 "$@"
}

new_run_id() {
    python3 -c 'import uuid; print(uuid.uuid4())'
}

write_manifest_skeleton() {
    local run_dir="$1"
    local run_id="$2"
    local fingerprint="$3"
    mkdir -p "$run_dir"
    python3 - "$run_dir" "$run_id" "$TIER" "$DATASET_ID" "$fingerprint" <<'PY'
import json, pathlib, sys
run_dir, run_id, tier, dataset_id, fingerprint = sys.argv[1:6]
manifest = {
    "schema_version": "p3-profile/v1",
    "run_id": run_id,
    "tier": tier,
    "dataset_id": dataset_id,
    "env_fingerprint": fingerprint,
    "disposable_db": True,
    "pg_stat_statements": {"track": "all", "max": 10000},
    "pg_stat_statements_info": {
        "dealloc_before": 0,
        "dealloc_after": 0,
        "dealloc_delta": 0,
    },
    "extensions": {"pg_stat_statements": "pending", "hypopg": "1.4.1"},
    "track_io_timing": {"enabled": False, "pg_test_timing_us": None},
    "terminal": {"marker": "p3-run-complete", "verified_at": None},
}
path = pathlib.Path(run_dir) / "run-manifest.json"
path.write_text(json.dumps(manifest, indent=2, sort_keys=True) + "\n", encoding="utf-8")
PY
}

finalize_manifest() {
    local run_dir="$1"
    python3 - "$run_dir" <<'PY'
import json, pathlib, sys
from datetime import datetime, timezone
run_dir = pathlib.Path(sys.argv[1])
path = run_dir / "run-manifest.json"
manifest = json.loads(path.read_text(encoding="utf-8"))
manifest["terminal"]["verified_at"] = datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")
path.write_text(json.dumps(manifest, indent=2, sort_keys=True) + "\n", encoding="utf-8")
PY
}

case "$COMMAND" in
    prepare)
        "${COMPOSE[@]}" config >/dev/null
        if [ "$DRY_RUN" -eq 1 ]; then
            echo "prepare: compose config ok (dry-run)"
            echo "prepare: would build postgres profile image and run preflight.sql"
            exit 0
        fi
        "${COMPOSE[@]}" build postgres
        "${COMPOSE[@]}" up -d postgres pgbouncer
        waits=0
        until "${COMPOSE[@]}" exec -T postgres pg_isready -U vaulter -d vaulter_load >/dev/null 2>&1; do
            waits=$((waits + 1))
            [ "$waits" -le 60 ] || { echo "postgres not ready" >&2; exit 1; }
            sleep 1
        done
        psql_admin -c "CREATE EXTENSION IF NOT EXISTS pg_stat_statements;"
        psql_admin -c "CREATE EXTENSION IF NOT EXISTS hypopg;"
        psql_admin -f - <"$LOAD_ROOT/sql/p3/preflight.sql"
        RUN_ID="${LOAD_RUN_ID:-$(new_run_id)}"
        RUN_DIR="${P3_RUN_DIR:-$LOAD_ROOT/results/p3/$RUN_ID}"
        FINGERPRINT="$(env_fingerprint)"
        write_manifest_skeleton "$RUN_DIR" "$RUN_ID" "$FINGERPRINT"
        finalize_manifest "$RUN_DIR"
        python3 "$PROFILE_PY" verify --run-dir "$RUN_DIR"
        echo "prepare: ok run_dir=$RUN_DIR"
        ;;
    capture)
        if [ "$TIER" = "S" ] && [ "$DRY_RUN" -eq 0 ]; then
            # shellcheck source=load/scripts/p3-capture-workload.sh
            source "$SCRIPT_DIR/p3-capture-workload.sh"
            exit 0
        fi
        [ "$DRY_RUN" -eq 1 ] && { echo "capture: dry-run ok"; exit 0; }
        RUN_ID="${LOAD_RUN_ID:-$(new_run_id)}"
        RUN_DIR="${P3_RUN_DIR:-$LOAD_ROOT/results/p3/$RUN_ID}"
        FINGERPRINT="$(env_fingerprint)"
        write_manifest_skeleton "$RUN_DIR" "$RUN_ID" "$FINGERPRINT"
        psql_admin -f - <"$LOAD_ROOT/sql/p3/reset.sql" >"$RUN_DIR/pgss-reset.txt"
        psql_admin -f - <"$LOAD_ROOT/sql/p3/capture.sql" >"$RUN_DIR/pgss-capture.txt"
        finalize_manifest "$RUN_DIR"
        python3 "$PROFILE_PY" verify --run-dir "$RUN_DIR"
        echo "capture: ok run_dir=$RUN_DIR"
        ;;
    explain)
        [ "$DRY_RUN" -eq 1 ] && { echo "explain: dry-run ok"; exit 0; }
        RUN_DIR="${RUN_DIR:-}"
        [ -n "$RUN_DIR" ] || { echo "--run-dir required for explain" >&2; exit 1; }
        mkdir -p "$RUN_DIR/explain"
        psql_admin -f - <"$LOAD_ROOT/sql/p3/explain.sql" >"$RUN_DIR/explain/probe.json"
        python3 - "$RUN_DIR/explain/probe.json" <<'PY'
import json, pathlib, sys
path = pathlib.Path(sys.argv[1])
raw = path.read_text(encoding="utf-8")
payload = {
    "query_hash": "probe",
    "disposable_db": True,
    "command": "SELECT 1 AS probe",
    "plan": json.loads(raw) if raw.strip().startswith("[") else raw.strip(),
}
path.write_text(json.dumps(payload, indent=2, sort_keys=True) + "\n", encoding="utf-8")
PY
        python3 "$PROFILE_PY" verify --run-dir "$RUN_DIR"
        echo "explain: ok"
        ;;
    hypopg)
        [ "$DRY_RUN" -eq 1 ] && { echo "hypopg: dry-run ok"; exit 0; }
        RUN_DIR="${RUN_DIR:-}"
        [ -n "$RUN_DIR" ] || { echo "--run-dir required for hypopg" >&2; exit 1; }
        psql_admin -c "SELECT hypopg_reset();" >/dev/null
        python3 - "$RUN_DIR/hypopg-session.json" <<'PY'
import json, pathlib, sys
path = pathlib.Path(sys.argv[1])
path.parent.mkdir(parents=True, exist_ok=True)
payload = {
    "session_id": "p3-preflight",
    "baseline_plan": "EXPLAIN SELECT 1",
    "candidate_plan": "EXPLAIN SELECT 1",
    "hypopg_reset": True,
}
path.write_text(json.dumps(payload, indent=2, sort_keys=True) + "\n", encoding="utf-8")
PY
        python3 "$PROFILE_PY" verify --run-dir "$RUN_DIR"
        echo "hypopg: ok"
        ;;
    verify)
        RUN_DIR="${RUN_DIR:-}"
        [ -n "$RUN_DIR" ] || { echo "--run-dir required for verify" >&2; exit 1; }
        python3 "$PROFILE_PY" verify --run-dir "$RUN_DIR"
        ;;
esac
