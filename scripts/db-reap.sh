#!/usr/bin/env bash
# Stops the disposable database servers of scripts/db-test-env.sh whose owner is gone — no Docker, no root.
#
#   scripts/db-reap.sh [--dry-run] [--legacy]   reap every vaulter-dbtest.* directory of the current user whose owner
#                                               process is dead: stop its server, then delete the directory
#   scripts/db-reap.sh --stop <dir>             stop the server of <dir> and delete it (what db_down does)
#   scripts/db-reap.sh --watch <dir>            watchdog started by db_up under setsid: reaps <dir> as soon as its owner
#                                               dies (kill -9, a timeout), exits once <dir> is gone
#
# Every directory db_up makes holds an `owner` file: "<pid> <start time>" of the shell that called db_up (the start
# time from /proc/<pid>/stat tells a recycled pid from the owner), or "persistent" for a server meant to outlive its
# run (scripts/pg-local.sh), which is never reaped. A directory without an owner file comes from an older harness: it is
# reported, and reaped only with --legacy. Only directories named vaulter-dbtest.* under /tmp, /dev/shm or
# VAULTER_DB_ROOT and owned by the current user are considered, and only mysqld/mariadbd/postgres processes of the
# current user whose command line names that directory are signalled — a live run of another shell is never touched.

set -uo pipefail

_reap_roots() {
    local root
    for root in /tmp /dev/shm ${VAULTER_DB_ROOT:+"$VAULTER_DB_ROOT"}; do
        [[ -d "$root" ]] && printf '%s\n' "$root"
    done | sort -u
}

# The start time of a process (field 22 of /proc/<pid>/stat, after the parenthesised command name); empty when gone.
_reap_start_time() {
    local stat
    stat="$(cat "/proc/$1/stat" 2>/dev/null)" || return 0
    stat="${stat##*) }"
    # shellcheck disable=SC2086
    set -- $stat
    printf '%s' "${20:-}"
}

# "alive", "dead", "persistent" or "unknown" (no owner file).
_reap_owner_state() {
    local dir="$1" owner pid start
    [[ -f "$dir/owner" ]] || { echo unknown; return; }
    owner="$(head -1 "$dir/owner" 2>/dev/null)"
    [[ "$owner" == persistent ]] && { echo persistent; return; }
    read -r pid start <<<"$owner"
    if [[ "$pid" =~ ^[0-9]+$ ]] && kill -0 "$pid" 2>/dev/null && [[ "$(_reap_start_time "$pid")" == "${start:-}" ]]; then
        echo alive
    else
        echo dead
    fi
}

_reap_valid_dir() {
    [[ -n "$1" && "$1" == */vaulter-dbtest.* && "$1" != *..* && -d "$1" && -O "$1" ]]
}

# The server processes of <dir>: the pid files first, then any mysqld/mariadbd/postgres of this user naming the
# directory on its command line (a server that died before writing its pid file, a postmaster's children).
_reap_server_pids() {
    local dir="$1" file pid comm
    {
        for file in "$dir/my.pid" "$dir/data/postmaster.pid"; do
            [[ -s "$file" ]] && head -1 "$file"
        done
        pgrep -u "$(id -u)" -f -- "$(printf '%s' "$dir" | sed 's/[][\.*^$+?(){}|/]/\\&/g')/" 2>/dev/null
    } | sort -un | while read -r pid; do
        [[ "$pid" =~ ^[0-9]+$ && "$pid" != "$$" ]] || continue
        comm="$(cat "/proc/$pid/comm" 2>/dev/null)" || continue
        case "$comm" in mysqld | mariadbd | postgres) ;; *) continue ;; esac
        # A stale pid file may name a recycled pid: the process must still name this directory.
        tr '\0' ' ' <"/proc/$pid/cmdline" 2>/dev/null | grep -qF -- "$dir/" && echo "$pid"
    done
}

# Stop the servers of <dir> (TERM, up to 10 s, then KILL) and delete the directory — only once they are gone: a
# server without its data directory spins and floods its log.
reap_stop() {
    local dir="$1" pids pid i
    _reap_valid_dir "$dir" || return 0
    mapfile -t pids < <(_reap_server_pids "$dir")
    if ((${#pids[@]} > 0)); then
        # mysqld shuts down on TERM; a postmaster on INT (fast shutdown — TERM would wait for its clients).
        for pid in "${pids[@]}"; do
            if [[ "$(cat "/proc/$pid/comm" 2>/dev/null)" == postgres ]]; then kill -INT "$pid" 2>/dev/null; else kill -TERM "$pid" 2>/dev/null; fi
        done
        for ((i = 0; i < 100; i++)); do
            mapfile -t pids < <(_reap_server_pids "$dir")
            ((${#pids[@]} == 0)) && break
            sleep 0.1
        done
        ((${#pids[@]} > 0)) && kill -KILL "${pids[@]}" 2>/dev/null
        for ((i = 0; i < 50; i++)); do
            mapfile -t pids < <(_reap_server_pids "$dir")
            ((${#pids[@]} == 0)) && break
            sleep 0.1
        done
        if ((${#pids[@]} > 0)); then
            echo "db-reap: $dir: server ${pids[*]} survived KILL; the directory is kept" >&2
            return 1
        fi
    fi
    rm -rf -- "$dir"
}

reap_watch() {
    local dir="$1"
    _reap_valid_dir "$dir" || return 0
    while [[ -d "$dir" ]]; do
        case "$(_reap_owner_state "$dir")" in
            dead) reap_stop "$dir"; return ;;
            alive) ;;
            *) return 0 ;;
        esac
        sleep "${DB_REAP_INTERVAL:-2}"
    done
}

reap_all() {
    local dry="$1" legacy="$2" quiet="$3" root dir state
    while read -r root; do
        for dir in "$root"/vaulter-dbtest.*; do
            _reap_valid_dir "$dir" || continue
            state="$(_reap_owner_state "$dir")"
            case "$state" in
                alive | persistent) continue ;;
                unknown) [[ "$legacy" == 1 ]] || { ((quiet)) || echo "db-reap: $dir: no owner file (older harness); rerun with --legacy to reap it"; continue; } ;;
            esac
            if [[ "$dry" == 1 ]]; then
                echo "db-reap: would reap $dir ($state owner; server: $(_reap_server_pids "$dir" | tr '\n' ' '))"
            else
                ((quiet)) || echo "db-reap: reaping $dir ($state owner)"
                reap_stop "$dir"
            fi
        done
    done < <(_reap_roots)
}

case "${1:-}" in
    --stop) reap_stop "${2:-}" ;;
    --watch) reap_watch "${2:-}" ;;
    *)
        dry=0 legacy=0 quiet=0
        for argument in "$@"; do
            case "$argument" in
                --dry-run) dry=1 ;;
                --legacy) legacy=1 ;;
                --quiet) quiet=1 ;;
                *) echo 'usage: scripts/db-reap.sh [--dry-run] [--legacy] [--quiet] | --stop <dir> | --watch <dir>' >&2; exit 2 ;;
            esac
        done
        reap_all "$dry" "$legacy" "$quiet"
        ;;
esac
