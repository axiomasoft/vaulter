# План разработки репозитория `vaulter` (Базовая публичная платформа)

**Паспорт:** [repos/vaulter.md](../repos/vaulter.md) · **Спецификация:** [spec/implementation/vaulter.md](../spec/implementation/vaulter.md) · **Схема БД:** [spec/database/sql/documents.sql](../spec/database/sql/documents.sql) · **Приёмка:** [acceptance.md](../acceptance.md)

Репозиторий уже существует (`/home/vostrikov/projects/packages/vaulter`). Цель работ — зафиксировать baseline, переразложить хранение документов и ввести `write_mode`, вынести закрытые части (`vaulter-sheets` и `vaulter-collaboration`), добавить стабильные `blockId` и публичный порт `AtomicDocumentMutation`, подтвердить чистую автономную установку для FlexCms.

## Очередь задач репозитория

| Шаг | ID | Волна | Зависимости | Исполнитель | Ревьюер | Сдаваемый артефакт |
| --- | --- | --- | --- | --- | --- | --- |
| 1 | `0.1` | W0 | нет | **Gemini Argon** | Gemini Carbon | Логи `flexcms/run.sh`, SHA-256 архивов, baseline экспортов |
| 2 | `0.2` | W0 | нет | **Gemini Argon** | Gemini Carbon | Отчёт `verify-fixtures.py`, `schema.sh`, `storage-benchmark.sh` |
| 3 | `1.0` | W1 | `0.1`, `0.2` | **GPT-6.2 Sol** | **Astra** | 4 таблицы payload/sync, `write_mode`, `ChangeDocumentWriteModeAction`, обновлённые schema snapshots |
| 4 | `2.0` (часть `vaulter`) | W1 | `0.1` | **Gemini Neon** | GPT-6.2 Sol | Удаление `packages/collaboration`, `packages/vue-collaboration`, `hocuspocus-server/`, `packages/azgard/`, приватных записей `release/packages.json` (после подтверждения сборки `vaulter-collaboration`) |
| 5 | `1.2` | W2 | `1.0`, `1.1` (из `vaulter-sheets`) | **Gemini Neon** | Gemini Carbon | Удаление `SpreadsheetDocumentType`, `VaulterSpreadsheet`, `vaulter/univer`; переснятые PHP/TS API snapshots |
| 6 | `3.1` | W2 | `1.0` | **Gemini Neon** | GPT-6.2 Sol | `attrs.blockId` (schema v2), `BlockIdentity.php`, `InitializeBlockIdsAction.php`, `packages/vue/src/tiptap/block-id.ts` |
| 7 | Порт `AtomicDocumentMutation` | W3 (старт) | `1.0`, `3.1` | **GPT-6.2 Sol** | **Astra** | `AtomicDocumentMutation`, `LockedDocumentMutation`, `DocumentMutationState`, `RunAtomicDocumentMutation`, `WriteLockedDocumentContent`, `RequiresCommandWrite`, ошибка `409 document_command_required` (сдаётся в рамках `3.3` / `4.2`) |
| 8 | `1.3` | W3 | `1.2` | **Gemini Carbon** | Gemini Neon | Автономный потребитель `e2e/consumers/documents-basic/` (Drive → Tiptap → Save/412/403) |

## Детальные инструкции по шагам

### Шаг 1–2 (`0.1`, `0.2`) — Gemini Argon
- Запустить `bash plan/checks/flexcms/run.sh` (при наличии `PACKMAN_DIR`), `python3 plan/checks/verify-fixtures.py`, `PG_BIN=/usr/lib/postgresql/18/bin bash plan/checks/schema.sh`, `PG_BIN=/usr/lib/postgresql/18/bin bash plan/checks/storage-benchmark.sh /tmp/vaulter-storage-warm.json`.
- Сохранить ревизии, SHA-256 архивов и список экспортов `@vaulter/vue` (зафиксировать наличие `VaulterSpreadsheet` до переноса).

