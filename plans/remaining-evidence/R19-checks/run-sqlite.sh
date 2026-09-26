#!/usr/bin/env bash

set -euo pipefail

ROOT=/home/vostrikov/projects/packages/vaulter
PACKAGE="$ROOT/packages/filament"
EVIDENCE="$ROOT/plans/remaining-evidence/R19-checks"
RUN_DIR="$EVIDENCE/sqlite-cold-70ea33b"
CACHE_ROOT="$PACKAGE/vendor/pestphp/pest-plugin-mutate/.temp"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
PLUGIN="$(jq -r '.["packages-dev"][] | select(.name == "pestphp/pest-plugin-mutate") | [.version, .dist.reference] | @tsv' "$PACKAGE/composer.lock")"

if [[ -e "$RUN_DIR" ]]; then
    echo "evidence directory already exists: $RUN_DIR" >&2
    exit 2
fi

mkdir -p "$RUN_DIR"

export APP_ENV=testing
export DB_CONNECTION=sqlite
export DB_DATABASE=':memory:'
export PCOV_ENABLED=1
export XDEBUG_MODE=off
unset DB_HOST DB_PORT DB_USERNAME DB_PASSWORD PAO_COMPACT COLLISIONS_PRINTER_COMPACT || true

cd "$PACKAGE"

{
    echo "run_id=R19-filament-sqlite-cold-$STAMP"
    echo "stamp=$STAMP"
    echo "commit=$(git -C "$ROOT" rev-parse HEAD)"
    echo "workdir=$PACKAGE"
    echo "driver=sqlite"
    echo "db_database=:memory:"
    echo "php=$(/usr/bin/php8.4 -v | head -n 1)"
    echo "pcov=$(/usr/bin/php8.4 -m | grep -i '^pcov$')"
    echo "plugin=$PLUGIN"
    echo "composer_lock_sha256=$(sha256sum composer.lock | awk '{print $1}')"
    echo "source_rollup_sha256=$(find src -type f -name '*.php' -print0 | sort -z | xargs -0 sha256sum | sha256sum | awk '{print $1}')"
    echo "command=/usr/bin/php8.4 -d xdebug.mode=off vendor/bin/pest --mutate --path=src --covered-only --clear-cache --min=95"
} > "$RUN_DIR/meta.txt"

set +e
/usr/bin/time -v -o "$RUN_DIR/time.txt" \
    script -qefc "/usr/bin/php8.4 -d xdebug.mode=off vendor/bin/pest --mutate --path=src --covered-only --clear-cache --min=95" \
    "$RUN_DIR/pest.log"
EXIT=$?
set -e

echo "$EXIT" > "$RUN_DIR/exit_code.txt"

if [[ -d "$CACHE_ROOT/pest-mutate-cache" ]]; then
    mkdir -p "$RUN_DIR/pest-mutate-cache"
    cp -a "$CACHE_ROOT/pest-mutate-cache/." "$RUN_DIR/pest-mutate-cache/"
    /usr/bin/php8.4 "$ROOT/plans/remaining-evidence/R02-checks/dump-cache.php" \
        "$RUN_DIR/pest-mutate-cache" > "$RUN_DIR/cache-results.json"
fi

python3 "$ROOT/plans/remaining-evidence/R02-checks/parse-log.py" \
    "$RUN_DIR" filament sqlite "$EXIT" "$STAMP"
SUMMARY_TMP="$RUN_DIR/summary.json.tmp"
jq '.min_flag = 95' "$RUN_DIR/summary.json" > "$SUMMARY_TMP"
mv "$SUMMARY_TMP" "$RUN_DIR/summary.json"
gzip -9 -c "$RUN_DIR/pest.log" > "$RUN_DIR/pest.log.gz"
(
    cd "$RUN_DIR"
    find . -maxdepth 1 -type f ! -name artifacts.sha256 ! -name pest.log -print0 \
        | sort -z \
        | xargs -0 sha256sum > artifacts.sha256
)

exit "$EXIT"
