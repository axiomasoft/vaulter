# Upgrade Guide

This file documents breaking changes and how to migrate through them. Entries are ordered
newest-first. See `CHANGELOG.md` for the full list of changes per release; this file only covers
changes that require action from consumers of the package.

## Unreleased

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

`Config::table()` now always returns prefix + canonical entity name. Old
`vaulter.table_names` is read only by `Config::legacyTable()` during upgrade.
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


### Prepared CAS commit and atomic quota (AD21)

Custom implementations of `Vaulter\Contracts\Cas\BlobStore` must add
`commitStaged(StagedObject $staged, BlobScope $scope, ?string $disk = null): Blob`.
It commits prepared bytes without discarding the staged object. Keep `commit()`
as stage → commitStaged → discard in `finally`. The outer upload owns cleanup
and reuses prepared bytes on transaction retries.

Quota reservations now run before CAS promotion. Subtree copies that exceed quota
throw the existing `Vaulter\Drive\Exceptions\QuotaExceededException` and roll
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
parent in `CreateDocumentNodeAction` (and in Drive Actions called directly) wrote a
second parentless node that the root listing never showed; the root could be renamed,
moved or trashed; two concurrent moves could form a cycle; `forceDelete` purged live
nodes; restoring or trashing twice was a silent no-op. After:

