#!/bin/sh
# Cgroup `memory.peak` per stand container, written to a JSON file
# perf-assert.py can read with `--container-peak`. This is a GROUNDED but coarse
# stand-in for `vaulter_request_peak_memory_bytes` (not shipped yet): it catches
# "the whole container blew past its limit", not "which request did it" — the
# distinction is printed by perf-assert.py, never hidden (Code Guidance
# "Анти-самообман", Scope Included item's own limitation note).
#
# Usage:
#   TIER=S0 ./load/scripts/collect-container-stats.sh > container-peak.json
set -eu

cd "$(dirname "$0")/.."

TIER="${TIER:-S0}"
PROJECT="${COMPOSE_PROJECT_NAME:-load}"

memory_peak_bytes() {
    service="$1"
    cid="$(docker compose -f docker-compose.load.yml --env-file "env/${TIER}.env" ps -q "$service" 2>/dev/null || true)"

    if [ -z "$cid" ]; then
        echo "null"
        return
    fi

    # cgroup v2 path inside the container's own namespace — `docker exec` runs INSIDE
    # the container so this is the container's own memory.peak, not the host's.
    docker exec "$cid" sh -c 'cat /sys/fs/cgroup/memory.peak 2>/dev/null || echo null'
}

app_peak="$(memory_peak_bytes app)"
pg_peak="$(memory_peak_bytes postgres)"

cat <<JSON
{
  "tier": "${TIER}",
  "app": { "memory_peak_bytes": ${app_peak} },
  "postgres": { "memory_peak_bytes": ${pg_peak} }
}
JSON
