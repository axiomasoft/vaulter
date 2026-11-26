#!/usr/bin/env bash
# Unified runner for S4 & engineering-standards §3.8 benchmarks (storage cold/WAL, Octane+RLS+OTel, 1 GiB CAS+ClamAV).
# Usage:
#   ./load/scripts/bench-s4-slo.sh dry-run
#   ./load/scripts/bench-s4-slo.sh smoke
#   ./load/scripts/bench-s4-slo.sh full
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
LOAD_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
MODE="${1:-dry-run}"
OUT_DIR="${OUT_DIR:-$LOAD_ROOT/results/s4-slo}"

case "$MODE" in
    dry-run)
        python3 "$SCRIPT_DIR/bench-storage-cold-wal.py" --mode dry-run
        php "$SCRIPT_DIR/bench-octane-rls.php" --mode=dry-run
        php "$SCRIPT_DIR/bench-cas-clamav.php" --mode=dry-run
        ;;
    smoke)
        mkdir -p "$OUT_DIR"
        python3 "$SCRIPT_DIR/bench-storage-cold-wal.py" --mode smoke --output "$OUT_DIR/cold-wal-smoke.json"
        php "$SCRIPT_DIR/bench-octane-rls.php" --mode=smoke --output "$OUT_DIR/octane-rls-smoke.json"
        php "$SCRIPT_DIR/bench-cas-clamav.php" --mode=smoke --clamd=auto --output "$OUT_DIR/cas-clamav-smoke.json"
        ;;
    full)
        mkdir -p "$OUT_DIR"
        python3 "$SCRIPT_DIR/bench-storage-cold-wal.py" --mode full --output "$OUT_DIR/cold-wal-full.json"
        php "$SCRIPT_DIR/bench-octane-rls.php" --mode=full --output "$OUT_DIR/octane-rls-full.json"
        php "$SCRIPT_DIR/bench-cas-clamav.php" --mode=full --size-mib=1024 --clamd="${CLAMD_TARGET:-tcp://127.0.0.1:3310}" --output "$OUT_DIR/cas-clamav-1gib.json"
        ;;
    *)
        echo "usage: $0 dry-run|smoke|full" >&2
        exit 2
        ;;
esac
