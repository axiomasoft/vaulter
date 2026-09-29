#!/usr/bin/env bash
# P8.7 — one LOAD_RUN_ID, live Prometheus scrape, 50-family matrix, perf-assert, on/off overhead.
# Usage:
#   TIER=S0 ./load/scripts/p8-live-gate.sh gate --profile smoke-perf
#   TIER=S  ./load/scripts/p8-live-gate.sh overhead --baseline plans/archive/VLT-LOAD/findings/P2.8-baseline-S.md
#   python3 load/gates/p8_live_gate.py verify --artifacts plans/archive/VLT-LOAD/artifacts/P8.7
set -euo pipefail

cd "$(dirname "$0")/.."
REPO_ROOT="$(cd .. && pwd)"
TIER="${TIER:-S0}"
ENV_FILE="env/${TIER}.env"
COMPOSE=(
    docker compose
    -f docker-compose.load.yml
    -f docker-compose.p87-host.yml
    --env-file "$ENV_FILE"
)
IMAGE_VERSIONS="postgres:16-alpine minio/minio:latest php:8.4-fpm-alpine grafana/k6:0.55.0"
ART="${P8_ARTIFACTS:-$REPO_ROOT/plans/archive/VLT-LOAD/artifacts/P8.7}"
RAW="$ART/raw"
GATE_PY="$REPO_ROOT/load/gates/p8_live_gate.py"
APP_URL="${LOAD_APP_URL:-http://127.0.0.1:${LOAD_APP_PORT:-8080}}"
PROM_URL="${LOAD_PROM_URL:-http://127.0.0.1:9090}"
PM_MAX_CHILDREN="$(awk -F= '/^PM_MAX_CHILDREN=/{print $2}' "$ENV_FILE")"

if [ ! -f "$ENV_FILE" ]; then
    echo "Unknown TIER=${TIER}" >&2
    exit 1
fi

mkdir -p "$ART" "$RAW"
chmod 0777 results 2>/dev/null || true

COMMAND="${1:-}"
shift || true

new_run_id() {
    python3 -c 'import uuid; print(uuid.uuid4())'
}

wait_db_table() {
    table="$1"
    max="${2:-60}"
    waits=0
    until "${COMPOSE[@]}" exec -T postgres sh -c "PGPASSWORD=secret psql -U vaulter -d vaulter_load -tAc \"SELECT to_regclass('public.${table}')\"" 2>/dev/null | grep -q "${table}"; do
        waits=$((waits + 1))
        if [ "$waits" -gt "$max" ]; then
            echo "postgres table ${table} not ready" >&2
            exit 1
        fi
        sleep 1
    done
}

wait_queue_worker() {
    waits=0
    until "${COMPOSE[@]}" exec -T queue-worker sh -c 'tr "\0" " " < /proc/1/cmdline | grep -q "queue:work"'; do
        waits=$((waits + 1))
        if [ "$waits" -gt 90 ]; then
            echo "queue-worker did not reach queue:work" >&2
            "${COMPOSE[@]}" logs --tail=80 queue-worker >&2 || true
            exit 1
        fi
        sleep 2
    done
    wait_db_table v_webhook_deliveries 60
}

compose_up() {
    "${COMPOSE[@]}" up -d --build postgres pgbouncer minio minio-init prometheus redis
    waits=0
    until "${COMPOSE[@]}" exec -T minio mc ready local >/dev/null 2>&1; do
        waits=$((waits + 1))
        if [ "$waits" -gt 30 ]; then
            echo "minio did not become ready" >&2
            exit 1
        fi
        sleep 2
    done
    "${COMPOSE[@]}" exec -T minio mc alias set load http://127.0.0.1:9000 vaulter vaulter-load-secret >/dev/null
    "${COMPOSE[@]}" exec -T minio mc mb --ignore-existing load/vaulter-load >/dev/null
    printf 'ok' | "${COMPOSE[@]}" exec -T minio mc pipe load/vaulter-load/.vaulter-health-sentinel >/dev/null
    "${COMPOSE[@]}" stop queue-worker >/dev/null 2>&1 || true
    "${COMPOSE[@]}" up -d --build --no-deps app
    waits=0
    until curl -fsS "${APP_URL}/up" >/dev/null 2>&1; do
        waits=$((waits + 1))
        if [ "$waits" -gt 90 ]; then
            echo "SUT /up did not become ready" >&2
            "${COMPOSE[@]}" logs --tail=80 app postgres minio redis prometheus >&2 || true
            exit 1
        fi
        sleep 2
    done
    wait_db_table v_webhook_deliveries 90
    "${COMPOSE[@]}" up -d --no-deps queue-worker
    wait_queue_worker
}

