# VLT-LOAD — load-test stand

Docker stand for the VLT-LOAD load-readiness program (`plans/VLT-LOAD/`). SUT = nginx +
php-fpm over the `orchestra/testbench-core` skeleton (**not** `vendor/bin/testbench
serve` — that's a single-threaded dev server, `pm.max_children`/`slo.md` §0.1 cannot exist
on it — see `plans/VLT-LOAD/plan.md` D28). Numbers (cpuset/memory/PG/PgBouncer/pool
raccounting) come from `plans/VLT-LOAD/reference-env.md` §1 — this stand does not invent
any of its own; see `plans/VLT-LOAD/harness.md` §2 for the mechanics.

## Up / down

```sh
TIER=S0 ./load/scripts/up.sh      # CI-profile: no cpuset, small volumes, class A only
TIER=S   ./load/scripts/up.sh     # authoritative lab tier (D25) — real cpuset pinning
TIER=M   ./load/scripts/up.sh     # class A only on this lab-box (D25 — see load/env/M.env)

TIER=S0 ./load/scripts/down.sh    # drops volumes; KEEP_VOLUMES=1 to keep them
```

If port 8080 is already taken on the host: `LOAD_APP_PORT=8180 TIER=S0 ./load/scripts/up.sh`.
**Export it for the rest of the session** (`export LOAD_APP_PORT=8180`) — every later
`docker compose` invocation touching this stand (seed, `run.sh`, a manual `exec`) must
see the SAME value, or compose recomputes the `app` service's `ports:` mapping, forces a
recreate, and `entrypoint.sh` re-runs `migrate:fresh` — silently wiping the seeded
dataset (P2.4 finding, hit live during this item's validation).

`up.sh` brings up `postgres pgbouncer minio minio-init prometheus app` — **not** `k6` (no
scripts exist yet, that's P2.4). Run k6 on demand once they do:

```sh
docker compose -f load/docker-compose.load.yml --profile bench run --rm k6 <script>
```

## Smoke-check the SUT is real (not just container-healthy)

Container `healthy` only proves `/load/health` answers — it does NOT prove a request makes
it through nginx -> php-fpm -> the package's own routes. Confirm with a real API call:

```sh
curl -fsS http://127.0.0.1:8080/load/health
# {"status":"ok"}

# Vaulter has no `POST storages` HTTP endpoint (a storage is provisioned per host entity
# via `Vaulter::for($host)`, not POSTed — operations-catalog.md carries no OP-nn for it).
# /load/storage is the stand's own seam for that step (same role as e2e's /e2e/setup):
curl -fsS -H 'X-Load-Actor: 1' http://127.0.0.1:8080/load/storage
# {"storage_id":1,"root_node_id":1,"actor_id":1}

curl -fsS -X POST -H 'X-Load-Actor: 1' -H 'Content-Type: application/json' \
  -d '{"name":"smoke"}' \
  http://127.0.0.1:8080/api/vaulter/storages/1/folders
# 201 {"data":{"id":2,"kind":"folder","name":"smoke", ...}}
```

Both `/load/storage` and the folder-create call are safe to re-run for the same
`X-Load-Actor` (idempotent — see `load/app/LoadServiceProvider.php`).

## Seed the volume+dirty-state envelope (P2.3)

```sh
docker compose -f load/docker-compose.load.yml --env-file load/env/S0.env exec app sh -c \
  'TIER=S0 vendor/bin/testbench load:seed --fresh'
```

Seeds `reference-env.md` §2's tree/blob/upload-session/outbox/webhook/grant/comment/
share/activity envelope for the tier, "dirty" state included — expired upload
sessions, orphan blobs (both GC-mark and GC-sweep scopes), a due outbox/webhook-retry
backlog, a many-grant actor (`X-Load-Actor: 900001` — `NodeReadRepository::
candidateNodeIds()`, L-06 fan-out). Idempotent by `dataset_id` (a hash of tier +
profile + the command's own version) unless `--fresh`; the manifest lands at
`load/seed/out/<dataset_id>.json` (git-ignored — a run artifact, not the stand
definition) with per-table counts and the id-lists a k6 scenario needs (many-grant
anchor node ids, the seeded storage/root, both actor ids).

Run the sustained `v_activity` writer ALONGSIDE a k6 scenario (own process, own
stopping point — never part of `load:seed`):

```sh
docker compose -f load/docker-compose.load.yml --env-file load/env/S0.env exec app sh -c \
  'TIER=S0 vendor/bin/testbench load:activity-write'   # Ctrl-C to stop, or --duration=<seconds>
```

`load/scripts/{snapshot,restore}.sh` tar/untar the `pg-data`+`minio-data` volumes so a
repeat prog run does not re-pay seed cost:

```sh
TIER=S0 ./load/scripts/snapshot.sh   # -> load/S0.pg-data.tar.gz, load/S0.minio-data.tar.gz
TIER=S0 ./load/scripts/restore.sh    # stops postgres/minio, wipes+restores, restarts
```

## k6 scenarios (P2.4)

Stock k6 only (D22) — no custom binary. `load/scripts/run.sh` stages the LATEST seed
manifest (`load/seed/out/vlt-load-*.json`, newest by mtime) into `load/k6/dataset.json`
first: the `k6` compose service only mounts `load/k6/` (not `load/seed/out/`), and every
scenario reads the manifest's id-lists off that staged copy via `k6/data.SharedArray`
(`load/k6/lib/dataset.js`).

```sh
TIER=S0 ./load/scripts/run.sh smoke                    # contract smoke — every scenario, 1 VU x 1 iteration
TIER=S0 ./load/scripts/run.sh scenarios/tus-upload.js   # a single scenario, full VUS_*/DURATION run
TIER=S0 DURATION=2m ./load/scripts/run.sh scenarios/mixed.js
```

Seed the dataset first (previous section) — a scenario against an unseeded stand has
nothing to read/write against and every check fails.

| Path | What |
|:--|:--|
| `k6/lib/config.js` | tier / base-url / per-group VU counts (`VUS_INTERACTIVE`/`VUS_UPLOAD`/`VUS_DOWNLOAD`, harness.md §4) / think-time / fixture sizes — all env-overridable |
| `k6/lib/dataset.js` | reads the staged `dataset.json` manifest (storage/root/actor ids, many-grant anchors) |
| `k6/lib/auth.js` | `X-Load-Actor` header helper |
| `k6/lib/fixtures.js` | D23 — the shared 8 MiB/64 MiB `ArrayBuffer`s (tus chunk / S3 part), the per-session salt |
| `k6/lib/metrics.js` | the five custom metrics, names byte-for-byte from `slo-gates.md` §1 |
| `k6/lib/expected.js` | 409/429 as protocol, not `http_req_failed` (`slo.md` §0.4) |
| `k6/lib/nodes.js`, `discovery.js` | node-tree HTTP helpers + one-time setup() discovery (a downloadable-file pool, the seeded `documents` folder's node ids, a bootstrap share token) — the manifest carries only a few anchor ids, not a full id-list per kind (Known Deviations) |
| `k6/scenarios/{tus-upload,s3-multipart,single-shot,download,tree,docs-collab,mixed}.js` | the 7 scenarios (Scope Included, P2.4) |
| `k6/smoke.js` | contract smoke — every scenario once, DB/storage post-conditions checked from `run.sh`/manually, not inside k6 |

## Layout

| Path | What |
|:--|:--|
| `docker-compose.load.yml` | postgres / pgbouncer / minio(+init) / app / prometheus / k6 |
| `env/{S0,S,M}.env` | tier numbers — `plans/VLT-LOAD/reference-env.md` §1/§4.1, cpuset translated to this lab-box's physical core ids (`lscpu -e=CPU,CORE,MAXMHZ`) |
| `docker/Dockerfile.app` | nginx + php-fpm, ONE image; app code is bind-mounted, not COPYed |
| `docker/{nginx.conf,php-fpm.conf,php.ini}` | SUT runtime config — `php-fpm.conf` is a template, `pm.max_children` substituted at container start from the tier's `PM_MAX_CHILDREN` |
| `docker/entrypoint.sh` | renders the FPM pool, migrates direct-to-postgres (bypasses PgBouncer — DDL/prepared-statement reuse under transaction pooling is a known caveat), then runs php-fpm + nginx |
| `docker/prometheus.yml` | self-scrape only; k6's `--out experimental-prometheus-rw` (D24) reaches Prometheus via `--web.enable-remote-write-receiver` (compose `command`, not this file) |
| `app/LoadServiceProvider.php`, `LoadActingAs.php` | load-stand host — mirrors `packages/core/tests/E2E/E2eServiceProvider.php` |
| `app/testbench.load.yaml` | testbench config, bind-mounted to `/app/testbench.yaml` |
| `app/Console/SeedLoadDatasetCommand.php` | `load:seed` — P2.3, the tier volume+dirty-state envelope |
| `app/Console/ActivityWriterCommand.php` | `load:activity-write` — P2.3, sustained `v_activity` tail during a k6 run |
| `seed/profiles/{S0,S,M,L}.php` | per-tier seed volume knobs — `reference-env.md` §2.1/§2.2/§4.1 (L is spec-only, `load:seed` refuses it) |
| `seed/out/` | `load:seed` manifests, keyed by `dataset_id` — git-ignored run artifacts |
| `scripts/{up,down,snapshot,restore}.sh` | tier-parametrised bring-up/tear-down/snapshot/restore |
| `scripts/run.sh` | **P2.4** — stage the seed manifest + run a k6 scenario or the contract smoke |
| `k6/` | **P2.4** — lib + 7 scenarios + `smoke.js` (see previous section) |
| `gates/`, `baselines/` | **P2.5**, do not exist yet |

## Why nginx does not cap request bodies

`client_max_body_size 0` (nginx.conf) — the size cap that matters is vaulter's OWN
`uploads.max_chunk_bytes` (config, enforced in `S3MultipartHandler`/`TusUploadHandler`).
Capping earlier at nginx/php.ini would clip a chunk before the package's own limit is
exercised, and the harness exists to measure the package, not its front proxy
(`plan.md` D-LOAD-4).

## Known limitation — tier M

D25: this lab-box (i7-14700K, 8 P-cores + 12 E-cores) cannot build a homogeneous 12-core
cpuset for tier M — `load/env/M.env` ships the memory/pm numbers from
`reference-env.md` §1.1 but leaves `CPUSET_*` empty (same mechanism as S0). Latency
numbers from a tier-M run on this host are **not authoritative** (`open-questions.md`
Q4) — only class A. The authoritative tier for P2 is **S**.
