---
id: D17
date: 1970-01-01
status: accepted
item: ''
items: []
supersedes: []
superseded_by: null
---
## Решение

**Гейт нагрузки — две плоскости оценки.** `k6-thresholds` (клиентские метрики, exit 99) + `perf-assert` (серверные RSS/батч/lock-set/backlog, PromQL). Серверные обязательства класса A порогами k6 невыразимы (RAG-8b) — без второй плоскости половина класса A была бы гейтом, который никто не вычисляет.

## Почему

Imported noncanonical legacy decision row
