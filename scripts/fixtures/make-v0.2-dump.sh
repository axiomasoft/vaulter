#!/usr/bin/env bash
# Regenerate (or --verify) the v0.2.0 data fixture dumps (PLAN2 P0.3).
#
#   scripts/fixtures/make-v0.2-dump.sh            # write tests/fixtures/v0.2.0/*.sql + SHA256SUMS
#   scripts/fixtures/make-v0.2-dump.sh --verify   # regenerate into a temp dir, compare, load, count
#
# PostgreSQL only: v0.2.0 cannot be installed on MySQL 8 (stored generated column over
# a CASCADE foreign key → error 1215), so no 0.2 data exists there (PLAN2 D09).
# FIXTURE_CONFIGS limits the configurations (space-separated); default: all three.
# Every configuration recreates the public schema of the *_test database first.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$root"

verify=false
[[ "${1:-}" == --verify ]] && verify=true

worktree="$root/build/fixtures/v0.2.0"
fixtures="$root/tests/fixtures/v0.2.0"
configs="${FIXTURE_CONFIGS:-pgsql-bigint pgsql-ulid pgsql-bigint-preflight-red}"

export DB_HOST="${DB_HOST:-127.0.0.1}"
export DB_PORT="${DB_PORT:-5432}"
export DB_DATABASE="${DB_DATABASE:-vaulter_test}"
export DB_USERNAME="${DB_USERNAME:-vaulter}"
export DB_PASSWORD="${DB_PASSWORD:-secret}"
[[ "$DB_DATABASE" == *_test ]] || { echo "make-v0.2-dump: database must end with _test: $DB_DATABASE" >&2; exit 1; }
export PGPASSWORD="$DB_PASSWORD"

pg() { psql -X -q -v ON_ERROR_STOP=1 -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USERNAME" -d "$DB_DATABASE" "$@"; }

reset_schema() {
    pg -c 'DROP SCHEMA public CASCADE; CREATE SCHEMA public' 2>/dev/null
}

# pg_dump of the SERVER's major (Debian pg_wrapper otherwise picks the newest client,
# whose dump carries settings an older server rejects); PG_DUMP overrides.
pg_dump_bin() {
    local major
    major="$(pg -Atc 'show server_version_num')"
    major="${major:0:2}"
    if [[ -n "${PG_DUMP:-}" ]]; then echo "$PG_DUMP"
    elif [[ -x "/usr/lib/postgresql/$major/bin/pg_dump" ]]; then echo "/usr/lib/postgresql/$major/bin/pg_dump"
    else echo pg_dump
    fi
}

dump() {
    "$(pg_dump_bin)" -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USERNAME" -d "$DB_DATABASE" \
        --no-owner --no-privileges --no-comments --inserts --rows-per-insert=100 \
        --quote-all-identifiers --restrict-key=vaulterfixturev020
}

# `<file>: <table>=<rows> … owners=<sorted owner_id set>` for the schema loaded now.
counts() {
    local line table
    line="$1:"
    for table in $(pg -Atc "select tablename from pg_tables where schemaname = 'public' order by 1"); do
        line+=" $table=$(pg -Atc "select count(*) from \"$table\"")"
    done
    echo "$line owners=$(pg -Atc "select string_agg(owner_id::text, ',' order by owner_id::text) from v_storages")"
}

generate() {
    local out="$1" config strategy variant
    if [[ ! -f "$worktree/vendor/autoload.php" ]]; then
        git worktree add --force "$worktree" v0.2.0 >/dev/null
        "$root/scripts/php84" composer --working-dir="$worktree" install --no-interaction --quiet
    fi
    "$root/scripts/db-preflight.sh" pgsql >/dev/null
    for config in $configs; do
        strategy="$(cut -d- -f2 <<<"$config")"
        variant=main
        [[ "$config" == *-preflight-red ]] && variant=preflight-red
        reset_schema
        FIXTURE_WORKTREE="$worktree" "$root/scripts/php84" php "$root/scripts/fixtures/make-v0.2-dump.php" "$strategy" "$variant"
        dump >"$out/$config.sql"
        echo "generated $config.sql" >&2
    done
    (cd "$out" && sha256sum -- $(for c in $configs; do echo "$c.sql"; done)) >"$out/SHA256SUMS"
}

if [[ "$verify" == false ]]; then
    mkdir -p "$fixtures"
    generate "$fixtures"
    exit 0
fi

tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT
generate "$tmp"
status=0

if ! diff -u <(grep -F -f <(for c in $configs; do echo " $c.sql"; done) "$fixtures/SHA256SUMS") "$tmp/SHA256SUMS"; then
    echo "make-v0.2-dump --verify: regenerated dumps differ from SHA256SUMS" >&2
    status=1
fi

for config in $configs; do
    reset_schema
    pg -f "$fixtures/$config.sql" >/dev/null
    line="$(counts "$config.sql")"
    expected="$(grep -F "$config.sql:" "$fixtures/README.md" | head -1 || true)"
    case "$config" in
        *-preflight-red) owners_ok=true ;;
        *-bigint) [[ "$line" == *" owners=1,2,3" ]] && owners_ok=true || owners_ok=false ;;
        *-ulid) [[ "$line" == *" owners=01ARZ3NDEKTSV4RRFFQ69G5FAV,01ARZ3NDEKTSV4RRFFQ69G5FAW,01ARZ3NDEKTSV4RRFFQ69G5FAX" ]] && owners_ok=true || owners_ok=false ;;
    esac
    if [[ "$line" == "$expected" && "$owners_ok" == true ]]; then
        echo "$line OK"
    else
        echo "$line MISMATCH (README: ${expected:-none})"
        status=1
    fi
done

exit "$status"
