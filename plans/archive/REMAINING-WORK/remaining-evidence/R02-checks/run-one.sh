#!/usr/bin/env bash
# Cold sequential Pest mutation for one R02 package/driver. No --parallel.
set -euo pipefail

ROOT=/home/vostrikov/projects/packages/vaulter
PKG="${1:?package: core|documents|drive|media|filament}"
DRIVER="${2:?driver: sqlite|pgsql}"
EVID="$ROOT/plans/remaining-evidence/R02-checks"
RUN_ID="${PKG}-${DRIVER}"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
LOG_DIR="$EVID/raw/$RUN_ID"
mkdir -p "$LOG_DIR"

case "$PKG" in
  core)
    WORKDIR="$ROOT"
    PEST="$ROOT/vendor/bin/pest"
    PATH_ARG="packages/core/src"
    CACHE_ROOT="$ROOT/vendor/pestphp/pest-plugin-mutate/.temp"
    ;;
  documents|drive|media|filament)
    WORKDIR="$ROOT/packages/$PKG"
    PEST="$WORKDIR/vendor/bin/pest"
    PATH_ARG="src"
    CACHE_ROOT="$WORKDIR/vendor/pestphp/pest-plugin-mutate/.temp"
    ;;
  *)
    echo "unknown package $PKG" >&2
    exit 2
    ;;
esac

if [[ "$DRIVER" == "sqlite" ]]; then
  export DB_CONNECTION=sqlite
  export DB_DATABASE=':memory:'
  unset DB_HOST DB_PORT DB_USERNAME DB_PASSWORD || true
else
  export DB_CONNECTION=pgsql
  export DB_HOST=127.0.0.1
  export DB_PORT=55432
  export DB_DATABASE="vaulter_r02_${PKG}_test"
  export DB_USERNAME=vaulter
  export DB_PASSWORD=secret
  if [[ ! "$DB_DATABASE" =~ ^[a-z0-9_]+_test$ ]]; then
    echo "DB_DATABASE $DB_DATABASE fails *_test gate" >&2
    exit 2
  fi
fi

export PCOV_ENABLED=1
export XDEBUG_MODE=off
export APP_ENV=testing
cd "$WORKDIR"

# Cold cache via Pest's own --clear-cache (FileStore of mutant verdicts).
# Do not shell-rm vendor/.temp: the environment blocks rm on vendor paths.

{
  echo "run_id=$RUN_ID"
  echo "stamp=$STAMP"
  echo "workdir=$WORKDIR"
  echo "pwd=$(pwd)"
  echo "driver=$DRIVER"
  echo "db_connection=$DB_CONNECTION"
  echo "db_database=${DB_DATABASE:-}"
  echo "db_host=${DB_HOST:-}"
  echo "db_port=${DB_PORT:-}"
  echo "php=$(/usr/bin/php8.4 -v | head -1)"
  echo "pcov=$(/usr/bin/php8.4 -m | grep -i pcov || true)"
  echo "command=script -qefc php8.4 pest --mutate --path=$PATH_ARG --covered-only --clear-cache"
} | tee "$LOG_DIR/meta.txt"

# No --parallel. No --min: complete the denominator even if below a CI floor.
# script(1) allocates a TTY so Pest/Collision use the human printer, not PAO JSON.
unset PAO_COMPACT COLLISIONS_PRINTER_COMPACT || true
set +e
/usr/bin/time -v -o "$LOG_DIR/time.txt" \
  script -qefc "/usr/bin/php8.4 -d xdebug.mode=off \"$PEST\" --mutate --path=\"$PATH_ARG\" --covered-only --clear-cache" \
  "$LOG_DIR/pest.log"
EXIT=$?
set -e
echo "$EXIT" > "$LOG_DIR/exit_code.txt"

# Preserve cache for union merge, then leave it in place until the next run-one.sh clears it.
if [[ -d "$CACHE_ROOT/pest-mutate-cache" ]]; then
  mkdir -p "$LOG_DIR/pest-mutate-cache"
  cp -a "$CACHE_ROOT/pest-mutate-cache/." "$LOG_DIR/pest-mutate-cache/" || true
  /usr/bin/php8.4 "$EVID/dump-cache.php" "$LOG_DIR/pest-mutate-cache" >"$LOG_DIR/cache-results.json" || true
fi

python3 "$EVID/parse-log.py" "$LOG_DIR" "$PKG" "$DRIVER" "$EXIT" "$STAMP"
echo "finished $RUN_ID exit=$EXIT"
exit 0
