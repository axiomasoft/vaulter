#!/bin/sh
# P4.3 — one matrix attempt: fixtures + CAS CLI or FPM path (k6 triggered from host).
set -eu

RUN_DIR="${1:?run dir}"
CELL_ID="${2:?cell_id path/size}"
FIXTURE_ID="${3:?fixture_id}"
RUN_PREFIX="${4:?run_prefix}"
FINGERPRINT="${5:?fingerprint}"
TIER="${6:?tier}"
RUN_ID="${7:?run_id}"
ATTEMPT_ID="${8:?attempt_id}"

SAMPLER="/app/load/scripts/p4-memory-sampler.py"
PATH_ID="${CELL_ID%%/*}"
SIZE_ID="${CELL_ID#*/}"
REPETITION="${P4_MATRIX_REPETITION:-1}"

case "$SIZE_ID" in
    5m) SIZE_LABEL="5MiB" ;;
    500m) SIZE_LABEL="500MiB" ;;
    5g) SIZE_LABEL="5GiB" ;;
    *)
        echo "unknown size_id ${SIZE_ID}" >&2
        exit 1
        ;;
esac

mkdir -p "$RUN_DIR"

echo "p4-matrix-capture: prepare fixture_id=${FIXTURE_ID} size=${SIZE_LABEL} cell=${CELL_ID}" >&2
vendor/bin/testbench load:memory-fixtures prepare \
    --fixture-id="${FIXTURE_ID}" \
    --run-prefix="${RUN_PREFIX}" \
    --size="${SIZE_LABEL}" \
    --matrix-path="${PATH_ID}" \
    --with-api-state
echo "p4-matrix-capture: prepare done fixture_id=${FIXTURE_ID}" >&2

vendor/bin/testbench load:memory-fixtures show \
    --fixture-id="${FIXTURE_ID}" \
    --run-prefix="${RUN_PREFIX}" >"${RUN_DIR}/fixture-manifest.json"

PROBE_JSON="$(vendor/bin/testbench load:memory-fixtures probe-object \
    --fixture-id="${FIXTURE_ID}" \
    --run-prefix="${RUN_PREFIX}")"
PROBE_SHA="$(python3 -c 'import json,sys; print(json.loads(sys.argv[1])["probe_object_sha256"])' "$PROBE_JSON")"
FIXTURE_BYTES="$(python3 -c 'import json,sys; print(json.loads(sys.argv[1])["size_bytes"])' "$PROBE_JSON")"

CEILING_MIB=64
case "$PATH_ID" in
    s3_putpart|s3_complete_postfix) CEILING_MIB=160 ;;
    download) CEILING_MIB=16 ;;
esac

