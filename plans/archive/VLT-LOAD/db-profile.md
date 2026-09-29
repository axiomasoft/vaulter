# P3 DB profile — tier S verdict

> Run-bound synthesis for VLT-LOAD P3. Raw numbers live in [`artifacts/P3/`](artifacts/P3/);
> this report is the interpretation layer (RAG:—). External methodology: [`findings/P3-rag-postgres-profiling.md`](findings/P3-rag-postgres-profiling.md) (RAG:✅).

## 1. Environment, run, dataset, cache

| Field | Value | Evidence |
|:--|:--|:--|
| `run_id` | `51259904-2241-4636-a06f-c82cd7d2d2ef` | `artifacts/P3/run-manifest.json` |
| `dataset_id` | `vlt-load-414fae92477d07f8` | `artifacts/P3/dataset-profile.json` |
| Tier | `S` | run manifest |
| Git SHA | `5cd93bcd27c6a2cabd9ad533c60366c355cda006` | run manifest |
| Env fingerprint | `b6f01b8a6600c3d5` | run manifest |
| PostgreSQL | PostgreSQL 16.15 (Debian 16.15-1.pgdg12+2) on x86_64-pc-linux-gnu | dataset profile |
| P8.7 preflight | `2e5e55e0e06f7130…` | run manifest |
| `pg_stat_statements` dealloc Δ | `0` | valid top-20 (`artifacts/P3/pgss-top20.json`) |
| Cache declaration | shared-buffer-cold-like_after_postgres_restart_page_cache_warm | run manifest / workload |
| Workload window | 60s mixed k6 | run manifest |
| `track_io_timing` | off | run manifest |
| Disposable DB | yes | run manifest |

**Validity (observed).** Single tier-S capture after seed `ANALYZE`, postgres restart for shared-buffer-cold-like state, `dealloc_delta=0`, and P8.7 GREEN preflight. **Not claimed:** host page-cache cold, production parity, or k6 p95 (pgss is impact-only; RAG-P3-2).

**Trace limitation.** `query-trace.jsonl` has **0** rows in the published bundle (P3.2 deviation: `LOAD_DB_TRACE` flush). OP↔SQL multiplicity / N+1 cannot be confirmed from trace; inference uses pgss + code anchors + explain (RAG-P3-5).

## 2. Operation coverage (47/47)

