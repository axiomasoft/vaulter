# P6 queue report — tier S

**Date:** 2026-09-23
**Evidence run:** `59dfb7e1-bd32-4c87-ab6f-eb8f409607af`
**Tier:** S
**Cells:** 46 valid, 118 attempts
**Verify digest:** `d48c8ffd07fb11847cff3a5f80d4c56cfe9b2e3459ed78ce251ab9a9b66f3766` (recomputed from `artifacts/P6/`, byte-stable second verify, matches P6.3)
**Authoritative inputs:** [`artifacts/P6/run-manifest.json`](artifacts/P6/run-manifest.json), [`artifacts/P6/matrix.json`](artifacts/P6/matrix.json), [`artifacts/P6/cell-verdicts.json`](artifacts/P6/cell-verdicts.json), [`artifacts/P6/database/tier-s-volume.json`](artifacts/P6/database/tier-s-volume.json)

Relay rows/s is not jobs/s. Queue wait is enqueue→processing, not delivery age. Declared fan-out (`0`/`1`/`all`) is the density axis; measured fan-out is HTTP receives / events on that cell. Cardinality probes are labelled `tier_ml_slo=false` and are not tier M/L SLO.

## 1. Environment and evidence validity

| Field | Value | Source |
|:--|:--|:--|
| Tier | S | run manifest |
| Git SHA | `e262c73b7b44ea4f138e4625337226505429768b` | `fingerprint.git_sha` |
| Worktree | dirty at fingerprint time | `git_dirty=true` |
| Clock | `2026-09-23T07:32:57Z` | run manifest; `postgres_now` same |
| Laravel | 13.17.0 | manifest |
| Redis `retry_after` | 90 s | manifest |
| Worker timeout / tries | 60 s / 1 | manifest |
| Worker command | `queue:work redis --queue=load-health,default --sleep=1 --tries=1` | manifest |
| Worker PIDs | `1231461`, `1245632` on every attempt | attempt `worker_pids` |
| Webhook HTTP / connect | 5 s / 2 s | manifest `webhook` |
| `max_attempts` / backoff_1 / disable_after | 5 / 10 s / 10 | manifest |
| Integrity before | deliveries 2, endpoints 1, outbox 4 | `integrity-before.json` |
| Integrity after | same counts and md5 | `integrity-after.json` |
| Prometheus `up` HTTP | 200 | `artifacts/P6/prometheus/up.status` |
| Prometheus `up` series | prometheus=1, `vaulter-sut` (app:8080)=0 | `up.json` |
| Relayed PromQL | empty vector | `prometheus/relayed.json` |

The matrix covers all 46 `matrix.json` cells. Attempt counts match three product-realistic repetitions and one for overlap, state-machine, and cardinality. `cell-verdicts.json` marks every cell `evidence=valid`. Integrity before equals integrity after. The published family is the second matrix attempt; rejected first-attempt files remain under `artifacts/P6/attempts/rejected/`.

`failed_jobs` is 2 on every product attempt, including the first sample of the 30-minute window (`t=0.4`). The gate paints a product cell RED when `failed_jobs > 0`. That is valid RED, not missing evidence. Package `business_dead` stays 0 on product cells; Laravel `failed_jobs` is not the package DLQ.

This item does not change retry_after, worker timeout, webhook timeouts, or accepted SLO.

## 2. How a row is counted

- Product outcomes come from the attempt file: `events_sent`, `http_receives`, `dispatch_uuids`, `failed_jobs`, `business_failed`, `business_dead`, `recovered`, `backlog_slope`, `redis_before` / `redis_after`.
- `dispatch_uuids` counts lifecycle rows with `source=queued`. Those events fire in the dispatcher, which does not see the worker context file, so product cells store `dispatch_uuids=0`. Queue wait (enqueue→processing) is therefore unevaluated on the product plane. Overlap duplicate controls take UUIDs from `processing`/`processed` instead.
- Slow and hang bursts send `min(rate, 4)` events (`burst_events` in `load/gates/p6_live_matrix.py`). They are not 100/200-event envelopes.
- Burst recovery waits 20 s for Redis pending=reserved=0; the 1× steady window waits 60 s after 1800 s. `recovered=false` with empty `redis_after` means the wait timed out and the queue later read empty, or the orchestrator flushed after the wait.
- Fan-out for SLO discussion is HTTP/event on that cell, not endpoint count. Density `all` with 20 endpoints plus one leftover active endpoint from the integrity snapshot (endpoints=1) produces HTTP/event=21.000 when the 500 sink drained.
- Volume 10k/5k is a separate authoritative tick in `tier-s-volume.json`, not a product cell.

