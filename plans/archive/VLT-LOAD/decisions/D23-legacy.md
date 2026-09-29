---
id: D23
date: 1970-01-01
status: accepted
item: ''
items: []
supersedes: []
superseded_by: null
---
## Решение

**Метрики k6 — встроенный выход `--out experimental-prometheus-rw`**, а не расширение `xk6-output-prometheus-remote`: оно **влито в ядро** k6 с версии v0.42. Кастомный бинарник ради вывода не собирается. k6-Operator остаётся вариантом только для распределённого прогона (в P2 не нужен).

## Почему

Imported noncanonical legacy decision row
