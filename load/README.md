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
TIER=S0 ./load/scripts/run.sh smoke-perf                # P2.6 — mixed.js gated by thresholds.js, CI's smoke-perf/regression profile
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

## Gate: thresholds + perf-assert (P2.5)

Two planes (D18, `slo-gates.md` §0.2): k6 sees only the client side, so the gate is
split into `load/gates/thresholds.js` (k6 `options.thresholds` — error-rate, checks-rate,
and the streaming class-A rows SLO-40..54 that k6 can actually see: throughput,
`data_sent`/`data_received`) and `load/gates/perf-assert.py` (everything else: RSS
ceilings, background unbounded-scan counters, class-B latency-vs-baseline — all read from
a Prometheus snapshot + `summary.json` + a committed baseline). `load/gates/coverage.py`
checks that every row of `slo-gates.md` §3 (96 today) has one of the two homes.

```sh
python3 load/gates/coverage.py
python3 load/gates/perf-assert.py --summary load/results/summary.json \
    --baseline load/baselines/smoke-perf-S0.json --profile smoke-perf \
    --prometheus-url http://127.0.0.1:9090
```

`load/gates/thresholds.js` is staged into `k6/lib/thresholds.js` by `run.sh` (same
`load/gates/` volume-mount gap as `dataset.json` — see previous section) and consumed
via `handleSummary` (`k6/lib/summary.js`) for the `meta` header (`commit`, `profile`,
`tier`, `env_fingerprint`, `dataset_id`) baseline comparison needs. `run.sh` computes
`ENV_FINGERPRINT` on the host (hash of the tier `.env` file + pinned image versions +
`dataset_id`) and passes it in — k6's sandbox has no hashing/crypto.

**Not wired into `smoke.js` on purpose.** The contract smoke is 1 VU × 1 iteration
(P2.4) — a single sample has no meaningful `med`/`min` distribution, and forcing the
real class-A thresholds onto it made the FUNCTIONAL contract fail on a healthy run
(found live while validating this item). The `smoke-perf`/`regression` profiles that DO
gate on `thresholds.js` are P2.6's job, against a sustained `constant-vus` run (e.g.
`mixed.js`), not this 1-iteration smoke.

**Today, almost everything is `not-checkable` or `advisory`** — by design (P8 hasn't
shipped: `coverage.py` prints this explicitly, not silently). `perf-assert.py` still
exits 0 on a real smoke run because none of that is a *blocking* verdict yet.

**Findings from live validation of this gate (recorded here, not fixed — D-LOAD-5):**
- `thresholdsFor()`'s streaming-class-A numbers, calibrated per `slo-gates.md` §3.2 for
  a sustained tier-S/M/L run, do not all hold at S0-smoke scale with tiny files/short
  durations — `single_shot`'s per-request overhead dominates a 1 MiB transfer, and a
  short `mixed.js` window can leave `download`'s discovery pool empty (both observed
  live). Calibrating/measuring this is P2.7 (CV) and P2.8 (DoD baseline on tier S), not
  this item.
