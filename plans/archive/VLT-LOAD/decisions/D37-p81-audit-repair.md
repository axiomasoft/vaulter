---
id: D37
date: 2026-09-20
status: accepted
item: P8.1
items: [P8.1, P8.2, P8.3, P8.4, P8.5, P8.6, P8.7]
supersedes: []
superseded_by: null
---
# D37 — Executable observability path after P8.1 RED audit

**Actor:** designer/cursor-grok-4.6-high/frontier/high

**Evidence:** `plans/VLT-LOAD/findings/P8.1-design-review.md` (RED: F1–F4) · owner confirmation
2026-09-20 to execute the owning continuation under Grok 4.6 · Prometheus classic histogram
docs · Laravel queue drivers.

## Решение

1. **Transport.** P8-minimum proof is Prometheus **pull** of load SUT `GET /metrics` from a
   Redis-backed shared registry. Fake in-process emissions are contract tests only. P8.7 owns
   host adapter, registry, scrape job `vaulter-sut`, and the trigger matrix.
2. **Ownership.** Host/harness gauges stay off package Files. `download_stream_active` is an
   absolute host collector. Stream metrics measure PHP first-write and attempted/read bytes;
   client TTFB/received bytes remain k6.
3. **Histograms.** Classic Prometheus histograms with family bucket sets in
   `observability-spec.md` §8. Perf-assert uses `_bucket` + `histogram_quantile` + `increase`/`rate`.
   `slo-gates.md` §3 expressions that pass a histogram base name as a scalar are non-normative.
4. **DI.** Factory reads `enabled` first; invalid/missing adapter → Null, not exception.
5. **Queue readiness.** Redis + worker + heartbeat round-trip. `sync` and `Queue::size()` are not
   readiness.

Family names, types, and §5 label keys are unchanged.

## Почему

RED audit showed the previous spec could not be executed by P8.2–P8.7: no durable FPM→sink path,
conflicting gauge ownership, invalid PromQL, and incomplete DI/queue contracts.

## Альтернативы

- OTel push from each FPM worker — rejected for P8-minimum (D35: no SDK types in core; P8.2
  excludes OTel adapter). Host may swap later.
- Keep Fake as P8.7 proof — rejected: multi-worker FPM.
- Rename stream families to `server_*` — rejected: P8.1 must not change §5 names.

## Последствия

P8.2–P8.7 item Files/Rules follow `observability-spec.md` §7–§10. Re-audit is
`task:plan-audit VLT-LOAD design`. P8.2 stays blocked until that review is GREEN.