### Шаг 3 (`1.0`) — GPT-6.2 Sol → ревью Astra
- **Миграции и схемы:** обновить `000302` (`v_documents` с `write_mode`, default `snapshot`) и `000303` (`v_document_revisions`), создать `000304`..`000307` (`v_document_payloads`, `v_document_sync_states`, `v_document_revision_payloads`, `v_document_revision_sync_states`) строго по `plan/spec/database/sql/documents.sql`.
- **Модели и репозитории:** создать `DocumentPayload`, `DocumentSyncState`, `DocumentRevisionPayload`, `DocumentRevisionSyncState`; переключить `DocumentStateWriter`, `DocumentContentLifecycle`, `DatabaseCollaborativeState`, `PendingStateGuard`, `CreateDocumentAction`, `UpdateDocumentContentAction`, `RestoreDocumentRevisionAction`.
- **Смена режима:** создать `ChangeDocumentWriteModeAction` (операция `change_write_mode`), запретить обычный PUT при `write_mode=journal` (`409 document_command_required`), оставить `collab_epoch` неизменным при обычном snapshot edit.
- **Проверка:** `scripts/t pgsql -p documents --full`, `scripts/t sqlite -p documents`, 3 гоночных сценария `document-race.py` через Laravel на двух соединениях, проверка профилей `uuid7.bigint.v.on` на PostgreSQL/MySQL/SQLite.

### Шаг 4 (`2.0`) — Gemini Neon → ревью GPT-6.2 Sol
- Координируется с `vaulter-collaboration`: сначала вынести и собрать закрытые архивы в `vaulter-collaboration`, затем удалить из `vaulter` каталоги `packages/collaboration/`, `packages/vue-collaboration/`, `hocuspocus-server/`, `packages/azgard/` (если не в релизе), очистить приватные записи в `release/packages.json`, обновить `composer.json` и `package.json`.
- **Проверка:** `php scripts/release/archive-check.php --all --worktree`, `composer gate`, `npm run test --workspace @vaulter/vue`.

### Шаг 5 (`1.2`) — Gemini Neon → ревью Gemini Carbon
- После приёмки `1.1` в `vaulter-sheets` удалить `SpreadsheetDocumentType.php`, `SpreadsheetContentTest.php`, `VaulterSpreadsheet.vue`, `spreadsheet.test.ts` и ссылки в `config/vaulter-documents.php`, `packages/vue/src/{index,plugin,viewers/builtin}.ts`.
- Заменить в общих тестах ссылки на `SpreadsheetDocumentType` локальной фикстурой `OpaqueNoteType.php`.
- Переснять контракты: `composer php-api`, `npm run api:surface:update --workspace @vaulter/vue && npm run api:surface:check --workspace @vaulter/vue`.
- **Проверка:** grep `SpreadsheetDocumentType|VaulterSpreadsheet|vaulter/univer|vaulter/spreadsheet` по распакованным ZIP/tgz даёт 0 рабочих совпадений; базовый потребитель собирается без приватных токенов.

### Шаг 6 (`3.1`) — Gemini Neon → ревью GPT-6.2 Sol
- Ввести `vaulter-documents.tiptap.block_ids` (default `false`), `BlockIdentity.php`, `InitializeBlockIdsAction.php` (CAS переход schema v1 → v2), `packages/vue/src/tiptap/block-id.ts`.
- Обеспечить правила: split (первая часть сохраняет ID, вторая — новый), join (ID первого), paste (новый ID), move (сохраняет ID), restore (ID ревизии + новое поколение), GET не пишет.
- Синхронизировать фикстуру схемы с `vaulter-collaboration`.
- **Проверка:** `BlockIdentityTest.php`, `block-id.test.ts`, сохранение bold/link attrs на p42.

### Шаг 7 (Порт `AtomicDocumentMutation`) — GPT-6.2 Sol → ревью Astra
- Реализовать в `packages/documents/src/`: `Contracts/AtomicDocumentMutation.php`, `Contracts/LockedDocumentMutation.php`, `Contracts/RequiresCommandWrite.php`, `Data/Documents/DocumentMutationState.php`, `Actions/Documents/RunAtomicDocumentMutation.php`, `LockedMutation.php`, `WriteLockedDocumentContent.php`, `Exceptions/DocumentCommandRequiredException.php`.
- Строгий порядок блокировок: `T → N → свежий Edit под N → D`, повтор callback при `40001/40P01`, образец — `plan/examples/reference/atomic-mutation/`.
- **Проверка:** `AtomicDocumentMutationTest.php`, `AtomicDocumentMutationRaceTest.php` (перенос сценариев B, C, D), `composer php-api`, `composer error-codes`.

### Шаг 8 (`1.3`) — Gemini Carbon → ревью Gemini Neon
- Создать `e2e/consumers/documents-basic/` по дереву из `plan/spec/implementation/hosts.md`.
- Собрать чистый Laravel + `@vaulter/vue` из релизных ZIP/tgz, открыть rich text и Markdown через `VaulterDrive @open-node` → `VaulterDocumentNode` + Tiptap.
- **Проверка:** `bash e2e/consumers/documents-basic/run.sh` → exit 0, сохранение узлов/marks/attrs/table, 412 во второй вкладке с сохранением черновика, 403 для reader.
