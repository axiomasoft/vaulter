# P7 chaos report — tier S

**Date:** 2026-09-23
**Evidence run:** `7b255888-43bb-4071-b106-45f70a7f475c`
**Tier:** S
**Cells:** 51 valid, 77 attempts
**Verify digest:** `b51a143828512efb5bb85c5d4f05863677545ae183f2ee3b9f407e3100895a62` (recomputed from `artifacts/P7/`, byte-stable with P7.3)
**Authoritative inputs:** [`artifacts/P7/run-manifest.json`](artifacts/P7/run-manifest.json), [`artifacts/P7/matrix.json`](artifacts/P7/matrix.json), [`artifacts/P7/cell-verdicts.json`](artifacts/P7/cell-verdicts.json), [`artifacts/P7/cells.json`](artifacts/P7/cells.json), [`artifacts/P7/attempts.jsonl`](artifacts/P7/attempts.jsonl), [`artifacts/P7/reconcile.jsonl`](artifacts/P7/reconcile.jsonl)

Availability is the injected-window probe outcome. Integrity is checksum/state join, not a 5xx average. Timing is measured harness-window duration. `cells.json` `rto_verdict` is `false` on every cell. This report does not invent RTO/RPO or treat MinIO/Toxiproxy as AWS.

## 1. Environment and evidence validity

| Field | Value | Source |
|:--|:--|:--|
| Tier | S | run manifest |
| Git SHA | `142d1d4398ba4e300fbab04afa9d58cf05e36cdd` | `git_sha` |
| Worktree | dirty at fingerprint time | `git_dirty=true` |
| Clock | `2026-09-23T11:45:48Z` | run manifest |
| Injector version | `p7.1-lane-stub` | manifest |
| Injector config digest | `e099291ae27b7cdfc1405877a4b5778808fa41269e875aaf5d60b87ae06586b0` | manifest |
| Config fingerprint | `ef13ea95198263e0a19e0fba9a62df9db5373bb2187c33c01c04ed1be23e9a84` | manifest |
| PgBouncer mode | transaction | manifest |
| MinIO prefix | `tmp/uploads/7b255888-43bb-4071-b106-45f70a7f475c` | manifest |
| tmpfs | `/var/tmp/vaulter-chaos` | manifest |
| App / MinIO / PgBouncer / PG / Prom images | `vaulter-load-app`, `minio/minio:latest`, `edoburu/pgbouncer:latest`, `vaulter-load-postgres-profile:16-hypopg-1.4.1`, `prom/prometheus:latest` | manifest `images` |
| Integrity before | all `v_*` counts 0, `ungranted_locks` 0, md5 `62334250848dc12acf346a725d6b9481` | `database/integrity-before.json` |
| Integrity after | identical object and md5 | `database/integrity-after.json` |
| Ungranted locks after | 0 | `database/locks.json` |
| Prometheus `up` | prometheus=1, `vaulter-sut` (app:8080)=0 | every `prometheus/*.json` sample |

The matrix covers all 51 `matrix.json` cells. Attempt counts match three product-realistic repetitions and one for protocol, transport, primary-transition, and storage-control. `cell-verdicts.json` marks every cell `evidence=valid`. All 77 attempt files bind `run_id=7b255888-43bb-4071-b106-45f70a7f475c`. Integrity before equals integrity after. `ledger.jsonl` has 77 of each phase `baseline → armed → fault → revert → recovery → reconcile`. All 77 barriers have `observed=true`. Secret markers used by verify were empty.

Fidelity labels (closed plane enum, RAG:— repository / RAG:✅ Toxiproxy/PG docs):

