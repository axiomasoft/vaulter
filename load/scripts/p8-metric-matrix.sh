#!/usr/bin/env bash
# P8.7 — open a metrics run, exercise host collectors, verify 50/50 families on /metrics.
set -euo pipefail

cd "$(dirname "$0")/.."

APP_URL="${LOAD_APP_URL:-http://127.0.0.1:${LOAD_APP_PORT:-8080}}"

OPEN_JSON="$(curl -fsS -X POST "${APP_URL}/metrics/run/open" -H 'Content-Type: application/json' -d '{}')"
RUN_ID="$(printf '%s' "$OPEN_JSON" | python3 -c 'import json,sys; print(json.load(sys.stdin)["run_id"])')"
export LOAD_RUN_ID="$RUN_ID"
echo "p8-metric-matrix: opened run_id=${RUN_ID}"

curl -fsS "${APP_URL}/load/metrics-snapshot" >/dev/null || true

if command -v docker >/dev/null 2>&1; then
  docker compose -f docker-compose.load.yml exec -T app \
    php vendor/bin/testbench vaulter:relay-outbox --max=1 >/dev/null 2>&1 || true
fi

METRICS="$(curl -fsS "${APP_URL}/metrics?run_id=${RUN_ID}")"
printf '%s\n' "$METRICS" | python3 - <<'PY'
import re, sys
text = sys.stdin.read()
families = set(re.findall(r'^# TYPE (vaulter_[a-z0-9_]+) ', text, re.M))
expected = 50
print(f"scraped_families={len(families)} expected={expected}")
if len(families) < expected:
    raise SystemExit(1)
PY

curl -fsS -X POST "${APP_URL}/metrics/run/finalize" \
  -H 'Content-Type: application/json' \
  -d "{\"run_id\":\"${RUN_ID}\"}" >/dev/null

echo "p8-metric-matrix: PASS (50 families visible on scrape)"