## 3. Full matrix

Machine SLO `red` on `product-realistic` means `failed_jobs > 0` (and, for the 30-minute 1× window, would also trip if backlog slope > 100). Slope on that window is 0. `not-a-product-slo` is overlap, state-machine, or a labelled cardinality probe.

| Cell | Plane | Rate | Fan-out | Sink | Reps | Machine SLO | Counted outcomes | Evidence |
|:--|:--|:--|:--|:--|---:|:--|:--|:--|
| `product-realistic.relay.i20.f0.2xx` | product-realistic | 20 | 0 | 2xx | 3 | red | events 60, HTTP 9, HTTP/event 0.150; dispatch_uuids 0; failed_jobs 2; business_failed 12/10/12; business_dead 0; recovered N/N/N; redis default pending after 10/12/10 | [a1](artifacts/P6/attempts/product-realistic.relay.i20.f0.2xx.attempt-1.json) |
| `product-realistic.relay.i20.f0.500` | product-realistic | 20 | 0 | 500 | 3 | red | events 60, HTTP 91, HTTP/event 1.517; dispatch_uuids 0; failed_jobs 2; business_failed 4/4/4; business_dead 0; recovered Y/Y/Y; redis default pending after 0/0/0 | [a1](artifacts/P6/attempts/product-realistic.relay.i20.f0.500.attempt-1.json) |
| `product-realistic.relay.i20.f0.slow-under-timeout` | product-realistic | 20 | 0 | slow-under-timeout | 3 | red | events 12, HTTP 12, HTTP/event 1.000; dispatch_uuids 0; failed_jobs 2; business_failed 4/4/4; business_dead 0; recovered Y/Y/Y; redis default pending after 0/0/0 | [a1](artifacts/P6/attempts/product-realistic.relay.i20.f0.slow-under-timeout.attempt-1.json) |
| `product-realistic.relay.i20.f0.over-timeout` | product-realistic | 20 | 0 | over-timeout | 3 | red | events 12, HTTP 12, HTTP/event 1.000; dispatch_uuids 0; failed_jobs 2; business_failed 4/4/4; business_dead 0; recovered Y/Y/Y; redis default pending after 0/0/0 | [a1](artifacts/P6/attempts/product-realistic.relay.i20.f0.over-timeout.attempt-1.json) |
| `product-realistic.relay.i20.f1.2xx` | product-realistic | 20 | 1 | 2xx | 3 | red | events 23760, HTTP 47520, HTTP/event 2.000; dispatch_uuids 0; failed_jobs 2; business_failed 4/4/4; business_dead 0; recovered Y/Y/Y; redis default pending after 0/0/0 | [a1](artifacts/P6/attempts/product-realistic.relay.i20.f1.2xx.attempt-1.json) |
| `product-realistic.relay.i20.f1.500` | product-realistic | 20 | 1 | 500 | 3 | red | events 60, HTTP 120, HTTP/event 2.000; dispatch_uuids 0; failed_jobs 2; business_failed 24/24/24; business_dead 0; recovered Y/Y/Y; redis default pending after 0/0/0 | [a1](artifacts/P6/attempts/product-realistic.relay.i20.f1.500.attempt-1.json) |
| `product-realistic.relay.i20.f1.slow-under-timeout` | product-realistic | 20 | 1 | slow-under-timeout | 3 | red | events 12, HTTP 24, HTTP/event 2.000; dispatch_uuids 0; failed_jobs 2; business_failed 4/4/4; business_dead 0; recovered Y/Y/Y; redis default pending after 0/0/0 | [a1](artifacts/P6/attempts/product-realistic.relay.i20.f1.slow-under-timeout.attempt-1.json) |
| `product-realistic.relay.i20.f1.over-timeout` | product-realistic | 20 | 1 | over-timeout | 3 | red | events 12, HTTP 16, HTTP/event 1.333; dispatch_uuids 0; failed_jobs 2; business_failed 11/12/12; business_dead 0; recovered N/Y/Y; redis default pending after 0/0/0 | [a1](artifacts/P6/attempts/product-realistic.relay.i20.f1.over-timeout.attempt-1.json) |
| `product-realistic.relay.i20.fall.2xx` | product-realistic | 20 | all | 2xx | 3 | red | events 60, HTTP 858, HTTP/event 14.300; dispatch_uuids 0; failed_jobs 2; business_failed 12/4/4; business_dead 0; recovered N/Y/Y; redis default pending after 410/0/0 | [a1](artifacts/P6/attempts/product-realistic.relay.i20.fall.2xx.attempt-1.json) |
| `product-realistic.relay.i20.fall.500` | product-realistic | 20 | all | 500 | 3 | red | events 60, HTTP 1260, HTTP/event 21.000; dispatch_uuids 0; failed_jobs 2; business_failed 404/404/404; business_dead 0; recovered Y/Y/Y; redis default pending after 0/0/0 | [a1](artifacts/P6/attempts/product-realistic.relay.i20.fall.500.attempt-1.json) |
| `product-realistic.relay.i20.fall.slow-under-timeout` | product-realistic | 20 | all | slow-under-timeout | 3 | red | events 12, HTTP 69, HTTP/event 5.750; dispatch_uuids 0; failed_jobs 2; business_failed 4/4/4; business_dead 0; recovered N/N/N; redis default pending after 60/61/59 | [a1](artifacts/P6/attempts/product-realistic.relay.i20.fall.slow-under-timeout.attempt-1.json) |
| `product-realistic.relay.i20.fall.over-timeout` | product-realistic | 20 | all | over-timeout | 3 | red | events 12, HTTP 10, HTTP/event 0.833; dispatch_uuids 0; failed_jobs 2; business_failed 12/12/10; business_dead 0; recovered N/N/N; redis default pending after 74/76/76 | [a1](artifacts/P6/attempts/product-realistic.relay.i20.fall.over-timeout.attempt-1.json) |
| `product-realistic.relay.i100.f0.2xx` | product-realistic | 100 | 0 | 2xx | 3 | red | events 300, HTTP 9, HTTP/event 0.030; dispatch_uuids 0; failed_jobs 2; business_failed 12/12/10; business_dead 0; recovered N/N/N; redis default pending after 90/92/92 | [a1](artifacts/P6/attempts/product-realistic.relay.i100.f0.2xx.attempt-1.json) |
| `product-realistic.relay.i100.f0.500` | product-realistic | 100 | 0 | 500 | 3 | red | events 300, HTTP 9, HTTP/event 0.030; dispatch_uuids 0; failed_jobs 2; business_failed 12/10/12; business_dead 0; recovered N/N/N; redis default pending after 90/92/90 | [a1](artifacts/P6/attempts/product-realistic.relay.i100.f0.500.attempt-1.json) |
| `product-realistic.relay.i100.f0.slow-under-timeout` | product-realistic | 100 | 0 | slow-under-timeout | 3 | red | events 12, HTTP 69, HTTP/event 5.750; dispatch_uuids 0; failed_jobs 2; business_failed 4/4/4; business_dead 0; recovered Y/Y/Y; redis default pending after 0/0/0 | [a1](artifacts/P6/attempts/product-realistic.relay.i100.f0.slow-under-timeout.attempt-1.json) |
| `product-realistic.relay.i100.f0.over-timeout` | product-realistic | 100 | 0 | over-timeout | 3 | red | events 12, HTTP 12, HTTP/event 1.000; dispatch_uuids 0; failed_jobs 2; business_failed 4/4/4; business_dead 0; recovered Y/Y/Y; redis default pending after 0/0/0 | [a1](artifacts/P6/attempts/product-realistic.relay.i100.f0.over-timeout.attempt-1.json) |
| `product-realistic.relay.i100.f1.2xx` | product-realistic | 100 | 1 | 2xx | 3 | red | events 300, HTTP 600, HTTP/event 2.000; dispatch_uuids 0; failed_jobs 2; business_failed 4/4/4; business_dead 0; recovered Y/Y/Y; redis default pending after 0/0/0 | [a1](artifacts/P6/attempts/product-realistic.relay.i100.f1.2xx.attempt-1.json) |
| `product-realistic.relay.i100.f1.500` | product-realistic | 100 | 1 | 500 | 3 | red | events 300, HTTP 600, HTTP/event 2.000; dispatch_uuids 0; failed_jobs 2; business_failed 104/104/104; business_dead 0; recovered Y/Y/Y; redis default pending after 0/0/0 | [a1](artifacts/P6/attempts/product-realistic.relay.i100.f1.500.attempt-1.json) |
| `product-realistic.relay.i100.f1.slow-under-timeout` | product-realistic | 100 | 1 | slow-under-timeout | 3 | red | events 12, HTTP 24, HTTP/event 2.000; dispatch_uuids 0; failed_jobs 2; business_failed 4/4/4; business_dead 0; recovered Y/Y/Y; redis default pending after 0/0/0 | [a1](artifacts/P6/attempts/product-realistic.relay.i100.f1.slow-under-timeout.attempt-1.json) |
| `product-realistic.relay.i100.f1.over-timeout` | product-realistic | 100 | 1 | over-timeout | 3 | red | events 12, HTTP 17, HTTP/event 1.417; dispatch_uuids 0; failed_jobs 2; business_failed 10/12/12; business_dead 0; recovered Y/Y/N; redis default pending after 0/0/0 | [a1](artifacts/P6/attempts/product-realistic.relay.i100.f1.over-timeout.attempt-1.json) |
| `product-realistic.relay.i100.fall.2xx` | product-realistic | 100 | all | 2xx | 3 | red | events 300, HTTP 4217, HTTP/event 14.057; dispatch_uuids 0; failed_jobs 2; business_failed 12/4/4; business_dead 0; recovered N/Y/Y; redis default pending after 2090/0/0 | [a1](artifacts/P6/attempts/product-realistic.relay.i100.fall.2xx.attempt-1.json) |
| `product-realistic.relay.i100.fall.500` | product-realistic | 100 | all | 500 | 3 | red | events 300, HTTP 6300, HTTP/event 21.000; dispatch_uuids 0; failed_jobs 2; business_failed 2004/2004/2004; business_dead 0; recovered Y/Y/Y; redis default pending after 0/0/0 | [a1](artifacts/P6/attempts/product-realistic.relay.i100.fall.500.attempt-1.json) |
| `product-realistic.relay.i100.fall.slow-under-timeout` | product-realistic | 100 | all | slow-under-timeout | 3 | red | events 12, HTTP 68, HTTP/event 5.667; dispatch_uuids 0; failed_jobs 2; business_failed 4/4/4; business_dead 0; recovered N/N/N; redis default pending after 61/61/61 | [a1](artifacts/P6/attempts/product-realistic.relay.i100.fall.slow-under-timeout.attempt-1.json) |
| `product-realistic.relay.i100.fall.over-timeout` | product-realistic | 100 | all | over-timeout | 3 | red | events 12, HTTP 9, HTTP/event 0.750; dispatch_uuids 0; failed_jobs 2; business_failed 12/10/12; business_dead 0; recovered N/N/N; redis default pending after 74/76/74 | [a1](artifacts/P6/attempts/product-realistic.relay.i100.fall.over-timeout.attempt-1.json) |
| `product-realistic.relay.i200.f0.2xx` | product-realistic | 200 | 0 | 2xx | 3 | red | events 600, HTTP 9, HTTP/event 0.015; dispatch_uuids 0; failed_jobs 2; business_failed 12/12/10; business_dead 0; recovered N/N/N; redis default pending after 190/192/192 | [a1](artifacts/P6/attempts/product-realistic.relay.i200.f0.2xx.attempt-1.json) |
| `product-realistic.relay.i200.f0.500` | product-realistic | 200 | 0 | 500 | 3 | red | events 600, HTTP 9, HTTP/event 0.015; dispatch_uuids 0; failed_jobs 2; business_failed 12/12/10; business_dead 0; recovered N/N/N; redis default pending after 190/192/192 | [a1](artifacts/P6/attempts/product-realistic.relay.i200.f0.500.attempt-1.json) |
| `product-realistic.relay.i200.f0.slow-under-timeout` | product-realistic | 200 | 0 | slow-under-timeout | 3 | red | events 12, HTTP 70, HTTP/event 5.833; dispatch_uuids 0; failed_jobs 2; business_failed 6/4/4; business_dead 0; recovered Y/Y/Y; redis default pending after 0/0/0 | [a1](artifacts/P6/attempts/product-realistic.relay.i200.f0.slow-under-timeout.attempt-1.json) |
| `product-realistic.relay.i200.f0.over-timeout` | product-realistic | 200 | 0 | over-timeout | 3 | red | events 12, HTTP 12, HTTP/event 1.000; dispatch_uuids 0; failed_jobs 2; business_failed 4/4/4; business_dead 0; recovered Y/Y/Y; redis default pending after 0/0/0 | [a1](artifacts/P6/attempts/product-realistic.relay.i200.f0.over-timeout.attempt-1.json) |
| `product-realistic.relay.i200.f1.2xx` | product-realistic | 200 | 1 | 2xx | 3 | red | events 600, HTTP 1200, HTTP/event 2.000; dispatch_uuids 0; failed_jobs 2; business_failed 4/4/4; business_dead 0; recovered Y/Y/Y; redis default pending after 0/0/0 | [a1](artifacts/P6/attempts/product-realistic.relay.i200.f1.2xx.attempt-1.json) |
| `product-realistic.relay.i200.f1.500` | product-realistic | 200 | 1 | 500 | 3 | red | events 600, HTTP 1200, HTTP/event 2.000; dispatch_uuids 0; failed_jobs 2; business_failed 204/204/204; business_dead 0; recovered Y/Y/Y; redis default pending after 0/0/0 | [a1](artifacts/P6/attempts/product-realistic.relay.i200.f1.500.attempt-1.json) |
| `product-realistic.relay.i200.f1.slow-under-timeout` | product-realistic | 200 | 1 | slow-under-timeout | 3 | red | events 12, HTTP 24, HTTP/event 2.000; dispatch_uuids 0; failed_jobs 2; business_failed 4/4/4; business_dead 0; recovered Y/Y/Y; redis default pending after 0/0/0 | [a1](artifacts/P6/attempts/product-realistic.relay.i200.f1.slow-under-timeout.attempt-1.json) |
| `product-realistic.relay.i200.f1.over-timeout` | product-realistic | 200 | 1 | over-timeout | 3 | red | events 12, HTTP 16, HTTP/event 1.333; dispatch_uuids 0; failed_jobs 2; business_failed 12/12/12; business_dead 0; recovered N/N/Y; redis default pending after 0/0/0 | [a1](artifacts/P6/attempts/product-realistic.relay.i200.f1.over-timeout.attempt-1.json) |
| `product-realistic.relay.i200.fall.2xx` | product-realistic | 200 | all | 2xx | 3 | red | events 600, HTTP 5574, HTTP/event 9.290; dispatch_uuids 0; failed_jobs 2; business_failed 14/4/4; business_dead 0; recovered N/N/N; redis default pending after 4190/1407/1541 | [a1](artifacts/P6/attempts/product-realistic.relay.i200.fall.2xx.attempt-1.json) |
| `product-realistic.relay.i200.fall.500` | product-realistic | 200 | all | 500 | 3 | red | events 600, HTTP 7401, HTTP/event 12.335; dispatch_uuids 0; failed_jobs 2; business_failed 2526/2386/2149; business_dead 0; recovered N/N/N; redis default pending after 1602/1746/1990 | [a1](artifacts/P6/attempts/product-realistic.relay.i200.fall.500.attempt-1.json) |
| `product-realistic.relay.i200.fall.slow-under-timeout` | product-realistic | 200 | all | slow-under-timeout | 3 | red | events 12, HTTP 67, HTTP/event 5.583; dispatch_uuids 0; failed_jobs 2; business_failed 4/4/4; business_dead 0; recovered N/N/N; redis default pending after 61/62/60 | [a1](artifacts/P6/attempts/product-realistic.relay.i200.fall.slow-under-timeout.attempt-1.json) |
| `product-realistic.relay.i200.fall.over-timeout` | product-realistic | 200 | all | over-timeout | 3 | red | events 12, HTTP 9, HTTP/event 0.750; dispatch_uuids 0; failed_jobs 2; business_failed 12/12/10; business_dead 0; recovered N/N/N; redis default pending after 74/76/76 | [a1](artifacts/P6/attempts/product-realistic.relay.i200.fall.over-timeout.attempt-1.json) |
| `overlap-control.relay-retry.barrier.relay2-retry2` | overlap-control | — | — | — | 1 | not-a-product-slo | relay_ticks 2, retry_ticks 2, span 0.021259s, duration 0.395s | [a1](artifacts/P6/attempts/overlap-control.relay-retry.barrier.relay2-retry2.attempt-1.json) |
| `overlap-control.duplicate-job.serial.same-delivery` | overlap-control | — | — | — | 1 | not-a-product-slo | dispatches 2, HTTP 1, queue_uuids 4 unique 4; delivery_id 172034 | [a1](artifacts/P6/attempts/overlap-control.duplicate-job.serial.same-delivery.attempt-1.json) |
| `overlap-control.duplicate-job.concurrent.same-delivery` | overlap-control | — | — | — | 1 | not-a-product-slo | dispatches 2, HTTP 1, queue_uuids 4 unique 4; delivery_id 172035 | [a1](artifacts/P6/attempts/overlap-control.duplicate-job.concurrent.same-delivery.attempt-1.json) |
| `state-machine-control.retry.backoff.force-due` | state-machine-control | — | — | — | 1 | not-a-product-slo | configured_backoff 10s; recorded_before_force_due True; forced status pending delivery 172036 | [a1](artifacts/P6/attempts/state-machine-control.retry.backoff.force-due.attempt-1.json) |
| `state-machine-control.retry.dead.force-due` | state-machine-control | — | — | — | 1 | not-a-product-slo | status dead | [a1](artifacts/P6/attempts/state-machine-control.retry.dead.force-due.attempt-1.json) |
| `state-machine-control.endpoint.disable.after-dead` | state-machine-control | — | — | — | 1 | not-a-product-slo | is_active False, failure_count 10; 4328 active=false failures=10 | [a1](artifacts/P6/attempts/state-machine-control.endpoint.disable.after-dead.attempt-1.json) |
| `dirty-cardinality-probe.endpoints.n200.attempt1` | dirty-cardinality-probe | — | 1 | 2xx | 1 | not-a-product-slo | seeded 200, relayed 1 in 24.962 ms; snapshot_restored; tier_ml_slo false | [a1](artifacts/P6/attempts/dirty-cardinality-probe.endpoints.n200.attempt1.attempt-1.json) |
| `dirty-cardinality-probe.endpoints.n1000.attempt1` | dirty-cardinality-probe | — | 1 | 2xx | 1 | not-a-product-slo | seeded 1000, relayed 1 in 34.827 ms; snapshot_restored; tier_ml_slo false | [a1](artifacts/P6/attempts/dirty-cardinality-probe.endpoints.n1000.attempt1.attempt-1.json) |
| `dirty-cardinality-probe.due-rows.n50000.attempt1` | dirty-cardinality-probe | — | — | 2xx | 1 | not-a-product-slo | seeded 50000, retried 50004 in 17.345s, peak 202.5 MiB, redis pending 50003; tier_ml_slo false | [a1](artifacts/P6/attempts/dirty-cardinality-probe.due-rows.n50000.attempt1.attempt-1.json) |
| `dirty-cardinality-probe.due-rows.n500000.attempt1` | dirty-cardinality-probe | — | — | 2xx | 1 | not-a-product-slo | seeded 500000, retry exit 255, ok False, empty stdout/stderr; duration 10.38s; tier_ml_slo false | [a1](artifacts/P6/attempts/dirty-cardinality-probe.due-rows.n500000.attempt1.attempt-1.json) |

