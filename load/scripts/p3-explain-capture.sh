#!/usr/bin/env bash
# P3.3 — actual EXPLAIN, index inventory/review, HypoPG candidates on published P3.2 corpus.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
LOAD_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
REPO_ROOT="$(cd "$LOAD_ROOT/.." && pwd)"
TIER="${TIER:-S}"
ENV_FILE="$LOAD_ROOT/env/${TIER}.env"
COMPOSE=(docker compose -f "$LOAD_ROOT/docker-compose.load.yml" --env-file "$ENV_FILE")
EXPLAIN_PY="$REPO_ROOT/load/gates/p3_explain.py"
ARTIFACT_DIR="${P3_ARTIFACT_DIR:?Set P3_ARTIFACT_DIR to the fresh capture artifact directory}"
COMPOSE_CMD="${COMPOSE[*]}"

cd "$REPO_ROOT"

python3 "$REPO_ROOT/load/gates/p3_workload.py" verify-artifacts --artifact-dir "$ARTIFACT_DIR"

"${COMPOSE[@]}" exec -T postgres pg_isready -U vaulter -d vaulter_load >/dev/null

python3 "$EXPLAIN_PY" --compose-cmd "$COMPOSE_CMD" capture --artifact-dir "$ARTIFACT_DIR"
python3 "$EXPLAIN_PY" --compose-cmd "$COMPOSE_CMD" build-index --artifact-dir "$ARTIFACT_DIR"
python3 "$EXPLAIN_PY" --compose-cmd "$COMPOSE_CMD" hypopg --artifact-dir "$ARTIFACT_DIR"
python3 "$EXPLAIN_PY" verify-artifacts --artifact-dir "$ARTIFACT_DIR"
python3 "$REPO_ROOT/load/gates/p3_trace_report.py" --artifact-dir "$ARTIFACT_DIR" --output "$ARTIFACT_DIR/trace-analysis.json"
python3 "$EXPLAIN_PY" verify-artifacts --artifact-dir "$ARTIFACT_DIR"

echo "p3-explain-capture: ok artifact_dir=$ARTIFACT_DIR"
