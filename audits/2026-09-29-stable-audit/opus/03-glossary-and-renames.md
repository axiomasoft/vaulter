# 03 — Словарь и карта переименований

Этот файл — нормативный для исполнителей: новое имя берётся **только** отсюда. Если
символа нет в таблицах, его имя не меняется (кроме перемещения в domain-подпапку по
ADR-0017, см. [04-packages-and-layout.md](04-packages-and-layout.md)).

## 1. Словарь

| Термин | Определение | Где в коде |
|---|---|---|
| **Owner** | Модель хоста, которой принадлежат drives (Project, Course, Team) | `v_drives.owner_type/owner_id`, `OwnerRef` |
| **Profile** | Именованная политика для группы drives: владельцы, диск, квота, загрузки, драйвер прав, типы документов, фичи | `Vaulter\Profiles\Profile` |
| **Drive** | Логическое файловое пространство одного владельца в одном профиле; имеет корневую папку | `Vaulter\Models\Drive\Drive`, `v_drives` |
| **Drive handle** | Объект операций над одним drive от имени одного actor'а | `Vaulter\Contracts\Drive\DriveHandle` |
| **Node** | Элемент дерева drive | `Vaulter\Models\Drive\Node`, `v_nodes` |
| **Folder / File / Document / Shortcut** | Значения `NodeKind`: контейнер / бинарное содержимое / структурированное содержимое / ссылка (зарезервировано) | `Vaulter\Enums\Drive\NodeKind` |
| **Root** | Единственная папка drive с `parent_id = NULL` | `v_drives.root_node_id` |
| **Revision** | Неизменяемый снимок содержимого файла или документа | `FileRevision`, `DocumentRevision` |
| **Head** | Текущая ревизия агрегата | `current_revision_id` в `v_files`/`v_documents` |
| **Blob** | Неизменяемый объект байтов в CAS | `Vaulter\Models\Cas\Blob` |
| **Blob scope** | Область дедупликации: global / tenant / drive | `BlobScope`, `v_blobs.scope_key` |
| **File handler** | Обработчик формата файла: возможности и viewer по MIME/расширению | `Vaulter\Contracts\Handlers\FileHandler` |
| **Document type** | Тип структурированного содержимого: схема, codec, версия схемы, viewer | `Vaulter\Documents\Contracts\DocumentType` |
| **Viewer** | Клиентский компонент показа/редактирования, адресуемый ключом `vendor/name` | `ViewerDescriptor::$key` |
| **Exporter** | Преобразование содержимого документа определённого типа в выходной формат | `Vaulter\Documents\Contracts\DocumentExporter` |
| **Actor** | Субъект операции: пользователь хоста или system (с причиной) | `Vaulter\ValueObjects\Access\Actor` |
| **Operation context** | Неизменяемый контекст операции: drive, actor, correlation id, idempotency key | `Vaulter\Operations\OperationContext` |
| **Permission driver** | Адаптер ролей/прав хоста (database, gate, spatie, azgard, свой) | `Vaulter\Contracts\Access\PermissionDriver` |
| **Access resolver** | Внутренний несменяемый вычислитель эффективных прав (driver + grants) | `Vaulter\Access\AccessResolver` |
| **Grant** | ACL-запись на узле для субъекта (allow/deny, item/subtree, уровень) | `NodeGrant` |
| **Share link** | Анонимная ссылка-токен на узел или поддерево | `NodeShare` |
| **Ability** | Действие, проверяемое над узлом (`view`, `download`, `comment`, `edit`, …) | `Vaulter\Enums\Access\Ability` |
| **Event type** | Каноническое имя доменного события (`node.moved`) — общее для activity и webhooks | `Vaulter\Enums\Events\EventType` |
| **Sequence** | Счётчик изменений узла: ETag, оптимистичная блокировка, порядок событий | `v_nodes.sequence` |
| **Collab epoch** | Поколение комнаты совместного редактирования документа | `v_documents.collab_epoch` |

## 2. Правила именования

