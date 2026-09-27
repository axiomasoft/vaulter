#!/usr/bin/env bash
# P3.2 — tier-S controlled workload capture (included from p3-profile.sh capture).
set -euo pipefail

: "${SCRIPT_DIR:?}"
: "${LOAD_ROOT:?}"
: "${REPO_ROOT:?}"
: "${TIER:?}"
: "${ENV_FILE:?}"
: "${COMPOSE:?}"
: "${PROFILE_PY:?}"
: "${WORKLOAD_PY:?}"

P3_DURATION="${P3_DURATION:-120s}"
APP_URL="${LOAD_APP_URL:-http://127.0.0.1:${LOAD_APP_PORT:-8080}}"
IMAGE_VERSIONS="vaulter-load-postgres-profile:16-hypopg-1.4.1 minio/minio:latest php:8.4-fpm-alpine grafana/k6:0.55.0"

if [ "$TIER" != "S" ]; then
    echo "P3.2 capture requires TIER=S (authoritative tier); got TIER=${TIER}" >&2
    exit 1
fi

python3 "$WORKLOAD_PY" preflight

IMAGE_VERSIONS="vaulter-load-postgres-profile:16-hypopg-1.4.1 minio/minio:latest php:8.4-fpm-alpine grafana/k6:0.55.0"
GIT_SHA="$(git -C "$REPO_ROOT" rev-parse HEAD 2>/dev/null || echo unknown)"
P87_RUN_ID="$(python3 - "$REPO_ROOT" <<'PY'
import json, pathlib, sys
path = pathlib.Path(sys.argv[1]) / "plans/VLT-LOAD/artifacts/P8.7/run-manifest.json"
manifest = json.loads(path.read_text(encoding="utf-8"))
print(manifest.get("run_id") or manifest.get("run_id_sha256", ""))
PY
)"
RUN_ID="${LOAD_RUN_ID:-$(python3 -c 'import uuid; print(uuid.uuid4())')}"
RUN_DIR="${P3_RUN_DIR:-$LOAD_ROOT/results/p3/$RUN_ID}"
TRACE_HOST_PATH="$LOAD_ROOT/results/p3/$RUN_ID/query-trace.jsonl"
TRACE_PATH="/app/load/results/p3/$RUN_ID/query-trace.jsonl"
mkdir -p "$RUN_DIR"
mkdir -p "$(dirname "$TRACE_HOST_PATH")"
chmod 0777 "$(dirname "$TRACE_HOST_PATH")"
: >"$TRACE_HOST_PATH"
chmod 0666 "$TRACE_HOST_PATH"
chmod 0777 "$LOAD_ROOT/results" 2>/dev/null || true

resolve_dataset() {
    MANIFEST="$(ls -t "$LOAD_ROOT/seed/out"/vlt-load-*.json 2>/dev/null | head -n1 || true)"
    if [ -z "$MANIFEST" ]; then
        echo "No seed manifest — run load:seed first" >&2
        exit 1
    fi
    DATASET_ID="$(basename "$MANIFEST" .json)"
    FINGERPRINT="$(python3 - "$ENV_FILE" "$IMAGE_VERSIONS" "$DATASET_ID" <<'PY'
import hashlib, pathlib, sys
env, images, dataset = sys.argv[1:4]
payload = pathlib.Path(env).read_bytes() + f"{images} {dataset}".encode()
print(hashlib.sha256(payload).hexdigest()[:16])
PY
)"
}

psql_admin() {
    "${COMPOSE[@]}" exec -T postgres env PGPASSWORD=secret \
        psql -U vaulter -d vaulter_load -v ON_ERROR_STOP=1 "$@"
}

psql_file() {
    cat "$1" | psql_admin -f -
}

extract_psql_json() {
    python3 -c "
import sys
for line in sys.stdin:
    s = line.strip()
    if s.startswith('{') and s.endswith('}'):
        print(s)
        raise SystemExit(0)
    if s.startswith('[{'):
        print(s)
        raise SystemExit(0)
raise SystemExit(1)
"
}

wait_db_table() {
    local table="$1"
    local max="${2:-120}"
    local waits=0
    until psql_admin -tAc "SELECT to_regclass('public.${table}')" 2>/dev/null | grep -q "${table}"; do
        waits=$((waits + 1))
        if [ "$waits" -gt "$max" ]; then
            echo "postgres table ${table} not ready" >&2
            exit 1
        fi
        sleep 2
    done
}