| OP | Verdict | Code anchor | Notes |
|:--|:--|:--|:--|
| OP-01 | db-explain-covered | `packages/drive/src/Http/Controllers/Uploads/TusUploadController.php:67` | — |
| OP-02 | db-explain-covered | `packages/drive/src/Http/Controllers/Uploads/TusUploadController.php:134` | — |
| OP-03 | db-explain-covered | `packages/drive/src/Http/Controllers/Uploads/TusUploadController.php:106` | — |
| OP-04 | db-explain-covered | `packages/drive/src/Http/Controllers/Uploads/TusUploadController.php:223` | — |
| OP-05 | db-explain-covered | `packages/drive/src/Http/Controllers/Uploads/TusUploadController.php:180` | — |
| OP-06 | db-explain-covered | `packages/drive/src/Http/Controllers/Uploads/S3MultipartController.php:56` | — |
| OP-07 | db-explain-covered | `packages/drive/src/Http/Controllers/Uploads/S3MultipartController.php:85` | — |
| OP-08 | db-explain-covered | `packages/drive/src/Http/Controllers/DownloadController.php:47` | — |
| OP-09 | db-explain-covered | `packages/drive/src/Http/Controllers/DownloadController.php:59` | — |
| OP-10 | db-explain-covered | `packages/core/src/Http/Controllers/Sharing/PublicShareController.php:102` | — |
| OP-11 | db-explain-covered | `packages/drive/src/Http/Controllers/StorageController.php:55` | — |
| OP-12 | db-explain-covered | `packages/drive/src/Http/Controllers/NodeController.php:81` | — |
| OP-13 | db-explain-covered | `packages/drive/src/Http/Controllers/NodeController.php:109` | — |
| OP-14 | db-explain-covered | `packages/drive/src/Http/Controllers/NodeController.php:307` | — |
| OP-15 | db-explain-covered | `packages/drive/src/Http/Controllers/NodeController.php:325` | — |
| OP-16 | db-explain-covered | `packages/drive/src/Http/Controllers/NodeController.php:158` | — |
| OP-17 | db-explain-covered | `packages/drive/src/Http/Controllers/NodeController.php:178` | — |
| OP-18 | db-explain-covered | `packages/drive/src/Http/Controllers/NodeController.php:142` | — |
| OP-19 | db-explain-covered | `packages/drive/src/Http/Controllers/NodeController.php:214` | — |
| OP-20 | db-explain-covered | `packages/drive/src/Http/Controllers/NodeController.php:227` | — |
| OP-21 | db-explain-covered | `packages/drive/src/Http/Controllers/NodeController.php:246` | — |
| OP-22 | db-explain-covered | `packages/drive/src/Http/Controllers/NodeController.php:277` | — |
| OP-23 | db-explain-covered | `packages/drive/src/Http/Controllers/NodeController.php:289` | — |
| OP-24 | db-explain-covered | `packages/drive/src/Http/Controllers/NodeController.php:265` | — |
| OP-25 | db-explain-covered | `packages/core/src/Http/Controllers/Activity/ActivityController.php:44` | — |
| OP-26 | db-explain-covered | `packages/core/src/Http/Controllers/Activity/ActivityController.php:62` | — |
| OP-27 | db-explain-covered | `packages/documents/src/Http/Controllers/DocumentNodeController.php:68` | — |
| OP-28 | db-explain-covered | `packages/documents/src/Http/Controllers/DocumentNodeController.php:90` | — |
| OP-29 | db-explain-covered | `packages/documents/src/Http/Controllers/DocumentNodeController.php:121` | — |
| OP-30 | db-explain-covered | `packages/documents/src/Http/Controllers/DocumentNodeController.php:106` | — |
| OP-31 | db-explain-covered | `packages/documents/src/Http/Controllers/DocumentNodeController.php:140` | — |
| OP-32 | db-explain-covered | `packages/documents/src/Http/Controllers/DocumentNodeController.php:157` | — |
| OP-33 | db-explain-covered | `packages/documents/src/Http/Controllers/DocumentNodeController.php:199` | — |
| OP-34 | db-explain-covered | `packages/documents/src/Http/Controllers/NodeCollaborationController.php:60` | — |
| OP-35 | db-explain-covered | `packages/documents/src/Http/Controllers/NodeCollaborationController.php:79` | — |
| OP-36 | db-explain-covered | `packages/documents/src/Http/Controllers/NodeCollaborationController.php:110` | — |
| OP-37 | db-explain-covered | `packages/documents/src/Http/Controllers/NodeCommentController.php:50` | — |
| OP-38 | db-explain-covered | `packages/documents/src/Http/Controllers/NodeCommentController.php:57` | — |
| OP-39 | no-db | `packages/core/src/Webhooks/OutboxRecorder.php:131` | embedded outbox write path; observed only via correlated SQL templates |
| OP-40 | no-db | `packages/core/src/Console/RelayOutboxCommand.php:29` | background command vaulter:relay-outbox not triggered in this run |
| OP-41 | no-db | `packages/core/src/Jobs/Webhooks/DeliverWebhook.php:55` | queued DeliverWebhook job; SQL visible only when queue worker runs |
| OP-42 | no-db | `packages/core/src/Console/RetryWebhooksCommand.php:29` | background command vaulter:retry-webhooks not triggered in this run |
| OP-43 | no-db | `packages/core/src/Console/PruneWebhooksCommand.php:40` | background command vaulter:prune-webhooks not triggered in this run |
| OP-44 | no-db | `packages/drive/src/Console/SweepUploadsCommand.php:32` | background command vaulter:sweep-uploads not triggered in this run |
| OP-45 | no-db | `packages/drive/src/Console/PurgeTrashCommand.php:46` | background command vaulter:purge-trash not triggered in this run |
| OP-46 | no-db | `packages/media/src/Console/GcBlobsCommand.php:26` | background command vaulter:gc-blobs not triggered in this run |
| OP-47 | no-db | `packages/media/src/Console/ReconcileBlobsCommand.php:41` | background command vaulter:reconcile-blobs not triggered in this run |

**Parity:** 47/47 operations — db-explain-covered=38, no-db=9.

## 3. `pg_stat_statements` top-20 (20/20)

