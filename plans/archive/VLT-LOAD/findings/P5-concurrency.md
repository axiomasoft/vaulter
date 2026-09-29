# P5 concurrency findings — bounded debt

**Date:** 2026-09-23
**Source run:** `2aeca12f-6180-4595-8466-31397c4ed15d`
**Profile:** [`concurrency-report.md`](../concurrency-report.md)
**Disposition:** evidence valid; product SLO mixed; no package fix in this item

## Inherited verdicts

| ID | Verdict | Why this run does not close it |
|:--|:--|:--|
| **L-02** | Open | 20k sweep exited 1 with remaining=20000 on all three attempts. SLO-70 throughput is 0. Batch rows and RSS were not captured. `SweepUploadsCommand` still uses unbounded `sweepable()->get()`. D11 blocks a drive behavior change until VLT-MUT `vaulter-drive` score ≥ 95. |
| **L-06** | Open | P5 disjoint and overlapping moves at client N≤200 were 2xx-only with deadlock delta 0. No CTE-anchor series was published. Cardinality evidence stays P3 `db-profile.md` §4.1 and **L-P3-05**. |
| **L-07** | Unconfirmed | int4-collision responses are 241/241 HTTP 429. No product request recorded a `hashtext` lock collision, and primitive key `42001` is a different control. False serialization was not proved and was not shown to be absent in the product population. |

The 1m probe is labelled `tier_l_claim=false`. It is not a tier-L claim.

## New debt

| ID | Severity | Confidence | Owner | Affected SLO | Evidence | Candidate dev slice | D11 |
|:--|:--|:--|:--|:--|:--|:--|:--|
| **L-P5-01** | High | High for status counts; medium for limiter identity | `load` first | SLO-40, SLO-43, §0.4 409 budget (ratio 0 and unevaluated as a success path); live-patch and same-resource cells | `cell-verdicts.json` RED upload/CAS/live-patch rows; attempt `statuses`; k6 `p5-product-realistic.cas-lock.normal-key.n200-a1.json` | Rerun after each VU has its own authenticated actor. Keep one status and `Retry-After`. Do not change the package rate limit from this report. | n/a for a `load/` measurement fix. A later `vaulter-uploads` behavior change is core, not the drive D11 gate. |
| **L-P5-02** | Medium | High that the class is `other` at N=1; low for the exact status | `load` capture, then `documents` if the status is a product reject | collab issuance cells, machine RED | `product-realistic.collab-issuance.issuance-burst.n1` attempts 1–3 (`other` 3) and `.n200` (`other` 600) | Persist the HTTP status. Confirm the collaboration flag and document id before reading N=200 as saturation. | n/a until a product change is specified |
| **L-P5-03** | High | High for exit, remaining, and missing RSS; low for the exception class | `load` capture now; `drive` `SweepUploadsCommand` only after D11 | SLO-70 not met; SLO-72 not claimed | `artifacts/P5/sweeper/dirty-cardinality-probe.sweeper-overlap.dirty-20k.n1.a1.json` (and a2, a3); `dirty-1m.n1.a1.json` | Persist stderr, exit code, PHP peak, and `vaulter_sweep_batch_rows` for a command that finishes. Batching remains a separate D11-gated slice. | **D11** for any `SweepUploadsCommand` behavior change. Metric capture is allowed by D31. |
| **L-P5-04** | Medium | High | `load` collector | pool boundary and lock-wait attribution; not a product SLO row | `observations.jsonl` sources `pg_locks`, `pgbouncer_pools`, `pgbouncer_stats`; `postgres/primitive-control.pgbouncer-queue.pool-timeout.n200.a1.json` | Sample `SHOW POOLS` during the holder window. Record an empty `pg_locks` list as empty. Stop defaulting `granted` to true. Keep the fingerprinted pool settings. | n/a |

## RED and anomalous cells

Every machine-RED cell maps to one debt:

- Upload tus/S3 different-resource N=1/8/32/200 and CAS normal-key N=1/8/32/200 map to **L-P5-01** (429, zero 409, zero 2xx).
- Live-patch N=1/8/32 maps to **L-P5-01** (setup 429, clients not started).
- Collab issuance N=1/8/32/200 maps to **L-P5-02**.

Anomalous non-RED cells:

- int4-collision N=1/8/32/200 is machine `green` and 429-only. Mapped to **L-P5-01** and the L-07 verdict above.
- Same-resource N=1/8/32/200 is `not-a-5pct-budget` with one setup 429. Mapped to **L-P5-01**. The ≤5 % budget does not apply.
- `pool-timeout.n200` and `pool-queue.n201` are `not-a-product-slo`. Attribution is **L-P5-04**.
- dirty-20k and dirty-1m are `not-a-product-slo`. The failed sweep is **L-P5-03**.

No integrity mismatch between `integrity-before.json` and `integrity-after.json` was found. That check does not block this synthesis.
