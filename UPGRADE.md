# Upgrade Guide

This file documents breaking changes and how to migrate through them. Entries are ordered
newest-first. See `CHANGELOG.md` for the full list of changes per release; this file only covers
changes that require action from consumers of the package.

## Unreleased

### Admission and write guard corrections (PLAN2.D54)

- **File copies and revision restores.** Was: `maxFileBytes` was enforced on uploads,
  but an existing oversized file could be copied or restored after lowering the limit.
  Now: every new file in a copy must fit the current limit; restoring a larger payload
  must fit too (`413 file_size_limit_exceeded`). Existing files stay readable, and a
  restore that reduces their size remains allowed. A refused operation leaves the
  head, revisions, quota and CAS edges unchanged.
- **Generated document names.** Was: conflict renaming could exceed the name policy
  when the extension consumed the byte budget. Now: the generated name passes the
  same policy as input; an invalid candidate returns `422 invalid_node_name` before
  a second document is created.
- **Model write guard.** Fresh statement builders retain their `WriteScope`, and
  `insertOrIgnoreReturning` checks it on Laravel versions that provide that statement.
  Was: those paths could bypass the guard. Now: an out-of-operation write returns
  the integration error `write_outside_operation` (500). Raw host SQL remains outside
  this guard, as documented in the compatibility guide.
- **Snapshot contract.** Attribution columns without an actor type are exposed as
  nullable string host keys. `GrantSnapshot::$subject` uses the existing `Subject`;
  `FileHeadSnapshot::$fileId` and `Page::$scanned` are included in the public value
  surface. Golden coverage now freezes snapshot properties, `Page`, their state
  enums and presentation DTO properties. Constructors of snapshots and pages are internal; hosts read these values.


### Document export by type and format (PLAN2.D28, D34–D37; PLAN2.P4.4)

Export belongs to `vaulter-documents` and answers a file.

- **Contract moved and widened.** `Vaulter\Contracts\Handlers\DocumentExporter` is gone; implement
  `Vaulter\Documents\Contracts\DocumentExporter`: add `key()` (`vendor/name`) and
  `documentTypes()` (the type keys it renders) and change `export(DocumentContent $content)` to
  `export(DocumentContent $content, DocumentType $type)`. Core `Contracts\Handlers\DocumentContent`
  is removed with it; use `Vaulter\Documents\Contracts\DocumentContent`.
- **Registry by (type, format).** `Registries\DocumentExporterRegistry` → `Export\DocumentExporterRegistry`:

  | Was | Now |
  |---|---|
  | `register($exporter)` (last one wins per format) | `register($exporter)`; a second exporter for one (type, format) pair or key throws `DuplicateDocumentExporterException` at boot |
  | — | `replace($key, $exporter)` swaps an exporter under its own key |
  | `get($format)` | `for($typeKey, $format)` |
  | `formats()` | `formatsFor($typeKey)` |

  The registry is frozen after boot, like the type registry. A tag `vaulter.exporters` is read too.
- **Config.** `vaulter-documents.exporters` is a list of class names. The 0.2 map `format => class`
  is still read (its classes, in order) with a deprecation; a host class that overrides a built-in
  format now collides with it, so list it instead of the built-in or `replace()` the built-in.
- **Removed:** `Export\NodeExportService` (use `Documents::in($drive)->export()`),
  `Enums\Export\ExportFormat` (a format is declared by its exporter), and
  `TipTapRenderer::render()` (call `toHtml()` / `toMarkdown()`).
- **`DocumentOperations::export($node, $format, $revision = null)`** returns `DocumentExport`
  (`bytes`, `filename`, `mimeType`, `format`, `sourceEpoch`, `sourceStateVersion`,
  `sourceRevisionId`); `content` and `toArray()` are gone. Needs Download and content egress. The
  type must be installed (`document_type_unavailable`) and list the format (`422
  unknown_export_format` with `allowed`). Exporting never creates a revision.
- **HTTP.** `GET nodes/{node}/export?format=` answers the file (`Content-Disposition: attachment`
  with `filename*`, `Cache-Control: private, no-store`, `X-Content-Type-Options: nosniff`) instead
  of `{ "data": … }` JSON. `@vaulter/vue` `documentNodes.export()` (and `useDocumentNode().exportAs()`)
  now resolves `{ blob, filename, mimeType }`.
- **Generator.** `php artisan vaulter:make:exporter {name}` scaffolds an exporter.
- Exporters may not use `Vaulter\Content\*`, `Storage` or the HTTP layer (architecture test).

### Document type registry, codecs and a safe fallback (PLAN2.D14, D15, D34, D44; PLAN2.P4.3)

No schema change: documents already store their type key (`vaulter/rich-text`, …) and schema
version. What a type is moved from an enum and file handlers to registered classes.

- **Registering types.** Was: `vaulter.enabled_document_types` (`richtext`, `table`, `markdown`,
  `template`) and `vaulter.features.spreadsheets`. Now: `vaulter-documents.types`, a list of
  `DocumentType` classes (default `[RichTextDocumentType::class, MarkdownDocumentType::class]`), plus
  the container tag `vaulter.document_types` and `Documents::types()->register()` before the
  application has booted. A published 0.2 config is normalized with a deprecation (`richtext`,
  `table`, `template` → `RichTextDocumentType`; `markdown` → `MarkdownDocumentType`;
  `spreadsheets = true` → `SpreadsheetDocumentType`). New `vaulter-documents.default_type`
  (`vaulter/rich-text`) must be a registered key. A legacy list without a rich-text format (say
  `enabled_document_types => ['markdown']`) normalizes to `[MarkdownDocumentType::class]` only, and
  boot then fails with `vaulter-documents.default_type "vaulter/rich-text" is not a registered
  document type`: set `default_type` to a registered key (`vaulter/markdown`) or add `richtext` back
  to the list. The registry freezes once the application has
  booted (`RegistryFrozenException`), a duplicate key throws `DuplicateDocumentTypeException`
  (override with `replace()`), and the `vaulter/` prefix is reserved.
- **Removed** `Vaulter\Documents\Enums\Documents\DocumentType`, `Engines\Handlers\TipTapHandler`,
  `Engines\Handlers\UniverHandler`, `Attributes\TiptapExtensions`,
  `Support\TipTap\Concerns\HasTiptapExtensions`, `Registries\DocumentTypeRegistry` (now
  `Types\DocumentTypeRegistry`), `DocumentsConfig::enabledDocumentTypes()` and
  `spreadsheetsEnabled()`, and `ContentCapabilities::$richText` (and the `rich_text` capability
  key): a document's type decides its content shape.
- **Moved** `ValueObjects\TipTapContent` → `Content\TipTap\TipTapContent`,
  `ValueObjects\LinkSchemeValidator` → `Content\TipTap\LinkSchemeValidator`,
  `Support\TipTap\TipTapRenderer` → `Content\TipTap\TipTapRenderer`,
  `ValueObjects\OpaqueDocumentContent` → `Content\OpaqueContent`,
  `Support\DocumentContentHasher` → `Content\ContentHasher` (now hashes any array), and core
  `Vaulter\Exceptions\Documents\{UnknownExportFormatException,UnsupportedDocumentContentException}`
  → `Vaulter\Documents\Exceptions\*`. Implement `Vaulter\Documents\Contracts\DocumentContent`
  instead of core `Vaulter\Contracts\Handlers\DocumentContent` (removed with the exporter move in P4.4).
- **Files are not documents.** `.docx`, `.doc`, `.md` and `.xlsx` files are no longer declared
  editable or collaborative: they are previewed or downloaded like any file. If you listed
  `TipTapHandler` in `vaulter.handlers`, remove it.
- **Presentation.** A document node is presented by its type: `handler.id` is `null`, and the
  presentation gains `document_type` and `type_available`; `ui` is the type's viewer
  (`vaulter/tiptap` with `preset: rich-text|markdown`, `vaulter/univer`, or `vaulter/unsupported`).
  Capabilities lost `rich_text`; documents report `downloadable: false`. Core
  `NodePresentation::$id` is nullable and core defers a node kind to a tagged
  `Vaulter\Contracts\Presentation\KindPresenter` (`vaulter.kind_presenters`).
- **PHP API.** `DocumentOperations::create($name, ?string $type = null, …)` — `null` uses
  `default_type`; the content is decoded by the type's codec. `CreateDocumentInput` takes
  `type` and `schemaVersion` instead of `format` and `mimeType` (every document node gets
  `CreateDocumentInput::NODE_MIME`). `DocumentOperations::updateContent()` (internal, HTTP) takes
  the raw array. Profiles name type keys: `documentTypes('vaulter/markdown')` (was `'markdown'`).
- **Unknown types and degraded content.** A document whose type is not installed reads as opaque
  JSON (`typeAvailable = false`, `editable = false`); `update`, `snapshot`, `restore`, `export` and
  `collaborationSession` answer `409 document_type_unavailable`; copy, trash and purge work.
  Content that reads only partly (nodes of an editor extension you switched off, or a newer
  schema) is read-only: `update` answers `409 document_content_degraded` instead of saving the
  stripped view.
- **HTTP.** `POST …/documents` accepts `type` (`vendor/name`); `format` and `mime` keep working. A
  spreadsheet create needs `SpreadsheetDocumentType` in `vaulter-documents.types` (was the
  `spreadsheets` flag).
- **New** `vaulter:make:document-type` and the contract test trait
  `Vaulter\Documents\Testing\Contracts\DocumentTypeContractTests`.
- **Docs.** [Document Types](docs/guide/document-types.md).

### Documents API through operations (PLAN2.D34, D35, D38, D44, D45; PLAN2.P4.8)

No schema change. Code that called the documents Actions must move to the operations.

- **PHP API.** Was: `app(CreateDocumentAction::class)->execute(new CreateDocumentInput(…, actorId: …))`
  and the other Actions, authorized by your own code. Now: `Documents::in($drive)->create()`,
  `content()`, `update()`, `snapshot()`, `revisions()`, `restore()`, `setRetention()`, `export()`,
  `collaborationSession()` and `Documents::comments($drive)->list()`, `add()`, `resolve()`, `delete()`,
  with `$drive = Vaulter::drive($owner)->actingAs($user)`. They authorize every call and return
  snapshots (`DocumentSnapshot`, `DocumentRevisionSnapshot`, `CommentSnapshot`, `NodeSnapshot`,
  `Page<T>`). Pass `DocumentSnapshot::$stateVersion` back as `expectedStateVersion`.