- A k6 threshold on a metric+tag combination that never received a sample in the run
  still evaluates (as zero) and can fail — `mixed.js` (P2.6's real consumer) must
  exercise every op it gates within the run window, or that op's threshold row should
  not be included for a profile that cannot guarantee it.

## CI: how the gate behaves (P2.6)

`.github/workflows/load.yml` — three jobs, `slo-gates.md` §7:

| Job | Trigger | Profile | Class A | Class B |
|:--|:--|:--|:--|:--|
| `smoke-perf` | `pull_request` (path-filtered), `workflow_dispatch` | `smoke-perf`, tier **S0** only (D17/D21/Q3 — the only profile `ubuntu-latest` fits) | **blocking** — a threshold breach (k6 exit 99) or a `fail` verdict from `perf-assert.py` fails the job | writes `load/results/summary.json` against the committed baseline; **never fails the job** (D15/D20) |
| `regression` | `schedule` (nightly on `main`), `workflow_dispatch` | `regression`, tier S0, median of **3** `run.sh regression` runs merged metric-by-metric (`statistics.median`, §4.3) | blocking, same as `smoke-perf` | same advisory comparison, against the same baseline, on the steadier median |
| `baseline-guard` | every `pull_request` | — | a diff under `load/baselines/*` without the PR label `perf-rebaseline` fails the job outright (§4.4) | — |

`run.sh smoke-perf` / `run.sh regression` (new `LOAD_PROFILE` case in the `TARGET` case
statement) compose the class-A gate onto `mixed.js` at run time — a stdin-piped k6
script (`--workdir /scripts` so its relative imports resolve like a real `/scripts/*.js`
file) that re-exports `mixed.js`'s `setup`/`interactive`/`upload`/`download` under
`options.thresholds: thresholdsFor(profile, tier)` and a `handleSummary` wired to
`summaryOutputs`. This composes `mixed.js` (P2.4), `thresholds.js` (P2.5) and
`summary.js` (P2.5) without editing any of the three — none of them are this item's
`Files`. `regression`'s 60s-per-run × 3 plus a 110s seed comfortably fits its own
60-minute budget; `smoke-perf`'s single run targets the tighter **≤ 6 min** class-A
budget from `slo-gates.md` §7 (Implementation Rules: shorten the run, never drop a
gated SLO, if it does not fit).

A job's step summary always prints the `perf-assert.py` tail
(`pass=N fail=N not-checkable=N incomparable=N known-red=N`) — a green `smoke-perf`
proves class A on the SLOs the client can see today, not all 96 rows of `slo-gates.md`
§3 (Code Guidance, Anti-самообман; most rows stay `not-checkable` until P8 ships their
Prometheus metric).

**Known-red today, on purpose:** SLO-46…48 (`S3MultipartHandler::complete()`, L-01) —
`perf-assert.py` prints `known-red`, not `fail`; this does not block the job (`slo-gates.md`
§6.4). It starts failing for real the moment the L-01 dev-slice lands (D11).

**Open, not this item's job (recorded, not silently absorbed):**
- **S0-scale calibration of the streaming class-A numbers is unresolved.** A live
  60s `run.sh smoke-perf` against a fully re-seeded S0 stand genuinely breaches
  `single_shot`/`tus_patch`'s `stream_throughput_mibs` floor (calibrated in
  `thresholds.js` for a sustained tier-S/M/L run against larger files — S0's ≤256 MiB
  fixture does not reach it) and can push `http_req_failed{class:interactive}` over
  budget under `pm.max_children=4`. Implementation Rules forbid "adjusting" those
  numbers here (`slo-gates.md`/`thresholds.js` are P1.4/P2.5 SSOT) — this is
  `smoke-perf`'s honest state until P2.7 (CV) / P2.8 (DoD baseline on tier S) either
  recalibrate the S0-inherited numbers or the owner accepts a dedicated S0 row.
  Until then, `smoke-perf` on this repo's `main` is expected to be **class-A red**, not
  green — the wiring (thresholds actually gate a sustained run, the job actually fails
  on a breach, artifacts/summary land either way) is what this item proves, not a
  clean run at S0 scale.
- The `regression` job's median-merge (workflow step, not a `load/` script) walks
  `data.metrics` generically (median per numeric leaf across the 3 runs) — it has not
  been exercised on GitHub's actual `ubuntu-latest` (P2.7's job, `findings/P2.7-cv-runner.md`).

## P3 profiling lane (P3.1)

Reproducible PostgreSQL profiling for the load stand: HypoPG `1.4.1` in the `postgres`
service image, `pg_stat_statements` with `track=all`, SQL preflight/reset/capture, a
privacy-safe Laravel `DB::listen` trace (`LOAD_DB_TRACE=1`, default off), and a bounded
evidence validator.

```sh
chmod +x load/scripts/p3-profile.sh
TIER=S0 ./load/scripts/p3-profile.sh prepare --dry-run
TIER=S0 ./load/scripts/p3-profile.sh prepare          # build image, CREATE EXTENSION, preflight
python3 -m unittest load/gates/test_p3_profile.py
```

Opt-in application trace during a workload (requires `LOAD_RUN_ID`; writes JSONL to
`LOAD_DB_TRACE_PATH`):

```sh
LOAD_DB_TRACE=1 LOAD_RUN_ID=<uuid> LOAD_DB_TRACE_PATH=/tmp/query-trace.jsonl \
  docker compose -f load/docker-compose.load.yml --env-file load/env/S0.env exec app \
  php vendor/bin/testbench load:seed
```

Admin SQL runs **direct-to-postgres** (`postgres:5432`); application workload stays on
PgBouncer (`pgbouncer:6432`). Run directories under `load/results/p3/<run_id>/` are
published only after `p3-profile.sh verify` / `python3 load/gates/p3_profile.py verify`.

## P3.2 controlled workload (tier S)

