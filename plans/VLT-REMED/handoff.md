# HANDOFF — 2026-07-11 — after P2.3

**Next:**

| Параметр | Значение |
|:--|:--|
| Model | opus |
| Thinking | high — развёртывание скелет-фазы P3 (6 items, W3 бэклога) требует читать 5 батчей (B11→B4, B6, B7, B22, B23) и решить их ТЗ/routing — не механическая работа |
| Context | fresh session — handoff свежий, рекомендован /clear |
| Суть | Развернуть фазу **P3 (W3 — надстройки и интеграция)** из скелета: детализировать 6 items по батчам бэклога (жёсткое ребро B11→B4 из D2), снять DoR, проставить Routing по умолчанию (sonnet/medium, если не найдётся триггер эскалации из `plan-protocol` §9/10) |

```
/task:plan-design VLT-REMED P3
```

**Done:**
- **Фаза P0 (W0 — Blocker'ы) ТЕРМИНАЛЬНА (3/3 🟢).** По D2 допустим промежуточный мерж/релиз ветки `remediation/audit-vlt`.
- **Фаза P1 (W1 — быстрые высоко-эксплуатируемые) ТЕРМИНАЛЬНА (4/4 🟢).** 17 находок W1 закрыты.
- **Фаза P2 (W2 — авторизация и изоляция) ТЕРМИНАЛЬНА (3/3, 🟠 Done with deviations).** 11 находок W2 закрыты.
  - **P2.1 (B12) — 🟠 Done with deviations.** Ядро ACL: докблоки `AccessResolver` выровнены к ADR-0007 (F-P1.1-01), `explain()`+VO `AccessExplanation`/`AccessAce` (F-P1.1-02), fail-loud role/team-грантов под gate/azgard (F-P1.2-01). Коммит `18274f1` + review-fix. Deviation: sonnet вместо opus/high (Routing), CHANGELOG отложен.
  - **P2.2 (B9) — 🟢 Done.** Item-safety trash-check на identity read/download/version-download/export (F-P1.12-01, `Vaulter\Http\Support\TrashGuard`); физический purge с FK-каскадом версий на `vaulter:purge-trash` (F-P1.12-02, `NodeStoreRepository::purgeSubtree()`, PG-верифицирован); снятие `keep_forever` (F-P1.12-03, `ReleaseKeptVersionAction`). Новый ADR-0016.
  - **P2.3 (B10) — 🟢 Done.** Filament: 4 листинга (`Storage`/`NodeGrant`/`NodeShare`/`Activity`) скоупятся к grant-reachable candidate-set актора через новый `PanelAccessScope` (F-P1.10-01); `PanelActor` резолвит через guard активной панели, не глобальный дефолт (F-P1.10-02); boot-time `TenantFootgunGuard` — warning всегда + throw вне production при `tenant.enabled=true` + дефолтная `Node` (F-P1.9-01); контрактный тест делегированной tenancy в `packages/core/tests/Access/TenantDelegationContractTest.php` (F-P1.9-02); докблоки+ADR-0012 Amendment+guard-тест — `storage_key_uniq` истинный backbone дедупа для NULL-tenant (F-P1.5-03). 14 новых тестов. Amendment ADR-0003/ADR-0012. Деталь — `phases/P2.md` P2.3 Completion Notes + `## Phase Handoff`.
  - Несущие решения фазы: **D17** (F-P1.1-01 → ADR-0007-alignment докблока; wall-deny барьер → Q4), **D18** (F-P1.5-03 → doc+guard, НЕ NULL-sentinel), **D19** (форма red-before для doc/coverage/invariant-guard находок).

**Remaining:** P3 (6, СКЕЛЕТ), P4 (10, СКЕЛЕТ), P5 (3, СКЕЛЕТ). Развёртывание КАЖДОЙ последующей фазы — `/task:plan-design VLT-REMED Pn` перед её первым item'ом (скелет-фаза не исполняется напрямую).

**Sources of truth:** `plans/VLT-REMED/plan.md` (Context/Execution Rules/Routing/Status Board/Decision Log D1–D19) ·
`plans/VLT-REMED/phases/P2.md` (ТЕРМИНАЛЬНА, P2.1 🟠 / P2.2 🟢 / P2.3 🟢, `## Phase Handoff` заполнен) · `plans/VLT-REMED/phases/{P0,P1}.md` (закрыты) ·
`plans/VLT-REMED/open-questions.md` (Q3 pending, Q4 pending — не блокируют) ·
`plans/VLT-REMED/findings/P0-remediation-norms.md` (нормы W0, неизменны) · `plans/VLT-REMED/findings/P0-premortem.md` ·
`plans/AUDIT-VLT/findings/REGISTER.md` (SSOT 103 находок, append-only, VLT-REMED в него НЕ пишет) ·
`plans/AUDIT-VLT/findings/P5.2-remediation-backlog.md` §5 (батчи B4/B6/B7/B11/B22/B23 → items P3, ребро B11→B4) ·
`docs/adr/{0003-embeddability-data-model,0007-pluggable-authorization,0012-dedup-scope,0016-trash-retention-erasure}.md` (несущие ADR; 0003 несёт Amendment P2.3 footgun-guard, 0007 — Amendment P2.1, 0012 — Amendment P2.3 NULL-tenant backbone, 0016 — новый из P2.2).

**Open risks:**
- **W2 полностью закрыта** — фаза P2 больше не открытый риск. Следующий открытый вопрос — развёртывание P3.
- Root `composer.json` phpstan/phpunit покрывают ТОЛЬКО `packages/core`. P2.3 тронула filament (свой vendor/TestCase) и media (`BlobStore.php`) — их own гейты прогнаны и зелёные (детали в Completion Notes P2.3); drive/documents тоже анти-регресс-прогнаны (не тронуты, оба зелёные).
- Root `composer lint:check` красный на пред-существующем `plans/AUDIT-VLT/artifacts/repro/**` (чужой read-only план, вне scope) — closure НЕ блокирует; свой новый код обязан быть pint-чистым (проверено).
- **Абсолютный барьер wall-deny (Q4) — НЕ реализовывать.** P2.1 зафиксировал ТОЛЬКО докблок (D17). Если код «просит» сделать стену непробиваемой — это Q4/продуктовое, эскалация (`rule conflict`), не импровизация.
- `CHANGELOG.md` заблокирован для Read/Write (`deny: Read(**/CHANGELOG.md)`) — долг записей за P1.2, P1.4, P2.1, P2.2 **и P2.3**. `UPGRADE.md` покрывает breaking-change-документирование по D3 для всех четырёх. Сессия с доступом должна догнать CHANGELOG.md.
- **Пред-существующий PG-only флейк `NodeCommentSchemaTest`** (jsonb `anchor` key-order round-trip не сохраняет порядок ключей на PostgreSQL), обнаруженный при полном PG-прогоне documents-сьюта в P2.2 — вне scope P2.2/P2.3, не заведён отдельной находкой. Следующая сессия с доступом к `findings/` должна это сделать, если ещё не заведена.
- В рабочем дереве присутствуют НЕ относящиеся к VLT-REMED правки (`plans/VLT-HSEAM/`, `.claude/workflows/vlt-remed-*.js`, модификации `.gitignore`/`.claude/.maind-feature-log.last`, правки `plans/AUDIT-VLT/**` и `plans/ACTIVE.md`) — из конкурентной сессии/хука, НЕ трогать, коммитить только scoped-пути item'а (соблюдено — P2.3 закоммитил только свои scoped-файлы).
- **Следующая фаза P3 — СКЕЛЕТ.** Прямое исполнение `/task:plan-exec VLT-REMED P3.1` упадёт на предусловии «скелет-фаза не исполняется». Обязателен `/task:plan-design VLT-REMED P3` первым шагом.

**Workarounds/Deferred/Open questions:**
- workarounds: drive/documents/filament/media — их own `composer analyse`/`test`/`test:types` (root покрывает только core). PG-прогон для F-id, требующих реального FK-каскада/SERIALIZABLE — `php8.4` + БД `vaulter_test` в контейнере `agelto_postgres` (127.0.0.1:5455, роль `vaulter`). SQLite не enforce'ит FK-каскад — PG-only тесты самоскипаются через `DB::connection()->getDriverName() !== 'pgsql'`. Testbench-дефолт `cache.default=database` без миграции → `cache.default => 'array'` в тестовом `defineEnvironment()`. При смене активного драйвера в тесте — `app()->forgetInstance(HostRoleDriverManager::class)` (+ `app()->forgetInstance(NodeReadRepository::class)` — P2.3 `PanelAccessScope`/`candidateNodeIds()` резолвится через контейнер, кэш репозитория переживает смену драйвера иначе). **Новое в P2.3:** тестовые фикстуры, которые ЗНАЮТ, что гоняют `tenant.enabled=true` с дефолтной моделью НЕ ради изоляции (напр. `UlidTestCase`/schema-пробы) — обязаны swap'нуть `vaulter.models.node` на `Vaulter\Tests\Fixtures\TenantAwareNode` (или свой эквивалент) в `defineEnvironment()`/до boot, иначе `TenantFootgunGuard` бросит `TenantIsolationNotConfiguredException` при старте приложения (non-production throws by design, F-P1.9-01) — раз `defineEnvironment()` выполняется ДО boot провайдеров в Orchestra Testbench. Filament-тест на non-default guard — `Filament::getPanel('admin')->authGuard('other')` мутирует ТОТ ЖЕ Panel-инстанс, что резолвит `FilamentManager::getDefaultPanel()` (общий `PanelRegistry`) — не нужен отдельный `setCurrentPanel()`.
- deferred: `docs/`-миграция и архивация AUDIT-VLT — отдельный `/task:plan-close archive AUDIT-VLT`. Ротация `signing_secret` (Q3) и абсолютный барьер wall-deny (Q4) — отдельные items по решению владельца. `CHANGELOG.md` записи за P1.2/P1.4/P2.1/P2.2/P2.3 — сессия с доступом. Пред-существующий PG-only jsonb-флейк `NodeCommentSchemaTest` — завести находку/чинить отдельно, вне scope W2.
- open_questions: Q3 (ротация `signing_secret`) и Q4 (абсолютный барьер wall-deny) — оба `Decision pending`, ни один W2 не блокировал (W2 теперь закрыта). Q1/Q2 — `Resolved → D1/D10`.
