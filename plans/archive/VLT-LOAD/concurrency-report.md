# P5 concurrency report — tier S

**Date:** 2026-09-23
**Evidence run:** `2aeca12f-6180-4595-8466-31397c4ed15d`
**Tier:** S
**Cells:** 56 valid, 104 attempts
**Verify digest:** `fb6343c5504b3f95aabe1db12260643877fcef2d41cead8ef225523621f1efdf` (recomputed from `artifacts/P5/`, matches P5.3)
**Authoritative inputs:** [`artifacts/P5/run-manifest.json`](artifacts/P5/run-manifest.json), [`artifacts/P5/matrix.json`](artifacts/P5/matrix.json), [`artifacts/P5/cell-verdicts.json`](artifacts/P5/cell-verdicts.json), [`artifacts/P5/observations.jsonl`](artifacts/P5/observations.jsonl)

`N` in every row is client concurrency (k6 VUs or primitive `psql` processes). It is not a count of PostgreSQL backends. HTTP 5xx is 0 on every cell and is not counted as success.

## 1. Environment and evidence validity

| Field | Value | Source |
|:--|:--|:--|
| Tier | S | run manifest |
| Git SHA | `7d54e2622e647cece510692fd8fb7eb13fac7f76` | run manifest `fingerprint.git_sha` |
| Worktree | dirty at fingerprint time | run manifest `fingerprint.git_dirty=true` |
| Clock | `2026-09-22T20:22:31Z` | run manifest |
| PostgreSQL collector | direct socket inside postgres | run manifest `collectors.postgres` |
| PgBouncer collector | admin database | run manifest `collectors.pgbouncer` |
| Pool mode | transaction | fingerprint `pgbouncer.pool_mode` |
| `default_pool_size` | 8 | fingerprint |
| `max_client_conn` | 200 | fingerprint |
| `query_wait_timeout` | 120 seconds | fingerprint |
| Baseline pool sample | `cl_waiting=0`, `sv_active=0`, `maxwait=0`, `avg_wait_time=0` | fingerprint |
| Integrity before | nodes 1019, uploads 31, blobs 9, storages 1 | `integrity-before.json` |
| Integrity after | same four counts | `integrity-after.json` |
| Seed fingerprint nodes | 1018 | `fingerprint.dataset_counts` |
| Prometheus `up` | HTTP 200 | `artifacts/P5/prometheus/up.status` |

The matrix covers all 56 `matrix.json` `order` entries. Attempt counts match `matrix.json` `repeats`: three for every `product-realistic` cell and for the 20k sweeper, one for worst-case, primitive, and the 1m probe. `cell-verdicts.json` marks every cell `evidence=valid`. `http_5xx` is 0. Integrity before equals integrity after, so the disposable fixture was restored. The seed fingerprint counted 1018 nodes and the integrity snapshot counted 1019 before and after; that one-node difference is recorded here and is not a within-run mutation.

P3 [`db-profile.md`](db-profile.md) is already produced (run `51259904-2241-4636-a06f-c82cd7d2d2ef`). Section 4.1 remains the recursive-CTE evidence for L-06. This report does not replace it.

Recorded pool settings stay as fingerprinted. This item does not change pool size, `query_wait_timeout`, or SLO thresholds.

## 2. How a row is counted

- Product HTTP outcomes come from the attempt file `statuses`, which copy k6 counters `p5_status_2xx`, `p5_status_409`, `p5_status_429`, `p5_status_5xx`, and `p5_status_other`. The k6 summary for a cell is `artifacts/P5/k6/p5-<cell_id>-a<attempt>.json` when the scenario ran.
- `waits.http_duration_seconds` on a k6 cell is orchestrator wall clock, including container start. Request latency is `metrics.http_req_duration` in the k6 summary. Example: `cas-lock.normal-key.n200` attempt 1 has k6 `http_req_duration` p95 644.9 ms and 200 `p5_status_429` counts.
- Same-resource and live-patch cells whose shared upload was not created store one setup status and `http_duration_seconds=0`. Their `N` clients did not start. The counted `429` is that setup response (the gate retries once after 60 seconds and still records 429).
- Primitive SQL outcomes are `statuses.ok` / `statuses.fail`. Try-lock also stores `nowait.refused_count`. The SQL is one transaction: `BEGIN`, `pg_advisory_xact_lock(42001)` or `pg_try_advisory_xact_lock(42001)` or pool key `42002`, `pg_backend_pid()`, `ROLLBACK`.
- Lock and pool samples in `observations.jsonl` are one snapshot per cell: attempt 3 when the cell has three repeats (24 cells), otherwise attempt 1 (32 cells). Repeats 1 and 2 of a three-repeat cell still have their own attempt files, deadlock cursors, and k6 summaries.
- Every file under `artifacts/P5/postgres/` (105 files) has `locks: []`. The observation field `granted: true` is the collector default when that list is empty (`first_lock.get('granted', True)`). It is not an observed grant. `pg_blocking_pids` is empty on every sample. `wait_event_type` is `Client` on 52 activity samples and absent on the four empty activity samples (`try-lock-nowait.n8`, both sweeper probes, `pool-timeout.n200`).
- Deadlocks are `post.deadlocks - pre.deadlocks` on each attempt file. The stored log cursor is a WAL LSN, not a count of deadlock log lines.