seed_s0() {
    "${COMPOSE[@]}" stop queue-worker >/dev/null 2>&1 || true
    "${COMPOSE[@]}" exec -T app php vendor/bin/testbench load:seed --tier=S0 --fresh
    "${COMPOSE[@]}" up -d --no-deps queue-worker
    wait_queue_worker
}

open_run() {
    curl -fsS -X POST "${APP_URL}/metrics/run/open" \
        -H 'Content-Type: application/json' \
        -d "{\"run_id\":\"${LOAD_RUN_ID}\",\"ttl_seconds\":7200}" >"$RAW/open.json"
}

finalize_run() {
    curl -fsS -X POST "${APP_URL}/metrics/run/finalize" \
        -H 'Content-Type: application/json' \
        -d "{\"run_id\":\"${LOAD_RUN_ID}\"}" >"$RAW/finalize.json" || true
}

app_artisan() {
    "${COMPOSE[@]}" exec -T -e LOAD_RUN_ID="$LOAD_RUN_ID" app php vendor/bin/testbench "$@"
}

record_trigger() {
    name="$1"
    command="$2"
    zero_ok="${3:-false}"
    python3 - "$RAW/triggers.json" "$name" "$command" "$zero_ok" <<'PY'
import json, sys
from pathlib import Path
path = Path(sys.argv[1])
payload = json.loads(path.read_text()) if path.exists() else {}
payload[sys.argv[2]] = {"ran": True, "command": sys.argv[3], "zero_ok": sys.argv[4] == "true"}
path.write_text(json.dumps(payload, indent=2, sort_keys=True) + "\n")
PY
}

