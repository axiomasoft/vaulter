#!/bin/sh
# VLT-LOAD P2.4/P2.5 — run a k6 scenario (or the contract smoke) against the up stand.
# Stages the LATEST seed manifest into load/k6/dataset.json first: the k6 container
# only mounts load/k6/ (docker-compose.load.yml `k6.volumes`), not load/seed/out/, and
# the scenarios read the manifest's id-lists via SharedArray (harness.md §3/§5,
# Implementation Rules — lib/dataset.js). P2.5 stages `load/gates/thresholds.js` the
# same way, for the same reason (`load/gates/` is not mounted either).
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
cp gates/thresholds.js k6/lib/thresholds.js

# P2.5 — `env_fingerprint` (slo-gates.md §4.1): hash of the cpuset/mem_limit envelope
# (the whole env-file IS that envelope, `load/env/<tier>.env`) + the pinned image
# versions (docker-compose.load.yml) + dataset_id. Computed on the HOST (k6's sandbox
# has no crypto/hashing) and passed in — same reasoning as staging thresholds.js/
# dataset.json: whatever k6 cannot compute itself, run.sh prepares first.
DATASET_ID="$(basename "$MANIFEST" .json)"
IMAGE_VERSIONS="postgres:16-alpine minio/minio:latest php:8.4-fpm-alpine grafana/k6:0.55.0"
ENV_FINGERPRINT="$( (cat "$ENV_FILE"; printf '%s %s' "$IMAGE_VERSIONS" "$DATASET_ID") | sha256sum | cut -c1-16)"
GIT_COMMIT_SHA="$(git rev-parse --short HEAD 2>/dev/null || echo unknown)"

case "$TARGET" in
    smoke) SCRIPT=smoke.js ;;
    *) SCRIPT="$TARGET" ;;
esac

docker compose -f docker-compose.load.yml --env-file "$ENV_FILE" --profile bench run --rm \
    -e ENV_FINGERPRINT="$ENV_FINGERPRINT" -e GIT_COMMIT_SHA="$GIT_COMMIT_SHA" k6 \
    run --out experimental-prometheus-rw "/scripts/${SCRIPT}"
