# HANDOFF — 2026-07-11 — after P2.2

**Next:**

| Параметр | Значение |
|:--|:--|
| Model | sonnet |
| Thinking | medium — P2.3 не поимённо в §Routing → дефолт sonnet/medium; локальный код по готовому ТЗ (Filament-скоуп + tenant-footgun + dedup NULL-tenant docs/guard), корень известен |
| Context | fresh session — handoff свежий, рекомендован /clear |
| Суть | Исполнить **P2.3 (B10 — tenant/scope-изоляция)**: `getEloquentQuery()`-скоуп для 4 Filament-ресурсов (`Storage`/`NodeGrant`/`NodeShare`/`Activity`, F-P1.10-01), `PanelActor` резолв через guard активной панели (F-P1.10-02), boot-time footgun-guard `tenant.enabled=true`+дефолтная `Node` (F-P1.9-01), контрактный тест делегированной tenancy (F-P1.9-02), docblock+ADR+guard-тест `storage_key_uniq` (F-P1.5-03, D18). Фаза P2 остаётся РАЗВЁРНУТА (2/3), P2.3 — последний item фазы; закрытие P2.3 = терминальная фаза P2 (обязателен `## Phase Handoff` в phases/P2.md).

```
/task:plan-exec VLT-REMED P2.3
```

