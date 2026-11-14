# ADR-0014 Step 2 — Excise legacy DocVault from packages/core

**Branch:** `feat/excise-legacy-docvault-adr-0014-step-2` (off `feat/node-comments-adr-0014-step-1`)
**Shape:** ONE excision commit. Rollback = VCS revert (no data). Gate: pint + phpstan max + pest + type-cov 100%.
**Seam:** phpstan max — after deletes, every dangling reference to a removed class/model surfaces there.

## Acceptance
- `grep -r 'Models\Documents\Document' packages/core/src` → only `NodeDocumentVersion` / `NodeComment` survive.
- One canonical document path in core (v_nodes, kind=document).
- CHANGELOG `[Unreleased]` breaking note (pre-1.0).
- Arch tests (split-roots, controller-no-static-query, canonical-domain spot-checks) stay green.
- Core stays azguard-free.

## DELETE (legacy, document_id-bound)

### Models — src/Models/Documents/
Document, DocumentFolder, DocumentVersion, DocumentPermission, DocumentComment, DocumentShare
KEEP: NodeComment, NodeDocumentVersion

### Migrations — packages/core/database/migrations/
000001..000006 (v_documents, v_document_folders, v_document_versions, v_document_permissions,
v_document_comments, v_document_shares). ADD `...000023_drop_legacy_docvault_tables` (idempotent
dropIfExists, driver-aware, reverse-FK order) for dev DBs that already ran create.

### Repositories
- Repositories/Documents/: DocumentReadRepository, DocumentStoreRepository, FolderReadRepository,
  FolderStoreRepository, VersionReadRepository, VersionStoreRepository, CommentReadRepository,
  CommentStoreRepository  (KEEP NodeComment*, NodeDocumentVersion*)
- Repositories/Access/: PermissionReadRepository, PermissionStoreRepository, DocumentVisibilityService
  (KEEP GrantReadRepository, GrantStoreRepository)
- Repositories/Sharing/: ShareReadRepository, ShareStoreRepository (KEEP NodeShare*)

