# HANDOFF — 2026-09-23 — after P7.4

**Next:** manual: frontier/high — P8.8 (§3 Routing: Exec = manual; `task:plan-exec` обязан отказать)

| Параметр | Значение |
|:--|:--|
| Batch | solo |
| Model | frontier/high |
| Effort | high |
| Capabilities | P3–P7 report digest join, frozen tracing allowlist reducer, producer-readiness/v1 |
| Context | continue-root |
| Суть | P7.4 closed 🟢. D42 next is P8.8 tracing-target selection from the five reports. |

```session-continuity-decision/v1
{
  "evidence": [
    "batch:507682c248b1ae2bbae8c5063cc19a87820af8d51b5573053f0126fc6e6867c8",
    "loaded-inputs:reusable",
    "cold-start-cost:0",
    "lifecycle:scope-complete",
    "checkpoint:5cc5a29de08c7b6ebf3603f765415880b5494d43bc79ac2a5f749f48a6361035"
  ],
  "outcome": "continue-root",
  "reason": "authorized-scope-complete",
  "runnable": true,
  "schema_version": "session-continuity-decision/v1"
}
```

**Provider-native command:**

```text
P8.8 — tracing targets and conditional deferred metrics from frozen observability-spec.md §20.
Exec = manual (frontier/high). Read the five P3–P7 reports and emit tracing-targets.json,
findings/P8.8-target-selection.md, and producer-readiness/v1. Empty D41 no-op is valid.
Do not edit packages/* or rewrite observability-spec.md.
```

**Done:** P7.4 synthesized `chaos-report.md` and `findings/P7-degradation.md` from run `7b255888-43bb-4071-b106-45f70a7f475c` (51/51 valid, digest `b51a143828512efb5bb85c5d4f05863677545ae183f2ee3b9f407e3100895a62`). Integrity before==after; ungranted locks 0. L-P7-01..05 open. P7 phase closed 🟢. Product HTTP paths unevaluated.

**Remaining:** P8.8 target selection and producer-readiness/v1. Then P8.9–P8.10, then P8.11.

**Sources of truth:** `plans/VLT-LOAD/phases/P7/P7.4.md` · `plans/VLT-LOAD/chaos-report.md` · `plans/VLT-LOAD/findings/P7-degradation.md` · `plans/VLT-LOAD/phases/P8/P8.8.md` · `plans/VLT-LOAD/observability-spec.md` · `plans/VLT-LOAD/decisions/D42-code-stretch-then-unattended-lab.md`.

**Open risks:** L-P7-01 harness GET not product HTTP; L-P7-02 empty snapshot + `webhook_outbox`; L-P7-03 primary hardcoded; L-P7-04 ENOSPC `dd`; L-P7-05 `/ready` never 200 and Prom `vaulter-sut=0`; L-04 unbounded `claimDue()` lock-set; L-05 O(endpoints) `subscribedEndpoints()`; L-P6-01 sticky `failed_jobs=2`; L-P6-02 product `dispatch_uuids=0`; L-P6-03 leftover-endpoint fan-out; L-P6-04 unrecovered bursts / Prom `vaulter-sut` down.

**Workarounds/Deferred/Open questions:**
- workarounds: P7.4 did not rerun the matrix; synthesis used published P7.3 artifacts only.
- deferred: no RTO/RPO invented; MinIO/Toxiproxy not treated as AWS; no `packages/*` edits; P8.8 may list the scrape/readiness gap only and must not select tracing targets from labelled GET probes.
- open_questions: —