| Что | Правило | Пример |
|---|---|---|
| Use case (команда) | `<Verb><Noun>Action`, метод `execute(OperationContext, <Verb><Noun>Input)` | `MoveNodeAction::execute()` |
| Вход use case | `*Input` (внутренний) / `*Options` (публичные необязательные параметры) | `MoveNodeInput`, `MoveOptions` |
| Результат со смыслом | существительное без суффикса | `NodePage`, `MintedShareLink`, `FileStream` |
| Чтение | `<Noun>Query` в `Queries/<Domain>` | `ListChildrenQuery` |
| Sub-API handle | `<Noun>Operations` | `NodeOperations` |
| Событие | прошедшее время, `<Noun><Verb>ed` | `NodeMoved`, `FileRevisionAdded` |
| Тип события | `noun.verb_past` в snake/dot | `file.revision_added` |
| Исключение | `<Condition>Exception`, код `snake_case` | `NodeNameConflictException` / `node_name_conflict` |
| Контракт и реализация | одинаковое короткое имя в разных namespace (конвенция Laravel) | `Contracts\Drive\DriveHandle` / `Drive\DriveHandle` |
| Ключ расширения | `vendor/name`, `[a-z0-9-]` | `vaulter/rich-text`, `acme/diagram` |
| Команда artisan | `vaulter:<area>:<verb>` | `vaulter:blobs:gc` |
| Маршрут | `vaulter.v1.<resource>.<action>` | `vaulter.v1.nodes.move` |
| JSON REST | `snake_case`, ID — строки | `"drive_id": "01J…"` |

Словарь аргументов (для всех публичных методов и HTTP):

| Аргумент | Смысл |
|---|---|
| `node` | Узел, над которым выполняется операция |
| `parent` | Папка, в которой **создаётся новый** узел (folder, document, upload) |
| `destination` | Папка, куда переходит **существующий** узел (move, copy, restore) |
| `folder` | Папка, содержимое которой читается (children) |
| `source` | Входные байты (`UploadSource`) |
| `revision` | Ревизия файла/документа |
| `expectedSequence` | Ожидаемый `sequence` узла (оптимистичная блокировка) |

`into`, `target`, `targetNode`, `actorId` из публичных сигнатур удаляются.

## 3. Классы: core (`Vaulter\`)

