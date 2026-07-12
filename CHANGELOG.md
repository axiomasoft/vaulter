# Changelog

All notable changes to **Vaulter** will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed
- **Re-platformed into an embeddable virtual document storage** (#20). The document/folder
  model gave way to a **Drive** domain — storages and a recursive node tree (folders/files) —
  backed by a content-addressable blob store (CAS). Collaboration became **node-attached** and
  standardised on **Hocuspocus** (Reverb is now for notifications, not collaboration). The
  release artifacts are the split Composer packages `vaulter-core`, `vaulter-filament`,
  `vaulter-permissions-azgard` and the npm package `@vaulter/vue`.
- **BREAKING (pre-1.0, MAJOR): `NodeGrantCreated`, `NodeGrantRevoked`, `NodeShareCreated` and
  `NodeShareRevoked` are no longer deferred to commit** (security remediation P0.2 / F-P1.7-03).
  All four events dropped `ShouldDispatchAfterCommit` and are now dispatched **synchronously
  inside the grant/share mutation's database transaction**, so the outbox and activity rows stage
  atomically with the mutation — closing a crash window where a committed grant/share could lose
  its outbox/activity row. Consequence for listeners: a listener on any of these four events now
  runs **inside the still-open transaction**. It **must be synchronous and idempotent**; a
  `ShouldQueue` listener, or one that assumes an after-commit guarantee, can now run against a
  mutation that is later rolled back, or race the transaction outright. Drive queued or
  after-commit side-effects off the durable outbox/webhook relay instead of off these events
  directly. See `UPGRADE.md` for migration guidance.

### Removed
- **BREAKING (pre-1.0): the CAS engine moved out of `vaulter-core` into the new standalone package
  `axioma-studio/vaulter-media`** (P2 #5, D2/D3). The concrete content-addressed blob engine —
  `Sha256ContentStore`, `FlysystemStorageDriver`, `BlobStore`, `BlobGarbageCollector`, `BlobReconciler`,
  `StagedTempObject` — and the `vaulter:gc-blobs` / `vaulter:reconcile-blobs` commands moved from
  `Vaulter\Cas\*` to `Vaulter\Media\Cas\*` / `Vaulter\Media\Console\*`. Hosts referencing those FQCNs
  directly, or relying on `vaulter-core` alone for Drive file upload/download/versioning, must now also
  `require axioma-studio/vaulter-media`. `Contracts\Cas\*`, the `v_blobs` schema, `Models\Cas\Blob`,
  `Enums\Cas\BlobStatus` and `Repositories\Cas\BlobStoreRepository` remain in core. Config paths
  (`vaulter.cas.*`, `vaulter.dedup.*`) are unchanged. Dependency direction is one-way (media → core; no cycle).
- **BREAKING (pre-1.0): the file/folder tree, storage manager and resumable uploads moved out of
  `vaulter-core` into the new standalone package `axioma-studio/vaulter-drive`** (P2 #5, D1). The
  Drive Actions (`TrashNodeAction`, `UploadFileAction`), `DriveContext`, `DriveManager`,
  `StorageManager`, the `vaulter:purge-trash` / `vaulter:sweep-uploads` commands, Drive events
  (`FileUploaded`, `NodeCopied`, `NodeCreated`, `NodeMoved`, `NodeRenamed`, `NodeTrashed`,
  `NodeVersionCommitted`, `StorageCreated`, …), the Drive/Storage/Upload HTTP controllers, requests
  and resources, and the whole `Uploads\*` subtree (tus + S3-multipart handlers, upload session/part
  models and repositories) moved from `Vaulter\*` to `Vaulter\Drive\*`. Hosts referencing those FQCNs
  directly, or relying on `vaulter-core` alone for `Vaulter::for()`/`forStorage()`, node CRUD, uploads
  or downloads, must now also `require axioma-studio/vaulter-drive`.
  `Contracts\Drive\{DriveHandle,DriveHandleFactory}`, `Support\DriveNameRules`,
  `Exceptions\Nodes\NodeNameConflictException`, `Http\Resources\Drive\NodeResource` and the
  `v_nodes`/`v_upload_sessions`/`v_upload_parts` schemas remain in core.
  `vaulter.models.{upload_session,upload_part}` are no longer defaulted by core and now
  guard-throw when the drive package is absent; other config paths are unchanged. Dependency
  direction is one-way (drive → core+media; no cycle).
- **BREAKING (pre-1.0): document editing, version history, comments and realtime collaboration
  moved out of `vaulter-core` into the new standalone package `axioma-studio/vaulter-documents`**
  (P2 #5, D2). The document Actions (`CreateDocumentNodeAction`, `UpdateDocumentContentAction`,
  `CreateNamedVersionAction`, `RestoreDocumentVersionAction`, `AddNodeCommentAction`,
  `ResolveNodeCommentAction`, `DeleteNodeCommentAction`, `PersistYjsSnapshotAction`), the default
  `Access\HostUserResolver`, the `Collaboration\*` drivers (`HocuspocusNodeCollaborationDriver`,
  `ReverbNodeCollaborationDriver`, `NodeCollaborationDriverManager`, `CollaborationCall`,
  `CollaborationCallResolver`), the document handlers (`TipTapHandler`, `PreviewHandler`,
  `DownloadOnlyHandler`, `UniverHandler`), the `HtmlExporter` / `MarkdownExporter`, the
  `NodeComment` / `NodeDocumentVersion` models and the document/collaboration/comment HTTP
  controllers moved from `Vaulter\*` to `Vaulter\Documents\*`. Hosts referencing those FQCNs
  directly, or relying on `vaulter-core` alone for document editing, versioning, comments or
  collaboration, must now also `require axioma-studio/vaulter-documents`.
  The handler-seam contracts (`FileHandler`, `DocumentContent`, `DocumentExporter`,
  `HandlerRegistry`, `NodeHandlerResolver`), the value objects (`FileDescriptor`,
  `HandlerCapabilities`, `HandlerUi`, `HandlerDescriptor`, `TipTapContent`,
  `OpaqueDocumentContent`) and the `v_node_comments`/`v_node_document_versions` schemas remain
  in core. **Their namespaces were subsequently renamed in the same unreleased cycle** — see the
  kind-neutral-rename entry below for the FQCNs a consumer actually lands on
  (`Contracts\Handlers\*` / `ValueObjects\Handlers\*`).
  `vaulter.models.{node_comment,node_document_version}` are no longer defaulted by core and now
  guard-throw when the documents package is absent; `vaulter.exporters` defaults to `[]` in core
  (`vaulter.handlers` has since gained a core-owned baseline — see VLT-CANON P0.4 below).
  Dependency direction is one-way (documents → core; no cycle) — `vaulter-drive` is not a runtime
  dependency of `vaulter-documents`.
- **BREAKING (pre-1.0): the format-handler contract is kind-neutral now — `DocumentHandler` →
  `FileHandler`, `Contracts\Documents\*` → `Contracts\Handlers\*`** (VLT-CANON P0.1, D7). The
  contract describes ANY file (image/pdf/download-only), not only editable documents, so
  "Engine" / "Document" naming was misleading for the built-in `DownloadOnlyHandler` /
  `PreviewHandler` fallbacks. Renamed: `Contracts\Documents\DocumentHandler` →
  `Contracts\Handlers\FileHandler`; `Contracts\Documents\{HandlerRegistry,NodeHandlerResolver,
  DocumentContent,DocumentExporter}` moved to `Contracts\Handlers\*` (same class names);
  `ValueObjects\Documents\DocumentCapabilities` → `ValueObjects\Handlers\HandlerCapabilities`;
  `ValueObjects\Documents\{HandlerDescriptor,HandlerUi,FileDescriptor}` moved to
  `ValueObjects\Handlers\*` (same class names). `ValueObjects\Documents\{TipTapContent,
  OpaqueDocumentContent,LinkSchemeValidator}` were unaffected by this item — they have since
  moved to `vaulter-documents` in P0.3 (see below). The npm package `@vaulter/vue` renamed the mirrored TS type
  `DocumentCapabilities` → `HandlerCapabilities` (`types.ts`); `HandlerDescriptor`/`HandlerUi`
  are unchanged. `config('vaulter.handlers')` / `config('vaulter.exporters')` keys are
  unaffected. See `UPGRADE.md` for the FQCN migration table.
- **BREAKING (pre-1.0): the `DocumentExporter::export()` parameter WIDENS to the opaque
  `DocumentContent` (was the concrete `TipTapContent`)** (VLT-CANON P0.2). The contract no
  longer assumes TipTap. Callers are unaffected (a `TipTapContent` is a `DocumentContent`);
  **implementations break**: an exporter still declaring `export(TipTapContent $content)` is
  no longer signature-compatible and fatals on class load. A custom exporter must widen its
  parameter to `Vaulter\Contracts\Handlers\DocumentContent` and narrow internally to the
  concrete shape it renders — the built-in `HtmlExporter` / `MarkdownExporter` narrow to
  `TipTapContent` and throw the new
  `Vaulter\Exceptions\Documents\UnsupportedDocumentContentException` otherwise. See
  `UPGRADE.md`.
- **BREAKING (pre-1.0): TipTap's content shapes moved out of `vaulter-core` into
  `vaulter-documents`** (VLT-CANON P0.3). `core` declares only the kind-neutral `DocumentContent`
  contract; it must not know any handler's concrete shape. Moved (same class names, new FQCN
  root `Vaulter\Documents\*`): `ValueObjects\Documents\{TipTapContent,LinkSchemeValidator,
  OpaqueDocumentContent}` → `Documents\ValueObjects\*`; `Attributes\TiptapExtensions` →
  `Documents\Attributes\TiptapExtensions`; `Exceptions\Documents\InvalidTipTapContentException` →
  `Documents\Exceptions\InvalidTipTapContentException`. The store-time allow-list, read-time
  decay and URL-scheme scrub (F-P1.11-01/-02, R-01/R-11/R-12) are unchanged byte-for-byte. A
  core-only host (no `vaulter-documents` installed) is unaffected. `config('vaulter.tiptap.*')`
  stays in core (host-facing config, not a TipTap shape). See `UPGRADE.md`.
- **BREAKING (pre-1.0): the format-handler registry ENGINE moved from `vaulter-documents` to
  core** (VLT-CANON P0.4, D10 — superseding H.5 / the T5 Option-B topology allocation).
  `DocumentHandlerRegistry`, `NodeHandlerResolver`, `DocumentFileDescriptorFactory`, the
  `Capability` enum and the baseline `PreviewHandler`/`DownloadOnlyHandler` moved to
  `Vaulter\Engines\*` / `Vaulter\Enums\Handlers\Capability` (same class names, new FQCN root); a
  drive-only host with no `vaulter-documents` installed now resolves a REAL preview/download
  handler descriptor for every node instead of a silent `null` degrade.
  `Vaulter::registerHandler()` / `Vaulter::handlers()` no longer throw `MissingPackageException`
  when `vaulter-documents` is absent — the seam is core-owned and unconditional now.
  `vaulter-documents` keeps only its own format additions (`TipTapHandler`, the feature-flagged
  `UniverHandler`). Also: `v_node_document_versions.format` no longer carries a schema
  `default('tiptap')` — every writer already stamped it explicitly. See `UPGRADE.md`.
- **BREAKING (pre-1.0): the legacy `document_id`-bound DocVault path was excised**
  (ADR-0014 step 2), leaving a single canonical `v_nodes` document path (kind=document
  nodes with node-attached comments and versions). Removed: the `Document`,
  `DocumentFolder`, `DocumentVersion`, `DocumentPermission`, `DocumentComment` and
  `DocumentShare` models and their `v_document*` tables and migrations; the legacy
  document/folder/version/permission/comment/share repositories, controllers, Actions,
  events, resources and requests; the `Document`-bound collaboration driver
  (`Contracts\Collaboration\CollaborationDriver` + its Reverb/Hocuspocus implementations
  and `Vaulter::driver()`); the `Contracts\Access\PermissionResolver` seam
  (`Vaulter::resolvePermissionsWith()` / `permissionResolver()` and the
  `vaulter.permission_resolver` config key); and the
  `vaulter.models.{document,version,folder,permission,comment,share}` config entries with
  their table names. A dev-only, idempotent `drop_legacy_docvault_tables` migration drops
  any pre-existing `v_document*` tables. Use `Vaulter::for()` and the node/document
  endpoints instead.
- **BREAKING (pre-1.0): `@vaulter/vue` dropped the legacy document-centric surface**
  (ADR-0014 step 4), which called the removed `/documents`, `/folders`, `/documents/*`
  endpoints. Removed: the client namespaces `documents`, `versions`, `folders`,
  `permissions`, `shares` and legacy `collaboration`; the composables `useDocuments`,
  `usePermissions`, `useShares`, `useDocumentExport`, `useCollaboration`, `useVaulterEditor`;
  the components `VaulterFileManager`, `VaulterEditor`, `VaulterVersionHistory`,
  `VaulterPermissionsPanel`, `VaulterSharePanel`, `VaulterBreadcrumb`; and the legacy
  `createCollaborationSession` transport. Use the Drive surface instead — `client.nodes`/
  `useNodes`/`VaulterDrive`, `client.grants`/`useNodeGrants`, `client.nodeShares`/
  `useNodeShares`, `useDocumentNode`, `useNodeCollaboration`. `useComments` /
  `VaulterCommentsPanel` were repointed to the node comment API (`/nodes/{node}/comments`,
  prop `nodeId` instead of `documentId`).

### Added
- **Config-first Storage Panels** — `Vaulter::for()`, per-panel table prefixes and id strategy
  (`bigint` | `ulid` | `uuid7`), with a per-panel schema/migration generator.
- **Content-addressable blob store (CAS)** — scoped dedup (global/tenant/storage), race-safe
  commit (advisory-lock on Postgres), two-phase garbage collection and a reconcile command.
- **Composite ACL** — `AccessResolver` over node grants, host-role drivers and share links, with
  access-aware listing; **four permission drivers**: Database (default), Gate, Spatie and AzGuard.
- **Real-time collaboration (node-attached)** — Hocuspocus driver, Yjs snapshot persistence and a
  TipTap projection; JWT-authenticated rooms bound to a single node.
- **Resumable uploads** — tus + S3-multipart, server-recomputed content hashes, a sweep command.
- **Append-only activity feed** and **transactional-outbox webhooks** with a single relay-sweep
  owner and idempotent delivery.
- **Handler-aware documents** — rich-text (TipTap) and opaque snapshots (e.g. the Univer
  spreadsheet handler), plus a handler-aware create-path (`mime` resolves the node's handler).
- **AzGuard bridge** (`axioma-studio/vaulter-permissions-azgard`) — a real `AzgardGuard` adapter
  delegating to AzGuard scoped roles and workspace-context checks, keeping core AzGuard-free.
- **Vue client** (`@vaulter/vue`) — Drive components (folder-picker, no `window.prompt`),
  shared-with-me view, node-collaboration composables and the headless spreadsheet shell.
- **Full Postgres portability lane** running the schema-portable suite against real Postgres
  alongside the default in-memory SQLite.
- **Pluggable export formats**: register a `\Vaulter\Contracts\DocumentExporter` via
  `config('vaulter.exporters')` or `Vaulter::registerExporter()` to export documents to custom
  formats (PDF, DOCX, …) alongside the built-in HTML and Markdown. The export response now also
  returns the exporter's `mime_type`; binary exporters return base64-encoded `content`.
- **Swappable share-link token generator**: `config('vaulter.share_token_generator')` — implement
  `\Vaulter\Contracts\ShareTokenGenerator` to mint short, hashed or prefixed share tokens (the
  default stays a 48-char random string).
- **Configurable table prefix**: `config('vaulter.table_prefix')` (env `VAULTER_TABLE_PREFIX`,
  default `v_`) prefixes every Vaulter table. Published migrations and models resolve table names
  through it, so the package coexists with host tables and naming conventions.

## [0.1.0] - 2026-06-14

First public release. Vaulter is a complete, embeddable document-management package for Laravel
with a TypeScript front-end and an optional Filament admin.

### Added
- **Filament admin** (`axioma-studio/vaulter-filament`): an optional Filament 5 plugin
  (`VaulterPlugin`) adding **Documents** (searchable table, edit form, trash filter, delete/restore/
  force-delete), **Folders** (CRUD) and **Share-links** (list + revoke) resources, each resolving the
  configured model. Self-contained package with an isolated CI workflow so Filament never constrains
  the core Laravel 12/13 matrix.
- Initial monorepo scaffold: `packages/core` (Laravel package), `packages/vue`
  (`@vaulter/vue` components), `hocuspocus-server/` (collaboration server) and
  VitePress documentation under `docs/`.
- Development tooling: Pint, PHPStan (**level max**) + Larastan, Rector, Pest + Orchestra
  Testbench + type-coverage, Infection, GitHub Actions CI and a `Makefile`.
- **M1 core domain** (`packages/core`):
  - Enums with attribute metadata (`DocumentType`, `PermissionLevel`, `CollaborationDriverType`),
    value objects (`TipTapContent`, `WorkspaceRef`), DTOs, domain events and a typed exception
    hierarchy.
  - Final readonly Actions (documents, versions, folders, permissions, collaboration token) over
    read/store repositories with a `DocumentVisibilityService`.
  - Swappable models with dual key mode (`id` | `ulid`) and optional `tenant_id` via
    `config('vaulter.models'|'id_type'|'tenant')`.
  - REST API (documents, versions, folders, permissions, collab token) with `JsonResource`
    responses that embed computed `abilities` (`AbilitiesDto`).
  - Collaboration driver abstraction: `ReverbCollaborationDriver` (default) and
    `HocuspocusCollaborationDriver` (optional), plus broadcast-channel authorization.
  - `PermissionResolver`, `StorageAdapter`, `CollaborationDriver` contracts; `VaulterManager`
    + facade.
- **M2 frontend** (`@vaulter/vue`): TypeScript-first rewrite.
  - Typed API client (`createVaulterClient`) covering documents, versions, folders, permissions
    and the collaboration token, with `VaulterApiError` and full request/response types.
  - Composables `useVaulterClient`, `useDocuments`, `useVaulterEditor`, `useCollaboration`,
    `usePermissions`.
  - Slot-driven components wired to the client and consuming computed `abilities`.
  - `VaulterPlugin` provides the client app-wide; Vite library build emits ES + UMD + `.d.ts`.
  - `Frontend` CI workflow (Node 20/22: type-check, Vitest, build).
- **M3 collaboration runtime** (backend + transport):
  - Server-side persistence of merged content: `POST /documents/{id}/collab/sync`
    (`SyncCollaborationStateAction`) updates the document and dispatches the broadcastable
    `DocumentContentSynced` event on the private channel `vaulter.documents.{id}`.
  - `@vaulter/vue`: `createCollaborationSession` (Hocuspocus out of the box, injectable Reverb
    bridge) and a `useCollaboration` that exposes a `Y.Doc` plus `connect`/`disconnect`.
- **Comments**: threaded, optionally anchored document comments.
  - `v_document_comments` model (swappable), `AddComment`/`ResolveComment`/`DeleteComment`
    actions, REST API under `/documents/{id}/comments` gated by the `comment` / `manage`
    abilities, and a broadcast `CommentAdded` (`comment.added`) + `CommentResolved` events.
  - `@vaulter/vue`: `useComments` composable, `VaulterCommentsPanel` component, client methods
    and types.
- **Public share links**: tokenized, level-scoped, optionally expiring document shares.
  - `v_document_shares` model (swappable), `CreateShareLink` / `RevokeShareLink` actions, managed
    under `/documents/{id}/shares` (gated by `manage`) with a **public** `GET /shares/{token}`
    that returns the document with abilities derived from the share level; `ShareLinkCreated` /
    `ShareLinkRevoked` events; `DocumentAbilities::fromLevel()`.
  - `@vaulter/vue`: `useShares` composable, `VaulterSharePanel` component, client methods (incl.
    `shares.resolve(token)`) and types.
- **Export**: render documents to HTML or Markdown.
  - `TipTapRenderer` (common nodes/marks, escaped HTML) + `ExportFormat` enum and a
    `GET /documents/{id}/export?format=` endpoint (gated by `view`) returning
    `{ format, content, filename }`.
  - `@vaulter/vue`: `useDocumentExport` composable (with a browser `download` helper) and a
    `documents.export(id, format)` client method.
- **Bulk operations**: `POST /documents/bulk` applies one action (`delete` / `restore` / `move` /
  `force-delete`) to many documents, authorizing each individually and returning `processed` vs
  `skipped` ids (`BulkAction` enum, `BulkDocumentController`). `@vaulter/vue`: `documents.bulk`
  client method and `useDocuments.bulk`.
- **Document operations**: move & duplicate.
  - `PATCH /documents/{id}/move` (`MoveDocumentAction`, gated by `edit`) re-files a document into a
    folder of the same workspace (cross-workspace → `422`) and dispatches `DocumentMoved`;
    `POST /documents/{id}/duplicate` (`DuplicateDocumentAction`, gated by `view` + `create`) clones it.
  - `@vaulter/vue`: `documents.move` / `documents.duplicate` client methods and `useDocuments`
    `move` / `duplicate`.
- **Search & trash**: document discovery and lifecycle.
  - The document list accepts a `q` term (title + content `LIKE`, workspace-scoped). A trash
    listing (`GET /documents/trash`) and permanent `DELETE /documents/{id}/force` (gated by
    `manage`) round out soft-delete/restore; `DocumentPurged` event on hard delete.
  - `@vaulter/vue`: `useDocuments` gains `listTrashed` / `forceDelete` (and `q` on `list`); client
    `documents.trashed` / `documents.forceDelete`.
- Documentation: architecture, embedding, search & trash, permission resolver, abilities,
  collaboration, comments, sharing, export, error reference, the TypeScript frontend guide, and
  ADRs 0001–0004.

[Unreleased]: https://github.com/axioma-studio/vaulter/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/axioma-studio/vaulter/releases/tag/v0.1.0
