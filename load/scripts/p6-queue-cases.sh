#!/bin/sh
# P6.2 — semantic matrix, local sink contract, post-run integrity.
# Usage:
#   ./load/scripts/p6-queue-cases.sh static-validate
#   ./load/scripts/p6-queue-cases.sh s0
#   ./load/scripts/p6-queue-cases.sh integrity --report report.json
set -eu

SCRIPT_DIR=$(CDPATH= cd "$(dirname "$0")" && pwd)
LOAD_ROOT=$(CDPATH= cd "$SCRIPT_DIR/.." && pwd)
REPO_ROOT=$(CDPATH= cd "$LOAD_ROOT/.." && pwd)
GATE=$REPO_ROOT/load/gates/p6_cases.py
SINK=$LOAD_ROOT/webhook-sink/router.php
SINK_DIR=$LOAD_ROOT/webhook-sink

usage() {
    echo "usage: $0 static-validate|s0|integrity --report FILE" >&2
    exit 1
}

COMMAND=
REPORT=

while [ $# -gt 0 ]; do
    case "$1" in
        static-validate|s0|integrity)
            COMMAND=$1
            shift
            ;;
        --report)
            REPORT=$2
            shift 2
            ;;
        *)
            usage
            ;;
    esac
done

[ -n "$COMMAND" ] || usage

case "$COMMAND" in
    static-validate)
        python3 "$GATE" static-validate
        ;;
    integrity)
        [ -n "$REPORT" ] || usage
        python3 "$GATE" integrity --report "$REPORT"
        ;;
    s0)
        tmp=$(mktemp -d "${TMPDIR:-/tmp}/p6-queue-cases.XXXXXX")
        server=
        cleanup() {
            if [ -n "$server" ]; then
                kill "$server" 2>/dev/null || true
                wait "$server" 2>/dev/null || true
            fi
            rm -rf "$tmp"
        }
        trap cleanup EXIT INT TERM
        ledger=$tmp/sink.jsonl
        port=18090
        LOAD_SINK_LEDGER=$ledger \
        LOAD_SINK_HANG_SECONDS=8 \
        LOAD_SINK_DELAY_MS=0 \
            php -S "127.0.0.1:${port}" -t "$SINK_DIR" "$SINK" >"$tmp/server.log" 2>&1 &
        server=$!
        ready=0
        i=0
        while [ "$i" -lt 50 ]; do
            if php -r 'exit(@fsockopen($argv[1], (int) $argv[2]) ? 0 : 1);' 127.0.0.1 "$port"; then
                ready=1
                break
            fi
            i=$((i + 1))
            sleep 0.05
        done
        [ "$ready" -eq 1 ] || {
            echo "p6-queue-cases s0: sink did not start" >&2
            cat "$tmp/server.log" >&2 || true
            exit 1
        }
        code_ok=$(curl -s -o /dev/null -w '%{http_code}' -X POST \
            -H 'X-Vaulter-Signature: do-not-store' \
            --data '{"payload":"discard"}' \
            "http://127.0.0.1:${port}/2xx/evt-2xx?run_id=s0&cell_id=product-realistic.relay.i20.f1.2xx")
        code_fail=$(curl -s -o /dev/null -w '%{http_code}' -X POST \
            -H 'X-Vaulter-Signature: do-not-store' \
            --data '{"payload":"discard"}' \
            "http://127.0.0.1:${port}/500/evt-500?run_id=s0&cell_id=product-realistic.relay.i20.f1.500")
        curl -s -o /dev/null -X POST --data '{}' \
            "http://127.0.0.1:${port}/2xx/dup-1?run_id=s0&cell_id=overlap-control.duplicate-job.concurrent.same-delivery"
        curl -s -o /dev/null -X POST --data '{}' \
            "http://127.0.0.1:${port}/2xx/dup-1?run_id=s0&cell_id=overlap-control.duplicate-job.concurrent.same-delivery"
        [ "$code_ok" = 204 ] || {
            echo "p6-queue-cases s0: 2xx returned ${code_ok}" >&2
            exit 1
        }
        [ "$code_fail" = 500 ] || {
            echo "p6-queue-cases s0: failure returned ${code_fail}" >&2
            exit 1
        }
        python3 "$GATE" overlap --out "$tmp/overlap.json"
        python3 "$GATE" s0-check --ledger "$ledger" --overlap "$tmp/overlap.json"
        ;;
esac