Authoritative profiling capture: P8.7 preflight, disposable re-seed, `ANALYZE`, shared-buffer-cold-like
postgres restart, warm + ranked workload window (`scenarios/mixed.js`, not SLO thresholds), `pg_stat_statements`
top-20, cluster `pg_stat_io` delta, OP coverage matrix. Publication validates a complete snapshot
and creates `plans/VLT-LOAD/artifacts/P3/<run_id>/`; existing destinations are refused.
Warmup and measured writers run for their respective durations and finish before trace reset or
publication. Writer, k6 and maintenance command failures reject the capture.

```sh
python3 load/gates/p3_workload.py preflight
export LOAD_RUN_ID="$(python3 -c 'import uuid; print(uuid.uuid4())')"
export P3_ARTIFACT_DIR="$PWD/plans/VLT-LOAD/artifacts/P3/$LOAD_RUN_ID"
TIER=S P3_DURATION=120s ./load/scripts/p3-profile.sh capture
python3 load/gates/p3_workload.py verify-artifacts --artifact-dir "$P3_ARTIFACT_DIR"
```

Requires local load stand (Docker), GREEN `plans/VLT-LOAD/artifacts/P8.7/`, and `load:seed` profile
(`--tier=S0` volume on tier-S hardware matches P2.8/P3 lab envelope).

## P3.3 actual EXPLAIN, index review, HypoPG (tier S corpus)

Runs on the fresh published P3.2 bundle selected by `P3_ARTIFACT_DIR` (same `run_id` / `dataset_id`).
Captures `EXPLAIN (ANALYZE, BUFFERS, VERBOSE, SETTINGS, WAL, FORMAT JSON)` via `PREPARE`/`EXECUTE`
with representative bindings, builds hot-family index inventory/review, and bounded HypoPG planner
candidates (plain `EXPLAIN` only — no `ANALYZE` on hypothetical indexes).

```sh
TIER=S P3_ARTIFACT_DIR="$P3_ARTIFACT_DIR" ./load/scripts/p3-explain-capture.sh
python3 -B -m unittest discover -s load/gates -p 'test_p3_*.py'
python3 -B -m unittest discover -s load/gates -p 'test_r04_*.py'
```

Outputs (under the selected run directory): `explain/*.json`,
`index-inventory.json`, `index-review.json`, `hypopg-candidates.json`. DML plans use
`BEGIN … ROLLBACK` on the disposable load DB only.
An `ok` plan counts as analyzed only with actual execution metrics and matching run/dataset;
rejected plans count as attempted and retain their reason. Repeated trace hashes within a single
invocation are N+1 review candidates, not an automatic product verdict.

## P4 memory lane (P4.1)

Isolated single-worker FPM lane with PID-safe `/proc` + cgroup v2 sampling and a schema
validator for PHP peak, process PSS (`smaps_rollup`), and cgroup breakdown. Real path matrix
fixtures land in P4.2; P4.3 owns the tier-S live run.

```sh
chmod +x load/scripts/p4-memory.sh load/scripts/p4-memory-sampler.py
docker compose -f load/docker-compose.load.yml -f load/docker-compose.memory.yml \
  --env-file load/env/S0.env config
python3 -m unittest load/gates/test_p4_memory.py
TIER=S0 ./load/scripts/p4-memory.sh dry-run
TIER=S0 ./load/scripts/p4-memory.sh run --case lane_selftest   # optional lab self-test
TIER=S0 ./load/scripts/p4-memory.sh verify --run-dir load/results/p4/<run_id>
```

Run directories under `load/results/p4/<run_id>/` (`run-manifest.json`, `samples.jsonl`,
optional `case-manifest.json`) publish only after `p4-memory.sh verify` or
`python3 load/gates/p4_memory.py verify --run-dir …`.

## P4.2 path fixtures and 15-cell matrix

Bounded on-disk fixtures (streaming PHP writes, no multi-GiB strings), a 5×3 public-path
case manifest, k6 `memory-paths.js` (`responseType:none` on download), and load-only
Artisan probes for CAS `stage()` and server/object checksum.

