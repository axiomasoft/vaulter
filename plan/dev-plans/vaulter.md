# План разработки репозитория `vaulter` (Базовая публичная платформа)

**Паспорт:** [repos/vaulter.md](../repos/vaulter.md) · **Спецификация:** [spec/implementation/vaulter.md](../spec/implementation/vaulter.md) · **Стандарты и стоп-краны CI:** [spec/engineering-standards.md](../spec/engineering-standards.md) · **Схема БД:** [spec/database/sql/documents.sql](../spec/database/sql/documents.sql) · **Приёмка:** [acceptance.md](../acceptance.md)

Репозиторий уже существует (`/home/vostrikov/projects/packages/vaulter`). Цель работ — зафиксировать baseline, выполнить инженерное укрепление базы (`0.3`: 64-bit advisory locks, ETag/proxy resilience, SSRF `CURLOPT_CONNECT_TO`, ZIP `S_IFLNK`, CI стоп-краны и документационный долг), переразложить хранение документов и ввести `write_mode`, вынести закрытые части (`vaulter-sheets` и `vaulter-collaboration`), добавить стабильные `blockId` и публичный порт `AtomicDocumentMutation`, подтвердить чистую автономную установку для FlexCms.

## Очередь задач репозитория

| Шаг | ID | Волна | Зависимости | Исполнитель | Ревьюер | Сдаваемый артефакт |
| --- | --- | --- | --- | --- | --- | --- |
| 1 | `0.1` | W0 | нет | **Grok** | **Gemini Neon** | Логи `flexcms/run.sh`, SHA-256 архивов, baseline экспортов |
| 2 | `0.2` | W0 | нет | **Grok** | **Gemini Neon** | Отчёт `verify-fixtures.py`, `schema.sh` (0 unindexed FKs), `storage-benchmark.sh` |
| 3 | `0.3` | W0.5 | `0.1`, `0.2` | **GPT-6.2 Sol** | **Astra** | 64-bit `hashtextextended` (`VaulterDatabase`, `BlobLock`), `X-Vaulter-ETag` + `DocumentContentResource.etag` + CORS Expose + `@vaulter/vue`, `CURLOPT_CONNECT_TO` + запрет trailing-dot в `webhooks`, `S_IFLNK` guard в `MimeSniffer`, `SQLSTATE 23001`, CI stop-rails, ADR `0015` и расширенные ранбуки |
| 4 | `1.0` | W1 | `0.1`, `0.2`, `0.3` | **GPT-6.2 Sol** | **Astra** | 4 таблицы payload/sync, `write_mode`, `ChangeDocumentWriteModeAction`, обновлённые schema snapshots (включая `v_documents_head_fk_idx` и `v_files_head_fk_idx`) |
| 5 | `2.0` (часть `vaulter`) | W1 | `0.1`, `0.3` | **Gemini Neon** | **GPT-6.2 Sol** | Удаление `packages/collaboration`, `packages/vue-collaboration`, `hocuspocus-server/`, `packages/azgard/`, приватных записей `release/packages.json` (после подтверждения сборки `vaulter-collaboration`) |
| 6 | `1.2` | W2 | `1.0`, `1.1` (из `vaulter-sheets`) | **Gemini Neon** | **GPT-6.2 Sol** | Удаление `SpreadsheetDocumentType`, `VaulterSpreadsheet`, `vaulter/univer`; переснятые PHP/TS API snapshots |
| 7 | `3.1` | W2 | `1.0` | **Gemini Neon** | **GPT-6.2 Sol** | `attrs.blockId` (schema v2), `BlockIdentity.php`, `InitializeBlockIdsAction.php`, `packages/vue/src/tiptap/block-id.ts` |
| 8 | Порт `AtomicDocumentMutation` | W3 (старт) | `1.0`, `3.1` | **GPT-6.2 Sol** | **Astra** | `AtomicDocumentMutation`, `LockedDocumentMutation`, `DocumentMutationState`, `RunAtomicDocumentMutation`, `WriteLockedDocumentContent`, `RequiresCommandWrite`, ошибка `409 document_command_required` (сдаётся в рамках `3.3` / `4.2`) |
| 9 | `1.3` | W3 | `1.2` | **Grok** | **Gemini Neon** | Автономный потребитель `e2e/consumers/documents-basic/` (Drive → Tiptap → Save/412/403) |

## Детальные инструкции по шагам

### Шаг 1–2 (`0.1`, `0.2`) — Grok → ревью Gemini Neon
- Запустить `bash plan/checks/flexcms/run.sh` (при наличии `PACKMAN_DIR`), `python3 plan/checks/verify-fixtures.py`, `PG_BIN=/usr/lib/postgresql/18/bin bash plan/checks/schema.sh`, `PG_BIN=/usr/lib/postgresql/18/bin bash plan/checks/storage-benchmark.sh /tmp/vaulter-storage-warm.json`.
- Сохранить ревизии, SHA-256 архивов и список экспортов `@vaulter/vue` (зафиксировать наличие `VaulterSpreadsheet` до переноса).