## 3. Full matrix

Machine SLO is the value in `cell-verdicts.json`. `red` means a `product-realistic` cell with zero 2xx and zero 5xx, or any cell with 5xx. There is no 5xx. `green` on `product-worst-case` `int4-collision` means the gate did not apply the product-realistic zero-success rule; the responses are still all 429. `not-a-5pct-budget` means same-resource 409 is a guard ratio, not the ≤5 % different-resource budget. `not-a-product-slo` means a primitive control or a dirty-cardinality probe.

| Cell | Plane | Surface | Shape | N | Reps | Machine SLO | Counted outcomes | Evidence |
|:--|:--|:--|:--|---:|---:|:--|:--|:--|
| `primitive-control.advisory-primitive.blocking-wait.n1` | primitive-control | advisory-primitive | blocking-wait | 1 | 1 | not-a-product-slo | sql ok 1, fail 0 | [a1](artifacts/P5/attempts/primitive-control.advisory-primitive.blocking-wait.n1.attempt-1.json) |
| `primitive-control.advisory-primitive.try-lock-nowait.n1` | primitive-control | advisory-primitive | try-lock-nowait | 1 | 1 | not-a-product-slo | sql ok 1, fail 0, refused 0/1 | [a1](artifacts/P5/attempts/primitive-control.advisory-primitive.try-lock-nowait.n1.attempt-1.json) |
| `primitive-control.advisory-primitive.xact-release.n1` | primitive-control | advisory-primitive | xact-release | 1 | 1 | not-a-product-slo | sql ok 1, fail 0 | [a1](artifacts/P5/attempts/primitive-control.advisory-primitive.xact-release.n1.attempt-1.json) |
| `primitive-control.pgbouncer-queue.pool-queue.n1` | primitive-control | pgbouncer-queue | pool-queue | 1 | 1 | not-a-product-slo | sql ok 1, fail 0 | [a1](artifacts/P5/attempts/primitive-control.pgbouncer-queue.pool-queue.n1.attempt-1.json) |
| `product-realistic.cas-lock.normal-key.n1` | product-realistic | cas-lock | normal-key | 1 | 3 | red | 2xx 0, 409 0, 429 3, 5xx 0, other 0; 409 ratio 0.000 (0/3) | [a1](artifacts/P5/attempts/product-realistic.cas-lock.normal-key.n1.attempt-1.json) |
| `product-realistic.collab-issuance.issuance-burst.n1` | product-realistic | collab-issuance | issuance-burst | 1 | 3 | red | 2xx 0, 409 0, 429 0, 5xx 0, other 3; 409 ratio 0.000 (0/3) | [a1](artifacts/P5/attempts/product-realistic.collab-issuance.issuance-burst.n1.attempt-1.json) |
| `product-realistic.subtree-move.disjoint-tree.n1` | product-realistic | subtree-move | disjoint-tree | 1 | 3 | green | 2xx 3, 409 0, 429 0, 5xx 0, other 0; 409 ratio 0.000 (0/3) | [a1](artifacts/P5/attempts/product-realistic.subtree-move.disjoint-tree.n1.attempt-1.json) |
| `product-realistic.sweeper-overlap.live-patch-overlap.n1` | product-realistic | sweeper-overlap | live-patch-overlap | 1 | 3 | red | 2xx 0, 409 0, 429 3, 5xx 0, other 0; 409 ratio 0.000 (0/3) | [a1](artifacts/P5/attempts/product-realistic.sweeper-overlap.live-patch-overlap.n1.attempt-1.json) |
| `product-realistic.upload-s3.different-resource.n1` | product-realistic | upload-s3 | different-resource | 1 | 3 | red | 2xx 0, 409 0, 429 3, 5xx 0, other 0; 409 ratio 0.000 (0/3) | [a1](artifacts/P5/attempts/product-realistic.upload-s3.different-resource.n1.attempt-1.json) |
| `product-realistic.upload-tus.different-resource.n1` | product-realistic | upload-tus | different-resource | 1 | 3 | red | 2xx 0, 409 0, 429 3, 5xx 0, other 0; 409 ratio 0.000 (0/3) | [a1](artifacts/P5/attempts/product-realistic.upload-tus.different-resource.n1.attempt-1.json) |
| `product-realistic.cas-lock.normal-key.n8` | product-realistic | cas-lock | normal-key | 8 | 3 | red | 2xx 0, 409 0, 429 24, 5xx 0, other 0; 409 ratio 0.000 (0/24) | [a1](artifacts/P5/attempts/product-realistic.cas-lock.normal-key.n8.attempt-1.json) |
| `product-realistic.cas-lock.normal-key.n32` | product-realistic | cas-lock | normal-key | 32 | 3 | red | 2xx 0, 409 0, 429 96, 5xx 0, other 0; 409 ratio 0.000 (0/96) | [a1](artifacts/P5/attempts/product-realistic.cas-lock.normal-key.n32.attempt-1.json) |
| `product-realistic.cas-lock.normal-key.n200` | product-realistic | cas-lock | normal-key | 200 | 3 | red | 2xx 0, 409 0, 429 600, 5xx 0, other 0; 409 ratio 0.000 (0/600) | [a1](artifacts/P5/attempts/product-realistic.cas-lock.normal-key.n200.attempt-1.json) |
| `product-realistic.collab-issuance.issuance-burst.n8` | product-realistic | collab-issuance | issuance-burst | 8 | 3 | red | 2xx 0, 409 0, 429 0, 5xx 0, other 24; 409 ratio 0.000 (0/24) | [a1](artifacts/P5/attempts/product-realistic.collab-issuance.issuance-burst.n8.attempt-1.json) |
| `product-realistic.collab-issuance.issuance-burst.n32` | product-realistic | collab-issuance | issuance-burst | 32 | 3 | red | 2xx 0, 409 0, 429 0, 5xx 0, other 96; 409 ratio 0.000 (0/96) | [a1](artifacts/P5/attempts/product-realistic.collab-issuance.issuance-burst.n32.attempt-1.json) |
| `product-realistic.collab-issuance.issuance-burst.n200` | product-realistic | collab-issuance | issuance-burst | 200 | 3 | red | 2xx 0, 409 0, 429 0, 5xx 0, other 600; 409 ratio 0.000 (0/600) | [a1](artifacts/P5/attempts/product-realistic.collab-issuance.issuance-burst.n200.attempt-1.json) |
| `product-realistic.subtree-move.disjoint-tree.n8` | product-realistic | subtree-move | disjoint-tree | 8 | 3 | green | 2xx 24, 409 0, 429 0, 5xx 0, other 0; 409 ratio 0.000 (0/24) | [a1](artifacts/P5/attempts/product-realistic.subtree-move.disjoint-tree.n8.attempt-1.json) |
| `product-realistic.subtree-move.disjoint-tree.n32` | product-realistic | subtree-move | disjoint-tree | 32 | 3 | green | 2xx 96, 409 0, 429 0, 5xx 0, other 0; 409 ratio 0.000 (0/96) | [a1](artifacts/P5/attempts/product-realistic.subtree-move.disjoint-tree.n32.attempt-1.json) |
| `product-realistic.subtree-move.disjoint-tree.n200` | product-realistic | subtree-move | disjoint-tree | 200 | 3 | green | 2xx 600, 409 0, 429 0, 5xx 0, other 0; 409 ratio 0.000 (0/600) | [a1](artifacts/P5/attempts/product-realistic.subtree-move.disjoint-tree.n200.attempt-1.json) |
| `product-realistic.sweeper-overlap.live-patch-overlap.n8` | product-realistic | sweeper-overlap | live-patch-overlap | 8 | 3 | red | 2xx 0, 409 0, 429 3, 5xx 0, other 0; 409 ratio 0.000 (0/3) | [a1](artifacts/P5/attempts/product-realistic.sweeper-overlap.live-patch-overlap.n8.attempt-1.json) |
| `product-realistic.sweeper-overlap.live-patch-overlap.n32` | product-realistic | sweeper-overlap | live-patch-overlap | 32 | 3 | red | 2xx 0, 409 0, 429 3, 5xx 0, other 0; 409 ratio 0.000 (0/3) | [a1](artifacts/P5/attempts/product-realistic.sweeper-overlap.live-patch-overlap.n32.attempt-1.json) |
| `product-realistic.upload-s3.different-resource.n8` | product-realistic | upload-s3 | different-resource | 8 | 3 | red | 2xx 0, 409 0, 429 24, 5xx 0, other 0; 409 ratio 0.000 (0/24) | [a1](artifacts/P5/attempts/product-realistic.upload-s3.different-resource.n8.attempt-1.json) |
| `product-realistic.upload-s3.different-resource.n32` | product-realistic | upload-s3 | different-resource | 32 | 3 | red | 2xx 0, 409 0, 429 96, 5xx 0, other 0; 409 ratio 0.000 (0/96) | [a1](artifacts/P5/attempts/product-realistic.upload-s3.different-resource.n32.attempt-1.json) |
| `product-realistic.upload-s3.different-resource.n200` | product-realistic | upload-s3 | different-resource | 200 | 3 | red | 2xx 0, 409 0, 429 600, 5xx 0, other 0; 409 ratio 0.000 (0/600) | [a1](artifacts/P5/attempts/product-realistic.upload-s3.different-resource.n200.attempt-1.json) |
| `product-realistic.upload-tus.different-resource.n8` | product-realistic | upload-tus | different-resource | 8 | 3 | red | 2xx 0, 409 0, 429 24, 5xx 0, other 0; 409 ratio 0.000 (0/24) | [a1](artifacts/P5/attempts/product-realistic.upload-tus.different-resource.n8.attempt-1.json) |
| `product-realistic.upload-tus.different-resource.n32` | product-realistic | upload-tus | different-resource | 32 | 3 | red | 2xx 0, 409 0, 429 96, 5xx 0, other 0; 409 ratio 0.000 (0/96) | [a1](artifacts/P5/attempts/product-realistic.upload-tus.different-resource.n32.attempt-1.json) |
| `product-realistic.upload-tus.different-resource.n200` | product-realistic | upload-tus | different-resource | 200 | 3 | red | 2xx 0, 409 0, 429 600, 5xx 0, other 0; 409 ratio 0.000 (0/600) | [a1](artifacts/P5/attempts/product-realistic.upload-tus.different-resource.n200.attempt-1.json) |
| `product-worst-case.cas-lock.int4-collision.n1` | product-worst-case | cas-lock | int4-collision | 1 | 1 | green | 2xx 0, 409 0, 429 1, 5xx 0, other 0; 409 ratio 0.000 (0/1) | [a1](artifacts/P5/attempts/product-worst-case.cas-lock.int4-collision.n1.attempt-1.json) |
| `product-worst-case.cas-lock.int4-collision.n8` | product-worst-case | cas-lock | int4-collision | 8 | 1 | green | 2xx 0, 409 0, 429 8, 5xx 0, other 0; 409 ratio 0.000 (0/8) | [a1](artifacts/P5/attempts/product-worst-case.cas-lock.int4-collision.n8.attempt-1.json) |
| `product-worst-case.cas-lock.int4-collision.n32` | product-worst-case | cas-lock | int4-collision | 32 | 1 | green | 2xx 0, 409 0, 429 32, 5xx 0, other 0; 409 ratio 0.000 (0/32) | [a1](artifacts/P5/attempts/product-worst-case.cas-lock.int4-collision.n32.attempt-1.json) |
| `product-worst-case.cas-lock.int4-collision.n200` | product-worst-case | cas-lock | int4-collision | 200 | 1 | green | 2xx 0, 409 0, 429 200, 5xx 0, other 0; 409 ratio 0.000 (0/200) | [a1](artifacts/P5/attempts/product-worst-case.cas-lock.int4-collision.n200.attempt-1.json) |
| `product-worst-case.subtree-move.overlapping-tree.n1` | product-worst-case | subtree-move | overlapping-tree | 1 | 1 | green | 2xx 1, 409 0, 429 0, 5xx 0, other 0; 409 ratio 0.000 (0/1) | [a1](artifacts/P5/attempts/product-worst-case.subtree-move.overlapping-tree.n1.attempt-1.json) |
| `product-worst-case.subtree-move.overlapping-tree.n8` | product-worst-case | subtree-move | overlapping-tree | 8 | 1 | green | 2xx 8, 409 0, 429 0, 5xx 0, other 0; 409 ratio 0.000 (0/8) | [a1](artifacts/P5/attempts/product-worst-case.subtree-move.overlapping-tree.n8.attempt-1.json) |
| `product-worst-case.subtree-move.overlapping-tree.n32` | product-worst-case | subtree-move | overlapping-tree | 32 | 1 | green | 2xx 32, 409 0, 429 0, 5xx 0, other 0; 409 ratio 0.000 (0/32) | [a1](artifacts/P5/attempts/product-worst-case.subtree-move.overlapping-tree.n32.attempt-1.json) |
| `product-worst-case.subtree-move.overlapping-tree.n200` | product-worst-case | subtree-move | overlapping-tree | 200 | 1 | green | 2xx 200, 409 0, 429 0, 5xx 0, other 0; 409 ratio 0.000 (0/200) | [a1](artifacts/P5/attempts/product-worst-case.subtree-move.overlapping-tree.n200.attempt-1.json) |
| `product-worst-case.upload-s3.same-resource.n1` | product-worst-case | upload-s3 | same-resource | 1 | 1 | not-a-5pct-budget | 2xx 0, 409 0, 429 1, 5xx 0, other 0; 409 ratio 0.000 (0/1) | [a1](artifacts/P5/attempts/product-worst-case.upload-s3.same-resource.n1.attempt-1.json) |
| `product-worst-case.upload-s3.same-resource.n8` | product-worst-case | upload-s3 | same-resource | 8 | 1 | not-a-5pct-budget | 2xx 0, 409 0, 429 1, 5xx 0, other 0; 409 ratio 0.000 (0/1) | [a1](artifacts/P5/attempts/product-worst-case.upload-s3.same-resource.n8.attempt-1.json) |
| `product-worst-case.upload-s3.same-resource.n32` | product-worst-case | upload-s3 | same-resource | 32 | 1 | not-a-5pct-budget | 2xx 0, 409 0, 429 1, 5xx 0, other 0; 409 ratio 0.000 (0/1) | [a1](artifacts/P5/attempts/product-worst-case.upload-s3.same-resource.n32.attempt-1.json) |
| `product-worst-case.upload-s3.same-resource.n200` | product-worst-case | upload-s3 | same-resource | 200 | 1 | not-a-5pct-budget | 2xx 0, 409 0, 429 1, 5xx 0, other 0; 409 ratio 0.000 (0/1) | [a1](artifacts/P5/attempts/product-worst-case.upload-s3.same-resource.n200.attempt-1.json) |
| `product-worst-case.upload-tus.same-resource.n1` | product-worst-case | upload-tus | same-resource | 1 | 1 | not-a-5pct-budget | 2xx 0, 409 0, 429 1, 5xx 0, other 0; 409 ratio 0.000 (0/1) | [a1](artifacts/P5/attempts/product-worst-case.upload-tus.same-resource.n1.attempt-1.json) |
| `product-worst-case.upload-tus.same-resource.n8` | product-worst-case | upload-tus | same-resource | 8 | 1 | not-a-5pct-budget | 2xx 0, 409 0, 429 1, 5xx 0, other 0; 409 ratio 0.000 (0/1) | [a1](artifacts/P5/attempts/product-worst-case.upload-tus.same-resource.n8.attempt-1.json) |
| `product-worst-case.upload-tus.same-resource.n32` | product-worst-case | upload-tus | same-resource | 32 | 1 | not-a-5pct-budget | 2xx 0, 409 0, 429 1, 5xx 0, other 0; 409 ratio 0.000 (0/1) | [a1](artifacts/P5/attempts/product-worst-case.upload-tus.same-resource.n32.attempt-1.json) |
| `product-worst-case.upload-tus.same-resource.n200` | product-worst-case | upload-tus | same-resource | 200 | 1 | not-a-5pct-budget | 2xx 0, 409 0, 429 1, 5xx 0, other 0; 409 ratio 0.000 (0/1) | [a1](artifacts/P5/attempts/product-worst-case.upload-tus.same-resource.n200.attempt-1.json) |
| `primitive-control.advisory-primitive.blocking-wait.n8` | primitive-control | advisory-primitive | blocking-wait | 8 | 1 | not-a-product-slo | sql ok 8, fail 0 | [a1](artifacts/P5/attempts/primitive-control.advisory-primitive.blocking-wait.n8.attempt-1.json) |
| `primitive-control.advisory-primitive.blocking-wait.n32` | primitive-control | advisory-primitive | blocking-wait | 32 | 1 | not-a-product-slo | sql ok 32, fail 0 | [a1](artifacts/P5/attempts/primitive-control.advisory-primitive.blocking-wait.n32.attempt-1.json) |
| `primitive-control.advisory-primitive.try-lock-nowait.n8` | primitive-control | advisory-primitive | try-lock-nowait | 8 | 1 | not-a-product-slo | sql ok 8, fail 0, refused 0/8 | [a1](artifacts/P5/attempts/primitive-control.advisory-primitive.try-lock-nowait.n8.attempt-1.json) |
| `primitive-control.advisory-primitive.try-lock-nowait.n32` | primitive-control | advisory-primitive | try-lock-nowait | 32 | 1 | not-a-product-slo | sql ok 32, fail 0, refused 0/32 | [a1](artifacts/P5/attempts/primitive-control.advisory-primitive.try-lock-nowait.n32.attempt-1.json) |
| `primitive-control.advisory-primitive.try-lock-nowait.n200` | primitive-control | advisory-primitive | try-lock-nowait | 200 | 1 | not-a-product-slo | sql ok 200, fail 0, refused 0/200 | [a1](artifacts/P5/attempts/primitive-control.advisory-primitive.try-lock-nowait.n200.attempt-1.json) |
| `primitive-control.advisory-primitive.try-lock-nowait.n201` | primitive-control | advisory-primitive | try-lock-nowait | 201 | 1 | not-a-product-slo | sql ok 201, fail 0, refused 0/201 | [a1](artifacts/P5/attempts/primitive-control.advisory-primitive.try-lock-nowait.n201.attempt-1.json) |
| `primitive-control.pgbouncer-queue.pool-queue.n8` | primitive-control | pgbouncer-queue | pool-queue | 8 | 1 | not-a-product-slo | sql ok 8, fail 0 | [a1](artifacts/P5/attempts/primitive-control.pgbouncer-queue.pool-queue.n8.attempt-1.json) |
| `primitive-control.pgbouncer-queue.pool-queue.n32` | primitive-control | pgbouncer-queue | pool-queue | 32 | 1 | not-a-product-slo | sql ok 32, fail 0 | [a1](artifacts/P5/attempts/primitive-control.pgbouncer-queue.pool-queue.n32.attempt-1.json) |
| `primitive-control.pgbouncer-queue.pool-queue.n200` | primitive-control | pgbouncer-queue | pool-queue | 200 | 1 | not-a-product-slo | sql ok 200, fail 0 | [a1](artifacts/P5/attempts/primitive-control.pgbouncer-queue.pool-queue.n200.attempt-1.json) |
| `primitive-control.pgbouncer-queue.pool-queue.n201` | primitive-control | pgbouncer-queue | pool-queue | 201 | 1 | not-a-product-slo | sql ok 201, fail 0 | [a1](artifacts/P5/attempts/primitive-control.pgbouncer-queue.pool-queue.n201.attempt-1.json) |
| `primitive-control.pgbouncer-queue.pool-timeout.n200` | primitive-control | pgbouncer-queue | pool-timeout | 200 | 1 | not-a-product-slo | script wall 134.035s | [a1](artifacts/P5/attempts/primitive-control.pgbouncer-queue.pool-timeout.n200.attempt-1.json) |
| `dirty-cardinality-probe.sweeper-overlap.dirty-20k.n1` | dirty-cardinality-probe | sweeper-overlap | dirty-20k | 1 | 3 | not-a-product-slo | a1: exit 1, remaining 20000/20000, 0.317s; a2: exit 1, remaining 20000/20000, 0.18s; a3: exit 1, remaining 20000/20000, 0.166s | [a1](artifacts/P5/attempts/dirty-cardinality-probe.sweeper-overlap.dirty-20k.n1.attempt-1.json) |
| `dirty-cardinality-probe.sweeper-overlap.dirty-1m.n1` | dirty-cardinality-probe | sweeper-overlap | dirty-1m | 1 | 1 | not-a-product-slo | a1: exit 1, remaining 1000000/1000000, 0.184s | [a1](artifacts/P5/attempts/dirty-cardinality-probe.sweeper-overlap.dirty-1m.n1.attempt-1.json) |