### Controllers — src/Http/Controllers/
DocumentController, FolderController, VersionController, PermissionController, CommentController,
ShareController (flat, document-bound — NOT Sharing/ShareController), CollaborationController,
BulkDocumentController, ExportController (confirmed document-bound: injects DocumentReadRepository +
DocumentVisibilityService). KEEP: Controller (base), Drive/*, Sharing/*, Uploads/*, Webhooks/*, Activity/*

### Actions — src/Actions/ (flat legacy)
AddCommentAction, ResolveCommentAction, DeleteCommentAction, CreateDocumentAction,
UpdateDocumentAction, MoveDocumentAction, DeleteDocumentAction, RestoreDocumentAction,
ForceDeleteDocumentAction, DuplicateDocumentAction, CreateFolderAction, DeleteFolderAction,
MoveFolderAction, RenameFolderAction, CreateVersionAction, RestoreVersionAction,
GrantPermissionAction, RevokePermissionAction, CreateShareLinkAction, RevokeShareLinkAction,
SyncCollaborationStateAction, IssueCollaborationTokenAction (legacy: uses manager->driver() +
legacy Document)
KEEP: Actions/Documents/* (node), Actions/Drive/*, Actions/Access/*, Actions/Sharing/* (node
CreateShareLinkAction/RevokeShareLinkAction), Actions/Uploads/*, Actions/Webhooks/*

### Events — src/Events/Documents/
CommentAdded, CommentResolved, DocumentContentSynced, DocumentCreated, DocumentDeleted,
DocumentMoved, DocumentPurged, DocumentRestored, DocumentUpdated, VersionCreated, VersionRestored
KEEP (node, legacy-like names): DocumentContentUpdated, DocumentVersionCreated, DocumentNodeCreated,
NodeCommentAdded, NodeCommentResolved, NodeCommentDeleted
- Events/Access/: PermissionGranted, PermissionRevoked (legacy) — KEEP NodeGrantCreated/Revoked
- Events/Sharing/: ShareLinkCreated, ShareLinkRevoked (legacy) — KEEP NodeShareCreated/Revoked

### Resources — src/Http/Resources/
DocumentResource, DocumentCommentResource, DocumentFolderResource, DocumentVersionResource,
DocumentPermissionResource, DocumentShareResource. KEEP Drive/*, Sharing/*, Activity/*, Uploads/*, Webhooks/*

### Requests — src/Http/Requests/
BulkDocumentRequest, ExportDocumentRequest, MoveDocumentRequest, StoreCommentRequest,
StoreDocumentRequest, StoreFolderRequest, StorePermissionRequest, StoreShareRequest,
StoreVersionRequest, SyncCollaborationRequest, UpdateDocumentRequest
KEEP: Drive/*, StoreNodeCommentRequest, Sharing/*, Uploads/*, Webhooks/*, VaulterFormRequest

### Data — src/Data/
CreateCommentData, CreateDocumentData, CreateFolderData, CreateShareData, CreateVersionData,
GrantPermissionData, UpdateDocumentData
KEEP: node DTOs (CopyNodeData, CreateDocumentNodeData, CreateDriveFolderData, CreateNamedVersionData,
CreateNodeCommentData, MoveNodeData, PersistYjsSnapshotData, RenameNodeData, UpdateDocumentContentData,
UploadFileData, CollaborationSession, Data/Access/*, Data/Sharing/*, Data/Activity/*, Data/Uploads/*, Data/Webhooks/*)

### Access abilities & permission contract
Access/Abilities/DocumentAbilities, Access/Abilities/AbilitiesDto (only used by DocumentAbilities;
NodeAbilities merely references it in a comment), Contracts/Access/PermissionResolver
KEEP: Access/Abilities/NodeAbilities (update its comment), AccessResolver, HostRoleDriver*, etc.

### Collaboration (legacy Document-bound)
Contracts/Collaboration/CollaborationDriver, Collaboration/ReverbCollaborationDriver,
Collaboration/HocuspocusCollaborationDriver
KEEP: NodeCollaborationDriver + Reverb/HocuspocusNodeCollaborationDriver + NodeCollaborationDriverManager,
CollaborationCall*, Contracts/Collaboration/{NodeCollaborationDriver,HostUserResolver}

### Exceptions
Exceptions/Documents/DocumentNotFoundException (legacy-only: DocumentReadRepository + DocumentController)
KEEP all other Exceptions/Documents/*

### Tests — packages/core/tests/Feature/ (legacy, 13)
AbilitiesTest, ActionsTest, BulkApiTest, CollaborationDriverTest, CollaborationHocuspocusTest,
CollaborationSyncTest, CommentApiTest, DocumentApiTest, DocumentOperationsTest, ExportApiTest,
SearchTrashTest, ShareApiTest, ShareTokenTest
- Fixtures: tests/Fixtures/{AllowResolver,DenyResolver,ViewOnlyResolver,NoCreateResolver} (impl legacy contract)
KEEP node tests: DriveApiTest, DriveAuthorizationTest, NodeShareApiTest, NodeCommentFeatureOffTest,
ActivityApiTest, ResumableUploadApiTest, PruneWebhooksTest, PurgeTrashTest (Node), ServiceProviderTest (EDIT)

## RETARGET / EDIT (shared surfaces — verified by phpstan seam)
- config/vaulter.php: drop models.{document,version,folder,permission,comment,share} +
  table_names.{documents,document_folders,document_versions,document_permissions,document_comments,
  document_shares} + permission_resolver block + top-of-file legacy `use` imports.
- Support/Config.php: drop documentModel()/versionModel()/folderModel()/permissionModel()/commentModel()/
  shareModel()/permissionResolverClass() + their table accessors (whatever exists). KEEP exporters(), nodeModel(), etc.
- VaulterManager: drop `driver()` (CollaborationDriver) + import; drop resolvePermissionsWith()/
  permissionResolver()/$permissionResolver field + PermissionResolver import. KEEP exporter(), handlers, panels.
- VaulterServiceProvider: drop PermissionResolver bind (resolverClass) + CollaborationDriver bind;
  registerBroadcastChannel → node-only (drop authorizeDocumentRoom + legacy fallback).
- Facades/Vaulter.php: drop @method resolvePermissionsWith/permissionResolver/driver docblock lines.
- routes/api.php: drop the legacy Documents/Versions/Folders/Permissions/Comments/Share/Bulk/Collab/Export
  block + their `use` imports. KEEP node routes.
- tests/Arch/ArchTest.php: retarget canonical spot-checks — `Models\Documents\Document` →
  `Models\Documents\NodeDocumentVersion`; `Events\Sharing\ShareLinkCreated` → `Events\Sharing\NodeShareCreated`.
- tests/Feature/ServiceProviderTest.php: drop the `Vaulter::permissionResolver()->toBeNull()` assertion.

## Trap notes
- Prefix collision Document*: keep DocumentContentUpdated/DocumentVersionCreated/DocumentNodeCreated
  (node events), Actions/Documents/*, Http/Controllers/Drive/DocumentNodeController, DocumentType enum
  (shared: node CreateDocumentNodeRequest + TipTapHandler), DocumentExporter/DocumentContent/DocumentHandler
  contracts (node export/handler path), Documents/* services (NodeExportService/DocumentHandlerRegistry/
  DocumentFileDescriptorFactory/CollaborationSessionService/NodeHandlerResolver/Handlers/*), ValueObjects/Documents/*.
- Flat Actions/CreateShareLinkAction (legacy) vs Actions/Sharing/CreateShareLinkAction (node) — same for Revoke.
- Http/Controllers/ShareController (legacy) vs Http/Controllers/Sharing/ShareController (node).