run_triggers() {
    python3 -c 'import json,sys; from pathlib import Path; Path(sys.argv[1]).write_text("{}\n")' "$RAW/triggers.json"
    curl -fsS "${APP_URL}/load/metrics-snapshot" >/dev/null || true
    for family in vaulter_upload_session_active vaulter_request_peak_memory_bytes vaulter_outbox_backlog vaulter_broadcast_queue_wait_seconds vaulter_download_stream_active; do
        record_trigger "$family" "GET /load/metrics-snapshot" true
    done

    app_artisan vaulter:relay-outbox --limit=100 || true
    record_trigger vaulter_outbox_stage_duration_seconds "vaulter:relay-outbox" true
    record_trigger vaulter_outbox_relay_duration_seconds "vaulter:relay-outbox" true
    record_trigger vaulter_outbox_relayed_total "vaulter:relay-outbox" true
    record_trigger vaulter_outbox_endpoints_scanned_total "vaulter:relay-outbox" true
    record_trigger vaulter_webhook_queue_wait_seconds "vaulter:relay-outbox" true
    record_trigger vaulter_webhook_delivery_duration_seconds "vaulter:relay-outbox" true
    record_trigger vaulter_webhook_delivered_total "vaulter:relay-outbox" true

    app_artisan vaulter:retry-webhooks || true
    record_trigger vaulter_webhook_retry_tick_seconds "vaulter:retry-webhooks" true
    record_trigger vaulter_webhook_retry_lockset_rows "vaulter:retry-webhooks" true
    record_trigger vaulter_failed_jobs_total "vaulter:retry-webhooks" true

    app_artisan vaulter:sweep-uploads || true
    record_trigger vaulter_sweep_tick_seconds "vaulter:sweep-uploads" true
    record_trigger vaulter_sweep_batch_rows "vaulter:sweep-uploads" true
    record_trigger vaulter_sweep_expired_total "vaulter:sweep-uploads" true
    record_trigger vaulter_sweep_skipped_total "vaulter:sweep-uploads" true
    record_trigger vaulter_command_peak_memory_bytes "vaulter:sweep-uploads" true

    app_artisan vaulter:gc-blobs || true
    record_trigger vaulter_gc_duration_seconds "vaulter:gc-blobs" true
    record_trigger vaulter_gc_batch_rows "vaulter:gc-blobs" true
    record_trigger vaulter_gc_rows_scanned_total "vaulter:gc-blobs" true
    record_trigger vaulter_gc_objects_deleted_total "vaulter:gc-blobs" true

    app_artisan vaulter:purge-trash || true
    record_trigger vaulter_purge_duration_seconds "vaulter:purge-trash" true
    record_trigger vaulter_purge_batch_nodes "vaulter:purge-trash" true
    record_trigger vaulter_purge_nodes_total "vaulter:purge-trash" true

    app_artisan vaulter:prune-webhooks --days=0 || true
    record_trigger vaulter_prune_duration_seconds "vaulter:prune-webhooks" true
    record_trigger vaulter_prune_rows_total "vaulter:prune-webhooks" true

    app_artisan vaulter:reconcile-blobs || true
    record_trigger vaulter_reconcile_duration_seconds "vaulter:reconcile-blobs" true
    record_trigger vaulter_reconcile_objects_total "vaulter:reconcile-blobs" true

    "${COMPOSE[@]}" exec -T postgres sh -c 'apk add --no-cache postgresql-client >/dev/null 2>&1 || true; PGPASSWORD=secret psql -U vaulter -d vaulter_load -c "SELECT 1"' >/dev/null 2>&1 || true
    record_trigger vaulter_pg_lock_wait_seconds "collect-postgres-lock-waits.sh" true
    record_trigger vaulter_upload_conflict_total "k6 mixed conflict path" true
    record_trigger vaulter_throttled_total "k6 mixed throttle path" true

    if [ -f k6/dataset.json ] || ls -t seed/out/vlt-load-*.json >/dev/null 2>&1; then
        MANIFEST="$(ls -t seed/out/vlt-load-*.json | head -n1)"
        cp "$MANIFEST" k6/dataset.json
        node "$REPO_ROOT/load/scripts/p8-collab-join.mjs" --backend "$APP_URL" --dataset k6/dataset.json --limit 4 --duration 15s >/dev/null 2>&1 || true
    fi
    record_trigger vaulter_collab_room_active "p8-collab-join.mjs" true
}

mark_http_triggers() {
    for family in \
        vaulter_http_request_duration_seconds \
        vaulter_tree_query_duration_seconds \
        vaulter_tree_cte_anchors \
        vaulter_subtree_nodes_touched \
        vaulter_activity_feed_duration_seconds \
        vaulter_activity_write_total \
        vaulter_export_duration_seconds \
        vaulter_collab_session_issued_total \
        vaulter_collab_snapshot_duration_seconds \
        vaulter_upload_bytes_total \
        vaulter_upload_chunk_duration_seconds \
        vaulter_assemble_bytes_total \
        vaulter_assemble_duration_seconds \
        vaulter_download_bytes_total \
        vaulter_download_ttfb_seconds
    do
        record_trigger "$family" "TIER=${TIER} ./load/scripts/run.sh ${PROFILE}" false
    done
}