## 4. Rows/s versus jobs/s

| Quantity | Recomputation | Result |
|:--|:--|:--|
| Volume outbox tick | 100 rows / 0.194596143 s | **513.88 rows/s**, tick 0.195 s. Claim limit 100; 10k rows were seeded and 100 were relayed. This is not a 10k drain and is not jobs/s. |
| Volume retry tick | 5102 due rows / 2.038155441 s | **2503.24 rows/s**, tick 2.038 s, peak RSS 52.5 MiB. Workers were paused; Redis was flushed after the tick. Lock-set is the unbounded `claimDue()` result (5102). |
| 1× steady ingress | 23720 events / 1802.652 s | **13.16 events/s** against a 20 events/s target. The loop inserts 20, relays 100, then sleeps toward 1 s. |
| 1× measured HTTP/event | 47440 / 23720 | **2.000**. Declared density is `1`. |
| Product `dispatch_uuids` | sum over 36×3 product attempts | **0**. Jobs/s is not computed from this ledger. HTTP/s is not substituted for jobs/s. |
| Queue wait p95 | enqueue→processing UUID pairs | **unevaluated** on the product plane. |
| Redis max pending | `queue/redis.jsonl` 216 samples | **4190** (`i200.fall.2xx` a1). Separate pending/delayed/reserved; delayed stayed 0 on product after-snapshots. |