## 4. Product-realistic HTTP

Different-resource upload and normal-key CAS reached the k6 VUs. Across those 12 cells and three repeats the counters sum to 2xx 0, 409 0, 429 2169, 5xx 0, other 0. Per surface the 429 totals are tus 723, S3 723, and CAS 723, each `3+24+96+600`. The §0.4 different-resource 409 ratio is `0/2169 = 0`, inside the ≤5 % budget. The machine SLO is still RED because every counted response is 429, so the cell has zero product successes. Those 429 responses are the measured surface. They are not NOWAIT 409s. The upload routes use `throttle:vaulter-uploads` (package default 120/minute, keyed by authenticated actor id, otherwise by IP). The scenario sends `X-Load-Actor` and the published k6 summary does not retain `Retry-After` or the response body, so this report does not promote the middleware name from a compatible status to a captured header.

Live-patch overlap is also machine RED, with a different count: three setup 429s across three attempts at each of N=1, N=8, and N=32 (`0/3` 409). The N clients were not launched. Same-resource worst-case is the same setup failure, one 429 per attempt, and is labelled `not-a-5pct-budget` so those eight 429s are not charged to the ≤5 % budget. The same-resource NOWAIT ratio was not measured.

Collab issuance is machine RED at N=1 already: 723 responses are `other` (not 2xx, 409, 429, or 5xx), including the three N=1 repeats. The status does not grow into a new class at N=200. `POST nodes/{node}/collab/token` is feature-gated and is not on `throttle:vaulter-uploads`. The exact status code was not stored.

