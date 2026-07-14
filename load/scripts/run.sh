#!/bin/sh
# VLT-LOAD P2.4/P2.5/P2.6 — run a k6 scenario (or the contract smoke, or a gated
# profile) against the up stand. Stages the LATEST seed manifest into
# load/k6/dataset.json first: the k6 container only mounts load/k6/
# (docker-compose.load.yml `k6.volumes`), not load/seed/out/, and the scenarios read
# the manifest's id-lists via SharedArray (harness.md §3/§5, Implementation Rules —
# lib/dataset.js). P2.5 stages `load/gates/thresholds.js` the same way, for the same
# reason (`load/gates/` is not mounted either).
#
# Usage:
#   TIER=S0 ./load/scripts/run.sh smoke
#   TIER=S0 ./load/scripts/run.sh scenarios/tus-upload.js
#   TIER=S0 VUS_UPLOAD=1 DURATION=10s ./load/scripts/run.sh scenarios/tree.js
#   TIER=S0 ./load/scripts/run.sh smoke-perf     # P2.6 — mixed.js + thresholds.js gate
#   TIER=S0 ./load/scripts/run.sh regression     # P2.6 — same wiring, regression profile
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

# P2.7 — `grafana/k6` runs as uid 12345, and `./results:/results` (docker-compose.load.yml)
# is a bind mount: on a fresh clone the directory does not exist, dockerd creates it as
# root:root 0755, and `handleSummary` dies with "permission denied" — no summary.json, so
# the whole gate downstream (perf-assert, baseline, this item's CV) has nothing to read.
# It never showed on the lab-box, where load/results/ already existed world-writable from
# the first hand-run. Create it before dockerd can, and make it writable for that uid.
mkdir -p results
chmod 0777 results 2>/dev/null || true

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
    smoke-perf|regression)
        # P2.6 — real thresholds.js binding, deferred from P2.5 (README "Not wired into
        # smoke.js on purpose"): a sustained `constant-vus` scenario (mixed.js) is the
        # only one with a meaningful med/min distribution for the class-A streaming
        # rows. mixed.js/summary.js/thresholds.js stay P2.4/P2.5 Files — this item does
        # not touch them, so the gate is composed here (stdin script, no new repo file,
        # same "stage what k6 cannot compute itself" reasoning as ENV_FINGERPRINT above)
        # and piped into the SAME single k6 invocation everything else in this script
        # uses (`--workdir /scripts` makes its relative imports resolve like a real
        # /scripts/*.js file would).
        LOAD_PROFILE="$TARGET"
        # 60s (vs. the 30s scenario default) — `slo-gates.md` §7's "≤ 6 мин" is the job
        # budget (checkout+seed+run+assert), not the k6 run alone; a sustained-run
        # threshold needs more than one sample per streaming metric to mean anything.
        DURATION="${DURATION:-60s}"
        ;;
    *) SCRIPT="$TARGET" ;;
esac

# P2.7 — `--no-deps` is load-bearing, not tidiness: `docker compose run` re-evaluates the
# dependency graph of `k6` (depends_on: app) and RECREATED `app` on a clean runner. Its
# entrypoint runs `migrate:fresh` on every start, so the seed (P2.3) was wiped seconds
# before k6 measured — 86 % of requests then 404'd and the gate read it as an SLO
# failure (run 29362400228). The stand is already up and healthy by `up.sh`; the k6
# container has no business touching it.
if [ -n "${LOAD_PROFILE:-}" ]; then
    docker compose -f docker-compose.load.yml --env-file "$ENV_FILE" --profile bench run --rm -T --no-deps \
        --workdir /scripts \
        -e ENV_FINGERPRINT="$ENV_FINGERPRINT" -e GIT_COMMIT_SHA="$GIT_COMMIT_SHA" \
        -e LOAD_PROFILE="$LOAD_PROFILE" -e TIER="$TIER" -e DURATION="$DURATION" k6 \
        run --out experimental-prometheus-rw - <<'JS'
import { options as baseOptions, setup, interactive, upload, download } from './scenarios/mixed.js';
import { TIER } from './lib/config.js';
import { thresholdsFor } from './lib/thresholds.js';
import { summaryOutputs } from './lib/summary.js';

// P2.6 — the wrapper `mixed.js` itself does not carry (Scope Excluded from P2.4/P2.5):
// class-A thresholds (harness.md §4 sustained-run requirement) and the `meta`-enriched
// `handleSummary` (slo-gates.md §4.1 baseline shape) both come from the profile, not
// from the scenario file.
export const options = {
    ...baseOptions,
    thresholds: thresholdsFor(__ENV.LOAD_PROFILE, TIER),
};

export function handleSummary(data) {
    return summaryOutputs(data, { profile: __ENV.LOAD_PROFILE });
}

export { setup, interactive, upload, download };
JS
else
    docker compose -f docker-compose.load.yml --env-file "$ENV_FILE" --profile bench run --rm --no-deps \
        -e ENV_FINGERPRINT="$ENV_FINGERPRINT" -e GIT_COMMIT_SHA="$GIT_COMMIT_SHA" k6 \
        run --out experimental-prometheus-rw "/scripts/${SCRIPT}"
fi
