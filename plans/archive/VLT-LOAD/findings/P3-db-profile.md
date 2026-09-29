# P3 DB profile — remediation backlog (bounded)

> Debt rows for dev-slices only. Fixes require **D11** where noted. Evidence IDs must resolve under `plans/VLT-LOAD/artifacts/P3/`.

| ID | Severity | Confidence | Owner package | Affected OP / SLO | Evidence IDs | Candidate remediation | D11 |
|:--|:--|:--|:--|:--|:--|:--|:--|
| **L-P3-01** | Low (evidence) | High | `load` gates | top-20 + mandatory DML hotspots | `explain/-6855089855741477301.json`; `explain/3824637638010629220.json`; `explain/9004762281719738018.json`; `explain/1190153895781818181.json`; `explain/-5991169169508416855.json`; `explain/1581082543845045234.json` | Extend `p3_explain.py` binding matrix; re-capture batch INSERT/UPSERT on disposable DB | n/a (tooling) |
| **L-P3-02** | Med | Medium | `core` | OP-12…15, SLO-04…09 | `explain/code-anchor_NodeReadRepository_candidateNodeIds.json`; `NodeReadRepository.php:491` | Fix anchor SQL capture; add ANALYZE plan card for many-grant median | Fixes **blocked D11** (drive grants) |
| **L-P3-03** | Low (measurement) | High | `load` | all DB-bearing OP | `query-trace.jsonl` (0 rows); P3.2 journal note | Re-run tier-S with trace flush; then map duplicate `sql_template_hash` per `command_id` | n/a |
| **L-P3-04** | Med | High | `core` | OP-40, SLO-58…60 (**L-05**) | `explain/code-anchor_OutboxRelay_subscribedEndpoints.json`; `OutboxRelay.php:113` | SQL-side partial index only helps at scale; primary fix = batch/cache endpoint subscriptions in PHP | core path — not drive D11 |
| **L-P3-05** | Med | High | `core` + `drive` | OP-12…15, OP-20…24 (**L-06**) | `explain/879347013115918506.json`; `pgss-top20.json#879347013115918506` | Measure 1 000-anchor tier; evaluate ltree/materialized path per **D6** | **D11** for `MoveNodeAction`; core CTE in `NodeReadRepository` |
| **L-P3-06** | Low | High | `core` | OP-12…15, OP-25…26, OP-40 | `hypopg-candidates.json#nodes_parent_storage_btree`; `#webhook_endpoints_active_btree` | Do not promote DDL from planner-rejected HypoPG; repeat on production-like cardinalities | DDL dev-slice after P3 |
| **L-P3-07** | Low | Medium | `core` | OP-25…26 | `index-review.json#redundant_index_hypotheses` | Confirm duplicate `v_activity` storage indexes; drop only after write-path review | n/a |

## Notes

- **L-P3-04** documents **L-05** — not closed at seed cardinality.
- **L-P3-05** documents **L-06** — recursive CTE cost observed, not fixed.
- No item authorizes production DDL or package hot-path edits inside P3.
