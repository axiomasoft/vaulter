# Changelog

All notable changes to **Vaulter** will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
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
