#!/bin/sh
# P8.11 nonempty host tracing proof.
# Usage: TIER=S ./load/scripts/p8-trace-proof.sh --manifest plans/VLT-LOAD/artifacts/P8/tracing-targets.json
set -eu

ROOT="$(CDPATH= cd -- "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"

TIER="${TIER:-}"
MANIFEST=""

while [ $# -gt 0 ]; do
    case "$1" in
        --manifest)
            MANIFEST="$2"
            shift 2
            ;;
        *)
            echo "unknown argument: $1" >&2
            exit 2
            ;;
    esac
done

if [ "$TIER" != "S" ]; then
    echo "TIER=S is required" >&2
    exit 2
fi

if [ -z "$MANIFEST" ] || [ ! -f "$MANIFEST" ]; then
    echo "manifest is required" >&2
    exit 2
fi

vendor/bin/pest \
    load/tests/Observability/OpenTelemetryTraceRecorderTest.php \
    load/tests/Observability/TraceFlushLifecycleTest.php \
    packages/core/tests/Observability/Tracing/TraceRecorderBindingTest.php \
    packages/core/tests/Observability/Tracing/WebhookPipelineTracingTest.php
(
    cd packages/drive
    vendor/bin/pest tests/Observability/Tracing/UploadSweepTracingTest.php
)

WORKDIR="$(mktemp -d)"
trap 'rm -rf "$WORKDIR"' EXIT

sample() {
    ratio="$1"
    out="$2"
    RATIO="$ratio" php -r '
        require "vendor/autoload.php";
        $ratio = (float) getenv("RATIO");
        echo json_encode(
            Vaulter\Load\Observability\OpenTelemetryTraceRecorder::sampleWorkload(ratio: $ratio),
            JSON_THROW_ON_ERROR
        );
    ' > "$out"
}

sample 0 "$WORKDIR/disabled.json"
sample 0.01 "$WORKDIR/sampled.json"
sample 1 "$WORKDIR/full.json"

OTEL_API="$(python3 - <<'PY'
import json
from pathlib import Path
packages = json.loads(Path("vendor/composer/installed.json").read_text())["packages"]
versions = {row["name"]: row["version"] for row in packages}
print(versions["open-telemetry/api"])
print(versions["open-telemetry/sdk"])
PY
)"
OTEL_API_VERSION="$(printf '%s\n' "$OTEL_API" | sed -n '1p')"
OTEL_SDK_VERSION="$(printf '%s\n' "$OTEL_API" | sed -n '2p')"
PHP_VERSION="$(php -r 'echo PHP_VERSION;')"

python3 load/gates/p8_trace_proof.py record \
    --manifest "$MANIFEST" \
    --disabled "$WORKDIR/disabled.json" \
    --sampled "$WORKDIR/sampled.json" \
    --full "$WORKDIR/full.json" \
    --otel-api "$OTEL_API_VERSION" \
    --otel-sdk "$OTEL_SDK_VERSION" \
    --php "$PHP_VERSION"

FIRST="$(python3 load/gates/p8_trace_proof.py verify --manifest "$MANIFEST")"
SECOND="$(python3 load/gates/p8_trace_proof.py verify --manifest "$MANIFEST")"

if [ "$FIRST" != "$SECOND" ]; then
    echo "verify digest changed: $FIRST != $SECOND" >&2
    exit 1
fi

echo "verify $FIRST"