```sh
chmod +x load/scripts/p4-memory-cases.sh
./load/scripts/p4-memory-cases.sh list          # 15 cells, ceilings 64/160/160/64/16 MiB
./load/scripts/p4-memory-cases.sh manifest      # machine-readable matrix JSON
./load/scripts/p4-memory-cases.sh static-scan   # no whole-file read helpers in P4.2 commands
./load/scripts/p4-memory-cases.sh contract-check  # pest + p4_memory unittest (+ k6 inspect when k6 is on PATH)

# Inside the memory app container (after stand up + migrate):
docker compose -f load/docker-compose.load.yml -f load/docker-compose.memory.yml \
  --env-file load/env/S0.env exec app sh -c \
  'vendor/bin/testbench load:memory-fixtures prepare --fixture-id=p4-contract-5m \
    --run-prefix=p4-contract --size=5MiB --with-api-state'
docker compose -f load/docker-compose.load.yml -f load/docker-compose.memory.yml \
  --env-file load/env/S0.env exec app sh -c \
  'vendor/bin/testbench load:memory-probe-cas-stage --fixture-id=p4-contract-5m --run-prefix=p4-contract'

# k6 single cell (manifest JSON from load:memory-fixtures show):
docker compose -f load/docker-compose.load.yml --profile bench run --rm k6 \
  run -e MEMORY_CELL=tus_final_patch/5m -e MEMORY_FIXTURE_MANIFEST="$(cat …)" \
  /scripts/scenarios/memory-paths.js
```

500 MiB and 5 GiB matrix cells stay **dry-run in k6 manifests** until `P4_MATRIX_LIVE=1`; the
S0 contract lane exercises all five paths at **5 MiB** only.

## P4.3 tier-S memory matrix (authoritative run)

P8.7 preflight must be GREEN (`plans/VLT-LOAD/artifacts/P8.7`). The matrix publishes immutable
carriers under `plans/VLT-LOAD/artifacts/P4/` (`run-manifest.json`, `cases.json`, `samples/`,
`php-peaks.json`, `cgroup.json`, `k6/`, `cell-verdicts.json`): 15 cells (5 paths × 3 sizes),
3 repetitions each (45 attempts), size order **5 MiB → 500 MiB → 5 GiB**, fresh `app` container
per attempt.

```sh
python3 load/gates/p8_live_gate.py verify --artifacts plans/VLT-LOAD/artifacts/P8.7
python3 -m unittest load/gates/test_p4_memory_matrix.py
chmod +x load/scripts/p4-memory.sh load/scripts/p4-memory-matrix-run.sh

# Live matrix (default; real k6/CLI paths; needs ≥2×5 GiB free disk for full 45):
TIER=S ./load/scripts/p4-memory.sh run --matrix
TIER=S ./load/scripts/p4-memory.sh verify --run-id "$(jq -r .run_id plans/VLT-LOAD/artifacts/P4/run-manifest.json)"

# Rebuild only after Dockerfile/build-context changes; normal runs reuse the existing app image:
P4_MATRIX_REBUILD=1 TIER=S ./load/scripts/p4-memory.sh run --matrix

# Optional S3 I/O timeout override; the 900s default accommodates measured 5 GiB transfers:
LOAD_S3_IO_TIMEOUT=900 TIER=S ./load/scripts/p4-memory.sh run --matrix

# Contract-only (synthetic gate fixtures, no live I/O):
P4_MATRIX_CONTRACT=1 TIER=S ./load/scripts/p4-memory.sh run --matrix

# Smoke one cell (e.g. before full matrix):
P4_MATRIX_SMOKE_CELL=tus_final_patch/5m P4_MATRIX_MAX_ATTEMPTS=1 TIER=S0 \
  ./load/scripts/p4-memory-matrix-run.sh --live --run-id "$(uuidgen)" --out /tmp/p4-matrix-smoke
```

`python3 load/gates/p4_memory_matrix.py verify-matrix --artifacts plans/VLT-LOAD/artifacts/P4`
confirms 45 valid attempts, exact byte sizes, repetitions, per-attempt `p4_memory.verify_run_dir`,
secret scan, and byte-stable re-verify (`verify --run-id` runs verify-matrix twice).

| Path | Artisan / k6 | Ceiling (MiB) |
|:--|:--|--:|
| `tus_final_patch` | k6 final PATCH after CLI pre-upload | 64 |
| `s3_putpart` | k6 single bounded 64 MiB part | 160 |
| `s3_complete_postfix` | parts pre-staged; k6 `complete` on fresh worker (P4.3) | 160 |
| `cas_stage_probe` | `load:memory-probe-cas-stage` (CLI, not FPM) | 64 |
| `download` | k6 `OP-08` + separate `load:memory-fixtures probe-object` checksum | 16 |

## P5 concurrency lane (P5.1)

