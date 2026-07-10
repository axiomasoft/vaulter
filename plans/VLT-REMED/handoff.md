# HANDOFF — 2026-07-11 — after P2.0

**Next:**

| Параметр | Значение |
|:--|:--|
| Model | opus |
| Thinking | high — P2.1 явно поимённо opus/high в `plan.md` §Routing: ядро `AccessResolver` (инвариант H.2, deny-вето, ADR-0007) — свёртка не выводится чтением по чеклисту |
| Context | fresh session — handoff свежий, рекомендован /clear |
| Суть | Исполнить **P2.1 (B12 — корректность ядра ACL, инвариант H.2)**: закрыть F-P1.1-01 (докблок «un-overridable wall» → выравнивание к ADR-0007, D17, БЕЗ смены поведения `resolveAcl`), F-P1.1-02 (диагностический `explain()` вне горячего `can()`), F-P1.2-01 (fail-loud role/group-грантов под gate/azgard). Фаза P2 РАЗВЁРНУТА (DoR закрыт) — items исполнимы. |

```
/task:plan-exec VLT-REMED P2.1
```

**Done:**
- **Фаза P0 (W0 — Blocker'ы) ТЕРМИНАЛЬНА (3/3 🟢).** По D2 допустим промежуточный мерж/релиз ветки `remediation/audit-vlt`.
- **Фаза P1 (W1 — быстрые высоко-эксплуатируемые) ТЕРМИНАЛЬНА (4/4 🟢).** Закрыты 17 находок волны W1 (детали — `phases/P1.md` Completion Notes).
- **Фаза P2 (W2 — авторизация и изоляция) РАЗВЁРНУТА из скелета (design, DoR закрыт).** 3 items детализированы (16 полей каждый), скелет-маркер снят. Кода приложения не писалось (фаза проектирования).
  - **P2.1 (B12)** — F-P1.1-01, F-P1.1-02, F-P1.2-01. opus/high (Routing). Ядро `AccessResolver`.
  - **P2.2 (B9)** — F-P1.12-01, F-P1.12-02, F-P1.12-03. sonnet/medium. Remanence корзины; F-P1.12-02 (физический purge) — **тест на PostgreSQL** (FK-каскад).
  - **P2.3 (B10)** — F-P1.10-01, F-P1.10-02, F-P1.9-01, F-P1.9-02, F-P1.5-03. sonnet/medium. Filament-скоуп + tenant-footgun + dedup NULL-tenant.
  - Несущие решения фазы: **D17** (F-P1.1-01 → ADR-0007-alignment докблока; абсолютный барьер wall-deny → Q4, не в P2.1), **D18** (F-P1.5-03 → документировать `storage_key_uniq` + guard-тест, НЕ NULL-sentinel), **D19** (форма red-before: doc-alignment/coverage-gap/invariant-guard находки — F-P1.1-01/F-P1.9-02/F-P1.5-03 — закрываются зелёным характеризационным/guard-тестом, red-before структурно неприменим).
  - Заведён **Q4** (`open-questions.md`, Decision pending): абсолютный барьер wall-deny — продуктовая фича, НЕ блокирует P2.

**Remaining:** P2 (3 items, развёрнута — исполнять P2.1→P2.2→P2.3), P3 (6, СКЕЛЕТ), P4 (10, СКЕЛЕТ), P5 (3, СКЕЛЕТ). Развёртывание КАЖДОЙ последующей фазы — `/task:plan-design VLT-REMED Pn` перед её первым item'ом (Execution Rules: скелет-фаза не исполняется напрямую). Мягкое ребро D2: B12 (P2.1) раньше B9/B10 — ядро ACL фундамент для remanence/scope-фиксов.

**Sources of truth:** `plans/VLT-REMED/plan.md` (Context/Execution Rules/Routing/Status Board/Decision Log D1–D19) ·
`plans/VLT-REMED/phases/P2.md` (РАЗВЁРНУТА, 3 items, DoR) · `plans/VLT-REMED/phases/{P0,P1}.md` (закрыты, терминальны) ·
`plans/VLT-REMED/open-questions.md` (Q3 pending, **Q4 pending** — не блокируют) ·
`plans/VLT-REMED/findings/P0-remediation-norms.md` (нормы W0, неизменны) · `plans/VLT-REMED/findings/P0-premortem.md` ·
`plans/AUDIT-VLT/findings/REGISTER.md` (SSOT 103 находок, append-only, VLT-REMED в него НЕ пишет) ·
`plans/AUDIT-VLT/findings/{P1.1-access-resolver,P1.2-role-drivers,P1.12-remanence,P1.10-filament-surface,P1.9-tenant-isolation,P1.5-cas-blobs}.md`
(доказательства 11 находок W2 с `file:line`) ·
`docs/adr/{0003-embeddability-data-model,0007-pluggable-authorization,0012-dedup-scope}.md` (несущие ADR фазы).

**Open risks:**
- W2 (авторизация/изоляция) ещё не исполнена — пакет не «чист»: trashed-узлы скачиваемы (F-P1.12-01), 4 Filament-листинга кросс-тенантны (F-P1.10-01), контент «удалённых» документов живёт в БД вечно (F-P1.12-02).
- **F-P1.12-02 (физический purge) требует PostgreSQL** для теста FK-каскада: `php8.4` + БД `vaulter_test` в контейнере (см. P0.3 handoff). SQLite по умолчанию НЕ enforce'ит FK-каскад — зелёный тест там замаскирует несработавший каскад (D12).
- Root `composer.json` phpstan/phpunit покрывают ТОЛЬКО `packages/core`. P2.1 целиком в core (root-гейт достаточен). P2.2 трогает `drive`+`documents`, P2.3 трогает `filament` — гнать их own `composer analyse`/`test`/`test:types` дополнительно. `packages/filament` имеет отдельный vendor + свой `TestCase` с Filament-панелью.
- Root `composer lint:check` красный на пред-существующем `plans/AUDIT-VLT/artifacts/repro/**` (чужой read-only план, вне scope) — closure НЕ блокирует; свой новый код обязан быть pint-чистым.
- **Абсолютный барьер wall-deny (Q4) — НЕ реализовывать в P2.1.** Если при чтении `resolveAcl` покажется, что стену «надо» сделать непробиваемой — это Q4/продуктовое решение, эскалация (`rule conflict`), НЕ импровизация. P2.1 фиксит только докблок (D17).
- `CHANGELOG.md` заблокирован для Read/Bash-инспекции прошлыми сессиями (`.claude/settings.local.json` → `deny: ["Read(**/CHANGELOG.md)"]`) — долг записей за P1.2 и P1.4. Сессия с доступом к чтению `CHANGELOG.md` должна догнать пропущенные записи.
- `packages/permissions-azgard/composer.json` не определяет `test:types`-скрипт — если item коснётся пакета, гнать только `analyse`/`test`/`lint:check`. (Драйвер `AzgardPermissionResolver` для F-P1.2-01 лежит в `packages/core/src/Drivers/Permission`, НЕ в permissions-azgard — P2.1 этот пакет не трогает.)
- В рабочем дереве присутствуют НЕ относящиеся к VLT-REMED правки (`plans/VLT-HSEAM/`, `.claude/workflows/vlt-remed-*.js`, модификации `.gitignore`/`.claude/.maind-feature-log.last`) — появились из конкурентной сессии/хука, НЕ трогать, `git status` по `plans/` вне VLT-REMED держать чистым.

**Workarounds/Deferred/Open questions:**
- workarounds: drive/documents/filament — их own `composer analyse`/`test`/`test:types` (root покрывает только core). PG-прогон для F-P1.12-02 — `php8.4` + `vaulter_test` в контейнере `agelto_postgres` (P0.3 handoff). Testbench-дефолт `cache.default=database` без миграции `cache`-таблицы → `cache.default => 'array'` в тестовом `defineEnvironment()` (throttle-middleware, P1.3). Резолюция актора из голого id — через `Auth::createUserProvider($providerName)` с провайдером дефолтного guard'а (P1.2). SQLite не enforce'ит FK-каскад по умолчанию (нужен `PRAGMA foreign_keys=ON` либо PG) — критично для F-P1.12-02.
- deferred: `docs/`-миграция и архивация AUDIT-VLT — отдельный `/task:plan-close archive AUDIT-VLT`. Ротация `signing_secret` (Q3) — отдельный item по решению владельца. Абсолютный барьер wall-deny (Q4) — отдельный item при «go» владельца. `CHANGELOG.md` записи за P1.2/P1.4 — сессия с доступом.
- open_questions: Q3 (ротация `signing_secret`) и **Q4 (абсолютный барьер wall-deny)** — оба `Decision pending`, ни один W2 не блокирует. Q1/Q2 — `Resolved → D1/D10`.
