#!/bin/sh
# Tear the stand down. Usage: TIER=S0 ./load/scripts/down.sh
# Adds -v (drops volumes: pg-data/minio-data/prom-data) unless KEEP_VOLUMES=1.
set -eu

cd "$(dirname "$0")/.."

TIER="${TIER:-S0}"
ENV_FILE="env/${TIER}.env"

if [ "${KEEP_VOLUMES:-0}" = "1" ]; then
    docker compose -f docker-compose.load.yml --env-file "$ENV_FILE" down
else
    docker compose -f docker-compose.load.yml --env-file "$ENV_FILE" down -v
fi
