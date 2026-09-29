---
id: D32
date: 1970-01-01
status: superseded
item: ''
items: []
supersedes: []
superseded_by: D36
---
## Решение

**Health split: liveness = SUT-level Laravel-native `/up` (пакет не владеет `/up`); readiness (DB/S3/queue) = опциональные vaulter Check-классы за `spatie/laravel-health` через `suggest` (не `require`) + wiring в SUT `load/`.** Гейт §5.4 (харнесс отличает «упало» от «не поднялось») удовлетворён без хард-зависимости.

## Почему

Imported noncanonical legacy decision row
