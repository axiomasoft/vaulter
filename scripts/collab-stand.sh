#!/usr/bin/env bash
# The I17 stand scenarios 2, 3 and 10 (collaboration#tests) against REAL processes in docker:
# PostgreSQL, Redis, the Laravel host behind `testbench serve` and TWO hubs
# (packages/collaboration/hub/stand/docker-compose.yml), then packages/collaboration/hub/test/stand/*.stand.js:
#   2  kill-after-retry — backend down past the retry deadline, SIGKILL of every hub: each acknowledged update is restored
#   3  two-hubs         — unrelated updates saved on two hubs at once: the rebuilt state holds both
#   10 sigterm-drain    — SIGTERM during typing: readiness falls at once, no receipt after the drain boundary
#
# Never part of a PLAN3 run (D41): it is run in PLAN4, or by hand, through `scripts/t stand`.
#
# Usage: bash scripts/collab-stand.sh
#   env: VAULTER_E2E_PORT=8787 (host), VAULTER_E2E_HUB_PORT=18234 (hub A; its management listener on +1),
#        VAULTER_E2E_HUB_B_PORT=18334 (hub B; management on +1), VAULTER_STAND_MODE=buffered|durable,
#        VAULTER_STAND_LOG=<file>
# Needs: docker with the compose plugin, npm ci in packages/collaboration/hub, composer install at the root
# (the host container bind-mounts the repository and its vendor/).
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
compose_file="$root/packages/collaboration/hub/stand/docker-compose.yml"
port="${VAULTER_E2E_PORT:-8787}"
hub_a_port="${VAULTER_E2E_HUB_PORT:-18234}"
hub_b_port="${VAULTER_E2E_HUB_B_PORT:-18334}"
log="${VAULTER_STAND_LOG:-${TMPDIR:-/tmp}/vaulter-collab-stand.log}"

export VAULTER_E2E_PORT="$port"
export VAULTER_E2E_HUB_PORT="$hub_a_port"
export VAULTER_E2E_HUB_MANAGEMENT_PORT="$((hub_a_port + 1))"
export VAULTER_E2E_HUB_B_PORT="$hub_b_port"
export VAULTER_E2E_HUB_B_MANAGEMENT_PORT="$((hub_b_port + 1))"

compose() {
    docker compose -f "$compose_file" "$@"
}

cleanup() {
    compose down -v --remove-orphans >>"$log" 2>&1 || true
}
trap cleanup EXIT INT TERM

mkdir -p "$(dirname "$log")"
: > "$log"

echo "== stand up (log: $log)" | tee -a "$log"
compose up -d --build --wait >>"$log" 2>&1 || {
    echo "collab-stand: the stand did not become healthy (see $log)" >&2
    compose ps -a >>"$log" 2>&1 || true
    compose logs --no-color >>"$log" 2>&1 || true
    exit 1
}

echo "== stand scenarios 2, 3, 10" | tee -a "$log"
status=0
# The scenarios crash and signal the same containers: one file at a time.
(
    cd "$root/packages/collaboration/hub"
    VAULTER_STAND_COMPOSE="$compose_file" \
    VAULTER_STAND_BACKEND="http://127.0.0.1:${port}" \
    VAULTER_STAND_HUB_A="ws://127.0.0.1:${hub_a_port}" \
    VAULTER_STAND_HUB_B="ws://127.0.0.1:${hub_b_port}" \
    VAULTER_STAND_MANAGEMENT_A="http://127.0.0.1:$((hub_a_port + 1))" \
    VAULTER_STAND_MANAGEMENT_B="http://127.0.0.1:$((hub_b_port + 1))" \
    node --test --test-concurrency=1 test/stand/*.stand.js
) 2>&1 | tee -a "$log" || status=$?

if [ "$status" -ne 0 ]; then
    compose logs --no-color >>"$log" 2>&1 || true
fi

echo "== exit $status (log: $log)" | tee -a "$log"
exit "$status"
