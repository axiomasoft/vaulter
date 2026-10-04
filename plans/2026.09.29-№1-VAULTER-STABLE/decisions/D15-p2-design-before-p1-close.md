---
id: D15
date: 2026-10-04
status: accepted
item: P2
items: [P1, P2]
supersedes: []
superseded_by: null
---
# D15 — Дизайн P2 до закрытия P1; исполнение P2 — после закрытия P1 (accepted)

**Actor:** repository owner (выбор варианта в сессии `task:plan-design 2026.09.29-№1-VAULTER-STABLE P2`, 2026-10-04);
фиксирует claude-opus-5-5/high.
**Evidence:** `handoff.md` (Next: `task:plan-close … P1`; остаток — O-3-CI и классифицированная MySQL-девиация P1.7);
`status.md` (P1.1–P1.11 исполнены, P1.7 🟠); D03 п.2 и D12 п.3 (исключение D12 не распространяется на P2).

## Solution

1. Исключение из D03 п.2 только для P2: `phases/P2/` детализируется сейчас по коду `main` @ `938f0ea`, пока P1 открыта.
   Продуктовый код всех items P1 уже в `main`; незакрытый остаток P1 — hosted CI (O-3-CI) и фазовый аудит.
2. `task:plan-design … finish` для P2, design audit P2 и исполнение любой строки execution-sheet P2 начинаются только
   после `task:plan-close 2026.09.29-№1-VAULTER-STABLE P1`. Если закрытие P1 (ремонт по фазовому аудиту, hosted CI)
   меняет продуктовый код, до finish выполняется delta assessment: затронутые item-контракты P2 сверяются с кодом,
   изменения фиксируются в Update Log `plan.md`.
3. Порядок фаз и цикл D03 п.1, п.3 не меняются; исключение не распространяется на P3–P8.

## Why

Владелец явно вызвал дизайн P2 и выбрал этот вариант при показанном конфликте с D03/D12. Дизайн не исполняет код и
не обходит фазовый аудит P1; гейт исполнения сохранён (тот же механизм, что D12 для P1).

## Consequences

`handoff.md` после дизайна P2: Next — закрытие P1, затем finish P2. Каждая строка execution-sheet P2 содержит в
Checkpoint условие «P1 закрыта (D15)».