## 5. Backlog slope, recovery, occupancy

The 30-minute 1× window is `product-realistic.relay.i20.f1.2xx` attempt 1. `database/transitions.jsonl` has 59 samples from `t=0.4` to `t=1778.7`. `outbox_pending` is 0 on the first and last sample, so slope last−first = 0. `failed_jobs` is 2 on every sample. Redis default pending/reserved/delayed are 0 on those samples. Attempts 2 and 3 of that cell are 1 s bursts of 20 events, not extra 30-minute windows.

5×/10× bursts are bounded (1 s insert + ≤20 s wait). `i200.fall.2xx` did not recover in 20 s: pending after 4190 / 1407 / 1541. `i200.fall.500` also left 1602–1990 pending. Two worker PIDs are present on every cell. Occupancy is inferred from hang/slow leftover pending, not from a per-worker busy histogram. Hang uses HTTP timeout 5 s; worker `--timeout` 60 s is below `retry_after` 90 s.

End-to-end mutation→subscriber p95 ≤ 10 s is not in the attempt files. It is not computed from delivery `created_at`.

## 6. Sink profiles and multiplicity

Declared density `1` at 2xx/500/slow is HTTP/event **2.000** wherever the burst drained (i20/i100/i200). Declared `all` + 500 that drained is HTTP/event **21.000** (i20 and i100). That matches 20 seeded endpoints plus the leftover integrity endpoint, not a 20-only fan-out. Declared `0` is not HTTP/event 0: leftover POSTs remain (i20.f0.2xx 9/60; i20.f0.500 91/60).