| Plane | Fidelity | What this run actually did |
|:--|:--|:--|
| `product-realistic` | **harness probe, not product HTTP/Artisan** | `mc pipe` a unique object, arm injector, `GET s3-fault-proxy:19000/vaulter-load/<key>`, revert. Cell names (`tus-patch`, `s3-put-part`, `cas-promote`, `abort`, `sweeper`, `upload-commit`, `outbox-mutation`, `lock-holder`) are matrix labels. |
| `protocol-control` | load-only S3 503 proxy | Same GET probe. `pre-upstream` / `after-accept` / `on-recovery` arm modes exist; the probe does not distinguish them. Not an AWS Complete/Abort. |
| `transport-control` | Toxiproxy TCP | Same GET probe through minio/pgbouncer proxies. Latency/timeout/reset/down. Not AWS or PG failover. |
| `primary-transition` | isolated PG16 fence/promote lab | Injector `pg-failover`. HTTP/durable are hardcoded `accepted`/`committed` in `p7_live_matrix.py`. Timing is the failover script window (~17 s converge). |
| `storage-control` | dedicated tmpfs ENOSPC | `dd` 8 MiB `fill.bin` until ENOSPC (`write_length=8388608`, `free_bytes=0`). Not `Sha256ContentStore::stage()` / `php://temp`. `enospc-then-retry` is the same recorded outcome as `enospc`. |

This item does not change injectors, matrix, or packages.

## 2. How a row is counted

- Attempt file fields: `http_outcome`, `availability`, `durable_state`, `checksum`, `evidence`, `t_detect_ns`, `t_restore_ns`, `t_converge_ns`, `injector`.
- `http_outcome` is `outcome(GET status)`: 503→`rejected`, 0→`timeout`, 2xx→`accepted`, else `error`. Storage-control and primary-transition then overwrite availability/durable (and primary overwrites HTTP).
- `t_detect_ns` = fault ledger mark − window start. `t_restore_ns` = window end − fault mark. `t_converge_ns` = window end − start. These include arm/barrier/PUT/GET/revert/reconcile, not product retry.
- Integrity join: object-to-db compares MinIO `mc cat` SHA-256 to the PUT checksum. db-to-object is `rejected-persisted` when `durable_state=rejected`, else that checksum status. `sample_entity()` queries `public.webhook_outbox` (the table is `v_outbox`); every database.jsonl row is `entity=outbox`, `status=absent`, `entity_id=<run_id>`.
- Expected injected-window errors are the GET 503/timeout/error during `fault`. Recovery-tail is `/up` and `/ready` after revert (`readiness.jsonl` phase `recovery`) plus `ledger.recovered=true`.
- `rto_verdict` is never computed. A 5xx count is not an integrity verdict.

## 3. Full matrix

`Evidence` is the machine field in `cell-verdicts.json`. There is no evidence-invalid cell. Availability `degraded`/`down` is the injected window, not a product SLO red.

