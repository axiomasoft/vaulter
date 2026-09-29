---
id: D4
date: 2026-07-10
status: accepted
item: ''
items: []
supersedes: []
superseded_by: null
---
## Решение

Схема находки фиксирована (`artifacts/finding-template.md`): `file:line` + доказательство + нарушенная норма + сверка с ADR + severity + уверенность. Реестр `findings/REGISTER.md` append-only.

## Почему

Находка без воспроизводимого доказательства не переживает полгода (pre-mortem R1.2); сводный отчёт P5.1 строится машинно из REGISTER.
