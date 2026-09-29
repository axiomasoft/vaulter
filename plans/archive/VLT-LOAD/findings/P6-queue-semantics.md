# P6 queue findings — bounded debt

**Date:** 2026-09-23
**Source run:** `59dfb7e1-bd32-4c87-ab6f-eb8f409607af`
**Profile:** [`queue-report.md`](../queue-report.md)
**Disposition:** evidence valid; product SLO red; no package fix in this item

## Inherited verdicts

| ID | Verdict | Why this run does not close it |
|:--|:--|:--|
| **L-04** | Open | `claimDue()` still locks every due row. 5k claimed 5102 rows in 2.038 s, RSS 52.5 MiB, lock-set 5102 > 1000. 50k finished 50004 rows in 17.345 s, RSS 202.5 MiB, `tier_ml_slo=false`. 500k retry exited 255 with an empty body. SLO-64 is not met. D11 does not gate this core file. |
| **L-05** | Open | `subscribedEndpoints()` still materializes all active endpoints per outbox row. 1-row relay 24.962 ms at 200 endpoints and 34.827 ms at 1000, density `1`, `tier_ml_slo=false`. Product HTTP/event is the actual fan-out on the 20-endpoint envelope. Volume tick 513.88 rows/s is 100 claimed rows, not a 1000-endpoint drain. |

The 50k and 500k probes are labelled `tier_ml_slo=false`. They are not SLO-65/66 claims.

## New debt

| ID | Severity | Confidence | Owner | Affected SLO | Evidence | Candidate dev slice | D11 |
|:--|:--|:--|:--|:--|:--|:--|:--|
| **L-P6-01** | High | High that `failed_jobs=2` is sticky and that the product gate is RED; medium for the two job classes | `load` first (reset/capture); `core` only after the two rows are identified | SLO-58, SLO-61 error budget; not package `dead` | `cell-verdicts.json` 36 RED rows; every product attempt `failed_jobs=2`; transitions.jsonl unique `{2}` | Persist `failed_jobs` payload/class. Truncate the table between cells or count a delta. Do not treat the two rows as `v_webhook_deliveries` DLQ. | n/a (measurement). A later `DeliverWebhook` change is core. |
| **L-P6-02** | High | High | `load` lifecycle ledger | SLO-61 jobs/s and queue-time p95 unevaluated | product `dispatch_uuids=0`; overlap `ledger_repair` text | Record `queued` with the same run/cell context the worker sees, or join dispatcher+worker UUIDs. Recompute enqueue→processing. | n/a |
| **L-P6-03** | Medium | High for HTTP/event=2.000 on drained density `1` and HTTP/event=21.000 on drained density `all`; medium for leftover-endpoint identity | `load` fixtures / sink ledger | fan-out proof; no-duplicate product verdict blocked | product HTTP/event column; integrity `endpoints=1`; sink run_id mix 50345/27204 | Restore endpoints to zero leftover subscribers. Stamp sink ledger with the request run_id. Keep serial and concurrent UUID windows disjoint. | n/a |
| **L-P6-04** | Medium | High for unrecovered pending and for the 100-row volume tick | `load` matrix / P8 scrape | SLO-58 drain of 10k; burst recovery; PromQL unused | `i200.fall.2xx` pending 4190/1407/1541; `tier-s-volume.json` relayed 100; `prometheus/up.json` vaulter-sut=0; empty `relayed.json` | Drain or sample beyond 20 s for `all`×10×, or label those cells recovered-timeout. Relay the 10k with repeated ticks. Keep `/metrics` up for the run. | n/a |

## RED and anomalous cells

Every machine-RED product cell maps to **L-P6-01** (`failed_jobs=2`).

Unrecovered high fan-out / hang leftovers additionally map to **L-P6-04**: `i20.f0.2xx`; `i20.fall.{2xx,slow,over-timeout}`; `i100.f0.{2xx,500}`; `i100.fall.{2xx a1, slow, over-timeout}`; `i200.f0.{2xx,500}`; `i200.fall.*`; hang cells with `recovered=N`.

HTTP/event anomalies map to **L-P6-03**: density `1` drained 2.000; density `all` drained 21.000; density `0` not zero.

Overlap/state/cardinality are `not-a-product-slo`:

- Serial and concurrent 2-dispatch/1-HTTP map to **L-P6-02** (UUID source) and are not a product exactly-once proof.
- Backoff 10 s, dead, disable `failure_count=10` / `is_active=false` match config. No new debt.
- 200/1000 endpoint 1-row curve maps to **L-05**.
- 50k/500k due probes map to **L-04**.

No integrity mismatch between `integrity-before.json` and `integrity-after.json`. That check does not block this synthesis.
