---
id: D6
date: 1970-01-01
status: accepted
item: ''
items: []
supersedes: []
superseded_by: null
---
## Решение

**Для деревьев проверить порог CTE-vs-ltree.** Recursive-CTE держим (cycle-guard + `withMaxDepth(64)` уже есть), но на глубоких/широких деревьях замерить, где `candidateNodeIds()` (N-CTE per grant) и `MoveNodeAction` (O(subtree) в PHP) ломают SLO; ltree/materialized-path — кандидат-долг, если CTE не тянет.

## Почему

Imported noncanonical legacy decision row
