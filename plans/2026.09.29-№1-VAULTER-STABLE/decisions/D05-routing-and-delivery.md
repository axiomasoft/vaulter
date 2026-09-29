---
id: D05
date: 2026-09-29
status: accepted
item: P0
items: [P0, P1, P2, P3, P4, P5, P6, P7, P8]
supersedes: []
superseded_by: null
---
# D05 — Маршрутизация моделей и доставка изменений (accepted)

**Actor:** author-choice (claude-opus-5-5/high).
**Evidence:** RAG:— OPUS `13-workstreams.md` (правила 1–6, маршрутизация opus/sonnet/haiku); `corex:PLAN3` `session-routing.md`;
`.swissknife.json` (providers claude-code, codex, cursor); история `main` (слияния через PR #47, #48).

## Решение

1. **Классы.** Маршрут аудита переводится в семантический класс Routing: `opus` → `frontier`, `sonnet` →
   `implementation`, `haiku` → `economy`; effort — из аудита (`low|medium|high`), `xhigh` — для массовых механических
   переносов ≥ ~200 файлов. Понижение класса/effort — только D#.
2. **Review.** `full` (независимая модель, свежий контекст) — для items, меняющих доступ/безопасность, конкурентность,
   миграции данных или публичный API, и для фундамента сетки (харнесс P0.2). Остальное — `light` (self-check +
   затронутые тесты). Reviewer ≠ executor по модели.
3. **Доставка (вместо OPUS 13 правила 1 «один пункт — одна ветка/PR»).** Ветка на фазу `plan2/<Pn>`; один scoped commit
   на item (Conventional Commits, русский текст, `Co-Authored-By` по правилам сессии); PR на фазу после GREEN фазового
   аудита. Push, PR и merge — **owner gate O-3**. Гейт затронутых пакетов (Pint, PHPStan, Pest; для Vue — `vue-tsc`,
   vitest, build) зелёный до коммита — правило 1 OPUS в остальном сохраняется.

## Почему

Фазовый аудит проверяет фазу целиком; PR на item дробит ревью и множит конфликты golden-снимков. Классы Routing
provider-neutral по протоколу Task.

## Последствия

`session-routing.md` задаёт пул моделей; `roadmap.md` `execution-sheet/v1` — конкретного исполнителя и ревьюера на строку.
