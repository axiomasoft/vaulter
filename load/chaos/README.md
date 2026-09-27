# P7.2 isolated chaos topology

Use the overlay with the load stand:

```bash
TIER=S docker compose -f load/docker-compose.load.yml -f load/docker-compose.chaos.yml \
  --env-file load/env/S.env --profile chaos-ha config
```

## Proxies

- **s3-fault-proxy** (`vaulter-load-app:latest` + bind-mounted `chaos/s3-fault-proxy.py`) sits in front of
  Toxiproxy→MinIO. Control API on `:19099` (`/arm`, `/revert`, `/observe`, `/self-test`).
  `pre-upstream-503` never contacts upstream and is not a TCP toxic. The proxy logs no
  signed headers or bodies. Foreign `run_id` and non-chaos upstreams are rejected.
- **Toxiproxy** `ghcr.io/shopify/toxiproxy:2.11.0` with `chaos/toxiproxy.json`:
  MinIO `19001→minio:9000`, PgBouncer `16432→pgbouncer:6432`. Latency/timeout/reset/down
  are transport-control only — not a real S3 outage and not PostgreSQL promotion.

App/queue-worker overlay env: `MINIO_ENDPOINT=http://s3-fault-proxy:19000`,
`DB_HOST=toxiproxy`, `DB_PORT=16432`. Direct MinIO/Postgres from the SUT fails
preflight.

## Promotion lab (`--profile chaos-ha`)

Two PostgreSQL 16 nodes (`postgres-primary`, `postgres-standby`) plus
`pgbouncer-ha`. `chaos/pg-failover.sh` fences the old primary, checks standby
replay LSN, promotes, then repoints PgBouncer. Unfenced promotion is refused.
There is no automatic failback. This plane stays separate from Toxiproxy
network-loss cells.

## ENOSPC

App `TMPDIR=/var/tmp/vaulter-chaos` is a dedicated 8m tmpfs. Recovery restores
only that volume. Host `/tmp` fill is rejected.

## Injector contract

Every injector: arm (run-id bound) → observe barrier → revert (idempotent) →
self-test. Current multipart cleanup is Flysystem `tmp/uploads/<id>.parts/*`
keys, not AWS `UploadId`.