| Cell | Plane | Surface | Fault | Injector | Reps | Evidence | Counted outcomes | T_detect / T_restore / T_converge (s) | Evidence |
|:--|:--|:--|:--|:--|---:|:--|:--|:--|:--|
| `product-realistic.tus-patch.503` | product-realistic | tus-patch | 503 | s3-fault-proxy | 3 | valid | http rejected/rejected/rejected; avail degraded/degraded/degraded; durable rejected/rejected/rejected | detect 3.437–3.587; restore 3.284–3.448; converge 6.721–6.978 | [a1](artifacts/P7/attempts/product-realistic.tus-patch.503.attempt-1.json) |
| `product-realistic.tus-patch.tcp-reset` | product-realistic | tus-patch | tcp-reset | toxiproxy | 3 | valid | http timeout/timeout/timeout; avail down/down/down; durable rejected/rejected/rejected | detect 3.370–3.465; restore 3.413–3.535; converge 6.878–6.916 | [a1](artifacts/P7/attempts/product-realistic.tus-patch.tcp-reset.attempt-1.json) |
| `product-realistic.s3-put-part.503` | product-realistic | s3-put-part | 503 | s3-fault-proxy | 3 | valid | http rejected/rejected/rejected; avail degraded/degraded/degraded; durable rejected/rejected/rejected | detect 3.475–3.575; restore 3.229–3.357; converge 6.774–6.906 | [a1](artifacts/P7/attempts/product-realistic.s3-put-part.503.attempt-1.json) |
| `product-realistic.s3-put-part.timeout` | product-realistic | s3-put-part | timeout | toxiproxy | 3 | valid | http timeout/timeout/timeout; avail down/down/down; durable rejected/rejected/rejected | detect 3.411–3.458; restore 3.375–3.551; converge 6.833–6.963 | [a1](artifacts/P7/attempts/product-realistic.s3-put-part.timeout.attempt-1.json) |
| `product-realistic.s3-complete.503` | product-realistic | s3-complete | 503 | s3-fault-proxy | 3 | valid | http rejected/rejected/rejected; avail degraded/degraded/degraded; durable rejected/rejected/rejected | detect 3.394–3.562; restore 3.243–3.453; converge 6.674–6.869 | [a1](artifacts/P7/attempts/product-realistic.s3-complete.503.attempt-1.json) |
| `product-realistic.cas-promote.503` | product-realistic | cas-promote | 503 | s3-fault-proxy | 3 | valid | http rejected/rejected/rejected; avail degraded/degraded/degraded; durable rejected/rejected/rejected | detect 3.433–3.522; restore 3.291–3.386; converge 6.724–6.853 | [a1](artifacts/P7/attempts/product-realistic.cas-promote.503.attempt-1.json) |
| `product-realistic.cas-promote.tcp-down` | product-realistic | cas-promote | tcp-down | toxiproxy | 3 | valid | http error/error/error; avail degraded/degraded/degraded; durable rejected/rejected/rejected | detect 3.344–3.556; restore 3.518–3.574; converge 6.863–7.075 | [a1](artifacts/P7/attempts/product-realistic.cas-promote.tcp-down.attempt-1.json) |
| `product-realistic.abort.503` | product-realistic | abort | 503 | s3-fault-proxy | 3 | valid | http rejected/rejected/rejected; avail degraded/degraded/degraded; durable rejected/rejected/rejected | detect 3.363–3.567; restore 3.243–3.352; converge 6.606–6.887 | [a1](artifacts/P7/attempts/product-realistic.abort.503.attempt-1.json) |
| `product-realistic.sweeper.503` | product-realistic | sweeper | 503 | s3-fault-proxy | 3 | valid | http rejected/rejected/rejected; avail degraded/degraded/degraded; durable rejected/rejected/rejected | detect 3.310–3.549; restore 3.274–3.381; converge 6.678–6.921 | [a1](artifacts/P7/attempts/product-realistic.sweeper.503.attempt-1.json) |
| `product-realistic.upload-patch.connection-loss` | product-realistic | upload-patch | connection-loss | toxiproxy | 3 | valid | http error/error/error; avail degraded/degraded/degraded; durable rejected/rejected/rejected | detect 3.372–3.526; restore 3.446–3.551; converge 6.869–7.077 | [a1](artifacts/P7/attempts/product-realistic.upload-patch.connection-loss.attempt-1.json) |
| `product-realistic.upload-commit.connection-loss` | product-realistic | upload-commit | connection-loss | toxiproxy | 3 | valid | http error/error/error; avail degraded/degraded/degraded; durable rejected/rejected/rejected | detect 3.376–3.494; restore 3.342–3.582; converge 6.719–6.990 | [a1](artifacts/P7/attempts/product-realistic.upload-commit.connection-loss.attempt-1.json) |
| `product-realistic.outbox-mutation.connection-loss` | product-realistic | outbox-mutation | connection-loss | toxiproxy | 3 | valid | http error/error/error; avail degraded/degraded/degraded; durable rejected/rejected/rejected | detect 3.301–3.572; restore 3.415–3.432; converge 6.716–6.993 | [a1](artifacts/P7/attempts/product-realistic.outbox-mutation.connection-loss.attempt-1.json) |
| `product-realistic.lock-holder.disconnect` | product-realistic | lock-holder | disconnect | toxiproxy | 3 | valid | http error/error/error; avail degraded/degraded/degraded; durable rejected/rejected/rejected | detect 3.377–3.530; restore 3.427–3.485; converge 6.862–7.010 | [a1](artifacts/P7/attempts/product-realistic.lock-holder.disconnect.attempt-1.json) |
| `protocol-control.tus-patch.503.pre-upstream` | protocol-control | tus-patch | 503.pre-upstream | s3-fault-proxy | 1 | valid | http rejected; avail degraded; durable rejected | detect 3.384; restore 3.395; converge 6.779 | [a1](artifacts/P7/attempts/protocol-control.tus-patch.503.pre-upstream.attempt-1.json) |
| `protocol-control.tus-patch.503.after-accept` | protocol-control | tus-patch | 503.after-accept | s3-fault-proxy | 1 | valid | http rejected; avail degraded; durable rejected | detect 3.379; restore 3.369; converge 6.749 | [a1](artifacts/P7/attempts/protocol-control.tus-patch.503.after-accept.attempt-1.json) |
| `protocol-control.tus-patch.503.on-recovery` | protocol-control | tus-patch | 503.on-recovery | s3-fault-proxy | 1 | valid | http rejected; avail degraded; durable rejected | detect 3.486; restore 3.339; converge 6.825 | [a1](artifacts/P7/attempts/protocol-control.tus-patch.503.on-recovery.attempt-1.json) |
| `protocol-control.s3-put-part.503.pre-upstream` | protocol-control | s3-put-part | 503.pre-upstream | s3-fault-proxy | 1 | valid | http rejected; avail degraded; durable rejected | detect 3.473; restore 3.257; converge 6.730 | [a1](artifacts/P7/attempts/protocol-control.s3-put-part.503.pre-upstream.attempt-1.json) |
| `protocol-control.s3-put-part.503.after-accept` | protocol-control | s3-put-part | 503.after-accept | s3-fault-proxy | 1 | valid | http rejected; avail degraded; durable rejected | detect 3.465; restore 3.302; converge 6.767 | [a1](artifacts/P7/attempts/protocol-control.s3-put-part.503.after-accept.attempt-1.json) |
| `protocol-control.s3-put-part.503.on-recovery` | protocol-control | s3-put-part | 503.on-recovery | s3-fault-proxy | 1 | valid | http rejected; avail degraded; durable rejected | detect 3.433; restore 3.373; converge 6.806 | [a1](artifacts/P7/attempts/protocol-control.s3-put-part.503.on-recovery.attempt-1.json) |
| `protocol-control.s3-complete.503.pre-upstream` | protocol-control | s3-complete | 503.pre-upstream | s3-fault-proxy | 1 | valid | http rejected; avail degraded; durable rejected | detect 3.423; restore 3.314; converge 6.737 | [a1](artifacts/P7/attempts/protocol-control.s3-complete.503.pre-upstream.attempt-1.json) |
| `protocol-control.s3-complete.503.after-accept` | protocol-control | s3-complete | 503.after-accept | s3-fault-proxy | 1 | valid | http rejected; avail degraded; durable rejected | detect 3.490; restore 3.325; converge 6.815 | [a1](artifacts/P7/attempts/protocol-control.s3-complete.503.after-accept.attempt-1.json) |
| `protocol-control.s3-complete.503.on-recovery` | protocol-control | s3-complete | 503.on-recovery | s3-fault-proxy | 1 | valid | http rejected; avail degraded; durable rejected | detect 3.484; restore 3.342; converge 6.827 | [a1](artifacts/P7/attempts/protocol-control.s3-complete.503.on-recovery.attempt-1.json) |
| `protocol-control.cas-promote.503.pre-upstream` | protocol-control | cas-promote | 503.pre-upstream | s3-fault-proxy | 1 | valid | http rejected; avail degraded; durable rejected | detect 3.463; restore 3.313; converge 6.777 | [a1](artifacts/P7/attempts/protocol-control.cas-promote.503.pre-upstream.attempt-1.json) |
| `protocol-control.cas-promote.503.after-accept` | protocol-control | cas-promote | 503.after-accept | s3-fault-proxy | 1 | valid | http rejected; avail degraded; durable rejected | detect 3.378; restore 3.393; converge 6.771 | [a1](artifacts/P7/attempts/protocol-control.cas-promote.503.after-accept.attempt-1.json) |
| `protocol-control.cas-promote.503.on-recovery` | protocol-control | cas-promote | 503.on-recovery | s3-fault-proxy | 1 | valid | http rejected; avail degraded; durable rejected | detect 3.511; restore 3.385; converge 6.896 | [a1](artifacts/P7/attempts/protocol-control.cas-promote.503.on-recovery.attempt-1.json) |
| `protocol-control.abort.503.pre-upstream` | protocol-control | abort | 503.pre-upstream | s3-fault-proxy | 1 | valid | http rejected; avail degraded; durable rejected | detect 3.498; restore 3.259; converge 6.758 | [a1](artifacts/P7/attempts/protocol-control.abort.503.pre-upstream.attempt-1.json) |
| `protocol-control.abort.503.after-accept` | protocol-control | abort | 503.after-accept | s3-fault-proxy | 1 | valid | http rejected; avail degraded; durable rejected | detect 3.377; restore 3.311; converge 6.688 | [a1](artifacts/P7/attempts/protocol-control.abort.503.after-accept.attempt-1.json) |
| `protocol-control.abort.503.on-recovery` | protocol-control | abort | 503.on-recovery | s3-fault-proxy | 1 | valid | http rejected; avail degraded; durable rejected | detect 3.483; restore 3.295; converge 6.778 | [a1](artifacts/P7/attempts/protocol-control.abort.503.on-recovery.attempt-1.json) |
| `protocol-control.sweeper.503.pre-upstream` | protocol-control | sweeper | 503.pre-upstream | s3-fault-proxy | 1 | valid | http rejected; avail degraded; durable rejected | detect 3.409; restore 3.356; converge 6.765 | [a1](artifacts/P7/attempts/protocol-control.sweeper.503.pre-upstream.attempt-1.json) |
| `protocol-control.sweeper.503.after-accept` | protocol-control | sweeper | 503.after-accept | s3-fault-proxy | 1 | valid | http rejected; avail degraded; durable rejected | detect 3.526; restore 3.344; converge 6.871 | [a1](artifacts/P7/attempts/protocol-control.sweeper.503.after-accept.attempt-1.json) |
| `protocol-control.sweeper.503.on-recovery` | protocol-control | sweeper | 503.on-recovery | s3-fault-proxy | 1 | valid | http rejected; avail degraded; durable rejected | detect 3.390; restore 3.417; converge 6.807 | [a1](artifacts/P7/attempts/protocol-control.sweeper.503.on-recovery.attempt-1.json) |
| `transport-control.tus-patch.latency` | transport-control | tus-patch | latency | toxiproxy | 1 | valid | http error; avail degraded; durable rejected | detect 3.510; restore 3.722; converge 7.232 | [a1](artifacts/P7/attempts/transport-control.tus-patch.latency.attempt-1.json) |
| `transport-control.tus-patch.timeout` | transport-control | tus-patch | timeout | toxiproxy | 1 | valid | http timeout; avail down; durable rejected | detect 3.517; restore 3.342; converge 6.859 | [a1](artifacts/P7/attempts/transport-control.tus-patch.timeout.attempt-1.json) |
| `transport-control.tus-patch.reset` | transport-control | tus-patch | reset | toxiproxy | 1 | valid | http timeout; avail down; durable rejected | detect 3.391; restore 3.564; converge 6.954 | [a1](artifacts/P7/attempts/transport-control.tus-patch.reset.attempt-1.json) |
| `transport-control.tus-patch.down` | transport-control | tus-patch | down | toxiproxy | 1 | valid | http error; avail degraded; durable rejected | detect 3.486; restore 3.450; converge 6.935 | [a1](artifacts/P7/attempts/transport-control.tus-patch.down.attempt-1.json) |
| `transport-control.s3-put-part.latency` | transport-control | s3-put-part | latency | toxiproxy | 1 | valid | http error; avail degraded; durable rejected | detect 3.342; restore 3.710; converge 7.052 | [a1](artifacts/P7/attempts/transport-control.s3-put-part.latency.attempt-1.json) |
| `transport-control.s3-put-part.reset` | transport-control | s3-put-part | reset | toxiproxy | 1 | valid | http timeout; avail down; durable rejected | detect 3.509; restore 3.585; converge 7.094 | [a1](artifacts/P7/attempts/transport-control.s3-put-part.reset.attempt-1.json) |
| `transport-control.upload-commit.timeout` | transport-control | upload-commit | timeout | toxiproxy | 1 | valid | http error; avail degraded; durable rejected | detect 3.367; restore 3.515; converge 6.883 | [a1](artifacts/P7/attempts/transport-control.upload-commit.timeout.attempt-1.json) |
| `transport-control.upload-commit.reset` | transport-control | upload-commit | reset | toxiproxy | 1 | valid | http error; avail degraded; durable rejected | detect 3.495; restore 3.441; converge 6.937 | [a1](artifacts/P7/attempts/transport-control.upload-commit.reset.attempt-1.json) |
| `transport-control.outbox-mutation.reset` | transport-control | outbox-mutation | reset | toxiproxy | 1 | valid | http error; avail degraded; durable rejected | detect 3.402; restore 3.517; converge 6.919 | [a1](artifacts/P7/attempts/transport-control.outbox-mutation.reset.attempt-1.json) |
| `transport-control.outbox-mutation.down` | transport-control | outbox-mutation | down | toxiproxy | 1 | valid | http error; avail degraded; durable rejected | detect 3.468; restore 3.526; converge 6.994 | [a1](artifacts/P7/attempts/transport-control.outbox-mutation.down.attempt-1.json) |
| `transport-control.lock-holder.reset` | transport-control | lock-holder | reset | toxiproxy | 1 | valid | http error; avail degraded; durable rejected | detect 3.393; restore 3.624; converge 7.017 | [a1](artifacts/P7/attempts/transport-control.lock-holder.reset.attempt-1.json) |
| `transport-control.pgbouncer.reconnect` | transport-control | pgbouncer | reconnect | toxiproxy | 1 | valid | http error; avail degraded; durable rejected | detect 3.697; restore 3.558; converge 7.255 | [a1](artifacts/P7/attempts/transport-control.pgbouncer.reconnect.attempt-1.json) |
| `primary-transition.upload-commit.promote` | primary-transition | upload-commit | promote | pg-failover | 1 | valid | http accepted; avail degraded; durable committed | detect 5.905; restore 11.106; converge 17.011 | [a1](artifacts/P7/attempts/primary-transition.upload-commit.promote.attempt-1.json) |
| `primary-transition.outbox-mutation.promote` | primary-transition | outbox-mutation | promote | pg-failover | 1 | valid | http accepted; avail degraded; durable committed | detect 5.828; restore 11.155; converge 16.982 | [a1](artifacts/P7/attempts/primary-transition.outbox-mutation.promote.attempt-1.json) |
| `primary-transition.lock-holder.fence-then-promote` | primary-transition | lock-holder | fence-then-promote | pg-failover | 1 | valid | http accepted; avail degraded; durable committed | detect 5.774; restore 11.187; converge 16.961 | [a1](artifacts/P7/attempts/primary-transition.lock-holder.fence-then-promote.attempt-1.json) |
| `storage-control.spill.enospc` | storage-control | spill | enospc | tmpfs-enospc | 1 | valid | http error; avail degraded; durable rejected | detect 3.566; restore 3.312; converge 6.878 | [a1](artifacts/P7/attempts/storage-control.spill.enospc.attempt-1.json) |
| `storage-control.cas-stage.enospc` | storage-control | cas-stage | enospc | tmpfs-enospc | 1 | valid | http error; avail degraded; durable rejected | detect 3.454; restore 3.255; converge 6.708 | [a1](artifacts/P7/attempts/storage-control.cas-stage.enospc.attempt-1.json) |
| `storage-control.write.enospc` | storage-control | write | enospc | tmpfs-enospc | 1 | valid | http error; avail degraded; durable rejected | detect 3.399; restore 3.335; converge 6.734 | [a1](artifacts/P7/attempts/storage-control.write.enospc.attempt-1.json) |
| `storage-control.spill.enospc-then-retry` | storage-control | spill | enospc-then-retry | tmpfs-enospc | 1 | valid | http error; avail degraded; durable rejected | detect 3.556; restore 3.388; converge 6.944 | [a1](artifacts/P7/attempts/storage-control.spill.enospc-then-retry.attempt-1.json) |
| `storage-control.cas-stage.enospc-then-retry` | storage-control | cas-stage | enospc-then-retry | tmpfs-enospc | 1 | valid | http error; avail degraded; durable rejected | detect 3.546; restore 3.383; converge 6.929 | [a1](artifacts/P7/attempts/storage-control.cas-stage.enospc-then-retry.attempt-1.json) |

