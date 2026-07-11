# HANDOFF — 2026-07-11 — after P3.5

**Next:** /task:plan-exec VLT-REMED P3.6

| Параметр | Значение |
|:--|:--|
| Model | sonnet |
| Thinking | medium — локальный connection-параметризация + doc-политика фикс по готовому ТЗ, автономный batch (`packages/core` схема+модели), Routing: P3 весь дефолтный sonnet/medium, escalation-триггеров §10 в фазе нет |
| Context | fresh session — handoff свежий, рекомендован /clear |
| Суть | Исполнить **P3.6 (B23 — Встраиваемость схемы)**: параметризовать `config('vaulter.connection')` (nullable, дефолт `null`) через `PanelTablesBuilder`/`Panel` и общий трейт/базовый класс моделей + раздел политики изменения схемы в `docs/guide/database-compatibility.md` (F-P2.3-02/04). Только `packages/core` — root-гейт покрывает целиком. Последний item фазы P3 — после него фаза P3 (W3) терминальна. Автономен, готов. |

```
/task:plan-exec VLT-REMED P3.6
```

**Done:**
- **Фаза P0 (W0 — Blocker'ы) ТЕРМИНАЛЬНА (3/3 🟢).**
- **Фаза P1 (W1 — быстрые высоко-эксплуатируемые) ТЕРМИНАЛЬНА (4/4 🟢).**
- **Фаза P2 (W2 — авторизация и изоляция) ТЕРМИНАЛЬНА (3/3, 🟠 Done with deviations).**
- **Фаза P3 (W3 — надстройки и интеграция) В РАБОТЕ (5/6 🟢):**
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
    `require`+`array_merge`+`config([...])` (три top-level ключа `vaulter.models/handlers/
    exporters` в ОДНОМ config-файле — `mergeConfigFrom` для трёх ключей одним вызовом не
    подходит, дал бы неверную вложенность) обёрнут в `if (! $this->app->
    configurationIsCached())` — файловый I/O в `register()` теперь пропускается под
    `config:cache`, как и у `mergeConfigFrom` в drive/media. F-P2.2-02 (doc-alignment, D19):
    в `docs/guide/installation.md` добавлен блок `::: warning Publishing migrations switches
    the canonical mode — don't run both` сразу после info-блока про auto-discovery —
    объясняет timestamp-переименование при публикации + трекинг Laravel по имени файла →
    повторный прогон под новым именем, предписывает вести миграции ТОЛЬКО из опубликованных
    копий после публикации. Архитектурный отказ от `discoversMigrations()+runsMigrations()`
    НЕ выбран (doc-фикс — дефолт ТЗ, риск zero-config install). Новые тесты:
    `packages/documents/tests/Unit/DocumentsConfigMergeTest.php` (3, F-P2.2-01 —
    outcome-характеризация мержа + host-приоритет + структурный guard-пин) и
    `packages/core/tests/Unit/InstallationDocsGuardTest.php` (1, F-P2.2-02 — green
    doc-guard). Root-гейт зелёный (411 passed root, 130 passed + 1 pre-existing skipped
    documents); `cd packages/documents && composer analyse/test/test:types` — все зелёные
    (0 phpstan errors, 100% type-coverage). **Pending Work:** `CHANGELOG.md` запись за
    P3.5 — файл deny-листед для Read/Write в этой сессии (долг с P1.2, см. Open risks ниже).
    Routing-adherence: sonnet/medium как предписано (без отклонений).
  - **P3.6 (B23):** параметризация `config('vaulter.connection')` (nullable) в схему+модели
    + doc политики изменения схемы (F-P2.3-02/04). Core. Автономен, готов. **Последний item
    фазы P3** — после его закрытия фаза P3 (W3) терминальна.

**Remaining:** P3.6 (1 item, ГОТОВ к exec, автономен), P4 (10, СКЕЛЕТ), P5 (3, СКЕЛЕТ).
Развёртывание P4/P5 — `/task:plan-design VLT-REMED Pn` перед первым item'ом каждой
(скелет-фаза не исполняется напрямую).

**Sources of truth:** `plans/VLT-REMED/plan.md` (Context/Execution Rules/Routing/Status
Board/Decision Log D1–D21, Update Log с записью P3.5) ·
`plans/VLT-REMED/phases/P3.md` (P3.1–P3.5 закрыты 🟢 с Completion Notes; P3.6 готов) ·
`plans/VLT-REMED/phases/{P0,P1,P2}.md` (закрыты; P2 🟠) ·
`plans/VLT-REMED/open-questions.md` (Q3/Q4 pending — не блокируют P3) ·
`plans/VLT-REMED/findings/P0-remediation-norms.md` (N3 — коды tus, применена в P3.4;
неизменны) ·
`plans/AUDIT-VLT/findings/REGISTER.md` (SSOT 103 находок, append-only, VLT-REMED в него
НЕ пишет) ·
`plans/AUDIT-VLT/findings/{P1.4-uploads,P2.2-service-providers,
P2.3-schema-embeddability}.md` (детальные находки батчей P3.5/P3.6) ·
`packages/documents/tests/Collaboration/CollaborationEnabledTestCase.php` (эталон:
как правильно включать оба collab-флага в тестах — В `defineEnvironment()`, ДО boot,
не в `beforeEach()`) ·
`packages/core/src/Webhooks/WebhookSigner.php` (canonical string, переиспользован
P3.2 для входящего направления — эталон для будущих HMAC-швов пакета) ·
`packages/drive/src/Uploads/Models/UploadSession.php` (`scopeSweepable` — P3.3 эталон
«TTL-порог = expires_at, стамплен один раз, не бампается» для любого будущего
lifecycle-sweep) ·
`packages/drive/src/Activity/RecordsDriveActivity.php` (эталон: как добавить новое
lifecycle-событие в activity-feed через существующий `ActivityRecorder::record()`) ·
`packages/drive/src/Exceptions/{UploadConflictException,OffsetMismatchException}.php`
(P3.4 эталон: self-rendering `render(Request): JsonResponse`; два РАЗНЫХ 409-сценария
намеренно оставлены как два класса — не сливать) ·
`packages/drive/src/Uploads/StagedUpload.php::rangeMatches()` (P3.4 эталон: потоковая
byte-range сверка без полного чтения staged-файла) ·
`packages/documents/src/VaulterDocumentsServiceProvider.php::packageRegistered()`
(P3.5 эталон: `configurationIsCached()`-гвард вокруг ручного merge, когда
`mergeConfigFrom` не подходит из-за нескольких top-level ключей в одном config-файле) ·
`packages/drive/src/VaulterDriveServiceProvider.php:48` (эталон точечного
`mergeConfigFrom` под `configurationIsCached` — образец для P3.6, если понадобится
новый config-ключ там же).

**Open risks:**
- Root `composer.json` phpstan/phpunit покрывают ТОЛЬКО `packages/core`. P3.6 —
  весь `packages/core` (root покрывает целиком) — own-гейт сверх root не требуется,
  свериться по факту при exec, если Files-список расширится.
- Root `composer lint:check` красный на пред-существующем
  `plans/AUDIT-VLT/artifacts/repro/**` (чужой read-only план, вне scope) — closure НЕ
  блокирует; свой новый код обязан быть pint-чистым (проверено точечно
  `vendor/bin/pint --test <файлы>` в P3.1–P3.5 — держать эту практику).
- `CHANGELOG.md` заблокирован для Read/Write (`deny: Read(**/CHANGELOG.md)`) — долг
  записей за P1.2, P1.4, P2.1, P2.2, P2.3, P3.1, P3.2, P3.3, P3.4, P3.5 и далее.
  `UPGRADE.md` покрывает breaking-change-документирование по D3. Сессия с доступом
  должна догнать CHANGELOG.md.
- **Пред-существующий PG-only флейк `NodeCommentSchemaTest`** (jsonb `anchor` key-order
  round-trip на PostgreSQL) — вне scope, ещё не заведён отдельной находкой. Следующая
  сессия с доступом к `findings/` должна это сделать.
- В рабочем дереве присутствуют НЕ относящиеся к VLT-REMED правки (`.claude/.maind-
  feature-log.last` удалён, `.gitignore`, `.claude/workflows/vlt-remed-*.js`,
  `plans/ACTIVE.md`, `plans/AUDIT-VLT/{plan.md,phases/*.md}`, `plans/VLT-HSEAM/`) из
  конкурентной сессии/хука — НЕ трогать, коммитить только scoped-пути item'а (P3.5
  подтвердил: staged только файлы item'а + плана, остальное осталось нетронутым).

**Workarounds/Deferred/Open questions:**
- workarounds: drive/documents/filament — их own `composer analyse`/`test`/`test:types`
  (root покрывает только core). Node-часть collab (P3.2) — `cd hocuspocus-server && npm
  test` (node:test). Testbench-дефолт `cache.default=database` без миграции →
  `cache.default => 'array'` в тестовом `defineEnvironment()` (нужно для throttle P1.3 и
  nonce-store P3.2). Feature-флаги, гейтящие МАРШРУТЫ (`document_versions`,
  `collaboration`, `comments`), обязаны ставиться в `defineEnvironment()` (до boot), НЕ в
  `beforeEach()` теста. Console-контекст в in-process тестах (`$this->artisan(...)`)
  НАСЛЕДУЕТ `actingAs()`-пользователя из того же теста — `Auth::logout()` перед прогоном
  консольной команды, если тест проверяет null-актор системного действия. PG-прогон для
  F-id с реальным FK/SERIALIZABLE — `php8.4` + БД `vaulter_test` в контейнере
  `agelto_postgres` (127.0.0.1:5455, роль `vaulter`); P3.5 не потребовал PG (config-merge/
  doc-guard не задевают FK/SERIALIZABLE-инварианты P0.3). tus HTTP-протокольные тесты —
  порядок проверок ВАЖЕН: authorizeSession (ownership) идёт ПЕРЕД protocol-assertions
  в контроллере. Один config-файл, несущий НЕСКОЛЬКО top-level `vaulter.*` ключей
  (P3.5-урок), не годится под один `mergeConfigFrom` — либо расщепить на N файлов + N
  вызовов, либо гвардировать ручной merge `configurationIsCached()` напрямую (выбрано в
  P3.5 как минимальный путь).
- deferred: `docs/`-миграция и архивация AUDIT-VLT — отдельный
  `/task:plan-close archive AUDIT-VLT`. Ротация `signing_secret` (Q3) и абсолютный
  барьер wall-deny (Q4) — отдельные items по решению владельца. `CHANGELOG.md` записи
  за P1.2/P1.4/P2.1/P2.2/P2.3/P3.1/P3.2/P3.3/P3.4/P3.5 и далее — сессия с доступом.
  Пред-существующий PG-only jsonb-флейк `NodeCommentSchemaTest` — завести
  находку/чинить отдельно. Полное покрытие `Tus-Resumable` на POST/GET (create/status) —
  вне заявленного scope F-P1.4-06, не заводить без новой находки.
- open_questions: Q3 (ротация `signing_secret`), Q4 (абсолютный барьер wall-deny) —
  оба `Decision pending`, ни один P3-item не блокируют. Q1/Q2 — `Resolved → D1/D10`.