| Было | Стало | Примечание |
|---|---|---|
| `Models\Drive\Storage` | `Models\Drive\Drive` | таблица `v_drives` |
| `Models\Drive\FileVersion` | `Models\Drive\FileRevision` | |
| `Models\Drive\FileVersionBlob` | `Models\Drive\FileRevisionBlob` | |
| `Models\Concerns\HasVaulterPanel` | — удалить | D05 |
| `Panels\Panel` | `Profiles\Profile` | builder, см. 05 §6 |
| `Panels\StoragePanelProvider` | `Profiles\ProfileProvider` | |
| `Panels\DefaultStoragePanel` | `Profiles\DefaultProfile` | |
| `Panels\PanelRegistry` | `Profiles\ProfileRegistry` | |
| `Panels\PanelMigrationGenerator`, `Panels\PanelTableResolver` | — удалить | D05 |
| `Schema\PanelTablesBuilder` | `Schema\SchemaBuilder` | без параметра Panel |
| `Support\VaulterSchema` | `Schema\VaulterSchema` | + `hostKey()` для миграций хоста |
| `Console\MakePanelCommand` | `Console\MakeProfileCommand` | `vaulter:make:profile` |
| `Console\PanelMigrationsCommand` | — удалить | |
| `Console\RelayOutboxCommand` | `Console\RelayWebhooksCommand` | `vaulter:webhooks:relay` |
| `Console\RetryWebhooksCommand` | (класс тот же) | `vaulter:webhooks:retry` |
| `Console\PruneWebhooksCommand` | (класс тот же) | `vaulter:webhooks:prune` |
| — | `Console\DoctorCommand`, `Console\InstallCommand` | D34 |
| `Contracts\Access\HostRoleDriver` | `Contracts\Access\PermissionDriver` | сигнатуры — 06 §5 |
| `Access\HostRoleDriverManager` | `Access\PermissionDriverManager` | |
| `Drivers\Permission\DatabasePermissionResolver` | `Access\Drivers\DatabasePermissionDriver` | |
| `Drivers\Permission\GatePermissionResolver` | `Access\Drivers\GatePermissionDriver` | |
| `Drivers\Permission\SpatiePermissionResolver` | `Access\Drivers\SpatiePermissionDriver` | |
| `Drivers\Permission\AzgardPermissionResolver` | → `Vaulter\Azgard\AzgardPermissionDriver` | в пакет azgard |
| `Contracts\Access\AzgardGuard` | → `Vaulter\Azgard\Contracts\AzgardGuard` | в пакет azgard |
| `Support\AzgardConfig` | → `Vaulter\Azgard\AzgardPermissionMap` | в пакет azgard |
| `Testing\FakeAzgardGuard` | → `Vaulter\Azgard\Testing\FakeAzgardGuard` | в пакет azgard |
| `Support\GateConfig`, `Support\SpatieConfig` | `Configuration\AccessConfig` | |
| `Testing\NullRoleDriver` | `Testing\NullPermissionDriver` | |
| `Exceptions\Access\MissingHostRoleDriverException` | `Exceptions\Access\MissingPermissionDriverException` | |
| `Access\ValueObjects\AclScope` | `Access\ValueObjects\AccessScope` | поля см. 06 §5 |
| `Access\Abilities\NodeAbilities` | `Access\ValueObjects\NodeAbilities` | |
| `Enums\Access\PermissionLevel` | — удалить | мёртвый |
| `Contracts\Handlers\HandlerRegistry` | `Contracts\Handlers\FileHandlerRegistry` | + `resolve()` |
| `Contracts\Handlers\NodeHandlerResolver` | `Contracts\Handlers\NodePresenter` | `present(Node): NodePresentation` |
| `Contracts\Handlers\DocumentContent` | → `Vaulter\Documents\Contracts\DocumentContent` | в documents |
| `Contracts\Handlers\DocumentExporter` | → `Vaulter\Documents\Contracts\DocumentExporter` | в documents |
| `Engines\DocumentHandlerRegistry` | `Handlers\FileHandlerRegistry` | |
| `Engines\NodeHandlerResolver` | `Handlers\NodePresenter` | |
| `Engines\DocumentFileDescriptorFactory` | `Handlers\FileDescriptorFactory` | |
| `Engines\Handlers\AbstractFileHandler` | `Handlers\AbstractFileHandler` | |
| `Engines\Handlers\PreviewHandler` | `Handlers\Builtin\PreviewHandler` | ключ `vaulter/preview` |
| `Engines\Handlers\DownloadOnlyHandler` | `Handlers\Builtin\DownloadOnlyHandler` | ключ `vaulter/download` |
| `ValueObjects\Handlers\HandlerUi` | `ValueObjects\Handlers\ViewerDescriptor` | `{key, props}` |
| `ValueObjects\Handlers\HandlerDescriptor` | `ValueObjects\Handlers\NodePresentation` | |
| `ValueObjects\Handlers\HandlerCapabilities` | `ValueObjects\Handlers\ContentCapabilities` | без `richText` |
| `Exceptions\Documents\*` | → `Vaulter\Documents\Exceptions\*` | в documents |
| `Exceptions\Panels\InvalidPanelException` | `Exceptions\Profiles\InvalidProfileException` | |
| `Exceptions\Panels\UnknownPanelException` | `Exceptions\Profiles\UnknownProfileException` | |
| `Exceptions\Support\TenantIsolationNotConfiguredException` | `Exceptions\Tenancy\TenancyMisconfiguredException` | |
| `Support\TenantFootgunGuard` | — удалить | D10 |
| `Support\Config` | — удалить → `Configuration\VaulterConfig` + секции | D26 |
| `Enums\Webhooks\WebhookEvent` + `Enums\Activity\ActivityVerb` | `Enums\Events\EventType` | D22 |
| `Webhooks\RecordsOutbox`, `Activity\RecordsActivity` | — удалить | заменены `Events\EventRecorder` |
| `Webhooks\OutboxRecorder`, `Activity\ActivityRecorder` | остаются как внутренние писатели `EventRecorder` | |
| `Http\Support\TrashGuard` | — удалить | инвариант в Actions |
| `Repositories\Drive\StorageReadRepository` | `Repositories\Drive\DriveReadRepository` | |
| `Repositories\Drive\StorageStoreRepository` | `Repositories\Drive\DriveStoreRepository` | |
| `Repositories\Drive\FileVersion{Read,Store}Repository` | `Repositories\Drive\FileRevision{Read,Store}Repository` | |
| `Repositories\Drive\FileVersionBlobStoreRepository` | `Repositories\Drive\FileRevisionBlobStoreRepository` | |
| `Http\Resources\Drive\*` | без переименования, кроме полей | D33 |