Disjoint subtree move is machine GREEN: 723/723 responses are 2xx at client N=1, 8, 32, and 200, three repeats, 409 ratio 0, 5xx 0. Overlapping subtree move is machine GREEN: 241/241 responses are 2xx at the same client sizes, one attempt each. No 409 was counted on either tree shape.

## 5. Primitive lock and pool controls

Advisory controls use client process counts up to 201. Every process file reported `ok`, and `fail` is 0. Try-lock `refused_count` is 0 at N=1, 8, 32, 200, and 201 on key `42001`. Because each transaction rolls back immediately, a refused count of 0 means this control did not record a concurrent false try-lock. It does not mean 201 backends held the lock together, and it does not measure `hashtext(scope|sha256)`.

PgBouncer `SHOW POOLS` / `SHOW STATS` on all 56 cell samples: `cl_waiting=0`, `maxwait=0`, `sv_active=0`, `avg_wait_time=0`. That includes `pool-queue` at client N=201, where 201/201 `psql` processes reported ok, and `pool-timeout` at client N=200.

The pool-timeout attempt records script wall clock 134.035 seconds and `waits.pgbouncer_wait_seconds` 134.035. The control starts 10 holders of `BEGIN; SELECT pg_sleep(130)` through PgBouncer and then 190 `SELECT 1` clients. The postgres snapshot for that cell has empty `locks` and empty `activity`. The 134.035 second figure is the script wall clock, which includes the 130 second sleep. It is not a non-zero `cl_waiting`, `maxwait`, or `avg_wait_time`. Those three pool-wait series stayed 0 at the sample instant.