The lane separates product HTTP time, PostgreSQL lock wait, and PgBouncer queue
wait. Planes are closed: `product-realistic`, `product-worst-case`,
`primitive-control`, `dirty-cardinality-probe`. Saturation `N=1/8/32/200` follows
tier S `default_pool_size=8` and `max_client_conn=200`. `N=201` is a client-boundary
control, not a supported workload. Product fixtures are P5.2; this lane does not
publish a live matrix.

```bash
chmod +x load/scripts/p5-concurrency.sh load/scripts/p5-postgres-snapshot.sh load/scripts/p5-pgbouncer-snapshot.sh
TIER=S ./load/scripts/p5-concurrency.sh dry-run
TIER=S ./load/scripts/p5-concurrency.sh check-stand
TIER=S ./load/scripts/p5-concurrency.sh verify --run-dir load/gates/fixtures/p5/complete
sh -n load/scripts/p5-concurrency.sh load/scripts/p5-postgres-snapshot.sh load/scripts/p5-pgbouncer-snapshot.sh
python3 -m unittest load/gates/test_p5_concurrency.py
```

`dry-run` prints the axes and performs no Docker or database write. `check-stand`
reads `docker compose config` for PostgreSQL 16 lock logging and PgBouncer
transaction pooling, then checks `SHOW POOLS` / `SHOW STATS` / `SHOW CONFIG`.
Primitive SQL uses `pg_advisory_xact_lock` / `pg_try_advisory_xact_lock` and
`ROLLBACK` on a disposable database (`P5_SQL_LIVE=1` and `P5_SQL_DATABASE` other
than `vaulter_load`). Snapshots keep `pg_blocking_pids()` as the blocker source;
a `pg_locks` self-join is diagnostic only. A missing sample stays
`evidence-invalid` and is never stored as zero. Artifacts are published only
after `verify`.

## P5 product fixtures (P5.2)

`load:concurrency-fixtures` writes a disposable snapshot keyed by `fixture_id`:
small same/different tus and S3 session ids, a normal CAS pair, and a searched
int4 `hashtext(scope|sha256)` collision (distinct sha256, equal int4, fail after
250k candidates). Tree plans are 1k nodes and restore per repetition. Dirty
profiles record 20k and 1m rows without seeding them; the 1m profile is not a
tier-L claim. Cleanup deletes that fixture id or one run-prefix directory.

The case matrix follows the P5.1 catalog. Same-resource 409s are protocol
results (one progress owner plus conflicts) and are not scored against the
≤5 % budget. Different-resource 409s stay inside that budget. Direct SQL is
not labeled as an OP or SLO. HTTP 5xx is never success. k6 live cells wait on
`P5_BARRIER_URL`; contract mode runs one n=1 cell per surface and skips the
1m seed.

```bash
chmod +x load/scripts/p5-concurrency-cases.sh
./load/scripts/p5-concurrency-cases.sh static-validate
./load/scripts/p5-concurrency-cases.sh inspect
./load/scripts/p5-concurrency-cases.sh contract
vendor/bin/pest load/tests/Feature/ConcurrencyFixturesTest.php
```

## Layout

