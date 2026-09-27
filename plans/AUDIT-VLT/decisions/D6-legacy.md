---
id: D6
date: 2026-07-10
status: accepted
item: ''
items: []
supersedes: []
superseded_by: null
---
## Решение

Repro-артефакты живут в `plans/AUDIT-VLT/artifacts/repro/<Pn.m>/`; попадание их в `packages/*/tests/` — дефект исполнения.

## Почему

Иначе временные тесты остаются в пакете навсегда (pre-mortem R2.2).
