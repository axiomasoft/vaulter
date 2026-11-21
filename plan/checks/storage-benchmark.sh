#!/usr/bin/env bash
# Run the pinned research workload in a fresh, socket-only PostgreSQL cluster.
set -euo pipefail

if [[ $# != 1 || -e "$1" ]]; then
    echo "Usage: bash plan/checks/storage-benchmark.sh NEW_RESULT.json" >&2
    exit 2
fi

output=$(realpath -m -- "$1")
mkdir -p -- "$(dirname -- "$output")"
corpus=$(cd "$(dirname "$0")/storage" && pwd)
(cd "$corpus" && sha256sum --check --status SHA256SUMS)
pgbin=${PG_BIN:-$(pg_config --bindir)}
python=${PYTHON:-python3}
"$python" -c 'import psycopg'
work=$(mktemp -d)
started=0
finish() {
    if (( started )); then
        "$pgbin/pg_ctl" -D "$work/db" -m fast -w stop
    fi
    rm -rf -- "$work"
}
trap finish EXIT
cp -R "$corpus" "$work/corpus"
"$python" "$work/corpus/generate_fixtures.py" --output "$work/corpus/fixtures" > "$work/generation.json"
"$pgbin/initdb" -D "$work/db" --no-locale --encoding=UTF8 --auth-local=trust --auth-host=reject > "$work/init.log"
"$pgbin/pg_ctl" -D "$work/db" -l "$work/postgres.log" \
    -o "-k '$work' -c listen_addresses='' -c unix_socket_permissions=0700 -c track_io_timing=on" -w start
started=1
unset PGSERVICE PGOPTIONS
export BENCH_DSN="host=$work port=5432 dbname=postgres user=$(id -un)"
"$python" "$work/corpus/load.py" > "$work/load.json"
"$python" "$work/corpus/run_reads.py" --warmup 10 --iterations 50 \
    --local-backend-proc --trial-label vaulter-spec-baseline --output "$work/result.json"
"$python" - "$work/result.json" <<'PY'
import json
import sys
from pathlib import Path

result = json.loads(Path(sys.argv[1]).read_text())
cases = result["cases"]
if result["status"] != "MEASURED" or len(cases) != 8:
    raise SystemExit("Incomplete run")
for case in cases:
    if case["status"] != "MEASURED" or case["sample_count"] != 50:
        raise SystemExit(f"Incomplete case: {case['case']}")
    print(f"{case['case']:30} p95={case['p95_ms']:.3f} ms")
PY
cp -- "$work/result.json" "$output"