Serial duplicate: 2 dispatches, 1 HTTP, 4 unique processing/processed UUIDs, delivery `172034`. Concurrent duplicate: 2 dispatches, 1 HTTP, 4 unique UUIDs, delivery `172035`. Serial no-op and concurrent both produced one POST. Two UUID strings are shared across the two attempt files (`6ce18f87-…`, `a3317dda-…`); that is ledger overlap, not a second HTTP. A no-duplicate product verdict is not made: product `dispatch_uuids` is 0, so queue UUID multiplicity is missing on that plane.

Barrier overlap: 2 relay + 2 retry ticks, span 0.021259 s, duration 0.395 s. No throughput claim.

Sink ledger has 77549 lines: 50345 stamped `59dfb7e1-…` and 27204 stamped `53e8508a-…` (rejected first attempt). Attempt `http_receives` uses a line offset during the published run, not a run_id filter. Status totals on published event ids: 204=62219, 500=15209, 504=109.

## 7. Retry, dead, disable, worker lease

Backoff control: configured 10 s (`webhook_backoff_1`), `recorded_before_force_due=true`, force-due left delivery `172036` `pending`. The 10 s wait was not spent; due was forced.

Dead control: status `dead`. Package `business_dead` on product cells stayed 0 because those cells did not run the force-due machine.