Новые классы core — [05-php-api.md](05-php-api.md) и [06-extension-points.md](06-extension-points.md).

## 4. Классы: drive (`Vaulter\Drive\`)

| Было | Стало |
|---|---|
| `DriveContext` | `DriveHandle` (реализация `Vaulter\Contracts\Drive\DriveHandle`) |
| `DriveManager` | `DriveHandleFactory` |
| `StorageManager` | `DriveProvisioner` |
| `ValueObjects\WorkspaceRef` | — удалить (не используется) |
| `Enums\BulkAction` | — удалить (не используется) |
| `Actions\CreateFolderAction` | `Actions\Nodes\CreateFolderAction` |
| `Actions\RenameNodeAction` | `Actions\Nodes\RenameNodeAction` |
| `Actions\MoveNodeAction` | `Actions\Nodes\MoveNodeAction` |
| `Actions\CopyNodeAction` | `Actions\Nodes\CopyNodeAction` |
| — | `Actions\Nodes\UpdateNodeAction` (pin, position) |
| `Actions\TrashNodeAction` | `Actions\Trash\TrashNodeAction` |
| `Actions\RestoreNodeAction` | `Actions\Trash\RestoreNodeAction` |
| `Actions\ForceDeleteNodeAction` | `Actions\Trash\PurgeNodeAction` |
| — | `Actions\Trash\EmptyTrashAction` |
| `Actions\UploadFileAction` | `Actions\Files\IngestFileAction` |
| — | `Actions\Files\RestoreFileRevisionAction` |
| `Actions\Uploads\CommitUploadAction` | `Actions\Uploads\CompleteUploadAction` |
| — | `Actions\Uploads\InitiateUploadAction`, `Actions\Uploads\AbortUploadAction` |
| — | `Actions\Drives\ArchiveDriveAction`, `Actions\Drives\DeleteDriveAction` |
| `Data\CreateDriveFolderData` | `Data\Nodes\CreateFolderInput` |
| `Data\RenameNodeData` | `Data\Nodes\RenameNodeInput` |
| `Data\MoveNodeData` | `Data\Nodes\MoveNodeInput` |
| `Data\CopyNodeData` | `Data\Nodes\CopyNodeInput` |
| `Data\UploadFileData` | `Data\Files\IngestFileInput` |
| `Data\Uploads\UploadInitData` | `Data\Uploads\InitiateUploadInput` |
| `Events\StorageCreated` | `Events\Drives\DriveCreated` |
| `Events\NodeCreated` | `Events\Nodes\FolderCreated` |
| `Events\NodeRenamed/NodeMoved/NodeCopied/NodeTrashed/NodeRestored` | `Events\Nodes\*` (имена те же) |
| `Events\NodeForceDeleted` | `Events\Nodes\NodePurged` |
| `Events\FileUploaded` | `Events\Files\FileUploaded` |
| `Events\NodeVersionCommitted` | `Events\Files\FileRevisionAdded` |
| `Events\UploadSessionInitiated/Aborted/Expired` | `Events\Uploads\UploadInitiated/UploadAborted/UploadExpired` |
| `Exceptions\CrossStorageMoveException` | `Exceptions\Nodes\CrossDriveOperationException` |
| `Exceptions\CycleException` | `Exceptions\Nodes\NodeCycleException` |
| `Exceptions\QuotaExceededException` | `Exceptions\Quota\QuotaExceededException` |
| `Exceptions\{OffsetMismatch,StagedUploadMissing,UploadConflict,UploadContentMismatch}Exception` | `Exceptions\Uploads\*` (имена те же) |
| `Http\Controllers\NodeController` | разбит, см. [10-http-api-v1.md](10-http-api-v1.md) §4 |
| `Http\Controllers\StorageController` | `Http\Controllers\Drives\ShowDriveController` |
| `Http\Controllers\DownloadController` | `Http\Controllers\Files\DownloadFileController`, `Http\Controllers\Revisions\DownloadRevisionController` |
| `Http\Controllers\Uploads\TusUploadController` | `Http\Controllers\Uploads\TusProtocolController` (протокольный, многометодный — исключение из D31) |
| `Http\Controllers\Uploads\S3MultipartController` | `Http\Controllers\Uploads\S3MultipartProtocolController` |
| `Http\Resources\StorageResource` | `Http\Resources\DriveResource` |
| `Http\Resources\FileVersionResource` | `Http\Resources\FileRevisionResource` |
| `Support\Uploads\MagicByteSniffer` | `Uploads\Inspection\MimeSniffer` |
| `Support\Uploads\StagedUpload` | `Uploads\StagedUpload` |
| `Support\Uploads\UploadModels` | — удалить → `Configuration\DriveConfig` |
| `Webhooks\RecordsDriveOutbox`, `Activity\RecordsDriveActivity` | — удалить |
| `Console\SweepUploadsCommand` | `vaulter:uploads:sweep` |
| `Console\PurgeTrashCommand` | `vaulter:trash:purge` |