## 6. Deadlock recomputation

Sum of `post.deadlocks - pre.deadlocks` across all 104 attempts is 0. WAL LSN cursors advanced on 17 attempts (dirty seeds, some tree moves, two live-patch attempts, two small CAS/collab attempts) with no deadlock-counter change. No separate deadlock-log line delta was published; the cursor field is a WAL LSN. A zero counter delta covers this run's sampled graph. It does not prove deadlock freedom for graphs this run did not execute.

## 7. Sweeper, SLO-70, and the 1m probe

SLO-70 (tier S, OP-44) requires sweep throughput ≥ 500 sessions/s on 20k expired rows, tick p95 ≤ 5 seconds, batch ≤ 1000 rows, and command RSS ≤ 256 MiB.

| Probe | Attempts | Seeded | Remaining | Exit | Elapsed | `tier_l_claim` | Batch rows | RSS |
|:--|--:|--:|--:|--:|--:|:--|:--|:--|
| dirty-20k | 3 | 20000 | 20000 | 1, 1, 1 | 0.317 s, 0.180 s, 0.166 s | false | not in artifact | not in artifact |
| dirty-1m | 1 | 1000000 | 1000000 | 1 | 0.184 s | false | not in artifact | not in artifact |

SLO-70 is not met: drained rows are 0, so throughput is 0. The command wall clock is under 5 seconds and is the time-to-failure, not a successful tick. Batch size and RSS were not captured, so those two SLO-70 clauses are unevaluated. Stderr is not in `artifacts/P5/sweeper/`.