## 4. Availability versus integrity

Injected-window HTTP (77 attempts): `rejected` 36, `error` 29, `timeout` 9, `accepted` 3. The three `accepted` rows are the primary-transition hardcodes, not measured 2xx from upload-commit/outbox/lock-holder.

Availability: `degraded` 68, `down` 9, `up` 0 during fault. `down` is GET status 0 (timeout/reset on minio toxics). `degraded` is 503 or the storage/primary overwrite.

Integrity on this snapshot:

| Check | Recomputation | Result |
|:--|:--|:--|
| Checksum mismatch | 0 of 77 attempts `evidence=invalid`; 77 unique SHA-256 | No visible corrupt object among harness PUTs |
| Object-to-db reconcile | 77 `matched` | MinIO bytes equal the PUT digest after revert |
| DB-to-object reconcile | 74 `rejected-persisted`, 3 `matched` | The 3 matches are primary-transition `durable=committed` labels, not `v_*` rows |
| Phantom product commit | `v_nodes=v_blobs=v_files=v_upload_sessions=v_upload_parts=v_outbox=0` before and after | No product row appeared or disappeared |
| Duplicate logical commit | no product session/blob/version ids | Unevaluated on the product plane |
| Split-brain / ambiguity | no `ambiguous`/`unresolved` reconcile status | None recorded |
| Ungranted locks | 0 | `locks.json` |
| Cross-run objects | prefix `tmp/uploads/<run_id>/`; 77 keys; prefix removed before after-snapshot | Counts restored |

