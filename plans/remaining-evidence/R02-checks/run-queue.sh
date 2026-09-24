#!/usr/bin/env bash
# Sequential R02 baselines. One package/driver at a time. No --parallel.
set -euo pipefail
ROOT=/home/vostrikov/projects/packages/vaulter
EVID="$ROOT/plans/remaining-evidence/R02-checks"
cd "$ROOT"
{
  echo "queue_start $(date -u +%FT%TZ)"
  bash "$EVID/run-one.sh" filament sqlite
  bash "$EVID/run-one.sh" media sqlite
  bash "$EVID/run-one.sh" media pgsql
  bash "$EVID/run-one.sh" documents pgsql
  bash "$EVID/run-one.sh" drive pgsql
  bash "$EVID/run-one.sh" core sqlite
  bash "$EVID/run-one.sh" core pgsql
  echo "queue_complete $(date -u +%FT%TZ)"
} 2>&1 | tee -a "$EVID/queue.log"
