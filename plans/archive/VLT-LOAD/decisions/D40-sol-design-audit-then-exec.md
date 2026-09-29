---
id: D40
date: 2026-09-20
status: superseded
item: P3
items: [P3, P4, P5, P6, P7, P8.8]
supersedes: []
superseded_by: D41
---
# D40 — Детализация фаз через Codex Sol, затем полный аудит плана, затем исполнение

**Actor:** owner/Dmitry Vostrikov

**Evidence:** RAG:— owner directive 2026-09-20 в сессии после P8.7.

## Решение

1. **Детализация.** Все ещё скелетные фазы (`P3`–`P7`, плюс `P8.8` после них) детализируются **только** через `task:plan-design` с маршрутом **Codex `gpt-5.6-sol`**, frontier/high, по числовому порядку фаз. Пункты P8.2–P8.7 не переоткрываются; live-долг P8.7 (harness/overhead) остаётся в `findings/P8.7-gate-closure.md` до operator-run.
2. **Аудит.** После того как у каждой из перечисленных фаз есть исполнимые item-спеки (не «СКЕЛЕТ»), один **полный аудит плана** `task:plan-audit VLT-LOAD` (Sol / frontier/high) по всему оставшемуся scope — без параллельного `plan-exec` по скелетным фазам.
3. **Исполнение.** `task:plan-exec` / `task:plan-run` по фазам возобновляется **только после** принятого результата шага 2. Следующий runnable design — `P3` (D34).

## Почему

Скелеты P3–P7 не должны уходить в implementation до единого design surface и одного audit gate; Sol уже зафиксирован в Meta/RUNBOOK для независимого review, владелец явно выбрал его для детализации и аудита перед кодом.
