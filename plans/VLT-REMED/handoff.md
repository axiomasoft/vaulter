# HANDOFF — 2026-07-11 — after P2.1

**Next:**

| Параметр | Значение |
|:--|:--|
| Model | sonnet |
| Thinking | medium — P2.2 не поимённо в §Routing → дефолт sonnet/medium; локальный код по готовому ТЗ (remanence-фиксы download/export + физический purge), корень известен |
| Context | fresh session — handoff свежий, рекомендован /clear |
| Суть | Исполнить **P2.2 (B9 — remanence и авторизация корзины)**: item-safety trash-check на read/download/version-download/export (F-P1.12-01), физический `forceDelete` поддерева на `vaulter:purge-trash` с FK-каскадом версий (F-P1.12-02, **тест на PostgreSQL**), снятие `keep_forever` (F-P1.12-03). Фаза P2 РАЗВЁРНУТА — items исполнимы. |

```
/task:plan-exec VLT-REMED P2.2
```

**Done:**
- **Фаза P0 (W0 — Blocker'ы) ТЕРМИНАЛЬНА (3/3 🟢).** По D2 допустим промежуточный мерж/релиз ветки `remediation/audit-vlt`.
- **Фаза P1 (W1 — быстрые высоко-эксплуатируемые) ТЕРМИНАЛЬНА (4/4 🟢).** 17 находок W1 закрыты.
- **Фаза P2 (W2 — авторизация и изоляция) РАЗВЁРНУТА, 1/3 закрыто.**
  - **P2.1 (B12) — 🟠 Done with deviations.** Закрыты F-P1.1-01 (докблоки wall-deny выровнены к ADR-0007, БЕЗ смены `can()`, D17), F-P1.1-02 (`AccessResolver::explain()` + VO `AccessExplanation`/`AccessAce`, горячий путь не задет), F-P1.2-01 (fail-loud role/team-грантов под драйвером без маркера `EnumeratesRoleSubjects`: warning всегда + throw вне production; spatie несёт маркер). Amendment ADR-0007 + UPGRADE.md. 8 новых тестов (F-id). Коммит `18274f1`. Гейт зелёный (lint red только на repro/**, analyse 0, test 398/398, types 100%). **Deviations:** исполнено sonnet вместо opus/high (Routing); CHANGELOG-запись отложена (permission-deny на `Read(**/CHANGELOG.md)`) — детали `phases/P2.md` P2.1.
  - **P2.2 (B9)** — F-P1.12-01/02/03. sonnet/medium. Remanence корзины; трогает **drive + documents** (гнать их own `composer analyse/test/test:types`); F-P1.12-02 (физический purge) — **тест на PostgreSQL** (FK-каскад).
  - **P2.3 (B10)** — F-P1.10-01/02, F-P1.9-01/02, F-P1.5-03. sonnet/medium. Filament-скоуп + tenant-footgun + dedup NULL-tenant; трогает **filament** (свой vendor/TestCase — гнать отдельно).
  - Несущие решения фазы: **D17** (F-P1.1-01 → ADR-0007-alignment докблока; wall-deny барьер → Q4), **D18** (F-P1.5-03 → doc+guard, НЕ NULL-sentinel), **D19** (форма red-before для doc/coverage/invariant-guard находок).

**Remaining:** P2 (осталось P2.2, P2.3 — исполнять по порядку), P3 (6, СКЕЛЕТ), P4 (10, СКЕЛЕТ), P5 (3, СКЕЛЕТ). Развёртывание КАЖДОЙ последующей фазы — `/task:plan-design VLT-REMED Pn` перед её первым item'ом (скелет-фаза не исполняется напрямую). Мягкое ребро D2: B12 (P2.1) закрыт — фундамент ACL для remanence/scope готов.

**Sources of truth:** `plans/VLT-REMED/plan.md` (Context/Execution Rules/Routing/Status Board/Decision Log D1–D19) ·
`plans/VLT-REMED/phases/P2.md` (РАЗВЁРНУТА, P2.1 закрыт 🟠, P2.2/P2.3 — ⬜, DoR) · `plans/VLT-REMED/phases/{P0,P1}.md` (закрыты) ·
`plans/VLT-REMED/open-questions.md` (Q3 pending, Q4 pending — не блокируют) ·
`plans/VLT-REMED/findings/P0-remediation-norms.md` (нормы W0, неизменны) · `plans/VLT-REMED/findings/P0-premortem.md` ·
`plans/AUDIT-VLT/findings/REGISTER.md` (SSOT 103 находок, append-only, VLT-REMED в него НЕ пишет) ·
`plans/AUDIT-VLT/findings/{P1.12-remanence,P1.10-filament-surface,P1.9-tenant-isolation,P1.5-cas-blobs}.md`
(доказательства оставшихся находок W2 с `file:line`) ·
`docs/adr/{0003-embeddability-data-model,0006-content-addressed-blobs-gc,0007-pluggable-authorization,0012-dedup-scope}.md` (несущие ADR; 0007 несёт Amendment P2.1).

**Open risks:**
- W2 не завершена: trashed-узлы всё ещё скачиваемы identity-путём (F-P1.12-01), контент «удалённых» документов живёт в БД вечно (F-P1.12-02), 4 Filament-листинга кросс-тенантны (F-P1.10-01). P2.1 закрыл только ядро ACL (докблоки/explain/fail-loud), НЕ remanence/scope.
- **F-P1.12-02 (физический purge) требует PostgreSQL** для теста FK-каскада: `php8.4` + БД `vaulter_test` в контейнере (см. P0.3 handoff). SQLite по умолчанию НЕ enforce'ит FK-каскад — зелёный тест там замаскирует несработавший каскад (D12/Code Guidance P2.2).
- Root `composer.json` phpstan/phpunit покрывают ТОЛЬКО `packages/core`. **P2.2 трогает drive+documents, P2.3 трогает filament** — гнать их own `composer analyse`/`test`/`test:types` дополнительно. `packages/filament` — отдельный vendor + свой `TestCase` с Filament-панелью. `packages/permissions-azgard` не определяет `test:types`-скрипт.
- Root `composer lint:check` красный на пред-существующем `plans/AUDIT-VLT/artifacts/repro/**` (чужой read-only план, вне scope) — closure НЕ блокирует; свой новый код обязан быть pint-чистым.
- **Абсолютный барьер wall-deny (Q4) — НЕ реализовывать.** P2.1 зафиксировал ТОЛЬКО докблок (D17). Если код «просит» сделать стену непробиваемой — это Q4/продуктовое, эскалация (`rule conflict`), не импровизация.
- `CHANGELOG.md` заблокирован для Read/Write (`deny: Read(**/CHANGELOG.md)`) — долг записей за P1.2, P1.4 **и P2.1**. Сессия с доступом должна догнать.
- В рабочем дереве присутствуют НЕ относящиеся к VLT-REMED правки (`plans/VLT-HSEAM/`, `.claude/workflows/vlt-remed-*.js`, модификации `.gitignore`/`.claude/.maind-feature-log.last`, правки `plans/AUDIT-VLT/**` и `plans/ACTIVE.md`) — из конкурентной сессии/хука, НЕ трогать, коммитить только scoped-пути item'а.

**Workarounds/Deferred/Open questions:**
- workarounds: drive/documents/filament — их own `composer analyse`/`test`/`test:types` (root покрывает только core). PG-прогон для F-P1.12-02 — `php8.4` + `vaulter_test` в контейнере (P0.3 handoff). SQLite не enforce'ит FK-каскад (нужен `PRAGMA foreign_keys=ON` либо PG). Testbench-дефолт `cache.default=database` без миграции → `cache.default => 'array'` в тестовом `defineEnvironment()` (throttle-middleware, P1.3). При смене активного драйвера в тесте — `app()->forgetInstance(HostRoleDriverManager::class)` (P2.1 GrantFailLoudTest). Типизировать class-константы (`public const string …`) для type-coverage 100% (P2.1 AccessExplanation).
- deferred: `docs/`-миграция и архивация AUDIT-VLT — отдельный `/task:plan-close archive AUDIT-VLT`. Ротация `signing_secret` (Q3) и абсолютный барьер wall-deny (Q4) — отдельные items по решению владельца. `CHANGELOG.md` записи за P1.2/P1.4/P2.1 — сессия с доступом.
- open_questions: Q3 (ротация `signing_secret`) и Q4 (абсолютный барьер wall-deny) — оба `Decision pending`, ни один W2 не блокирует. Q1/Q2 — `Resolved → D1/D10`.
