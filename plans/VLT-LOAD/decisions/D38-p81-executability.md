---
id: D38
date: 2026-09-20
status: accepted
item: P8.1
items: [P8.1, P8.2, P8.3, P8.4, P8.5, P8.6, P8.7]
supersedes: []
superseded_by: null
---
# D38 — Executable gate reader, run-scoped Redis, exact Files, nonce readiness

**Actor:** designer/cursor-grok-4.6-high/frontier/high

**Evidence:** RAG:✅ 2026-09-20 Perplexity + primary docs — Prometheus exposition 0.0.4
(`_bucket`/`_sum`/`_count`, `+Inf == _count`, HELP/TYPE grouping)
https://prometheus.io/docs/instrumenting/exposition_formats/ · histogram quantile vs
bucket boundaries https://prometheus.io/docs/practices/histograms/ · Redis `EVAL` atomic
multi-key https://redis.io/docs/latest/develop/programmability/eval-intro/ · Laravel 12
queue connection vs queue https://laravel.com/docs/12.x/queues · php-fpm `pm.status_path`
https://www.php.net/manual/en/fpm.status.php. RAG:— second RED audit
`findings/P8.1-design-review.md` F1–F4 · owner `task:plan-run VLT-LOAD P8.1`.

## Решение

1. **Gate reader.** P8.7 owns `load/gates/perf-assert.py`, `_slo_table.py`, `promql_map.py`
   and fixtures. The reader executes `observability-spec.md` §8 PromQL (histogram `_bucket`
   + `histogram_le_ratio` / `histogram_le_all` for class-A caps, never a histogram base name
   as scalar). Mapping is `SLO row → PromQL → threshold`. Fixtures cover histogram, counter,
   run-max gauge, and compound expressions before the live harness.
2. **Redis registry.** Keys are run-scoped `vaulter:metrics:{<LOAD_RUN_ID>}:…` with open /
   observe / scrape / finalize. One Lua `EVAL` updates all histogram buckets + `_sum` +
   `_count` (`+Inf == _count`). Manifest registers 50 zero-series. Exposition is Prometheus
   0.0.4 with exact HELP/TYPE/sample grouping. Backend failure drops the emission, never a
   torn write. Peak `MAX` gauges cannot leak across runs.
3. **Files / signals.** Item `Files` are the exact superset in `observability-spec.md` §14.
   `operation` is a METHOD+path map (§9.1), not Laravel route names or k6 tags. `tier` comes
   from compose `TIER`. `collab_room_active` requires compose `hocuspocus` + hub `/stats` +
   k6 WebSocket. `download_stream_active` requires php-fpm status + nginx internal location.
   Missing sources are not replaced by a silent zero.
4. **Readiness.** Queue probe is nonce-scoped (`vaulter:queue-ready:{nonce}`), connection
   `redis`, queue `load-health`, one-shot consume, bounded connect/read/poll. Stale ACK is a
   required negative. `Dockerfile.app` installs the PHP Redis extension. Histogram `le`
   sets include every blocking §3 threshold. `format` = `html`/`markdown`; `command` =
   artisan signatures; `route` = closed logical ids.

Family names, types, and §5 label **keys** are unchanged.

## Почему

The post-D37 audit showed the design still could not be executed: the live reader queries
bare names, Redis had no atomic/run contract, several producers sat outside `Files`, and
readiness/buckets/vocabularies were open. D37's transport/ownership/DI choices stay;
this record closes the executability gaps.

## Альтернативы

- Keep interpolation-only `histogram_quantile` for class-A duration — rejected: error
  depends on bucket grid; class-A uses `le` ratio at the exact threshold.
- Invent Laravel `->name()` on every package route — rejected for P8-minimum (additive
  risk on public routes); logical path map is enough and honest.
- Drop `collab_room_active` / `download_stream_active` — rejected: that would change §5
  families; sources are added to the load stand instead.

## Consequences

P8.2–P8.7 item Files/Rules follow `observability-spec.md` §4 and §7–§14. Re-audit is
`task:plan-audit VLT-LOAD design`. P8.2 stays blocked until that review is GREEN.
