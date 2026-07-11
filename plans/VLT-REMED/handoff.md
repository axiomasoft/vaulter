# HANDOFF — 2026-07-11 — after P4.8

**Next:**

| Параметр | Значение |
|:--|:--|
| Model | opus |
| Thinking | high — P4.9 закладывает канон структуры пакетов (ADR + arch-тесты), архитектурное решение под D10, opus/high по `plan.md` §Routing |
| Context | fresh session — handoff свежий, рекомендован /clear |
| Суть | Исполнить **P4.9**: инвентарь раскладки всех шести PHP-пакетов, ADR `docs/adr/0017-*.md` (канон A, layer-first + доменный под-namespace), arch-тесты (Pest Arch), которые ПРОХОДЯТ на `core` (эталон) и ПАДАЮТ/`todo` на `documents`/`drive` (намеренно — ТЗ объёма P4.10). БЕЗ переноса кода (`git diff --stat packages/*/src` — ПУСТ). |

```
/task:plan-exec VLT-REMED P4.9
```

**Done:**
- **Фаза P0 (W0 — Blocker'ы) ТЕРМИНАЛЬНА (3/3 🟢).**
- **Фаза P1 (W1 — быстрые высоко-эксплуатируемые) ТЕРМИНАЛЬНА (4/4 🟢).**
- **Фаза P2 (W2 — авторизация и изоляция) ТЕРМИНАЛЬНА (3/3, 🟠 Done with deviations).**
- **Фаза P3 (W3 — надстройки и интеграция) ТЕРМИНАЛЬНА (6/6 🟢).**
- **Фаза P4 (W4) В РАБОТЕ (7/10 🟢, 1/10 🔴 Blocked-формально):**
  - **P4.1 (B24 — Supply-chain, CI-матрица, arch- и mutation-гейты) — 🔴 Blocked**
    (коммит `74294e0`), 13 из 14 находок B24 закрыты штатно, заблокирована только
    F-P4.5-02 (hocuspocus-мажор, эскалирован по D6-гарду, `⛔ Skipped by decision`,
    реверчен на `^2.15`/`^2.13`). Полные детали — `phases/P4.md` P4.1 Completion Notes
    + ESCALATION-блок.
  - **P4.2 (B15 — Безопасность и корректность запросов) — 🟢 Done** (коммит `cd31116`).
  - **P4.3 (B16 — Индексы и пагинация) — 🟢 Done** (коммит `408e17f`).
  - **P4.4 (B17 — Молчащие швы: докблок ≠ код) — 🟢 Done** (коммиты `f3b51b7`, `2764d13`).
  - **P4.5 (B18 — Расширяемость швов именования и экспорта) — 🟢 Done** (коммит `4bbaa7f`).
  - **P4.6 (B20 — Гигиена публичного API) — 🟢 Done** (коммит `e4277a3`).
  - **P4.7 (B21 — Release-readiness и UPGRADE.md) — 🟢 Done** (коммит `e00f82c`).
  - **P4.8 (B19 — Docs-drift) — 🟢 Done** (коммит `b5d7751`):
    - **F-P2.4-01**: `docs/guide/configuration.md:189` — вычищен мёртвый ключ
      `permission_resolver`; секция «Null driver» дополнена реальным путём
      (`drivers.permission => 'database'`, с предупреждением про fail-closed на
      пустом `v_node_grants`).
    - **F-P2.1-02**: `docs/reference/facade.md` — убраны `resolvePermissionsWith()`,
      `permissionResolver()`, `driver()` (ни одного метода нет на `VaulterManager`,
      сверено грепом `public function`). `docs/reference/contracts.md` — убраны
      `Access\PermissionResolver` и `Collaboration\CollaborationDriver` (legacy),
      оба подтверждённо отсутствуют в коде.
    - **F-P2.1-03**: `docs/reference/contracts.md` — добавлены 7 контрактов
      (`Access\GrantAuthoritativeDriver`, `Cas\ListableContentStore`,
      `Cas\ListableStorageDriver`, `Documents\DocumentContent`,
      `Documents\HandlerRegistry`, `Documents\NodeHandlerResolver`,
      `permissions-azgard`'s `AzgardBackedUser`).
    - **F-P2.5-01**: `docs/reference/commands.md` — добавлена `vaulter:prune-webhooks`
      (счётчик команд 9→10, добавлена в «Scheduling», weekly).
    - **F-P2.5-02**: `docs/reference/rest-api.md` — секция «Comments» (4 маршрута
      `NodeCommentController`) в Drive-раздел + `comments`/`grants` в таблицу
      Feature gates. `GET shared` уже был задокументирован ранее (вероятно
      P4.3/пагинация-фикс) — этот пункт находки был неактуален на момент P4.8.
    - **F-P2.5-03**: `docs/guide/errors.md` — убран якорь удалённого
      `DocumentNotFoundException`, добавлены **15** секций (не 9, как оценивал
      P2.5-аудит — код разошёлся сильнее за P0–P4.7: 21 уникальный
      `self::docs()`-якорь в коде на дату исполнения против 8 в старой доке;
      ground truth взят по актуальному грепу, не по числу из finding).
      `unknown-collaboration-driver` оставлен и переформулирован под актуальный
      код (`NodeCollaborationDriverManager`, не `Vaulter::driver()`).
    - **F-P2.5-05**: `docs/reference/configuration.md` — `gc.orphan_grace_hours`.
    - **F-P1.8-04**: `docs/reference/rest-api.md` — секция «## CORS».
    - **F-P2.5-08**: `CONTRIBUTING.md:29` — «level 8» → «level max».
    - **Новый тест**: `packages/core/tests/Support/DocsDriftGuardTest.php` — 10
      green doc-guard-инвариантов с F-id; red-before подтверждён `git stash`
      изменённых недоковых файлов (docs/**, CONTRIBUTING.md) + `git stash pop`.
    - Root: `composer test` 471/471 (было 461, +10), `test:types` 100%, `analyse`
      0 errors, `lint:check` красный ТОЛЬКО на пред-существующем
      `plans/AUDIT-VLT/artifacts/repro/**` (вне scope), новый файл pint-чист.
    - **Known Deviations**: исполнено sonnet/medium вместо sonnet/low из
      `plan.md` §Routing (модель/effort сессии запинены оркестратором на уровне
      запуска, не переключались вручную).
    - Полные Completion Notes — `phases/P4.md` P4.8.

**Remaining:** (порядок исполнения P4)
1. ~~**P4.1**~~–~~**P4.8**~~ — исполнены (P4.1 🔴 Blocked-формально, P4.2–P4.8 🟢 Done).
2. **P4.9** (opus/high, D10) — канон A: инвентарь + ADR-0017 + arch-тесты (БЕЗ переноса). **← следующий**.
3. **P4.10** (opus/high, D10) — namespace-refactor к канону (gate: P4.9 закрыт; `namespace-refactor-safety`).

Затем **P5** (3 items, СКЕЛЕТ — требует `/task:plan-design VLT-REMED P5`, после P4). Скелет-фаза не
исполняется напрямую (`plan.md` §Execution Rules).

**Открытый пункт (не блокирует P4.9, требует внимания владельца/будущей сессии):**
**F-P4.5-02** (hocuspocus `2.x→4.x`) остаётся на `⛔ Skipped by decision` — см. ESCALATION-блок
`phases/P4.md` P4.1 и Q-заводку ниже. `/task:plan-design VLT-REMED` (новый отдельный план на
hocuspocus-мажор) — по решению владельца, вне текущего remaining-списка P4.

**Sources of truth:** `plans/VLT-REMED/plan.md` (Context/Execution Rules/Routing/Status Board — P3
терминальна 6/6, P4 в работе 7/10🟢+1/10🔴 / Decision Log D1–D23 / Update Log с записью P4.8) ·
`plans/VLT-REMED/phases/P4.md` (P4.1 закрыт с ESCALATION-блоком; P4.2–P4.8 закрыты 🟢/🟠;
P4.9–P4.10 детализированы, готовы к исполнению) ·
`plans/VLT-REMED/phases/{P0,P1,P2,P3}.md` (закрыты; P2 🟠) ·
`plans/VLT-REMED/open-questions.md` (Q3/Q4 pending — не блокируют P4) ·
`plans/AUDIT-VLT/findings/REGISTER.md` (SSOT 103 находок, append-only, VLT-REMED в него НЕ пишет) ·
`docs/adr/` (существующие ADR — стиль, следующий номер для P4.9 — проверить `ls docs/adr/`) ·
`packages/core/tests/Arch/ArchTest.php` (стиль Pest Arch пакета, эталон канона A —
`packages/core/src/**`) · `packages/documents/src/**`, `packages/drive/src/Uploads/**`
(дивергентные раскладки — цель P4.9's инвентаря).

**Open risks:**
- Root `composer.json` phpstan/phpunit покрывают ТОЛЬКО `packages/core`. **P4.9** трогает ADR (doc)
  + arch-тесты в КАЖДОМ из шести пакетов — прогнать `composer analyse/test/test:types` по каждому
  пакету, не только root. **P4.10** трогает ВСЕ пакеты — прогон по каждому тоже.
- Root `composer lint:check` красный на пред-существующем `plans/AUDIT-VLT/artifacts/repro/**`
  (чужой read-only план, вне scope) — closure НЕ блокирует; свой новый код обязан быть pint-чистым
  (`vendor/bin/pint --test <файлы>`).
- **`CHANGELOG.md` заблокирован для Read/Write** (`deny: Read(**/CHANGELOG.md)`) — долг записей за
  P1.2/P1.4/P2.1/P2.2/P2.3/P3.1-P3.6/P4.1/P4.2/P4.4/P4.5/P4.7 и далее. `UPGRADE.md` (не
  `UPGRADING.md`, D23) — канонический сток breaking-changes (9 записей; P4.8 без новой записи —
  behaviour-preserving, чистый docs-fix).
- **P4.9 — item-гейт для P4.10 (анти-самообман, паттерн 2):** ЗАПРЕЩЕНО закрывать P4.9, если
  arch-тесты не кодируют канон реально (правило проходит на `core`, падает/`todo` на
  `documents`/`drive` — грепаемо в теле теста). Канон НЕ изобретать — зафиксирован D10 (схема
  `core`), ADR его формализует, не переопределяет; отклонение от D10 = эскалация на plan-design.
  Если канон физически неприменим к какому-то пакету при инвентаризации — зафиксировать исключение
  в ADR с обоснованием и эскалировать (не «додумать»).
- **P4.5-урок для будущих items, задевающих arch-гейты P4.1 (H.5 и другие):** ArchTest'ы,
  добавленные P4.1 как «анти-самообман»-гейт волны, — ТВЁРДАЯ рамка, не рекомендация; item, чьё
  ТЗ предполагает нарушение, обязан сверить Scope Included на альтернативный OR-вариант ДО
  импровизации/эскалации.
- **P4.6-урок:** когда ТЗ item'а называет «предпочтительно X, fallback Y при большом числе call site»
  — исполнитель ОБЯЗАН фактически посчитать call site грепом ДО выбора fallback (не доверять
  прогнозу предыдущего handoff — это прогноз планирования, не факт кода).
- **P4.7-урок:** «сток UPGRADE.md — свести ВСЕ breaking плана» проверяется грепом `F-P` в
  `UPGRADE.md` против Completion Notes закрытых items, НЕ по памяти; будущий item, добавляющий
  собственный breaking, обязан добавить СВОЮ секцию в UPGRADE.md тоже.
- **P4.8-урок:** доковая находка с числом («9 недостающих якорей» и т.п.) — числа могут устареть к
  моменту исполнения (код продолжает жить между аудитом и ремедиацией); ground truth ВСЕГДА берётся
  свежим грепом на момент исполнения, число из finding — ориентир, не потолок/пол. Фактически
  оказалось 15 вместо 9 (код разошёлся сильнее за P0–P4.7), и один пункт (`GET shared`) уже был
  закрыт другим item'ом (P4.3) до того, как P4.8 до него дошёл — сверять «уже закрыто?» тоже грепом,
  не пропускать слепо по списку находки.
- **Локальная песочница exec-сессий не несёт coverage-драйвера (xdebug/pcov)** — ни `php` (8.5), ни
  `php8.4` не имеют модуля; `sudo`/`pecl` заблокированы harness'ом. Реальный измеренный MSI для
  P4.1's `infection.json5` НЕДОСТИЖИМ локально — используется провизорный порог 50/70.
- **Пред-существующий count-зависимый PG-флейк тестового харнесса (обнаружен в P4.3, вне scope
  P4.3–P4.8 чинить).** Полный прогон `composer test` НА POSTGRES даёт спорадический 1 failure —
  `MassAssignmentHardeningTest`, последний кейс. Триггер — НЕ логика P4.x. `composer test` (SQLite,
  дефолтный гейт Validation) НЕ подвержен.
- **P4.4 добавила новую `tests/Pest.php`-биндинг-директорию** (`$core.'/PanelBoot'` →
  `BrokenPanelBootTestCase`) — будущие тесты со сломанным boot кладутся туда же.
- **P4.10 — межпакетный namespace-refactor (closed-enum escalation-триггер, opus/high, D10).** Самый
  вероятный провал — забытый morph-алиас: полиморфные строки в БД держат старый FQCN. Обязателен
  morph-инвариант-тест на PG.
- **F-P4.5-02 (hocuspocus-мажор) эскалирован в P4.1** — стек РЕВЕРЧЕН обратно на `^2.15`/`^2.13`.
- **Пред-существующий Rector-долг** (112 файлов core/drive/media/documents + 2 filament) — вне
  Scope Included P4.1, Rector CI-шаг остаётся advisory (`|| true`).
- **Остаточный `npm audit --audit-level=high` в `packages/vue`** (1 high, `vite<=6.4.2`↔esbuild
  dev-server-only, GHSA-67mh-4wv8-2f99) — critical happy-dom RCE ЗАКРЫТ.
- Testbench-снаги (унаследованы из P1.3/P3.1): `cache.default => 'array'` в `defineEnvironment()`;
  фиче-флаги маршрутов — в `defineEnvironment()` (до boot); `Auth::logout()` перед in-process
  `$this->artisan(...)`. PG-прогон — `php8.4` + БД `vaulter_test` в контейнере `agelto_postgres`
  (127.0.0.1:5455, роль/пароль `vaulter`).
- **`packages/core/tests/Drive/` НЕ забинжен ни к какому `TestCase`** — новые core-level тесты модели/
  репозитория кладутся в `packages/core/tests/Schema/`, НЕ в `Drive/`.
- В рабочем дереве присутствуют НЕ относящиеся к VLT-REMED правки (`.claude/.maind-feature-log.last`,
  `.gitignore`, `.claude/workflows/vlt-remed-*.js`, `plans/ACTIVE.md`, `plans/AUDIT-VLT/*`,
  `plans/VLT-HSEAM/`) из конкурентной сессии/хука — НЕ трогать, коммитить только scoped-пути item'а.
  Подтверждено ещё раз в P4.8: `git status` до и после коммита показывает эти пути нетронутыми.
- Пред-существующий PG-only флейк `NodeCommentSchemaTest` (jsonb `anchor` key-order round-trip) — вне
  scope. Модели `UploadSession`/`UploadPart`/`NodeComment`/`NodeDocumentVersion` не подключают
  `ResolvesVaulterConnection` (P3.6 Pending Work).

**Workarounds/Deferred/Open questions:**
- workarounds: см. Open risks (per-package гейты; pint-точечно; `cache.default=array`; feature-флаги в
  `defineEnvironment`; PG-контейнер `agelto_postgres`; провизорный MSI-порог 50/70; PG count-зависимый
  флейк тестового харнесса ИЗОЛИРУЕТСЯ прогоном целевого `--filter`; boot-time fail-loud проверяется
  через `Container::resolved()`/выделенный `TestCase`; **P4.6-урок:** считать call site ГРЕПОМ по
  факту кода перед выбором fallback-варианта; **P4.7-урок:** UPGRADE.md-сток сверяется грепом
  `F-P` против Completion Notes, не по памяти, и требует записи от ТЕКУЩЕГО item'а тоже, если он
  сам breaking; **P4.8-урок:** доковая находка с фиксированным числом — перепроверять свежим грепом
  на момент исполнения, число из finding могло устареть; red-before для doc/config/manifest-alignment
  guard-тестов верифицируется `git stash` изменённых не-тестовых файлов (не самого теста) + прогон на
  голом коде.
- deferred: `docs/`-миграция и архивация AUDIT-VLT — `/task:plan-close archive AUDIT-VLT`. Ротация
  `signing_secret` (Q3) и абсолютный барьер wall-deny (Q4) — отдельные items по решению владельца.
  `CHANGELOG.md`-наполнение (содержимое) — сессия с доступом. Подключение хуков панели
  `fillNode/presentNode/pipes` к пайплайну (F-P3.2-02) — фича при «go» владельца.
  **Hocuspocus-мажор (F-P4.5-02, D6)** — эскалирован в P4.1, отдельный план по решению владельца.
  Пред-существующий Rector-долг — отдельный item/бэклог. Реальный измеренный MSI — фактический
  прогон в CI. Остаточный `npm audit high` в `packages/vue` — апстрим `vite-plugin-dts`.
  Пред-существующий count-зависимый PG-флейк (`MassAssignmentHardeningTest`) — отдельная
  сессия/item. CAS-точки в drive/media НЕ обёрнуты в `MissingPackageException` — отдельный
  item/бэклог.
- open_questions: Q3 (ротация `signing_secret`), Q4 (абсолютный барьер wall-deny) — оба
  `Decision pending`, ни один P4-item не блокируют. Q1/Q2 — `Resolved → D1/D10`.