write_skeleton() {
    python3 - "$RUN_DIR" "$RUN_ID" "$TIER" "$DATASET_ID" "$FINGERPRINT" <<'PY'
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
    "pg_stat_statements_info": {"dealloc_before": 0, "dealloc_after": 0, "dealloc_delta": 0},
    "extensions": {"pg_stat_statements": "1.10", "hypopg": "1.4.1"},
    "track_io_timing": {"enabled": False, "pg_test_timing_us": None},
    "terminal": {"marker": "p3-run-complete", "verified_at": None},
}
path = pathlib.Path(run_dir) / "run-manifest.json"
path.write_text(json.dumps(manifest, indent=2, sort_keys=True) + "\n", encoding="utf-8")
PY
}

"${COMPOSE[@]}" config >/dev/null
if ! docker image inspect vaulter-load-postgres-profile:16-hypopg-1.4.1 >/dev/null 2>&1; then
    "${COMPOSE[@]}" build postgres
fi
if ! docker image inspect vaulter-load-app >/dev/null 2>&1; then
    "${COMPOSE[@]}" build app queue-worker
fi
"${COMPOSE[@]}" up -d --pull never postgres pgbouncer minio redis prometheus 2>/dev/null || \
    "${COMPOSE[@]}" up -d postgres pgbouncer minio redis prometheus
waits=0
until "${COMPOSE[@]}" exec -T postgres pg_isready -U vaulter -d vaulter_load >/dev/null 2>&1; do
    waits=$((waits + 1))
    [ "$waits" -le 90 ] || { echo "postgres not ready" >&2; exit 1; }
    sleep 1
done
psql_admin -c "CREATE EXTENSION IF NOT EXISTS pg_stat_statements;"
psql_admin -c "CREATE EXTENSION IF NOT EXISTS hypopg;"
psql_file "$LOAD_ROOT/sql/p3/preflight.sql" >/dev/null

LOAD_RUN_ID="$RUN_ID" LOAD_DB_TRACE=1 LOAD_DB_TRACE_PATH="$TRACE_PATH" \
    VAULTER_LOAD_METRICS_ENABLED=true \
    "${COMPOSE[@]}" up -d --pull never --no-deps --force-recreate app queue-worker

waits=0
until curl -fsS "${APP_URL}/up" >/dev/null 2>&1; do
    waits=$((waits + 1))
    [ "$waits" -le 120 ] || { echo "app /up not ready" >&2; exit 1; }
    sleep 2
done

wait_db_table v_nodes 120
wait_db_table v_webhook_deliveries 120

echo "Seeding disposable DB (migrate:fresh on app start)..." >&2
"${COMPOSE[@]}" exec -T app php vendor/bin/testbench load:seed --tier=S0 --fresh --no-interaction
resolve_dataset
write_skeleton

cp "$MANIFEST" "$LOAD_ROOT/k6/dataset.json"
cp "$LOAD_ROOT/gates/thresholds.js" "$LOAD_ROOT/k6/lib/thresholds.js"

psql_admin -c "ANALYZE;"
psql_file "$LOAD_ROOT/sql/p3/dataset-profile.sql" | extract_psql_json >"$RUN_DIR/dataset-profile.raw.json"
psql_file "$LOAD_ROOT/sql/p3/pg-stat-io.sql" | extract_psql_json >"$RUN_DIR/pg-stat-io-before.json"

echo "shared-buffer-cold-like via postgres restart (host page cache unchanged)" >&2
"${COMPOSE[@]}" restart postgres
waits=0
until "${COMPOSE[@]}" exec -T postgres pg_isready -U vaulter -d vaulter_load >/dev/null 2>&1; do
    waits=$((waits + 1))
    [ "$waits" -le 90 ] || { echo "postgres not ready after restart" >&2; exit 1; }
    sleep 1
done

python3 - "$RUN_DIR/workload.meta.json" "$P3_DURATION" <<'PY'
import json, pathlib, sys
duration = sys.argv[2]
path = pathlib.Path(sys.argv[1])
path.write_text(json.dumps({
    "profile": "mixed",
    "duration": duration,
    "warmup": "post-seed-analyze",
    "cache_declaration": "shared-buffer-cold-like_after_postgres_restart_page_cache_warm",
    "preflight": "P8.7 artifacts verify",
}, indent=2, sort_keys=True) + "\n", encoding="utf-8")
PY

