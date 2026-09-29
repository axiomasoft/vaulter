---
id: D25
date: 1970-01-01
status: accepted
item: ''
items: []
supersedes: []
superseded_by: null
---
## Решение

**`load/` живёт в ЭТОМ репозитории** (не отдельным репо): гейт `smoke-perf` обязан запускаться на PR этого репо, а baseline-артефакт — проходить code review в том же PR (`slo-gates.md` §4.1). Пакетный `src/` при этом не трогается (D-LOAD-4) — единственное исключение вне `load/`: `require-dev` (S3-драйвер) и `.github/workflows/load.yml`.

## Почему

Imported noncanonical legacy decision row
