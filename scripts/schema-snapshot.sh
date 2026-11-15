#!/usr/bin/env bash
# Schema snapshots of the packaged migrations on a disposable engine.
#
#   scripts/schema-snapshot.sh --driver=<sqlite|pgsql|mysql> (--all-presets | --preset=<id>)
#                              [--compare=<dir>] [--write=<dir>] [--write-original] [--rollback] [--mode=builder]
#
#   --compare=DIR     compare every preset with DIR/<driver>/<preset>.json (exit 1 on any difference)
#   --write=DIR       write DIR/<driver>/<preset>.json (the current expected mirror, updated deliberately)
#   --write-original  write packages/core/tests/Fixtures/Schema/original/ — immutable earlier truth. Refuses (exit 2)
#                     unless the product tree equals c64bba25 and is clean; records that in original/metadata.json.
#   --rollback        also roll the chain back and require an empty schema
#   --mode=builder    build from SchemaBuilder::createAll() instead of the migration chain (default: the chain)
#
# --write-original: a preset whose 0.x chain cannot migrate an empty database is captured in builder mode instead
# (the intended current DDL); original/metadata.json lists those presets and the chain's error.
#
# Every preset runs in its own PHP process on its own database; pgsql/mysql start a private server
# (scripts/db-test-env.sh, serial — DB_PARALLEL does not apply), DB_EXTERNAL=1 uses the one you provide.
# Narrow DDL equivalence only: no behavioural test runs here.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$root"
base_commit=c64bba25
original_dir=packages/core/tests/Fixtures/Schema/original

driver='' preset='' all=0 compare='' write='' write_original=0 rollback=0 mode=''
for argument in "$@"; do
    case "$argument" in
        --driver=*) driver="${argument#*=}" ;;
        --preset=*) preset="${argument#*=}" ;;
        --all-presets) all=1 ;;
        --compare=*) compare="${argument#*=}" ;;
        --write=*) write="${argument#*=}" ;;
        --write-original) write_original=1 ;;
        --rollback) rollback=1 ;;
        --mode=*) mode="${argument#*=}" ;;
        *) echo "schema-snapshot: unknown argument [$argument]" >&2; exit 2 ;;
    esac
done
case "$driver" in sqlite | pgsql | mysql) ;; *) echo 'schema-snapshot: --driver=<sqlite|pgsql|mysql> is required' >&2; exit 2 ;; esac
(( all || ${#preset} )) || { echo 'schema-snapshot: --all-presets or --preset=<id> is required' >&2; exit 2; }

paths=(packages/*/src packages/*/database packages/*/config)
if (( write_original )); then
    if ! git diff --quiet "$base_commit" -- "${paths[@]}" || [[ -n "$(git status --porcelain -- "${paths[@]}")" ]]; then
        echo "schema-snapshot: --write-original refused — the product tree is not clean and identical to $base_commit" >&2
        exit 2
    fi
    write="$original_dir"
fi

php=("$root/scripts/php84" php)
if [[ -n "$preset" ]]; then presets=("$preset"); else mapfile -t presets < <("${php[@]}" scripts/schema-snapshot.php --list-presets); fi

export APP_ENV=testing
if [[ "$driver" == sqlite ]]; then
    export DB_CONNECTION=sqlite DB_DATABASE=:memory:
else
    # shellcheck source=scripts/db-test-env.sh
    source "$root/scripts/db-test-env.sh"
    if [[ "${DB_EXTERNAL:-0}" == 1 ]]; then
        db_use_external "$driver" 0 || exit "$?"
    else
        trap db_down EXIT
        db_up "$driver" 0
    fi
fi

failed=0 index=0 base_database="${DB_DATABASE:-}"
fallbacks=()
for id in "${presets[@]}"; do
    index=$((index + 1))
    if [[ "$driver" != sqlite && "${DB_EXTERNAL:-0}" != 1 ]]; then
        db_create "snap_${index}_test"
        export DB_DATABASE="snap_${index}_test"
    else
        export DB_DATABASE="$base_database"
    fi
    args=(--driver="$driver" --preset="$id")
    [[ -z "$compare" ]] || args+=(--compare="$compare")
    [[ -z "$write" ]] || args+=(--output="$write/$driver/$id.json")
    (( ! rollback )) || args+=(--rollback)
    [[ -z "$mode" ]] || args+=(--mode="$mode")
    if ! error="$("${php[@]}" scripts/schema-snapshot.php "${args[@]}" 2>&1 >/dev/null)"; then
        if (( write_original )) && [[ -z "$mode" ]]; then
            # The chain itself fails on this preset: the immutable "before" is the schema the builder defines.
            if "${php[@]}" scripts/schema-snapshot.php "${args[@]}" --mode=builder >/dev/null 2>&1; then
                echo "schema-snapshot: $driver/$id chain failed, captured in builder mode: $(head -c 300 <<<"$error" | tr '\n' ' ')" >&2
                fallbacks+=("$id"$'\t'"$(head -c 300 <<<"$error" | tr '\n' ' ')")
                continue
            fi
        fi
        echo "$error" >&2
        echo "schema-snapshot: FAILED $driver/$id" >&2
        failed=$((failed + 1))
    fi
done

if (( write_original && failed == 0 )); then
    version="$(case "$driver" in
        sqlite) "${php[@]}" -r 'echo (new SQLite3(":memory:"))->querySingle("select sqlite_version()");' ;;
        pgsql) "$DB_PG_BIN/psql" -X -tA -h 127.0.0.1 -p "$DB_PORT" -U "$DB_USERNAME" -d postgres -c 'show server_version' 2>/dev/null || echo external ;;
        mysql) mysqld --version | sed 's/.*Ver //' ;;
    esac)"
    metadata="$original_dir/metadata.json"
    [[ -f "$metadata" ]] || echo '{}' > "$metadata"
    tree_sha="$(git ls-tree -r HEAD -- "${paths[@]}" | sha256sum | cut -d' ' -f1)"
    jq --arg base "$base_commit" --arg head "$(git rev-parse HEAD)" --arg tree "$tree_sha" --arg driver "$driver" \
        --arg version "$version" --arg php "$("${php[@]}" -r 'echo PHP_VERSION;')" --arg count "${#presets[@]}" \
        --argjson fallback "$(printf '%s\n' "${fallbacks[@]:-}" | jq -R -s 'split("\n") | map(select(length > 0) | split("\t") | {preset: .[0], chain_error: .[1]})')" \
        '.product_tree_clean_vs = $base | .captured_at_head = $head | .source_tree_sha256 = $tree
         | .setup = {php: $php, command: "scripts/schema-snapshot.sh --write-original", config: "packages/core/tests/Fixtures/Schema/snapshot-configs.json"}
         | .engines[$driver] = {version: $version, presets: ($count | tonumber)}
         | .builder_fallback[$driver] = $fallback' "$metadata" > "$metadata.tmp"
    mv "$metadata.tmp" "$metadata"
fi

printf 'schema-snapshot: driver=%s presets=%s failed=%s\n' "$driver" "${#presets[@]}" "$failed"
(( failed == 0 ))