The 1m row is a targeted cardinality probe on the tier-S host. `tier_l_claim` is false. It is not a tier-L SLO-72 run. Drain projection for 1m rows is not computable from 0 expired rows.

`SweepUploadsCommand` still materializes `$sessions->sweepable($now)->get()` with no limit (`packages/drive/src/Console/SweepUploadsCommand.php`, the `get()` inside the tick timing callback). That is the L-02 code fact (D8). The 1m probe failed in 0.184 seconds, faster than the first 20k attempt, which is not a completed materialization of 1e6 rows. P5 therefore does not confirm the RSS hypothesis and does not clear it.

## 8. L-02, L-06, and L-07

**L-02 — open.** SLO-70 is not met on the 20k probe. The unbounded `get()` is still in the command. Peak RSS and `vaulter_sweep_batch_rows` were not published, so the memory/batch failure mode is not the measured outcome. Any behavior change in `SweepUploadsCommand` stays behind D11 until VLT-MUT `vaulter-drive` reaches score ≥ 95. D31 allows additive metric seams; it does not authorize the batching fix.

**L-06 — open, unchanged.** P3 `db-profile.md` §4.1 observed the grant-scoped recursive CTE at 13 067 rows and 154.5 ms on fingerprint `879347013115918506`. P5 tree cells at client N≤200 on a 1019-node integrity snapshot returned only 2xx, with deadlock delta 0 and no sampled lock waiters. P5 artifacts do not contain `vaulter_tree_cte_anchors` or `vaulter_subtree_nodes_touched`. This is not the 1 000-anchor envelope. The existing debt is L-P3-05. D11 applies to a `MoveNodeAction` fix.