finalize_manifest() {
    python3 - "$RUN_DIR" "$CELL_ID" "$FIXTURE_ID" "$FINGERPRINT" "$TIER" "$RUN_ID" "$ATTEMPT_ID" \
        "$PATH_ID" "$SIZE_ID" "$REPETITION" "$FIXTURE_BYTES" "$CEILING_MIB" <<'PY'
import json
import datetime
import sys
from pathlib import Path

run_dir, cell_id, fixture_id, fingerprint, tier, run_id, attempt_id, path_id, size_id, repetition, fixture_bytes, ceiling_mib = sys.argv[1:13]
run_dir = Path(run_dir)
byte_accounting = json.loads((run_dir / "byte_accounting.json").read_text())
worker = json.loads((run_dir / "worker_pid_identity.json").read_text())
php_peak = int((run_dir / "php_peak.txt").read_text().strip())
transfer = int((run_dir / "transfer_bytes.txt").read_text().strip())
case = json.loads((run_dir / "case-manifest.json").read_text())

manifest = {
    "schema_version": "p4-memory/v1",
    "run_id": run_id,
    "tier": tier,
    "case_id": cell_id,
    "fixture_id": fixture_id,
    "env_fingerprint": fingerprint,
    "cgroup_mode": "v2",
    "fpm": {"pm": "static", "max_children": 1, "pool": "memory"},
    "worker_pid_identity": worker,
    "byte_accounting": byte_accounting,
    "php_peak_bytes": php_peak,
    "prometheus_capture": {
        "family": "vaulter_request_peak_memory_bytes",
        "value_bytes": php_peak,
    },
    "preflight": {"smaps_rollup": True, "cgroup_v2": True, "worker_pid_unique": True},
    "terminal": {
        "marker": "p4-run-complete",
        "verified_at": datetime.datetime.now(datetime.timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
    },
    "matrix": {
        "attempt_id": attempt_id,
        "cell_id": cell_id,
        "repetition": int(repetition),
        "size_bytes": int(fixture_bytes),
        "path_id": path_id,
        "size_id": size_id,
        "transfer_bytes": transfer,
    },
}
(run_dir / "run-manifest.json").write_text(json.dumps(manifest, indent=2, sort_keys=True) + "\n", encoding="utf-8")
(run_dir / "case-manifest.json").write_text(json.dumps(case, indent=2, sort_keys=True) + "\n", encoding="utf-8")
PY
}

write_case_manifest() {
    method="$1"
    req_path="$2"
    process_kind="$3"
    python3 - <<PY
import json
case = {
    "case_id": "${CELL_ID}",
    "fixture_id": "${FIXTURE_ID}",
    "request": {"method": "${method}", "path": "${req_path}"},
    "ceilings_mib": {"php_peak": int("${CEILING_MIB}")},
    "process_kind": "${process_kind}",
}
with open("${RUN_DIR}/case-manifest.json", "w", encoding="utf-8") as fh:
    json.dump(case, fh, indent=2, sort_keys=True)
    fh.write("\n")
PY
}

if [ "$PATH_ID" = "cas_stage_probe" ]; then
    write_case_manifest POST "/cli/load:memory-probe-cas-stage" php_cli
    echo skip >"${RUN_DIR}/.k6-skip"
    : >"${RUN_DIR}/samples.jsonl"

    cli_pid="$$"
    starttime="$(awk '{print $22}' "/proc/${cli_pid}/stat")"
    python3 "$SAMPLER" sample --pid "$cli_pid" --starttime "$starttime" --phase before \
        >>"${RUN_DIR}/samples.jsonl" || true

    PROBE_OUT="$(vendor/bin/testbench load:memory-probe-cas-stage \
        --fixture-id="${FIXTURE_ID}" \
        --run-prefix="${RUN_PREFIX}" \
        --ceiling-mib="${CEILING_MIB}")"

    php_peak="$(python3 -c 'import json,sys; print(json.loads(sys.argv[1])["php_peak_bytes"])' "$PROBE_OUT")"
    staged_sha="$(python3 -c 'import json,sys; print(json.loads(sys.argv[1])["staged_sha256"])' "$PROBE_OUT")"
    probe_sha="$(python3 -c 'import json,sys; print(json.loads(sys.argv[1])["probe_object_sha256"])' "$PROBE_OUT")"

    python3 "$SAMPLER" sample --pid "$cli_pid" --starttime "$starttime" --phase during \
        --php-peak "$php_peak" >>"${RUN_DIR}/samples.jsonl" || true
    python3 "$SAMPLER" sample --pid "$cli_pid" --starttime "$starttime" --phase after \
        --php-peak "$php_peak" >>"${RUN_DIR}/samples.jsonl"

    python3 - <<PY >"${RUN_DIR}/byte_accounting.json"
import json
print(json.dumps({
    "http_status": 200,
    "content_length_bytes": int("${FIXTURE_BYTES}"),
    "client_bytes_received": int("${FIXTURE_BYTES}"),
    "server_object_sha256": "${staged_sha}",
    "probe_object_sha256": "${probe_sha}",
}))
PY
    printf '%s\n' "$php_peak" >"${RUN_DIR}/php_peak.txt"
    printf '%s\n' "$FIXTURE_BYTES" >"${RUN_DIR}/transfer_bytes.txt"
    printf '{"pid": %s, "starttime_ticks": %s}\n' "$cli_pid" "$starttime" >"${RUN_DIR}/worker_pid_identity.json"
    finalize_manifest
    exit 0
fi

worker_pid=""
for pid in $(pidof php-fpm 2>/dev/null || true); do
    if tr '\0' ' ' <"/proc/${pid}/cmdline" | grep -q 'pool memory'; then
        worker_pid="$pid"
        break
    fi
done
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

case "$PATH_ID" in
    tus_final_patch)
        write_case_manifest PATCH "/api/vaulter/resumable-uploads/{upload_id}" fpm_worker
        ;;
    s3_putpart)
        write_case_manifest POST "/api/vaulter/resumable-uploads/{upload_id}/parts" fpm_worker
        ;;
    s3_complete_postfix)
        write_case_manifest POST "/api/vaulter/resumable-uploads/{upload_id}/complete" fpm_worker
        ;;
    download)
        write_case_manifest GET "/api/vaulter/nodes/{node_id}/download" fpm_worker
        ;;
    *)
        echo "unknown path_id ${PATH_ID}" >&2
        exit 1
        ;;
esac

: >"${RUN_DIR}/samples.jsonl"
sample before >>"${RUN_DIR}/samples.jsonl"
echo "p4-matrix-capture: k6-ready cell=${CELL_ID} fixture_bytes=${FIXTURE_BYTES}" >&2
touch "${RUN_DIR}/.k6-ready"

while [ ! -f "${RUN_DIR}/.k6-done" ]; do
    sample during >>"${RUN_DIR}/samples.jsonl" || true
    sleep 0.05
done

if [ ! -f "${RUN_DIR}/k6-summary.json" ]; then
    echo "missing k6-summary.json" >&2
    exit 1
fi

K6_JSON="$(cat "${RUN_DIR}/k6-summary.json")"
python3 -c 'import json,sys; d=json.loads(sys.argv[1]); sys.exit(0 if d.get("ok") else 1)' "$K6_JSON" || {
    echo "k6 summary not ok: $K6_JSON" >&2
    exit 1
}

printf '%s\n' "$K6_JSON" | python3 -c 'import json,sys; d=json.load(sys.stdin); json.dump({
    "http_status": int(d["http_status"]),
    "content_length_bytes": int(d["content_length_bytes"]),
    "client_bytes_received": int(d["client_bytes_received"]),
    "server_object_sha256": sys.argv[1],
    "probe_object_sha256": sys.argv[1],
}, sys.stdout)' "$PROBE_SHA" >"${RUN_DIR}/byte_accounting.json"

php_peak="$(php -r 'echo memory_get_peak_usage(true);')"
sample after "$php_peak" >>"${RUN_DIR}/samples.jsonl"

printf '%s\n' "$php_peak" >"${RUN_DIR}/php_peak.txt"
python3 -c 'import json,sys; print(int(json.loads(sys.argv[1])["transfer_bytes"]))' "$K6_JSON" >"${RUN_DIR}/transfer_bytes.txt"
printf '{"pid": %s, "starttime_ticks": %s}\n' "$worker_pid" "$starttime" >"${RUN_DIR}/worker_pid_identity.json"
finalize_manifest

echo "p4-matrix-capture: cleanup api state fixture_id=${FIXTURE_ID}" >&2
vendor/bin/testbench load:memory-fixtures cleanup-api-state \
    --fixture-id="${FIXTURE_ID}" \
    --run-prefix="${RUN_PREFIX}"
