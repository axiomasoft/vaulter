#!/usr/bin/env bash
# Collaboration protocol 2 e2e against REAL processes: `vendor/bin/testbench serve` (the
# Laravel host of testbench.yaml on a throwaway SQLite file) and the Hocuspocus hub
# (`node packages/collaboration/hub/src/index.js`, its compaction worker included), then
# packages/collaboration/hub/test/e2e/restore-epoch.e2e.js: a client's edit is saved when the hub
# acknowledges its envelope; restore while a client is editing → the drain checkpoints the log, the
# generation closes, the hub closes the room with 4409 → the reopened room shows the restored content
# and the old edit never comes back; a read-only client changes nothing; a client with an older schema
# version is read-only.
#
# Usage: bash scripts/collab-e2e.sh   (env: VAULTER_E2E_PORT=8787, VAULTER_E2E_HUB_PORT=18234)
# Needs: composer install at the root, npm ci in packages/collaboration/hub, PHP 8.4 (scripts/php84).
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
port="${VAULTER_E2E_PORT:-8787}"
hub_port="${VAULTER_E2E_HUB_PORT:-18234}"
management_port="$((hub_port + 1))"
log="${VAULTER_E2E_LOG:-${TMPDIR:-/tmp}/vaulter-collab-e2e.log}"
work="$(mktemp -d "${TMPDIR:-/tmp}/vaulter-collab-e2e.XXXXXX")"
pids=()

cleanup() {
    for pid in "${pids[@]}"; do
        kill "$pid" 2>/dev/null || true
    done
    wait 2>/dev/null || true
    rm -rf -- "$work"
}
trap cleanup EXIT INT TERM

mkdir -p "$(dirname "$log")"
: > "$log"

jwt_secret="e2e-collab-jwt-secret-$(date +%s)-at-least-32-bytes"
webhook_secret="e2e-collab-webhook-secret-$(date +%s)"

# testbench serve hands its child only a curated environment, so the host is configured
# through its own testbench.yaml in a throwaway working path (with the repo's vendor).
ln -s "$root/vendor" "$work/vendor"
touch "$work/database.sqlite"
cat > "$work/testbench.yaml" <<YAML
providers:
  - Vaulter\\VaulterServiceProvider
  - Vaulter\\Media\\VaulterMediaServiceProvider
  - Vaulter\\Drive\\VaulterDriveServiceProvider
  - Vaulter\\Documents\\VaulterDocumentsServiceProvider
  - Vaulter\\Collaboration\\VaulterCollaborationServiceProvider
  - Vaulter\\Integration\\Tests\\E2E\\E2eServiceProvider
env:
  - APP_KEY="base64:lRknxO3IznrZODHVPvLNKylvYP19ch+EXZ8dM+i3OlY="
  - APP_ENV="local"
  - APP_URL="http://127.0.0.1:${port}"
  - DB_CONNECTION="sqlite"
  - DB_DATABASE="${work}/database.sqlite"
  - CACHE_STORE="file"
  - VAULTER_COLLAB_DRIVER="hocuspocus"
  - VAULTER_COLLAB_URL="ws://127.0.0.1:${hub_port}"
  - VAULTER_COLLAB_SECRET="${jwt_secret}"
  - VAULTER_COLLAB_WEBHOOK_SECRET="${webhook_secret}"
YAML

testbench() {
    (cd "$root" && TESTBENCH_WORKING_PATH="$work" "$root/scripts/php84" "$root/vendor/bin/testbench" "$@")
}

wait_for() {
    local url="$1" name="$2"
    for _ in $(seq 1 100); do
        if curl -fsS "$url" >/dev/null 2>&1; then
            return 0
        fi
        sleep 0.2
    done
    echo "collab-e2e: $name did not become healthy at $url" | tee -a "$log" >&2
    exit 1
}

echo "== migrate ($work/database.sqlite)" | tee -a "$log"
testbench migrate:fresh --force >>"$log" 2>&1

echo "== testbench serve :$port" | tee -a "$log"
testbench serve --host=127.0.0.1 --port="$port" >>"$log" 2>&1 &
pids+=("$!")
wait_for "http://127.0.0.1:${port}/e2e/health" "testbench serve"

echo "== hub :$hub_port" | tee -a "$log"
(
    cd "$root/packages/collaboration/hub"
    NODE_ENV=test \
    VAULTER_LARAVEL_WEBHOOK_URL="http://127.0.0.1:${port}/api/vaulter/v1" \
    VAULTER_COLLAB_WEBHOOK_SECRET="$webhook_secret" \
    VAULTER_COLLAB_PORT="$hub_port" \
    VAULTER_COLLAB_MANAGEMENT_PORT="$management_port" \
    VAULTER_COLLAB_DEBOUNCE_MS=200 \
    VAULTER_COLLAB_MAX_DEBOUNCE_MS=1000 \
    VAULTER_COLLAB_THROTTLE=1000 \
    VAULTER_COLLAB_FLUSH_INTERVAL_MS=200 \
    VAULTER_COLLAB_COMPACTION_INTERVAL_MS=500 \
    exec node src/index.js
) >>"$log" 2>&1 &
pids+=("$!")
wait_for "http://127.0.0.1:${management_port}/livez" "hub"

echo "== restore-epoch e2e" | tee -a "$log"
status=0
(
    cd "$root/packages/collaboration/hub"
    VAULTER_E2E_BACKEND="http://127.0.0.1:${port}" \
    VAULTER_E2E_HUB="ws://127.0.0.1:${hub_port}" \
    node --test test/e2e/restore-epoch.e2e.js
) 2>&1 | tee -a "$log" || status=$?

echo "== exit $status (log: $log)" | tee -a "$log"
exit "$status"
