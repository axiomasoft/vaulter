---
id: D5
date: 1970-01-01
status: accepted
item: ''
items: []
supersedes: []
superseded_by: null
---
## Решение

**Профилирование БД:** `pg_stat_statements` (reset per run) + `EXPLAIN(ANALYZE,BUFFERS,VERBOSE)` + **HypoPG** для гипотез-индексов; lock-контеншн — `pg_blocking_pids()`-запрос + `log_lock_waits`+`deadlock_timeout=1s`; пул — PgBouncer transaction-mode.

## Почему

Imported noncanonical legacy decision row
