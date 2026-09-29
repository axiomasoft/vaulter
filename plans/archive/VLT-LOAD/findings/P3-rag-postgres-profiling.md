# P3 — RAG: PostgreSQL 16 profiling, HypoPG и application query tracing

> Проверено 2026-09-20 через Perplexity MCP (`search`, web) и прямую сверку с первичными
> источниками. Этот документ фиксирует внешние предпосылки дизайна P3; факты репозитория и
> фактические результаты прогонов принадлежат `db-profile.md` и `artifacts/P3/`.

## RAG-P3-1 — `EXPLAIN ANALYZE` исполняет statement

**Вывод.** `EXPLAIN (ANALYZE ...)` не является read-only просмотром плана: statement реально
исполняется. Для `INSERT`/`UPDATE`/`DELETE` PostgreSQL рекомендует `BEGIN; EXPLAIN ANALYZE ...;
ROLLBACK`, но rollback не отменяет стоимость исполнения, блокировки, WAL и прогрев кэша. Поэтому P3
снимает actual-планы только на disposable копии load-датасета, с `statement_timeout` и
`lock_timeout`; production/общая БД исключена.

Для машинного evidence используется `FORMAT JSON`; `BUFFERS`, `VERBOSE`, `SETTINGS` и `WAL`
фиксируют соответственно buffer/I/O, квалифицированные узлы, planner-relevant overrides и WAL.
`track_io_timing` даёт I/O time, но сам может иметь измеримый overhead — перед включением нужен
`pg_test_timing` и запись результата в fingerprint окружения.

Источники: [PostgreSQL 16 — EXPLAIN](https://www.postgresql.org/docs/16/sql-explain.html),
[PostgreSQL 16 — statistics configuration](https://www.postgresql.org/docs/16/runtime-config-statistics.html).
RAG:✅

## RAG-P3-2 — `pg_stat_statements` задаёт impact, но не p95

**Вывод.** `pg_stat_statements` агрегирует normalized query fingerprints и хранит calls,
planning/execution time, mean/stddev, rows, blocks, temp I/O и WAL. Он не хранит распределение
individual request latency и не заменяет k6 p95/p99. P3 связывает обе плоскости через `run_id`,
`dataset_id`, окно времени и OP-id: k6 остаётся владельцем SLO latency, PostgreSQL — владельцем
query impact.

Перед каждым изолированным прогоном выполняется `pg_stat_statements_reset()`; `stats_reset` и
`dealloc` из `pg_stat_statements_info` входят в evidence. Ненулевой рост `dealloc` делает top-20
неполным и требует повышения `pg_stat_statements.max` с повтором, а не молчаливого отчёта.

Источники: [PostgreSQL 16 — pg_stat_statements](https://www.postgresql.org/docs/16/pgstatstatements.html),
[PostgreSQL 16 — monitoring statistics](https://www.postgresql.org/docs/16/monitoring-stats.html).
RAG:✅

## RAG-P3-3 — `pg_stat_io` только cluster context

**Вывод.** PostgreSQL 16 `pg_stat_io` агрегирует I/O по backend type, object и context на уровне
кластера. Он полезен для общего контекста прогона, но не доказывает стоимость конкретного запроса;
query attribution остаётся за `pg_stat_statements` и `EXPLAIN ... BUFFERS`.

Источник: [PostgreSQL 16 — `pg_stat_io`](https://www.postgresql.org/docs/16/monitoring-stats.html#MONITORING-PG-STAT-IO-VIEW).
RAG:✅

## RAG-P3-4 — HypoPG доказывает выбор planner, не runtime-ускорение

**Вывод.** HypoPG хранит hypothetical indexes только в private memory текущего connection и
показывает их только в обычном `EXPLAIN` **без `ANALYZE`**. Поэтому кандидат считается
`planner-supported`, если hypothetical plan выбирает индекс и снижает cost/rows-read shape, но это
не доказательство фактического выигрыша. Реальный индекс и его write/WAL/maintenance цена относятся
к отдельному dev-срезу после P3.

P3 пинит HypoPG `1.4.1` (stable; совместимость заявлена до PostgreSQL 17), выполняет baseline и
hypothetical `EXPLAIN (FORMAT JSON, SETTINGS)` в одной сессии и завершает её `hypopg_reset()`.

Источники: [HypoPG — Usage](https://hypopg.readthedocs.io/en/rel1_stable/usage.html),
[HypoPG 1.4.1 changelog](https://github.com/HypoPG/hypopg/blob/1.4.1/CHANGELOG.md).
RAG:✅

## RAG-P3-5 — N+1 требует application correlation

**Вывод.** Server-side fingerprint не знает, какой HTTP/console operation породил серию похожих
запросов. Laravel предоставляет `DB::listen(QueryExecuted)` с SQL template, bindings и duration;
P3 использует load-host-only listener, коррелированный с `LOAD_RUN_ID` и OP-id. Raw bindings,
токены и пользовательские значения не сохраняются: evidence содержит normalized template hash,
connection, duration и ordinal. Повтор одного fingerprint внутри одной операции — сигнал N+1, а не
автоматический verdict; P3 подтверждает его код-якорем и `Actual Loops`/calls.

`auto_explain.log_analyze` не включается глобально: официальная документация предупреждает о
существенном overhead per-node timing. Он остаётся опциональным bounded fallback с sampling и
`log_timing=off`, если listener не видит nested SQL.

Источники: [Laravel 12 — Listening for Query Events](https://laravel.com/docs/12.x/database#listening-for-query-events),
[PostgreSQL 16 — auto_explain](https://www.postgresql.org/docs/16/auto-explain.html).
RAG:✅

