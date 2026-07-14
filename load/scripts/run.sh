#!/bin/sh
# VLT-LOAD P2.4 — run a k6 scenario (or the contract smoke) against the up stand.
# Stages the LATEST seed manifest into load/k6/dataset.json first: the k6 container
# only mounts load/k6/ (docker-compose.load.yml `k6.volumes`), not load/seed/out/, and
# the scenarios read the manifest's id-lists via SharedArray (harness.md §3/§5,
# Implementation Rules — lib/dataset.js).
#
# Usage:
#   TIER=S0 ./load/scripts/run.sh smoke
#   TIER=S0 ./load/scripts/run.sh scenarios/tus-upload.js
#   TIER=S0 VUS_UPLOAD=1 DURATION=10s ./load/scripts/run.sh scenarios/tree.js
set -eu

cd "$(dirname "$0")/.."

TIER="${TIER:-S0}"
ENV_FILE="env/${TIER}.env"
TARGET="${1:-smoke}"

if [ ! -f "$ENV_FILE" ]; then
    echo "Unknown TIER=${TIER} — expected one of: $(ls env | sed 's/\.env$//' | tr '\n' ' ')" >&2
    exit 1
fi

MANIFEST="$(ls -t seed/out/vlt-load-*.json 2>/dev/null | head -n1 || true)"

if [ -z "$MANIFEST" ]; then
    echo "No seed manifest under load/seed/out/ — run 'load:seed' first (README.md 'Seed the volume+dirty-state envelope')." >&2
    exit 1
fi

cp "$MANIFEST" k6/dataset.json

case "$TARGET" in
    smoke) SCRIPT=smoke.js ;;
    *) SCRIPT="$TARGET" ;;
esac

docker compose -f docker-compose.load.yml --env-file "$ENV_FILE" --profile bench run --rm k6 \
    run --out experimental-prometheus-rw "/scripts/${SCRIPT}"