## 5. Классы: documents (`Vaulter\Documents\`)

| Было | Стало |
|---|---|
| `DocumentsModels` | — удалить → `Configuration\DocumentsConfig` |
| `Models\Versions\NodeDocumentVersion` | `Models\Revisions\DocumentRevision` |
| — | `Models\Documents\Document` |
| `Enums\Documents\DocumentType` | — удалить → `Types\DocumentTypeRegistry` + классы типов |
| `Enums\Export\ExportFormat` | — удалить (формат объявляет экспортёр) |
| `Attributes\TiptapExtensions`, `Support\TipTap\Concerns\HasTiptapExtensions` | — удалить (расширения задаёт тип) |
| `Engines\Handlers\TipTapHandler` | — удалить → `Types\RichTextDocumentType`, `Types\MarkdownDocumentType` |
| `Engines\Handlers\UniverHandler` | — удалить → `Types\SpreadsheetDocumentType` |
| `ValueObjects\TipTapContent` | `Content\TipTap\TipTapContent` |
| `ValueObjects\OpaqueDocumentContent` | `Content\OpaqueContent` |
| `ValueObjects\LinkSchemeValidator` | `Content\TipTap\LinkSchemeValidator` |
| `Support\TipTap\TipTapRenderer` | `Content\TipTap\TipTapRenderer` |
| `Support\DocumentContentHasher` | `Content\ContentHasher` |
| `Actions\Documents\CreateDocumentNodeAction` | `Actions\Documents\CreateDocumentAction` |
| `Actions\Versions\CreateNamedVersionAction` | `Actions\Revisions\CreateNamedRevisionAction` |
| `Actions\Versions\RestoreDocumentVersionAction` | `Actions\Revisions\RestoreDocumentRevisionAction` |
| `Actions\Versions\ReleaseKeptVersionAction` | `Actions\Revisions\UpdateRevisionRetentionAction` |
| `Actions\Collaboration\PersistYjsSnapshotAction` | `Actions\Collaboration\PersistCollaborationStateAction` |
| `Actions\Comments\AddNodeCommentAction` | `Actions\Comments\AddCommentAction` |
| `Actions\Comments\ResolveNodeCommentAction` | `Actions\Comments\ResolveCommentAction` |
| `Actions\Comments\DeleteNodeCommentAction` | `Actions\Comments\DeleteCommentAction` |
| `Data\Documents\CreateDocumentNodeData` | `Data\Documents\CreateDocumentInput` |
| `Data\Documents\UpdateDocumentContentData` | `Data\Documents\UpdateDocumentContentInput` |
| `Data\Versions\CreateNamedVersionData` | `Data\Revisions\CreateNamedRevisionInput` |
| `Data\Comments\CreateNodeCommentData` | `Data\Comments\AddCommentInput` |
| `Data\Collaboration\PersistYjsSnapshotData` | `Data\Collaboration\PersistCollaborationStateInput` |
| `Data\Collaboration\CollaborationSessionData` | `Data\Collaboration\CollaborationSession` |
| `Events\Documents\DocumentNodeCreated` | `Events\Documents\DocumentCreated` |
| `Events\Versions\DocumentVersionCreated` | `Events\Revisions\DocumentRevisionCreated` |
| — | `Events\Revisions\DocumentRestored` |
| `Events\Comments\NodeComment{Added,Resolved,Deleted}` | `Events\Comments\Comment{Added,Resolved,Deleted}` |
| `Exceptions\NodeDocumentVersionNotFoundException` | `Exceptions\DocumentRevisionNotFoundException` |
| `Exceptions\NotADocumentNodeException` | `Exceptions\NotADocumentException` |
| `Exceptions\InvalidTipTapContentException` | `Exceptions\InvalidDocumentContentException` |
| — | `Exceptions\DocumentTypeUnavailableException`, `Exceptions\DuplicateDocumentTypeException` |
| `Contracts\Collaboration\NodeCollaborationDriver` | `Contracts\CollaborationDriver` |
| `Collaboration\NodeCollaborationDriverManager` | `Collaboration\CollaborationDriverManager` |
| `Collaboration\HocuspocusNodeCollaborationDriver` | `Collaboration\HocuspocusCollaborationDriver` |
| `Collaboration\ReverbNodeCollaborationDriver` | — удалить (deprecated для Yjs в 0.2; см. 15-owner-questions Q7) |
| `Export\NodeExportService` | `Export\DocumentExporterRegistry` + `Actions\Export\ExportDocumentAction` |
| `Http\Controllers\DocumentNodeController` | разбит, см. 10 §4 |