Integrity is **not RED** on the recorded joins. It is also **not a product proof**: the durable identity is a harness MinIO key, and the DB entity is an absent `webhook_outbox` lookup. A missing product corruption is not the same as proving CAS/upload/outbox survive faults.

## 5. Timing (measured, not an SLO)

| Plane | n | T_detect s | T_restore s | T_converge s |
|:--|---:|:--|:--|:--|
| product-realistic | 39 | 3.301–3.587 | 3.229–3.582 | 6.606–7.077 |
| protocol-control | 18 | 3.377–3.526 | 3.257–3.417 | 6.688–6.896 |
| transport-control | 12 | 3.342–3.697 | 3.342–3.722 | 6.859–7.255 |
| storage-control | 5 | 3.399–3.566 | 3.255–3.388 | 6.708–6.944 |
| primary-transition | 3 | 5.774–5.905 | 11.106–11.187 | 16.961–17.011 |
| all | 77 | min 3.301, median 3.465, max 5.905 | min 3.229, median 3.388, max 11.187 | min 6.606, median 6.869, max 17.011 |

Primary restore ~11.1 s is `pg-failover` revert duration. Other restore ~3.3 s is probe+revert of S3/TCP/tmpfs injectors. No RTO/RPO claim.

## 6. Retry, resume, terminal transitions