**L-07 — unconfirmed.** The int4-collision cells are machine `green` and response-level 429-only (241/241). The scenario calls complete only after a 2xx part, so those cells did not record a CAS-lock collision. Population incidence of false serialization was not observed: product CAS traffic stopped at HTTP 429, and `pg_locks` samples are empty. The primitive try-lock key is `42001`, not `hashtext(scope|sha256)`. A later dev slice may measure a real collision pair only after product requests reach `BlobLock`. D11's published text gates drive hot-path files; `BlobLock` is core. This report does not open a core lock change.

## 9. Recomputed guards

| Quantity | Recomputation | Result |
|:--|:--|:--|
| Different-resource 409 | `0 / 2169` upload-tus + upload-s3 + cas normal-key responses | 0 %, inside ≤5 %; cells remain RED for zero 2xx |
| Same-resource 409 | `0 / 8` setup responses; N clients not started | guard ratio not measured; excluded from the 5 % budget |
| HTTP 5xx | sum of attempt `http_5xx` and status `5xx` | 0 |
| Deadlock delta | sum of per-attempt counter deltas | 0 |
| Pool wait | max `cl_waiting`, max `maxwait`, max `avg_wait_time` over 56 samples | 0, 0, 0 |
| Pool-timeout wall | attempt `statuses.elapsed_seconds` | 134.035 s script clock, holders sleep 130 s |

## 10. Debt map

Bounded rows live in [`findings/P5-concurrency.md`](findings/P5-concurrency.md).

| Cells | Debt |
|:--|:--|
| Product-realistic upload, CAS normal-key, and live-patch RED rows; same-resource setup 429; int4-collision 429-only `green` | **L-P5-01** |
| Collab issuance RED rows | **L-P5-02** |
| dirty-20k and dirty-1m command failures; L-02 stays open | **L-P5-03** |
| Empty lock samples and zero pool-wait series, including `pool-timeout` and `pool-queue` N=201 | **L-P5-04** |
| Tree 2xx rows | no new debt; **L-06** stays with **L-P3-05** |

## 11. Limitations

- RED product cells are valid evidence. They are not missing runs.
- Empty `pg_locks` plus a default `granted: true` cannot be read as “no waiter” or “lock granted”.
- Pool samples were taken once per cell and are all zeros, including the timeout control.
- Collab `other` has no stored HTTP status.
- Sweeper stderr, batch histogram, and RSS are absent.
- The fingerprint worktree was dirty. Image digests are in the run manifest.
- No `packages/*` change is part of this interpretation.