## 6. Методы фасада и handle

| Было | Стало |
|---|---|
| `Vaulter::for(Model $entity, ?string $panel)` | `Vaulter::drive(Model $owner, ?string $profile = null): DriveHandle` |
| `Vaulter::forStorage(Storage $storage)` | `Vaulter::drive(Drive $drive): DriveHandle` (тот же метод) |
| `Vaulter::panel($name)`, `panels()` | `Vaulter::profiles()->get($key)`, `Vaulter::profiles()` |
| `Vaulter::registerPanel($p)` | `Vaulter::profiles()->register($p)` |
| `Vaulter::registerHandler($h)` | `Vaulter::handlers()->register($h)` |
| `Vaulter::registerDocumentType()`, `documentTypes()` | `Documents::types()->register()` (фасад documents) |
| `Vaulter::registerExporter()`, `exporter()`, `exporterFormats()` | `Documents::exporters()->register()/for()/formats()` |
| `$handle->storage()` | `$handle->model()` |
| `$handle->panel()` | `$handle->profile()` |
| `$handle->createFolder($name, $parent, $actorId)` | `$handle->folders()->create($name, parent: ..., options: ...)` |
| `$handle->upload($source, $into, $name, $mime, $originalName, $actorId)` | `$handle->files()->upload(UploadSource, ?UploadOptions)` |
| `$handle->uploadVersion($target, $source, ...)` | `$handle->files()->addRevision($file, UploadSource, ?RevisionOptions)` |
| `$handle->move($node, $into, $actorId)` | `$handle->nodes()->move($node, destination: ..., options: ...)` |
| `$handle->rename($node, $name, $actorId)` | `$handle->nodes()->rename($node, $name, expectedSequence: ...)` |
| `$handle->copy($node, $into, $name, $actorId)` | `$handle->nodes()->copy($node, destination: ..., options: ...)` |
| `$handle->trash($node, $actorId)` | `$handle->nodes()->trash($node)` |
| `$handle->restore($node, $actorId)` | `$handle->trash()->restore($node, ?RestoreOptions)` |
| `$handle->forceDelete($node, $actorId)` | `$handle->trash()->purge($node)` |
| `$handle->list($folder)` | `$handle->nodes()->children($folder, ?ListOptions): NodePage` |
| `FileHandler::id()` | `FileHandler::key()` (формат `vendor/name`) |
| `FileHandler::ui(FileDescriptor): HandlerUi` | `FileHandler::viewer(FileDescriptor): ViewerDescriptor` |
| `NodeHandlerResolver::describe(Node, ?string)` | `NodePresenter::present(Node): NodePresentation` |
| `DocumentHandlerRegistry::resolve()` (только в реализации) | `FileHandlerRegistry::resolve()` (в контракте) |
| `AccessResolver::canCreateInWorkspace(actor, storage)` | `AccessResolver::canCreateIn(actor, Drive)` |
| `HostRoleDriver::canCreateInWorkspace(actor, type, id)` | `PermissionDriver::canCreateIn(actor, AccessScope)` |
| `BlobStore::commit(stream, ?int tenantId, ?int storageId, ?disk)` | `BlobStore::commit(stream, BlobScope $scope, ?string $disk = null)` |
| `BlobStore::attach(FileVersion, Blob, ...)` | `BlobStore::attach(FileRevision, Blob, ...)` |

## 7. Таблицы и колонки

