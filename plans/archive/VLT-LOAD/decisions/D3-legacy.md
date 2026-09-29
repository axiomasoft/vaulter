---
id: D3
date: 1970-01-01
status: accepted
item: ''
items: []
supersedes: []
superseded_by: null
---
## Решение

**Стриминг тела в k6 — через `xk6-fasthttp` (кастомный бинарник), НЕ стоковый k6.** Core-k6 грузит файл целиком в RAM (`open()`/`SharedArray`) → OOM генератора на 5 ГиБ × сотни VU. Экспериментальный streams-API — JS-уровень, не HTTP-тело. Для tus допустим и chunk-на-уровне-сценария.

## Почему

Imported noncanonical legacy decision row
