#!/bin/sh
# Snapshot pg-data + minio-data so a repeat prog run starts from the
# SAME seeded state without re-paying seed cost (Deliverables — "снапшот/
# восстановление... повторный прогон не переплачивает seed'ом").
# Usage: TIER=S0 ./load/scripts/snapshot.sh
#
# Stops postgres/minio first: tarring a LIVE Postgres data dir risks an
# inconsistent snapshot (no pg_start_backup dance here — this is bench tooling, not
# a prod backup path). Restarts both afterwards, same tier. Output lands in
# load/ (git-ignored: `load/*.tar.gz`, see .gitignore).
set -eu

cd "$(dirname "$0")/.."

TIER="${TIER:-S0}"
PROJECT="vaulter-load" # docker-compose.load.yml `name:` — volume prefix.
OUT_DIR="${SNAPSHOT_DIR:-.}"

docker compose -f docker-compose.load.yml --env-file "env/${TIER}.env" stop postgres minio

mkdir -p "$OUT_DIR"

for VOL in pg-data minio-data; do
    docker run --rm \
        -v "${PROJECT}_${VOL}:/data:ro" \
        -v "$(cd "$OUT_DIR" && pwd)":/backup \
        alpine sh -c "tar czf /backup/${TIER}.${VOL}.tar.gz -C /data ."
done

docker compose -f docker-compose.load.yml --env-file "env/${TIER}.env" start postgres minio

echo "Snapshot written: ${OUT_DIR}/${TIER}.pg-data.tar.gz, ${OUT_DIR}/${TIER}.minio-data.tar.gz"
