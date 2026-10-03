---
id: D12
date: 2026-10-02
status: accepted
item: P1
items: [P0, P1]
supersedes: []
superseded_by: null
---
# D12 — Дизайн P1 до закрытия P0; исполнение P1 — после закрытия P0 (accepted)

**Actor:** repository owner (выбор варианта в сессии `task:plan-design … P1`); фиксирует claude-opus-5-5/high.
**Evidence:** `brief/00-brief.md#2026-10-02-design-p1-before-p0-close`; `phases/P0/P0.md` (Audit P0 — ATTENTION, PA01);
`handoff.md` (P0.2 waiting-external: независимое ревью PA01); коммит `731cbb8` (PA01 трогает только
`packages/core/tests/Concurrency/`).

## Solution

1. Исключение из D03 п.2 только для P1: `phases/P1/` детализируется сейчас, пока P0 открыта. P1 проектируется по коду
   `main` @ `a5d8792`; незакрытый остаток P0 (ревью PA01 в P0.2) меняет только тест харнесса и не меняет продуктовый код,
   который читает дизайн P1.
2. Исполнение любой строки execution-sheet P1, `task:plan-design … finish` для P1 и `task:plan-audit … design` начинаются
   только после `task:plan-close 2026.09.29-№1-VAULTER-STABLE P0`. Если закрытие P0 изменит продуктовый код или
   `ForkRace` API, P1 повторно сверяется с кодом (delta assessment) до finish.
3. Порядок фаз и цикл D03 п.1, п.3 не меняются; исключение не распространяется на P2–P8.

## Why

Владелец явно вызвал дизайн P1 и выбрал этот вариант при показанном конфликте с D03. Дизайн не исполняет код и не
обходит фазовый аудит P0; гейт исполнения сохранён.

## Consequences

`roadmap.md` и `handoff.md`: Next после дизайна P1 — закрытие P0 (P0.2 → close), затем finish P1. Execution-sheet P1
содержит условие «P0 закрыта» в Checkpoint каждой строки.