| Path | What |
|:--|:--|
| `docker-compose.load.yml` | postgres / pgbouncer / minio(+init) / app / prometheus / k6 |
| `docker-compose.memory.yml` | P4.1 overlay — `PM_MAX_CHILDREN=1`, memory FPM pools, FPM-status healthcheck |
| `env/{S0,S,M}.env` | tier numbers — `plans/VLT-LOAD/reference-env.md` §1/§4.1, cpuset translated to this lab-box's physical core ids (`lscpu -e=CPU,CORE,MAXMHZ`) |
| `docker/Dockerfile.app` | nginx + php-fpm, ONE image; app code is bind-mounted, not COPYed |
| `docker/{nginx.conf,php-fpm.conf,php.ini}` | SUT runtime config — `php-fpm.conf` is a template, `pm.max_children` substituted at container start from the tier's `PM_MAX_CHILDREN` |
| `docker/php-fpm.memory.conf` | P4.1 memory lane pool template (`memory` + `memory_status`) |
| `scripts/p4-memory.sh`, `scripts/p4-memory-sampler.py` | P4.1 lane CLI + read-only sampler |
| `scripts/p5-concurrency.sh`, `scripts/p5-postgres-snapshot.sh`, `scripts/p5-pgbouncer-snapshot.sh` | P5.1 barrier lane and raw PG/PgBouncer collectors |
| `scripts/p6-queue.sh`, `scripts/p6-queue-snapshot.sh`, `gates/p6_queue.py` | P6.1 queue lane, Redis LLEN/ZCARD sampler, evidence validator |
| `webhook-sink/router.php`, `scripts/p6-queue-cases.sh`, `gates/p6_cases.py` | P6.2 controlled sink, semantic matrix, S0 contract |
| `scripts/p7-chaos.sh`, `scripts/p7-chaos-snapshot.sh`, `gates/p7_chaos.py` | P7.1 chaos lane, phase ledger, collectors, evidence validator |
| `docker-compose.chaos.yml`, `chaos/` | P7.2 overlay: pinned Toxiproxy, S3 503 proxy, PG16 HA lab, dedicated tmpfs |
| `scripts/p7-injectors.sh`, `gates/p7_injectors.py` | P7.2 injector matrix, S0 contract, isolation guards |
| `app/Console/PrepareChaosFixturesCommand.php` | P7.2 disposable upload/CAS/outbox fixtures |
| `app/Console/PrepareQueueFixturesCommand.php`, `app/Webhooks/LoadSinkOutboundUrlGuard.php` | P6.2 disposable queue fixtures and sink-only outbound guard |
| `gates/p5_concurrency.py`, `gates/test_p5_concurrency.py`, `gates/fixtures/p5/` | P5.1 evidence schema, negative fixtures, validator |
| `scripts/p5-concurrency-cases.sh`, `k6/scenarios/concurrency.js`, `k6/lib/concurrency-cases.js` | P5.2 case matrix, contract k6 scenario, barrier mapping |
| `gates/p5_cases.py`, `gates/test_p5_cases.py` | P5.2 expected-409 classification and catalog parity |
| `app/Console/PrepareConcurrencyFixturesCommand.php` | P5.2 disposable fixtures, collision search, integrity checks |
| `gates/p4_memory.py`, `gates/test_p4_memory.py` | P4.1 evidence validator + fixtures |
| `docker/entrypoint.sh` | renders the FPM pool, migrates direct-to-postgres (bypasses PgBouncer — DDL/prepared-statement reuse under transaction pooling is a known caveat), then runs php-fpm + nginx |
| `docker/prometheus.yml` | self-scrape only; k6's `--out experimental-prometheus-rw` (D24) reaches Prometheus via `--web.enable-remote-write-receiver` (compose `command`, not this file) |
| `app/LoadServiceProvider.php`, `LoadActingAs.php` | load-stand host — mirrors `packages/core/tests/E2E/E2eServiceProvider.php` |
| `app/testbench.load.yaml` | testbench config, bind-mounted to `/app/testbench.yaml` |
| `app/Console/SeedLoadDatasetCommand.php` | `load:seed` — P2.3, the tier volume+dirty-state envelope |
| `app/Console/ActivityWriterCommand.php` | `load:activity-write` — P2.3, sustained `v_activity` tail during a k6 run |
| `seed/profiles/{S0,S,M,L}.php` | per-tier seed volume knobs — `reference-env.md` §2.1/§2.2/§4.1 (L is spec-only, `load:seed` refuses it) |
| `seed/out/` | `load:seed` manifests, keyed by `dataset_id` — git-ignored run artifacts |
| `scripts/{up,down,snapshot,restore}.sh` | tier-parametrised bring-up/tear-down/snapshot/restore |
| `scripts/run.sh` | **P2.4/P2.5/P2.6** — stage the seed manifest + `gates/thresholds.js` + run a k6 scenario, the contract smoke, or a gated `smoke-perf`/`regression` profile (stdin-piped `mixed.js` + thresholds + `handleSummary`) |
| `scripts/collect-container-stats.sh` | **P2.5** — cgroup `memory.peak` per stand container (coarse RSS stand-in until P8) |
| `k6/` | **P2.4** — lib + 7 scenarios + `smoke.js` (see previous section) |
| `gates/thresholds.js` | **P2.5** — k6 `options.thresholds` builder (client plane, D18) |
| `gates/perf-assert.py`, `_slo_table.py` | **P2.5** — server plane: PromQL/summary.json verdicts per `slo-gates.md` §3 row |
| `gates/coverage.py` | **P2.5** — machine check that every §3 row has an assigned plane |
| `baselines/` | **P2.5** — committed baseline artifacts (`slo-gates.md` §4.1), `.gitkeep` until the first real one lands |
| `.github/workflows/load.yml` (repo root, not under `load/`) | **P2.6** — `smoke-perf` (PR), `regression` (nightly, median-3), `baseline-guard` (label rule) — see "CI: how the gate behaves" above |

## P6 queue lane (P6.1)

