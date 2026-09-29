# 04 — Пакеты, границы и раскладка директорий

Основание — [D02](02-decisions.md#d02), [D05](02-decisions.md#d05), [D08](02-decisions.md#d08),
ADR-0017 (layer-first + domain sub-namespace остаётся каноном).

## 1. Пакеты и зависимости

| Пакет | Composer / npm | Namespace | `require` (Vaulter) | Назначение |
|---|---|---|---|---|
| core | `axioma-studio/vaulter-core` | `Vaulter\` | — | Kernel: схема, общие модели, доступ, шаринг, activity, события/webhooks, профили, файловые обработчики, контракты, конфиг ядра |
| media | `axioma-studio/vaulter-media` | `Vaulter\Media\` | core | CAS: staging, sha256, promote, GC, reconcile, repair scopes |
| drive | `axioma-studio/vaulter-drive` | `Vaulter\Drive\` | core, media | Drive handle, операции дерева и файлов, загрузки (single/tus/S3), скачивание, корзина, HTTP Drive |
| documents | `axioma-studio/vaulter-documents` | `Vaulter\Documents\` | core, **drive** | Типы документов, ревизии, комментарии, экспорт, коллаборация, HTTP Documents |
| filament | `axioma-studio/vaulter-filament` | `Vaulter\Filament\` | core, **drive** | Админ-панель поверх публичного API |
| azgard | `axioma-studio/vaulter-azgard` | `Vaulter\Azgard\` | core | Драйвер прав AzGuard + адаптер guard |
| metapackage | `axioma-studio/vaulter` (новый) | — | core, media, drive, documents | Установка одной командой |
| vue | `@vaulter/vue` | — | API v1 | Клиент, composables, компоненты, реестр viewer'ов |
| hub | `hocuspocus-server` (Docker/npm-приложение) | — | API v1 hub-эндпоинты | Yjs-хаб |

Граф (разрешённые направления, проверяется Deptrac/Pest arch):

```
            ┌────────────┐
            │    core    │◄─────────────┬──────────────┐
            └─────▲──────┘              │              │
                  │                     │              │
            ┌─────┴──────┐        ┌─────┴─────┐  ┌─────┴─────┐
            │   media    │        │  azgard   │  │ (host app)│
            └─────▲──────┘        └───────────┘  └───────────┘
                  │
            ┌─────┴──────┐
            │   drive    │◄──────────┐
            └─────▲──────┘           │
                  │                  │
            ┌─────┴──────┐     ┌─────┴─────┐
            │ documents  │     │ filament  │
            └────────────┘     └───────────┘
```

Правила (arch-тесты в каждом пакете):

1. Пакет импортирует только namespace пакетов, от которых зависит в `composer.json`.
2. core не содержит слов `Azgard`, `TipTap`, `Univer`, `Hocuspocus`, `Filament` в `src` (кроме
   строковых ключей типов событий/конфигов, перечисленных в allow-list теста).
3. Никто вне пакета не импортирует его `Repositories\*`, `Actions\*`, `Queries\*`, `Support\*` —
   только `Contracts\*`, `Operations`-через-handle, `Models\*`, `Data\*`, `ValueObjects\*`, `Enums\*`,
   `Events\*`, `Exceptions\*`, `Facades\*`.
4. `Http\*` и Filament не импортируют `Actions\*`, `Repositories\*` (D08).
5. `src` никогда не импортирует `Testing\*` (D35).
6. Фасад `DB` и `config()` используются только в `Database\VaulterDatabase` и `Configuration\*` (D25, D26).

## 2. Владение (ADR-0019 «Kernel ownership», создать)

| Артефакт | Владелец | Правило |
|---|---|---|
| Все миграции и `SchemaBuilder` | core | Одна история миграций; таблицы создаются независимо от установленных модулей |
| Модели `Drive`, `Node`, `File`, `FileRevision`, `FileRevisionBlob`, `Blob`, `NodeGrant`, `NodeShare`, `Activity`, `Outbox`, `WebhookEndpoint`, `WebhookDelivery` | core | Используются kernel-сервисами или несколькими модулями |
| `UploadSession`, `UploadPart` | drive | Читает и пишет только drive |
| `Document`, `DocumentRevision`, `NodeComment` | documents | Читает и пишет только documents |
| Конфиг | пакет-владелец ключа | Ключи, влияющие на схему — в `vaulter.php` (core) |
| HTTP-маршруты | модуль операции | Префикс, версия и стеки middleware — из core |
| Контракты швов, которые вызывает core | core | `DriveHandle`, `*Operations` drive-уровня, `NodeContentLifecycle`, `BlobStore`, `FileHandler` |
| Контракты, которые вызывает только модуль | модуль | `DocumentType`, `DocumentExporter`, `CollaborationDriver`, `UploadProtocolHandler` |
| HTTP ядра (grants, shares, activity, публичные ссылки) | core | Работает через `Vaulter::drive()`; core регистрирует эти маршруты, только если связан `DriveHandleFactory` (установлен vaulter-drive). Webhook-endpoints — всегда |

## 3. Раскладка: core

```text
packages/core/
├── config/vaulter.php
├── database/
│   ├── factories/                     # DriveFactory, NodeFactory, FileFactory, NodeGrantFactory (D35)
│   └── migrations/                    # + 2026_10_xx_000001_vaulter_0_3_canon.php
├── resources/lang/
├── routes/
│   ├── v1-authenticated.php           # grants, shares (owner-side), activity, webhook endpoints
│   ├── v1-public.php                  # shares/{token}*
│   └── (hub-маршрутов в core нет)
└── src/
    ├── VaulterManager.php
    ├── VaulterServiceProvider.php     # тонкий: вызывает Registrars
    ├── Facades/Vaulter.php
    ├── Concerns/HasDrives.php         # trait для моделей хоста (D27)
    ├── Access/
    │   ├── AccessResolver.php         # + evaluateMany(), canCreateIn()
    │   ├── OperationGuard.php
    │   ├── PermissionDriverManager.php
    │   ├── Drivers/{Database,Gate,Spatie}PermissionDriver.php
    │   └── ValueObjects/{AccessScope,Decision,GrantSet,Subject,AccessAce,AccessExplanation,NodeAbilities,Visibility}.php
    ├── Actions/
    │   ├── Access/{GrantNodeAccessAction,RevokeNodeAccessAction}.php
    │   ├── Sharing/{CreateShareLinkAction,RevokeShareLinkAction}.php
    │   └── Webhooks/{Register,Update,Deactivate,Delete}WebhookEndpointAction.php
    ├── Cas/BlobLock.php
    ├── Configuration/
    │   ├── VaulterConfig.php          # корень readonly-конфига ядра
    │   ├── {Database,Ids,Tenancy,Access,Routes,Sharing,Webhooks,Downloads,Observability,Listing,Schedule}Config.php
    │   ├── ConfigNormalizer.php       # legacy-ключи → канон + deprecation
    │   └── ConfigValidator.php
    ├── Console/{Install,Doctor,MakeProfile,MakeFileHandler,MakePermissionDriver,RelayWebhooks,RetryWebhooks,PruneWebhooks}Command.php
    ├── Contracts/
    │   ├── Access/{PermissionDriver,GrantAuthoritativeDriver,EnumeratesRoleSubjects,AccessOperations}.php
    │   ├── Activity/ActivityOperations.php
    │   ├── Cas/{BlobStore,ContentStore,StorageDriver,StagedObject,ListableContentStore,ListableStorageDriver,ContentScanner}.php
    │   ├── Doctor/DoctorCheck.php
    │   ├── Drive/{DriveHandle,DriveHandleFactory,NodeOperations,FolderOperations,FileOperations,TrashOperations,NodeContentLifecycle}.php
    │   ├── Events/EventRecorder.php
    │   ├── Handlers/{FileHandler,FileHandlerRegistry,NodePresenter}.php
    │   ├── Observability/{MetricsRecorder,TraceRecorder}.php
    │   ├── Sharing/{ShareTokenGenerator,ShareOperations}.php
    │   ├── Tenancy/{TenantResolver,ResolvesOwnerTenant}.php
    │   ├── Webhooks/{HostResolver,OutboundUrlGuard}.php
    │   └── IdStrategy.php
    ├── Data/
    │   ├── Access/{GrantInput,GrantNodeAccessInput}.php          # публичный вход / внутренний вход Action
    │   ├── Drive/{UploadSource,UploadOptions,RevisionOptions,CreateFolderOptions,MoveOptions,CopyOptions,RestoreOptions,ListOptions,SearchOptions,UpdateNodeOptions,NodePage,FileStream,CopyResult}.php
    │   ├── Sharing/{ShareLinkInput,MintedShareLink}.php
    │   └── Webhooks/…
    ├── Database/VaulterDatabase.php
    ├── Doctor/{Doctor,DoctorReport}.php + Checks/*Check.php
    ├── Enums/
    │   ├── Access/{Ability,Effect,GrantLevel,GrantScope,Permission}.php
    │   ├── Cas/{BlobStatus,DedupScope}.php
    │   ├── Drive/{NodeKind,OnNameConflict,NodeSort,DriveStatus,ScanStatus}.php
    │   ├── Events/EventType.php
    │   └── Handlers/Capability.php
    ├── Events/
    │   ├── DomainEvent.php
    │   ├── EventRecorder.php          # реализация контракта
    │   ├── Access/{NodeGrantCreated,NodeGrantRevoked}.php
    │   └── Sharing/{NodeShareCreated,NodeShareRevoked}.php
    ├── Exceptions/
    │   ├── VaulterException.php
    │   ├── Contracts/ProblemException.php
    │   ├── Access/{PermissionDenied,MissingActor,MissingPermissionDriver,UnenumerableGrantSubject}Exception.php
    │   ├── Nodes/{NodeNotFound,NodeNameConflict,StaleNode,UnsupportedNodeKind,InvalidDestination}Exception.php
    │   ├── Content/ContentUnavailableException.php
    │   ├── Profiles/{InvalidProfile,UnknownProfile,ProfileRestriction}Exception.php
    │   ├── Configuration/InvalidConfigurationException.php
    │   ├── Tenancy/TenancyMisconfiguredException.php
    │   ├── Schema/UnknownIdStrategyException.php
    │   ├── Support/MissingPackageException.php
    │   └── Webhooks/{BlockedOutboundUrl,WebhookResponseTooLarge}Exception.php
    ├── Handlers/
    │   ├── FileHandlerRegistry.php
    │   ├── NodePresenter.php
    │   ├── FileDescriptorFactory.php
    │   ├── AbstractFileHandler.php
    │   └── Builtin/{PreviewHandler,DownloadOnlyHandler}.php
    ├── Http/
    │   ├── Controllers/{Access,Sharing,Activity,Webhooks}/<Verb><Noun>Controller.php
    │   ├── Middleware/{RecordVaulterRequestMetrics,ResolveShareToken,AssignCorrelationId}.php
    │   ├── Problems/ProblemRenderer.php
    │   ├── Requests/{Access,Sharing,Webhooks}/*Request.php
    │   ├── Resources/{Drive,Access,Sharing,Activity,Webhooks}/*Resource.php
    │   └── Support/{HttpResourceResolver,HttpOperationContextFactory,DownloadHeaders,ETag}.php
    ├── Jobs/Webhooks/DeliverWebhook.php
    ├── Models/{Drive,Access,Sharing,Activity,Cas,Webhooks}/… + Concerns/{HasExtraData,HasVaulterKeys,ResolvesVaulterConnection}.php
    ├── Operations/
    │   ├── OperationContext.php
    │   ├── Access/AccessOperations.php
    │   ├── Sharing/ShareOperations.php
    │   └── Activity/ActivityOperations.php
    ├── Policies/{NodePolicy,WebhookEndpointPolicy}.php
    ├── Profiles/{Profile,ProfileProvider,DefaultProfile,ProfileRegistry,UploadPolicy}.php
    ├── Providers/                     # registrars, вызываемые из VaulterServiceProvider
    │   └── {Bindings,Access,Events,Http,Scheduling,Doctor}Registrar.php
    ├── Queries/Activity/…
    ├── Repositories/{Access,Activity,Cas,Drive,Sharing,Webhooks}/…
    ├── Schema/{SchemaBuilder,VaulterSchema,IdStrategyResolver,AutoIncrementIds,UlidIds,Uuid7Ids,HostKeyColumns}.php
    ├── Sharing/{ShareContext,ShareResolution,ShareResolver,ShareResult}.php
    ├── Support/…                      # только внутренние помощники без доменного смысла
    ├── Testing/{InteractsWithVaulter,NullPermissionDriver,FakeMetricsRecorder,FakeTraceRecorder}.php + Contracts/*ContractTests.php
    ├── ValueObjects/
    │   ├── Access/{Actor,ActorRef,OwnerRef}.php
    │   ├── Cas/BlobScope.php
    │   ├── Handlers/{FileDescriptor,ContentCapabilities,ViewerDescriptor,NodePresentation}.php
    │   └── Webhooks/PinnedOutboundEndpoint.php
    └── Webhooks/{OutboxRecorder,OutboxRelay,DefaultOutboundUrlGuard,SigningSecretEncryptor,SystemHostResolver,WebhookRetryDispatcher,WebhookSigner,ResponseSizeLimit}.php
```

Примечания:

- `Activity/ActivityRecorder` переезжает в `Events/` как внутренний писатель (`Events\Writers\ActivityWriter`),
  `Webhooks/OutboxRecorder` — остаётся, но вызывается только из `EventRecorder`.
- `Data/Access/GrantNodeAccessData` → `Data/Access/GrantNodeAccessInput`; прочие `*Data` в core — аналогично
  (`CreateShareLinkData` → `ShareLinkInput`, `RegisterWebhookEndpointData` → `RegisterWebhookEndpointInput`).
- `Observability/*` остаётся без изменений.

## 4. Раскладка: drive

```text
packages/drive/
├── config/vaulter-drive.php
├── routes/{v1-authenticated.php}
└── src/
    ├── VaulterDriveServiceProvider.php
    ├── DriveHandle.php                # impl Contracts\Drive\DriveHandle
    ├── DriveHandleFactory.php
    ├── DriveProvisioner.php
    ├── Actions/
    │   ├── Drives/{ArchiveDriveAction,DeleteDriveAction}.php
    │   ├── Nodes/{CreateFolderAction,RenameNodeAction,MoveNodeAction,CopyNodeAction,UpdateNodeAction}.php
    │   ├── Trash/{TrashNodeAction,RestoreNodeAction,PurgeNodeAction,EmptyTrashAction}.php
    │   ├── Files/{IngestFileAction,RestoreFileRevisionAction}.php
    │   └── Uploads/{InitiateUploadAction,CompleteUploadAction,AbortUploadAction}.php
    ├── Configuration/DriveConfig.php
    ├── Console/{SweepUploadsCommand,PurgeTrashCommand,PurgeDrivesCommand}.php
    ├── Content/FileContentLifecycle.php
    ├── Contracts/Uploads/{UploadProtocolHandler,UploadInspector}.php
    ├── Data/
    │   ├── Nodes/{CreateFolderInput,RenameNodeInput,MoveNodeInput,CopyNodeInput,UpdateNodeInput}.php
    │   ├── Trash/{TrashNodeInput,RestoreNodeInput,PurgeNodeInput}.php
    │   ├── Files/{IngestFileInput,UploadFacts}.php
    │   └── Uploads/{InitiateUploadInput}.php
    ├── Doctor/Checks/{UploadTempDirCheck,TusConfigCheck}.php
    ├── Enums/Uploads/{UploadProtocol,UploadStatus}.php
    ├── Events/{Drives,Nodes,Files,Uploads}/…
    ├── Exceptions/{Nodes,Quota,Uploads,Files}/…
    ├── Http/
    │   ├── Controllers/{Drives,Nodes,Folders,Files,Revisions,Trash,Search,Shared,Uploads}/…Controller.php
    │   ├── Requests/{Nodes,Folders,Files,Revisions,Trash,Uploads}/…Request.php
    │   └── Resources/{DriveResource,FileRevisionResource,Uploads/UploadSessionResource}.php
    ├── Models/Uploads/{UploadSession,UploadPart}.php
    ├── Operations/{NodeOperations,FolderOperations,FileOperations,TrashOperations,UploadOperations}.php
    ├── Queries/{ListChildrenQuery,SearchNodesQuery,ListTrashQuery,SharedWithActorQuery}.php
    ├── Repositories/Uploads/…
    └── Uploads/
        ├── UploadSessionManager.php
        ├── StagedUpload.php
        ├── TusUploadHandler.php
        ├── S3MultipartHandler.php
        └── Inspection/{MimeSniffer,SizeInspector,MimeConsistencyInspector,FilenameInspector,ScanDispatchInspector}.php
```

`UploadOperations` не входит в core-контракт `DriveHandle` (зависит от модели `UploadSession` пакета
drive); доступ — `$drive->extension(UploadOperations::class)` (см. 05 §3).

## 5. Раскладка: documents

```text
packages/documents/
├── config/vaulter-documents.php
├── routes/{v1-authenticated.php,v1-hub.php}
└── src/
    ├── VaulterDocumentsServiceProvider.php
    ├── Facades/Documents.php          # types(), exporters(), in(DriveHandle)
    ├── Actions/
    │   ├── Documents/{CreateDocumentAction,UpdateDocumentContentAction}.php
    │   ├── Revisions/{CreateNamedRevisionAction,RestoreDocumentRevisionAction,UpdateRevisionRetentionAction}.php
    │   ├── Comments/{AddCommentAction,ResolveCommentAction,DeleteCommentAction}.php
    │   ├── Collaboration/{IssueCollaborationTokenAction,PersistCollaborationStateAction}.php
    │   └── Export/ExportDocumentAction.php
    ├── Collaboration/{CollaborationDriverManager,HocuspocusCollaborationDriver,CollaborationCall,CollaborationCallResolver,HostUserResolver}.php
    ├── Configuration/DocumentsConfig.php
    ├── Content/
    │   ├── ContentHasher.php
    │   ├── OpaqueContent.php
    │   ├── Codecs/{TipTapCodec,OpaqueJsonCodec}.php
    │   ├── TipTap/{TipTapContent,TipTapSchema,TipTapRenderer,LinkSchemeValidator}.php
    │   └── DocumentContentLifecycle.php
    ├── Contracts/{DocumentType,DocumentCodec,DocumentContent,SupportsCollaboration,UpgradesSchema,DocumentExporter,CollaborationDriver,HostUserResolver,ExportFilenameResolver}.php
    ├── Data/{Documents,Revisions,Comments,Collaboration}/…
    ├── Doctor/Checks/{CollaborationSecretsCheck,DocumentTypesCheck}.php
    ├── Events/{Documents,Revisions,Comments}/…
    ├── Exceptions/…
    ├── Export/{DocumentExporterRegistry,HtmlExporter,MarkdownExporter,DefaultExportFilenameResolver}.php
    ├── Http/
    │   ├── Controllers/{Documents,Revisions,Comments,Collaboration,Export}/…Controller.php
    │   ├── Middleware/ResolveCollaborationCall.php
    │   ├── Requests/…
    │   └── Resources/{DocumentRevisionResource,NodeCommentResource}.php
    ├── Models/{Documents/Document,Revisions/DocumentRevision,Comments/NodeComment}.php
    ├── Operations/{DocumentOperations,CommentOperations}.php
    ├── Repositories/{Documents,Revisions,Comments}/…
    └── Types/{DocumentTypeRegistry,RichTextDocumentType,MarkdownDocumentType,SpreadsheetDocumentType,UnknownDocumentType}.php
```

## 6. Раскладка: media, azgard, filament

```text
packages/media/src/
├── VaulterMediaServiceProvider.php
├── Configuration/MediaConfig.php
├── Cas/{BlobStore,BlobGarbageCollector,BlobReconciler,FlysystemStorageDriver,Sha256ContentStore,StagedTempObject,ScopeRepairer,ObjectKey}.php
├── Console/{GcBlobsCommand,ReconcileBlobsCommand,RepairBlobScopesCommand}.php
└── Doctor/Checks/{ContentDiskProbeCheck,DedupScopeCheck}.php

packages/azgard/src/
├── VaulterAzgardServiceProvider.php
├── AzgardPermissionDriver.php
├── AzgardPermissionMap.php
├── AzgardGuardAdapter.php
├── NullAzgardGuard.php
├── Contracts/{AzgardGuard,AzgardBackedUser}.php
└── Testing/FakeAzgardGuard.php

packages/filament/src/                  # конвенция Filament (исключение ADR-0017 сохраняется)
├── VaulterPlugin.php
├── VaulterFilamentServiceProvider.php
├── Resources/{Drive,Node,NodeGrant,NodeShare,Activity}Resource/…
└── Support/{PanelActor,DriveHandleFor}.php   # все действия через DriveHandle::actingAs(PanelActor::current())
```

В `filament`: `StorageResource` → `DriveResource`; `PanelAccessScope` удаляется — таблицы берут
видимость из `Queries` через handle (точная видимость D12). «Panel» внутри filament означает
Filament panel и не переименовывается.

## 7. Service providers

Каждый provider — тонкий: `configurePackage()` + вызовы registrar-классов (`Providers/*Registrar`),
каждый из которых тестируется отдельно. Порядок фаз:

| Фаза | Что делает | Где |
|---|---|---|
| `register` | merge конфига пакета, bind контрактов, singleton реестров, регистрация `DoctorCheck`, `NodeContentLifecycle` через tag | все |
| `boot` | нормализация + валидация конфига (исключение для security-ключей), маршруты (через `RouteRegistrar` core), политики, планировщик, публикации, freeze реестров на `app->booted()` | все |

Теги контейнера (единая схема):

| Тег | Что | Кто добавляет |
|---|---|---|
| `vaulter.file_handlers` | `FileHandler` | core (builtin), хост, расширения |
| `vaulter.content_lifecycles` | `NodeContentLifecycle` | drive, documents |
| `vaulter.doctor_checks` | `DoctorCheck` | все пакеты |
| `vaulter.document_types` | `DocumentType` | documents, расширения |
| `vaulter.exporters` | `DocumentExporter` | documents, расширения |
| `vaulter.upload_inspectors` | `UploadInspector` | drive, хост |
