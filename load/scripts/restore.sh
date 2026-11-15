#!/bin/sh
# Restore pg-data + minio-data from a snapshot.sh archive pair.
# Usage: TIER=S0 ./load/scripts/restore.sh
#
# The stand must already be up (up.sh) — this stops postgres/minio, WIPES their
# volumes, untars the archive, restarts. Does not reseed: `dataset_id` after
# restore is whatever the snapshot carried (verify against its manifest —
# load/seed/out/<dataset_id>.json — if you need to confirm which dataset this is).
set -eu

cd "$(dirname "$0")/.."

TIER="${TIER:-S0}"
PROJECT="vaulter-load"
IN_DIR="${SNAPSHOT_DIR:-.}"

for VOL in pg-data minio-data; do
    ARCHIVE="${IN_DIR}/${TIER}.${VOL}.tar.gz"
    if [ ! -f "$ARCHIVE" ]; then
        echo "Missing snapshot archive: ${ARCHIVE}" >&2
        exit 1
    fi
done

docker compose -f docker-compose.load.yml --env-file "env/${TIER}.env" stop postgres minio

for VOL in pg-data minio-data; do
    docker run --rm \
        -v "${PROJECT}_${VOL}:/data" \
        -v "$(cd "$IN_DIR" && pwd)":/backup \
        alpine sh -c "find /data -mindepth 1 -delete && tar xzf /backup/${TIER}.${VOL}.tar.gz -C /data"
done

docker compose -f docker-compose.load.yml --env-file "env/${TIER}.env" start postgres minio

echo "Restored ${TIER} from ${IN_DIR}/${TIER}.{pg-data,minio-data}.tar.gz"
