#!/bin/sh
# run a k6 scenario (or the contract smoke, or a gated
# profile) against the up stand. Stages the LATEST seed manifest into
# load/k6/dataset.json first: the k6 container only mounts load/k6/
# (docker-compose.load.yml `k6.volumes`), not load/seed/out/, and the scenarios read
# the manifest's id-lists via SharedArray (Implementation Rules —
# lib/dataset.js). `load/gates/thresholds.js` is staged the same way, for the same
# reason (`load/gates/` is not mounted either).
#
# Usage:
#   TIER=S0 ./load/scripts/run.sh smoke
#   TIER=S0 ./load/scripts/run.sh scenarios/tus-upload.js
#   TIER=S0 VUS_UPLOAD=1 DURATION=10s ./load/scripts/run.sh scenarios/tree.js
#   TIER=S0 ./load/scripts/run.sh smoke-perf     # mixed.js + thresholds.js gate
#   TIER=S0 ./load/scripts/run.sh regression     # same wiring, regression profile
set -eu

cd "$(dirname "$0")/.."

TIER="${TIER:-S0}"
ENV_FILE="env/${TIER}.env"
TARGET="${1:-smoke}"

if [ "$TARGET" = "--p8-matrix" ]; then
    exec "$(dirname "$0")/p8-metric-matrix.sh"
fi

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

# `grafana/k6` runs as uid 12345, and `./results:/results` (docker-compose.load.yml)
# is a bind mount: on a fresh clone the directory does not exist, dockerd creates it as
# root:root 0755, and `handleSummary` dies with "permission denied" — no summary.json, so
# the whole gate downstream (perf-assert, baseline, this item's CV) has nothing to read.
# It never showed on the lab-box, where load/results/ already existed world-writable from
# the first hand-run. Create it before dockerd can, and make it writable for that uid.
mkdir -p results
chmod 0777 results 2>/dev/null || true

# `env_fingerprint`: hash of the cpuset/mem_limit envelope
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
    smoke-perf|regression|lab)
        # Real thresholds.js binding (README "Not wired into
        # smoke.js on purpose"): a sustained `constant-vus` scenario (mixed.js) is the
        # only one with a meaningful med/min distribution for the class-A streaming
        # rows. mixed.js/summary.js/thresholds.js stay as they are, so the gate is composed here (stdin script, no new repo file,
        # same "stage what k6 cannot compute itself" reasoning as ENV_FINGERPRINT above)
        # and piped into the SAME single k6 invocation everything else in this script
        # uses (`--workdir /scripts` makes its relative imports resolve like a real
        # /scripts/*.js file would).
        LOAD_PROFILE="$TARGET"
        # 60s (vs. the 30s scenario default) — `slo-gates.md` §7's "≤ 6 мин" is the job
        # budget (checkout+seed+run+assert), not the k6 run alone; a sustained-run
        # threshold needs more than one sample per streaming metric to mean anything.
        # Lab needs a longer declared window for operation-specific class-B trends.
        # The summary gate still marks sparse trends unevaluated by sample count.
        if [ "$TARGET" = "lab" ]; then
            DURATION="${DURATION:-180s}"
        else
            DURATION="${DURATION:-60s}"
        fi
        ;;
    *) SCRIPT="$TARGET" ;;
esac

# R03 evidence identity includes dirty source bytes, the actual declared window,
# and a fresh run directory. Supplied lab facts must belong to this run; the helper
# replaces governor/turbo/swap/foreign-container claims with read-only observations.
RUN_ID="${RUN_ID:-$(python3 -c 'import uuid; print(uuid.uuid4())')}"
case "$RUN_ID" in *[!a-zA-Z0-9_-]*|'') echo "Invalid RUN_ID" >&2; exit 1 ;; esac
SOURCE_DIGEST="$(python3 -B gates/r03_lab.py --source-digest)"
COMMIT="$GIT_COMMIT_SHA"
DECLARED_DURATION="${DURATION:-30s}"
export RUN_ID SOURCE_DIGEST COMMIT ENV_FINGERPRINT DATASET_ID TIER DECLARED_DURATION
mkdir "results/$RUN_ID"
LAB_FACTS_JSON=""
if [ "${LOAD_PROFILE:-}" = "lab" ]; then
    if [ -z "${LAB_FACTS:-}" ]; then
        echo "Lab requires LAB_FACTS with run-bound L-1…L-7 raw evidence; see gates/r03_lab.py." >&2
        exit 2
    fi
    # Do not persist compose environment values (credentials); only resource facts.
    COMPOSE_FACTS="$(docker compose -f docker-compose.load.yml --env-file "$ENV_FILE" --profile bench config --format json)"
    printf '%s' "$COMPOSE_FACTS" | python3 -c 'import json,sys; s=json.load(sys.stdin); print(json.dumps({"name":s["name"], "services":{k:{f:v for f,v in row.items() if f in ["cpuset","cpu_quota","cpus","mem_limit","memswap_limit"]} for k,row in s["services"].items()}}))' > "results/$RUN_ID/stand-resources.json"
    COMPOSE_PROJECT="$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["name"])' "results/$RUN_ID/stand-resources.json")"
    python3 -B gates/r03_lab.py --facts "$LAB_FACTS" --project "$COMPOSE_PROJECT" --stand "results/$RUN_ID/stand-resources.json" --out "results/$RUN_ID/lab-facts.json"
    LAB_FACTS_JSON="$(cat "results/$RUN_ID/lab-facts.json")"
