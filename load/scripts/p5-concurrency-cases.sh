#!/bin/sh
# Fixture/case matrix helpers. Does not seed the 1m dirty profile.
# Usage:
#   ./load/scripts/p5-concurrency-cases.sh list
#   ./load/scripts/p5-concurrency-cases.sh static-validate
#   ./load/scripts/p5-concurrency-cases.sh inspect
#   ./load/scripts/p5-concurrency-cases.sh contract
set -eu

SCRIPT_DIR=$(CDPATH= cd "$(dirname "$0")" && pwd)
LOAD_ROOT=$(CDPATH= cd "$SCRIPT_DIR/.." && pwd)
REPO_ROOT=$(CDPATH= cd "$LOAD_ROOT/.." && pwd)
SCENARIO=load/k6/scenarios/concurrency.js

usage() {
    echo "usage: $0 list|static-validate|inspect|contract" >&2
    exit 1
}

run_k6() {
    if command -v k6 >/dev/null 2>&1; then
        command k6 "$@"
        return
    fi
    docker run --rm -v "$REPO_ROOT":/work -w /work grafana/k6:0.55.0 "$@"
}

COMMAND=${1:-}
[ -n "$COMMAND" ] || usage

case "$COMMAND" in
    list)
        cd "$REPO_ROOT"
        node --input-type=module -e '
import { catalog, contractCells } from "./load/k6/lib/concurrency-cases.js";
const cells = catalog();
const contract = contractCells();
if (contract.some((cell) => cell.shape === "dirty-1m" || cell.n !== 1)) {
  console.error("contract set must be one n=1 cell per surface and must omit dirty-1m");
  process.exit(1);
}
console.log(`cells=${cells.length}`);
console.log(`contract=${contract.length}`);
for (const cell of contract) {
  console.log(cell.cell_id);
}
'
        ;;
    static-validate)
        cd "$REPO_ROOT"
        python3 -m unittest load/gates/test_p5_cases.py
        ;;
    inspect)
        cd "$REPO_ROOT"
        run_k6 inspect "$SCENARIO"
        ;;
    contract)
        cd "$REPO_ROOT"
        run_k6 run -e P5_CONTRACT=1 "$SCENARIO"
        ;;
    *)
        usage
        ;;
esac
