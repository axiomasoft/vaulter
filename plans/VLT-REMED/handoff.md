# HANDOFF — 2026-07-11 — after P4.4

**Next:**

| Параметр | Значение |
|:--|:--|
| Model | sonnet |
| Thinking | medium (дефолт §Routing — локальный код по готовому ТЗ, корень известен) |
| Context | fresh session — handoff свежий, рекомендован /clear |
| Суть | Исполнить **P4.5 (B18)** строго по ТЗ `phases/P4.md`: расширяемость трёх хардкод-точек без форка пакета — вынести лимит/паттерн `DriveNameRules` в `config('vaulter.drive.name_rules')` (дефолты неизменны), сообщение валидатора имени — через lang-ключ (`vaulter::validation.name_no_separators`), `NodeExportService::filenameFor` — `protected`-точка расширения. Item трогает `packages/documents` (`NodeExportService`) — свой гейт валидации обязателен. |

```
/task:plan-exec VLT-REMED P4.5
```

**Done:**
- **Фаза P0 (W0 — Blocker'ы) ТЕРМИНАЛЬНА (3/3 🟢).**
- **Фаза P1 (W1 — быстрые высоко-эксплуатируемые) ТЕРМИНАЛЬНА (4/4 🟢).**
- **Фаза P2 (W2 — авторизация и изоляция) ТЕРМИНАЛЬНА (3/3, 🟠 Done with deviations).**
- **Фаза P3 (W3 — надстройки и интеграция) ТЕРМИНАЛЬНА (6/6 🟢).**
- **Фаза P4 (W4) В РАБОТЕ (3/10 🟢, 1/10 🔴 Blocked-формально):**
  - **P4.1 (B24 — Supply-chain, CI-матрица, arch- и mutation-гейты) — 🔴 Blocked**
    (коммит `74294e0`), 13 из 14 находок B24 закрыты штатно, заблокирована только
    F-P4.5-02 (hocuspocus-мажор, эскалирован по D6-гарду, `⛔ Skipped by decision`,
    реверчен на `^2.15`/`^2.13`). Полные детали — `phases/P4.md` P4.1 Completion Notes
    + ESCALATION-блок.
  - **P4.2 (B15 — Безопасность и корректность запросов) — 🟢 Done** (коммит `cd31116`):
    LIKE-wildcard-экранирование в `NodeReadRepository::search()` (F-P1.8-03) +
    `Node::enableCycleDetection(): true` (F-P4.2-02). Полные Completion Notes —
    `phases/P4.md` P4.2.
  - **P4.3 (B16 — Индексы и пагинация) — 🟢 Done** (коммит `408e17f`): партиционный
    индекс `(trashed_at)` + снятие трёх дубль-индексов, `withMaxDepth` на обходе
    предков, тай-брейкер `id` в трёх курсор-фидах (F-P4.1-01/02, F-P4.2-01/05).
    Полные Completion Notes — `phases/P4.md` P4.3.
  - **P4.4 (B17 — Молчащие швы: докблок ≠ код) — 🟢 Done** (коммиты `f3b51b7`,
    `2764d13`): семь находок закрыты по направлениям D22 —
    - **код:** `PanelRegistry::class` теперь эагерно резолвится в
      `VaulterServiceProvider::packageBooted()` (F-P3.2-01, битая панель валится на
      boot, не на первом запросе); `VaulterManager::registerExporter()` принимает
      `DocumentExporter|class-string<DocumentExporter>`, симметрично
      `registerHandler()` (F-P3.3-01); новый `Vaulter\Exceptions\Support\
      MissingPackageException` оборачивает `BindingResolutionException` в
      `driveFactory()`/`handlers()` (`registerHandler()` делегирует в `handlers()`)
      с текстом `Fix: composer require axioma-studio/vaulter-<pkg>`
      (F-P3.3-02/F-P3.5-01 — **сужено** до трёх методов `VaulterManager`, CAS-точки
      в `drive`/`media` НЕ тронуты, см. Pending Work ниже); мёртвый `StorageAdapter`/
      `DefaultStorageAdapter`/`storage_adapter`-ключ удалён (`git rm` + правка
      `Config.php`/`config/vaulter.php`), реальная CAS-persistence всегда шла через
      `StorageDriver`/`ContentStore` (F-P3.1-03, пред-грепом подтверждено — живых
      ссылок не было).
    - **докблок:** `StoragePanelProvider::fillNode/presentNode/pipes` помечены
      `@unimplemented`, снято ложное «enrich without forking» (F-P3.2-02);
      `GatePermissionResolver::gateAllows` больше не обещает несуществующий swallow
      (F-P1.2-02).
    - 11 новых тестов (446/446 root SQLite, было 435). Полные Completion Notes,
      Pending Work и Known Deviations (routing-adherence — без отклонений; сужение
      «CAS-точек»; докблок `HandlerRegistry` не правился, т.к. стал фактически
      верным) — `phases/P4.md` P4.4 Completion Notes.

**Remaining:** (порядок исполнения P4)
1. ~~**P4.1**~~ — исполнен, 🔴 Blocked ТОЛЬКО формально (plan-lint ESCALATION-инвариант).
2. ~~**P4.2**~~ — исполнен, 🟢 Done.
3. ~~**P4.3**~~ — исполнен, 🟢 Done.
4. ~~**P4.4**~~ — исполнен, 🟢 Done.
5. **P4.5** (B18) — расширяемость `DriveNameRules`/lang-ключ/`filenameFor`. **← следующий**.
6. **P4.6** (B20) — докблок фасада (12 методов), FQCN в доке, статкеш за инстанс.
7. **P4.7** (B21) — split-changelog-конфиг, deprecation-policy, `@vaulter/vue` peerDeps, сток `UPGRADE.md`.
8. **P4.8** (B19, sonnet/low) — docs-drift (9 находок, doc-guard-инварианты).
9. **P4.9** (opus/high, D10) — канон A: инвентарь + ADR-0017 + arch-тесты (БЕЗ переноса).
10. **P4.10** (opus/high, D10) — namespace-refactor к канону (gate: P4.9 закрыт; `namespace-refactor-safety`).

Затем **P5** (3 items, СКЕЛЕТ — требует `/task:plan-design VLT-REMED P5`, после P4). Скелет-фаза не
исполняется напрямую (`plan.md` §Execution Rules).

**Открытый пункт (не блокирует P4.5, требует внимания владельца/будущей сессии):**
**F-P4.5-02** (hocuspocus `2.x→4.x`) остаётся на `⛔ Skipped by decision` — см. ESCALATION-блок
`phases/P4.md` P4.1 и Q-заводку ниже. `/task:plan-design VLT-REMED` (новый отдельный план на
hocuspocus-мажор) — по решению владельца, вне текущего remaining-списка P4.

**Sources of truth:** `plans/VLT-REMED/plan.md` (Context/Execution Rules/Routing/Status Board — P3
терминальна 6/6, P4 в работе 3/10🟢+1/10🔴 / Decision Log D1–D23 / Update Log с записью P4.4) ·
`plans/VLT-REMED/phases/P4.md` (P4.1 закрыт с ESCALATION-блоком; P4.2/P4.3/P4.4 закрыты 🟢; P4.5–P4.10
детализированы, готовы к исполнению) · `plans/VLT-REMED/phases/{P0,P1,P2,P3}.md` (закрыты; P2 🟠) ·
`plans/VLT-REMED/open-questions.md` (Q3/Q4 pending — не блокируют P4) ·
`plans/AUDIT-VLT/findings/REGISTER.md` (SSOT 103 находок, append-only, VLT-REMED в него НЕ пишет) ·
`plans/AUDIT-VLT/findings/{P3.1-naming-seam,P3.5-driver-seams}.md` (F-P3.1-01/02/04, F-P3.1-04 —
Required Reads P4.5) · `UPGRADE.md` (7 записей: 4 P4.1 + 1 P4.2 + 2 P4.4 — StorageAdapter-удаление,
`MissingPackageException` вместо голого `BindingResolutionException`).

**Open risks:**
- Root `composer.json` phpstan/phpunit покрывают ТОЛЬКО `packages/core`. P4.5 трогает
  `packages/documents` (`NodeExportService::filenameFor`) — обязателен свой
  `cd packages/documents && composer analyse && composer test && composer test:types`.
  P4.9/P4.10 трогают ВСЕ пакеты — прогон по каждому.
- Root `composer lint:check` красный на пред-существующем `plans/AUDIT-VLT/artifacts/repro/**` (чужой
  read-only план, вне scope) — closure НЕ блокирует; свой новый код обязан быть pint-чистым
  (`vendor/bin/pint --test <файлы>`).
- **`CHANGELOG.md` заблокирован для Read/Write** (`deny: Read(**/CHANGELOG.md)`) — долг записей за
  P1.2/P1.4/P2.1/P2.2/P2.3/P3.1-P3.6/P4.1/P4.2/P4.4 и далее. **F-P2.1-06 (P4.7) чинится КОНФИГОМ
  split-пайплайна** (D23), не наполнением файла; наполнение — Pending Work, сессия с доступом.
  `UPGRADE.md` (не `UPGRADING.md`, D23) — канонический сток breaking-changes (7 записей: 4 из P4.1
  + 1 из P4.2 + 2 из P4.4; P4.3 новую запись не добавила — behaviour-preserving перф/корректность-фикс).
- **P4.4 Pending Work, не переносится автоматически в P4.5:** CAS-точки (`StorageDriver`/
  `ContentStore`/`BlobStore`) в конструкторной DI контроллеров/actions `drive`/`media` НЕ обёрнуты в
  `MissingPackageException` — сужение Scope Included (F-P3.3-02+F-P3.5-01) до трёх методов
  `VaulterManager`, названных в «Где» находки F-P3.5-01. Отдельный item/бэклог по решению владельца,
  если желаемо расширить install-hint на CAS-конструкторную DI.
- **Локальная песочница exec-сессий не несёт coverage-драйвера (xdebug/pcov)** — ни `php` (8.5), ни
  `php8.4` не имеют модуля; `sudo`/`pecl` заблокированы harness'ом. Реальный измеренный MSI для
  P4.1's `infection.json5` (root + 4 per-package) НЕДОСТИЖИМ локально — используется провизорный
  порог 50/70 (advisory job, не блокирует CI). Фактический MSI можно узнать только из реального
  прогона `mutation.yml`/сателлитных workflow в CI (там PHP 8.4 + xdebug).
- **Пред-существующий count-зависимый PG-флейк тестового харнесса (обнаружен в P4.3, вне scope
  P4.3/P4.4 чинить).** Полный прогон `composer test` НА POSTGRES (`agelto_postgres`, `DB_CONNECTION=pgsql`)
  даёт спорадический 1 failure — `MassAssignmentHardeningTest`, последний кейс
  (`WebhookEndpoint.signing_secret`), `SQLSTATE[25P02]` «current transaction is aborted» на
  СЛЕДУЮЩЕЙ проверке. Триггер — НЕ логика P4.3/P4.4 (доказано инертным пустым тестом без единой строчки
  диффа); порядко-/count-зависимая хрупкость PG-транзакции внутри самого `MassAssignmentHardeningTest`.
  Новая находка НЕ заводится; будущая сессия, занимающаяся стабилизацией PG CI, должна начать здесь.
  `composer test` (SQLite, дефолтный гейт Validation) НЕ подвержен — 446/446 зелёных на P4.4.
- **P4.4 добавила новую `tests/Pest.php`-биндинг-директорию** (`$core.'/PanelBoot'` →
  `BrokenPanelBootTestCase`, отдельно от `Panels/` — Pest запрещает файлу директории переопределять
  test case другой директории через per-файловый `uses()`) — будущие тесты, которым нужен ПОЛОМАННЫЙ
  boot (config задан ДО провайдер-boot, а не в обычном тесте), кладутся туда же, не в `Panels/`.
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
  безопасно верифицируется временным `git stash` фикса + прогон (P4.2-урок); PG count-зависимый флейк
  тестового харнесса ИЗОЛИРУЕТСЯ прогоном целевого `--filter` (P4.3-урок); **P4.4-урок:** boot-time
  fail-loud (провайдер бросает исключение ВНУТРИ `packageBooted()`) проверяется НЕ явным вызовом в теле
  теста, а (а) `Container::resolved(Contract::class)` — «уже резолвнут» без теста, который сам его
  резолвит, и/или (б) отдельным `TestCase`, конфигурирующим ЛОМАЮЩИЙ вход через `defineEnvironment()`
  (выполняется ДО `Illuminate\Foundation\Bootstrap\BootProviders` внутри Testbench
  `createApplication()`) и ловящим исключение из `parent::setUp()` в try/catch — такой `TestCase`
  живёт в СОБСТВЕННОЙ директории `tests/Pest.php`-биндинга (Pest запрещает файлу директории
  переопределять test case другой уже забинженной директории).
- deferred: `docs/`-миграция и архивация AUDIT-VLT — `/task:plan-close archive AUDIT-VLT`. Ротация
  `signing_secret` (Q3) и абсолютный барьер wall-deny (Q4) — отдельные items по решению владельца.
  `CHANGELOG.md`-наполнение — сессия с доступом. Подключение хуков панели `fillNode/presentNode/pipes`
  к пайплайну (F-P3.2-02: P4.4 только помечает `@unimplemented`, D22) — фича при «go» владельца.
  **Hocuspocus-мажор (F-P4.5-02, D6)** — эскалирован в P4.1, отдельный план по решению владельца.
  Пред-существующий Rector-долг (112+2 файла) — отдельный item/бэклог по решению владельца.
  Реальный измеренный MSI — фактический прогон в CI (xdebug доступен там, не локально).
  Остаточный `npm audit high` в `packages/vue` (vite/esbuild dev-server) — апстрим `vite-plugin-dts`.
  **Пред-существующий count-зависимый PG-флейк (`MassAssignmentHardeningTest`)** — стабилизация
  тестового харнесса на Postgres, отдельная сессия/item по решению владельца.
  **CAS-точки в drive/media НЕ обёрнуты в `MissingPackageException`** (P4.4 Pending Work) — отдельный
  item/бэклог по решению владельца, если желаемо расширить install-hint дальше трёх методов
  `VaulterManager`.
- open_questions: Q3 (ротация `signing_secret`), Q4 (абсолютный барьер wall-deny) — оба
  `Decision pending`, ни один P4-item не блокируют. Q1/Q2 — `Resolved → D1/D10`.