fi

# `--no-deps` is load-bearing, not tidiness: `docker compose run` re-evaluates the
# dependency graph of `k6` (depends_on: app) and RECREATED `app` on a clean runner. Its
# entrypoint runs `migrate:fresh` on every start, so the seed was wiped seconds
# before k6 measured — 86 % of requests then 404'd and the gate read it as an SLO
# failure (run 29362400228). The stand is already up and healthy by `up.sh`; the k6
# container has no business touching it.
if [ -n "${LOAD_PROFILE:-}" ]; then
    set +e
    docker compose -f docker-compose.load.yml --env-file "$ENV_FILE" --profile bench run --rm -T --no-deps \
        --workdir /scripts \
        -e ENV_FINGERPRINT="$ENV_FINGERPRINT" -e GIT_COMMIT_SHA="$GIT_COMMIT_SHA" \
        -e RUN_ID="$RUN_ID" -e SOURCE_DIGEST="$SOURCE_DIGEST" -e LAB_FACTS_JSON="$LAB_FACTS_JSON" \
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
    summaryTrendStats: ['avg', 'min', 'med', 'max', 'p(90)', 'p(95)', 'count'],
};

export function handleSummary(data) {
    const expectedClassBKeys = __ENV.LOAD_PROFILE === 'lab'
        ? Object.keys(thresholdsFor(__ENV.LOAD_PROFILE, TIER)).filter((key) => key.startsWith('http_req_duration{op:'))
        : [];
    return summaryOutputs(data, { profile: __ENV.LOAD_PROFILE, expectedClassBKeys });
}

export { setup, interactive, upload, download };
JS
    K6_EXIT=$?
    set -e
    # Preserve the exact k6 exit independently of evidence admission.
    printf '%s\n' "$K6_EXIT" > "results/$RUN_ID/k6-exit.txt"
    if [ -f results/summary.json ]; then
        python3 - "$RUN_ID" <<'PY_ID'
import json, shutil, sys
from pathlib import Path
source = Path('results/summary.json')
if json.loads(source.read_text()).get('meta', {}).get('run_id') != sys.argv[1]:
    raise SystemExit('summary belongs to a previous run')
shutil.copyfile(source, Path('results') / sys.argv[1] / 'summary.json')
PY_ID
    else
        echo "No summary produced for $RUN_ID" >&2
        exit 2
    fi
    SOURCE_AFTER="$(python3 -B gates/r03_lab.py --source-digest)"
    printf '%s\n' "$SOURCE_AFTER" > "results/$RUN_ID/source-after.sha256"
    if [ "$SOURCE_AFTER" != "$SOURCE_DIGEST" ]; then
        echo "Source changed during $RUN_ID; evidence rejected" >&2
        exit 2
    fi
    if [ "$LOAD_PROFILE" = "lab" ]; then
        set +e
        python3 -B gates/r03_summary.py --summary "results/$RUN_ID/summary.json" --declared-duration "$DURATION" \
            --lab-facts "results/$RUN_ID/lab-facts.json" > "results/$RUN_ID/admission.json"
        AUDIT_EXIT=$?
        set -e
        printf '%s\n' "$AUDIT_EXIT" > "results/$RUN_ID/audit-exit.txt"
        if [ "$AUDIT_EXIT" -ne 0 ]; then exit "$AUDIT_EXIT"; fi
    fi
    exit "$K6_EXIT"
else
    docker compose -f docker-compose.load.yml --env-file "$ENV_FILE" --profile bench run --rm --no-deps \
        -e ENV_FINGERPRINT="$ENV_FINGERPRINT" -e GIT_COMMIT_SHA="$GIT_COMMIT_SHA" k6 \
        run --out experimental-prometheus-rw "/scripts/${SCRIPT}"
fi
