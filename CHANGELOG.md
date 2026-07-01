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

### Removed
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