Disable: `failure_count=10`, `is_active=false`, endpoint `4328 active=false failures=10`. Config `webhook_disable_after=10`. The published control waited until 10; a burst of 10 without that wait is not this row.

Worker lease versus package retry: `DeliverWebhook` has no job `$tries/$timeout`; HTTP failure is recorded in `v_webhook_deliveries` and the job returns successfully, so `failed_jobs` is not package DLQ. The two Laravel `failed_jobs` rows are a separate worker-failure count that the product SLO gate treats as red.

## 8. L-04 and L-05

**L-04 — open.** `WebhookRetryDispatcher::claimDue()` still does `dueForRetry()->lockForUpdate()->get()` with no limit (`packages/core/src/Webhooks/WebhookRetryDispatcher.php`). Authoritative 5k: 5102 rows claimed, tick 2.038 s (SLO-64 p95 ≤ 2.0 s fails), RSS 52.5 MiB (≤ 256 MiB), throughput 2503 rows/s (≥ 500). Lock-set 5102 > 1000. Labelled 50k probe: 50004 rows in 17.345 s (2883 rows/s), RSS 202.5 MiB, Redis pending 50003 because workers were paused; `tier_ml_slo=false`, not SLO-65. Labelled 500k probe: seed 500000 ok, retry exit 255, empty stdout/stderr, duration 10.38 s, no RSS. That is the L-04 observation, not a missing probe. No tier L claim.