wait_scrape() {
    sleeps=0
    until curl -fsS "${PROM_URL}/api/v1/targets" | python3 -c 'import json,sys; data=json.load(sys.stdin)["data"]["activeTargets"];
raise SystemExit(0 if any(t.get("labels",{}).get("job")=="vaulter-sut" and t.get("health")=="up" for t in data) else 1)'; do
        sleeps=$((sleeps + 1))
        if [ "$sleeps" -gt 30 ]; then
            echo "Prometheus job vaulter-sut is not up" >&2
            exit 1
        fi
        sleep 2
    done
    sleep 10
}

collect_scrape() {
    curl -fsS "${APP_URL}/metrics?run_id=${LOAD_RUN_ID}" >"$RAW/exposition.prom"
    curl -fsS "${PROM_URL}/api/v1/query" --data-urlencode 'query={job="vaulter-sut"}' >"$RAW/prometheus-query.json"
}

write_manifest() {
    DATASET_ID="$(basename "$(ls -t seed/out/vlt-load-*.json | head -n1)" .json)"
    FINGERPRINT="$( (cat "$ENV_FILE"; printf '%s %s' "$IMAGE_VERSIONS" "$DATASET_ID") | sha256sum | cut -c1-16)"
    python3 - "$RAW/manifest-fields.json" "$LOAD_RUN_ID" "$TIER" "$PROFILE" "$DATASET_ID" "$FINGERPRINT" "$IMAGE_VERSIONS" "$METRICS_ENABLED" <<'PY'
import hashlib, json, sys
from pathlib import Path
run_id, tier, profile, dataset, fingerprint, images, enabled = sys.argv[2:]
Path(sys.argv[1]).write_text(json.dumps({
    "run_id_sha256": hashlib.sha256(run_id.encode()).hexdigest(),
    "tier": tier,
    "profile": profile,
    "dataset_id": dataset,
    "env_fingerprint": fingerprint,
    "image_versions": images,
    "prometheus_job": "vaulter-sut",
    "metrics_enabled": enabled == "true",
    "rejected_attempts": [],
}, indent=2, sort_keys=True) + "\n")
PY
    python3 "$GATE_PY" manifest --fields "$RAW/manifest-fields.json" --out "$ART/run-manifest.json"
    printf '%s' "$FINGERPRINT"
}

collect_rss() {
    ./scripts/collect-container-stats.sh >"$RAW/container-peak.json"
}

app_anon_rss_bytes() {
    "${COMPOSE[@]}" exec -T app sh -c "grep -E '^anon ' /sys/fs/cgroup/memory.stat 2>/dev/null | head -n1 | awk '{print \$2}'" 2>/dev/null | tr -d '\r\n ' || echo 0
}

run_gate() {
    PROFILE="${PROFILE:-smoke-perf}"
    while [ $# -gt 0 ]; do
        case "$1" in
            --profile) PROFILE="$2"; shift 2 ;;
            *) echo "unknown gate flag $1" >&2; exit 1 ;;
        esac
    done
    METRICS_ENABLED="${VAULTER_LOAD_METRICS_ENABLED:-true}"
    export LOAD_RUN_ID="${LOAD_RUN_ID:-$(new_run_id)}"
    export VAULTER_LOAD_METRICS_ENABLED="$METRICS_ENABLED"
    export TIER
    printf '%s\n' "$LOAD_RUN_ID" >"$RAW/run-id.private"
    chmod 600 "$RAW/run-id.private"
    compose_up
    seed_s0
    open_run
    run_triggers
    mark_http_triggers
    TIER="$TIER" ./scripts/run.sh "$PROFILE" || true
    wait_scrape
    collect_scrape
    collect_rss
    python3 "$GATE_PY" metric-matrix --exposition "$RAW/exposition.prom" --triggers "$RAW/triggers.json" --out "$ART/metric-matrix.json"
    python3 "$GATE_PY" verdicts --summary results/summary.json --prometheus-url "$PROM_URL" --profile "$PROFILE" --pm-max-children "${PM_MAX_CHILDREN:-4}" --out "$RAW/verdicts.json"
    python3 "$GATE_PY" gate-matrix --verdicts "$RAW/verdicts.json" --profile "$PROFILE" --tier "$TIER" --out "$ART/gate-matrix.json"
    write_manifest >/dev/null
    finalize_run
    echo "p8-live-gate gate: artifacts in $ART"
}