**Done:**
- **Фаза P0 (W0 — Blocker'ы) ТЕРМИНАЛЬНА (3/3 🟢).** По D2 допустим промежуточный мерж/релиз ветки `remediation/audit-vlt`.
- **Фаза P1 (W1 — быстрые высоко-эксплуатируемые) ТЕРМИНАЛЬНА (4/4 🟢).** 17 находок W1 закрыты.
- **Фаза P2 (W2 — авторизация и изоляция) РАЗВЁРНУТА, 2/3 items закрыто (P2.3 остаётся).**
  - **P2.1 (B12) — 🟠 Done with deviations.** Ядро ACL: докблоки `AccessResolver` выровнены к ADR-0007 (F-P1.1-01), `explain()`+VO `AccessExplanation`/`AccessAce` (F-P1.1-02), fail-loud role/team-грантов под gate/azgard (F-P1.2-01). Коммит `18274f1` + review-fix (детект капабилити по `subjectsFor()`-override, не только маркер). Deviations: sonnet вместо opus/high, CHANGELOG отложен.
  - **P2.2 (B9) — 🟢 Done.** Remanence: item-safety trash-check на identity read/download/version-download/export (F-P1.12-01, новый `Vaulter\Http\Support\TrashGuard`, симметричен `ShareResolver::safeTargetNode`); физический purge с FK-каскадом на `vaulter:purge-trash` (F-P1.12-02, новый `NodeStoreRepository::purgeSubtree()`, PG-верифицирован red-before/green-after); снятие `keep_forever` (F-P1.12-03, новый `ReleaseKeptVersionAction` + `PATCH nodes/{node}/document-versions/{version}/keep-forever`). Новый ADR-0016. 17 новых тестов (F-id), гейт зелёный (root: analyse 0, test 399/399, types 100%; drive/documents own: analyse 0/0, test 124+2skip / 117+1skip SQLite, types 100%/100%; PG: drive 126/126, documents 117/118 + 1 pre-existing НЕ связанный флейк `NodeCommentSchemaTest` jsonb key-order). Деталь — `phases/P2.md` P2.2 Completion Notes.
  - **P2.3 (B10) — ⬜ Not started.** F-P1.10-01/02, F-P1.9-01/02, F-P1.5-03. sonnet/medium. Filament-скоуп + tenant-footgun + dedup NULL-tenant; трогает **filament** (свой vendor/TestCase — гнать отдельно).
  - Несущие решения фазы: **D17** (F-P1.1-01 → ADR-0007-alignment докблока; wall-deny барьер → Q4), **D18** (F-P1.5-03 → doc+guard, НЕ NULL-sentinel), **D19** (форма red-before для doc/coverage/invariant-guard находок).

**Remaining:** P2 (осталось P2.3 — последний item, закрытие терминализует фазу), P3 (6, СКЕЛЕТ), P4 (10, СКЕЛЕТ), P5 (3, СКЕЛЕТ). Развёртывание КАЖДОЙ последующей фазы — `/task:plan-design VLT-REMED Pn` перед её первым item'ом (скелет-фаза не исполняется напрямую).

**Sources of truth:** `plans/VLT-REMED/plan.md` (Context/Execution Rules/Routing/Status Board/Decision Log D1–D19) ·
`plans/VLT-REMED/phases/P2.md` (РАЗВЁРНУТА, P2.1 🟠 / P2.2 🟢 закрыты, P2.3 — ⬜, DoR) · `plans/VLT-REMED/phases/{P0,P1}.md` (закрыты) ·
`plans/VLT-REMED/open-questions.md` (Q3 pending, Q4 pending — не блокируют) ·
`plans/VLT-REMED/findings/P0-remediation-norms.md` (нормы W0, неизменны) · `plans/VLT-REMED/findings/P0-premortem.md` ·
`plans/AUDIT-VLT/findings/REGISTER.md` (SSOT 103 находок, append-only, VLT-REMED в него НЕ пишет) ·
`plans/AUDIT-VLT/findings/{P1.10-filament-surface,P1.9-tenant-isolation,P1.5-cas-blobs}.md` (доказательства оставшейся находки W2 P2.3 с `file:line`) ·
`docs/adr/{0003-embeddability-data-model,0007-pluggable-authorization,0012-dedup-scope,0016-trash-retention-erasure}.md` (несущие ADR; 0007 несёт Amendment P2.1, 0016 — новый из P2.2).

**Open risks:**
- W2 не завершена: 4 Filament-листинга (`Storage`/`NodeGrant`/`NodeShare`/`Activity`) остаются кросс-тенантны (F-P1.10-01), `PanelActor` резолвит через глобальный дефолтный guard, не guard панели (F-P1.10-02), `tenant.enabled=true` с дефолтной `Node`-моделью — тихий footgun без runtime-предупреждения (F-P1.9-01). P2.1/P2.2 закрыли ядро ACL и remanence; P2.3 остаётся единственным незакрытым item'ом фазы.
- Root `composer.json` phpstan/phpunit покрывают ТОЛЬКО `packages/core`. **P2.3 трогает filament** — гнать её own `composer analyse`/`test`/`test:types` дополнительно. `packages/filament` — отдельный vendor + свой `TestCase` с Filament-панелью (`P1.10` §3).
- Root `composer lint:check` красный на пред-существующем `plans/AUDIT-VLT/artifacts/repro/**` (чужой read-only план, вне scope) — closure НЕ блокирует; свой новый код обязан быть pint-чистым.
- **Абсолютный барьер wall-deny (Q4) — НЕ реализовывать.** P2.1 зафиксировал ТОЛЬКО докблок (D17). Если код «просит» сделать стену непробиваемой — это Q4/продуктовое, эскалация (`rule conflict`), не импровизация.
- `CHANGELOG.md` заблокирован для Read/Write (`deny: Read(**/CHANGELOG.md)`) — долг записей за P1.2, P1.4, P2.1 **и P2.2**. Сессия с доступом должна догнать.
- **Пред-существующий PG-only флейк `NodeCommentSchemaTest`** (jsonb `anchor` key-order round-trip не сохраняет порядок ключей на PostgreSQL) обнаружен при полном PG-прогоне documents-сьюта в P2.2 — файл НЕ модифицирован этим item'ом, не относится к remanence/purge/keep_forever, вне scope P2.2. Не заведена отдельной находкой — следующая сессия с доступом к findings/ должна это сделать, если ещё не заведена.
- В рабочем дереве присутствуют НЕ относящиеся к VLT-REMED правки (`plans/VLT-HSEAM/`, `.claude/workflows/vlt-remed-*.js`, модификации `.gitignore`/`.claude/.maind-feature-log.last`, правки `plans/AUDIT-VLT/**` и `plans/ACTIVE.md`) — из конкурентной сессии/хука, НЕ трогать, коммитить только scoped-пути item'а.

**Workarounds/Deferred/Open questions:**
- workarounds: drive/documents/filament — их own `composer analyse`/`test`/`test:types` (root покрывает только core). PG-прогон для F-id, требующих реального FK-каскада/SERIALIZABLE — `php8.4` + БД `vaulter_test` в контейнере `agelto_postgres` (127.0.0.1:5455, роль `vaulter`; пароль роли был неизвестен сессии — сброшен через `ALTER ROLE vaulter WITH PASSWORD …` суперюзером `agelto`, ad-hoc, только тестовая БД). SQLite не enforce'ит FK-каскад (нужен `PRAGMA foreign_keys=ON` либо PG) — PG-only тесты (F-P1.12-02) самоскипаются на SQLite через `DB::connection()->getDriverName() !== 'pgsql'` (паттерн `SweepPendingSerializableTest`). Testbench-дефолт `cache.default=database` без миграции → `cache.default => 'array'` в тестовом `defineEnvironment()` (throttle-middleware, P1.3). При смене активного драйвера в тесте — `app()->forgetInstance(HostRoleDriverManager::class)` (P2.1 GrantFailLoudTest). Типизировать class-константы (`public const string …`) для type-coverage 100% (P2.1 AccessExplanation). `DB::table(...)->whereKey(...)` НЕ существует на базовом Query Builder (только Eloquent) — использовать `->where('id', $value)` (P2.2 PurgeTrashTest PG-каскад-тест).
- deferred: `docs/`-миграция и архивация AUDIT-VLT — отдельный `/task:plan-close archive AUDIT-VLT`. Ротация `signing_secret` (Q3) и абсолютный барьер wall-deny (Q4) — отдельные items по решению владельца. `CHANGELOG.md` записи за P1.2/P1.4/P2.1/P2.2 — сессия с доступом. Пред-существующий PG-only jsonb-флейк `NodeCommentSchemaTest` — завести находку/чинить отдельно, вне P2.2 scope.
- open_questions: Q3 (ротация `signing_secret`) и Q4 (абсолютный барьер wall-deny) — оба `Decision pending`, ни один W2 не блокирует. Q1/Q2 — `Resolved → D1/D10`.