**L-05 — open.** `subscribedEndpoints()` still loads every active endpoint in PHP per outbox row (`OutboxRelay.php`). Endpoint probes used density `1` (one subscriber) while scanning 200 and 1000 active rows: 1 row in 24.962 ms at 200 endpoints, 34.827 ms at 1000. `tier_ml_slo=false`. Product fan-out is the HTTP/event column, not those probes. Volume tick at the 20-endpoint envelope is 513.88 rows/s for 100 claimed rows. This is not a 1000-endpoint 2000 rows/s drain.

## 9. SLO-55…66

| SLO | Plane | Verdict on this run |
|:--|:--|:--|
| SLO-55 | outbox stage overhead | **Unevaluated.** P6 did not time `OutboxRecorder::stage()` inside a host mutation. |
| SLO-56, SLO-57 | M/L | **Excluded.** |
| SLO-58 | product-realistic + 10k volume + 1× window | **Not met.** Machine RED from `failed_jobs=2`. Slope 0 on 30 min. One 100-row tick 0.195 s and 513.88 rows/s; 10k was not drained. |
| SLO-59, SLO-60 | M/L | **Excluded.** 200/1000 endpoint probes are not those SLOs. |
| SLO-61 | product-realistic, 2 workers | **Not met.** `failed_jobs=2`. Jobs/s and queue-time p95 unevaluated (`dispatch_uuids=0`). Two PIDs present. |
| SLO-62, SLO-63 | M/L | **Excluded.** |
| SLO-64 | 5k due volume | **Not met.** Throughput 2503 ≥ 500; RSS 52.5 ≤ 256; tick 2.038 > 2.0; lock-set 5102 > 1000. |
| SLO-65, SLO-66 | M/L | **Not claimed.** 50k/500k probes are labelled `tier_ml_slo=false`. |

