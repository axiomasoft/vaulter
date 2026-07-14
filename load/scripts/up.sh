#!/bin/sh
# VLT-LOAD — bring the stand up for a tier. Usage: TIER=S0 ./load/scripts/up.sh
# Override the host port if 8080 is taken: LOAD_APP_PORT=8180 TIER=S0 ./load/scripts/up.sh
set -eu

cd "$(dirname "$0")/.."

TIER="${TIER:-S0}"
ENV_FILE="env/${TIER}.env"

if [ ! -f "$ENV_FILE" ]; then
    echo "Unknown TIER=${TIER} — expected one of: $(ls env | sed 's/\.env$//' | tr '\n' ' ')" >&2
    exit 1
fi

docker compose -f docker-compose.load.yml --env-file "$ENV_FILE" up -d --build \
    postgres pgbouncer minio minio-init prometheus app

echo "Stand up (tier ${TIER}). SUT: http://127.0.0.1:${LOAD_APP_PORT:-8080}/load/health"
echo "k6/gate services (P2.4+) start on demand: docker compose -f load/docker-compose.load.yml --profile bench run --rm k6 ..."
