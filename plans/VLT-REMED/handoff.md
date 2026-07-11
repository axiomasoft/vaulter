# HANDOFF — 2026-07-11 — after P3.6

**Next:** /task:plan-design VLT-REMED P4

| Параметр | Значение |
|:--|:--|
| Model | opus |
| Thinking | high — разворачивание скелет-фазы (P4, 10 items, W4 «Качество, DX, CI + канон структуры»): DoR-гейт, RAG design-time для P4.1 (CVE-статусы firebase/php-jwt, happy-dom, @tiptap/* мажор), архитектурное решение места структурного рефактора (D10) |
| Context | fresh session — handoff свежий, рекомендован /clear |
| Суть | Развернуть фазу **P4 (W4)** из скелета: 10 items (батчи B24, B15-B18, B20, B21, B19 + 2 структурных P4.9/P4.10), DoR закрыт, скелет-маркер снят. P4 не исполняется напрямую (скелет-гейт §Execution Rules). |

```
/task:plan-design VLT-REMED P4
```

**Done:**
- **Фаза P0 (W0 — Blocker'ы) ТЕРМИНАЛЬНА (3/3 🟢).**
- **Фаза P1 (W1 — быстрые высоко-эксплуатируемые) ТЕРМИНАЛЬНА (4/4 🟢).**
- **Фаза P2 (W2 — авторизация и изоляция) ТЕРМИНАЛЬНА (3/3, 🟠 Done with deviations).**
- **Фаза P3 (W3 — надстройки и интеграция) ТЕРМИНАЛЬНА (6/6 🟢):**
  - **P3.1 (B11) закрыт 🟢** — коммит `756ba2a`. Собственный флаг `features.collaboration`
    (дефолт `false`) гейтит все collab-пути; `ActivityResource` гейтится на `activity`;
    дефолт-транспорт выровнен документацией+ADR-0001 Amendment (D21). Закрыты F-P1.6-01,
    F-P3.6-01, F-P2.5-06, F-P1.10-03. Гейт волны B11→B4 снят.
  - **P3.2 (B4) закрыт 🟢** — коммит `58d8225`. Двусторонняя HMAC-подпись входящего
    collab-webhook (тот же `WebhookSigner`, что подписывает исходящие) поверх identity
    (secret/JWT не ослаблены), timestamp-окно ±300с, anti-replay через `Cache::add`;
    серверный `exp`-таймер в `hocuspocus-server`. Закрыты F-P1.6-02, F-P1.6-03. **Known
    Deviation:** `webhook_secret` теперь ОБЯЗАТЕЛЕН для ЛЮБОГО collab-вызова (санкционировано
    ТЗ, задокументировано в `UPGRADE.md`).
  - **P3.3 (B6) закрыт 🟢** — коммит `bebd81e`. `UploadSession::scopeSweepable` подбирает
    застрявшие `Assembled`-сессии наряду с `Initiated`/`Uploading`; `authorizeSession` (оба
    контроллера) сверяет `created_by` сессии с актором ПОВЕРХ ability-проверки (IDOR-guard);
    `UploadSessionManager` эмитит три новых lifecycle-события, `RecordsDriveActivity` пишет
    activity через существующий `ActivityRecorder`. Закрыты F-P1.4-10, F-P1.4-12, F-P1.4-13.
    10 новых/обновлённых тестов с F-id. `UPGRADE.md` — новая секция breaking.
  - **P3.4 (B7) закрыт 🟢** — коммит `eacf3c6`. `OffsetMismatchException` мапит
    offset-mismatch в tus 409 + `Upload-Offset`; идемпотентный повтор совпадающего диапазона
    → 204; неверный Content-Type → 415; отсутствующий/чужой `Tus-Resumable` → 412; новый
    `OPTIONS resumable-uploads/{upload_id}` (без авторизации) анонсирует discovery-заголовки;
    metadata фильтруется closed allow-list'ом ключей + лимитом длины. Закрыты F-P1.4-02,
    F-P1.4-05, F-P1.4-06, F-P1.4-07, F-P1.4-08. 9 новых HTTP-тестов + обновления существующих.
    Red-before подтверждён вручную (git stash + прогон на до-фикс коде). `UPGRADE.md` — новая
    секция breaking.
  - **P3.5 (B22) закрыт 🟢** — коммиты `843a3b4` (код), `b5fbbfe` (доки плана).
    F-P2.2-01: `VaulterDocumentsServiceProvider::packageRegistered()` — существующий
    `require`+`array_merge`+`config([...])` обёрнут в `if (! $this->app->
    configurationIsCached())`. F-P2.2-02 (doc-alignment, D19): в `docs/guide/installation.md`
    добавлен блок `::: warning Publishing migrations switches the canonical mode`. Новые
    тесты: `DocumentsConfigMergeTest.php` (3), `InstallationDocsGuardTest.php` (1).
  - **P3.6 (B23) закрыт 🟢** — коммит `b7a08ab`. F-P2.3-02: новый `config('vaulter.connection')`
    (nullable, env `VAULTER_DB_CONNECTION`) прокинут через `PanelTablesBuilder` (два новых
    приватных метода `schemaConnection()`/`dbConnection()`, ВСЕ `Schema::`/`DB::` вызовы файла
    переведены на них — createTable/dropTable + 5 driver-aware `afterCreate`-индекс-хуков +
    `ipColumn`/`binaryColumn`) и через новый трейт `Vaulter\Models\Concerns\
    ResolvesVaulterConnection` (рядом с `HasVaulterKeys`) во все 12 core-моделей (`Node`,
    `Storage`, `NodeGrant`, `Activity`, `File`, `FileVersion`, `FileVersionBlob`, `NodeShare`,
    `Outbox`, `WebhookEndpoint`, `WebhookDelivery`, `Blob`). Дефолт `null` резолвится ровно как
    раньше (обратная совместимость). F-P2.3-04 (doc-only, D19): раздел «Schema change policy»
    + «Dedicated database connection» в `docs/guide/database-compatibility.md`. Новые тесты:
    `packages/core/tests/Schema/PanelConnectionTest.php` (3, red-before подтверждён вручную) и
    `packages/core/tests/Unit/DatabaseCompatibilityDocsGuardTest.php` (2, green doc-guard).
    `UPGRADE.md` — новая секция Unreleased (additive, «no action required»). Root-гейт:
    416 passed (было 411) / 1056 assertions, phpstan 0 ошибок (потребовало нормализации
    `getConnectionName()` через `enum_value()` — Larastan типизирует `$this->connection` как
    `string|UnitEnum|null`), type-coverage 100%. **Pending Work:** `CHANGELOG.md` запись
    (deny-листед); модели `UploadSession`/`UploadPart` (drive) и `NodeComment`/
    `NodeDocumentVersion` (documents) НЕ подключают трейт (вне Scope — root-гейт покрывал
    только `packages/core`), их таблицы уже создаются на нужном соединении через тот же
    `PanelTablesBuilder`, но модельный слой этих 4 сущностей — нет.

**Remaining:** P4 (10 items, СКЕЛЕТ — требует `/task:plan-design VLT-REMED P4`), P5
(3 items, СКЕЛЕТ — требует `/task:plan-design VLT-REMED P5`, после P4). Скелет-фаза не
исполняется напрямую (`plan.md` §Execution Rules).

**Sources of truth:** `plans/VLT-REMED/plan.md` (Context/Execution Rules/Routing/Status
Board — P3 терминальна 6/6/Decision Log D1–D21, Update Log с записью P3.6) ·
`plans/VLT-REMED/phases/P3.md` (P3.1–P3.6 закрыты 🟢 с Completion Notes + Phase Handoff
заполнен) · `plans/VLT-REMED/phases/{P0,P1,P2}.md` (закрыты; P2 🟠) ·
`plans/VLT-REMED/open-questions.md` (Q3/Q4 pending — не блокируют P4) ·
`plans/VLT-REMED/findings/P0-remediation-norms.md` (N3 — коды tus, применена в P3.4;
неизменны) ·
`plans/AUDIT-VLT/findings/REGISTER.md` (SSOT 103 находок, append-only, VLT-REMED в него
НЕ пишет) ·
`plans/AUDIT-VLT/findings/{P2.3-schema-embeddability}.md` (детальная находка батча P3.6,
F-P2.3-02/04) ·
`packages/core/src/Models/Concerns/ResolvesVaulterConnection.php` (P3.6 эталон: как
override'ить `getConnectionName()` совместимо с per-model enum-оверрайдом — сначала
`enum_value($this->connection)`, потом package-wide config-фоллбэк) ·
`packages/core/src/Schema/PanelTablesBuilder.php::schemaConnection()/dbConnection()`
(P3.6 эталон: единая точка резолва Schema/DB-соединения для файла, несущего 26+
call site — не N точечных правок) ·
`packages/documents/tests/Collaboration/CollaborationEnabledTestCase.php` (эталон:
как правильно включать оба collab-флага в тестах — В `defineEnvironment()`, ДО boot,
не в `beforeEach()`) ·
`packages/core/src/Webhooks/WebhookSigner.php` (canonical string, переиспользован
P3.2 для входящего направления — эталон для будущих HMAC-швов пакета) ·
`packages/drive/src/Uploads/Models/UploadSession.php` (`scopeSweepable` — P3.3 эталон
«TTL-порог = expires_at, стамплен один раз, не бампается» для любого будущего
lifecycle-sweep) ·
`packages/drive/src/Exceptions/{UploadConflictException,OffsetMismatchException}.php`
(P3.4 эталон: self-rendering `render(Request): JsonResponse`) ·
`packages/documents/src/VaulterDocumentsServiceProvider.php::packageRegistered()`
(P3.5 эталон: `configurationIsCached()`-гвард вокруг ручного merge, когда
`mergeConfigFrom` не подходит из-за нескольких top-level ключей в одном config-файле).

**Open risks:**
- Root `composer.json` phpstan/phpunit покрывают ТОЛЬКО `packages/core`. Любой P4-item,
  трогающий `documents`/`drive`/`media`/`filament`, обязан дополнительно гонять свой
  собственный `composer analyse && composer test && composer test:types` из директории
  пакета (P3.5/P3.6 не потребовали этого — оба чисто core).
- Root `composer lint:check` красный на пред-существующем
  `plans/AUDIT-VLT/artifacts/repro/**` (чужой read-only план, вне scope) — closure НЕ
  блокирует; свой новый код обязан быть pint-чистым (проверено точечно
  `vendor/bin/pint --test <файлы>` в P3.1–P3.6 — держать эту практику).
- `CHANGELOG.md` заблокирован для Read/Write (`deny: Read(**/CHANGELOG.md)`) — долг
  записей за P1.2, P1.4, P2.1, P2.2, P2.3, P3.1, P3.2, P3.3, P3.4, P3.5, P3.6 и далее.
  `UPGRADE.md` покрывает breaking-change-документирование по D3. Сессия с доступом
  должна догнать CHANGELOG.md.
- **Пред-существующий PG-only флейк `NodeCommentSchemaTest`** (jsonb `anchor` key-order
  round-trip на PostgreSQL) — вне scope, ещё не заведён отдельной находкой. Следующая
  сессия с доступом к `findings/` должна это сделать.
- Модели `UploadSession`/`UploadPart` (`packages/drive`) и `NodeComment`/
  `NodeDocumentVersion` (`packages/documents`) не подключают `ResolvesVaulterConnection`
  (P3.6 Pending Work) — если хост реально включит `vaulter.connection`, эти 4 модели
  останутся на дефолтном разрешении подключения, пока drive/documents own-пакетный item
  не добавит трейт там же. Не найдено новой находки — таблицы этих сущностей уже создаются
  на нужном соединении через общий `PanelTablesBuilder`, расходится только модельный слой.
- В рабочем дереве присутствуют НЕ относящиеся к VLT-REMED правки (`.claude/.maind-
  feature-log.last` удалён, `.gitignore`, `.claude/workflows/vlt-remed-*.js`,
  `plans/ACTIVE.md`, `plans/AUDIT-VLT/{plan.md,phases/*.md}`, `plans/VLT-HSEAM/`) из
  конкурентной сессии/хука — НЕ трогать, коммитить только scoped-пути item'а (P3.6
  подтвердил: staged только файлы item'а + плана, остальное осталось нетронутым).

**Workarounds/Deferred/Open questions:**
- workarounds: drive/documents/filament — их own `composer analyse`/`test`/`test:types`
  (root покрывает только core). Node-часть collab (P3.2) — `cd hocuspocus-server && npm
  test` (node:test). Testbench-дефолт `cache.default=database` без миграции →
  `cache.default => 'array'` в тестовом `defineEnvironment()` (нужно для throttle P1.3 и
  nonce-store P3.2). Feature-флаги, гейтящие МАРШРУТЫ, обязаны ставиться в
  `defineEnvironment()` (до boot), НЕ в `beforeEach()` теста. Console-контекст в
  in-process тестах (`$this->artisan(...)`) НАСЛЕДУЕТ `actingAs()`-пользователя из того
  же теста — `Auth::logout()` перед прогоном консольной команды. PG-прогон для F-id с
  реальным FK/SERIALIZABLE — `php8.4` + БД `vaulter_test` в контейнере `agelto_postgres`
  (127.0.0.1:5455, роль `vaulter`); P3.6 не потребовал PG (connection-параметризация —
  чистая маршрутизация, не FK/SERIALIZABLE-инвариант). Один config-файл, несущий
  НЕСКОЛЬКО top-level `vaulter.*` ключей (P3.5-урок), не годится под один
  `mergeConfigFrom`. **P3.6-урок:** когда файл несёт МНОГО (10+) точечных
  `Schema::`/`DB::` call site, вводи ОДНУ приватную точку резолва соединения
  (`schemaConnection()`/`dbConnection()`) и глобальным find/replace переведи все call
  site на неё — не трогай их логику построчно. **P3.6-урок:** `Model::getConnectionName()`
  override обязан сначала пропустить `$this->connection` через `enum_value()` (Larastan
  типизирует его `string|UnitEnum|null`), иначе phpstan max ловит return-type mismatch на
  КАЖДОЙ модели, подключившей трейт.
- deferred: `docs/`-миграция и архивация AUDIT-VLT — отдельный
  `/task:plan-close archive AUDIT-VLT`. Ротация `signing_secret` (Q3) и абсолютный
  барьер wall-deny (Q4) — отдельные items по решению владельца. `CHANGELOG.md` записи
  за P1.2/P1.4/P2.1/P2.2/P2.3/P3.1/P3.2/P3.3/P3.4/P3.5/P3.6 и далее — сессия с доступом.
  Пред-существующий PG-only jsonb-флейк `NodeCommentSchemaTest` — завести
  находку/чинить отдельно. Полное покрытие `Tus-Resumable` на POST/GET (create/status) —
  вне заявленного scope F-P1.4-06, не заводить без новой находки. `ResolvesVaulterConnection`
  для drive/documents-моделей (UploadSession/UploadPart/NodeComment/NodeDocumentVersion) —
  отдельный item в scope тех пакетов, если понадобится.
- open_questions: Q3 (ротация `signing_secret`), Q4 (абсолютный барьер wall-deny) —
  оба `Decision pending`, ни один P3-item не блокировали. Q1/Q2 — `Resolved → D1/D10`.