restart_overhead_leg() {
    "${COMPOSE[@]}" stop queue-worker >/dev/null 2>&1 || true
    "${COMPOSE[@]}" up -d --no-deps app
    waits=0
    until curl -fsS "${APP_URL}/up" >/dev/null 2>&1; do
        waits=$((waits + 1))
        if [ "$waits" -gt 90 ]; then
            echo "SUT /up did not become ready for overhead leg" >&2
            exit 1
        fi
        sleep 2
    done
    wait_db_table v_webhook_deliveries 90
    "${COMPOSE[@]}" up -d --no-deps queue-worker
    wait_queue_worker
}

measure_pair_leg() {
    label="$1"
    enabled="$2"
    export VAULTER_LOAD_METRICS_ENABLED="$enabled"
    export LOAD_RUN_ID="$(new_run_id)"
    METRICS_ENABLED="$enabled"
    restart_overhead_leg
    if [ "$enabled" = "true" ]; then
        open_run
        run_triggers
        mark_http_triggers
    fi
    TIER="$TIER" ./scripts/run.sh "${PROFILE:-smoke-perf}" || true
    collect_rss
    app_rss="$(app_anon_rss_bytes)"
    DATASET_ID="$(basename "$(ls -t seed/out/vlt-load-*.json | head -n1)" .json)"
    FINGERPRINT="$( (cat "$ENV_FILE"; printf '%s %s' "$IMAGE_VERSIONS" "$DATASET_ID") | sha256sum | cut -c1-16)"
    python3 - "$RAW/${label}.json" "$RAW/container-peak.json" "results/summary.json" "$FINGERPRINT" "$DATASET_ID" "$IMAGE_VERSIONS" "$ENV_FILE" "$app_rss" <<'PY'
import json, sys
from pathlib import Path
out, peak_path, summary_path, fingerprint, dataset, images, env_file, app_rss = sys.argv[1:]
peak = json.loads(Path(peak_path).read_text())
summary = json.loads(Path(summary_path).read_text())
http = summary.get("metrics", {}).get("http_req_duration", {}).get("values", {})
rss_bytes = int(str(app_rss).strip() or 0)
Path(out).write_text(json.dumps({
    "env_fingerprint": fingerprint,
    "dataset_id": dataset,
    "image_versions": images,
    "cpuset": Path(env_file).read_text(),
    "http_p95_ms": float(http.get("p(95)", 0)),
    "app_rss_bytes": rss_bytes,
}, indent=2, sort_keys=True) + "\n")
PY
    if [ "$enabled" = "true" ]; then
        finalize_run
    fi
}

run_overhead() {
    BASELINE=""
    while [ $# -gt 0 ]; do
        case "$1" in
            --baseline) BASELINE="$2"; shift 2 ;;
            --profile) PROFILE="$2"; shift 2 ;;
            *) echo "unknown overhead flag $1" >&2; exit 1 ;;
        esac
    done
    PROFILE="${PROFILE:-smoke-perf}"
    if [ -z "$BASELINE" ]; then
        echo "overhead requires --baseline" >&2
        exit 1
    fi
    compose_up
    seed_s0
    measure_pair_leg off false
    measure_pair_leg on true
    python3 "$GATE_PY" overhead --on "$RAW/on.json" --off "$RAW/off.json" --out "$ART/overhead.json"
    echo "p8-live-gate overhead: $ART/overhead.json (baseline $BASELINE)"
}

case "$COMMAND" in
    gate) run_gate "$@" ;;
    overhead) run_overhead "$@" ;;
    verify) python3 "$GATE_PY" verify --artifacts "$ART" ;;
    *)
        echo "Usage: $0 gate|overhead|verify" >&2
        exit 1
        ;;
esac