run_mixed_workload() {
    local duration="$1"
    "${COMPOSE[@]}" exec -d -T app php vendor/bin/testbench load:activity-write --duration=180 >/dev/null 2>&1 || true
    DURATION="$duration" TIER="$TIER" LOAD_RUN_ID="$RUN_ID" \
        "$LOAD_ROOT/scripts/run.sh" scenarios/mixed.js
}

# Short warm pass (seed/preflight traffic excluded from ranking by later reset).
run_mixed_workload 30s

psql_file "$LOAD_ROOT/sql/p3/reset.sql" >"$RUN_DIR/pgss-reset.txt"
python3 - "$TRACE_HOST_PATH" <<'PY'
import fcntl, pathlib, sys
with pathlib.Path(sys.argv[1]).open('r+') as trace:
    fcntl.flock(trace, fcntl.LOCK_EX)
    trace.truncate(0)
    trace.flush()
    fcntl.flock(trace, fcntl.LOCK_UN)
PY

run_mixed_workload "$P3_DURATION"
touch "$RUN_DIR/workload.ok"

"${COMPOSE[@]}" exec -T app php vendor/bin/testbench artisan vaulter:relay-outbox --no-interaction >/dev/null || true
"${COMPOSE[@]}" exec -T app php vendor/bin/testbench artisan vaulter:retry-webhooks --no-interaction >/dev/null || true
"${COMPOSE[@]}" exec -T app php vendor/bin/testbench artisan vaulter:sweep-uploads --no-interaction >/dev/null || true
"${COMPOSE[@]}" exec -T app php vendor/bin/testbench artisan vaulter:gc-blobs --no-interaction >/dev/null || true

if [ ! -s "$TRACE_HOST_PATH" ]; then
    echo "P3 query trace is empty after workload; refusing to publish" >&2
    exit 1
fi
python3 - "$TRACE_HOST_PATH" "$RUN_ID" <<'PY'
import json, pathlib, sys
rows = [json.loads(line) for line in pathlib.Path(sys.argv[1]).read_text().splitlines() if line.strip()]
if not any(row.get('run_id') == sys.argv[2] and str(row.get('command_id', '')).startswith('http:') for row in rows):
    raise SystemExit('P3 measured-window trace has no HTTP SQL rows')
PY
if [ "$TRACE_HOST_PATH" != "$RUN_DIR/query-trace.jsonl" ]; then
    cp "$TRACE_HOST_PATH" "$RUN_DIR/query-trace.jsonl"
fi

psql_file "$LOAD_ROOT/sql/p3/pgss-export.sql" >"$RUN_DIR/pgss-export.txt"
psql_file "$LOAD_ROOT/sql/p3/hotspot-fingerprints.sql" | extract_psql_json >"$RUN_DIR/hotspot-fingerprints.json"
psql_file "$LOAD_ROOT/sql/p3/pg-stat-io.sql" | extract_psql_json >"$RUN_DIR/pg-stat-io-after.json"

python3 "$WORKLOAD_PY" build \
    --run-dir "$RUN_DIR" \
    --tier "$TIER" \
    --env-fingerprint "$FINGERPRINT" \
    --git-sha "$GIT_SHA" \
    --p87-run-id "$P87_RUN_ID"

python3 "$WORKLOAD_PY" verify-artifacts --artifact-dir "$RUN_DIR"
python3 "$WORKLOAD_PY" publish --run-dir "$RUN_DIR"
python3 "$WORKLOAD_PY" verify-artifacts --artifact-dir "$REPO_ROOT/plans/VLT-LOAD/artifacts/P3"
python3 "$WORKLOAD_PY" verify-artifacts --artifact-dir "$REPO_ROOT/plans/VLT-LOAD/artifacts/P3"

python3 - "$RUN_DIR/run-manifest.json" <<'PY'
import json, pathlib, sys
from datetime import datetime, timezone
path = pathlib.Path(sys.argv[1])
manifest = json.loads(path.read_text(encoding="utf-8"))
manifest["terminal"]["verified_at"] = datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")
path.write_text(json.dumps(manifest, indent=2, sort_keys=True) + "\n", encoding="utf-8")
PY

cp "$RUN_DIR/run-manifest.json" "$REPO_ROOT/plans/VLT-LOAD/artifacts/P3/run-manifest.json"

echo "capture: ok run_dir=$RUN_DIR artifacts=$REPO_ROOT/plans/VLT-LOAD/artifacts/P3"