Product retry/resume/abort/sweep state machines were not executed. `durable_state=rejected` means the GET was not 2xx (or storage overwrite). `enospc-then-retry` cells record the same `http=error`, `durable=rejected` as `enospc` with no second product attempt. Outbox/upload session status is `absent`.

Expected injected errors (fault-phase GET) are separate from recovery-tail: after revert, `ledger.recovered=true` on all 77 recovery rows. That recovered flag is the harness probe-after-revert, not a product resume.

## 7. Locks, backend, PgBouncer

Pool mode is transaction. No `pg_locks` / `SHOW POOLS` / backend-pid series was published. The only lock observation is `ungranted=0` after the family. Lock-holder cells used the same GET probe as tus-patch; they do not prove advisory-xact release on a live upload session. PgBouncer reconnect is one toxiproxy-armed GET (`transport-control.pgbouncer.reconnect`, http `error`, avail `degraded`).

## 8. Part / staged / orphan cleanup

Part and staged prefixes are the harness keys `tmp/uploads/<run_id>/<cell>/a<n>`. `remove_prefix` ran once after the matrix. Integrity counts stayed 0. There is no `v_upload_parts` / Flysystem `.parts` listing, no CAS orphan walk, and no sweeper invocation. Cleanup of package part objects is unevaluated.