- A parent or destination must be a live folder of the same drive, checked under row
  locks inside the transaction: otherwise `InvalidDestinationException`
  (409, `invalid_destination`); a folder of another drive raises
  `CrossStorageMoveException` (409, `cross_drive_operation`) from Drive Actions and
  `InvalidDestinationException` from `CreateDocumentNodeAction`.
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
`transaction()` callback. The shipped UploadSession, UploadPart, NodeComment and NodeDocumentVersion models
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
class: `HostUserResolver` implements `Vaulter\Documents\Contracts\Collaboration\HostUserResolver`
and exists purely to let the M4b collaboration webhook materialise an `Authorizable` from a bare
user id (see its own docblock: "purely collaboration-webhook machinery"). Its folder disagreed
with its contract. It now sits next to the collaboration drivers it serves
(`NodeCollaborationDriverManager`, `HocuspocusNodeCollaborationDriver`, …). core's `Access/` and
`Webhooks/` domain-service folders were also audited against ADR-0017 in this pass — both conform
(they are in fact ADR-0017's own reference shape for the pattern) and needed no change.

| Old | New |
|---|---|
| `Vaulter\Documents\Access\HostUserResolver` | `Vaulter\Documents\Collaboration\HostUserResolver` |

Only hosts that bind or reference the *default* implementation directly are affected — the
contract FQCN (`Vaulter\Documents\Contracts\Collaboration\HostUserResolver`) that hosts normally
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
(`HtmlExporter`/`MarkdownExporter`; `NodeCollaborationDriverManager`/drivers), the TipTap-only
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
| `Vaulter\Drive\Uploads\MagicByteSniffer` | `Vaulter\Drive\Support\Uploads\MagicByteSniffer` |
| `Vaulter\Drive\Uploads\StagedUpload` | `Vaulter\Drive\Support\Uploads\StagedUpload` |
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

**Not renamed — the core-owned contract seam is untouched:** `Vaulter\Contracts\Access\AzgardGuard`
(the narrow interface `AzgardPermissionResolver` calls through) stays in `vaulter-core` under its
existing FQCN. Only the bridge/adapter package that implements it moved.

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

**`CreateDocumentNodeData::$format` default changed from the literal `'tiptap'` to
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
`InvalidTipTapContentException` were still physically in `packages/core` — a leftover of the P2 #5
extraction. P0.3 moves all five into the TipTap driver's own package, `vaulter-documents`. This is
a **breaking change to public FQCNs only**: the store-time allow-list, the read-time decay, and
the URL-scheme scrub (F-P1.11-01/-02, R-01/R-11/R-12) are unchanged byte-for-byte.

| Old FQCN | New FQCN |
|:--|:--|
| `Vaulter\ValueObjects\Documents\TipTapContent` | `Vaulter\Documents\ValueObjects\TipTapContent` |
| `Vaulter\ValueObjects\Documents\LinkSchemeValidator` | `Vaulter\Documents\ValueObjects\LinkSchemeValidator` |
| `Vaulter\ValueObjects\Documents\OpaqueDocumentContent` | `Vaulter\Documents\ValueObjects\OpaqueDocumentContent` |
| `Vaulter\Attributes\TiptapExtensions` | `Vaulter\Documents\Attributes\TiptapExtensions` |
| `Vaulter\Exceptions\Documents\InvalidTipTapContentException` | `Vaulter\Documents\Exceptions\InvalidTipTapContentException` |

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

**This changes the value written on two paths.** `CreateDocumentNodeAction`,
`CreateNamedVersionAction` and `RestoreDocumentVersionAction` already stamped `format` on every
INSERT and are unaffected. But **`UpdateDocumentContentAction` (autosave) and
`PersistYjsSnapshotAction` (Yjs snapshot) did NOT** — when they appended a NEW version row for an
existing document (outside the autosave-debounce window), they let the DB default fill the
column. Both now carry the format forward from the node's head version
(`$head?->format ?? DocumentType::RichText->value`). Consequence: for a **non-rich-text document**
(e.g. a `markdown` node, or a `univer` spreadsheet with `features.spreadsheets` on), an appended
autosave/snapshot row used to be stamped `'tiptap'` regardless of the document's real format; it
is now stamped with the document's actual format (`'markdown'`, `'univer'`, …). That value is
surfaced to clients via `NodeDocumentVersionResource` (`format` field) and via the version-history
API. **If your client branches on `format === 'tiptap'`, it must be updated** — a document whose
head says `markdown` now has autosave rows that say `markdown` too, not `tiptap`. Rich-text
documents are unaffected in practice (their head already carried `richtext`, and autosave rows
now inherit it instead of `'tiptap'` — see the value-space note below).

**Value-space note.** The column has always mixed two vocabularies: `DocumentType` values
(`richtext` / `table` / `markdown` / `template` — what `CreateDocumentNodeRequest` validates and
what `CreateDocumentNodeAction` writes) and handler ids (`univer`, and formerly the `'tiptap'` DB
default, which is `TipTapHandler::id()` and is NOT a valid `DocumentType`). P0.4 does not unify
them, and **no backfill ships**: rows written before this change keep whatever they were stamped
with (including `'tiptap'` on old autosave rows of a markdown document). Treat `format` as an
advisory label, not a closed enum — the handler is resolved from the node's mime, never from this
column (D4). `CreateNamedVersionAction`'s no-head fallback changed from the literal `'tiptap'` to
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
through the container tag `'vaulter.handlers'` precisely so this cannot silently disable them, so
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
| `Vaulter\Drive\Uploads\Actions\CommitUploadAction` | `Vaulter\Drive\Actions\Uploads\CommitUploadAction` |
| `Vaulter\Drive\Uploads\Models\UploadSession` | `Vaulter\Drive\Models\Uploads\UploadSession` |
| `Vaulter\Drive\Uploads\Models\UploadPart` | `Vaulter\Drive\Models\Uploads\UploadPart` |
| `Vaulter\Drive\Uploads\Repositories\*` | `Vaulter\Drive\Repositories\Uploads\*` |
| `Vaulter\Drive\Uploads\Enums\{UploadProtocol,UploadStatus}` | `Vaulter\Drive\Enums\Uploads\*` |
| `Vaulter\Drive\Uploads\Data\UploadInitData` | `Vaulter\Drive\Data\Uploads\UploadInitData` |
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
| `Vaulter\Documents\Models\NodeDocumentVersion` | `Vaulter\Documents\Models\Versions\NodeDocumentVersion` |
| `Vaulter\Documents\Enums\DocumentType` | `Vaulter\Documents\Enums\Documents\DocumentType` |
| `Vaulter\Documents\Enums\Capability` | `Vaulter\Documents\Enums\Documents\Capability` |
| `Vaulter\Documents\Enums\ExportFormat` | `Vaulter\Documents\Enums\Export\ExportFormat` |
| `Vaulter\Documents\Enums\CollaborationDriverType` | `Vaulter\Documents\Enums\Collaboration\CollaborationDriverType` |
| `Vaulter\Documents\Events\NodeComment{Added,Deleted,Resolved}` | `Vaulter\Documents\Events\Comments\NodeComment{Added,Deleted,Resolved}` |
| `Vaulter\Documents\Events\Document{ContentUpdated,NodeCreated}` | `Vaulter\Documents\Events\Documents\Document{ContentUpdated,NodeCreated}` |
| `Vaulter\Documents\Events\DocumentVersionCreated` | `Vaulter\Documents\Events\Versions\DocumentVersionCreated` |
| `Vaulter\Documents\Actions\*` (comment / document / version / snapshot) | `Vaulter\Documents\Actions\{Comments,Documents,Versions,Collaboration}\*` |
| `Vaulter\Documents\Data\*` | `Vaulter\Documents\Data\{Comments,Documents,Versions,Collaboration}\*` |
| `Vaulter\Documents\Repositories\NodeComment*Repository` | `Vaulter\Documents\Repositories\Comments\NodeComment*Repository` |
| `Vaulter\Documents\Repositories\NodeDocumentVersion*Repository` | `Vaulter\Documents\Repositories\Versions\NodeDocumentVersion*Repository` |

Model config defaults (`vaulter.models.node_comment` / `node_document_version`) resolve to the new
FQCNs automatically; a host that pinned the FQCN explicitly must update it. `filament`, `media` and
`azgard` were already canon-conformant (or, for `filament`, follow the framework's own
plugin convention as a documented ADR-0017 exception) and did not move.

### TipTap content: a closed store-time schema (now with images/tables), a config seam to extend it, and read-time decay (P5.2 / P0.1 / F-P1.11-01, F-P1.11-02, R-01)

**What changed.** Rich-text content is validated against a **closed allow-list** before it is
stored (`Vaulter\Documents\ValueObjects\TipTapContent::fromArray()`): unknown node types, unknown
marks, attributes outside the per-type list, and `link.href`/`image.src` URLs whose scheme is not
`http`/`https`/`mailto` all reject the whole document with `InvalidTipTapContentException`.

The shipped allow-list covers exactly what the package offers the editor through
`#[TiptapExtensions]` on `DocumentType`:

- nodes: `doc`, `paragraph`, `heading`, `text`, `hardBreak`, `horizontalRule`, `bulletList`,
  `orderedList`, `listItem`, `blockquote`, `codeBlock`, `image`, `table`, `tableRow`, `tableCell`,
  `tableHeader`;
- marks: `bold`, `italic`, `strike`, `code`, `link`, `underline`;
- attributes: `heading.level`, `link.href|target|rel`, `image.src|alt|title`, `codeBlock.language`,
  `tableCell|tableHeader.colspan|rowspan|colwidth`.

**Reading never throws.** `NodeDocumentVersion::tipTapContent()` **decays**: a snapshot written
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
- `StagedUpload::append()` throws the new `Vaulter\Drive\Exceptions\StagedUploadMissingException`
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
  `markAssembled()` + `CommitUploadAction::execute()` in the open.
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

**Who is affected.** Hosts that drive `CommitUploadAction` themselves (a custom upload controller,
a queued commit job), or call `markCommitted()` directly.

**What you must check.** Wrap your commit in `commitAssembled()`:

```php
$node = $manager->commitAssembled($session, fn (UploadSession $locked) => $commitAction->execute($locked));
```

and handle `UploadConflictException` (surface a 409 — the session was reclaimed; the client starts
a new upload).

### The document read API now returns SANITIZED TipTap content (decayed + scheme-scrubbed), not the raw row (P5.2 / R-12)

**What changed.** `NodeDocumentVersionResource` (behind `GET nodes/{node}/content`) no longer echoes
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
key. `UploadSession`/`UploadPart` (`packages/drive`) and `NodeComment`/`NodeDocumentVersion`
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
  `NodeDocumentVersion` — a key outside the list is silently discarded by `fill()`/`create()`.
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
| `axioma-studio/vaulter-documents` | `Vaulter\Documents\*` | The document Actions (`CreateDocumentNodeAction`, `UpdateDocumentContentAction`, `CreateNamedVersionAction`, `RestoreDocumentVersionAction`, the comment Actions, `PersistYjsSnapshotAction`), the `Collaboration\*` drivers, the handlers (`TipTapHandler`, `PreviewHandler`, `DownloadOnlyHandler`, `UniverHandler`), `HtmlExporter` / `MarkdownExporter`, the `NodeComment` / `NodeDocumentVersion` models and the document/collaboration/comment controllers. |

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
`CollaborationDriver` implementation with `Contracts\NodeCollaborationDriver` (node-attached, see
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

## Канон PHP API 0.3 (AD03, AD04, AD05, AD11, AD14)

Все следующие FQN переименованы жёстко, без class_alias и deprecated-обёрток.
Имена таблиц, колонок, ключи конфигурации и значения событий на этом шаге остаются прежними;
их переход описывается отдельными изменениями схемы и конфигурации.

| Было | Стало |
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

- `Vaulter::panel()/panels()/registerPanel()` заменены на `Vaulter::profiles()->get()/register()/registerProvider()`;
  `Vaulter::registerHandler()` — на `Vaulter::handlers()->register()` (AD03, AD14).
- `FileHandler::id()/ui()` заменены на `key()/viewer()`. Ключи имеют формат `vendor/name`:
  `vaulter/preview`, `vaulter/download`, `vaulter/tiptap`, `vaulter/univer`.
  `ViewerDescriptor` содержит `key` и `props`; имена Vue-компонентов и `route` удалены (AD14).
- `NodePresenter::describe(Node, ?string)` заменён на `present(Node)` (AD14).
- `SchemaBuilder` использует общую схему и `createTable(string $entity)/dropTable(string $entity)` без профиля (AD05).
- `ProfileProvider::panel()` заменён на `profile()`. Own-tables API (`tablePrefix`, `tables`, `models`,
  `idStrategy` и их getters), `HasVaulterPanel`, `PanelMigrationGenerator`, `PanelTableResolver`,
  `vaulter:panel:migrations` и `PermissionLevel` удалены (AD05, AD11).
- Hooks `pipes()/resource()/fillNode()/presentNode()/booted()` удалены. `booted()` раньше
  действительно вызывался после регистрации: перенесите нужную host-логику в service provider.
  Его удаление — изменение API, а не утверждение, что вызовов никогда не было (AD05).
- `vaulter:make-panel` заменён на `vaulter:make:profile`; генератор пишет provider в `app/Vaulter/Profiles`.
  Флаги own-tables `--prefix/--id` удалены. `vaulter:relay-outbox` сохраняет прежнюю сигнатуру (AD03, AD05).
- Тег контейнера `vaulter.handlers` заменён на `vaulter.file_handlers`; ключ конфига `vaulter.handlers` пока сохранён (AD14).
- `EventType::webhookTypes()` возвращает подписываемые типы без `Upload*`; `cases()` включает события lifecycle загрузки.
  Backing values остаются значениями 0.2; `StorageCreated/NodeCreated/VersionCreated/NodeForceDeleted`
  заменены на `DriveCreated/FolderCreated/FileRevisionAdded/NodePurged` (AD03, AD04).

The remaining core inputs also use their canonical names (OPUS 04 §3):

| Old | New |
|:--|:--|
| `Vaulter\Data\Access\GrantNodeAccessData` | `Vaulter\Data\Access\GrantNodeAccessInput` |
| `Vaulter\Data\Sharing\CreateShareLinkData` | `Vaulter\Data\Sharing\ShareLinkInput` |
| `Vaulter\Data\Webhooks\RegisterWebhookEndpointData` | `Vaulter\Data\Webhooks\RegisterWebhookEndpointInput` |
| `Vaulter\Data\Webhooks\UpdateWebhookEndpointData` | `Vaulter\Data\Webhooks\UpdateWebhookEndpointInput` |