### Шаг 3 (`0.3`) — GPT-6.2 Sol → ревью Astra
- **64-битные advisory locks, PG 18/19 `SQLSTATE 23001` и защита `#61964`:**
  - В `packages/core/src/Database/VaulterDatabase.php` (`lockAggregateShared`, `advisoryLock`) и `packages/media/src/Cas/BlobLock.php` (`acquireMany`) заменить `hashtext(...)` на `hashtextextended(..., 0)`. Обновить зависимые тесты и фикстуры (`load/app/Console/ConcurrencyHashtext.php`, `ConcurrencyFixtureSupport.php`).
  - В маппере ошибок БД (`vaulter-core`) обрабатывать PG 18 `SQLSTATE 23001` (`restrict_violation`) наряду с `23503`; в `VaulterDatabase::transaction()` гарантировать `SET CONSTRAINTS ALL IMMEDIATE` перед `commit()` на PG и очистку PDO/`afterCommit` при не-concurrency ошибке `COMMIT` (`laravel/framework#61964`).
- **Устойчивость `If-Match` / `ETag` за Nginx/Cloudflare, `Idempotency-Key` и CORS:**
  - На ответах с сильным `ETag` (`packages/core`, `packages/drive`, `packages/documents`) выставлять дублирующий заголовок `X-Vaulter-ETag`, `Cache-Control: private, no-cache, no-transform`, `Vary: Authorization, Accept`, добавить `'etag' => $this->etag()` в `DocumentContentResource::toArray()`, и экспонировать `Access-Control-Expose-Headers: ETag, X-Vaulter-ETag, Retry-After, Idempotent-Replayed, X-Vaulter-Api-Version` + `Access-Control-Allow-Headers` (`If-Match`, `X-Vaulter-If-Match`, `Idempotency-Key`).
  - Явно отвергать слабый `If-Match: W/"..."` с `412` (`weak_etag_not_allowed`); поддержать 7-дневное надгробие `expired` → `410 Gone` для `Idempotency-Key` (draft-09) и multipart-fingerprinting без `boundary`.
  - В `@vaulter/vue` (`packages/vue/src/api/`) читать тег в приоритете `body.etag → X-Vaulter-ETag → ETag`. Добавить тест с симуляцией прокси, ослабляющего `ETag` до `W/"..."`, и CORS.