## 9. ENOSPC partial-write

All five storage-control cells: injector `tmpfs-enospc`, `write_length=8388608`, `free_bytes=0`, path `/var/tmp/vaulter-chaos/7b255888-43bb-4071-b106-45f70a7f475c`. That is the `dd` fill of `fill.bin`, not a short `fwrite` from `Sha256ContentStore::stage()`. RAG:— stage() still does not check short writes; this run does not measure that outcome. `enospc-then-retry` is not a distinct resume.

## 10. Readiness and root cause

| Probe | Baseline (77) | Recovery (77) |
|:--|:--|:--|
| `/up` HTTP 200 | 77 true | 77 true |
| `/ready` HTTP 200 | 77 false | 77 false |

`/ready` was already false before the first injector. Recovery did not restore it. Prometheus `vaulter-sut` `up=0` on the first and later scrapes (same pattern as P6 L-P6-04). Root cause of `/ready` is outside the fault window: P8.6 `/ready` needs DB SELECT 1, S3 sentinel, and nonce-scoped queue heartbeat. This stand never presented a green `/ready`, so readiness cannot attribute a cell to DB vs S3 vs queue.

P8.8 may consume only gaps this run actually exposed: scrape is a lone `up` query; `vaulter-sut=0`; no T_detect/T_restore series; `/ready` never 200. Do not select tracing targets from labelled GET probes. An empty P7 contribution to the target set is a valid D41 no-op input, not a reason to invent spans.

