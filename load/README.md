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
| `scripts/{up,down}.sh` | tier-parametrised bring-up/tear-down |
| `k6/` | scenarios — **P2.4**, empty in P2.2 |
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