The lane keeps outbox rows/s, jobs/s, Redis pending/delayed/reserved depth, worker
occupancy and HTTP receives as separate fields. Planes are closed:
`product-realistic`, `overlap-control`, `state-machine-control`,
`dirty-cardinality-probe`. Only `product-realistic` claims SLO-58, SLO-61 and
SLO-64. Tier S product cells are 2 workers, a 50 ms sink, outbox 10k, 20
endpoints, 5k due retries, ingress 20/100/200 and fan-out 0/1/all across sink
profiles 2xx, 500, slow-under-timeout and over-timeout. Overlap, forced
backoff/dead/disable and the 200/1000 endpoint plus 50k/500k due probes do not
claim throughput. P6.2 adds the controlled sink, fixtures and semantic matrix.
The authoritative tier-S run remains P6.3.

Redis pending depth is `LLEN` of `queues:{name}` (`pendingSize()`). Delayed and
reserved depth are `ZCARD` of the `:delayed` and `:reserved` sets. `RedisQueue::size()`
is not a pending sample. A missed probe is evidence-invalid and is not stored
as zero. The lifecycle listener records uuid, class, attempt and outcome and
does not unserialize the job command. Listener I/O failures do not change the
job result. Artifacts are published only after `verify`.

```bash
chmod +x load/scripts/p6-queue.sh load/scripts/p6-queue-snapshot.sh
TIER=S ./load/scripts/p6-queue.sh dry-run
TIER=S ./load/scripts/p6-queue.sh check-stand
TIER=S ./load/scripts/p6-queue.sh verify --run-dir load/gates/fixtures/p6/complete
sh -n load/scripts/p6-queue.sh load/scripts/p6-queue-snapshot.sh
python3 -m unittest load/gates/test_p6_queue.py
vendor/bin/pest load/tests/Feature/QueueLifecycleLedgerTest.php
```

`dry-run` prints the 46-cell matrix and does not start Docker or write the
database. `run` without `P6_PUBLISH=1` refuses to start workers. The current
worker route stays `queue:work redis --queue=load-health,default --sleep=1
--tries=1` with `retry_after` 90.

## P6 sink, fixtures and semantic matrix (P6.2)

The sink listens only on the stand network (`webhook-sink:8090`, no published
host port). Profiles are `2xx`, `500`, `delay` (under the 5 s webhook timeout)
and `hang`. `hang` sleeps until `LOAD_SINK_HANG_SECONDS` and never past 30 s.
The ledger records `event_id`, profile, status, start, end and ordinal. It does
not record the signature or the body. `LoadSinkOutboundUrlGuard` refuses every
URL that is not that sink, so the stand cannot POST to the public internet.

Fixtures are deterministic per run prefix and fixture id. Cleanup deletes that
id or the whole snapshot and nothing outside the fixture root. Tier S declares
10k outbox rows, 20 endpoints and 5k due retries, ingress 1×/5×/10×
(20/100/200) and subscription density 0/1/all. Endpoint probes 200/1000 and due
probes 50k/500k are not seeded and are not tier M/L SLOs; each one requires a
snapshot restore first. Fan-out is `deliveries/jobs`, not the endpoint count.
Backoff, dead and disable controls record the configured backoff delta before
any explicit clock move. That clock is not an elapsed-time SLO. Concurrent
duplicate jobs share one delivery id and use distinct queue UUIDs. A serial
terminal no-op is not concurrent exactly-once proof. A `timeout >= retry_after`
control is mechanism-only on queue `load-timeout-control` and is not the
product worker.

```bash
chmod +x load/scripts/p6-queue-cases.sh
./load/scripts/p6-queue-cases.sh static-validate
./load/scripts/p6-queue-cases.sh s0
python3 -m unittest load/gates/test_p6_cases.py
vendor/bin/pest load/tests/Feature/QueueFixturesTest.php
```

`static-validate` checks every plane × case axis. `s0` runs one 2xx POST, one
500 POST, one synchronized 2-relay/2-retry barrier and one concurrent duplicate
against the local sink, then checks for missing or extra sink receives.

## P7 chaos lane (P7.1)

The lane records a run-scoped phase ledger
(`baseline` → `armed` → `fault` → `revert` → `recovery` → `reconcile`)
and keeps dependency availability, HTTP outcome and durable product state as
separate fields. Planes are closed: `product-realistic`, `transport-control`,
`protocol-control`, `primary-transition`, `storage-control`. `armed` requires an
observed barrier. `recovered` requires a successful `/up`/`/ready` probe plus a
product reconcile. Missing revert, terminal marker or checksum is
evidence-invalid. Timing (`T_detect`, `T_restore`, `T_converge`) is published
without an RTO verdict. Object records store key, checksum and size only.