## 11. Inherited P5 / P6

P5.4 GREEN, run `2aeca12f-6180-4595-8466-31397c4ed15d`. L-02 open, L-06 open, L-07 unconfirmed. This chaos family does not close them.

P6.4 GREEN, run `59dfb7e1-bd32-4c87-ab6f-eb8f409607af`. L-04/L-05 open; L-P6-01…04 open. P7 did not exercise outbox relay or `claimDue()`. Sticky `failed_jobs` from P6 is not visible here (`failed_jobs=0` on the empty snapshot).

D11 still blocks drive hot-path slices (`S3MultipartHandler`, `SweepUploadsCommand`). D9 observability minimum is already P8.6/P8.7 GREEN; this run did not scrape product series.

## 12. Debt map

Bounded rows live in [`findings/P7-degradation.md`](findings/P7-degradation.md).

| Cells / observation | Debt |
|:--|:--|
| All 13 product-realistic ×3 and every protocol/transport GET probe | **L-P7-01** (not product HTTP) |
| `database.jsonl` `status=absent`; `v_*=0`; `webhook_outbox` lookup | **L-P7-02** |
| Three `primary-transition.*` `http=accepted` / `durable=committed` | **L-P7-03** |
| Five `storage-control.*` 8 MiB `dd`; identical retry cells | **L-P7-04** |
| `/ready` false 154/154; Prom `vaulter-sut=0` | **L-P7-05** |
| Protocol `pre-upstream`/`after-accept`/`on-recovery` identical GET 503 | **L-P7-01** |
| No evidence-invalid cell | none to map; limitation is missing product path, not missing matrix files |

## 13. Limitations

- Valid evidence means the harness ledger, barrier, checksum, and snapshot rules passed. It does not mean Vaulter upload/CAS/outbox survived the fault.
- Injected 503/timeout/error are expected on the GET probe. They are not recovery-tail product 5xx.
- MinIO is not AWS. Toxiproxy is not S3 outage. `pg-failover` is not automatic host HA.
- Fingerprint worktree was dirty. `injector_version` is still `p7.1-lane-stub`.
- No `packages/*` change is part of this interpretation.
