#!/usr/bin/env bash
# Run Pest against a private, fast database with one database per worker.
#
#   scripts/test-db.sh [-C <dir>] <sqlite|pgsql|mysql> [pest arguments...]
#
#   scripts/test-db.sh pgsql packages/core/tests/Schema            # from the repo root
#   scripts/test-db.sh -C packages/drive mysql tests/Drive          # from a package
#   scripts/test-db.sh --provision pgsql --workers 4 -- <command...>
#
# The second form only PROVISIONS: it prepares the engine (private server, or the external one with DB_EXTERNAL=1),
# exports DB_*, runs <command> and tears down. scripts/t uses it so that one server serves every schema partition;
# the runner owns what is executed (partitions, passes, groups). The first form is the standalone convenience
# wrapper: it still adds one trailing `--group=serial` pass when workers > 1, unless the arguments already carry a
# --group/--exclude-group of their own or DB_SERIAL_PASS=0 — then the caller decides, and serial never runs twice.
#
# pgsql and mysql start a disposable server of their own (scripts/db-test-env.sh): fsync off, no binlog, in a
# temporary directory that is removed at the end, so a run never touches or waits for the shared local
# server and the lanes of one session cannot collide. sqlite needs no server.
#
# Environment:
#   DB_PARALLEL   workers (default: half the CPUs, at most 8); 0 or 1 runs serially in one process. Every worker
#                 of a server engine gets its own database (SuiteDatabaseConnection reads TEST_TOKEN).
#   DB_EXTERNAL   1 uses a server that is already running (CI service, DB_HOST/DB_PORT/DB_DATABASE/...): nothing is started or
#                 stopped, DB_DATABASE must end in _test, worker databases are created when missing.
#   DB_SERIAL_PASS 0 drops the automatic serial pass of the first form.
#   DB_FAST       1 (default) trades durability for speed on the private server; 0 keeps it durable.
#   TEST_LOG_DIR  where the output goes (default var/quality/test-db); follow it with `tail -f <dir>/<engine>-output.log`.
#   PEST_FLAGS    flags before your arguments (default `--ci`, the compact reporter the plan receipts use).
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cwd="$root"
if [[ "${1:-}" == -C ]]; then
    cwd="$root/${2:?usage: test-db.sh -C <dir> <sqlite|pgsql|mysql> [pest arguments...]}"
    shift 2
fi

provision_only=0
workers_arg=''
if [[ "${1:-}" == --provision ]]; then
    provision_only=1
    shift
fi

engine="${1:-}"
case "$engine" in
    sqlite | pgsql | mysql) shift ;;
    *) printf 'usage: %s [-C <dir>] <sqlite|pgsql|mysql> [pest arguments...]\n       %s --provision <engine> [--workers N] -- <command...>\n' "$0" "$0" >&2; exit 2 ;;
esac
if (( provision_only )); then
    if [[ "${1:-}" == --workers ]]; then workers_arg="${2:?--workers needs a number}"; shift 2; fi
    [[ "${1:-}" == -- ]] || { echo 'test-db.sh --provision: expected -- <command...>' >&2; exit 2; }
    shift
    (( $# > 0 )) || { echo 'test-db.sh --provision: no command' >&2; exit 2; }
fi

cpus="$(nproc 2>/dev/null || echo 4)"
default_workers=$((cpus / 2))
(( default_workers > 8 )) && default_workers=8
(( default_workers < 1 )) && default_workers=1
workers="${workers_arg:-${DB_PARALLEL:-$default_workers}}"
log_dir="${TEST_LOG_DIR:-$root/var/quality/test-db}"
mkdir -p "$log_dir"
output="$log_dir/$engine-output.log"

export APP_ENV=testing
if [[ "$engine" == sqlite ]]; then
    export DB_CONNECTION=sqlite DB_DATABASE=:memory:
else
    # shellcheck source=scripts/db-test-env.sh
    source "$root/scripts/db-test-env.sh"
    # A local server started by scripts/pg-local.sh is reused when it answers (never in CI: DB_EXTERNAL decides there).
    if [[ "$engine" == pgsql && -z "${DB_EXTERNAL:-}" && -f "$root/var/pg-local.env" ]]; then
        # shellcheck disable=SC1091
        source "$root/var/pg-local.env"
        if "$PG_LOCAL_BIN/pg_isready" -q -h 127.0.0.1 -p "$DB_PORT"; then
            export DB_EXTERNAL=1 PATH="$PG_LOCAL_BIN:$PATH"
        fi
    fi
    if [[ "${DB_EXTERNAL:-0}" == 1 ]]; then
        db_use_external "$engine" "$workers" || exit "$?"
    else
        trap db_down EXIT
        db_up "$engine" "$workers"
    fi
    (( workers > 1 )) && export VAULTER_TEST_DB_PER_PROCESS=1
fi

if (( provision_only )); then
    printf 'engine=%s workers=%s external=%s\n' "$engine" "$workers" "${DB_EXTERNAL:-0}"
    export DB_EXTERNAL=1 DB_PROVISIONED_ENGINE="$engine" DB_PROVISIONED_WORKERS="$workers"
    status=0
    "$@" || status=$?
    exit "$status"
fi

args=(php -d memory_limit=1G vendor/bin/pest)
# shellcheck disable=SC2206
args+=(${PEST_FLAGS:---ci})
php_wrapper="$root/scripts/php84"
# The package suites call vendor/bin/pest of their own package; the wrapper pins PHP 8.4.
printf 'engine=%s workers=%s cwd=%s output=%s\n' "$engine" "$workers" "${cwd#"$root"/}" "$output"

# ParaTest takes a single path, so several paths run as several parallel passes; flags go to every pass.
paths=()
flags=()
for argument in "$@"; do
    if [[ "$argument" == -* ]]; then flags+=("$argument"); else paths+=("$argument"); fi
done

started=$SECONDS
status=0
: > "$output"
run_pest() {
    local code=0
    (cd "$cwd" && "$php_wrapper" "${args[@]}" "$@") 2>&1 | tee -a "$output" || code=${PIPESTATUS[0]}
    return "$code"
}
serial_pass=1
[[ "${DB_SERIAL_PASS:-1}" == 0 ]] && serial_pass=0
for flag in "${flags[@]:-}"; do
    [[ "$flag" == --group* || "$flag" == --exclude-group* ]] && serial_pass=0
done
if (( workers > 1 )); then
    for path in "${paths[@]:-}"; do
        run_pest --parallel --processes="$workers" --exclude-group=serial "${flags[@]}" ${path:+"$path"} || status=1
    done
    # Tests in group `serial` look at process-wide state (the shared temp directory) and run alone afterwards;
    # a path without such tests is not an error.
    if (( serial_pass )); then
        serial_code=0
        run_pest --group=serial "${flags[@]}" "${paths[@]}" || serial_code=$?
        if (( serial_code != 0 )) && ! tail -n 5 "$output" | grep -q 'No tests found'; then
            (( status != 0 )) || status=$serial_code
        fi
    fi
else
    run_pest "${flags[@]}" "${paths[@]}" || status=$?
fi
printf 'engine=%s exit=%s seconds=%s\n' "$engine" "$status" "$((SECONDS - started))"
exit "$status"
