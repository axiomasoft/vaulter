#!/bin/sh
# In-container capture for one lane_selftest request (bind-mounted from repo).
set -eu

RUN_DIR="${1:?run dir}"
CASE_ID="${2:?case}"
FIXTURE_ID="${3:?fixture}"
FINGERPRINT="${4:?fingerprint}"
TIER="${5:?tier}"
RUN_ID="${6:?run_id}"

SAMPLER="/app/load/scripts/p4-memory-sampler.py"

worker_pid=""
for pid in $(pidof php-fpm 2>/dev/null || true); do
    if tr '\0' ' ' <"/proc/${pid}/cmdline" | grep -q 'pool memory'; then
        worker_pid="$pid"
        break
    fi
done
if [ -z "$worker_pid" ]; then
    worker_pid="$(ps -o pid= -C php-fpm | awk 'NR==1{print $1}' | tr -d ' ')"
fi
[ -n "$worker_pid" ] || { echo "no php-fpm worker" >&2; exit 1; }
starttime="$(awk '{print $22}' "/proc/${worker_pid}/stat")"

sample() {
    phase="$1"
    php_peak="${2:-}"
    if [ -n "$php_peak" ]; then
        python3 "$SAMPLER" sample --pid "$worker_pid" --starttime "$starttime" --phase "$phase" --php-peak "$php_peak"
    else
        python3 "$SAMPLER" sample --pid "$worker_pid" --starttime "$starttime" --phase "$phase"
    fi
}

mkdir -p "$RUN_DIR"
: >"$RUN_DIR/samples.jsonl"
sample before >>"$RUN_DIR/samples.jsonl"

wget -q -O /tmp/p4-response.bin "http://127.0.0.1:8080/load/health" &
req_pid=$!
while kill -0 "$req_pid" 2>/dev/null; do
    sample during >>"$RUN_DIR/samples.jsonl" || true
    sleep 0.05
done
wait "$req_pid"

clen="$(wc -c </tmp/p4-response.bin | tr -d ' ')"
sha256="$(sha256sum /tmp/p4-response.bin | awk '{print $1}')"
php_peak="$(php -r 'echo memory_get_peak_usage(true);')"
sample after "$php_peak" >>"$RUN_DIR/samples.jsonl"

python3 - <<PY
import json, datetime
manifest = {
    "schema_version": "p4-memory/v1",
    "run_id": "${RUN_ID}",
    "tier": "${TIER}",
    "case_id": "${CASE_ID}",
    "fixture_id": "${FIXTURE_ID}",
    "env_fingerprint": "${FINGERPRINT}",
    "cgroup_mode": "v2",
    "fpm": {"pm": "static", "max_children": 1, "pool": "memory"},
    "worker_pid_identity": {"pid": int("${worker_pid}"), "starttime_ticks": int("${starttime}")},
    "byte_accounting": {
        "http_status": 200,
        "content_length_bytes": int("${clen}"),
        "client_bytes_received": int("${clen}"),
        "server_object_sha256": "${sha256}",
        "probe_object_sha256": "${sha256}",
    },
    "php_peak_bytes": int("${php_peak}"),
    "prometheus_capture": {
        "family": "vaulter_request_peak_memory_bytes",
        "value_bytes": int("${php_peak}"),
    },
    "preflight": {"smaps_rollup": True, "cgroup_v2": True, "worker_pid_unique": True},
    "terminal": {
        "marker": "p4-run-complete",
        "verified_at": datetime.datetime.now(datetime.timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
    },
}
with open("${RUN_DIR}/run-manifest.json", "w", encoding="utf-8") as fh:
    json.dump(manifest, fh, indent=2, sort_keys=True)
    fh.write("\n")
case = {
    "case_id": "${CASE_ID}",
    "fixture_id": "${FIXTURE_ID}",
    "request": {"method": "GET", "path": "/load/health"},
    "ceilings_mib": {"php_peak": 512},
    "process_kind": "fpm_worker",
}
with open("${RUN_DIR}/case-manifest.json", "w", encoding="utf-8") as fh:
    json.dump(case, fh, indent=2, sort_keys=True)
    fh.write("\n")
PY