| Rank | Fingerprint | Calls | Total exec ms | Plan verdict class | Explain evidence |
|:--|:--|--:|--:|:--|:--|
| 1 | `879347013115918506` | 162 | 27122.0 | recursive-cte | `artifacts/P3/explain/879347013115918506.json` |
| 2 | `-6855089855741477301` | 63 | 202.9 | explain-binding-gap | `artifacts/P3/explain/-6855089855741477301.json` |
| 3 | `-1894524016563631725` | 3236 | 105.7 | recursive-cte | `artifacts/P3/explain/-1894524016563631725.json` |
| 4 | `4727283112728325761` | 8 | 64.0 | index-or-shape-ok | `artifacts/P3/explain/4727283112728325761.json` |
| 5 | `-6030593131041620695` | 18 | 52.7 | index-or-shape-ok | `artifacts/P3/explain/-6030593131041620695.json` |
| 6 | `6297879775831589256` | 4 | 25.2 | index-or-shape-ok | `artifacts/P3/explain/6297879775831589256.json` |
| 7 | `-2043260873617940286` | 3 | 22.2 | index-or-shape-ok | `artifacts/P3/explain/-2043260873617940286.json` |
| 8 | `-5145945359103328075` | 1 | 18.0 | index-or-shape-ok | `artifacts/P3/explain/-5145945359103328075.json` |
| 9 | `1972103085930111493` | 2 | 17.9 | index-or-shape-ok | `artifacts/P3/explain/1972103085930111493.json` |
| 10 | `4832147948566295162` | 1 | 12.4 | index-or-shape-ok | `artifacts/P3/explain/4832147948566295162.json` |
| 11 | `3824637638010629220` | 18 | 12.0 | explain-binding-gap | `artifacts/P3/explain/3824637638010629220.json` |
| 12 | `4717367921025960537` | 13 | 9.4 | index-or-shape-ok | `artifacts/P3/explain/4717367921025960537.json` |
| 13 | `8630992533815637473` | 18 | 9.1 | index-or-shape-ok | `artifacts/P3/explain/8630992533815637473.json` |
| 14 | `9004762281719738018` | 1000 | 8.9 | explain-binding-gap | `artifacts/P3/explain/9004762281719738018.json` |
| 15 | `1190153895781818181` | 119 | 8.3 | explain-binding-gap | `artifacts/P3/explain/1190153895781818181.json` |
| 16 | `-3591856563463969443` | 8 | 8.1 | index-or-shape-ok | `artifacts/P3/explain/-3591856563463969443.json` |
| 17 | `-4286444071232566354` | 680 | 7.8 | index-or-shape-ok | `artifacts/P3/explain/-4286444071232566354.json` |
| 18 | `-5991169169508416855` | 13 | 7.2 | explain-binding-gap | `artifacts/P3/explain/-5991169169508416855.json` |
| 19 | `-3238224264296891740` | 468 | 5.9 | index-or-shape-ok | `artifacts/P3/explain/-3238224264296891740.json` |
| 20 | `1581082543845045234` | 720 | 5.7 | explain-binding-gap | `artifacts/P3/explain/1581082543845045234.json` |

**Mandatory hotspot fingerprints** (from `pgss-top20.json#mandatory_hotspots`): `v_activity`→`-6855089855741477301`, `v_blobs`→`-6030593131041620695`, `v_file_versions`→`3824637638010629220`, `v_nodes`→`879347013115918506`, `v_outbox`→`1610336054333300395`, `v_upload_parts`→`7283217862701016213`, `v_upload_sessions`→`4717367921025960537`. Family `v_webhook_deliveries` is covered via index review (no dedicated pgss mandatory row in this run).

## 4. Plan cards (selected)

### 4.1 Recursive CTE — grant-scoped descendants (L-06)

