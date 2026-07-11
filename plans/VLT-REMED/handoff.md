# HANDOFF — 2026-07-11 — after P4.3

**Next:** /task:plan-exec VLT-REMED P4.4

| Параметр | Значение |
|:--|:--|
| Model | sonnet |
| Thinking | medium (дефолт §Routing — локальный код по готовому ТЗ, корень известен) |
| Context | fresh session — handoff свежий, рекомендован /clear |
| Суть | Исполнить **P4.4 (B17)** строго по ТЗ `phases/P4.md`: семь «молчащих» швов (докблок ≠ код) по направлениям D22 — часть кодом (eager-резолв `PanelRegistry` на boot, `registerExporter()` принимает FQCN, доменное исключение с install-hint на кросс-пакетных швах, удаление мёртвого `StorageAdapter`), часть докблоком (`fillNode/presentNode/pipes` → `@unimplemented`, `gateAllows` под факт). Item трогает `packages/documents` — свой гейт валидации обязателен. |

```
/task:plan-exec VLT-REMED P4.4
```

**Done:**
- **Фаза P0 (W0 — Blocker'ы) ТЕРМИНАЛЬНА (3/3 🟢).**
- **Фаза P1 (W1 — быстрые высоко-эксплуатируемые) ТЕРМИНАЛЬНА (4/4 🟢).**
- **Фаза P2 (W2 — авторизация и изоляция) ТЕРМИНАЛЬНА (3/3, 🟠 Done with deviations).**
- **Фаза P3 (W3 — надстройки и интеграция) ТЕРМИНАЛЬНА (6/6 🟢).**
- **Фаза P4 (W4) В РАБОТЕ (2/10 🟢, 1/10 🔴 Blocked-формально):**
  - **P4.1 (B24 — Supply-chain, CI-матрица, arch- и mutation-гейты) — 🔴 Blocked**
    (коммит `74294e0`), 13 из 14 находок B24 закрыты штатно, заблокирована только
    F-P4.5-02 (hocuspocus-мажор, эскалирован по D6-гарду, `⛔ Skipped by decision`,
    реверчен на `^2.15`/`^2.13`). Полные детали — `phases/P4.md` P4.1 Completion Notes
    + ESCALATION-блок.
  - **P4.2 (B15 — Безопасность и корректность запросов) — 🟢 Done** (коммит `cd31116`):
    LIKE-wildcard-экранирование в `NodeReadRepository::search()` (F-P1.8-03) +
    `Node::enableCycleDetection(): true` (F-P4.2-02). Полные Completion Notes —
    `phases/P4.md` P4.2.
  - **P4.3 (B16 — Индексы и пагинация) — 🟢 Done** (коммит `408e17f`):
    (1) F-P4.1-01 — новая миграция `2026_07_11_000001_optimize_hot_path_indexes.php`
    (идемпотентна через `Schema::getIndexes()`) + `PanelTablesBuilder::addTrashedAtIndex()`
    эмитят партиционный индекс `(trashed_at) WHERE trashed_at IS NOT NULL` на `v_nodes`
    — `PurgeTrashCommand` больше не сканирует всю таблицу узлов на каждый плановый
    прогон. (2) F-P4.1-02 — сняты три индекса-дубля: `v_nodes.(storage_id,parent_id)`
    (строгий префикс составного), `v_blobs.status` (строгий префикс `_gc_idx`), второй
    `created_at` на `v_activity` (буквальный дубль `_created_idx`) — правка
    `PanelTablesBuilder::defineNodes/defineBlobs/defineActivity` убирает эмиссию у
    источника (фреш-инсталляции больше не воспроизводят дубль), миграция чинит уже
    смигрированные БД. (3) F-P4.2-01 — `withMaxDepth(NodeReadRepository::DEFAULT_MAX_DEPTH)`
    на `AccessResolver::ancestorChain()` + `NodeReadRepository::ancestors()`/`breadcrumb()`
    — симметрично с `descendants()`/`subtree()` (ADR-0005). (4) F-P4.2-05 — вторичный
    `orderBy('id')`/`orderByDesc('id')` в `search()`/`trashed()`/`GrantReadRepository::forNode()`
    (паттерн `Activity::scopeNewestFirst()`). Тесты: `HotPathIndexesTest.php` (4),
    `NodeAncestorMaxDepthTest.php` (3), `CursorTieBreakerTest.php` (3) — все с F-id,
    red-before подтверждён вручную (`git stash` фикса → 6/10 упали → `git stash pop`).
    Прогнано на SQLite (composer test 435/435, test:types 100%) и PostgreSQL
    (`agelto_postgres`, `php8.4`) — целевые 10 тестов зелёные. **Pending Work** этого
    item'а несёт задокументированный ПРЕД-СУЩЕСТВУЮЩИЙ count-зависимый PG-флейк
    (`MassAssignmentHardeningTest`), доказанно НЕ связанный с P4.3-кодом (воспроизведён
    инертным пустым тестом без единой строчки диффа) — см. `phases/P4.md` P4.3
    Completion/Pending Work.

**Remaining:** (порядок исполнения P4)
1. ~~**P4.1**~~ — исполнен, 🔴 Blocked ТОЛЬКО формально (plan-lint ESCALATION-инвариант).
2. ~~**P4.2**~~ — исполнен, 🟢 Done.
3. ~~**P4.3**~~ — исполнен, 🟢 Done.
4. **P4.4** (B17) — семь молчащих швов по D22 (5 код + 2 докблок). **← следующий**.
5. **P4.5** (B18) — расширяемость `DriveNameRules`/lang-ключ/`filenameFor`.
6. **P4.6** (B20) — докблок фасада (12 методов), FQCN в доке, статкеш за инстанс.
7. **P4.7** (B21) — split-changelog-конфиг, deprecation-policy, `@vaulter/vue` peerDeps, сток `UPGRADE.md`.
8. **P4.8** (B19, sonnet/low) — docs-drift (9 находок, doc-guard-инварианты).
9. **P4.9** (opus/high, D10) — канон A: инвентарь + ADR-0017 + arch-тесты (БЕЗ переноса).
10. **P4.10** (opus/high, D10) — namespace-refactor к канону (gate: P4.9 закрыт; `namespace-refactor-safety`).

Затем **P5** (3 items, СКЕЛЕТ — требует `/task:plan-design VLT-REMED P5`, после P4). Скелет-фаза не
исполняется напрямую (`plan.md` §Execution Rules).

**Открытый пункт (не блокирует P4.4, требует внимания владельца/будущей сессии):**
**F-P4.5-02** (hocuspocus `2.x→4.x`) остаётся на `⛔ Skipped by decision` — см. ESCALATION-блок
`phases/P4.md` P4.1 и Q-заводку ниже. `/task:plan-design VLT-REMED` (новый отдельный план на
hocuspocus-мажор) — по решению владельца, вне текущего remaining-списка P4.

**Sources of truth:** `plans/VLT-REMED/plan.md` (Context/Execution Rules/Routing/Status Board — P3
терминальна 6/6, P4 в работе 2/10🟢+1/10🔴 / Decision Log D1–D23 / Update Log с записью P4.3) ·
`plans/VLT-REMED/phases/P4.md` (P4.1 закрыт с ESCALATION-блоком; P4.2/P4.3 закрыты 🟢; P4.4–P4.10
детализированы, готовы к исполнению) · `plans/VLT-REMED/phases/{P0,P1,P2,P3}.md` (закрыты; P2 🟠) ·
`plans/VLT-REMED/open-questions.md` (Q3/Q4 pending — не блокируют P4) ·
`plans/AUDIT-VLT/findings/REGISTER.md` (SSOT 103 находок, append-only, VLT-REMED в него НЕ пишет) ·
`plans/AUDIT-VLT/findings/{P3.2-panel-seam,P3.3-handler-exporter-seam,P3.5-driver-seams,`
`P3.1-naming-seam,P1.2-role-drivers}.md` (детальные находки батча B17 — Required Reads P4.4 —
F-P3.2-01/02, F-P3.3-01/02, F-P3.5-01, F-P3.1-03, F-P1.2-02) · `docs/adr/0009-storage-panels.md`
(норма «panel validated on boot» — Required Read P4.4) · `UPGRADE.md` (4 записи P4.1 + 1 запись
P4.2 — LIKE-escape + cycle-detection behaviour-change; P4.3 не добавляла запись — индексы/withMaxDepth/
тай-брейкер не меняют наблюдаемый публичный контракт, только перф/корректность внутри существующего
поведения).

**Open risks:**
- Root `composer.json` phpstan/phpunit покрывают ТОЛЬКО `packages/core`. P4.4 трогает
  `packages/documents` (`DocumentHandlerRegistry::registerExporter`) — обязателен свой
  `cd packages/documents && composer analyse && composer test && composer test:types`.
  P4.9/P4.10 трогают ВСЕ пакеты — прогон по каждому.
- Root `composer lint:check` красный на пред-существующем `plans/AUDIT-VLT/artifacts/repro/**` (чужой
  read-only план, вне scope) — closure НЕ блокирует; свой новый код обязан быть pint-чистым
  (`vendor/bin/pint --test <файлы>`).
- **`CHANGELOG.md` заблокирован для Read/Write** (`deny: Read(**/CHANGELOG.md)`) — долг записей за
  P1.2/P1.4/P2.1/P2.2/P2.3/P3.1-P3.6/P4.1/P4.2 и далее. **F-P2.1-06 (P4.7) чинится КОНФИГОМ
  split-пайплайна** (D23), не наполнением файла; наполнение — Pending Work, сессия с доступом.
  `UPGRADE.md` (не `UPGRADING.md`, D23) — канонический сток breaking-changes (5 записей: 4 из P4.1
  + 1 из P4.2; P4.3 новую запись не добавила — behaviour-preserving перф/корректность-фикс).
- **Локальная песочница exec-сессий не несёт coverage-драйвера (xdebug/pcov)** — ни `php` (8.5), ни
  `php8.4` не имеют модуля; `sudo`/`pecl` заблокированы harness'ом. Реальный измеренный MSI для
  P4.1's `infection.json5` (root + 4 per-package) НЕДОСТИЖИМ локально — используется провизорный
  порог 50/70 (advisory job, не блокирует CI). Фактический MSI можно узнать только из реального
  прогона `mutation.yml`/сателлитных workflow в CI (там PHP 8.4 + xdebug).
- **Пред-существующий count-зависимый PG-флейк тестового харнесса (обнаружен в P4.3, вне scope
  P4.3 чинить).** Полный прогон `composer test` НА POSTGRES (`agelto_postgres`, `DB_CONNECTION=pgsql`)
  даёт спорадический 1 failure — `MassAssignmentHardeningTest`, последний кейс
  (`WebhookEndpoint.signing_secret`), `SQLSTATE[25P02]` «current transaction is aborted» на
  СЛЕДУЮЩЕЙ проверке (`select max(batch) from migrations`). Триггер — НЕ логика P4.3: добавление
  СОДЕРЖАТЕЛЬНО ПУСТОГО `it('x', fn() => expect(true)->toBeTrue())` в ЛЮБОЙ существующий файл
  `packages/core/tests/Schema/` ДО `MassAssignmentHardeningTest` по алфавиту (проверено на
  `BlobDedupScopeTest.php`, откачено чисто без диффа) реплицирует ТОТ ЖЕ failure; тот же инертный
  тест ПОСЛЕ него (алфавитно) — не реплицирует. Это порядко-/count-зависимая хрупкость PG-транзакции
  внутри самого `MassAssignmentHardeningTest` (ожидаемый `QueryException` от NOT NULL нарушения
  оставляет транзакцию Postgres aborted; эта абортация протекает в СЛЕДУЮЩИЙ тест-кейс/класс при
  сдвиге его позиции в общем прогоне) — аналогична уже задокументированному PG-флейку
  `NodeCommentSchemaTest` (jsonb `anchor` key-order round-trip, ниже). Новая находка НЕ заводится
  (не поведенческий дефект пакета, инфраструктурная хрупкость тестового харнесса); будущая сессия,
  занимающаяся стабилизацией PG CI, должна начать здесь. `composer test` (SQLite, дефолтный гейт
  Validation) НЕ подвержен — 435/435 зелёных на каждом item'е W4 до сих пор.
- **P4.10 — межпакетный namespace-refactor (closed-enum escalation-триггер, opus/high, D10).** Самый
  вероятный провал (pre-mortem R-refactor) — забытый morph-алиас: полиморфные строки в БД держат старый
  FQCN, связи молча ломаются в проде при зелёных тестах на свежей БД. Обязателен morph-инвариант-тест на
  PG. Доказательство неизменности поведения — зелёный сьют БЕЗ правки тестов сверх `use`-строк.
- **F-P4.5-02 (hocuspocus-мажор) эскалирован в P4.1** — стек РЕВЕРЧЕН обратно на `^2.15`/`^2.13`
  (hocuspocus-server, packages/vue, e2e). Если будущая сессия/владелец решит развернуть отдельный
  план на этот мажор — начинать с `hocuspocus-server/test/websocket-hardening.test.js` (v2.x-специфичная
  форма `listen(port, host, websocketOptions)`), это первое, что ломается на v4.
- **Пред-существующий Rector-долг** (112 файлов core/drive/media/documents + 2 filament),
  обнаруженный подключением гейта в P4.1, НЕ вычищен (вне Scope Included P4.1) — Rector CI-шаг
  остаётся advisory (`|| true`) именно по этой причине. Не заводить как «регрессию P4.1» в ревью —
  долг пред-существовал, просто не был виден без CI-гейта.
- **Остаточный `npm audit --audit-level=high` в `packages/vue`** (1 high, `vite<=6.4.2`↔esbuild
  dev-server-only, GHSA-67mh-4wv8-2f99) — critical happy-dom RCE ЗАКРЫТ, это отдельный, более
  старый и менее опасный (dev-server-only, moderate по факту) остаток; полное закрытие требует
  `vite-plugin-dts` релиза с поддержкой `vite@8` (апстрим, отслеживать).
- Testbench-снаги (унаследованы из P1.3/P3.1): `cache.default => 'array'` в тестовом
  `defineEnvironment()`; feature-флаги маршрутов — в `defineEnvironment()` (до boot), не `beforeEach`;
  `Auth::logout()` перед in-process `$this->artisan(...)`. PG-прогон для FK/SERIALIZABLE/индексов/CTE —
  `php8.4` + БД `vaulter_test` в контейнере `agelto_postgres` (127.0.0.1:5455, роль `vaulter`,
  пароль `vaulter`).
- **`packages/core/tests/Drive/` НЕ забинжен ни к какому `TestCase`** (`tests/Pest.php`: только
  `DriveTestHelpers`, drive-suite переехал в пакет `vaulter-drive`, комментарий явно фиксирует «не
  тест-кейс») — новые core-level тесты модели/репозитория (не HTTP drive-контроллеры) кладутся в
  `packages/core/tests/Schema/` (biндится к `TestCase`), по образцу `NodeAdjacencyTest.php`, НЕ в
  `Drive/` — иначе Pest молча не находит `config`-контейнер и падает `Target class [config] does
  not exist` на каждом тесте (P4.2-урок).
- В рабочем дереве присутствуют НЕ относящиеся к VLT-REMED правки (`.claude/.maind-feature-log.last`,
  `.gitignore`, `.claude/workflows/vlt-remed-*.js`, `plans/ACTIVE.md`, `plans/AUDIT-VLT/*`,
  `plans/VLT-HSEAM/`) из конкурентной сессии/хука — НЕ трогать, коммитить только scoped-пути item'а.
- Пред-существующий PG-only флейк `NodeCommentSchemaTest` (jsonb `anchor` key-order round-trip) — вне
  scope, ещё не заведён отдельной находкой. Модели `UploadSession`/`UploadPart`/`NodeComment`/
  `NodeDocumentVersion` не подключают `ResolvesVaulterConnection` (P3.6 Pending Work).

**Workarounds/Deferred/Open questions:**
- workarounds: см. Open risks (per-package гейты; pint-точечно; `cache.default=array`; feature-флаги в
  `defineEnvironment`; PG-контейнер `agelto_postgres`; когда файл несёт МНОГО call site — одна точка
  резолва + глобальный find/replace, P3.6-урок; `getConnectionName()` override через `enum_value()`);
  провизорный MSI-порог 50/70 при отсутствии локального coverage-драйвера (P4.1); `window.prompt`-стаб
  для happy-dom ≥16 в `vi.spyOn`-фикстурах (P4.1); red-before для «раздутой/бесконечной рекурсии»
  безопасно верифицируется временным `git stash` фикса + прогон (никогда не оставлять сессию с
  реверченным фиксом) — на SQLite/малом cyclic-фикстуре она ограничена PHP `memory_limit`, не
  бесконечным hang (P4.2-урок); тот же `git stash`+прогон+`stash pop` паттерн подтвердил red-before
  для четырёх F-id P4.3 разом (6/10 новых тестов упали без фикса, 4/10 — закрыты миграцией независимо
  от `PanelTablesBuilder`); PG count-зависимый флейк тестового харнесса ИЗОЛИРУЕТСЯ прогоном
  целевого `--filter` вместо полного `packages/core/tests` на PG, когда нужно доказать конкретный
  F-id зелёным без шума пред-существующей хрупкости (P4.3-урок).
- deferred: `docs/`-миграция и архивация AUDIT-VLT — `/task:plan-close archive AUDIT-VLT`. Ротация
  `signing_secret` (Q3) и абсолютный барьер wall-deny (Q4) — отдельные items по решению владельца.
  `CHANGELOG.md`-наполнение — сессия с доступом. Подключение хуков панели `fillNode/presentNode/pipes`
  к пайплайну (F-P3.2-02: P4.4 только помечает `@unimplemented`, D22) — фича при «go» владельца.
  **Hocuspocus-мажор (F-P4.5-02, D6)** — эскалирован в P4.1, отдельный план по решению владельца.
  Пред-существующий Rector-долг (112+2 файла) — отдельный item/бэклог по решению владельца.
  Реальный измеренный MSI — фактический прогон в CI (xdebug доступен там, не локально).
  Остаточный `npm audit high` в `packages/vue` (vite/esbuild dev-server) — апстрим `vite-plugin-dts`.
  **Пред-существующий count-зависимый PG-флейк (`MassAssignmentHardeningTest`)** — стабилизация
  тестового харнесса на Postgres, отдельная сессия/item по решению владельца (обнаружено, не заведено
  отдельной находкой, см. Open risks P4.3).
- open_questions: Q3 (ротация `signing_secret`), Q4 (абсолютный барьер wall-deny) — оба
  `Decision pending`, ни один P4-item не блокируют. Q1/Q2 — `Resolved → D1/D10`.