| Было | Стало |
|---|---|
| `v_storages` | `v_drives` |
| `*.storage_id` (nodes, blobs, activity, upload_sessions, …) | `*.drive_id` |
| `v_storages.panel` | `v_drives.profile` |
| `v_storages.storage_type`, `slug`, `default_node_permissions`, `deleted_at` | — удалить |
| `v_nodes.current_version_id`, `v_nodes.file_id`, `v_nodes.deleted_at` | — удалить (D16, D27) |
| — | `v_nodes.sequence` (unsigned int, default 0) |
| `v_files.current_version_id`, `version_count`, `deleted_at` | `current_revision_id`, `revision_count`; `deleted_at` удалить |
| `v_file_versions` | `v_file_revisions` |
| `v_file_versions.version_no` | `v_file_revisions.number` |
| — | `v_file_revisions.declared_mime_type`, `scan_status` |
| `v_file_version_blobs` (`file_version_id`) | `v_file_revision_blobs` (`file_revision_id`) |
| `v_blobs.storage_id` | `v_blobs.drive_id` |
| — | `v_blobs.scope_key` (NOT NULL), `UNIQUE(scope_key, sha256)` |
| `v_node_document_versions` | `v_document_revisions` |
| `v_node_document_versions.node_id` | `v_document_revisions.document_id` |
| `…version_no`, `snapshot_label`, `format` | `number`, `label`, — (тип в `v_documents`; добавлена `schema_version`) |
| — | `v_documents` (новая) |
| `v_node_grants.deleted_at`, `v_node_shares.deleted_at` | — удалить |
| `v_outbox` | + `aggregate_type`, `aggregate_id`, `sequence`, `drive_id` |
| `v_upload_sessions.node_id` | `target_node_id`; + `result_node_id`, `result_revision_id` |
| `v_nodes.owner_id` (пользователь-владелец узла) | `v_nodes.owned_by` |
| `v_node_grants.inherited`, `inherited_from_node_id` | — удалить (не используются) |

Типы колонок-ключей хоста — D25. Полная upgrade-миграция — [08-data-model-and-migration.md](08-data-model-and-migration.md).

## 8. События (типы)

| Было (`WebhookEvent`/`ActivityVerb`) | Стало (`EventType`) |
|---|---|
| `storage.created` | `drive.created` |
| `node.created` (папка) | `folder.created` |
| `file.uploaded` | `file.uploaded` |
| `version.created` | `file.revision_added` |
| `node.moved` / `node.renamed` / `node.copied` / `node.trashed` / `node.restored` | без изменений |
| `node.force_deleted` | `node.purged` |
| `grant.created` / `grant.revoked` / `share.created` / `share.revoked` | без изменений |
| `upload_session.initiated/aborted/expired` | `upload.initiated/aborted/expired` |
| — | `document.created`, `document.updated`, `document.revision_created`, `document.restored` |
| — | `comment.added`, `comment.resolved`, `comment.deleted` |
| — | `drive.archived`, `drive.deleted`, `node.updated` |

## 9. TypeScript (`@vaulter/vue`)

| Было | Стало |
|---|---|
| `Storage`, `StorageId` | `Drive`, `DriveId` |
| `FileVersion` | `FileRevision` |
| `DocumentVersion`, `SpreadsheetVersion` | `DocumentRevision` (контент типизируется по `document_type`) |
| `DocumentFormatName` | `DocumentTypeKey` (`string`) |
| `GrantLevelName = 'reader'\|'commenter'\|'editor'\|'manager'` | `'reader'\|'commenter'\|'writer'\|'organizer'\|'owner'` — сейчас TS расходится с сервером |
| `HandlerDescriptor`, `HandlerUi`, `HandlerCapabilities` | `NodePresentation`, `ViewerDescriptor`, `ContentCapabilities` (генерируются) |
| `docVaultClientKey` | `vaulterClientKey` |
| `registerEngineComponent()` и др. модульные функции | опция плагина `viewers` + `useViewerRegistry()` |
| `client.nodes.forceDelete` | `client.nodes.purge` |
| `client.documents.versions/createNamedVersion/restoreVersion` | `client.documents.revisions/snapshot/restore` |
| — (версии файлов) | `client.files.revisions/addRevision/downloadRevision/restoreRevision` |
| `client.documents.handler` | удалить (presentation приходит в `Node`) |
