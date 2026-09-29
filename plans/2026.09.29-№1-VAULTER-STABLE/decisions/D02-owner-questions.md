---
id: D02
date: 2026-09-29
status: accepted
item: P1
items: [P1, P1.11, P2, P4, P7, P8]
supersedes: []
superseded_by: null
---
# D02 — Вопросы владельцу Q1–Q15: решения по умолчанию; Q1 закрыт фактами (accepted)

**Actor:** author-choice (claude-opus-5-5/high). Владелец может переопределить любой пункт новым D#.
**Evidence:** RAG:— OPUS `15-owner-questions.md`; `git tag` → только `v0.2.0`; RAG:✅ HTTP 2026-09-29:
`https://repo.packagist.org/p2/axioma-studio/vaulter-core.json` → 404, `https://registry.npmjs.org/@vaulter%2fvue` → 404;
ни один `composer.json` в `~/projects/*` и `~/projects/*/*` (кроме самого vaulter) не требует `vaulter`.

## Решение

1. **Q1 — релиза 0.2.1 нет.** 0.2.0 не опубликован в Packagist/npm, локальных потребителей нет. Фаза P1 входит в
   **0.3.0** (AD01); цепочка upgrade-миграций — одна (OPUS 08 §3, без выделения №3/№7/ремонта N04 в 0.2.1).
2. **P1.11 сужается** до метаданных: URL `axiomasoft` в `composer.json`/`package.json`/`VaulterException::docs()`/install
   command. Переписывание README под 0.2 API не делается — README пишется с нуля в P7.6 (OPUS 12 §4, §8).
3. Q2–Q15 — решения по умолчанию OPUS 15 действуют без изменений: Q2 «данные 0.2 возможны» (строгий preflight,
   upgrade-миграции, `repair-scopes` обязательны); Q3 бит `Comment`; Q4 удалить own-tables; Q5 удалить `table`/`template`;
   Q6 `auth:sanctum` по умолчанию; Q7 удалить Reverb-драйвер коллаборации; Q8 outbox откатывает мутацию; Q9 `vaulter-azgard`
   выходит только при теге AzGuard (owner gate O-4); Q10 `DELETE` = purge; Q11 планировщик включён; Q12 MySQL
   «поддерживается»; Q13 Laravel 12 сохраняется; Q14 OpenAPI вручную; Q15 префикс `vaulter/`.

## Почему

Отдельный 0.2.1 без внешних установок — лишний релиз и две цепочки миграций; README под API, который удаляется в той же
0.3, — выброшенная работа.

## Последствия

UPGRADE.md описывает один переход 0.2 → 0.3. Если владелец сообщит о внешних установках 0.2 — новый D# возвращает 0.2.1.