- **Fingerprint:** `879347013115918506` (rank #1 by total time).
- **Observed:** `EXPLAIN ANALYZE` on disposable DB — `Recursive Union` + `WorkTable Scan`, **13 067** rows returned, **154.5 ms** execution (`artifacts/P3/explain/879347013115918506.json`).
- **Inferred:** Laravel recursive CTE from listing/grant paths (`NodeReadRepository::candidateNodeIds` pattern); cost scales with subtree width, not a single index miss.
- **OP linkage:** OP-12…15, OP-20 (many-grant actor); code anchor `packages/core/src/Repositories/Drive/NodeReadRepository.php:491`.
- **Verdict:** **N-CTE / cardinality** — justified shape for grant expansion on tier-S seed, but **L-06** remains open for 1 000-anchor envelope (D6, D11 on fixes).

### 4.2 Recursive CTE — ancestor walk (top-20)

- **Fingerprint:** `-1894524016563631725` (3 236 calls, low mean).
- **Observed:** shallow recursive union, **1** row (`artifacts/P3/explain/-1894524016563631725.json`).
- **Verdict:** **recursive-cte-ok** at median parameters; not a seq-scan debt.

### 4.3 `subscribedEndpoints()` SQL shape (L-05)

- **Anchor:** `code-anchor_OutboxRelay_subscribedEndpoints.json`.
- **Observed:** `Seq Scan` on `v_webhook_endpoints`, **5** rows, 0.03 ms (`artifacts/P3/explain/code-anchor_OutboxRelay_subscribedEndpoints.json`).
- **Inferred:** PHP still loads all active endpoints per relay row (D8); DB cost negligible at seed cardinality, **fan-out risk** is application-side — updates **L-05** evidence, does not close L-05.

### 4.4 `candidateNodeIds` anchor capture

- **Anchor:** `code-anchor_NodeReadRepository_candidateNodeIds.json` — **rejected** (truncated SQL / syntax).
- **Verdict:** **limitation** — representative recursive SQL must be re-captured; debt **L-P3-02**.

### 4.5 Batch DML / UPSERT fingerprints (P3.3 deviation)

Rejected explain for mandatory/top-20 fingerprints: `-6855089855741477301`, `3824637638010629220`, `9004762281719738018`, `1190153895781818181`, `-5991169169508416855`, `1581082543845045234`.
- **Cause (observed):** binding coercion / unique violations in disposable `EXECUTE` matrix (`p3_explain.py` capture).
- **Verdict:** **explain-binding-gap** — not proof of bad plans; blocks seq-scan verdict for those statements until matrix extended (**L-P3-01**).

## 5. Hot table families (8/8)

| Family | Indexes | Index bytes | Unused non-PK | Seq scan notes |
|:--|--:|--:|--:|:--|
| `v_nodes` | 15 | 7774208 | 9 | 0 seq in explain corpus |
| `v_blobs` | 8 | 12017664 | 5 | 0 seq in explain corpus |
| `v_upload_sessions` | 9 | 868352 | 7 | 0 seq in explain corpus |
| `v_upload_parts` | 3 | 622592 | 1 | 0 seq in explain corpus |
| `v_file_versions` | 9 | 7307264 | 7 | 0 seq in explain corpus |
| `v_activity` | 11 | 86786048 | 9 | 0 seq in explain corpus |
| `v_outbox` | 5 | 155648 | 3 | 0 seq in explain corpus |
| `v_webhook_deliveries` | 4 | 65536 | 3 | 0 seq in explain corpus |

**Redundant index hypothesis (debt-only):** `v_activity_storage_id_index` vs `v_activity_storage_idx` (`artifacts/P3/index-review.json#redundant_index_hypotheses`).

## 6. N+1 and application multiplicity

| Check | Result | Evidence |
|:--|:--|:--|
| Per-OP duplicate template hash in one request | **not observed** (empty trace) | `artifacts/P3/query-trace.jsonl` (0 rows published) |
| High global `calls` without per-OP correlation | **not used as N+1 proof** | RAG-P3-5 |
| PHP loop + repeated SQL pattern | **inferred only** for outbox relay | `OutboxRelay.php:113`, L-05 |
| Recursive CTE per grant | **observed** via pgss + explain | §4.1, L-06 |

**N+1 verdict:** No confirmed N+1 row in this run; **L-P3-03** records trace gap. Re-run capture with trace flush fix before treating multiplicity as green.

## 7. Index / HypoPG candidates (planner-only)

| Candidate | HypoPG verdict | OPs | Evidence |
|:--|:--|:--|:--|
| `nodes_parent_storage_btree` | planner-rejected | OP-12, OP-13, OP-14, OP-15, OP-20 | `artifacts/P3/hypopg-candidates.json` |
| `activity_storage_created_btree` | superseded-by-existing | OP-25, OP-26 | `artifacts/P3/hypopg-candidates.json` |
| `webhook_endpoints_active_btree` | planner-rejected | OP-40 | `artifacts/P3/hypopg-candidates.json` |

**Rule (RAG-P3-4):** `planner-rejected` / unchanged cost ⇒ **no index-fast claim**; dev-slice DDL remains gated by **D11** for drive-touched paths.

## 8. Legacy L-row updates (this valid run)

| Legacy ID | P3 update | Relabel? |
|:--|:--|:--|
| **L-05** | SQL is cheap seq scan at 5 endpoints; PHP O(endpoints×outbox) still the risk | No — still hypothesis for 1 000 endpoints |
| **L-06** | Mandatory hotspot shows 13k-row recursive CTE / 154 ms on tier-S seed | Strengthen evidence; fix still **D11**-gated for drive |
| **L-01** | Not in pgss top-20 (multipart path) | Unchanged (P2.8 arithmetic + run) |

## 9. Limitations

- Empty application trace → no OP-level SQL count / N+1 proof.
- Eight explain rejections on write/batch fingerprints.
- HypoPG on small-cardinality probes does not prove production wins.
- p95 latency attribution remains k6 + P8 metrics, not `pg_stat_statements` mean.

---

*Generated for P3.4 close — 2026-09-22.*