- **Actions** are internal and take `execute(OperationContext $context, …Input $input)`. Inputs lost
  `actorId` (the actor is the context's); `AddCommentInput` is now `(body, parentId, anchor)` — the
  author is the acting user, and a system context cannot comment (`MissingActorException`).
  `RestoreDocumentRevisionAction` takes `RestoreDocumentRevisionInput`, `UpdateRevisionRetentionAction`
  takes `UpdateRevisionRetentionInput` (it can pin as well as release), the comment Actions take
  `ResolveCommentInput`/`DeleteCommentInput`.
- **HTTP.** Same URLs and JSON keys. A node the user cannot see, a trashed node and a node of another
  tenant answer `404 node_not_found` (was `403 permission_denied`, or a bare `404` for trash); a comment of
  another node answers `404 comment_not_found`. Export needs `Download`; reading the content
  (`GET .../content`) needs `View` (was `Download`), so a previewer reads documents in the app. Resolving a comment needs
  `Comment` and authorship or `Edit` (was authorship or `Manage`; deleting keeps authorship or `Manage`).
  Ids in the document, revision and comment JSON are strings (`"id": "42"`), as for nodes since P4.13;
  `resolved_by` and `created_by` too. The history is ordered by creation time, newest first.
- **Collaboration.** `CollaborationSessionService::issue($actor, $node)` becomes
  `issue($node, $ability, $actorId)` and makes no access decision; call
  `Documents::in($drive)->collaborationSession($node)` instead. The hub endpoints are unchanged.
- **Removed** `Vaulter\Http\Support\TrashGuard` (core); its only user, the documents controller, now
  relies on `NodeLocator`, which keeps trashed nodes out.
- **Docs.** [Document Nodes — The PHP API](docs/guide/documents.md#the-php-api).

### Copying documents and content lifecycle registry (PLAN2.D17, D30, D40; PLAN2.P4.2)

- **Document copy works.** `NodeOperations::copy()` (and `POST .../copy`) now copies a document, alone
  or inside a folder: the copy is a new aggregate with one checkpoint, `state_version` 1 and
  `collab_epoch` 0; history and comments are not copied. It needs the documents package. Without it,
  copying a document still raises `UnsupportedNodeKindException` as before. A subtree with a document
  the copier cannot View/Copy/Download is still refused whole (`permission_denied`).
- **Registry.** `NodeContentLifecycleRegistry` gains `registerFallback()`; a second registration of a
  kind now throws `Vaulter\Exceptions\Registry\DuplicateNodeContentLifecycleException`
  (`duplicate_registration`, was `LogicException`). Lifecycles are also collected from the container
  tag `vaulter.content_lifecycles`; the `vaulter.content_lifecycles` config list keeps working.
- **Marker.** `Vaulter\Contracts\Drive\PurgeOnlyContentLifecycle` marks a lifecycle whose `copy()` is a
  refusal; a kind resolving to one cannot be copied. Drive's `DocumentPurgeLifecycle` (now a fallback,
  replaced by the documents package) and `ShortcutPurgeLifecycle` carry it. A custom lifecycle for
  `document` or `shortcut` registered through config now replaces the fallback and, unless it
  implements the marker, makes the kind copyable.

### Public API on snapshots; models are internal; write guard (PLAN2.D44, D38; PLAN2.P4.13; ADR-0026)

No schema change. Code that holds `Node`, `FileRevision`, `NodeGrant`, `NodeShare` or `Activity`
results of the operations must switch to snapshots.

- **Results.** Every method of `NodeOperations`, `FolderOperations`, `FileOperations`,
  `TrashOperations`, `AccessOperations`, `ShareOperations`, `ActivityOperations` and the handle
  returns an immutable snapshot (`Vaulter\Data\Snapshots\*`) or a `Vaulter\Data\Page<T>`
  (`items`, `nextCursor`; `scanned` is the number of candidates examined). Was: Eloquent models,
  `NodePage`, `CursorPaginator`, Eloquent `Collection`. Snapshot properties are camelCase:
  `$node->parentId`, `->driveId`, `->sizeBytes`, `->trashedAt`, `->mimeType`, `->inheritsAccess`,
  `->createdAt`, `->contentModifiedAt`; the viewer's star is `$node->viewer?->starred`; the file head is
  `$node->file` (`fileId`, `revisionId`, `revisionNumber`, `sha256`, …). Actor fields (`createdBy`,
  `updatedBy`, `trashedBy`) hold the host key of the user as a string, or null for the system.
  Ids are strings.
- **Parameters.** `Node|int|string` becomes `NodeSnapshot|string|int` (likewise for revisions, grants and
  share links). Passing a model is a `TypeError`; pass `$model->getKey()`. `UploadOptions::$parent`,
  `SearchOptions::$within` and `RestoreOptions::$destination` follow.
- **Lists.** `$page->items()` on a paginator becomes `$page->items`; `$page->nextCursor()->encode()`
  becomes `$page->nextCursor` (a string; pass it back as `ListOptions::$cursor`).
  `AccessOperations::grants()` and `ShareOperations::list()` return a plain list.
- **Share links.** `ShareOperations::create()`/`rotate()` return `Vaulter\Data\Snapshots\MintedShareLink`
  with `->link` (a `ShareLinkSnapshot`) and `->token`. Was `->share` (a model). `update()` returns a
  `ShareLinkSnapshot`. `ShareLinkSnapshot::$passwordProtected` replaces `hasPassword()`.
- **Handle.** `DriveHandle::snapshot()` and `quota()` are new. `DriveHandle::model()` is `@internal`; use
  `snapshot()` unless you really need the row. `DriveHandle::root()` returns a `NodeSnapshot`.
- **Custom implementations.** A class implementing a changed contract must change its signatures. An
  `AccessOperations`, `ShareOperations` or `ActivityOperations` double in tests: return snapshots.
  `NodePresenter::present()` and `FileDescriptorFactory::forNode()` also accept a `NodeSnapshot`.
- **REST.** JSON keys are unchanged. Ids are now always strings, so a bigint install now sees `"id": "42"`
  where it saw `42`. The Vue client already types ids as strings.
- **Write guard.** Vaulter models refuse to be written outside a Vaulter operation:
  `$node->save()`, `Node::query()->update(...)`, `->delete()`, `->increment(...)` and the like throw
  `WriteOutsideOperationException` (500 `write_outside_operation`). Go through the operations. Host
  code that writes with `DB::table()` is not intercepted. Test fixtures that build rows with models can
  wrap the write in `app(VaulterDatabase::class)->write(fn () => $model->save())`, or set
  `vaulter.database.write_guard` (`VAULTER_DB_WRITE_GUARD`) to `false` in the test environment. Vaulter's
  own suites do the latter by default and run dedicated lanes with `VAULTER_TEST_WRITE_GUARD=1`.
- **Docs.** [API compatibility](docs/guide/compatibility.md) states what 1.0 freezes and what it does
  not; [ADR-0026](docs/adr/0026-public-api-snapshots.md) records the decision.

### File revisions, quota over every revision, no global dedup (PLAN2.D48, D49; PLAN2.P4.12)

Back up the database and stop writes before migrating.

- **Quota recount.** `2026_10_07_000007_quota_all_revisions` adds `v_file_revisions.superseded_at`
  (backfill: commit time of the next revision) and recounts `v_drives.used_bytes` as the sum of
  every retained revision, trash included, without a dedup discount (`Vaulter\Quota\QuotaCalculator`).
  Drives with history will show **higher usage** than before. A drive that ends up above
  `quota_bytes` keeps reads, deletes and purges; uploads, new revisions, restores and copies are
  refused (`quota_exceeded`) until revisions are deleted or pruned or the quota is raised. Check
  before migrating if hard limits matter.
- **Quota behaviour.** A new revision charges its full size (was: the difference from the head; a
  smaller revision used to release space). Restoring a revision charges it again; deleting or pruning
  a revision releases it.
- **Retention.** Drive profiles prune non-current revisions by default: at most 100 per file, never
  younger than 7 days after they were superseded, never the head or a pinned revision. Set
  `->drivePolicy(fn ($p) => $p->revisionRetention(new RevisionRetentionPolicy(maxNoncurrentRevisions: null)))`
  to keep everything, and schedule `vaulter:revisions:prune` daily (nothing is pruned until it
  runs). `Profile::revisionLimit(n)` keeps `n` revisions including the head. The internal
  `Vaulter\Drive\Files\FileRevisionRetention` helper is removed.
- **API.** `FileOperations::deleteRevision($file, $revision)` (Manage; head → 409
  `cannot_delete_head_revision`, pinned → 409 `revision_pinned`) and
  `setRevisionRetention($file, $revision, bool $keepForever)` (Edit). Custom `FileOperations`
  implementations must add both. `FileRevisionDeleted` is dispatched after commit.
- **Dedup scope.** `vaulter-media.dedup.scope = global` (and `VAULTER_DEDUP_SCOPE=global`) now stops the
  application at boot (`InvalidConfigurationException`). Use `tenant` (default), `drive` or `off`, then
  run `php artisan vaulter:blobs:repair-scopes --dry-run` and `--execute` to move existing `global`
  blobs into the tenant (or, without a tenant, the drive) of each referencing revision. Under
  `tenant`, a drive without a tenant now deduplicates within itself (was install-wide).
  `MediaConfig::dedupScope()` returns `Vaulter\Media\Configuration\DedupScope`;
  `BlobScope::global()` is removed.

### Share links 1.0: role, audience, rotation, redemptions (PLAN2.D47; PLAN2.P4.10)

Back up the database and stop writes before migrating. `2026_10_07_000006_share_links_v1` **refuses**
(`UpgradePreflightException`, nothing changed) when a link's ability mask is not exactly the abilities
of a role a link may carry. Revoke such links, or recreate them with a role, and run it again.

- **`v_node_shares`**: `role_key`, `role_version`, `audience` and `token_generation` replace
  `permissions`, `permissions_version`, `level`, `require_login` and `allow_discovery`. Conversion:
  mask 65 → `reader`, 97 → `commenter`, 1 → `previewer` (view without download; never widened to
  reader), `require_login = true` → audience `authenticated`, otherwise `anyone`. A 0.x mask is read
  as before (1 → `reader`, 33 → `commenter`). `allow_discovery` was never used and is dropped.
  The earlier migration `2026_10_07_000005` now skips its share-mask steps when `permissions` is
  absent (a fresh install); upgraded databases behave as before.
- **API.** `ShareLinkInput{role, audience, scope, expiresAt, password, maxUses}` (was
  `permissions`, `level`, `requireLogin`, `allowDiscovery`); new `ShareLinkUpdate`;
  `ShareOperations::update()` and `rotate()`; `ShareContext` carries a `Role`, not a mask.
  REST: `role`/`audience` replace `permissions`/`level`/`require_login`/`allow_discovery`;
  new `PATCH nodes/{node}/shares/{share}` and `POST …/rotate`.
- **Rules.** `writer` links need audience `authenticated` or `tenant`; `organizer` and `owner` are
  never allowed (`share_role_not_allowed`, 422). Expiry may not pass the profile's
  `maxShareLinkTtlDays`, at creation and on every visit (`share_link_ttl_exceeded`, 422).
- **Behaviour.** A previewer link gets `403 permission_denied` on download. `max_uses` now counts
  redemptions: the first request of a visit counts and returns an `X-Share-Session` header; requests
  that send it back do not. Before, every download counted and previews did not. New tokens are
  43-character `base64url(random_bytes(32))` (was 48 alphanumerics); stored tokens keep working.
- **Vue.** `CreateNodeSharePayload{role, audience, …}`; `NodeShare` has `role`, `role_version`,
  `audience`, `token_generation`; `nodeShares.update()` / `rotate()`; the panel takes `roles` and
  `audiences` props instead of `levels`.
- **Activity/webhook payloads** of share events carry `role`, `role_version`, `audience`, `scope`
  instead of `level` and `permissions`.

### Access model 1.0: versioned roles, no deny, inheritance boundaries (PLAN2.D45, D46, D53; PLAN2.P4.9; ADR-0025)

Back up the database and stop writes before migrating. `2026_10_07_000005_access_model_v1` **refuses**
(`UpgradePreflightException`, nothing changed) when `v_node_grants` holds a `deny` grant, a level
that is not one of the five 0.x levels, or `permissions` bits beyond the level's own bits — such a
grant has no positive equivalent. Rewrite those grants first (usually: remove the deny and put an
inheritance boundary on the node, or give the people who need it a direct grant) and run it again.

- **Levels become roles of the same name, version 1** (`reader`, `commenter`, `writer`,
  `organizer`, `owner`; new `previewer`). Declared differences from the 0.x bitmasks: `writer` also
  gains Trash and Restore; `organizer` gains Share and loses Delete. `reader` keeps what Read gave
  and now includes Download — View and Download are separate abilities, so a `previewer` can see
  without downloading or copying.
- **`v_node_grants`**: `role_key`/`role_version` replace `level`, `permissions` and `effect`; the
  grant is unique per (node, subject, scope). `v_nodes.owner_id` is dropped **without creating
  grants**: no built-in driver granted anything from it, so a host that relied on "the creator
  owns the node" must grant the creator a role explicitly.
- **Algorithm.** Was: allow/deny buckets per depth, `max(rank)`, a final deny veto. Now: the
  union of the roles of the node's direct grants, the subtree grants of its ancestors up to and
  including the first `inherit_grants = false` node, and the driver's roles; a host
  `override()` is the only block.
- **Removed**: `GrantLevel`, `AclFolder`, `AclMask`, `AccessAce`, `GrantInput`'s `level`/`effect`/
  `permissions` (now `GrantInput{subject, role, scope, expiresAt}`), `AccessScope::$nodeOwner` and
  the `node_owner` Gate context entry. `Effect` remains only as the return type of
  `PermissionDriver::override()`.
- **Changed shapes**: `Permission` has one bit per ability (View 1, Edit 2, Create 4, Delete 8,
  Share 16, Comment 32, Download 64, Move 128, Copy 256, Trash 512, Restore 1024, Manage 2048;
  `all()` is 4095; was CRUDS 1..31); `Decision{Role $role}` (was level/bits/effect, build several
  with `Decision::union()`); `GrantSet{allowedBits, superAllow, superDeny}` (no deny mask, no
  level); `AccessExplanation{allowed, ability, reason, sources, restrictions, boundaryNodeId}`.
- **Driver maps.** `vaulter.access.gate.abilities` and `vaulter.access.spatie.permissions` are
  keyed by role key and gain `previewer => vaulter.preview`; a key that is not a role throws
  `UnknownRoleException` (`unknown_role`, 422). The `azgard` lane probes
  `previewer→View`, `reader→Download`, `commenter→Comment`, `writer→Edit`, `organizer→Share`,
  `owner→Manage`; `AzgardPermissionDriver` takes a `RoleRegistry`. Custom drivers must return
  `Decision{Role}` (or `null`).
- **HTTP and events.** `POST nodes/{node}/grants` takes `role` (was `level`/`effect`/
  `permissions`) and returns `role` and `role_version`; activity and webhook grant payloads carry
  `role`/`role_version` instead of `level`/`permissions`/`effect`. `@vaulter/vue` follows: `NodeGrant`
  has `role`/`role_version`, `GrantNodeAccessPayload` takes `role`, `GrantEffectName` is gone and
  `VaulterNodeGrantsPanel` offers `roles` instead of `levels`/`effects` (share links keep `level`,
  now a role key; `VaulterNodeSharePanel` defaults to `previewer`/`reader`/`writer`).
- **New**: `access()->restrictInheritance($node, InheritanceRestriction::Empty|CopyCurrent)` and
  `restoreInheritance($node)` (Manage); `explain()` lists the role sources and the boundary node;
  copy now needs View + Download + Copy on every copied node.
- **Grant management requires Manage**, including listing, granting and revoking. An organizer's
  Share permits bearer links; it cannot grant itself owner access.
- **Share links** are adapted minimally: stored bits use the new layout, the share `level` column
  holds a role key, and a link to a folder stops at inheritance boundaries. Their redesign is a
  later change. Legacy masks preserve their exact abilities, including Download for READ.
  Internal `v_node_shares.permissions_version` makes conversion resumable; new links use version 1.
  Vue sends explicit non-comment masks for anonymous roles. Rollback refuses new share masks
  with no exact 0.x equivalent (for example, preview-only View) before changing anything.
  The node owner column is removed even when the legacy grants table is absent.

### Node data contract: names, properties, stars, times, drive policy (PLAN2.D39–D43; PLAN2.P4.11)

Back up the database before migrating. `2026_10_07_000004_node_data_contract` refuses
(`UpgradePreflightException`) a node whose `extra_data` cannot become 1.0 properties (more than
100 keys, a key over 64 bytes after replacing characters outside `A-Za-z0-9_.-` with `_`, two
keys that collide after that replacement, a value over 1024 bytes, or more than 16 KiB in all) and
a `name_key`/`properties`/`content_modified_at` column or `v_node_user_states` table it did not
create. It changes nothing in that case; move the data out and run it again.

- **Names are no longer trimmed.** `NodeName::normalize()` lost its `$maxLength` argument and the
  trim; new names pass the profile's name policy — `portable-v1` by default, which refuses
  `< > : " | ? *`, edge whitespace, a trailing dot, Windows device names and `desktop.ini`/`.DS_Store`.
  Hosts that need the old acceptance set `->drivePolicy(fn ($p) => $p->namePolicy('permissive-v1'))`.
  `InvalidNodeNameException` renders `invalid_node_name` (was `validation_failed`) with a `reason`.
  Stored names are kept as they are.
- **Sibling uniqueness uses `name_key`** (NFC + full case folding, byte comparison) instead of
  `lower(name)`. The migration renames each later live sibling that now collides with an earlier
  one (`Straße`/`STRASSE`, NFC/NFD pairs) to `"<base> (conflict N)<.ext>"`, logs a warning and
  records a `node.renamed` activity from `vaulter:system`. Raw inserts into `v_nodes` must now
  write `name_key` (`NodeName::key($name)`); the `Node` model sets it on every name write.
- **`extra_data` is gone from every table** and `HasExtraData` was removed. `v_nodes.extra_data`
  moves to `properties['legacy']` (top-level keys, non-string values JSON-encoded); the other
  tables' `extra_data` is dropped (no Vaulter code wrote it). `vaulter.extra_data.enabled` and
  `VaulterConfig::extraDataEnabled()`/`VaulterSchema::extraData()` are gone; the config key is
  deprecated and ignored.
- **`is_pinned`/`position` and `nodes()->update()` were removed** with `UpdateNodeOptions`. Use the
  personal `nodes()->star()/unstar()/starred()`; children sort by name (or `ListOptions`). Store
  shared metadata with `nodes()->setProperties($node, 'your.namespace', [...])`. The REST node no
  longer has `is_pinned`/`position`; it has `viewer.starred` and `content_modified_at`.
- **Limits live in `DrivePolicy`.** `vaulter-drive.names.max_length` (and the legacy
  `vaulter.drive.name_rules.max_length`) is deprecated and ignored and `DriveConfig::nameMaxLength()`
  is gone: names are limited to 255 UTF-8 bytes by the policy. A profile's `UploadPolicy::maxFileSize`
  still applies as `DrivePolicy::maxFileBytes` when the drive policy sets none. Oversized uploads
  now fail with 413 `file_size_limit_exceeded` (was 422 `upload_rejected` / `file_too_large`).
  New 409 `folder_children_limit_exceeded` / `tree_depth_limit_exceeded` (defaults 10 000 children,
  trashed included, and 100 levels) can refuse writes that used to succeed.
- **`DriveNameRules::rules()` takes no argument** (was `rules(int $maxLength = 255)`): request
  validation keeps the 255-character shape check; the Actions apply the policy.

### Document aggregate and node pointers (PLAN2.D34, PLAN2.D35, ADR-0022; PLAN2.P4.1)

Back up the database and stop writes before migrating. The upgrade is three migrations:
`2026_10_01_000005_documents_aggregate`, `2026_10_07_000001_drop_node_legacy_pointers` and
`2026_10_07_000002_revision_provenance_and_heads`. Each refuses (`UpgradePreflightException`)
instead of guessing: document nodes without any version, a document head that is not one of its
own versions, versions attached to non-document nodes, soft-deleted nodes, or a file whose head is
a revision of another file. Rolling back restores the 0.2 shape with the same ids.

- **`v_node_document_versions` → `v_documents` + `v_document_revisions`.** Every 0.2 version
  becomes a checkpoint with the **same id** (activity/outbox payloads stay valid):
  `version_no` → `number`, `snapshot_label` → `label`, `format` → `v_documents.type`
  (`richtext|table|template` → `vaulter/rich-text`, `markdown` → `vaulter/markdown`, `univer` →
  `vaulter/spreadsheet`, anything else → `legacy/<format>`). The head's payload becomes the
  document's working state. Per-version `extra_data` is not carried (no 0.2 code wrote it).
- **`v_nodes` loses `current_version_id`, `file_id` and `deleted_at`.** Read heads from the
  aggregate: `$node->file->current_revision_id` / `File::currentRevision()` for files,
  `v_documents.current_revision_id` for documents. `Node` no longer uses `SoftDeletes`
  (`withTrashed()`, `onlyTrashed()`, `forceDelete()`, `trashed()` are gone; purge is a real
  DELETE). `Node::currentVersion()` was removed; `Node::file()` is now a `HasOne` by `node_id`.
  `File::currentVersion()` → `File::currentRevision()`. Sibling-name uniqueness no longer
  mentions `deleted_at`. The REST node JSON keeps `file_id`/`current_version_id`, computed from
  the aggregate.
- **Document checkpoints are immutable.** After INSERT only `keep_forever` changes; the model
  throws on any other update and `DocumentRevisionStoreRepository::update()` was removed
  (`setRetention()` remains). `DocumentRevision` has `document_id`, `number`, `label`,
  `schema_version`, `state_version`, `content_size`, `restored_from_revision_id` and no
  `node_id`/`version_no`/`snapshot_label`/`format`/`extra_data`; mass assignment is closed.
- **Autosave writes the working state, not a revision row.** Saves replace
  `v_documents.content`/`yjs_state`; an autosave checkpoint is taken when
  `vaulter-documents.revisions.checkpoint_interval_seconds` (default 300) has passed. The 0.2 key
  `revisions.autosave_debounce_seconds` (and `vaulter.versions.autosave_debounce_seconds`) is
  deprecated and ignored with an `E_USER_DEPRECATED` notice — it described an in-place overwrite
  that no longer exists. `DocumentsConfig::autosaveDebounceSeconds()` →
  `checkpointIntervalSeconds()`.
- **Document content writes no longer bump `v_nodes.sequence`.** Content has its own validator,
  `v_documents.state_version` (+1 per effective write; identical saves are no-ops) and
  `collab_epoch` (moves on restore and on a JSON replace of Yjs state). `UpdateDocumentContentInput`
  takes `expectedStateVersion` instead of `expectedSequence` and a stale value throws
  `StaleDocumentStateException` (`412 document_state_mismatch`); `CreateNamedRevisionInput` lost
  `expectedSequence` (a named checkpoint changes no validator); `RestoreDocumentRevisionAction`
  takes `expectedStateVersion`. Code that relied on a document save invalidating a node
  `expectedSequence` must compare `state_version` instead.
- **Restore is copy-forward with provenance** for files and documents:
  `restored_from_revision_id` names the source revision. A restored document checkpoint is
  labelled `Restored from #N` (was `Restored from vN`).
- **Events keep their signatures.** `DocumentContentUpdated` fires only when a save changed
  something; `$version` is the head checkpoint after the save and `$created` says whether this
  save took it. `DocumentCreated::$version` is checkpoint #1.
- **Head foreign keys.** On PostgreSQL/MySQL `v_files_head_fk` and `v_documents_head_fk` refuse a
  head of another aggregate and the deletion of a head; SQLite relies on the Actions. Code that
  deletes nodes outside Vaulter's purge must clear `current_revision_id` first on MySQL.

### P1–P3 audit follow-ups (PLAN2 audit 2026-10-07)

- **Folder COPY needs View and Copy on every active descendant.** `nodes()->copy()` now refuses the
  whole copy with `PermissionDeniedException` (`permission_denied`, 403) when any included
  descendant is hidden from the actor or not copyable (for example an `inherit_grants=false`
  child without a grant), and writes nothing. A system actor still bypasses ACL only.
  `CopyNodeInput` gained an optional `authorizedNodeIds`; the Action refuses a descendant that
  appears after the Operations layer authorised the subtree. Grant the actor access to the
  child (or move it out of the folder) to copy.
- **Activity feeds hide who and where from readers without Share.** For an actor without the
  `Share` ability on the target node, `ip`, `user_agent`, `request_id` and `actor_id` are
  `null`, and `context` loses `subject_type`, `subject_id`, `permissions` and `token_prefix`.
  Share-capable readers and system actors see the full rows. The projection is read-only.
- **Upload completion retries deadlocks at the outermost level.** `UploadOperations::complete()`
  repeats the whole completion up to three times after a deadlock or serialization failure
  (staged bytes survive a rollback). When the budget is exhausted the response is
  `concurrent_update` (409, `Retry-After: 1`) instead of a 500; repeat the request unchanged.
  `VaulterDatabase::retryingOutermost()` is the reusable internal primitive.
- **Replaying a committed completion re-checks the mutation right** (`Edit` on the target file or
  `Create` in the parent), not only `View` on the result.
- **Core no longer reads other packages' configuration keys.** Scan work tuning
  (`lease_seconds`, `scanner_timeout_seconds`, `max_attempts`) reaches core through the internal
  `Vaulter\Contracts\Content\ScanWorkSettings` (bound by `vaulter-drive` to `DriveConfig`), and the
  comments switch through `Vaulter\Contracts\Profiles\CommentsFeature` (bound by `vaulter-documents`;
  core alone reports comments as off). Config files and keys are unchanged.
  `ScanConfig::workSetting()` was removed.
- **Scan work uses the database clock** for leases, due times and backoff; application clock skew
  no longer expires leases or makes work due early.
- **Package test harnesses** (`vaulter-drive`, `-documents`, `-media`) now share core's
  `SuiteDatabaseConnection`: an unknown `DB_CONNECTION` throws instead of silently running on
  SQLite, and a server database must end in `_test`.

### Strict tenant boundaries (AD10; PLAN2.P3.8)

`vaulter.tenancy.enabled` remains `false` by default. When enabled, set
`vaulter.tenancy.resolver` to a container-resolvable class implementing
`Vaulter\Contracts\Tenancy\TenantResolver::current(): int|string|null`.
Boot rejects missing, invalid or wrong-interface resolvers in every environment,
including production; boot permits `current() === null` for maintenance CLI.
Tenant operations refuse null context. Integer IDs normalize to decimal strings;
string IDs keep leading zeroes. Resolver instances are scoped to the request/job;
`current()` must read the host's current context each time.

Host adapter examples (use the tenant model configured by your host):

```php
final class SpatieTenantResolver implements \Vaulter\Contracts\Tenancy\TenantResolver
{
    public function current(): int|string|null
    {
        return \App\Models\Tenant::current()?->getKey();
    }
}

final class StanclTenantResolver implements \Vaulter\Contracts\Tenancy\TenantResolver
{
    public function current(): int|string|null
    {
        return tenant()?->getTenantKey();
    }
}
```

Set `vaulter.tenancy.resolver` to your adapter's FQCN. These adapters read existing
host state; host HTTP/queue middleware owns restoration. See
[Spatie current tenant](https://spatie.be/docs/laravel-multitenancy/v4/basic-usage/working-with-the-current-tenant)
and [Stancl current tenant](https://tenancyforlaravel.com/docs/v3/tenants/#accessing-the-current-tenant).

Strict mode supersedes ADR-0003's delegated-only tenancy and the warning-only
`TenantFootgunGuard`. Custom model scopes remain additional protection. Enable
strict mode only after assigning authoritative tenant IDs to existing drives and
children. Provisioning stamps the drive and node/file/revision/grant/share/activity/
upload rows; `ResolvesOwnerTenant::tenantOf(Model $owner)` optionally checks the
fresh owner at both provisioning and existing-drive lookup. An owner/profile
already attached to another tenant is denied and never duplicated.

System actors bypass ACL only. PHP handles, HTTP resolution, listings and private
scanner reads retain tenant restrictions. An anonymous bearer share derives only
its target drive scope after token, TTL, password/login and issuer checks; it does
not open ordinary user/system handles or all-tenant access.

Host queue middleware must restore host tenancy **before**
`Vaulter\Jobs\Middleware\RestoreTenantContext`. Vaulter validates the payload's
`driveId`/`tenantId` against the current host resolver and fresh drive; it does not
switch a database or call a fictional resolver setter. Missing/mismatched tenants
or deleted actors fail without calling the job body. Pass an `ActorRef` for user
jobs, obtain the rehydrated actor from that middleware instance, and use normal
Operations so execution uses current permissions. Scanner jobs carry their own
scope, validate immutable revision/blob/attempt identity, and have one attempt;
maintenance bypass is never propagated to a job.

`Vaulter::withoutTenancy(fn () => ...)` is an explicit trusted maintenance closure.
It is nested, fiber-local and restored in `finally`; ACL, profile, scan and data
invariants remain enforced. GC, reconcile, repair, purge-trash and sweep commands
use explicit maintenance closures. Scan recovery requires `--tenant` matching the
current context or explicit `--all-tenants --reason=...`; queued jobs still require
the host to restore each payload's tenant. No HTTP parameter controls this bypass.

### Uploads, scan policy and content delivery (AD13; PLAN2.P3.7, D26–D29)

Apply the additive `2026_10_06_000002_add_scan_evidence_and_work.php` migration before patched writers/workers start. It preserves existing revision MIME/status/data and adds independent scan admission, evidence/work/backfill and upload cleanup carriers. Do not roll it down while workers run. Run the migration against the configured Vaulter connection.

Use `$handle->extension(\Vaulter\Drive\Operations\UploadOperations::class)` for `initiate($input)`, `complete($sessionOrKey)` and `abort($sessionOrKey)`. `InitiateUploadInput` no longer carries storage or actor authority; internal upload actions accept `OperationContext` first. Complete remains under the session mutex through the outer transaction. A committed replay returns its stored node/revision result after fresh ownership/scope/View checks. It does not repeat ingest, events or work. Cleanup failure after commit preserves success and a durable cleanup flag.

Canonical `vaulter.scan` keys are `mode`, `required`, `scanner`, `scanner_key`, `scanner_version`, `policy_version`, `rollout_key`, with null defaults. The old `vaulter-drive.uploads.scan` policy keys are aliases when the canonical value is null; unequal explicit values fail configuration. `required=true` means `all_content`. Configured enforcement requires a host implementation of `ContentScanner` whose `key()` and `version()` exactly match the configuration, and whose `scan(ScanInput): ScanResult` borrows the supplied stream. Private scanner IO is separate from public Download authorization. Configure the broker retry window at least 180 seconds: scanner deadline < worker timeout 90 < lease 120 < broker retry window.

`off` removes mandatory evidence eligibility; it never permits infected/quarantined bytes. `all_content` blocks legacy `not_required` and bare `clean` until compatible versioned evidence exists. `new_content_only` requires an explicit immutable rollout key and persisted server admission boundary; client timestamps cannot grandfather content. Global and profile predicates each apply with their own boundary. Configure scanner identity in off mode, explicitly activate policy and prepare legacy evidence before a strict cutover, or explicitly choose the new-content rollout. Activation intentionally prewarms bounded legacy work even when those revisions are exempt under new-content mode. New required revisions always record their own durable work in their creation transaction. Mode changes retain compatible evidence; scanner/policy version changes require new evidence.

```bash
# Disabled-tenancy example; use --tenant=... for an enabled tenant scope.
php artisan vaulter:scan-rescan --drive=DRIVE --reason=prepare-scan-policy --activate-policy
php artisan vaulter:scan-rescan --drive=DRIVE --reason=prepare-legacy --limit=100 --json
php artisan vaulter:scan-reconcile --drive=DRIVE --reason=recover-scan-work --limit=100 --max-seconds=30 --json
```

Retain the returned UUID request id when resuming a signed `--after` cursor. Scope/spec/flag changes invalidate cursors. `--rescan` starts a new fenced generation; `--retry-failed` retries only failed work. `--dry-run` writes and publishes nothing. Enabled tenancy requires a selected tenant or explicit `--all-tenants` with a reason. Exit 0 reports scheduled/recovered intents; it does not claim that every revision is clean. Required legacy discovery survives activation/publication crashes through durable backfill checkpoints. Broker outage, lost jobs and expired leases are reclaimed; retry exhaustion stays failed until explicit operator action. Cleanup retries never re-ingest committed uploads.

```php
// Enabled-tenancy host example; schedules remain host-owned.
Schedule::command('vaulter:scan-reconcile --all-tenants --reason=scheduled-scan-recovery --limit=100 --max-seconds=30')
    ->everyMinute()->withoutOverlapping(2);
```

For disabled tenancy omit `--all-tenants` and retain an explicit reason. The database lease/CAS is authoritative across schedulers. No built-in antivirus adapter or cloud deployment is supplied. Deterministic `unsupported`, `too_large`, `password_protected`, `access_denied` and unknown failures never become clean. Manifests are unsupported by the single-blob scanner. Optional failed rescan retains still-valid earlier clean evidence; revocation/new epochs remove its eligibility. Infection quarantines the immutable blob and cannot be cleared by a late clean worker. No public quarantine-release workflow is introduced.

Uploads read chunks of at most 64 KiB from the current source position, count actual bytes/hash, enforce the effective limit while reading and persist detected MIME. Borrowed resource/PSR streams remain open. Own path/string handles and prepared temps close/discard on every path. MIME refinement only uses bounded verified container metadata. Registered `UploadInspector` keys must be unique; execution order is descending priority then ascending key. Inspectors cannot publish jobs before commit.

`ContentDelivery` mediates PHP and share reads using exact committed revision/blob/edge selection and fresh ACL/profile/availability. Metadata descriptors do not grant bytes or URLs; a pending head never substitutes an older clean revision. Protected HTTP content is `private, no-store`. `NodeExportService::export($context, $node, $format)` now requires explicit authority and selects only the actual inline document head; full immutable inline-document scanning remains P4-owned.

`vaulter.downloads.delivery_mode` defaults to `strict`; `directUrl()` rejects before a signer. Explicit `direct_signed` requires a trusted `DirectContentUrlSigner` signing the selected immutable key/version, with `direct_ttl_seconds` 1–300 (default 60). Public shares remain mediated. Revoke/quarantine blocks new gateway/sign requests, but an already-issued bearer URL remains usable until expiry or an adapter storage policy denies it; an already-started stream may continue. No immediate revocation claim or derivative/304 cache endpoint is introduced.

### Canonical authorized Drive API (AD06–AD08, AD13, AD17, AD23; PLAN2.P3.3)

The old facade entry points and flat handle methods are removed without aliases.
Replace `Vaulter::for($owner, $panel)` with `Vaulter::drive($owner, $profile)` and
`Vaulter::forStorage($storage)` with `Vaulter::drive($drive)`. Owner inputs must
have a persisted identity. Owner lookup provisions the drive/root when absent;
`Vaulter::findDrive($owner, $profile)` returns a nullable handle without creating
anything. An omitted profile selects the registry default. Existing Drive inputs
are reloaded by persisted key; an explicitly mismatched profile is rejected.

`actingAs($user)`, `asSystem($reason)` and `withCorrelationId($id)` return new
immutable handles; retain the returned value. An unbound handle resolves the
current guard user on every operation; no user produces `actor_required` (401).
Jobs and commands explicitly select `actingAs()` or `asSystem()`. System execution
records its reason and retains drive scope, profile restrictions and data
invariants. `model()`/`profile()` expose metadata; `root()` and node reads require
View. HTTP and Filament use the same guards: hidden/foreign nodes return 404;
a visible node without the requested ability returns 403. Move/copy require the
source capability and Create at the resolved destination.

```php
use Vaulter\Data\Drive\RevisionOptions;
use Vaulter\Data\Drive\UploadOptions;
use Vaulter\Data\Drive\UploadSource;
use Vaulter\Facades\Vaulter;

$drive = Vaulter::drive($project)->actingAs($user);
$folder = $drive->folders()->create('Reports');
$file = $drive->files()->upload(
    UploadSource::fromString('draft', 'report.txt'),
    new UploadOptions(parent: $folder),
);
$revision = $drive->files()->addRevision(
    $file,
    UploadSource::fromString('updated', 'report.txt'),
    new RevisionOptions(expectedSequence: $file->sequence),
);
$file = $drive->nodes()->find($file);
```

| Removed handle call | Replacement |
|---|---|
| `createFolder()` | `folders()->create()` |
| `upload()` | `files()->upload(UploadSource, ?UploadOptions)` |
| `uploadVersion()` | `files()->addRevision()`; returns `FileRevision`, not `Node` |
| `rename()` | `nodes()->rename()` |
| `move()` / `copy()` | `nodes()->move(destination: …)` / `copy(destination: …)`; use typed options |
| `trash($node)` | `nodes()->trash($node)` |
| `restore($node)` | `trash()->restore($node, ?RestoreOptions)` |
| `forceDelete($node)` | `trash()->purge($node)` |
| `list($folder)` | `nodes()->children($folder, ?ListOptions): NodePage` |

Remove per-call `actorId` arguments; identity belongs to the handle. The sub-APIs
also provide `nodes()->find/search/update/abilities`,
`files()->revisions/restoreRevision/read`, `trash()->list/empty`, and
`access()/shares()/activity()`. Revision restore appends immutable history and
returns `FileRevision`. `files()->read()` returns a `FileStream`; callers close
its stream in `finally`. HTTP downloads stream those same bytes and close them
after consumption. NodePage exposes `items`, `nextCursor` and `scanned`; page
sizes are 1–200, with signed forward cursors and no total. Discard old node-list
cursors. Extensions resolve with a named `handle` argument and must use its
authorized operations.

Upload/folder-create/move default to `OnNameConflict::Fail`; copy/restore default
to `Rename`. Rename considers the original name and five UTF-8-safe suffixes,
`name (2).ext` through `name (6).ext`, then throws `NodeNameConflictException`.
`AddRevision` is accepted only by uploads and requires Edit on the existing file.

Move document registry calls to `Vaulter\Documents\Facades\Documents`:

| Removed core facade call | Replacement |
|---|---|
| `Vaulter::registerDocumentType($key, $definition)` | `Documents::types()->register($key, $definition)` |
| `Vaulter::documentTypes()` | `Documents::types()->all()` |
| `Vaulter::registerExporter($exporter)` | `Documents::exporters()->register($exporter)` |
| `Vaulter::exporter($format)` | `Documents::exporters()->for($typeKey, $format)` |
| `Vaulter::exporterFormats()` | `Documents::exporters()->formatsFor($typeKey)` |

Definitions remain arrays, exporters remain instances or container-resolved
FQCNs, and configured exporters remain under `vaulter-documents.exporters`.

Run core migration `2026_10_06_000001_add_node_operation_sequence.php` when
upgrading an existing database. It adds `v_nodes.sequence` as signed BIGINT with
default 0 and a nonnegative constraint; existing nodes start at 0. The common
range is 0–9223372036854775807; PHP sequence APIs require a 64-bit runtime.
Changed existing nodes increment once per logical mutation under the same
transaction and locks. New nodes remain at 0. Current document autosave/Yjs
append and overwrite writers, named snapshots and restores participate too.
Supported PHP `expectedSequence` arguments/options stay optional. A mismatch
throws `StaleNodeException` (`node_sequence_mismatch`, 412) before side effects;
exhaustion throws `SequenceOverflowException` (`node_sequence_exhausted`, 409)
without wraparound. Effective no-ops compare supplied expectations but emit no
mutation event or increment. A new revision/head identity counts as a change even
with identical bytes. History-only retention, grants and shares do not increment
this editorial counter. Sequence alone is insufficient as a strong validator
for JSON containing other changing dependencies.

Purge physically deletes the trashed subtree, including document revision/content/
Yjs rows through foreign-key cascades in the same transaction. File lifecycle
cleanup detaches CAS edges and releases quota. `trash()->empty()` counts deleted
roots, skips unauthorized roots and processes each root independently; an
unexpected failure rolls back that root, reports it, continues, then rethrows
the first failure. The purge command returns a failing exit status after
processing remaining roots. Copy supports folders/files; a document or shortcut
anywhere in the requested subtree raises `UnsupportedNodeKindException` before
inserts. Rich Document/Comment aggregate operations, full scan admission and
strict tenant integration are outside this change.

### Authorized access, sharing and activity (AD08, AD12, AD17; PLAN2.P3.4)

Core `Operations\AccessOperations`, `ShareOperations` and `ActivityOperations`
now take an `OperationContext` and enforce current View plus Share/Manage,
scoped child identity and effective profile features. HTTP and Filament use
these same boundaries. Hidden targets return `node_not_found` (404); visible
nodes without the required ability return 403. Grants are independent of
`share_links`; their own `grants` feature must be enabled.

Use public `GrantInput` and `ShareLinkInput` without a caller-supplied actor.
Internal Actions now accept `execute(OperationContext, *Input)` with a located
node; grant revoke addresses one row and hard-deletes it. The existing HTTP
subject revoke retains its all-scopes/effects behavior. Share revocation retains
`revoked_at` and idempotence. Events carry ActorRef and `activity.context.correlation_id`; the request UUID
remains in `request_id`. They retain explicit system reason without attributing an ambient authenticated user.

Activity uses current target visibility, newest-first `(created_at,id)` order,
and signed forward cursors scoped to drive/tenant/node/filter. Old activity
cursors must be discarded. A scan-budget-limited empty page can still carry a
next cursor. No hidden-target totals or historical-payload authorization.
Only page size and cursor apply to activity ListOptions.

Anonymous share mint rejects Commenter and every raw Comment bit, including
level-derived masks; Writer/Organizer/Owner require an explicit mask without
Comment. TTL, use budget and password bounds are enforced on direct PHP calls.
The plaintext token is returned once; later reads expose metadata only.
New requests after revoke commit return 404; already-started streams can continue.
The legacy public-download scan hook accepts `not_required`/`clean`; versioned
required-mode evidence and complete egress qualification remain PLAN2.P3.7.

### Typed Operations and immutable inputs (AD06, AD17, AD18; PLAN2.P3.2)

Core adds seven typed Operations interfaces, immutable `Data\Drive` options,
`UploadSource`, bytes-only `FileStream`, and lifecycle/copy result contracts.
`Node|int|string` arguments and named options replace positional source/actor
arguments in the prepared target API. Existing DriveHandle/manager methods are
switched atomically in PLAN2.P3.3; these additive contracts do not activate them.
`GrantInput` excludes caller-supplied `grantedBy`; live grant/share DTO callers
migrate in PLAN2.P3.4.

Use `UploadSource::fromUploadedFile/fromPath/fromStream/fromString/fromPsrStream`.
Readers consume the current position and leave caller resources and PSR streams
open. Path/string readers belong to the package and must be released in `finally`.
Declared MIME/size are hints; trusted facts are computed by ingest in PLAN2.P3.7.
PSR streams stay typed and are read in chunks; core directly requires
`psr/http-message` (^1.1 or ^2.0). FileStream carries bytes and revision metadata.

Options support `make()` and immutable fluent setters. List/search page sizes
must be 1–200. Optional expectedSequence accepts nonnegative signed int64 values;
PHP calls retain the nullable default. `AddRevision` is permitted only on uploads;
other conflict options reject it. NodeSort applies to node lists; revision/activity
Operations validate their supported list fields when implemented.

### Profile permission drivers and Comment (PLAN2.P3.5, OPUS D11 / AD01)

`Permission::Comment = 32` is independent of Read. `Permission::all()` replaces
`allBits()` and the removed `All` case, returning 63. Reader/Commenter/Writer/
Organizer/Owner masks are 1/33/39/47/63; Reader cannot comment. Existing raw masks
are preserved; levels are expanded when read, so stored Owner levels include Comment.

`PermissionDriver::canCreateIn(Authorizable, AccessScope)` and
`AccessResolver::canCreateIn(Authorizable, Drive)` replace `canCreateInWorkspace`.
AccessScope now contains profile, driveOwner (OwnerRef), driveId, nodeOwner and
optional tenantId. OwnerRef uses the host morph alias and key. Gate abilities,
including create, receive one context array with `profile`, `drive_owner`,
`drive_id`, `node_owner`, `tenant_id`. Update host Gate callbacks and drivers.
Every permission path selects the drive profile's driver with the global key as
fallback. Driver instances are cached by key and configured class; access results
are recomputed on each call. Anonymous Commenter share requests are rejected;
public share mint enforcement remains PLAN2.P3.4. Hosts can reuse
`Testing\Contracts\PermissionDriverContractTests` on their own ACL fixtures.


### Frozen runtime profiles (AD05, PLAN2.P3.6, PLAN2.D26, PLAN2.D27)

Replace `Profile::id()/getId()` with `key()/getKey()`, `host()/hostModel()` with
`owners()/getOwners()`, and `withoutGrants()` with `grants(false)`. Replace arbitrary
`feature()/features()/featureEnabled()` calls with `grants()`, `shareLinks()`,
`comments()` and the effective `ProfilePolicy` feature reader. Keys match
`^[a-z0-9][a-z0-9-]{0,63}$`; rename underscore keys consistently in stored drive
profiles and provider configuration. `vaulter:make:profile` emits the canonical
builder and kebab keys.

Register provider FQCNs in `vaulter.profiles` before boot. Providers resolve once
through the container; duplicate keys fail boot, including an explicitly repeated
default key. Configure a replacement default provider via `profiles.default`.
Registry values are independent frozen snapshots. Mutating a returned profile or
registering after boot throws `LogicException`; the caller's original builder
remains independent. Configuration remains cacheable without closures.

Profile flags inherit global settings when unset. Explicitly enabling a globally
disabled feature fails boot. Runtime flags conjoin global and profile settings;
user View/ACL checks precede profile errors and system actors still obey profile
restrictions. Disabled grants remove their ACL contribution and preserve the host
role lane. Comment HTTP operations and document creation honor profile policy.
Owner restrictions apply before returning or provisioning a drive. Disk and quota
defaults affect new drives only; existing values remain stored. A nullable profile
quota inherits `vaulter-drive.quota.default_bytes` (default unlimited).

Upload size is the minimum limit, MIME lists are independent allow predicates,
and blocked extensions combine case-insensitively. `UploadPolicy` retains its
`requireScan` argument and appends optional `scanMode` and `scanPolicyVersion`.
Canonical `vaulter.scan` policy values default to null; matching
`vaulter-drive.uploads.scan` values are compatibility aliases. Unequal explicit
values fail boot. `required=true` means `all_content` and conflicts with an
explicit weaker mode. Global and profile eligibility remain independent; profile
`off` cannot subtract a global requirement. Persisted activation, scan evidence,
scanner bindings and admission consumers are supplied by PLAN2.P3.7; this
intermediate step is not a release qualification.

Trash retention uses each drive's frozen profile; `--days` is an explicit command
override. The revision retention helper keeps the current head and permanent
revisions, detaches obsolete CAS edges, preserves used bytes and the monotonic
revision allocation counter. Rewritten revision actions connect this helper in
PLAN2.P3.3/PLAN2.P3.7.

### Operation security foundations (AD05, AD07, PLAN2.P3.1)

Core now provides `Vaulter\ValueObjects\Access\Actor` and `ActorRef`,
`Vaulter\Operations\OperationContext` and `NodeLocator`, and
`Vaulter\Access\OperationGuard`. Existing DriveHandle calls retain their current
surface until the subsequent atomic handle migration.

Construct a user actor with `Actor::user($user)`, or explicitly select
`OperationContext::system($drive, 'nightly import')` in trusted jobs and commands.
System reasons are trimmed, nonempty and at most 191 bytes. User identities must
come from Eloquent or Authenticatable; changing a bound user's identity requires
a new actor. Jobs serialize `ActorRef` and the drive key, then resolve the user and
current permissions at execution. Serializing an `Actor` is rejected.

Use `NodeLocator::find($context, $key)` before authorizing an operation. Model
arguments contribute only their persisted key; the locator re-fetches the
configured node model on Vaulter's connection within the bound drive. Trashed
nodes require the explicit internal `includeTrashed` mode. The guard also
re-fetches node arguments, checks scope before system bypass, and evaluates View
before the requested capability. `visible()` rebinds and evaluates candidates in
batch, preserves input order and excludes inaccessible nodes. No ACL decisions
are cached across calls.

`MissingActorException` uses the normative `actor_required` code with HTTP 401;
hidden/foreign nodes use `node_not_found`/404, visible capability denial uses
`permission_denied`/403, and `ProfileRestrictionException` defines
`profile_restriction`/403. The existing JSON error renderer is retained.
`OperationKind` categorizes public operations and grants no private scanner
capability. Profile policy, strict tenancy and content availability connect in
their subsequent integration steps; these foundations do not activate them.
Authorization precedes mutation locks: revocation affects the next authorization
call, with the documented interval between authorization and lock acquisition.

### Package-owned configuration and typed access (AD26, P2.5)

Merge your published configuration into the new package files before removing the
old keys. Keep host overrides; publishing with `--force` would replace them.
The publication tags are `vaulter-config`, `vaulter-media-config`,
`vaulter-drive-config`, `vaulter-documents-config`, `vaulter-azgard-config` and
`vaulter-filament-config`. Rename the old Media file `config/vaulter-cas.php` to
`config/vaulter-media.php`, then move the core-owned disk/dedup/GC values into it.
Drive and Documents now merge under their own roots, rather than `vaulter.models`,
`vaulter.handlers` and `vaulter.exporters`.

Configuration compatibility applies to keys, not PHP imports. Replace the removed helpers:

| Removed API | Replacement |
|---|---|
| `Vaulter\Support\Config` | `Vaulter\Configuration\VaulterConfig` plus section classes |
| `Vaulter\Support\GateConfig`, `Vaulter\Support\SpatieConfig` | `Vaulter\Configuration\AccessConfig` |
| `Vaulter\Drive\Support\Uploads\UploadModels` | `Vaulter\Drive\Configuration\DriveConfig::model(string)` |
| `Vaulter\Documents\DocumentsModels` | `Vaulter\Documents\Configuration\DocumentsConfig::model(string)` |

Inject the `final readonly` configuration singletons into services. In models,
migrations and static contexts use `app(DatabaseConfig::class)`, etc. The classes
read `Illuminate\Contracts\Config\Repository` on every call, so test-time
`config()->set()` remains visible. `VaulterConfig` exposes `database()`, `ids()`,
`tenancy()`, `access()`, `routes()`, `listing()`, `sharing()`, `downloads()`,
`webhooks()` and `observability()`. Direct Vaulter-key reads outside `Configuration`
are prohibited in package code by architecture tests. There are no PHP class aliases.

The normalizer runs after installed package defaults merge and before provider boot.
Each old key emits `E_USER_DEPRECATED` once. An explicitly customized canonical value
that differs from the translated old value throws
`Vaulter\Exceptions\Configuration\InvalidConfigurationException`; equal values only
warn. Default canonical values can be replaced by old published values. Module keys
are normalized only when their owning package is installed. Deprecated key support
is removed in 1.0.

| Deprecated key | Canonical key |
|---|---|
| `vaulter.connection` | `vaulter.database.connection` |
| `vaulter.table_prefix` | `vaulter.database.table_prefix` |
| `vaulter.id_type / vaulter.ids.default` | `vaulter.ids.strategy` |
| `vaulter.tenant.enabled` | `vaulter.tenancy.enabled` |
| `vaulter.panels / vaulter.default_panel` | `vaulter.profiles.providers / vaulter.profiles.default` |
| `vaulter.features.reliability` | `vaulter.features.webhooks` |
| `vaulter.drivers.permission` | `vaulter.access.permission_driver` |
| `vaulter.permission_drivers` | `vaulter.access.permission_drivers` |
| `vaulter.gate.* / vaulter.spatie.*` | `vaulter.access.gate.* / vaulter.access.spatie.*` |
| `vaulter.azgard.*` | `vaulter-azgard.*` |
| `vaulter.route_prefix` | `vaulter.routes.prefix` |
| `vaulter.middleware` | `vaulter.routes.middleware.authenticated` |
| `vaulter.rate_limits.{public,uploads}.per_minute` | `vaulter.routes.rate_limits.{public,uploads}` |
| `vaulter.share_token_generator / vaulter.share.token_generator` | `vaulter.sharing.token_generator` |
| `vaulter.share.{url_base,prefix_length,default_ttl_hours,max_ttl_hours}` | `vaulter.sharing.{url_base,prefix_length,default_ttl_hours,max_ttl_hours}` |
| `vaulter.downloads.inline_mime_whitelist` | `vaulter.downloads.inline_mime_types` |
| `vaulter.storage.default_disk` | `vaulter-media.disk` |
| `vaulter.dedup.scope` | `vaulter-media.dedup.scope` |
| `vaulter.gc.*` | `vaulter-media.gc.*` |
| `vaulter.cas.{content_store,storage_driver,blob_store}` | `vaulter-media.{content_store,storage_driver,blob_store}` |
| `vaulter.uploads.max_bytes / vaulter.drive.max_upload_kb` | `vaulter-drive.uploads.max_file_size` |
| `vaulter.drive.allowed_mime` | `vaulter-drive.uploads.allowed_mime_types` |
| `vaulter.uploads.chunk_size` | `vaulter-drive.uploads.resumable.chunk_size` |
| `vaulter.uploads.max_chunk_bytes` | `vaulter-drive.uploads.resumable.max_chunk_size` |
| `vaulter.uploads.session_ttl_hours` | `vaulter-drive.uploads.resumable.session_ttl_hours` |
| `vaulter.uploads.max_active_sessions` | `vaulter-drive.uploads.resumable.max_active_sessions_per_actor` |
| `vaulter.uploads.metadata.*` | `vaulter-drive.uploads.resumable.metadata.*` |
| `vaulter.features.uploads / vaulter.uploads.tus.enabled` | `vaulter-drive.uploads.resumable.enabled` |
| `vaulter.trash.retention_days` | `vaulter-drive.trash.retention_days` |
| `vaulter.drive.name_rules.max_length` | `vaulter-drive.names.max_length` |
| `vaulter.max_document_size_kb` | `vaulter-documents.content.max_size_kb` |
| `vaulter.max_versions / vaulter.versions.max` | `vaulter-documents.revisions.limit` |
| `vaulter.versions.autosave_debounce_seconds` | `vaulter-documents.revisions.autosave_debounce_seconds` |
| `vaulter.features.comments` | `vaulter-documents.comments.enabled` |
| `vaulter.features.collaboration` | `vaulter-documents.collaboration.enabled` |
| `vaulter.collaboration.driver` | `vaulter-documents.collaboration.driver` |
| `vaulter.collaboration.channel_prefix` | `vaulter-documents.collaboration.broadcast.channel_prefix` |
| `vaulter.collaboration.webhook_secret` | `vaulter-documents.collaboration.hub.webhook_secret` |
| `vaulter.collaboration.webhook_timestamp_tolerance` | `vaulter-documents.collaboration.hub.timestamp_tolerance` |
| `vaulter.collaboration.drivers.hocuspocus.secret` | `vaulter-documents.collaboration.hub.jwt_secret` |
| `vaulter.collaboration.drivers.hocuspocus.{url,token_ttl}` | `vaulter-documents.collaboration.hub.{url,token_ttl}` |
| `vaulter.tiptap.*` | `vaulter-documents.tiptap.*` |
| `vaulter.exporters` | `vaulter-documents.exporters` |
| `vaulter.models.storage` | `vaulter.models.drive` |
| `vaulter.models.file_version / vaulter.models.file_version_blob` | `vaulter.models.file_revision / vaulter.models.file_revision_blob` |
| `vaulter.models.upload_session / vaulter.models.upload_part` | `vaulter-drive.models.upload_session / vaulter-drive.models.upload_part` |
| `vaulter.models.node_comment` | `vaulter-documents.models.node_comment` |
| `vaulter.models.node_document_version` | `vaulter-documents.models.document_revision` |
| `vaulter-filament.resources.storage` | `vaulter-filament.resources.drive` |

Migration details:

- `id_type = id` maps to `ids.strategy = bigint`; `ulid` maps to `ulid`.
  When both old ID keys exist, `ids.default` retains its previous precedence over
  `id_type`. Update `panels` classes to `ProfileProvider` if necessary; incompatible classes
  fail with a `vaulter:make:profile` hint.
- `dedup.scope = storage` maps to `drive`. The old `uploads.max_bytes` and
  `drive.max_upload_kb` limits are compared in bytes (`max_upload_kb × 1024`);
  different legacy limits log a warning and the smaller wins. A legacy `false`
  for either `features.uploads` or `uploads.tus.enabled` disables resumable uploads.
- If `share_token_generator` and `share.token_generator` both exist, a custom
  value wins over the other shipped default. Two different custom values throw
  `InvalidConfigurationException`; keep only `sharing.token_generator`.
- `vaulter.middleware` now sets only the authenticated stack. Put middleware for
  public links and signed hub callbacks in `routes.middleware.public` and `.hub`.
- Documents and revisions are enabled by installing `vaulter-documents`; blobs
  and file revisions are always enabled. `features.blobs`, `features.versions`
  and `features.document_versions` are removed. Their old `false` values now warn
  and are ignored; uninstall the Documents package if you do not want its surface.
- Comments default to **true** in `vaulter-documents.comments.enabled`. Explicit
  legacy `features.comments = false` is translated and stays false; configure the
  canonical key to disable comments in a fresh installation. Collaboration stays
  opt-in via `vaulter-documents.collaboration.enabled`.
- `tenant.column`, `drive.name_rules.no_separators_pattern` and `migrations.path`
  warn and are ignored. The tenant column is always `tenant_id`; separators are
  rejected by fixed code. Keep `table_names` only for the physical-table upgrade;
  `DatabaseConfig::legacyTable()` is its sole reader.
- `vaulter.enabled_document_types` and `vaulter.features.spreadsheets` remain core
  keys in this transition, read by `DocumentsConfig`. Exporters remain a
  **format → FQCN map** under `vaulter-documents.exporters`, not a list.
- Move TipTap allow-list overrides from core to `config/vaulter-documents.php`:
  `vaulter.tiptap.extra_nodes` becomes `vaulter-documents.tiptap.extra_nodes`,
  `extra_marks` becomes `vaulter-documents.tiptap.extra_marks`,
  `extra_attributes` becomes `vaulter-documents.tiptap.extra_attributes`, and
  `url_attributes` becomes `vaulter-documents.tiptap.url_attributes`. These settings
  remain additive to the built-in allow-list and retain URL-scheme validation.
- For old published keys, exact built-in FQCN strings shipped in 0.2 are translated
  to canonical implementations. Custom FQCN strings remain unchanged; update their
  PHP imports, base classes and contracts to canonical names yourself.

Boot now validates the permission-driver selection and contracts, the outbound URL
guard, token generator, model subclasses and profile providers. Profile keys must
be unique and the default profile must be registered. Closures are rejected. The
`null` permission driver is forbidden in production. When collaboration is enabled,
both `hub.jwt_secret` and `hub.webhook_secret` must be present and distinct:
production throws; other environments log a warning. Invalid observability recorder
classes log a warning and use a null implementation.

See the English and Russian configuration guides for the current package layouts.

### AzGuard bridge, media commands and Filament Drive resource (P2.4)

Install the separate bridge before selecting `VAULTER_PERMISSION_DRIVER=azgard`:

```bash
composer require axioma-studio/vaulter-azgard
```

Its discovered provider registers `vaulter.access.permission_drivers.azgard` when the host
has not explicitly set that key, and binds the real guard adapter. Remove the former
AzGuard driver entry from a published core config unless it is an intentional custom
override. Without the bridge, `azgard` is an unknown driver and P2.5 boot validation raises
`InvalidConfigurationException`.

Replace the following FQNs; no aliases are provided:

| Before | After |
|---|---|
| `Vaulter\Access\Drivers\AzgardPermissionDriver` | `Vaulter\Azgard\AzgardPermissionDriver` |
| `Vaulter\Contracts\Access\AzgardGuard` | `Vaulter\Azgard\Contracts\AzgardGuard` |
| `Vaulter\Support\AzgardConfig` | `Vaulter\Azgard\AzgardPermissionMap` |
| `Vaulter\Testing\FakeAzgardGuard` | `Vaulter\Azgard\Testing\FakeAzgardGuard` |

Move the former `vaulter.azgard.permissions` and `vaulter.azgard.create_permission`
settings to `vaulter-azgard.permissions` and `vaulter-azgard.create_permission` in
`config/vaulter-azgard.php`. The optional panel context remains
`vaulter-azgard.panel_id`; `null` lets AzGuard resolve it from the permission key.
`Vaulter\Azgard\Configuration\AzgardConfig` reads these settings at each call, and
`AzgardPermissionMap` receives it through its constructor. P2.5 now translates the
old keys with deprecation notices. The driver registry is `vaulter.access.permission_drivers`.

The user model must implement `Vaulter\Azgard\Contracts\AzgardBackedUser` and align
workspace/owner morph types with AzGuard/Corex. Fake-based tests require the installed
bridge and all its Composer dependencies, even when no live AzGuard checks run.

Update schedules and CLI invocations from `vaulter:gc-blobs` to `vaulter:blobs:gc`
and from `vaulter:reconcile-blobs` to `vaulter:blobs:reconcile`. Options and the
`GcBlobsCommand` / `ReconcileBlobsCommand` class names stay the same; old signatures
have no aliases.

Filament resource imports become `Vaulter\Filament\Resources\DriveResource`,
`Vaulter\Filament\Resources\DriveResource\Pages\ListDrives` and
`Vaulter\Filament\Resources\DriveResource\Pages\ViewDrive`. Replace the former
`StorageResource`, `ListStorages` and `ViewStorage` names and change
`vaulter-filament.resources.storage` to `vaulter-filament.resources.drive`.
The resource's navigation and view labels are Drive. P2.5 translates the old toggle;
`PanelActor` and `PanelAccessScope` keep their names.

### Document names and collaboration (AD03, AD04)

Replace these document FQNs. The former names have no aliases. Controller
class names and actions stay the same under their domain namespaces. Request
names, routes, JSON fields, error codes, the `node_document_version` model key,
and the `v_node_document_versions` table and its `version_no` column stay the same.

| Before (`Vaulter\Documents\`) | After (`Vaulter\Documents\`) |
|---|---|
| `Models\Versions\NodeDocumentVersion` | `Models\Revisions\DocumentRevision` |
| `Repositories\Versions\NodeDocumentVersionReadRepository` | `Repositories\Revisions\DocumentRevisionReadRepository` |
| `Repositories\Versions\NodeDocumentVersionStoreRepository` | `Repositories\Revisions\DocumentRevisionStoreRepository` |
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
| `Events\Comments\NodeCommentAdded` | `Events\Comments\CommentAdded` |
| `Events\Comments\NodeCommentResolved` | `Events\Comments\CommentResolved` |
| `Events\Comments\NodeCommentDeleted` | `Events\Comments\CommentDeleted` |
| `Exceptions\NodeDocumentVersionNotFoundException` | `Exceptions\DocumentRevisionNotFoundException` |
| `Exceptions\NotADocumentNodeException` | `Exceptions\NotADocumentException` |
| `Exceptions\InvalidTipTapContentException` | `Exceptions\InvalidDocumentContentException` |
| `Contracts\Collaboration\NodeCollaborationDriver` | `Contracts\CollaborationDriver` |
| `Contracts\Collaboration\HostUserResolver` | `Contracts\HostUserResolver` |
| `Contracts\Export\ExportFilenameResolver` | `Contracts\ExportFilenameResolver` |
| `Collaboration\NodeCollaborationDriverManager` | `Collaboration\CollaborationDriverManager` |
| `Collaboration\HocuspocusNodeCollaborationDriver` | `Collaboration\HocuspocusCollaborationDriver` |
| `Http\Resources\NodeDocumentVersionResource` | `Http\Resources\DocumentRevisionResource` |
| `Http\Controllers\DocumentNodeController` | `Http\Controllers\Documents\DocumentNodeController` |
| `Http\Controllers\NodeCommentController` | `Http\Controllers\Comments\NodeCommentController` |
| `Http\Controllers\NodeCollaborationController` | `Http\Controllers\Collaboration\NodeCollaborationController` |
| `Collaboration\ReverbNodeCollaborationDriver` | Removed: use Hocuspocus |

Reverb for Yjs collaboration is removed. Use `hocuspocus` and configure its hub
URL and JWT secret. Remove `collaboration.drivers.reverb` and
`VAULTER_COLLAB_CONNECTION`; `Config::reverbConnection()` and
`CollaborationDriverType::Reverb` are removed. The Hocuspocus session keeps
`connection: null`. Comment broadcast channels and their access checks remain.

### Drive names and handle methods (AD03, AD04)

Drive classes use domain namespaces. Replace the following FQNs; the old names
and removed classes have no aliases. Routes, middleware and JSON fields retain
their current behavior. The P3.3 canonical API section above supersedes the old
entry points: replace `Vaulter::for()` / `forStorage()` with `Vaulter::drive()`.

| Before | After |
|---|---|
| `Vaulter\Drive\DriveContext` | `Vaulter\Drive\DriveHandle` |
| `Vaulter\Drive\DriveManager` | `Vaulter\Drive\DriveHandleFactory` |
| `Vaulter\Drive\StorageManager` | `Vaulter\Drive\DriveProvisioner` |
| `Vaulter\Drive\Actions\ForceDeleteNodeAction` | `Vaulter\Drive\Actions\Trash\PurgeNodeAction` |
| `Vaulter\Drive\Actions\UploadFileAction` | `Vaulter\Drive\Actions\Files\IngestFileAction` |
| `Vaulter\Drive\Actions\Uploads\CommitUploadAction` | `Vaulter\Drive\Actions\Uploads\CompleteUploadAction` |
| `Vaulter\Drive\Data\CreateDriveFolderData` | `Vaulter\Drive\Data\Nodes\CreateFolderInput` |
| `Vaulter\Drive\Data\UploadFileData` | `Vaulter\Drive\Data\Files\IngestFileInput` |
| `Vaulter\Drive\Data\Uploads\UploadInitData` | `Vaulter\Drive\Data\Uploads\InitiateUploadInput` |
| `Vaulter\Drive\Events\StorageCreated` | `Vaulter\Drive\Events\Drives\DriveCreated` |
| `Vaulter\Drive\Events\NodeCreated` | `Vaulter\Drive\Events\Nodes\FolderCreated` |
| `Vaulter\Drive\Events\NodeForceDeleted` | `Vaulter\Drive\Events\Nodes\NodePurged` |
| `Vaulter\Drive\Events\FileUploaded` | `Vaulter\Drive\Events\Files\FileUploaded` |
| `Vaulter\Drive\Events\NodeVersionCommitted` | `Vaulter\Drive\Events\Files\FileRevisionAdded` |
| `Vaulter\Drive\Exceptions\CrossStorageMoveException` | `Vaulter\Drive\Exceptions\Nodes\CrossDriveOperationException` |
| `Vaulter\Drive\Exceptions\CycleException` | `Vaulter\Drive\Exceptions\Nodes\NodeCycleException` |
| `Vaulter\Drive\Exceptions\QuotaExceededException` | `Vaulter\Drive\Exceptions\Quota\QuotaExceededException` |
| `Vaulter\Drive\Http\Controllers\StorageController` | `Vaulter\Drive\Http\Controllers\Drives\ShowDriveController` |
| `Vaulter\Drive\Http\Controllers\Uploads\TusUploadController` | `Vaulter\Drive\Http\Controllers\Uploads\TusProtocolController` |
| `Vaulter\Drive\Http\Controllers\Uploads\S3MultipartController` | `Vaulter\Drive\Http\Controllers\Uploads\S3MultipartProtocolController` |
| `Vaulter\Drive\Http\Resources\StorageResource` | `Vaulter\Drive\Http\Resources\DriveResource` |
| `Vaulter\Drive\Http\Resources\FileVersionResource` | `Vaulter\Drive\Http\Resources\FileRevisionResource` |
| `Vaulter\Drive\Support\Uploads\MagicByteSniffer` | `Vaulter\Drive\Uploads\Inspection\MimeSniffer` |
| `Vaulter\Drive\Support\Uploads\StagedUpload` | `Vaulter\Drive\Uploads\StagedUpload` |
| `Vaulter\Drive\Actions\CreateFolderAction` | `Vaulter\Drive\Actions\Nodes\CreateFolderAction` |
| `Vaulter\Drive\Actions\RenameNodeAction` | `Vaulter\Drive\Actions\Nodes\RenameNodeAction` |
| `Vaulter\Drive\Actions\MoveNodeAction` | `Vaulter\Drive\Actions\Nodes\MoveNodeAction` |
| `Vaulter\Drive\Actions\CopyNodeAction` | `Vaulter\Drive\Actions\Nodes\CopyNodeAction` |
| `Vaulter\Drive\Actions\TrashNodeAction` | `Vaulter\Drive\Actions\Trash\TrashNodeAction` |
| `Vaulter\Drive\Actions\RestoreNodeAction` | `Vaulter\Drive\Actions\Trash\RestoreNodeAction` |
| `Vaulter\Drive\Data\RenameNodeData` | `Vaulter\Drive\Data\Nodes\RenameNodeInput` |
| `Vaulter\Drive\Data\MoveNodeData` | `Vaulter\Drive\Data\Nodes\MoveNodeInput` |
| `Vaulter\Drive\Data\CopyNodeData` | `Vaulter\Drive\Data\Nodes\CopyNodeInput` |
| `Vaulter\Drive\Events\NodeRenamed` | `Vaulter\Drive\Events\Nodes\NodeRenamed` |
| `Vaulter\Drive\Events\NodeMoved` | `Vaulter\Drive\Events\Nodes\NodeMoved` |
| `Vaulter\Drive\Events\NodeCopied` | `Vaulter\Drive\Events\Nodes\NodeCopied` |
| `Vaulter\Drive\Events\NodeTrashed` | `Vaulter\Drive\Events\Nodes\NodeTrashed` |
| `Vaulter\Drive\Events\NodeRestored` | `Vaulter\Drive\Events\Nodes\NodeRestored` |
| `Vaulter\Drive\Events\UploadSessionInitiated` | `Vaulter\Drive\Events\Uploads\UploadInitiated` |
| `Vaulter\Drive\Events\UploadSessionAborted` | `Vaulter\Drive\Events\Uploads\UploadAborted` |
| `Vaulter\Drive\Events\UploadSessionExpired` | `Vaulter\Drive\Events\Uploads\UploadExpired` |
| `Vaulter\Drive\Exceptions\OffsetMismatchException` | `Vaulter\Drive\Exceptions\Uploads\OffsetMismatchException` |
| `Vaulter\Drive\Exceptions\StagedUploadMissingException` | `Vaulter\Drive\Exceptions\Uploads\StagedUploadMissingException` |
| `Vaulter\Drive\Exceptions\UploadConflictException` | `Vaulter\Drive\Exceptions\Uploads\UploadConflictException` |
| `Vaulter\Drive\Exceptions\UploadContentMismatchException` | `Vaulter\Drive\Exceptions\Uploads\UploadContentMismatchException` |
| `Vaulter\Drive\Http\Requests\CreateFolderRequest` | `Vaulter\Drive\Http\Requests\Folders\CreateFolderRequest` |
| `Vaulter\Drive\Http\Requests\UploadFileRequest` | `Vaulter\Drive\Http\Requests\Files\UploadFileRequest` |
| `Vaulter\Drive\Http\Requests\CopyNodeRequest` | `Vaulter\Drive\Http\Requests\Nodes\CopyNodeRequest` |
| `Vaulter\Drive\Http\Requests\MoveNodeRequest` | `Vaulter\Drive\Http\Requests\Nodes\MoveNodeRequest` |
| `Vaulter\Drive\Http\Requests\RenameNodeRequest` | `Vaulter\Drive\Http\Requests\Nodes\RenameNodeRequest` |
| `Vaulter\Drive\ValueObjects\WorkspaceRef` | Removed; unused |
| `Vaulter\Drive\Enums\BulkAction` | Removed; unused |
| `$handle->storage(): Drive` | `$handle->model(): Drive` |
| `$handle->panel(): string` | `$handle->profile(): Profile` |

`profile()` returns the registered `Vaulter\Profiles\Profile` object. Use
`$handle->profile()->getId()` when a string profile ID is needed. Both the core
interface and the drive implementation expose these methods; update typed callers.

The drive provider delegates bindings and HTTP registration to individually tested
`Providers\BindingsRegistrar` and `Providers\HttpRegistrar`.


### Canonical schema 0.3 (P2.7)

Back up the database and object store before running `php artisan migrate --force`
in production. Stop application writes during the code/schema upgrade; deploy the
canonical code and migrate stored events together. Migration №1 refuses soft-deleted
nodes, drives or files, documents without revisions, and own-tables installations.
It lists offending rows/tables and recommends `vaulter:upgrade:inspect` (planned for
P7; it is not shipped yet). Resolve these conditions before retrying.

The existing base migration filenames stay unchanged. Fresh installs create all
core tables regardless of feature flags; those flags still control runtime behavior.
The upgrade sequence includes the existing №3 host keys, №6 second-root repair and
№7 blob scopes between these new steps:

| Migration | Change |
|---|---|
| №1 `2026_10_01_000001_vaulter_preflight` | Read-only safety checks before any rename. |
| №2 `…000002_rename_storages_to_drives` | Normalize legacy table overrides, rename `storages` to `drives`, `panel` to `profile` and `storage_id` to `drive_id`; create missing core tables. |
| №4 `…000004_file_revisions` | `file_versions` / `file_version_blobs` become `file_revisions` / `file_revision_blobs`; `version_no` → `number`, `content_sha256` → `sha256`, `file_version_id` → `file_revision_id`. File handles use `current_revision_id` / `revision_count`. Upload sessions use `target_node_id` and nullable result references. |
| №8 `…000008_grants_shares_cleanup` | Hard-delete soft-deleted grants; preserve share revocation as `COALESCE(revoked_at, deleted_at)`; remove deleted/inherited columns and use an ordinary grant UNIQUE. |
| №9 `…000009_events` | Convert activity/outbox/delivery events and endpoint subscription lists; add activity occurrence ID and nullable outbox aggregate columns. |

`DatabaseConfig::table()` now always returns prefix + canonical entity name. Old
`vaulter.table_names` is read only by `DatabaseConfig::legacyTable()` during upgrade.
Drive/File/NodeGrant/NodeShare models no longer use Eloquent `SoftDeletes`.
`Profile::storageType()` and `storageTypeKey()` are removed. HTTP JSON keys such as
`storage_id`, `version_no`, `content_sha256` and `panel` stay unchanged until P6.1;
removed drive metadata `slug` and `storage_type` return null.

Rollback restores historical names, structure and event values, but cannot recover
hard-deleted grants, deleted drive metadata (`slug`, `storage_type`,
`default_node_permissions`), or the original grant inheritance fields. Reintroduced
columns use defaults (`storage_type=private`, permissions=31, inherited=false,
nullable fields=null). Share revocation and №6 tree repairs remain in effect. Tables
created for previously disabled features remain. Roll back the full upgrade batch to
reach migration №2: `--step=5` only reaches №4 when all eight upgrade migrations run.
Fresh-install rollback leaves canonical names for the base migrations to drop.

Webhook consumers must update these event names. Subscription conversion preserves
order, duplicates, wildcards and third-party names:

| 0.2 | 0.3 |
|---|---|
| `storage.created` | `drive.created` |
| `node.created` | `folder.created` |
| `version.created` | `file.revision_added` |
| `node.force_deleted` | `node.purged` |
| `upload_session.initiated/aborted/expired` | `upload.initiated/aborted/expired` |

Node document versions and the node's `current_version_id`, `file_id`, `deleted_at`
remain until P4.1. Historical PostgreSQL bigint and ULID fixtures are supported;
MySQL fresh installation still has the known generated-column/FK blocker owned by
P8.1 (see [database compatibility](docs/guide/database-compatibility.md)).


### Canonical PHP API 0.3 (P2.1; AD03, AD04, AD05, AD11, AD14)

Every FQN below is renamed outright: no `class_alias` and no deprecated wrappers.
Table and column names, configuration keys and event values keep their old names in this step;
their move is described by the separate schema and configuration changes.

| Old | New |
|:--|:--|
| `Vaulter\Models\Drive\Storage` | `Vaulter\Models\Drive\Drive` |
| `Vaulter\Models\Drive\FileVersion` | `Vaulter\Models\Drive\FileRevision` |
| `Vaulter\Models\Drive\FileVersionBlob` | `Vaulter\Models\Drive\FileRevisionBlob` |
| `Vaulter\Panels\Panel` | `Vaulter\Profiles\Profile` |
| `Vaulter\Panels\StoragePanelProvider` | `Vaulter\Profiles\ProfileProvider` |
| `Vaulter\Panels\DefaultStoragePanel` | `Vaulter\Profiles\DefaultProfile` |
| `Vaulter\Panels\PanelRegistry` | `Vaulter\Profiles\ProfileRegistry` |
| `Vaulter\Schema\PanelTablesBuilder` | `Vaulter\Schema\SchemaBuilder` |
| `Vaulter\Support\VaulterSchema` | `Vaulter\Schema\VaulterSchema` |
| `Vaulter\Console\MakePanelCommand` | `Vaulter\Console\MakeProfileCommand` |
| `Vaulter\Console\RelayOutboxCommand` | `Vaulter\Console\RelayWebhooksCommand` |
| `Vaulter\Contracts\Access\HostRoleDriver` | `Vaulter\Contracts\Access\PermissionDriver` |
| `Vaulter\Access\HostRoleDriverManager` | `Vaulter\Access\PermissionDriverManager` |
| `Vaulter\Testing\NullRoleDriver` | `Vaulter\Testing\NullPermissionDriver` |
| `Vaulter\Exceptions\Access\MissingHostRoleDriverException` | `Vaulter\Exceptions\Access\MissingPermissionDriverException` |
| `Vaulter\Access\ValueObjects\AclScope` | `Vaulter\Access\ValueObjects\AccessScope` |
| `Vaulter\Access\Abilities\NodeAbilities` | `Vaulter\Access\ValueObjects\NodeAbilities` |
| `Vaulter\Contracts\Handlers\HandlerRegistry` | `Vaulter\Contracts\Handlers\FileHandlerRegistry` |
| `Vaulter\Contracts\Handlers\NodeHandlerResolver` | `Vaulter\Contracts\Handlers\NodePresenter` |
| `Vaulter\Engines\DocumentHandlerRegistry` | `Vaulter\Handlers\FileHandlerRegistry` |
| `Vaulter\Engines\NodeHandlerResolver` | `Vaulter\Handlers\NodePresenter` |
| `Vaulter\Engines\DocumentFileDescriptorFactory` | `Vaulter\Handlers\FileDescriptorFactory` |
| `Vaulter\Engines\Handlers\AbstractFileHandler` | `Vaulter\Handlers\AbstractFileHandler` |
| `Vaulter\Engines\Handlers\PreviewHandler` | `Vaulter\Handlers\Builtin\PreviewHandler` |
| `Vaulter\Engines\Handlers\DownloadOnlyHandler` | `Vaulter\Handlers\Builtin\DownloadOnlyHandler` |
| `Vaulter\ValueObjects\Handlers\HandlerUi` | `Vaulter\ValueObjects\Handlers\ViewerDescriptor` |
| `Vaulter\ValueObjects\Handlers\HandlerDescriptor` | `Vaulter\ValueObjects\Handlers\NodePresentation` |
| `Vaulter\ValueObjects\Handlers\HandlerCapabilities` | `Vaulter\ValueObjects\Handlers\ContentCapabilities` |
| `Vaulter\Exceptions\Support\TenantIsolationNotConfiguredException` | `Vaulter\Exceptions\Tenancy\TenancyMisconfiguredException` |
| `Vaulter\Drivers\Permission\DatabasePermissionResolver` | `Vaulter\Access\Drivers\DatabasePermissionDriver` |
| `Vaulter\Drivers\Permission\GatePermissionResolver` | `Vaulter\Access\Drivers\GatePermissionDriver` |
| `Vaulter\Drivers\Permission\SpatiePermissionResolver` | `Vaulter\Access\Drivers\SpatiePermissionDriver` |
| `Vaulter\Drivers\Permission\AzgardPermissionResolver` | `Vaulter\Access\Drivers\AzgardPermissionDriver` |
| `Vaulter\Exceptions\Panels\InvalidPanelException` | `Vaulter\Exceptions\Profiles\InvalidProfileException` |
| `Vaulter\Exceptions\Panels\UnknownPanelException` | `Vaulter\Exceptions\Profiles\UnknownProfileException` |
| `Vaulter\Repositories\Drive\StorageReadRepository` | `Vaulter\Repositories\Drive\DriveReadRepository` |
| `Vaulter\Repositories\Drive\StorageStoreRepository` | `Vaulter\Repositories\Drive\DriveStoreRepository` |
| `Vaulter\Repositories\Drive\FileVersionReadRepository` | `Vaulter\Repositories\Drive\FileRevisionReadRepository` |
| `Vaulter\Repositories\Drive\FileVersionStoreRepository` | `Vaulter\Repositories\Drive\FileRevisionStoreRepository` |
| `Vaulter\Repositories\Drive\FileVersionBlobStoreRepository` | `Vaulter\Repositories\Drive\FileRevisionBlobStoreRepository` |
| `Vaulter\Enums\Activity\ActivityVerb` | `Vaulter\Enums\Events\EventType` |
| `Vaulter\Enums\Webhooks\WebhookEvent` | `Vaulter\Enums\Events\EventType` |

- `Vaulter::panel()/panels()/registerPanel()` are replaced by `Vaulter::profiles()->get()/register()/registerProvider()`;
  `Vaulter::registerHandler()` by `Vaulter::handlers()->register()` (AD03, AD14).
- `FileHandler::id()/ui()` are replaced by `key()/viewer()`. Keys use the `vendor/name` format:
  `vaulter/preview`, `vaulter/download`, `vaulter/tiptap`, `vaulter/univer`.
  `ViewerDescriptor` carries `key` and `props`; Vue component names and `route` are removed (AD14).
- `NodePresenter::describe(Node, ?string)` is replaced by `present(Node)` (AD14).
- `SchemaBuilder` builds the shared schema through `createTable(string $entity)/dropTable(string $entity)`, with no profile (AD05).
- `ProfileProvider::panel()` is replaced by `profile()`. The own-tables API (`tablePrefix`, `tables`, `models`,
  `idStrategy` and their getters), `HasVaulterPanel`, `PanelMigrationGenerator`, `PanelTableResolver`,
  `vaulter:panel:migrations` and `PermissionLevel` are removed (AD05, AD11).
- The hooks `pipes()/resource()/fillNode()/presentNode()/booted()` are removed. `booted()` used to
  be called after registration: move any host logic it carried into a service provider.
  Its removal is an API change, not a claim that it was never called (AD05).
- `vaulter:make-panel` is replaced by `vaulter:make:profile`; the generator writes the provider to `app/Vaulter/Profiles`.
  The own-tables flags `--prefix/--id` are removed. `vaulter:relay-outbox` keeps its signature (AD03, AD05).
- The container tag `vaulter.handlers` is replaced by `vaulter.file_handlers`; the config key `vaulter.handlers` is kept for now (AD14).
- `EventType::webhookTypes()` returns the subscribable types without `Upload*`; `cases()` includes the upload lifecycle events.
  Backing values stay the 0.2 values; `StorageCreated/NodeCreated/VersionCreated/NodeForceDeleted`
  are replaced by `DriveCreated/FolderCreated/FileRevisionAdded/NodePurged` (AD03, AD04).

The remaining core inputs also use their canonical names (OPUS 04 §3):

| Old | New |
|:--|:--|
| `Vaulter\Data\Access\GrantNodeAccessData` | `Vaulter\Data\Access\GrantNodeAccessInput` |
| `Vaulter\Data\Sharing\CreateShareLinkData` | `Vaulter\Data\Sharing\ShareLinkInput` |
| `Vaulter\Data\Webhooks\RegisterWebhookEndpointData` | `Vaulter\Data\Webhooks\RegisterWebhookEndpointInput` |
| `Vaulter\Data\Webhooks\UpdateWebhookEndpointData` | `Vaulter\Data\Webhooks\UpdateWebhookEndpointInput` |

### Prepared CAS commit and atomic quota (AD21)

Custom implementations of `Vaulter\Contracts\Cas\BlobStore` must add
`commitStaged(StagedObject $staged, BlobScope $scope, ?string $disk = null): Blob`.
It commits prepared bytes without discarding the staged object. Keep `commit()`
as stage → commitStaged → discard in `finally`. The outer upload owns cleanup
and reuses prepared bytes on transaction retries.

Quota reservations now run before CAS promotion. Subtree copies that exceed quota
throw the existing `Vaulter\Drive\Exceptions\Quota\QuotaExceededException` and roll
back completely. The core repository throws its common base,
`Vaulter\Exceptions\Drive\QuotaExceededException`; Drive Actions preserve the
existing exception type and HTTP 413 `quota_exceeded` response.


### Lossless CAS scope and migration №7 (AD20)

Replace `BlobStore::commit($stream, tenantId: ..., storageId: ...)` with
`commit($stream, BlobScope::forUpload($dedupScope, $tenantId, (string) $storageId), $disk)`.
`ContentStore::keyFor()` now receives `Vaulter\ValueObjects\Cas\BlobScope`.
Host tenant IDs and drive IDs remain strings without numeric coercion. Tenant scope
with no tenant uses `BlobScope::global()`. Scope IDs contain 1–96 bytes; IDs outside
`[A-Za-z0-9_-]{1,64}` are encoded as unpadded base64url in physical paths.

Migration `2026_10_01_000007_blob_scope_key` backfills the NOT NULL `scope_key`
and replaces the dedup index with `UNIQUE(scope_key, sha256)`. It preserves all
existing `storage_key` values and objects. A uniqueness conflict stops migration
before the old index is removed; investigate the conflicting rows before retrying.
Custom blob schemas must also provide `scope_key`.

After the SQL migrations, inspect and repair mixed legacy areas:

```bash
php artisan vaulter:blobs:repair-scopes --dry-run
php artisan vaulter:blobs:repair-scopes --execute
```

Exactly one mode is required. Review `hash_mismatch` and `unreferenced` reports;
these blobs remain unchanged. Each target commits independently, so a failed copy
can be retried. Old objects stay until GC; successful moves are journalled in
`storage/logs/vaulter-repair-scopes.log`. Target scope comes from revision → file →
node → storage; no tenant is guessed. A storage with NULL tenant belongs to the
host-wide global scope. Reads always use the disk and path recorded on the blob.
Run with operational access to all storages in the affected panel, and pause other
operators changing tenant assignments during repair.

The migration's `down()` restores the historical structure using the current
`dedup.scope`; it does not undo object copies or redirected edges. If repaired
rows conflict with the old index, rollback fails before removing `scope_key`.
Take a backup before upgrading or repairing data.

### Exact listing and shared cursors (AD12)

Before: children, search, trash and Filament exposed grant-reachable candidates,
including denied nodes; `shared` used `page`, `current_page` and `total`.
After: all these surfaces and folder `show` children apply effective `View` for every
driver. REST listings return `meta.next_cursor`, `per_page`, `scanned`, `path` and a
null `prev_cursor`; `total` and numbered page links are omitted. Replace shared
page-number iteration with `cursor` iteration, and continue through empty pages
while `next_cursor` is non-null. `vaulter.listing.scan_budget` defaults to 1000;
raise it to inspect more candidates per request. Invalid or cross-query cursors
return 422 `validation_failed` with `errors.cursor`.

New PHP surface: `AccessResolver::evaluateMany()`, `AclFolder`, `NodePage` and
`ExactListing`. Listing resource abilities use the batch grant sets.

### Tree invariants, second-root repair and migration №6 (AD13)

Before: Actions accepted any node of the drive as a parent, so a folder, upload, move,
copy or document could land inside a file, a document or a trashed folder; a `null`
parent in `CreateDocumentAction` (and in Drive Actions called directly) wrote a
second parentless node that the root listing never showed; the root could be renamed,
moved or trashed; two concurrent moves could form a cycle; `forceDelete` purged live
nodes; restoring or trashing twice was a silent no-op. After:

- A parent or destination must be a live folder of the same drive, checked under row
  locks inside the transaction: otherwise `InvalidDestinationException`
  (409, `invalid_destination`); a folder of another drive raises
  `CrossStorageMoveException` (409, `cross_drive_operation`) from Drive Actions and
  `InvalidDestinationException` from `CreateDocumentAction`.
- A `null` destination always means the drive root (`root_node_id`).
- The root cannot be renamed, moved, copied, trashed or force-deleted (409, `invalid_destination`).
- Trashing a node already in the trash raises `InvalidDestinationException`; restoring or
  force-deleting a node that is not in the trash raises `NodeNotTrashedException`
  (409, `node_not_trashed`). Delete a live node by trashing it first.
- Every tree write takes the drive tree lock as its first statement: moves, restores, trashes
  and force deletes exclusively (`VaulterDatabase::lockTree()`), creates, uploads, copies and
  renames shared (`VaulterDatabase::lockTreeShared()`, new). Concurrent moves can no longer form a
  cycle (`CycleException`, 409, `node_move_cycle`) and a create cannot slip under a folder being
  trashed. On PostgreSQL the shared lock keeps uploads concurrent with each other but a structural
  operation waits for in-flight uploads of its drive; on MySQL/MariaDB and SQLite the lock is the
  storage row and tree writes of one drive run one at a time. Custom Actions that change the
  tree should take the same locks before locking node rows.
- Names written by create, upload, rename, copy (explicit name) and document creation are
  trimmed, NFC-normalized when ext-intl is installed, and rejected with
  `InvalidNodeNameException` (422, `validation_failed`, `errors.name`) when empty, `.`/`..`,
  longer than `vaulter.drive.name_rules.max_length` UTF-8 bytes, or containing `/`, `\`
  or a control character.

Run `php artisan migrate --force` after a database backup. Migration
`2026_10_01_000006_nodes_cleanup` moves every parentless node other than a drive's
`root_node_id` under that root, renaming it `"<base> (legacy N)<.ext>"` when a live root
child already owns the name, writes a `node.moved` activity row (`actor_type`
`vaulter:system`, `context.reason` `n04_repair`, `context.renamed_from`) per move, and then
adds the unique one-root-per-drive index (`{nodes}_root_uniq`; a generated `root_storage`
column on MySQL/MariaDB). It stops without changes when a drive has several parentless
nodes and no `root_node_id`: set the real root first. The repair is irreversible —
rollback only drops the index. Re-running the migration changes nothing.

### Independent host keys and migration №3 (AD25)

Before: host owner, subject, author, tenant and actor columns inherited Vaulter's
entity-ID strategy (`char(26)` for ULID or bigint). After: all 27 columns use
`vaulter.ids.host_keys`, default `string` (`varchar(64)`), so UUID users and bigint
projects/roles can coexist with ULID Vaulter IDs. `VaulterSchema::hostKey()` is new;
`actor()` and `tenant()` use the host setting. Entity keys and references keep their strategy.

Run `php artisan migrate --force` in a maintenance window after a database backup.
Migration `2026_10_01_000003_host_key_columns` preserves existing values, column
nullability and indexes, uses `vaulter.connection` and legacy table names, and is
idempotent. PostgreSQL casts values with `USING`; MySQL/MariaDB modifies column types;
SQLite requires no type upgrade. The migration does not rename the tenant column.

Set `VAULTER_HOST_KEYS` to `bigint`, `uuid` or `ulid` only when every existing host
key fits that type. Incompatible data raises the database driver's error. Rollback
restores the current Vaulter ID strategy; host keys inserted after upgrading (for
example UUIDs into an old ULID installation) can prevent rollback. Do not assume a
structural rollback can accept every new host key.

### Runtime Drive dependencies (AD02)

Documents and Filament now declare `axioma-studio/vaulter-drive` in `require`
rather than `require-dev`; installing either also installs Drive and its Media
backend. Update your Composer lock accordingly. Filament Move now requires
workspace creation access and Create on the destination, including the storage
root. A denied move leaves the node in place and shows "Move not allowed".

### HTTP middleware and route parameters (AD31)

Replace `vaulter.middleware` with `vaulter.routes.middleware.authenticated` and
remove the legacy key to adopt the default `['api', 'auth:sanctum']`. Install and
configure Sanctum or choose your own host guard. A non-null legacy list still
wins for protected routes, including an empty list. Public shares and signed hub
callbacks use their own `public` and `hub` stacks; metrics are appended once to
all stacks. Replace PHP calls to `Config::middleware()` with the corresponding
`authenticatedMiddleware()`, `publicMiddleware()` or `hubMiddleware()`.

Global route bindings for `node` and `storage` have been removed. Vaulter's middleware
resolves its own parameters through swappable repositories before Laravel's
`SubstituteBindings`; host routes retain their own model bindings. Controller
model signatures and existing route paths are preserved.

### Webhook event occurrence ids (AD22, E8)

Webhook `event_id` values are now 26-character ULIDs generated when a domain event
is constructed. Consumers should deduplicate by the complete `event_id`, without
parsing a topic or node id from it. Repeated operations producing the same state
have different ids; retries of one occurrence keep its original id. Existing queued
rows retain their stored ids, so accept both formats while the backlog drains.

All domain events expose readonly `eventId` and accept an optional final
`?string $eventId = null` constructor argument. Preserve this value when reconstructing
an event for replay. Custom callers of `OutboxRecorder::stage()` using named arguments
must replace `key:` with `eventId:` and pass the originating event's id.

### Domain HTTP error codes and statuses (AD29)

Domain exceptions now implement `Vaulter\Exceptions\Contracts\ProblemException`
with `problemCode(): string` and `httpStatus(): int`. JSON requests and paths under
`vaulter.route_prefix` receive `{message, code}` plus the existing error fields.
PHP callers continue receiving the same exception classes and messages. Host HTML
routes and configuration/infrastructure failures retain Laravel's default handling.

Update HTTP clients for these changes:

- `NodeNameConflictException`: 422 → 409, `code: node_name_conflict`; `errors.name` remains.
- `UploadContentMismatchException`: 415 → 422, `code: upload_rejected`, `reason: mime_mismatch`.
- Document, move and lookup exceptions that previously returned 500 now return the
  4xx statuses listed in [the error reference](docs/guide/errors.md#domain-http-errors).

`OffsetMismatchException` still returns 409 and the authoritative `Upload-Offset`
plus `Tus-Resumable: 1.0.0`. New `NodeNotFoundException::forId()` and
`StorageNotFoundException::forId()` provide 404 codes for resource resolvers.
Custom domain exceptions should implement `ProblemException`; additional JSON fields
can be returned from `protected renderExtras(): array`. RFC 9457 formatting follows
in a later release.

### Dedicated database transactions (AD25)

Transactions, locks and after-commit callbacks previously used the host default connection;
they now follow `vaulter.connection` via `Vaulter\Database\VaulterDatabase`. The config
key is unchanged. Actions, upload repositories, CAS services and webhook/activity subscribers
now receive this singleton through constructor injection. Resolve them through Laravel's
container, or provide `database: app(VaulterDatabase::class)` when constructing them directly.
Custom transaction code can use the same singleton; acquire tree/CAS locks inside its
`transaction()` callback. The shipped UploadSession, UploadPart, NodeComment and DocumentRevision models
now also follow this key, so their Eloquent writes participate in the same transaction.
A custom replacement model must resolve the same connection to preserve atomicity.

### CI: mutation testing runs on native Pest, Infection is gone (VLT-CANON Open risk #4)

Not consumer-facing — a contributor/CI note; no published API, config key or schema changes.

Every package lane (`documents`, `drive`, `media`, `filament`) ran Infection under `|| true` and
**never executed a mutant**: Infection 0.33.3 has no Pest adapter, so it could not map this
suite's tests to mutants, and `|| true` reported the failure as green. The four lanes now use
the same tool as the root `vaulter-core` lane (`pest --mutate`, VLT-REMED D38/P5.9), each in its
own job with a coverage driver and a real `--min=95` threshold and **no** `|| true`.

What this changes for a contributor:

- `composer mutate` in `packages/{documents,drive,media,filament}` and `make mutate` at the root
  now invoke `vendor/bin/pest --mutate …`, not `vendor/bin/infection` (the root binary has not
  existed since P5.9 — `make mutate` was broken).
- Running mutation testing locally needs a coverage driver (`pcov` or `xdebug`); without one Pest
  reports that coverage is unavailable.
- `infection/infection` is no longer a dev dependency and the four `infection.json5` files are
  removed. If you had local tooling pointing at them, point it at the Pest invocation instead.

Baseline at the time of the switch: `--min=95` = `floor(Score) - 5`, measured 2026-07-13 —
`core`, `documents`, `drive`, `media` and `filament` all score **100.00%**.

### `documents/src/Access/HostUserResolver` moves into `Collaboration/` (VLT-CANON P2.4)

**Why.** `Access/` in `vaulter-documents` held exactly one class, and it was not an access-control
class: `HostUserResolver` implements `Vaulter\Documents\Contracts\HostUserResolver`
and exists purely to let the M4b collaboration webhook materialise an `Authorizable` from a bare
user id (see its own docblock: "purely collaboration-webhook machinery"). Its folder disagreed
with its contract. It now sits next to the collaboration drivers it serves
(`CollaborationDriverManager`, `HocuspocusCollaborationDriver`, …). core's `Access/` and
`Webhooks/` domain-service folders were also audited against ADR-0017 in this pass — both conform
(they are in fact ADR-0017's own reference shape for the pattern) and needed no change.

| Old | New |
|---|---|
| `Vaulter\Documents\Access\HostUserResolver` | `Vaulter\Documents\Collaboration\HostUserResolver` |

Only hosts that bind or reference the *default* implementation directly are affected — the
contract FQCN (`Vaulter\Documents\Contracts\HostUserResolver`) that hosts normally
type-hint or swap is unchanged. Pre-1.0 — hard rename, no alias phase. Pure move: zero behavior
change.

### Catch-all folders resolved by role: `documents/src/Documents`, `documents/src/Support`, `drive/src/Uploads` (VLT-CANON P2.3)

**Why.** Final structural canon (Part 3): `documents/src/Documents/` was a tautologically-named
grab-bag mixing the (already core-owned, VLT-CANON P0.4) engine registry remnants with TipTap/Univer
drivers and two read-only evaluator services; `documents/src/Support/` mixed engine-neutral helpers
with TipTap-only ones; `drive/src/Uploads/` mixed orchestrator classes with pure helpers. Each moved
class now sits in the domain-service folder or layer matching its actual role — the registry/driver
classes join `Engines/Handlers` (mirrors core's `Vaulter\Engines\Handlers`), the two evaluators join
the existing `Export`/`Collaboration` domain-service folders (ADR-0017) alongside their siblings
(`HtmlExporter`/`MarkdownExporter`; `CollaborationDriverManager`/drivers), the TipTap-only
Support helpers get a `TipTap` domain sub-namespace, and the Uploads helpers join a new
`Support/Uploads`. Pre-1.0 — hard rename, no alias phase. Pure move: zero behavior change.

**What moved (documents):**

| Old | New |
|---|---|
| `Vaulter\Documents\Documents\Handlers\TipTapHandler` | `Vaulter\Documents\Engines\Handlers\TipTapHandler` |
| `Vaulter\Documents\Documents\Handlers\UniverHandler` | `Vaulter\Documents\Engines\Handlers\UniverHandler` |
| `Vaulter\Documents\Documents\NodeExportService` | `Vaulter\Documents\Export\NodeExportService` |
| `Vaulter\Documents\Documents\CollaborationSessionService` | `Vaulter\Documents\Collaboration\CollaborationSessionService` |
| `Vaulter\Documents\Support\TipTapRenderer` | `Vaulter\Documents\Support\TipTap\TipTapRenderer` |
| `Vaulter\Documents\Support\Concerns\HasTiptapExtensions` | `Vaulter\Documents\Support\TipTap\Concerns\HasTiptapExtensions` |

`Vaulter\Documents\Support\DocumentContentHasher` is engine-neutral and did NOT move.

**What moved (drive):**

| Old | New |
|---|---|
| `Vaulter\Drive\Uploads\MagicByteSniffer` | `Vaulter\Drive\Uploads\Inspection\MimeSniffer` |
| `Vaulter\Drive\Uploads\StagedUpload` | `Vaulter\Drive\Uploads\StagedUpload` |
| `Vaulter\Drive\Uploads\UploadModels` | `Vaulter\Drive\Support\Uploads\UploadModels` |

`Vaulter\Drive\Uploads\{UploadSessionManager,S3MultipartHandler,TusUploadHandler}` are orchestrators
(`*Manager`/`*Handler`) and stay in `Vaulter\Drive\Uploads` — the domain-service folder shape itself
did not change, only the pure-helper classes it also held moved out.

**Not renamed:** `Vaulter\Contracts\Handlers\*` (the public contract interfaces), `Vaulter\Engines\*`
(the core-owned registry, moved earlier in P0.4), and the `config('vaulter.handlers')`/
`config('vaulter-documents.handlers')` KEYS — only the FQCN *values* configured under them changed.

**Who breaks.** A host that directly references any of the old FQCNs above (a manual `use`, an
`instanceof` check, `app(OldFqcn::class)`, or a published `config/vaulter-documents.php` copy still
listing `TipTapHandler::class` under its old FQCN) must update to the new FQCN.

### Package renamed: `axioma-studio/vaulter-permissions-azgard` → `axioma-studio/vaulter-azgard` (VLT-CANON P2.1)

**Why.** Final structural canon (Part 3): the AzGuard bridge package's `permissions-` prefix was
the only package in the monorepo naming a *layer* rather than the *thing it bridges* (`vaulter-core`,
`vaulter-drive`, `vaulter-media`, `vaulter-documents`, `vaulter-filament` all name a domain/framework,
not a role). Pre-1.0, so the rename ships without an alias/deprecation phase.

**What moved:**

| Old | New |
|---|---|
| `packages/permissions-azgard/` (dir) | `packages/azgard/` |
| `axioma-studio/vaulter-permissions-azgard` (Composer package) | `axioma-studio/vaulter-azgard` |
| `Vaulter\Permissions\Azgard\*` (namespace) | `Vaulter\Azgard\*` |
| `Vaulter\Permissions\Azgard\VaulterAzgardServiceProvider` | `Vaulter\Azgard\VaulterAzgardServiceProvider` |
| `Vaulter\Permissions\Azgard\AzgardGuardAdapter` | `Vaulter\Azgard\AzgardGuardAdapter` |
| `Vaulter\Permissions\Azgard\NullAzgardGuard` | `Vaulter\Azgard\NullAzgardGuard` |
| `Vaulter\Permissions\Azgard\Contracts\AzgardBackedUser` | `Vaulter\Azgard\Contracts\AzgardBackedUser` |

**At that migration step**, the contract remained in core. In the current Unreleased
version it moves to `Vaulter\Azgard\Contracts\AzgardGuard`; follow the P2.4 bridge
migration above as well.

**Who breaks — and who does not.** The bridge has **never been published**: this repo carries no
tag, and the split-to-Packagist pipeline (`monorepo-split.yml`) is tag-triggered, so
`axioma-studio/vaulter-permissions-azgard` never existed on Packagist. **A host installing from
Packagist therefore has nothing to migrate — it only ever sees `axioma-studio/vaulter-azgard`.**

Only a host tracking this monorepo's dev branch directly is affected. Such a host must:
- change a `require` of `axioma-studio/vaulter-permissions-azgard` (via a path/VCS repository) to
  `axioma-studio/vaulter-azgard`, and update the repo path if it points at
  `packages/permissions-azgard`;
- update any direct import of `Vaulter\Permissions\Azgard\*` (service-provider registration, the
  `AzgardGuardAdapter`/`NullAzgardGuard`/`AzgardBackedUser` FQCNs) to `Vaulter\Azgard\*`.

The published config file name (`config/vaulter-azgard.php`, tag `vaulter-azgard-config`) and its
contents are unchanged — only the package/namespace names moved.

### `HandlerCapabilities::$richText` now defaults to `false`; the export read path is kind-neutral (VLT-CANON P0.5)

**`richText` default flipped from `true` to `false`.** `Vaulter\ValueObjects\Handlers\
HandlerCapabilities` no longer assumes rich text (TipTap) is the default shape for editable
content — a handler that doesn't explicitly declare `richText: true` is now treated as
non-rich-text (opaque content), not rich-text. **Who breaks:** any THIRD-PARTY `FileHandler`
that relies on omitting `richText` to mean "rich text" — it must now set `richText: true`
explicitly. The two built-in editable handlers are unaffected: `TipTapHandler` now sets
`richText: true` explicitly (it always behaved as rich-text; only the *declaration* changed from
implicit to explicit); `UniverHandler` already set `richText: false` explicitly. A request with
NO bound node (`UpdateDocumentContentRequest::isRichText()`'s no-node fallback) now defaults to
`false` (kind-neutral — no claim without a resolved handler) instead of `true`; this path is only
reachable outside normal route-model-binding and has no known consumer.

**`CreateDocumentInput::$format` default changed from the literal `'tiptap'` to
`DocumentType::RichText->value` (`'richtext'`).** `'tiptap'` was never a valid `DocumentType`
value (see the P0.4 value-space note above) — only direct construction of this DTO (bypassing the
HTTP create endpoint, which always supplies `format` explicitly via
`CreateDocumentNodeRequest::versionFormat()`) is affected.

**The export read path (`NodeExportService::contentOf()`) is now kind-neutral**, closing the gap
P0.2/P0.3/P0.4 tracked as open: it used to resolve EVERY node's head through `TipTapContent`
regardless of the node's actual handler, so a non-rich-text node (e.g. a `univer` spreadsheet)
decayed to an EMPTY TipTap document and exported as blank content instead of being refused. It
now resolves the node's handler and branches on `richText`: a rich-text node's head still decays
through `TipTapContent` exactly as before (no change for TipTap documents); a non-rich-text
node's head is now returned as its own `OpaqueDocumentContent` snapshot, which the existing
`HtmlExporter`/`MarkdownExporter` narrowing guard rejects with
`UnsupportedDocumentContentException` instead of silently rendering nothing. **Who breaks:**
any client exporting a non-rich-text (e.g. Univer) document to HTML/Markdown — it now gets a 4xx
`UnsupportedDocumentContentException` instead of a 200 with blank/empty content. This is a
correctness fix, not a new restriction: the blank export was never useful output.

**Frontend:** `HandlerCapabilities` in `@vaulter/vue` now carries `rich_text: boolean` (was
missing from the TS type entirely, even though the backend always serialised it) plus an open
index signature (`[capability: string]: boolean | undefined`) — capabilities are an additive axis
set, and future new flags won't break existing client code that destructures a known subset.

### The format-handler contract is kind-neutral now: `DocumentHandler` → `FileHandler` (VLT-CANON P0.1 / D7)

**Why.** The contract describes ANY file a host might route to a handler — image/pdf preview,
download-only fallback — not only editable documents. "Document" naming was misleading for the
built-in `DownloadOnlyHandler`/`PreviewHandler` fallbacks, which are not editors. VLT-CANON P0.1
renames the core seam to a kind-neutral vocabulary ahead of pluggable non-editor handlers. This is
a **breaking change to public FQCNs only** — no behaviour, schema, config-key, or route changes.
`config('vaulter.handlers')` / `config('vaulter.exporters')` keys are unaffected.

| Old FQCN | New FQCN |
|:--|:--|
| `Vaulter\Contracts\Documents\DocumentHandler` | `Vaulter\Contracts\Handlers\FileHandler` |
| `Vaulter\Contracts\Documents\HandlerRegistry` | `Vaulter\Contracts\Handlers\HandlerRegistry` |
| `Vaulter\Contracts\Documents\NodeHandlerResolver` | `Vaulter\Contracts\Handlers\NodeHandlerResolver` |
| `Vaulter\Contracts\Documents\DocumentContent` | `Vaulter\Contracts\Handlers\DocumentContent` |
| `Vaulter\Contracts\Documents\DocumentExporter` | `Vaulter\Contracts\Handlers\DocumentExporter` |
| `Vaulter\ValueObjects\Documents\DocumentCapabilities` | `Vaulter\ValueObjects\Handlers\HandlerCapabilities` |
| `Vaulter\ValueObjects\Documents\HandlerDescriptor` | `Vaulter\ValueObjects\Handlers\HandlerDescriptor` |
| `Vaulter\ValueObjects\Documents\HandlerUi` | `Vaulter\ValueObjects\Handlers\HandlerUi` |
| `Vaulter\ValueObjects\Documents\FileDescriptor` | `Vaulter\ValueObjects\Handlers\FileDescriptor` |

`Vaulter\ValueObjects\Documents\{TipTapContent,OpaqueDocumentContent,LinkSchemeValidator}` were
unaffected by P0.1 — they have since moved to `vaulter-documents` in P0.3 (see below). Any
`FileHandler` implementation (host-registered or third-party) must update its `implements`
clause and constructor/method type-hints to the new FQCNs; a class still implementing the old
`Vaulter\Contracts\Documents\DocumentHandler` interface no longer satisfies `HandlerRegistry`.

**`@vaulter/vue`.** The TS type mirroring `DocumentCapabilities` in `types.ts` is renamed to
`HandlerCapabilities`. `HandlerDescriptor`/`HandlerUi` are unchanged.

### `DocumentExporter::export()` now takes the opaque `DocumentContent`, not `TipTapContent` (VLT-CANON P0.2)

**Why.** The exporter contract assumed every document is TipTap; that stopped being kind-neutral
once `DocumentContent` (the same opaque interface the write path already stores) exists. A
non-TipTap handler's content should be exportable through the same seam without the contract
forcing a TipTap shape on it.

**Who breaks.** Callers are fine — a `TipTapContent` *is* a `DocumentContent`. **Implementations
break**: the parameter widened, so an exporter still declaring `export(TipTapContent $content)` is
no longer signature-compatible with the interface and fatals on class load.

```diff
 interface DocumentExporter
 {
-    public function export(TipTapContent $content): string;
+    public function export(DocumentContent $content): string;
 }
```

Any custom `DocumentExporter` (host-registered or third-party) must update its `export()`
parameter type to `Vaulter\Contracts\Handlers\DocumentContent` and narrow internally to the
concrete shape it renders, e.g.:

```php
use Vaulter\Contracts\Handlers\DocumentContent;
use Vaulter\Exceptions\Documents\UnsupportedDocumentContentException;
use Vaulter\Documents\ValueObjects\TipTapContent;

public function export(DocumentContent $content): string
{
    if (!$content instanceof TipTapContent) {
        throw UnsupportedDocumentContentException::forExporter(
            $this->format(), TipTapContent::class, $content::class,
        );
    }

    // ... render $content as before
}
```

`UnsupportedDocumentContentException` is new and lives in **core**
(`Vaulter\Exceptions\Documents\`), next to `UnknownExportFormatException` — a core-only host that
registers its own exporter can throw it without requiring `vaulter-documents`. The built-in
`HtmlExporter`/`MarkdownExporter` guard exactly as above.

Note: the package's own node-export path does not yet hand a non-TipTap `DocumentContent` to an
exporter — `NodeExportService::contentOf()` still resolves every version through `TipTapContent`.
The guard is what makes a custom exporter safe today and what makes the seam correct once a
kind-neutral read path lands; it is not yet reachable through `GET .../export`.

`config('vaulter.exporters')` keys and every exporter's `format()`/`mimeType()`/`extension()` are
unaffected.

### TipTap's content shapes moved out of core into `vaulter-documents` (VLT-CANON P0.3)

**Why.** `core` declares only the kind-neutral `DocumentContent` contract (§P0.2); it must not
know the concrete shape of any handler's content. `TipTapContent`, `LinkSchemeValidator` (its
URL-scheme allow-list guard), `OpaqueDocumentContent`, the `#[TiptapExtensions]` attribute, and
`InvalidDocumentContentException` were still physically in `packages/core` — a leftover of the P2 #5
extraction. P0.3 moves all five into the TipTap driver's own package, `vaulter-documents`. This is
a **breaking change to public FQCNs only**: the store-time allow-list, the read-time decay, and
the URL-scheme scrub (F-P1.11-01/-02, R-01/R-11/R-12) are unchanged byte-for-byte.

| Old FQCN | New FQCN |
|:--|:--|
| `Vaulter\ValueObjects\Documents\TipTapContent` | `Vaulter\Documents\ValueObjects\TipTapContent` |
| `Vaulter\ValueObjects\Documents\LinkSchemeValidator` | `Vaulter\Documents\ValueObjects\LinkSchemeValidator` |
| `Vaulter\ValueObjects\Documents\OpaqueDocumentContent` | `Vaulter\Documents\ValueObjects\OpaqueDocumentContent` |
| `Vaulter\Attributes\TiptapExtensions` | `Vaulter\Documents\Attributes\TiptapExtensions` |
| `Vaulter\Exceptions\Documents\InvalidDocumentContentException` | `Vaulter\Documents\Exceptions\InvalidDocumentContentException` |

**Who breaks.** Any code (host or third-party) that names one of the old FQCNs directly —
`instanceof` checks, `catch` clauses, `use` imports, a custom `#[TiptapExtensions]`-tagged enum
case. A **core-only host with no `vaulter-documents` installed** is unaffected: none of these five
classes were ever reachable without the TipTap driver's own `DocumentType` enum, which already
lives in `vaulter-documents`.

`config('vaulter.tiptap.*')` (the `extra_nodes`/`extra_marks`/`extra_attributes`/`url_attributes`
allow-list seam, `Vaulter\Support\Config`) stays in **core** — it is host-facing config surface,
not a TipTap shape, and is out of this item's scope. `v_node_document_versions.format` and its
`default('tiptap')` have since been addressed — see P0.4 below.

### `v_node_document_versions.format` no longer defaults to `'tiptap'`; the handler-registry ENGINE moved from `vaulter-documents` to core (VLT-CANON P0.4 / D10, superseding H.5 / T5)

**Schema.** `v_node_document_versions.format` (`PanelTablesBuilder::defineDocumentVersions`) no
longer carries `->default('tiptap')`. The engine that writes a version now stamps `format`
explicitly — a silent `'tiptap'` DB default is exactly the stale mime→editor association H.5
exists to prevent (D4/D12).

**This changes the value written on two paths.** `CreateDocumentAction`,
`CreateNamedRevisionAction` and `RestoreDocumentRevisionAction` already stamped `format` on every
INSERT and are unaffected. But **`UpdateDocumentContentAction` (autosave) and
`PersistCollaborationStateAction` (Yjs snapshot) did NOT** — when they appended a NEW version row for an
existing document (outside the autosave-debounce window), they let the DB default fill the
column. Both now carry the format forward from the node's head version
(`$head?->format ?? DocumentType::RichText->value`). Consequence: for a **non-rich-text document**
(e.g. a `markdown` node, or a `univer` spreadsheet with `features.spreadsheets` on), an appended
autosave/snapshot row used to be stamped `'tiptap'` regardless of the document's real format; it
is now stamped with the document's actual format (`'markdown'`, `'univer'`, …). That value is
surfaced to clients via `DocumentRevisionResource` (`format` field) and via the version-history
API. **If your client branches on `format === 'tiptap'`, it must be updated** — a document whose
head says `markdown` now has autosave rows that say `markdown` too, not `tiptap`. Rich-text
documents are unaffected in practice (their head already carried `richtext`, and autosave rows
now inherit it instead of `'tiptap'` — see the value-space note below).

**Value-space note.** The column has always mixed two vocabularies: `DocumentType` values
(`richtext` / `table` / `markdown` / `template` — what `CreateDocumentNodeRequest` validates and
what `CreateDocumentAction` writes) and handler ids (`univer`, and formerly the `'tiptap'` DB
default, which is `TipTapHandler::id()` and is NOT a valid `DocumentType`). P0.4 does not unify
them, and **no backfill ships**: rows written before this change keep whatever they were stamped
with (including `'tiptap'` on old autosave rows of a markdown document). Treat `format` as an
advisory label, not a closed enum — the handler is resolved from the node's mime, never from this
column (D4). `CreateNamedRevisionAction`'s no-head fallback changed from the literal `'tiptap'` to
`DocumentType::RichText->value` (`'richtext'`) for the same reason.

**Direct inserts.** If you insert `v_node_document_versions` rows yourself (bypassing the Actions
— not a supported path), you must now set `format` explicitly or the insert fails the `NOT NULL`
constraint.

**Migrating an existing install.** This is a column-default removal on an already-shipped
migration file (`PanelTablesBuilder` is the single column-source a fresh install AND
`panel:migrations` both read), not a new migration — the package has no tagged release yet
(pre-1.0, `[Unreleased]`), so no ALTER migration ships for it. An already-migrated database keeps
its column default until you re-run the schema; nothing breaks either way, because the writers no
longer depend on it.

**Engine ownership (D10).** The format-extensibility registry ENGINE — `DocumentHandlerRegistry`,
`NodeHandlerResolver`, `DocumentFileDescriptorFactory`, the `Capability` enum, and the baseline
`PreviewHandler`/`DownloadOnlyHandler` — moved from `vaulter-documents` to **core**
(`Vaulter\Engines\*` / `Vaulter\Enums\Handlers\Capability`), superseding H.5's "concrete engine
ships in vaulter-documents" allocation and the T5 Option-B topology's `Documents\*
(registry/handlers/services) → docdrive` allocation. **Why:** a drive-only host with no
`vaulter-documents` installed previously got `null` for every node's `handler` payload
(`NodeResource::handler()` degraded silently); it now resolves a real preview/download
descriptor, because the engine + its two baseline handlers are unconditionally bound by core's
own `VaulterServiceProvider`. `vaulter-documents` keeps ONLY its own format additions
(`TipTapHandler`, the feature-flagged `UniverHandler`) — it no longer owns the registry, and
`config('vaulter.handlers')` now has core's two baseline entries by default (documents' own
config merge ADDS `TipTapHandler` on top, same merge mechanism as before).

| Old FQCN | New FQCN |
|:--|:--|
| `Vaulter\Documents\Documents\DocumentHandlerRegistry` | `Vaulter\Engines\DocumentHandlerRegistry` |
| `Vaulter\Documents\Documents\NodeHandlerResolver` | `Vaulter\Engines\NodeHandlerResolver` |
| `Vaulter\Documents\Documents\DocumentFileDescriptorFactory` | `Vaulter\Engines\DocumentFileDescriptorFactory` |
| `Vaulter\Documents\Documents\Handlers\DownloadOnlyHandler` | `Vaulter\Engines\Handlers\DownloadOnlyHandler` |
| `Vaulter\Documents\Documents\Handlers\PreviewHandler` | `Vaulter\Engines\Handlers\PreviewHandler` |
| `Vaulter\Documents\Enums\Documents\Capability` | `Vaulter\Enums\Handlers\Capability` |

**Who breaks.** Any code (host or third-party) naming one of the six old FQCNs directly —
`instanceof` checks, `use` imports, a direct `app(\Vaulter\Documents\Documents\DocumentHandlerRegistry::class)`
resolution. `Vaulter::registerHandler()`/`Vaulter::handlers()`/`Vaulter\Contracts\Handlers\{HandlerRegistry,NodeHandlerResolver}`
are **unaffected** — they were always the stable seam; only the concrete engine behind them
moved. `VaulterManager::handlers()`/`registerHandler()` no longer throw `MissingPackageException`
when `vaulter-documents` is absent (the seam is core-owned now, never package-conditional); a
host or test that caught that exception around these two calls specifically must drop the catch.

**If you published `config/vaulter.php`.** Your published copy carries the pre-P0.4
`'handlers' => []`, and Laravel's `mergeConfigFrom` is a shallow, *existing-wins* merge — so your
empty array wins over core's new baseline default. The baseline handlers are ALSO registered
through the container tag `'vaulter.handlers'` (renamed `vaulter.file_handlers` in 0.3, see "Canonical PHP API 0.3") precisely so this cannot silently disable them, so
**no action is strictly required**: preview/download keep working. But your published config no
longer reflects the shipped defaults — refresh the `handlers` key to keep it honest:

```php
// config/vaulter.php
'handlers' => [
    \Vaulter\Engines\Handlers\PreviewHandler::class,
    \Vaulter\Engines\Handlers\DownloadOnlyHandler::class,
],
```

(`vaulter-documents` still adds `TipTapHandler` — and `UniverHandler` when `features.spreadsheets`
is on — on top of whatever this list holds; you never list those yourself.)

`NodeExportService::contentOf()`'s TipTap-only read path (tracked as open here) is fixed in
VLT-CANON P0.5 — see above.

### The collaboration server (`hocuspocus-server`) now requires Node >= 22 (P4.1 / F-P4.5-02)

**What changed.** `hocuspocus-server/package.json` raises `engines.node` from `>=20` to `>=22`, and
the Hocuspocus CI lane in `.github/workflows/frontend.yml` dropped its Node 20 cell (it now runs
Node 22 only). The reference Docker image already builds on `node:22-slim`.

**Why.** `@hocuspocus/server@4` — the direct dependency the collab server pins (`^4.3`, closed in
this same P4.1 major bump) — declares `engines.node >=22` (verified in `package-lock.json`;
Hocuspocus 4 stable "requires Node 22+"). While the package still declared `>=20`, `npm ci` under
`engine-strict` would fail on Node 20, and the Node 20 CI lane was "testing" a runtime the collab
server's own direct dependency no longer supports — a fictitious support claim. Aligning the
support range **up** (to what the dependency actually requires) is the honest fix. The other
`@hocuspocus/*` packages (`extension-logger`/`redis`/`throttle`) declare no engines constraint;
`yjs@13.6` requires `>=16` and `ws@8` requires `>=10`, so `>=22` is the single binding floor and the
whole collab stack is engine-consistent at Node 22.

**Who is affected.** Anyone running the `hocuspocus-server` collaboration hub on Node 20 (or any
Node < 22): `npm ci`/`npm install` for the collab server now fails the engine check, and the
runtime is no longer supported. This is a **breaking change** for consumers pinned to Node 20.

**What you must do.** Upgrade the host running `hocuspocus-server` to Node 22 (LTS) or newer. The
Node used to build/publish `@vaulter/vue` and other tooling is unaffected — only the collaboration
server's runtime floor moved.

### Package internal structure canon — namespace moves (P4.10 / ADR-0017, Canon A)

**Why.** The six packages drifted into three incompatible internal layouts. ADR-0017 declares one
canon (layer-first placement + a domain sub-namespace inside each layer, `vaulter-core` is the
reference), and P4.10 moves the divergent packages onto it. This is a **breaking change to public
FQCNs only** — no behaviour, schema, config-key, or route changes. Nothing in the database is
touched: none of the moved classes is ever persisted as a polymorphic morph-type string (activity
targets are always `Node`; `owner_type`/`author_type`/`actor_type` store the *host* model's morph
class), so no data migration is required.

**`vaulter-drive` — the `Uploads/` layer subtree was dissolved into the package-root layers** (the
inverse-nested `Uploads/Actions`, `Uploads/Models`, … violated Canon A Law 1). The flat upload
service classes (`UploadSessionManager`, `TusUploadHandler`, `S3MultipartHandler`, `MagicByteSniffer`,
`StagedUpload`, `UploadModels`) stayed put as the `Uploads/` domain-service folder (mirrors
`core/src/Sharing`). Update any `use` statements:

| Old FQCN | New FQCN |
|:--|:--|
| `Vaulter\Drive\Uploads\Actions\CommitUploadAction` | `Vaulter\Drive\Actions\Uploads\CompleteUploadAction` |
| `Vaulter\Drive\Uploads\Models\UploadSession` | `Vaulter\Drive\Models\Uploads\UploadSession` |
| `Vaulter\Drive\Uploads\Models\UploadPart` | `Vaulter\Drive\Models\Uploads\UploadPart` |
| `Vaulter\Drive\Uploads\Repositories\*` | `Vaulter\Drive\Repositories\Uploads\*` |
| `Vaulter\Drive\Uploads\Enums\{UploadProtocol,UploadStatus}` | `Vaulter\Drive\Enums\Uploads\*` |
| `Vaulter\Drive\Uploads\Data\UploadInitData` | `Vaulter\Drive\Data\Uploads\InitiateUploadInput` |
| `Vaulter\Drive\Uploads\Contracts\UploadProtocolHandler` | `Vaulter\Drive\Contracts\Uploads\UploadProtocolHandler` |
| `Vaulter\Drive\Uploads\Http\Controllers\*` | `Vaulter\Drive\Http\Controllers\Uploads\*` |
| `Vaulter\Drive\Uploads\Http\Requests\*` | `Vaulter\Drive\Http\Requests\Uploads\*` |
| `Vaulter\Drive\Uploads\Http\Resources\*` | `Vaulter\Drive\Http\Resources\Uploads\*` |

The upload service classes under `Vaulter\Drive\Uploads\*` (no layer segment) are unchanged.

**`vaulter-documents` — the flat layer roots gained a domain sub-namespace** (their classes
sat directly in `Actions/`, `Events/`, `Data/`, `Enums/`, `Repositories/`, `Models/` with no
domain grouping, violating Canon A Law 2). Each class moved one level down into its domain
(`Comments`, `Documents`, `Versions`, `Collaboration`, `Export`). The pre-existing domain-service
folders `Vaulter\Documents\{Documents,Collaboration,Export,Access,Casts}` and the `Http/`,
`Contracts/`, `ValueObjects/`, `Support/`, `Exceptions/` roots are unchanged. Update `use`
statements — the key public ones:

| Old FQCN | New FQCN |
|:--|:--|
| `Vaulter\Documents\Models\NodeComment` | `Vaulter\Documents\Models\Comments\NodeComment` |
| `Vaulter\Documents\Models\NodeDocumentVersion` | `Vaulter\Documents\Models\Revisions\DocumentRevision` |
| `Vaulter\Documents\Enums\DocumentType` | `Vaulter\Documents\Enums\Documents\DocumentType` |
| `Vaulter\Documents\Enums\Capability` | `Vaulter\Documents\Enums\Documents\Capability` |
| `Vaulter\Documents\Enums\ExportFormat` | `Vaulter\Documents\Enums\Export\ExportFormat` |
| `Vaulter\Documents\Enums\CollaborationDriverType` | `Vaulter\Documents\Enums\Collaboration\CollaborationDriverType` |
| `Vaulter\Documents\Events\NodeComment{Added,Deleted,Resolved}` | `Vaulter\Documents\Events\Comments\Comment{Added,Deleted,Resolved}` |
| `Vaulter\Documents\Events\Document{ContentUpdated,NodeCreated}` | `Vaulter\Documents\Events\Documents\Document{ContentUpdated,Created}` |
| `Vaulter\Documents\Events\DocumentVersionCreated` | `Vaulter\Documents\Events\Revisions\DocumentRevisionCreated` |
| `Vaulter\Documents\Actions\*` (comment / document / version / snapshot) | `Vaulter\Documents\Actions\{Comments,Documents,Revisions,Collaboration}\*` |
| `Vaulter\Documents\Data\*` | `Vaulter\Documents\Data\{Comments,Documents,Revisions,Collaboration}\*` |
| `Vaulter\Documents\Repositories\NodeComment*Repository` | `Vaulter\Documents\Repositories\Comments\NodeComment*Repository` |
| `Vaulter\Documents\Repositories\NodeDocumentVersion*Repository` | `Vaulter\Documents\Repositories\Revisions\DocumentRevision*Repository` |

Model config defaults (`vaulter.models.node_comment` / `node_document_version`) resolve to the new
FQCNs automatically; a host that pinned the FQCN explicitly must update it. `filament`, `media` and
`azgard` were already canon-conformant (or, for `filament`, follow the framework's own
plugin convention as a documented ADR-0017 exception) and did not move.

### TipTap content: a closed store-time schema (now with images/tables), a config seam to extend it, and read-time decay (P5.2 / P0.1 / F-P1.11-01, F-P1.11-02, R-01)

**What changed.** Rich-text content is validated against a **closed allow-list** before it is
stored (`Vaulter\Documents\ValueObjects\TipTapContent::fromArray()`): unknown node types, unknown
marks, attributes outside the per-type list, and `link.href`/`image.src` URLs whose scheme is not
`http`/`https`/`mailto` all reject the whole document with `InvalidDocumentContentException`.

The shipped allow-list covers exactly what the package offers the editor through
`#[TiptapExtensions]` on `DocumentType`:

- nodes: `doc`, `paragraph`, `heading`, `text`, `hardBreak`, `horizontalRule`, `bulletList`,
  `orderedList`, `listItem`, `blockquote`, `codeBlock`, `image`, `table`, `tableRow`, `tableCell`,
  `tableHeader`;
- marks: `bold`, `italic`, `strike`, `code`, `link`, `underline`;
- attributes: `heading.level`, `link.href|target|rel`, `image.src|alt|title`, `codeBlock.language`,
  `tableCell|tableHeader.colspan|rowspan|colwidth`.

**Reading never throws.** `DocumentRevision::tipTapContent()` **decays**: a snapshot written
before this validator existed (or by an extension you have since removed) comes back with the
unknown nodes/marks/attribute keys stripped out, as the method always promised. Nothing in the
database is migrated or rewritten.

**Extending it.** New config keys — `vaulter.tiptap.extra_nodes`, `vaulter.tiptap.extra_marks`,
`vaulter.tiptap.extra_attributes`:

```php
// config/vaulter.php
'tiptap' => [
    'extra_nodes' => ['mention', 'taskList', 'taskItem'],
    'extra_marks' => ['highlight'],
    'extra_attributes' => ['mention' => ['id', 'label'], 'taskItem' => ['checked']],
],
```

The seam is **additive**: it adds to the shipped list and can never remove from it. URL-bearing
attributes (`link.href`, `image.src`) stay scheme-checked whatever you configure — that is a
security invariant, not a preference.

**Why.** Stored XSS: `href="javascript:…"` and script-shaped nodes were persisted verbatim and
re-rendered to every viewer. The store-time allow-list is the first echelon;
`symfony/html-sanitizer` on the HTML render path is the second (and Markdown export re-applies the
scheme allow-list plus markdown escaping — see the next section).

**Who is affected.** Any host whose editor mounts Tiptap extensions beyond the list above: their
content is now **rejected on save** until the extension's nodes/marks/attributes are declared in
`vaulter.tiptap.*`.

**What you must check.** List the extensions your editor mounts and make sure every node/mark type
they emit is either in the shipped list or in `vaulter.tiptap.extra_*`. Content already in the
database needs no migration — it will decay on read and can be re-saved once the config declares
its nodes.

### Markdown export escapes Markdown (not HTML), and drops unsafe link/image targets (P5.2 / R-04, R-10)

**What changed.** `TipTapRenderer::toMarkdown()` no longer runs text and URLs through
`htmlspecialchars()`. It now:

- backslash-escapes Markdown metacharacters (`\`, `` ` ``, `*`, `_`, `{`, `}`, `[`, `]`, `(`, `)`,
  `#`, `+`, `!`, `|`, `~`, `<`, `>`) in text;
- emits link/image destinations through a safe emitter — a URL containing `(`, `)`, spaces or
  angle brackets is written in CommonMark's `<...>` form (with `<`/`>`/space percent-encoded)
  instead of being concatenated into `](...)`;
- drops the link wrapper (keeping the label) / the image entirely when the scheme is not
  `http`/`https`/`mailto`;
- renders `image` as `![alt](src)` and `table` as a GFM pipe table;
- fences a `codeBlock` with a backtick run **longer than any run inside its body** (CommonMark), and
  emits `attrs.language` as the info string only when it is a bare language token
  (`[A-Za-z0-9_+-]*`) — anything else is dropped (P5.2 review / RV-01). A code block whose text
  contained its own ` ``` ` fence — legitimate text, accepted at store time — used to close the
  fence early and emit whatever followed it as **live raw HTML** in the exported `.md`; a `language`
  carrying a newline did the same from the opening line.

**Why.** HTML escaping left the Markdown metacharacters live, so a link label of
`click](javascript:alert(1))` closed the link early and opened a **new, live** `javascript:` link
in the exported file — the scheme allow-list had checked the `href`, not the string actually
emitted. As a side effect, exports also stopped printing HTML entities into `.md` (`&amp;` broke
query strings, `&lt;` showed up as literal text).

**Who is affected.** Anything that diffs or byte-compares exported Markdown, or feeds it to a
renderer that relied on the old HTML entities.

**What you must check.** Re-generate any stored Markdown export fixtures.

### `TipTapRenderer` now takes a required constructor dependency (`HtmlSanitizerInterface`) (P0.1 / F-P1.11-01)

**What changed.** `Vaulter\Documents\Support\TipTapRenderer` (the public `final` renderer behind
`toHtml()`/`toMarkdown()`, documented in `docs/guide/export.md`) gained a constructor:

```php
// Before — a stateless renderer with no constructor
$renderer = new TipTapRenderer();

// After
public function __construct(private readonly HtmlSanitizerInterface $sanitizer) {}
```

The injected `Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface` is the **second echelon** of
the stored-XSS fix: `toHtml()` re-sanitizes the rendered HTML on the way out (link schemes limited
to `http`/`https`/`mailto`, media to `http`/`https`), so content persisted before the store-time
allow-list existed is scrubbed on render too. `VaulterDocumentsServiceProvider::packageRegistered()`
binds `HtmlSanitizerInterface` as a singleton, so **container resolution is unaffected** —
`app(TipTapRenderer::class)`, and any class that constructor-injects `TipTapRenderer` (the pattern
shown in `docs/guide/export.md`), pick up the dependency automatically.

**Why.** The renderer had to sanitize the HTML it emits, and per the package's DI discipline the
sanitizer is injected through the constructor rather than resolved from the container inline — the
same reason `NodeExportService` grew a constructor dependency (see the P4.5 section below).

**Who is affected.** Only a host that manually `new`s the renderer (`new TipTapRenderer()`) instead
of resolving it through the container (the documented/only supported way) — that call now fatals
with `ArgumentCountError: Too few arguments to function __construct()`. This is a
constructor-signature change on a public class, i.e. a pre-1.0 **breaking change** for
direct-instantiation consumers, shipped without a deprecation cycle.

**What you must do.** Resolve the renderer through the container instead of constructing it
directly:

```php
// Before
$renderer = new TipTapRenderer();

// After
$renderer = app(\Vaulter\Documents\Support\TipTapRenderer::class);
```

If you must construct it by hand, pass an `HtmlSanitizerInterface` yourself
(`app(\Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface::class)` gives you the package's
configured instance). No config change or database migration is required for this upgrade note.

### `webhooks.delivery.follow_redirects` is removed — deliveries never follow redirects (P5.2 / R-05)

**What changed.** The config key `vaulter.webhooks.delivery.follow_redirects` is **gone**. Outbound
webhook deliveries never follow HTTP redirects: `DeliverWebhook` calls `withoutRedirecting()`
unconditionally and sets `CURLOPT_FOLLOWLOCATION => false` on the transport. A `3xx` from a
receiver is now the final (non-2xx) response and the delivery is recorded as a failed attempt.

**Why.** The key switched the SSRF barrier off. The guard resolves and range-checks the endpoint
URL and pins the vetted IP onto the connection (`CURLOPT_RESOLVE`) — but the pin covers the
**original** host:port only. A redirect hop is resolved again, without the guard and without a pin,
so a receiver answering `302 Location: http://169.254.169.254/latest/meta-data/` walked the
delivery straight onto the cloud metadata service. A boolean that turns a security barrier off is
not flexibility.

**Who is affected.** Hosts that set `follow_redirects => true`, and any receiver that answers a
delivery with a redirect (e.g. `http` → `https`, or a URL that has moved).

**What you must check.** Remove the key from your published config (it is ignored now). Point
`WebhookEndpoint::$url` at the **final** URL — the receiver must answer the POST itself, not bounce
it.

### Resumable uploads: the per-chunk ceiling now SHIPS ENABLED (64 MiB), and an over-cap body is a 413 (P5.2 review / F-P1.4-04, RV-03)

**What changed.**

- `config('vaulter.uploads.max_chunk_bytes')` now defaults to **67 108 864 (64 MiB)** — it was `0`,
  i.e. "no per-chunk cap". A single tus `PATCH` / S3 part body larger than the ceiling is refused
  with **413 Payload Too Large** on its declared `Content-Length`, before the body is read; a body
  with no declared length is cut off at the ceiling before a byte is staged.
- The request body is now streamed (`php://input` handed straight to the handler) instead of being
  materialised into a PHP string by the controller.
- `env('VAULTER_UPLOAD_MAX_CHUNK_BYTES')` still overrides it; `0` still disables the cap entirely.

**Why.** The ceiling existed but was disabled by default next to a 5 GiB `max_bytes`, and it was
enforced *behind* the place the harm was delivered: the controller had already materialised the
whole body in memory before anything checked its size. One authorized `PATCH` could take a worker
out on memory. A ceiling that only holds when the host remembers to switch it on is not a ceiling.

**Who is affected.** Anyone whose client sends single chunks/parts larger than 64 MiB — those
requests now get a 413 (they used to be accepted).

**What you must check.** The advertised `chunk_size` hint is 8 MiB, so a conformant client is far
below the ceiling. If you deliberately push huge single chunks, raise
`uploads.max_chunk_bytes` (or set it to `0` to restore the old unbounded behaviour — understand
that you are re-opening the DoS lever).

### Upload sessions: abort/expire now take the PATCH lock; a missing staged object is a hard error (P5.2 / R-02, R-03, R-08)

**What changed.**

- `UploadSessionManager::abort()` / `expire()` now run inside the same critical section as a tus
  `PATCH` (one transaction, the per-session lock as its first statement, status re-read under the
  lock). If a `PATCH` holds the lock, they throw `UploadConflictException` instead of reclaiming
  the session.
- `vaulter:sweep-uploads` **skips** a session whose lock it cannot take (a `PATCH` is in flight)
  and reports it as skipped; it no longer fails, and no longer deletes the staged bytes of a live
  upload.
- `S3MultipartHandler::putPart()` runs in that same critical section too (P5.2 review / RV-02): it
  takes the lock, re-reads the status under it and may now throw `UploadConflictException` (lock
  held by another actor) or `ValidationException` (the session was reclaimed while the part was in
  flight). It used to check `isOpen()` on the caller's stale snapshot and stage a part object onto
  an already-terminal session — bytes nothing would ever collect.
- `StagedUpload::append()` throws the new `Vaulter\Drive\Exceptions\Uploads\StagedUploadMissingException`
  (409) when the session claims `offset > 0` but the staged object is gone, instead of silently
  writing the new chunk alone.
- `UploadSessionStoreRepository::advanceReceived()` is a compare-and-set on the open statuses: it
  throws `UploadConflictException` rather than forcing a terminal session back to `uploading`.
- The Postgres advisory-lock key is now 64-bit (`hashtextextended`), so two different `upload_id`s
  no longer collide into a bogus 409.
- **MySQL/MariaDB:** the session lock is now `SELECT … FOR UPDATE **NOWAIT**` (it was a plain,
  blocking `FOR UPDATE`). "I could not take the lock" is an immediate answer on every engine now,
  as the contract above already claimed. Needs **MySQL 8** / **MariaDB 10.3+** — both already the
  floor in [database compatibility](docs/guide/database-compatibility.md).

**Why.** The sweeper reclaimed sessions with no lock at all: it could delete the staged object
between a live `PATCH`'s "read offset" and its "append", after which the `PATCH` wrote its chunk on
its own (losing the accepted prefix), forced the row back to `uploading`, and answered the client
with a **regressed** offset. The client then uploaded the tail into a file with a hole — a
corrupted upload, with no error anywhere.

**Who is affected.** Hosts calling `UploadSessionManager::abort()`/`expire()` directly: both may
now throw `UploadConflictException`.

**What you must check.** Wrap direct `abort()`/`expire()` calls in a `UploadConflictException`
handler (surface a 409 / retry later). Clients must treat a 409 as "re-read `HEAD` and resume".

### The upload COMMIT now runs inside the session lock too; `markCommitted()` is a compare-and-set (P5.2 / R-11)

**What changed.**

- New `UploadSessionManager::commitAssembled(UploadSession $session, callable $commit)`: it takes
  the per-session lock, re-reads the status under it, moves the session to `assembled`, and runs
  your commit callback **inside** that critical section. Both shipped controllers (tus `PATCH`
  completion, S3 `complete-multipart`) now go through it instead of calling
  `markAssembled()` + `CompleteUploadAction::execute()` in the open.
- `UploadSessionStoreRepository::markCommitted()` is now a compare-and-set on `assembled`: it
  throws `UploadConflictException` (409) instead of force-writing `committed`, and the commit
  transaction rolls back with it.
- A session reclaimed (expired/aborted) between its last chunk and its commit is now refused with
  `UploadConflictException` rather than committed.

**Why.** The sweeper legitimately reclaims an `assembled` session past its TTL (F-P1.4-10), and a
`PATCH` is gated on status, never on expiry — so an upload could land its last chunk after
`expires_at`, and the commit then streamed the staged bytes into `BlobStore.commit` with **nothing
holding the lock**. A sweeper tick discarded the staged object mid-stream: the commit either died
on "no staged bytes" or committed a **truncated** blob (the CAS re-hashes whatever it is handed —
nothing downstream could tell), and `markCommitted()` then resurrected the terminal row as
`committed`.

**Who is affected.** Hosts that drive `CompleteUploadAction` themselves (a custom upload controller,
a queued commit job), or call `markCommitted()` directly.

**What you must check.** Wrap your commit in `commitAssembled()`:

```php
$node = $manager->commitAssembled($session, fn (UploadSession $locked) => $commitAction->execute($locked));
```

and handle `UploadConflictException` (surface a 409 — the session was reclaimed; the client starts
a new upload).

### The document read API now returns SANITIZED TipTap content (decayed + scheme-scrubbed), not the raw row (P5.2 / R-12)

**What changed.** `DocumentRevisionResource` (behind `GET nodes/{node}/content`) no longer echoes
the persisted `content` column verbatim for TipTap documents. It now returns
`TipTapContent::decayFrom(...)->withSanitizedUrls()`:

- unknown nodes/marks and attribute keys outside the allow-list are **stripped**;
- `link.href` / `image.src` whose scheme is not `http`/`https`/`mailto` are **dropped** (the same
  `LinkSchemeValidator` the store-time gate uses, so `java\tscript:` obfuscation is caught too).

A non-rich-text handler's opaque snapshot (a Univer workbook, §7 level 2) is still returned
**verbatim** — the content shape decides, not the `format` label.

**Why.** This is the path the editor actually mounts, and both XSS echelons missed it: the
store-time validator never saw rows written before it existed, and `symfony/html-sanitizer` guards
only the HTML render (export/preview). A legacy `href="javascript:alert(1)"` therefore travelled
straight from the API into the DOM as an `<a href>`.

**Who is affected.** Hosts whose client reads `data.content` and relies on custom TipTap
nodes/marks of their own.

**What you must check.** Declare your extensions in `vaulter.tiptap.extra_nodes` /
`extra_marks` / `extra_attributes` (the same config seam the store-time allow-list uses — see the
TipTap section above). Anything not declared there is now stripped on **read** as well as rejected
on write.

### Panels are read at boot, not on first use (P4.4 / F-P3.2-01)

**What changed.** `PanelRegistry` is now resolved during `VaulterServiceProvider::packageBooted()`,
so every panel in `config('vaulter.panels')` is validated at **boot**. A panel with a bad model /
pipe / resource FQCN — or a per-panel id strategy on the shared schema — now throws
`InvalidPanelException` while the app boots (including under `config:cache`, `route:cache` and
artisan commands), instead of surfacing on the first live request that happened to touch it.

**Why.** That is what the ADR and `InvalidPanelException`'s own docblock always promised. A broken
panel that only explodes on some later request is a landmine, not a config error.

**Who is affected.** Anyone who registered a panel by writing `config('vaulter.panels')` **after the
framework had booted** — most often a test that did `config()->set('vaulter.panels', [...])` inside
the test body. The registry has already read the config by then, so the write is not picked up and
the panel resolves as `UnknownPanelException`.

**What you must check.** Panels must be in the config **before boot** — a config file, or (in tests)
Testbench's `defineEnvironment()`. If you genuinely need to add a panel at runtime, use the seam
built for it:

```php
app(\Vaulter\Panels\PanelRegistry::class)->registerProvider(new MyStoragePanel);
```

### Raw-text/nosniff and rate-limit hardening on downloads and uploads (P1.1, P1.3 / F-P1.3-04, F-P1.11-03, F-P1.8-01, F-P1.4-09)

**What changed.**

- **Downloads (P1.1).** Every PHP-streamed download/preview response (Drive downloads and public
  share downloads) now carries `X-Content-Type-Options: nosniff` unconditionally, and any
  `Content-Type` outside the safe whitelist is forced to `application/octet-stream` with an
  attachment disposition.
- **Rate limits (P1.3).** The package registers two named rate limiters — `vaulter-public` (public
  share endpoints) and `vaulter-uploads` (upload endpoints) — and applies them to its routes.
  Clients past the limit get `429`.
- **Upload-session cap (P1.3).** An actor may hold at most `vaulter.uploads.max_active_sessions`
  open sessions; `initiate()` past the cap throws `429 TooManyRequestsHttpException`.

**Why.** A stored `text/html` (or SVG) attachment served inline executed in the origin of the host
application — a stored-XSS channel through the file-download path. And an unauthenticated public
share endpoint with no rate limit is a free brute-force/enumeration oracle; an unbounded number of
open upload sessions is a free staging-space DoS.

**Who is affected.** Hosts that relied on inline rendering of HTML/SVG attachments; clients that
hammer public share or upload endpoints; anything that opens many concurrent upload sessions per
user.

**What you must check.** Serve HTML/SVG attachments through your own controller if you truly need
them inline. Tune `vaulter.rate_limits.*` and `vaulter.uploads.max_active_sessions` to your
traffic, and handle `429` in clients.

### `EnumCaseAttributeResolver::forCase()`/`flush()` are no longer static — resolve the class through the container (P4.6 / F-P2.1-05)

**What changed.** `Vaulter\Support\EnumCaseAttributeResolver` (the enum-case PHP-attribute
reflection resolver backing the `HasIcon`/`HasLabel`/`HasTiptapExtensions` traits) moved its
reflection cache from a process-wide `private static array $cache` to an instance property
(`private array $cache`), and its two public methods moved from `public static function` to
`public function` accordingly:

```php
// Before
public static function forCase(UnitEnum $case, string $attribute): ?object
public static function flush(): void

// After
public function forCase(UnitEnum $case, string $attribute): ?object
public function flush(): void
```

The class is bound as a container `scoped()` singleton in
`VaulterServiceProvider::packageRegistered()` and resolved via
`app(EnumCaseAttributeResolver::class)` — the same pattern already used by `PanelRegistry`/
`IdStrategyResolver` elsewhere in the package. The three in-package call sites (`HasIcon`,
`HasLabel`, `HasTiptapExtensions`) were switched to this container-resolved form in the same
commit.

**Why.** The previous `static $cache` was process-wide, mutable state living outside the
facade (E3.3 — no global state outside the facade): under Octane/queue workers it silently
carried reflection results across unrelated requests/jobs sharing the same worker process, and
any two independent container scopes (e.g. two Testbench test cases in the same process)
unintentionally shared one cache. Binding the resolver as a container-scoped singleton ties the
cache's lifetime to the container/request scope instead of the whole PHP process.

**Breaking consequence.** Calling `EnumCaseAttributeResolver::forCase(...)` or
`EnumCaseAttributeResolver::flush()` **statically** now fatals immediately with `Error:
Non-static method Vaulter\Support\EnumCaseAttributeResolver::forCase() cannot be called
statically` (`::flush()` likewise) — this is a pre-1.0 breaking change shipped without a
deprecation cycle (D3), not a deprecation-then-removal.

**Who is affected.** Only host code that calls `EnumCaseAttributeResolver::forCase(...)` or
`::flush()` directly and statically, bypassing the package's own `HasIcon`/`HasLabel`/
`HasTiptapExtensions` traits (the traits themselves already resolve the instance through the
container and need no changes on your side).

**What you must do.** Resolve an instance through the container instead of calling the class
statically:

```php
// Before
$label = EnumCaseAttributeResolver::forCase($case, Label::class);
EnumCaseAttributeResolver::flush();

// After
$label = app(EnumCaseAttributeResolver::class)->forCase($case, Label::class);
app(EnumCaseAttributeResolver::class)->flush();
```

No config change or database migration is required for this upgrade note.

### `@vaulter/vue`: `yjs` and `@tiptap/pm` moved to `peerDependencies` — install them yourself (P4.7 / F-P2.5-07)

**What changed.** `packages/vue/package.json`'s `yjs` (`^13.6`) and `@tiptap/pm` (`^3.0.0`) moved
from `dependencies` to `peerDependencies` (matching the ranges already shipped and tested). They
stay listed in `devDependencies` too so the package's own build/test/dev loop is unaffected.
`vue` was already a peer; these two join it for the same reason.

**Why.** Both libraries hold module-level singleton state — `yjs` a `Y.Doc` registry, `@tiptap/pm`
a ProseMirror instance/schema. A host that itself uses TipTap/ProseMirror or Yjs (very likely for
an app embedding Vaulter specifically for collaborative editing) previously risked a SECOND copy
of these libraries in `node_modules` whenever bundler/npm dedup missed a matching minor version —
two `Y.Doc` instances for the same document silently diverge instead of syncing; two ProseMirror
instances give `instanceof` mismatches between the host's schema and `@vaulter/vue`'s. Moving them
to `peerDependencies` makes the host own a single shared instance the same way it already owns
`vue`.

**Who is affected.** Every consumer of `@vaulter/vue` — npm (`>=7`) auto-installs missing peers by
default, so `npm install` alone may already satisfy this; older npm, `--legacy-peer-deps`, `pnpm`
in strict mode, or a lockfile that never resolved these two packages at the top level will instead
warn/fail on missing peers.

**What you must do.** Ensure `yjs@^13.6` and `@tiptap/pm@^3.0.0` (or a version your package
manager considers compatible with those ranges) are present in your own `dependencies` —
add them explicitly if your install reports them missing or your bundler complains about a
duplicate/unresolved peer.

No PHP-side change; this only affects `@vaulter/vue` npm consumers.

### Node-name policy is now a config seam; the reject message is now a lang key; `NodeExportService` takes a new constructor dependency (P4.5 / F-P3.1-01, F-P3.1-02, F-P3.1-04)

**What changed.**

1. `DriveNameRules::rules()`/`messages()` (the shared name validation for
   create/rename/upload/copy Drive requests) now read `config('vaulter.drive.name_rules.
   max_length')` and `config('vaulter.drive.name_rules.no_separators_pattern')` instead of
   hardcoding `255` and the slash-reject regex. Defaults are byte-identical to the previous
   hardcode — a host that never touches this config key sees no behaviour change.
2. The reject message (`'The name must not contain path separators (/ or \\).'`) is now
   resolved through `trans('vaulter::validation.name_no_separators')` (a new package lang
   file, publishable via `--tag=vaulter-translations`) instead of an inline string literal.
   Default text is unchanged.
3. `Vaulter\Documents\Documents\NodeExportService` (the node-attached document export path)
   gains a new constructor dependency, `Vaulter\Documents\Contracts\Export\
   ExportFilenameResolver`, bound by `VaulterDocumentsServiceProvider` to a new
   `DefaultExportFilenameResolver` that reproduces the pre-P4.5 filename scheme (node
   basename + exporter extension) unchanged. The class stays `final` (it is covered by the
   `Vaulter\Documents\Documents`-namespace `ArchTest` finality gate, P4.1/H.5) — a host
   wanting a different export filename scheme rebinds `ExportFilenameResolver` in its own
   provider instead of subclassing.

**Why.** F-P3.1-01/02 (Minor/Nit): the name policy and its message were hardcoded in a
`final` class with no config/lang seam, forcing a host with a different naming policy or
locale to fork the file. F-P3.1-04 (Minor): the export filename scheme was a `private`
method on the same `final` class — no override path existed at all.

**Who is affected.** Config/lang consumers: nobody unless they now explicitly opt into a
custom `name_rules`/translation — defaults are unchanged. `NodeExportService` consumers:
only a host that manually `new`s the class instead of resolving it through the container
(the documented/only supported way) — container resolution picks up the new dependency
automatically via the provider's binding.

### `VaulterManager::for()`/`registerHandler()`/`handlers()` now throw a `MissingPackageException` (not a raw `BindingResolutionException`) when `vaulter-drive`/`vaulter-documents` are absent (P4.4 / F-P3.3-02, F-P3.5-01)

**What changed.** These seams previously let the container's generic
`Illuminate\Contracts\Container\BindingResolutionException` ("Target [...] is not
instantiable.") propagate — a message with no mention of "vaulter" or which composer package to
install. They now catch it and throw `Vaulter\Exceptions\Support\MissingPackageException`
instead, naming the missing contract and the exact `composer require` to run.

**Why.** The docblocks of `HandlerRegistry`/`NodeHandlerResolver` already claimed a "fails
loudly with an install hint" — this makes that true.

**Who is affected.** Only a host that specifically caught `BindingResolutionException` around
these calls to detect a missing satellite package — that catch no longer matches
(`MissingPackageException` still extends the base `RuntimeException`-rooted `VaulterException`,
so a broad `catch (\Throwable)`/`catch (\Exception)` is unaffected).

### Dead `StorageAdapter` seam removed: `Contracts\Cas\StorageAdapter`, `Support\DefaultStorageAdapter`, `config('vaulter.storage_adapter')` (P4.4 / F-P3.1-03)

**What changed.** `Vaulter\Contracts\Cas\StorageAdapter`, its no-op default implementation
`Vaulter\Support\DefaultStorageAdapter`, the `config('vaulter.storage_adapter')` key and
`Config::storageAdapterClass()` are removed entirely.

**Why.** This was a fully-declared seam (contract + default implementation + config-bound FQCN
resolver) left over from an earlier, pre-CAS milestone — nothing in the package ever resolved
`Config::storageAdapterClass()` or bound `StorageAdapter` in any provider. Real binary-attachment
persistence goes through the independent, actually-wired `StorageDriver`/`ContentStore` CAS seam
(`packages/media`). Keeping the dead seam around was a DX trap: a host reading the contract and
binding an implementation against it got a class that was never called.

**Who is affected.** Only a host that bound its own `StorageAdapter` implementation via
`config('vaulter.storage_adapter')` or referenced `Vaulter\Contracts\Cas\StorageAdapter` /
`Vaulter\Support\DefaultStorageAdapter` directly — given the seam was never invoked, this is
expected to be nobody in practice.

**What you must check.** If you implemented a `StorageAdapter` expecting it to handle binary
attachment storage, remove the config key/binding and use the `StorageDriver`/`ContentStore`
CAS contracts (`config('vaulter.cas.storage_driver')` / `config('vaulter.cas.content_store')`)
instead — that is the seam real persistence has always gone through.

### Node name search now escapes `%`/`_`/`\`; cycle detection now on for tree reads (P4.2 / F-P1.8-03, F-P4.2-02)

**What changed.** Two `packages/core` behaviour changes, both narrowing a previously-loose
result rather than adding a new capability:

1. `NodeReadRepository::search()` (backing the `GET storages/{storage}/search` endpoint) now
   escapes `%`, `_` and `\` in the caller-supplied search term before it is wrapped in `LIKE`
   wildcards (`... like ? escape '\'`). A term of `%` or `_` used to match every node in the
   tree (unescaped `LIKE` wildcard); it now matches only nodes whose name literally contains
   that character.
2. `Vaulter\Models\Drive\Node::enableCycleDetection()` now returns `true` (the
   staudenmeir/laravel-adjacency-list trait default is `false`). `ancestors()`/`descendants()`
   (and everything built on them: `AccessResolver::ancestorChain()`, breadcrumbs, subtree
   reads) now stop the recursive CTE at a repeated row instead of recursing without a bound.

**Why.** (1) a search term containing `%`/`_` previously behaved as a wildcard rather than a
literal character — surprising results, and a pathological term could force a full-table scan.
(2) the only existing guard against a `parent_id` cycle was `MoveNodeAction::assertNoCycle()`,
exercised solely on the move-through-Action write path; a cycle introduced any other way (raw
SQL, a seeder, a legacy import) went undetected on read and could exhaust memory/hang a request.

**Who is affected.** (1) Any caller of the node search endpoint/repository method whose search
terms may contain a literal `%` or `_` and previously relied on (or worked around) the
wildcard behaviour. (2) Only hosts whose data can contain a `parent_id` cycle bypassing
`MoveNodeAction` (raw writes, imports, seeders) — a tree with no cycle is completely unaffected;
a tree that does have one will now get a bounded/truncated ancestor or descendant list instead
of an unbounded recursive scan.

**What you must check.** If you depended on `%`/`_` behaving as a search wildcard, switch to
a proper wildcard/filter API instead (this was never a documented contract). If you suspect
cyclic `parent_id` data (only reachable by bypassing the package's own write paths), audit and
repair it — cycle detection now surfaces the cycle as a truncated traversal instead of masking
it with an unbounded/failing query.

No config change, migration, or database schema change is required for this upgrade note.

### `firebase/php-jwt` raised to `^7.0` (CVE-2025-45769) — signing secrets now need ≥256 bits (P4.1 / F-P4.4-04)

**What changed.** `firebase/php-jwt` moved from `^6.0` to `^7.0` (root `composer.json` and
`packages/documents/composer.json`, the package minting the Hocuspocus collaboration room JWT).
`^6.0` was affected by CVE-2025-45769 ("weak encryption"); `^7.0` closes it and adds a HS256
minimum-key-length check (`DomainException: Provided key is too short` for keys under 256 bits /
32 bytes).

**Action required if you set a short `vaulter.collaboration.drivers.hocuspocus.secret`.** Any
existing HS256 signing secret shorter than 32 bytes now throws at JWT-mint time instead of
silently signing with a weak key. Generate a secret with at least 32 bytes, e.g.
`php -r 'echo bin2hex(random_bytes(32));'` (64 hex chars = 32 bytes), and set it via
`VAULTER_COLLAB_JWT_SECRET`/config. No action needed if your secret was already ≥32 bytes.

### `happy-dom`/`vitest` raised in `packages/vue` devDependencies (critical RCE) — no consumer action (P4.1 / F-P4.4-05)

**What changed.** `packages/vue`'s test tooling moved: `happy-dom` `^15.11.0` → `^20.10.6` (closes
a critical VM Context Escape / RCE advisory, GHSA-37j7-fg3j-429f) and `vitest` `^2.1.0` → `^4.1.10`
(pulled in by the happy-dom fix). Both are devDependencies — **never shipped in the published
`dist/` bundle** — so this is not a consumer-facing change; only contributors running the test
suite are affected. One internal test fixture (`tests/drive-components.test.ts`) needed a
`window.prompt` stub before `vi.spyOn` (happy-dom ≥16 no longer defines `window.prompt` at all).

**Known residual (tracked, not a regression from this change).** `npm audit --audit-level=high`
in `packages/vue` still reports one advisory: `vite <=6.4.2` (esbuild dev-server request/response
disclosure, GHSA-67mh-4wv8-2f99, moderate — the severity shown against the `vite` entry is
inherited from its `esbuild` dependency). This is dev-server-only (never shipped), pre-existing
before this change, and NOT the critical happy-dom RCE this entry closes. A full fix needs a
`vite` 6→8 major bump; attempted and reverted here because `vite-plugin-dts` (Vue `.d.ts`
generation) has no release compatible with `vite@8` yet (its `5.0.3` Volar-based rewrite needs an
undeclared `@vue/language-core` dependency and breaks `npm run build`). Tracked for a follow-up
once `vite-plugin-dts` ships `vite@8` support.

### Reference Hocuspocus `docker-compose.yml` no longer defaults to `:latest` (P4.1 / F-P1.8-05)

**What changed.** `hocuspocus-server/docker-compose.yml`'s `image:` now requires
`VAULTER_COLLAB_IMAGE_TAG` to be set (no floating `:latest` default) — the file already documented
"pin a version" in its header comment, but silently defaulted to `:latest` otherwise.

**Action required if you copied this compose file.** Set `VAULTER_COLLAB_IMAGE_TAG` to a released
`vaulter-hocuspocus` tag (e.g. `1.2.3`) or, in production, an immutable `@sha256:…` digest — see
`docs/guide/hocuspocus-server.md`. Without it, `docker compose up` now fails fast at interpolation
time instead of silently floating to whatever `:latest` currently resolves to.

### `@tiptap/*` raised from `2.x` to `3.x` in `packages/vue` and `e2e/` — no code changes needed (P4.1 / F-P4.5-01)

**What changed.** `@tiptap/pm`, `@tiptap/starter-kit`, `@tiptap/vue-3`, `@tiptap/extension-link`
(`packages/vue`) and `@tiptap/extension-collaboration` (`e2e/`) moved from `^2.10` to `^3.0.0`.
Verified mechanical: `npm run build`/`typecheck`/`test` (102 tests) pass unchanged in
`packages/vue`, and the `e2e/` playground builds cleanly — no source changes were required by the
TipTap 3.0 API.

**`@hocuspocus/*` was also raised to `^4.3` (F-P4.5-02).** The collab stack
(`@hocuspocus/server`/extensions in `hocuspocus-server`, `@hocuspocus/provider` in `packages/vue`
and `e2e/`) moved from `^2.x` to `^4.3`, with `yjs` aligned to `^13.6.8` under the server's peer
requirement. Hocuspocus 4 reshaped the transport (`ws` → `crossws`) and moved `.listen()` off the
`Hocuspocus` core onto a `Server` class with a `websocketOptions` config key, so `src/index.js` and
the `websocket-hardening.test.js` regression suite were rewritten against the public v4 API (same
frame-cap security invariant, `51/51` green, no hang). **This bump carries a runtime consequence:**
`@hocuspocus/server@4` requires **Node >= 22** — see the "collaboration server now requires Node
>= 22" section at the top of this file.

### New optional config('vaulter.connection') routes the whole schema onto a named connection (P3.6 / F-P2.3-02, F-P2.3-04)

**What changed.** A new, nullable config key — `config('vaulter.connection')` (env
`VAULTER_DB_CONNECTION`) — lets a host route every Vaulter-owned table/model onto a named
Laravel database connection instead of the application's default one (sharding, data placement,
isolating a heavy CAS/activity workload), without forking the package. `PanelTablesBuilder` now
creates/drops tables and runs every driver-aware index statement through
`Schema::connection(config('vaulter.connection'))`/`DB::connection(...)`, and every shipped model
(`Node`, `Storage`, `NodeGrant`, `Activity`, `File`, `FileVersion`, `FileVersionBlob`, `NodeShare`,
`Outbox`, `WebhookEndpoint`, `WebhookDelivery`, `Blob`) now resolves `getConnectionName()` from the
same key via the new `Vaulter\Models\Concerns\ResolvesVaulterConnection` trait.

**This is purely additive — no action required.** The key defaults to `null`, which resolves
exactly as before (the host's default connection); nothing changes unless you explicitly set it.

**Known gap — `packages/drive`/`packages/documents` models.** Only the 12 `packages/core` models
(`Node`, `Storage`, `NodeGrant`, `Activity`, `File`, `FileVersion`, `FileVersionBlob`, `NodeShare`,
`Outbox`, `WebhookEndpoint`, `WebhookDelivery`, `Blob`) resolve `getConnectionName()` from this
key. `UploadSession`/`UploadPart` (`packages/drive`) and `NodeComment`/`DocumentRevision`
(`packages/documents`) do NOT — their tables ARE created on `config('vaulter.connection')` (same
`PanelTablesBuilder`), but the models still resolve to the host's default connection. If you set
`VAULTER_DB_CONNECTION` to a non-default connection, these 4 models will throw "table not found"
on first access. Do not point `vaulter.connection` away from the default while using drive
upload-session or document-comment/version features until this is closed in a follow-up.

**Docs:** `docs/guide/database-compatibility.md` gained a "Dedicated database connection" section
covering this key, and a "Schema change policy" section formalising how the shipped `v_*` schema
is allowed to evolve after the package's first tag (F-P2.3-04, doc-only — no code/behaviour
change).

No database migration is required for this change.

### The tus resumable-upload endpoints now enforce the tus 1.0.0 protocol: offset-mismatch is 409 (not a bare redirect/422), a wrong PATCH Content-Type is 415, a missing/wrong `Tus-Resumable` is 412, an OPTIONS discovery endpoint exists, and metadata keys are whitelisted (P3.4 / F-P1.4-02, F-P1.4-05, F-P1.4-06, F-P1.4-07, F-P1.4-08)

**What changed.**

1. **A tus PATCH whose `Upload-Offset` does not match the server's authoritative offset now
   returns `409 Conflict`** (with the CURRENT server offset in the `Upload-Offset` response
   header), instead of a bare 302 redirect (non-JSON request) or 422 (JSON request) a plain
   Laravel validation error rendered before. A retransmit of a chunk whose bytes are IDENTICAL to
   what is already staged at that exact byte range is still accepted (204) — only a genuine
   mismatch (different bytes, or a "jump ahead" past the server's offset) is now a 409.

   **Breaking consequence:** any client-side code that specifically branched on 302/422 for a
   tus offset conflict must branch on 409 instead — this is what real tus clients already expect
   per the protocol.

2. **A PATCH whose `Content-Type` is not `application/offset+octet-stream` now returns `415`**
   before any byte is appended (previously accepted and staged regardless of Content-Type).

3. **HEAD/PATCH/DELETE on a resumable-upload session now require the `Tus-Resumable: 1.0.0`
   request header** — a request missing it, or naming an unsupported version, is refused with
   `412 Precondition Failed` (carrying `Tus-Version: 1.0.0`). Previously the header was set on
   RESPONSES but never checked on requests.

   **Breaking consequence:** any client issuing HEAD/PATCH/DELETE against these endpoints WITHOUT
   the `Tus-Resumable` header now gets 412 instead of succeeding. A real tus client already sends
   this header on every request; only a bespoke/incomplete client integration is affected.

4. **`OPTIONS` on a resumable-upload handle is now a real discovery endpoint**, answering (without
   authorization) with `Tus-Resumable`, `Tus-Version: 1.0.0`, `Tus-Extension: creation,termination`
   and `Tus-Max-Size` (from `uploads.max_bytes`, omitted when unbounded). The checksum extension is
   NOT implemented and therefore deliberately not advertised.

5. **Resumable-upload metadata (`Upload-Metadata` header or JSON `metadata`) is now filtered
   through a closed key allow-list** (`vaulter.uploads.metadata.allowed_keys`, default `filename`,
   `filetype`, `name`, `mime_type`) and every accepted value is truncated to
   `vaulter.uploads.metadata.max_value_length` (default 512 characters). A key outside the
   allow-list is silently dropped, not rejected.

   **Breaking consequence:** any host relying on an arbitrary/custom metadata key surviving to
   `v_upload_sessions.metadata` must add that key to `vaulter.uploads.metadata.allowed_keys`.

**Who is affected.** Any consumer of the tus resumable-upload endpoints (`resumable-uploads/
{upload_id}`) — a compliant tus client already sends `Tus-Resumable` and the correct
`Content-Type`, and already expects 409 on offset conflict, so most integrations need no change.
A host relying on a non-standard metadata key, or on the pre-fix 302/422/204-on-anything
behaviour, must adjust as described above.

No database migration is required for this upgrade note.

### Upload-session lifecycle: a stuck Assembled upload now gets swept, only the session's OWNER may drive it, and initiate/abort/expire now write activity rows (P3.3 / F-P1.4-10, F-P1.4-12, F-P1.4-13)

**What changed.**

1. **The sweeper (`vaulter:sweep-uploads`) now reclaims a session stuck in `Assembled`** — every
   byte landed but the commit that follows was interrupted — once it is past its TTL, exactly as
   it already did for `Initiated`/`Uploading`. Before this, an `Assembled` session was never
   swept: its staged temp (and any known S3-multipart parts) leaked forever. A session that
   reaches `Assembled` well within its TTL is unaffected — `expires_at` is stamped once at
   `initiate` and never bumped, so only a session that got stuck PAST its original TTL is
   reclaimed.

   **Who is affected.** Any host running `vaulter:sweep-uploads` (or its schedule) with resumable
   uploads whose commit step can be interrupted after assembly (process kill, deploy mid-request,
   `BlobStore.commit` failure). No action required — this only reclaims sessions that were
   already permanently stuck.

2. **`authorizeSession` (tus HEAD/PATCH/DELETE and S3-multipart parts/complete) now ALSO checks
   that the acting actor is the session's `created_by` owner**, on top of the existing
   Create/Edit ability check on the target folder/node. Previously any actor holding a
   legitimate Create/Edit grant on the SAME folder/node — having merely learned the
   (unguessable, server-minted) `upload_id` — could HEAD/PATCH/DELETE or drive someone ELSE's
   in-flight session to completion or abort it. A session with no recorded `created_by`
   (system-minted, no owner to compare against) is unaffected — the ability gate alone still
   applies.

   **Breaking consequence:** a session that used to be drivable by ANY actor sharing the
   Create/Edit grant on its target is now refused (403) to everyone except its original creator.

   **Who is affected.** Any host where multiple actors share a Create/Edit grant on the same
   folder/node AND rely on being able to resume/abort a colleague's resumable-upload session by
   its `upload_id`. This was never a supported/intended workflow (the finding is exactly that it
   should not have worked) — if your integration depended on it, have the ORIGINAL uploader
   drive their own session instead, or open a new session under the actor who needs to act.

3. **`initiate`/abort (client DELETE)/`expire` (sweeper) on a resumable-upload session now each
   write ONE activity row** (`upload_session.initiated` / `upload_session.aborted` /
   `upload_session.expired`, gated on the existing `features.activity` flag, same as every other
   Drive activity row). Previously only the terminal commit
   (`file.uploaded`/`version.created`) was audited; session creation/abandonment/reclaim left no
   trace at all. `expire` rows carry no actor (the sweeper runs outside an HTTP request).

   **Who is affected.** Hosts consuming the activity feed (`GET .../activity`, the Filament
   `ActivityResource`) will start seeing three new verbs. No action required unless your
   integration enumerates a closed list of expected verbs.

No database migration is required for this upgrade note.

### Every collab webhook call must now carry a valid HMAC body signature; `webhook_secret` is REQUIRED for collaboration to work at all (P3.2 / F-P1.6-02, F-P1.6-03)

**What changed.**

1. **`collab/authenticate` and `collab/store` now require an HMAC-SHA256 signature of the
   raw request body**, on top of the existing identity check (the shared `webhook_secret`
   header OR a valid room JWT bearer token). The signature is computed over
   `"{timestamp}.{rawBody}"` — the SAME canonical string `Vaulter\Webhooks\WebhookSigner`
   already uses for outbound webhook deliveries — keyed by
   `config('vaulter.collaboration.webhook_secret')`, and presented in two new headers:
   `X-Vaulter-Collab-Timestamp` + `X-Vaulter-Collab-Signature`. A call with a missing
   signature, a timestamp outside `config('vaulter.collaboration.webhook_timestamp_tolerance')`
   (default ±300s), or a replayed signature (the same signature seen again within that
   window) is now refused with a 401 — even when its identity (secret/JWT) is perfectly
   valid. Previously the request body was never signed at all, on either identity path, so a
   captured secret/JWT let an attacker replay or tamper with the `yjs_state`/`node_id` payload
   indefinitely.

   **Breaking consequence:** because the signing key is ALWAYS `webhook_secret` — regardless
   of whether the caller identifies via the secret header or a JWT — `webhook_secret` is now
   REQUIRED for ANY collab webhook call to succeed. A deployment running the JWT-only identity
   path with no `webhook_secret` configured will find every `collab/authenticate`/`collab/store`
   call 401 after upgrading.

   **Who is affected.** Any host running the Hocuspocus collaboration hub
   (`config('vaulter.features.collaboration', true)`, VLT-REMED P3.1).

   **What you must do.** Set `VAULTER_COLLAB_WEBHOOK_SECRET` (both on the Laravel app and on
   the `hocuspocus-server` Node hub, via its own `VAULTER_COLLAB_WEBHOOK_SECRET` env var) if you
   have not already. Ensure the Laravel app server and the Node hub have closely synchronised
   clocks (NTP) — a clock skew beyond the ±300s tolerance window causes signed calls to be
   refused as stale; the tolerance is configurable via
   `config('vaulter.collaboration.webhook_timestamp_tolerance')`.

2. **`hocuspocus-server` now arms a server-side timer that force-closes a live collaboration
   WebSocket once its JWT's `exp` passes**, instead of relying solely on the debounced
   `onStoreDocument` write to re-validate the token. Previously a long-lived READ-ONLY viewer
   connection (which never triggers a store) could keep syncing over the open socket
   indefinitely past its token's expiry, since only a write re-checked `exp`.

   **Who is affected.** Any host running the Hocuspocus hub with viewer (read-only) sessions
   left open past the collab token TTL (`config('vaulter.collaboration.drivers.hocuspocus.
   token_ttl')`, default 3600s). No action required — this tightens enforcement automatically;
   a client that needs a longer session should request a fresh token before its current one
   expires (reconnect), same as before.

No database migration is required for either change in this section.

### Realtime collaboration now needs its OWN feature flag; ActivityResource degrades instead of registering against a disabled subsystem (P3.1 / F-P1.6-01, F-P1.10-03)

**What changed.**

1. **A new flag, `config('vaulter.features.collaboration')` (default `false`), now gates the
   entire realtime-collaboration surface** — `POST nodes/{node}/collab/token`, the
   `collab/authenticate`/`collab/store` server-to-server webhooks, the private broadcast
   channel authorization, and the `ResolveCollaborationCall` middleware group. Previously all
   of this was gated ONLY by `features.document_versions`, so any host with document editing
   on automatically shipped the entire realtime/Node/webhook attack surface, whether or not it
   used collaboration. The two flags are now independent and BOTH must be `true` for the
   realtime routes to mount (`document_versions && collaboration`); `document_versions` alone
   continues to gate the non-realtime editing surface (`content` GET/PATCH, named
   versions/restore) exactly as before.

   **Who is affected.** Any host with `features.document_versions => true` that relies on
   realtime collaboration (Yjs sessions, the collab-token handshake, the Hocuspocus/Reverb
   webhooks).

   **What you must do.** Set `config('vaulter.features.collaboration', true)` (or
   `VAULTER_COLLAB_ENABLED`-style env wiring in your own config, if you introduce one) after
   upgrading — otherwise every collab endpoint starts 404ing. A host that only edits documents
   without realtime sync needs no change (it never relied on the collab endpoints, and now
   also no longer ships their surface).

2. **`ActivityResource` (Filament) is now ALSO gated on the core `activity` feature flag**, on
   top of its own existing `config('vaulter-filament.resources.activity')` toggle (default
   `true`). Previously the plugin consulted only its own toggle, so a host that disabled the
   core `activity` subsystem (`config('vaulter.features.activity', false)`) but left the
   Filament toggle untouched kept `ActivityResource` registered against a subsystem it had
   turned off (F-P1.10-03). This is a footgun/degradation fix, not a security fix: a host
   running with `features.activity` at its default (`true`) sees no change.

   **Who is affected.** Hosts that disabled `features.activity` while keeping the Filament
   plugin's `activity` resource toggle at its default `true` — `ActivityResource` now simply
   does not register (matching every other core-flag-gated Filament surface), instead of
   appearing in navigation.

**Docs corrected alongside this change (no code/behaviour impact):** the collaboration guide
and `ADR-0001` now correctly state `hocuspocus` (not `reverb`) as the default transport —
this ONLY affects documentation and a dead code-level fallback
(`Config::collaborationDriverName()`); the actual config default (`config/vaulter.php:755`)
was already `hocuspocus` and is unchanged (F-P3.6-01).

No database migration is required for either change in this section.

### Four Filament resources now scope their listing; PanelActor resolves through the panel's own guard; a boot-time guard throws on a tenancy footgun (P2.3 / F-P1.10-01, F-P1.10-02, F-P1.9-01)

**What changed.**

1. **`StorageResource`/`NodeGrantResource`/`NodeShareResource`/`ActivityResource` now scope
   `getEloquentQuery()` to the acting panel admin's grant-reachable candidate set
   (F-P1.10-01)**, the same candidate-set semantic `NodeResource` already used. Previously
   these four resources had no policy and no query scope, so ANY authenticated panel user
   saw every storage/grant/share/activity row of every owner — this closes a broken-access-
   control gap, so it is a security fix, not a regression. A host relying on every panel user
   seeing every row (e.g. a single trusted super-admin panel with the `null`/non-grant-
   authoritative driver) is unaffected — the narrowing only applies when the active
   `HostRoleDriver` is grant-authoritative (the standalone `database` driver) with the
   `grants` feature on; every other driver keeps the listing OPEN as before.
2. **`PanelActor::current()`/`id()` now resolve through the active panel's OWN auth guard**
   (`Filament::auth()`), not the application's global default guard (F-P1.10-02). A host whose
   panel runs on a non-default guard (`->authGuard('admin')` etc.) previously had every
   Vaulter Filament resource evaluate the WRONG identity's grants against
   `AccessResolver`. Hosts on the default guard (the common case) see no behaviour change.
3. **Booting with `vaulter.tenant.enabled=true` and the default (non-scoping) `Node` model now
   throws outside production** (F-P1.9-01, ADR-0003 Amendment). This was always a silent,
   fully-open-listing misconfiguration (the `tenant_id` column is populated but nothing
   filters by it); it is now loud everywhere except `app()->environment('production')`, which
   still only logs a warning (no crash in production). **Action required** if you enabled
   `vaulter.tenant.enabled` without swapping `config('vaulter.models.node')` for your own
   tenant-scoping subclass: either supply that subclass, or disable `vaulter.tenant.enabled`
   — both silence the guard.

### Trash now blocks download/export; purge-trash physically deletes; keep_forever is releasable (P2.2 / F-P1.12-01, F-P1.12-02, F-P1.12-03)

**What changed.** Three corrections to the trash/remanence lifecycle (see ADR-0016):

1. **A trashed node 404s on identity download/export, not just listings (F-P1.12-01).**
   `GET nodes/{node}/download`, `.../versions/{v}/download`, `nodes/{node}/export`, and the
   whole `DocumentNodeController` document surface (`content`/`versions`/autosave/named-
   version/restore/export) now 404 when the node's `trashed_at` is set, even for an actor
   whose ACL grant on it is still live. Previously only listings and the public share
   channel excluded trashed nodes — the identity download/export path did not, so a
   "deleted" file stayed fully downloadable for the whole retention window.
2. **`vaulter:purge-trash` now PHYSICALLY deletes rows past retention (F-P1.12-02).**
   Previously "purge" called the same stage-2 action as the HTTP force-delete endpoint — a
   `SoftDeletes` tombstone (`deleted_at`), never a real `DELETE` — so inline document
   content (`v_node_document_versions.content`/`yjs_state`) and version metadata stayed in
   the database forever, defeating the retention/erasure expectation. Purge now issues a
   genuine `DELETE` on the subtree after the retention window elapses, so FK
   `cascadeOnDelete` removes every dependent row (file/document versions, blob edges,
   grants, shares) too. **This is irreversible** — a host that relied on
   `Node::withTrashed()` to forensically inspect a purged node after retention will no
   longer find it.
3. **`keep_forever` can now be released (F-P1.12-03).** `PATCH
   nodes/{node}/document-versions/{version}/keep-forever` (guarded by the same `Edit`
   ability as setting the pin) clears the pin, so the version re-enters the prune window on
   the node's next snapshot/restore. Previously the pin, once set, could never be unset.

**Who is affected.**

- Any integration that polled/relied on `nodes/{node}/download` or `.../export` continuing
  to serve content for a node the user believed "deleted" (it is now `404` immediately)
  — this closes an information-disclosure gap, so this is a security fix, not a regression.
- Any operator/runbook that used `Node::withTrashed()` after `vaulter:purge-trash` to
  inspect or recover a "purged" node's data — that data is now genuinely gone.
- Anyone who pinned a document version `keep_forever` expecting it to stay pinned forever
  with no release path — a release path now exists; nothing is auto-released.

**What you must check.** If your host UI polls a download/export URL for a node the user
just trashed, expect a `404`, not a stale `200`. If a runbook depends on purge-trash being
recoverable, update it — purge is now genuinely irreversible.

### ACL core: role/team grants fail loud under non-enumerating drivers; wall-deny semantics clarified; new `explain()` diagnostic (P2.1 / F-P1.1-01, F-P1.1-02, F-P1.2-01)

**What changed.** Three corrections to the authorization core (`AccessResolver` and the
grant write-path), none of which changes an existing `can()` verdict on the default
`database` lane:

1. **A role/team grant under a non-enumerating driver now fails loud (F-P1.2-01).**
   `GrantNodeAccessAction::execute()` now inspects the active `HostRoleDriver`: if the
   grant's subject is a role/team subject (`subject_type` = the configured
   `vaulter.spatie.role_subject_type` / `team_subject_type`, default `role`/`team`) and
   the driver **cannot enumerate role subjects**, the grant would never take effect and
   Vaulter **logs a warning always**, and **throws
   `Vaulter\Exceptions\Access\UnenumerableGrantSubjectException` outside production**. A
   driver counts as enumerating when it either implements the marker
   `Vaulter\Contracts\Access\EnumeratesRoleSubjects` **or** overrides `subjectsFor()` to
   append role/team `Subject`s — so a host that follows the documented subclass-and-append
   extension path is recognised without the marker. Only the built-in base lanes that
   inherit `subjectsFor()` unchanged (the standalone `database` base, `gate`, `azgard`,
   `null`) trip the guard — they expand an actor only into itself. The `spatie` lane
   implements the marker and is unaffected. The failure direction is safe (an inert grant
   grants nothing), so production only logs.
2. **The `inherit_grants=false` "wall" is documented correctly (F-P1.1-01 / D17).** The
   `AccessResolver` docblocks used to claim a deny on a wall node was an "un-overridable
   wall … no nearer allow can ever pierce it". That was never the behaviour (nor what
   ADR-0007 specifies): a nearer explicit allow **below** the wall pierces such a deny,
   exactly as it pierces any other inherited deny. The code is unchanged; only the false
   guarantee was removed. If you locked down a subtree with `inherit_grants=false` + a
   subtree deny expecting an absolute barrier, re-audit — a nearer allow below it still
   grants access (an absolute wall-deny is a possible future feature, not current
   behaviour).
3. **New diagnostic `AccessResolver::explain()` (F-P1.1-02).** A public
   `explain(Authorizable $actor, Ability $ability, Node $node): AccessExplanation`
   returns the verdict, a machine-readable reason, the folded allow/deny masks + level,
   and the provenance chain of every grant the bounded walk visited (each tagged with
   depth and whether it counted). It runs a separate pass and never touches the hot
   `can()`/`effectiveGrants()` path.

**Who is affected.**

- Any host that writes `v_node_grants` rows addressed to a role/team subject under the
  `database`, `gate`, `azgard`, or `null` driver **with `subjectsFor()` inherited
  unchanged** — such a write now throws in non-prod and logs a warning in prod. Switch to
  the `spatie` driver, or subclass your driver and **override `subjectsFor()`** to append
  the role/team `Subject`s (optionally marking it `EnumeratesRoleSubjects`). A subclass
  that already overrides `subjectsFor()` to append those Subjects is unaffected.
- Anyone who relied on the (incorrect) "un-overridable wall" docblock guarantee — see
  point 2.

**What you must check.** Grep your integration for grant writes targeting `role`/`team`
subject types and confirm the active driver enumerates them; audit any
`inherit_grants=false` lockdown that assumed an absolute deny barrier.

No database migration or config change is required for this upgrade note.

### Mass-assignment hardening: every model now carries an explicit `$fillable`/`$guarded` list (P1.4 / F-P1.8-02, F-P1.5-04, F-P1.7-04)

**What changed.** All 16 package models used to declare `protected $guarded = [];` — Eloquent
mass-assignment fully open on every column, including service/security ones (`tenant_id`,
`token_hash`, `signing_secret`, `sha256`, `storage_key`, `use_count`, `status`, `owner_*`, …). Each
model now carries an explicit, closed list:

- **A closed `$fillable`** (only genuinely user-facing columns) on `Node`, `Storage`, `File`,
  `FileVersion`, `NodeShare`, `WebhookEndpoint`, `UploadSession`, `NodeComment`,
  `DocumentRevision` — a key outside the list is silently discarded by `fill()`/`create()`.
- **A fully `$guarded = ['*']`** (Eloquent's "totally guarded" state) on `Blob`, `FileVersionBlob`,
  `NodeGrant`, `Activity`, `Outbox`, `WebhookDelivery` — these rows are exclusively server-written
  today (CAS identity, ACL, audit trail, outbox/delivery bookkeeping), so `fill()`/`create()` with
  ANY attribute now throws `Illuminate\Database\Eloquent\MassAssignmentException` instead of
  silently doing nothing.
- `File` carries a partial `$guarded` list (mostly-service columns, `mime_type` remains fillable).

**Why.** F-P1.8-02 (Minor): a latent footgun — any future `Model::create($request->validated())`/
`->fill($input)` on an unguarded model could let a caller set a service/security column. No
exploitable sink existed in the shipped code (every write path already used explicit server-built
arrays), but the package is a library other hosts extend — closing the class of bug matters more
than any single instance of it. F-P1.5-04/F-P1.7-04 single out `Blob`
(content-addressed CAS identity) and the three webhook models (`signing_secret`) as the
highest-value columns to protect.

**Who is affected.**

- A host subclassing a Vaulter model (`config('vaulter.models.*')`) that relies on mass-assigning a
  column outside the new list — e.g. `MyNode::create(['tenant_id' => …, 'name' => …])` — will now
  find `tenant_id` silently dropped (closed-`$fillable` models) or the whole call throwing
  `MassAssignmentException` (fully-`$guarded` models). Use `forceFill()`/`->fill()` on only the
  documented public columns, or extend the model to widen its own `$fillable` if your subclass
  genuinely needs to mass-assign more.
- No database migration or config change is required; this is a pure in-memory guard.

**What you must check.** Grep your integration for direct `::create([...])`/`->fill([...])` calls
against `Vaulter\Models\**`/`Vaulter\Drive\Uploads\Models\**`/`Vaulter\Documents\Models\**` (or
their configured subclasses) that pass a service column (`tenant_id`, `*_id`, `status`,
`signing_secret`, `token_hash`, `sha256`, `storage_key`, `use_count`, `granted_by`, `owner_*`, any
timestamp) — route that write through the corresponding action/repository (or `forceFill()` if you
are confident the value is trusted) instead.

### Public share-link surface hardened: no more presign-redirect, issuer re-check, default TTL, opaque public id (P1.2 / F-P1.3-01/02/03/05)

**What changed.** Four independent fixes to the bearer share-link surface
(`DownloadController`, `PublicShareController`, `ShareResolver`, `SharedNodeResource`):

1. **No more presign-redirect (F-P1.3-01).** Both `DownloadController::current()`/`version()`
   and `PublicShareController::download()` used to 302-redirect to a short-lived signed URL when
   the configured disk could mint one (any S3-compatible disk). They now ALWAYS stream the bytes
   through PHP, exactly like a disk without `providesTemporaryUrls()` always did. Their return
   type narrowed from `StreamedResponse|RedirectResponse` to `StreamedResponse`. A presigned URL
   is a bearer token that is never re-authorized per request and cannot be revoked instantly —
   D15 never sanctioned this mode; it was a defect, not a supported optimization.
2. **Issuer re-check on every share consumption (F-P1.3-02).** A bearer link now dies the moment
   its issuer (the actor who minted it, `granted_by`) loses `Ability::Share` on the node — not
   just when the link itself is explicitly revoked. Previously a link outlived its issuer's own
   access indefinitely (confused-deputy). A share with no recorded issuer (a pre-existing row
   minted before this fix, or one created by a system/console actor) is unaffected.
3. **Bearer links are never immortal by omission (F-P1.3-03).** A link created without an
   explicit `expires_at` now gets `config('vaulter.share.default_ttl_hours')` (default 72h)
   instead of never expiring. An explicit `expires_at` above
   `config('vaulter.share.max_ttl_hours')` (default 720h / 30 days) is now rejected by validation
   (422) instead of silently accepted.
4. **The public share JSON no longer exposes the raw node id (F-P1.3-05).** `SharedNodeResource`
   (`GET share/{token}`, `GET share/{token}/nodes`) now returns `id`/`parent_id` as an opaque,
   deterministic `hash_hmac`-derived string, not `Node::getKey()`. Under `id_type=bigint` the raw
   key is a sequential integer that leaked creation order/count to an anonymous link holder; under
   the default `ulid` it was already unguessable but is now opaque too, for consistency across id
   strategies. No public endpoint accepts a node id from the client, so this does not break
   addressing.

**Who is affected.**

- Anyone relying on `DownloadController`/`PublicShareController` returning a redirect on an
  S3-compatible disk (e.g. a CDN/proxy expecting a 302 to pass through) — the response is now
  always a streamed download through the application.
- Anyone parsing the `id`/`parent_id` fields of the public share JSON as the internal node key
  (e.g. correlating it against an owner-side API response) — it is now an opaque string with no
  relationship to `Node::getKey()` outside the share response itself.
- Anyone who created share links expecting them to never expire without an explicit
  `expires_at` — pre-existing links with a `null` `expires_at` are UNAFFECTED (the default only
  applies at mint time); newly minted links without an explicit expiry now expire after
  `default_ttl_hours`.
- Anyone whose share-minting form posts an `expires_at` further out than `max_ttl_hours` — that
  request now 422s; either request a shorter expiry or raise `vaulter.share.max_ttl_hours`.

**What you must check.** If you depend on any of the four behaviours above, override the
relevant config key (`vaulter.share.default_ttl_hours`, `vaulter.share.max_ttl_hours`) or adjust
client code that parsed the public `id`/`parent_id` as a real key or relied on a 302 from the
download endpoints.

No database migration is required for this upgrade note.

### Access/sharing events no longer dispatch after commit (P0.2 / F-P1.7-03)

**What changed.** Four kernel events dropped `Illuminate\Contracts\Events\ShouldDispatchAfterCommit`
and are now dispatched **synchronously, inside the database transaction** of the mutation that
raises them:

- `Vaulter\Events\Access\NodeGrantCreated`
- `Vaulter\Events\Access\NodeGrantRevoked`
- `Vaulter\Events\Sharing\NodeShareCreated`
- `Vaulter\Events\Sharing\NodeShareRevoked`

**Why.** The package's transactional-outbox fix (P0.2 / F-P1.7-03) stages the outbox and activity
rows for these events in the *same* database commit as the grant/share mutation, to close a crash
window in which a committed grant/share could lose its outbox/activity row. That requires the
event — and therefore every listener on it — to run before the transaction commits, not after.

**Who is affected.** Only hosts that have registered a listener on one of the four events above.
If you have not, no action is needed.

**What you must check.** Open every listener class registered for `NodeGrantCreated`,
`NodeGrantRevoked`, `NodeShareCreated` or `NodeShareRevoked` (`EventServiceProvider::$listen`,
`Event::listen(...)` calls, or attribute-based `#[AsEventListener]` — including a listener that
happens to catch a broader event via an interface or the wildcard `*`), and confirm it satisfies
both of the following:

1. **It is synchronous.** The listener class must **not** implement `Illuminate\Contracts\Queue\ShouldQueue`.
   A queued listener attached to one of these events used to run only after the transaction
   committed; it now runs inside the still-open transaction, which either fails outright (no
   queue connection available mid-request in most setups) or — if it does get dispatched — can
   execute against a mutation that is later rolled back.
2. **It is idempotent and side-effect-safe if the surrounding transaction rolls back.** Because the
   listener now runs before commit, any work it performs (writes to another table, external HTTP
   call, cache write, mail dispatch, etc.) can execute even if the enclosing grant/share mutation
   is subsequently rolled back by the caller. Do not rely on "if the listener ran, the mutation
   committed."

**How to migrate a listener that needs an after-commit or queued guarantee.** Do not attach that
behaviour to the raw event. Instead, drive it off the package's durable transactional-outbox
relay, which already staged an outbox row atomically with the mutation and dispatches it only
after commit (`vaulter:relay-outbox`) with at-least-once delivery:

- If you need an outbound webhook, register a `WebhookEndpoint` subscribed to the corresponding
  outbox event type instead of listening to the Eloquent/domain event directly.
- If you need a queued side-effect (e.g. send a notification, update a search index), have your
  listener enqueue a job for the relayed webhook/activity payload — i.e. drive it from something
  that already only fires after commit — rather than from `NodeGrantCreated` /
  `NodeGrantRevoked` / `NodeShareCreated` / `NodeShareRevoked` themselves.

**If you cannot make your listener synchronous+idempotent right now**, detach it from these four
events (`Event::forget(...)` in your provider, or remove the listener registration) rather than
leave a queued/non-idempotent listener attached — an unreviewed queued listener on these events
will misbehave under transaction rollback.

No config change, migration, or database schema change is required for this upgrade note; it is a
pure code-contract change on the four events listed above.

### `vaulter-core` split into four Composer packages — require the ones you use (P2 #5 / D1, D2, D3)

**Why.** `vaulter-core` shipped the CAS blob engine, the Drive tree/uploads and the whole document
& collaboration stack in one package, so a host that wanted only a node tree still pulled in TipTap
and Yjs. The engines moved out into three standalone packages. The dependency direction is one-way
(`drive` → `core` + `media`; `documents` → `core`) — there is no cycle, and `vaulter-drive` is not a
runtime dependency of `vaulter-documents`.

**What moved.**

| Package | Namespace | What went there |
|:--|:--|:--|
| `axioma-studio/vaulter-media` | `Vaulter\Media\*` | The CAS engine — `Sha256ContentStore`, `FlysystemStorageDriver`, `BlobStore`, `BlobGarbageCollector`, `BlobReconciler`, `StagedTempObject` (was `Vaulter\Cas\*`) — and the `vaulter:gc-blobs` / `vaulter:reconcile-blobs` commands. |
| `axioma-studio/vaulter-drive` | `Vaulter\Drive\*` | `DriveContext`, `DriveManager`, `StorageManager`, the Drive Actions (`TrashNodeAction`, `UploadFileAction`), the Drive events (`FileUploaded`, `NodeCreated`, `NodeMoved`, `NodeTrashed`, `StorageCreated`, …), the Drive/Storage/Upload HTTP layer, the whole `Uploads\*` subtree (tus + S3-multipart), and the `vaulter:purge-trash` / `vaulter:sweep-uploads` commands. |
| `axioma-studio/vaulter-documents` | `Vaulter\Documents\*` | The document Actions (`CreateDocumentAction`, `UpdateDocumentContentAction`, `CreateNamedRevisionAction`, `RestoreDocumentRevisionAction`, the comment Actions, `PersistCollaborationStateAction`), the `Collaboration\*` drivers, the handlers (`TipTapHandler`, `PreviewHandler`, `DownloadOnlyHandler`, `UniverHandler`), `HtmlExporter` / `MarkdownExporter`, the `NodeComment` / `DocumentRevision` models and the document/collaboration/comment controllers. |

**What stays in `vaulter-core`.** The contracts and schema, so the seams a host implements against
do not move: `Contracts\Cas\*`, the `v_blobs` schema, `Models\Cas\Blob`, `Enums\Cas\BlobStatus`,
`Repositories\Cas\BlobStoreRepository`; `Contracts\Drive\{DriveHandle,DriveHandleFactory}`,
`Support\DriveNameRules`, `Exceptions\Nodes\NodeNameConflictException`,
`Http\Resources\Drive\NodeResource` and the `v_nodes` / `v_upload_sessions` / `v_upload_parts`
schemas; the handler-seam contracts and value objects and the `v_node_comments` /
`v_node_document_versions` schemas.

**What to do.**

1. Add the packages whose behaviour you actually use:
   - node CRUD, `Vaulter::for()` / `forStorage()`, uploads or downloads →
     `composer require axioma-studio/vaulter-drive`
   - file upload/download/versioning (the blob engine behind Drive) →
     `composer require axioma-studio/vaulter-media`
   - document editing, versioning, comments or realtime collaboration →
     `composer require axioma-studio/vaulter-documents`
2. Repoint any direct FQCN references per the table above. If a host referenced only the contracts
   and value objects listed under *What stays in core*, nothing changes.

   > The handler-seam contracts and value objects were renamed **again** later in this same
   > unreleased cycle. Land on `Contracts\Handlers\*` / `ValueObjects\Handlers\*` — see the
   > kind-neutral-rename note above for the final FQCNs.
3. Config: `vaulter.cas.*` and `vaulter.dedup.*` are unchanged. `vaulter.models.{upload_session,
   upload_part}` and `vaulter.models.{node_comment,node_document_version}` are no longer defaulted
   by core — they **guard-throw** when the owning package is absent, which is the intended signal
   that you need to `require` it. `vaulter.exporters` defaults to `[]` in core.

### The legacy `document_id`-bound path (DocVault) is removed (ADR-0014 step 2)

**Why.** Two parallel document models coexisted: the original `document_id`-bound one
(`v_document*` tables) and the node-attached one on `v_nodes`. One canonical path replaces both —
`kind=document` nodes, with comments and versions attached to the node.

**Removed.** The `Document`, `DocumentFolder`, `DocumentVersion`, `DocumentPermission`,
`DocumentComment` and `DocumentShare` models, their `v_document*` tables and migrations, and the
matching repositories, controllers, Actions, events, resources and requests. Also removed: the
`Document`-bound collaboration seam (`Contracts\Collaboration\CollaborationDriver`, its Reverb and
Hocuspocus implementations, and `Vaulter::driver()`); the `Contracts\Access\PermissionResolver`
seam (`Vaulter::resolvePermissionsWith()`, `permissionResolver()` and the
`vaulter.permission_resolver` config key); and the
`vaulter.models.{document,version,folder,permission,comment,share}` config entries with their
table names.

**What to do.** Move to `Vaulter::for()` and the node/document endpoints. Replace a
`CollaborationDriver` implementation with `Contracts\CollaborationDriver` (node-attached, see
the collaboration notes above), and a `PermissionResolver` implementation with the host role driver.
Drop the removed `vaulter.models.*` and `vaulter.permission_resolver` keys from your published
config.

**Data.** A dev-only, idempotent `drop_legacy_docvault_tables` migration drops any pre-existing
`v_document*` tables. It does **not** migrate data. If you carry production rows in `v_document*`,
back them up and port them onto `v_nodes` **before** running migrations — the package ships no
backfill.

### `@vaulter/vue` dropped the legacy document-centric surface (ADR-0014 step 4)

**Why.** The removed client surface called the `/documents`, `/folders` and `/documents/*` HTTP
endpoints that no longer exist after the DocVault excision above; it could only have 404'd.

**Removed → use instead.**

| Removed | Replacement |
|:--|:--|
| client namespaces `documents`, `versions`, `folders`, `permissions`, `shares`, legacy `collaboration` | `client.nodes`, `client.grants`, `client.nodeShares` |
| `useDocuments`, `usePermissions`, `useShares`, `useDocumentExport`, `useCollaboration`, `useVaulterEditor` | `useNodes`, `useNodeGrants`, `useNodeShares`, `useDocumentNode`, `useNodeCollaboration` |
| `VaulterFileManager`, `VaulterEditor`, `VaulterVersionHistory`, `VaulterPermissionsPanel`, `VaulterSharePanel`, `VaulterBreadcrumb` | `VaulterDrive` |
| `createCollaborationSession` transport | `useNodeCollaboration` |

`useComments` and `VaulterCommentsPanel` survive but were **repointed** to the node comment API
(`/nodes/{node}/comments`): the prop is now `nodeId`, not `documentId`.