Injectors and isolated topology belong to P7.2. The authoritative tier-S family
is P7.3. Cleanup is exact run scope: no wildcard bucket, database or volume
deletion. `SIGINT`/`SIGTERM` run emergency revert.

P7 route changes and final base-route restore skip migrations for both app and
queue-worker. Schema bootstrap runs explicitly against `postgres-primary` /
`vaulter_chaos` only. The entrypoint defaults to skipping migrations and rejects
migration opt-in on a P7 route; ordinary base-app initialization retains its
Compose bootstrap setting (`LOAD_SKIP_MIGRATIONS=0`), while workers always skip.
Final integrity and lock readback follows the actual restore, including failure
cleanup; the terminal marker is written only after successful restore/readback.
Offline route controls use the Docker CLI's Compose renderer without a daemon:

```sh
PYTHONDONTWRITEBYTECODE=1 python3 -B -m unittest discover -s load/gates -p 'test_p7_base_restore.py'
```

The R11 full verifier requires all ten primary streams for all 77 attempts in
the 51-cell matrix. Fault identity, phases and observation order must agree
within each attempt; baseline and recovery probes are separate. Attempt files
and cell verdicts must agree with raw product results and both DB↔object joins,
including durable keys, checksums, sizes and legitimate expected absence.
Prometheus must contain a green `up{job="vaulter-sut",instance="app:8080"}`
instant vector within that attempt's wall-time window. The runner queries the
recorded end of the window explicitly. Historical labelled GET evidence stays
rejected. These admission checks cover the captured object; staged/part inventory
capture and the producer's `matched-absent` verdict remain separate repairs.

```sh
PYTHONDONTWRITEBYTECODE=1 python3 -B -m unittest discover -s load/gates -p 'test_p7_evidence.py'
```

```bash
chmod +x load/scripts/p7-chaos.sh load/scripts/p7-chaos-snapshot.sh
TIER=S ./load/scripts/p7-chaos.sh dry-run
TIER=S ./load/scripts/p7-chaos.sh verify --run-dir load/gates/fixtures/p7/complete
TIER=S ./load/scripts/p7-chaos.sh revert --run-dir load/gates/fixtures/p7/complete
sh -n load/scripts/p7-chaos.sh load/scripts/p7-chaos-snapshot.sh
python3 -m unittest load/gates/test_p7_chaos.py
```

`dry-run` prints the 51-cell matrix and does not start Docker or write the
database or object store. `run` without `P7_PUBLISH=1` refuses to start a live
fault window. `interrupt-fixture` arms a marker and proves revert on `SIGINT`.

## P7 injectors and matrix (P7.2)

Isolated topology lives in `docker-compose.chaos.yml` plus `load/chaos/`.
Toxiproxy is pinned to `ghcr.io/shopify/toxiproxy:2.11.0` and only proves TCP
latency/timeout/reset/down. S3 HTTP 503 is a load-only reverse proxy that
returns 503 before upstream and is never called a real S3 outage. PostgreSQL
promotion uses a two-node PG 16 lab with fencing, then PgBouncer repoint; a
session kill is not failover. ENOSPC is a dedicated app tmpfs at
`/var/tmp/vaulter-chaos`. Current multipart cleanup is Flysystem
`tmp/uploads/<id>.parts/*` keys, not AWS `UploadId`. Direct MinIO/Postgres
bypass fails preflight. Fixture cleanup is run-prefix scoped.

```bash
chmod +x load/scripts/p7-injectors.sh load/chaos/pg-failover.sh
TIER=S docker compose -f load/docker-compose.load.yml -f load/docker-compose.chaos.yml \
  --env-file load/env/S.env --profile chaos-ha config
python3 -m unittest load/gates/test_p7_injectors.py
./load/scripts/p7-injectors.sh static-validate
./load/scripts/p7-injectors.sh compose-config
./load/scripts/p7-injectors.sh guards
./load/scripts/p7-injectors.sh s0
./load/scripts/p7-injectors.sh promotion-rehearsal
vendor/bin/pest load/tests/Feature/ChaosFixturesTest.php
```

`s0` runs one 503, one TCP reset, one backend termination and one ENOSPC cell,
each with verified revert. `promotion-rehearsal` proves one writable primary
and PgBouncer reconnect, then restores only the isolated HA volumes.

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
