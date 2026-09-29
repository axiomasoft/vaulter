# P7 chaos findings — bounded debt

**Date:** 2026-09-23
**Source run:** `7b255888-43bb-4071-b106-45f70a7f475c`
**Profile:** [`chaos-report.md`](../chaos-report.md)
**Disposition:** evidence valid; integrity joins green on harness objects; product fault paths unevaluated; no package fix in this item

## Inherited verdicts

| ID | Verdict | Why this run does not close it |
|:--|:--|:--|
| **L-02** | Open | Sweeper was a labelled GET probe, not `SweepUploadsCommand`. Remaining=20000 from P5.3 is untouched. D11 still gates a drive batching slice. |
| **L-04** | Open | No `claimDue()` lock-set. P6 5k/50k/500k probes remain the evidence. |
| **L-05** | Open | No endpoint-cardinality curve. P6 200/1000 endpoint 1-row times remain. |
| **L-06** | Open | No subtree-move CTE series. P3 `db-profile.md` §4.1 and P5 disjoint moves stay the evidence. |
| **L-07** | Unconfirmed | No CAS `hashtext` population. P5 int4-collision 429s are unchanged. |
| **L-P6-01…04** | Open | Empty snapshot `failed_jobs=0`. This family did not relay, so sticky `failed_jobs`, `dispatch_uuids=0`, leftover fan-out, and unrecovered Redis pending are not remeasured. |

Cardinality probes stay `tier_ml_slo=false`. They are not P7 claims.

## New debt

| ID | Severity | Confidence | Owner | Affected invariant | Evidence | Candidate dev slice | Gate |
|:--|:--|:--|:--|:--|:--|:--|:--|
| **L-P7-01** | High | High | `load` matrix (`p7_live_matrix.py`) | product HTTP/Artisan path equals the named cell (tus PATCH, proxied putPart/complete, CAS promote, abort, sweeper, upload commit, outbox) | all 51 cells; attempt `injector`+`http_outcome`; `run_window` GET `s3-fault-proxy:19000` | Drive a real authenticated operation per surface after barrier ACK. Keep plane labels. Do not treat GET 503 as tus/S3/CAS proof. | P7 re-run after harness change; no D11 until a drive behavior change |
| **L-P7-02** | High | High | `load` fixtures / `sample_entity()` | DB↔object join uses package tables (`v_upload_sessions`, `v_upload_parts`, `v_blobs`, `v_outbox`) and a nonempty disposable snapshot | `database.jsonl` 77× `entity=outbox status=absent`; `integrity-before.json` all `v_*=0`; query is `public.webhook_outbox` | Point the sampler at `v_*`. Seed run-prefixed upload/CAS/outbox fixtures (P7.2 command) before the matrix. Join both directions by durable id. | P7 re-run |
| **L-P7-03** | High | High | `load` primary-transition branch | measured commit after fence/promote, not a hardcoded `accepted`/`committed` | `primary-transition.upload-commit.promote`, `outbox-mutation.promote`, `lock-holder.fence-then-promote` attempt-1; `p7_live_matrix.py` overwrite | After promote+PgBouncer route, run the named mutation and record HTTP from that call. Keep `T_*` as measured. Do not call this AWS/host HA. | P7 re-run; no automatic HA product choice |
| **L-P7-04** | Medium | High for `dd` 8 MiB / `free_bytes=0`; high that retry cells match enospc | `load` ENOSPC lane, then `media` only to measure `stage()` | ENOSPC short/failed write on `Sha256ContentStore::stage()` / spill; retry after remount is a second product attempt | `storage-control.spill.enospc`, `cas-stage.enospc`, `write.enospc`, `spill.enospc-then-retry`, `cas-stage.enospc-then-retry`; `temp.jsonl` `write_length=8388608` | Keep dedicated tmpfs. Call the real stage/spill path. Record requested vs written bytes. Retry cells must differ from the first attempt. | P7 re-run. A `stage()` return-check is a later `media` slice, not this item |
| **L-P7-05** | Medium | High | `load` stand / P8 scrape | `/ready` is 200 at baseline so a recovery-tail 503 is attributable; PromQL uses `vaulter-sut` | `readiness.jsonl` `ready_ok=false` 154/154; `prometheus/*.json` `job=vaulter-sut` value `0` | Restore P8.6 heartbeat + `/metrics` scrape on the chaos compose. Publish `/ready` body/which check failed. Keep `/up` vs `/ready` separate. | P7 re-run; P8.8 may list this scrape gap only |

## RED and anomalous cells

There is no evidence-invalid cell and no checksum-mismatch attempt. Machine `evidence=valid` does not mean product integrity GREEN.

Availability `degraded`/`down` on injected GET is expected and maps to **L-P7-01** (wrong operation), not to a product error-budget miss.

Anomalous non-invalid cells:

- All protocol-control moment triples (`pre-upstream` / `after-accept` / `on-recovery`) are GET `rejected`/`degraded`/`rejected`. Mapped to **L-P7-01**.
- Primary-transition `accepted`/`committed` with object-to-db `matched` on the harness key. Mapped to **L-P7-03**. Not a duplicate logical commit.
- Storage-control retry cells identical to non-retry. Mapped to **L-P7-04**.
- `/ready` false on baseline and recovery. Mapped to **L-P7-05**, not to a fault-window outage.

No integrity mismatch between `integrity-before.json` and `integrity-after.json`. That check does not block this synthesis. It also does not prove part/staged/orphan cleanup of package objects.

## P8.8 routing

Only gaps this run exposed: lone Prometheus `up` query, `vaulter-sut=0`, no recorded T_detect/T_restore series, `/ready` never 200. Do not add tracing targets from labelled GET probes. An empty P7 target contribution is a valid D41 no-op input.
