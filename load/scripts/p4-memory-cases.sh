#!/usr/bin/env bash
# P4.2 — memory path matrix helpers (fixtures manifest + contract checks).
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
LOAD_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
REPO_ROOT="$(cd "$LOAD_ROOT/.." && pwd)"
TIER="${TIER:-S0}"
ENV_FILE="$LOAD_ROOT/env/${TIER}.env"

usage() {
    echo "usage: $0 list|manifest|contract-check|static-scan|prepare" >&2
    exit 1
}

list_cells() {
    node --input-type=module -e '
import { memoryMatrixCells } from "./load/k6/lib/memory-cases.js";
for (const cell of memoryMatrixCells()) {
  console.log(`${cell.cell_id}\t${cell.size_bytes}\t${cell.ceiling_mib}\t${cell.dry_run ? "dry-run" : "live"}`);
}
'
}

emit_manifest() {
    node --input-type=module -e '
import { memoryMatrixCells } from "./load/k6/lib/memory-cases.js";
console.log(JSON.stringify({ schema_version: "p4-memory-matrix/v1", cells: memoryMatrixCells() }, null, 2));
'
}

static_scan() {
    local failed=0
    local patterns='file_get_contents|stream_get_contents|Storage::get'
    while IFS= read -r file; do
        if grep -Eq "$patterns" "$file"; then
            echo "forbidden helper in $file" >&2
            failed=1
        fi
    done < <(find "$LOAD_ROOT/app/Console" -maxdepth 1 -name 'PrepareMemoryFixturesCommand.php' -o -name 'ProbeCasStageMemoryCommand.php' -o -name 'MemoryFixtureSupport.php')
    return "$failed"
}

contract_check() {
    cd "$REPO_ROOT"
    local count
    count="$(node --input-type=module -e 'import { contractCells } from "./load/k6/lib/memory-cases.js"; console.log(contractCells().length);')"
    if [ "$count" != "5" ]; then
        echo "contract cells expected 5 paths at 5MiB, got ${count}" >&2
        return 1
    fi
    k6 inspect "$LOAD_ROOT/k6/scenarios/memory-paths.js" >/dev/null
    python3 -m unittest load.gates.test_p4_memory load.gates.test_p4_memory_matrix -q
    vendor/bin/pest load/tests/Feature/MemoryFixtureCommandsTest.php
    static_scan
    echo "contract-check: ok (${count} live cells at 5MiB)"
}

prepare_fixture() {
    local fixture_id="${1:-p4-contract-5m}"
    local run_prefix="${2:-p4-contract}"
    local size="${3:-5MiB}"
    if [ ! -f "$ENV_FILE" ]; then
        echo "prepare requires stand env (${ENV_FILE}) — run inside app container for --with-api-state" >&2
        return 1
    fi
    docker compose -f "$LOAD_ROOT/docker-compose.load.yml" -f "$LOAD_ROOT/docker-compose.memory.yml" \
        --env-file "$ENV_FILE" exec -T app sh -c \
        "vendor/bin/testbench load:memory-fixtures prepare --fixture-id=${fixture_id} --run-prefix=${run_prefix} --size=${size} --with-api-state"
}

COMMAND="${1:-}"
shift || true

case "$COMMAND" in
    list)
        cd "$REPO_ROOT"
        cells="$(list_cells | wc -l | tr -d ' ')"
        if [ "$cells" != "15" ]; then
            echo "expected 15 cells, got ${cells}" >&2
            exit 1
        fi
        list_cells
        ;;
    manifest)
        cd "$REPO_ROOT"
        emit_manifest
        ;;
    static-scan)
        static_scan
        ;;
    contract-check)
        contract_check
        ;;
    prepare)
        prepare_fixture "${1:-}" "${2:-}" "${3:-}"
        ;;
    *)
        usage
        ;;
esac