- **SSRF вебхуков и `ZipCentralDirectoryGuard`:**
  - В `packages/webhooks/src/DefaultOutboundUrlGuard.php` использовать `Uri\WhatWg\Url` (PHP 8.5) / `idn_to_ascii` (PHP 8.4), запретить `userinfo` и trailing-dot хосты (`example.com.`), в `PinnedOutboundEndpoint.php` добавить `curlConnectToEntry()` (с `[ip]` для IPv6) и перевести `DeliverWebhook.php` на `CURLOPT_CONNECT_TO` + **`CURLOPT_FORBID_REUSE => true`** (сохранив `curlResolveEntry()` deprecated для BC).
  - В `packages/drive/src/Uploads/Inspection/MimeSniffer.php` внедрить `ZipCentralDirectoryGuard` (без `extractTo()`, запрет `..`, `\`, `\0`, ведущих `/`, дубликатов, `S_IFLNK` `0120000` и zip-бомб).
- **Стоп-краны CI, `vaulter:doctor` и документационный долг:**
  - Внедрить все 12 стоп-кранов из [engineering-standards.md](../spec/engineering-standards.md) (`spaze/phpstan-disallowed-calls ^4.15.1` на `DB::transaction()`, `set_config(..., false)`, `hashtext(`, `pg_advisory_lock(`, `parse_url(`, `ZipArchive::extractTo()`, `VerifyCsrfToken::class`; лицензионный сканер CI; guard `@phpstan-ignore`/`->skip()`; проверка `ClamAV >= 1.5.5` в `vaulter:doctor`; матрица `PG 18.7 + PG 19.0` и `Node 26.13.1`).
  - Зафиксировать статус ADR `0015` в `docs/adr/index.md` и расширить короткие ранбуки (< 1 000 байт) в `docs/guides/operations/`.
- **Проверка:** `scripts/t pgsql -p core,media,drive,documents,webhooks --full`, `npm test --workspace @vaulter/vue`, `composer php-api`, `PG_BIN=/usr/lib/postgresql/18/bin bash plan/checks/schema.sh` (0 unindexed FKs), негативные тесты CI (`hashtext(`, `Internal`, `example.com.`, `userinfo`, `S_IFLNK`, `#61964`).

### Шаг 4 (`1.0`) — GPT-6.2 Sol → ревью Astra
- **Миграции и схемы:** обновить `000302` (`v_documents` с `write_mode`, default `snapshot`) и `000303` (`v_document_revisions`), создать `000304`..`000307` (`v_document_payloads`, `v_document_sync_states`, `v_document_revision_payloads`, `v_document_revision_sync_states`) строго по `plan/spec/database/sql/documents.sql`.
- **Модели и репозитории:** создать `DocumentPayload`, `DocumentSyncState`, `DocumentRevisionPayload`, `DocumentRevisionSyncState`; переключить `DocumentStateWriter`, `DocumentContentLifecycle`, `DatabaseCollaborativeState`, `PendingStateGuard`, `CreateDocumentAction`, `UpdateDocumentContentAction`, `RestoreDocumentRevisionAction`.
- **Смена режима:** создать `ChangeDocumentWriteModeAction` (операция `change_write_mode`), запретить обычный PUT при `write_mode=journal` (`409 document_command_required`), оставить `collab_epoch` неизменным при обычном snapshot edit.
- **Проверка:** `scripts/t pgsql -p documents --full`, `scripts/t sqlite -p documents`, 3 гоночных сценария `document-race.py` через Laravel на двух соединениях, проверка профилей `uuid7.bigint.v.on` на PostgreSQL/MySQL/SQLite.

### Шаг 5 (`2.0`) — Gemini Neon → ревью GPT-6.2 Sol
- Координируется с `vaulter-collaboration`: сначала вынести и собрать закрытые архивы в `vaulter-collaboration`, затем удалить из `vaulter` каталоги `packages/collaboration/`, `packages/vue-collaboration/`, `hocuspocus-server/`, `packages/azgard/` (если не в релизе), очистить приватные записи в `release/packages.json`, обновить `composer.json` и `package.json`.
- **Проверка:** `php scripts/release/archive-check.php --all --worktree`, `composer gate`, `npm run test --workspace @vaulter/vue`.

### Шаг 6 (`1.2`) — Gemini Neon → ревью GPT-6.2 Sol
- После приёмки `1.1` в `vaulter-sheets` удалить `SpreadsheetDocumentType.php`, `SpreadsheetContentTest.php`, `VaulterSpreadsheet.vue`, `spreadsheet.test.ts` и ссылки в `config/vaulter-documents.php`, `packages/vue/src/{index,plugin,viewers/builtin}.ts`.
- Заменить в общих тестах ссылки на `SpreadsheetDocumentType` локальной фикстурой `OpaqueNoteType.php`.
- Переснять контракты: `composer php-api`, `npm run api:surface:update --workspace @vaulter/vue && npm run api:surface:check --workspace @vaulter/vue`.
- **Проверка:** grep `SpreadsheetDocumentType|VaulterSpreadsheet|vaulter/univer|vaulter/spreadsheet` по распакованным ZIP/tgz даёт 0 рабочих совпадений; базовый потребитель собирается без приватных токенов.

### Шаг 7 (`3.1`) — Gemini Neon → ревью GPT-6.2 Sol
- Ввести `vaulter-documents.tiptap.block_ids` (default `false`), `BlockIdentity.php`, `InitializeBlockIdsAction.php` (CAS переход schema v1 → v2), `packages/vue/src/tiptap/block-id.ts`.
- Обеспечить правила: split (первая часть сохраняет ID, вторая — новый), join (ID первого), paste (новый ID), move (сохраняет ID), restore (ID ревизии + новое поколение), GET не пишет.
- Синхронизировать фикстуру схемы с `vaulter-collaboration`.
- **Проверка:** `BlockIdentityTest.php`, `block-id.test.ts`, сохранение bold/link attrs на p42.

### Шаг 8 (Порт `AtomicDocumentMutation`) — GPT-6.2 Sol → ревью Astra
- Реализовать в `packages/documents/src/`: `Contracts/AtomicDocumentMutation.php`, `Contracts/LockedDocumentMutation.php`, `Contracts/RequiresCommandWrite.php`, `Data/Documents/DocumentMutationState.php`, `Actions/Documents/RunAtomicDocumentMutation.php`, `LockedMutation.php`, `WriteLockedDocumentContent.php`, `Exceptions/DocumentCommandRequiredException.php`.
- Строгий порядок блокировок: `T → N → свежий Edit под N → D`, повтор callback при `40001/40P01`, образец — `plan/examples/reference/atomic-mutation/`.
- **Проверка:** `AtomicDocumentMutationTest.php`, `AtomicDocumentMutationRaceTest.php` (перенос сценариев B, C, D), `composer php-api`, `composer error-codes`.

### Шаг 9 (`1.3`) — Grok → ревью Gemini Neon
- Создать `e2e/consumers/documents-basic/` по дереву из `plan/spec/implementation/hosts.md`.
- Собрать чистый Laravel + `@vaulter/vue` из релизных ZIP/tgz, открыть rich text и Markdown через `VaulterDrive @open-node` → `VaulterDocumentNode` + Tiptap.
- **Проверка:** `bash e2e/consumers/documents-basic/run.sh` → exit 0, сохранение узлов/marks/attrs/table, 412 во второй вкладке с сохранением черновика, 403 для reader.