## 10. Debt map

Bounded rows live in [`findings/P6-queue-semantics.md`](findings/P6-queue-semantics.md).

| Cells | Debt |
|:--|:--|
| All 36 product-realistic RED rows (`failed_jobs=2`) | **L-P6-01** |
| Product `dispatch_uuids=0`; queue wait unevaluated | **L-P6-02** |
| HTTP/event ≠ declared density 0/1; leftover endpoint; sink mix with rejected run | **L-P6-03** |
| Unrecovered 5×/10× `all` and hang leftovers; 10k volume only a 100-row tick; Prometheus sut `up=0` | **L-P6-04** |
| 5k lock-set 5102, 50k/500k probes | **L-04** stays open |
| 200/1000 endpoint 1-row curve; product HTTP/event | **L-05** stays open |
| Serial vs concurrent 2 dispatch / 1 HTTP | no extra product duplicate POST; UUID intersection is a limitation of **L-P6-02**/**L-P6-03** |

## 11. Limitations

- RED product cells are valid evidence. They are not missing runs.
- `failed_jobs=2` is sticky; it is not a per-cell increment.
- Jobs/s is not computed. Relay rows/s is not jobs/s.
- Queue wait is unevaluated on the product plane.
- Prometheus `vaulter-sut` was down; empty `vaulter_outbox_relayed_total` is not a zero drain.
- Sink ledger mixes the rejected first attempt's `run_id`.
- Duplicate-control UUIDs come from `processing`/`processed` only.
- Cardinality probes are not tier M/L.
- Fingerprint worktree was dirty. Image digests are in the run manifest.
- P7 owns Redis/DB/network kill windows; this report does not pre-judge them.
- No `packages/*` change is part of this interpretation.
