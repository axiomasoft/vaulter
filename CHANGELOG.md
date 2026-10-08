# Changelog

All notable changes to **Vaulter** will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### File revisions and quota (PLAN2.D48, D49; PLAN2.P4.12)

- Changed the quota to count every retained revision (trash included) with its full logical size,
  without a dedup discount. Was: only the head of each file, a new revision charged
  `new − old head`. A new revision and a restored revision add their size, a copy adds its head,
  deleting/pruning a revision and purging free bytes; growth past the quota is refused, reads and
  deletes keep working on a drive above it.
- Added `RevisionRetentionPolicy` in `DrivePolicy::$revisionRetention` (`maxNoncurrentRevisions` 100,
  `maxNoncurrentAgeDays` null, `minNoncurrentAgeDays` 7, pinned always protected),
  `DrivePolicyBuilder::revisionRetention()`, `vaulter:revisions:prune {--drive=*} {--dry-run}` and
  `PruneFileRevisionsJob` (schedule daily). `Profile::revisionLimit(n)` now sets
  `maxNoncurrentRevisions = n − 1`. Was: an unwired `Vaulter\Drive\Files\FileRevisionRetention`
  helper (removed).
- Added `FileOperations::deleteRevision()` (Manage; `cannot_delete_head_revision`, `revision_pinned`)
  and `setRevisionRetention()` (Edit), the `FileRevisionDeleted` event and
  `v_file_revisions.superseded_at`.
- Removed the `global` dedup scope: `vaulter-media.dedup.scope` is `tenant` (default), `drive` or the
  new `off`; `global` fails at boot with `InvalidConfigurationException`. `MediaConfig::dedupScope()`
  returns the `DedupScope` enum (was a string); `BlobScope::global()` is gone and a drive without a
  tenant deduplicates within itself under `tenant` (was install-wide). `vaulter:blobs:repair-scopes`
  moves `global` blobs like `legacy:snull`/`legacy:tnull`.
- Migration `2026_10_07_000007_quota_all_revisions` adds and backfills `superseded_at` and recounts
  `used_bytes` for every drive.
- Fixed `vaulter:scan-rescan --activate-policy`, which failed with a `TypeError` (a boolean shadowed
  the activation action).

### Share links 1.0 (PLAN2.D47; PLAN2.P4.10)

- Added `role`/`audience` on share links: previewer, reader and commenter for any audience, writer
  only for `authenticated` or `tenant`, never organizer or owner (`share_role_not_allowed`).
  Was: an ability mask, a `level`, `require_login` and an unused `allow_discovery`.
- Added `ShareOperations::update()` (expiry, budget, password, audience — token unchanged) and
  `rotate()` (new token, `token_generation` + 1, old token and its sessions void), with
  `PATCH nodes/{node}/shares/{share}` and `POST …/rotate`.
- Changed `max_uses` to count redemptions: one per link session (signed `X-Share-Session`), not per
  download; the update is atomic and bound to the token generation.
- Added `tenant` audience (signed in and the host's current tenant is the drive's tenant) and the
  drive policy's `maxShareLinkTtlDays` for links, checked on creation, update and every visit
  (`share_link_ttl_exceeded`).
- Changed previewer links to refuse the original with `403 permission_denied`; subtree links stay
  live against the tree and inheritance boundaries.
- Changed share tokens to `base64url(random_bytes(32))`, and share activity/webhook context to
  `role`/`role_version`/`audience`/`scope`.
- Migration `2026_10_07_000006_share_links_v1` converts masks to roles (65 reader, 97 commenter,
  1 previewer) and refuses any other mask; `2026_10_07_000005` skips its share-mask steps on a fresh
  schema. @vaulter/vue and the Filament share resource moved to role/audience (+ rotate).

### Access model 1.0 (PLAN2.D45, D46, D53; PLAN2.P4.9; ADR-0025)

- Added versioned roles (`Role`/`RoleRegistry`: previewer, reader, commenter, writer, organizer,
  owner) stored as `role_key`/`role_version` on `v_node_grants`. Was: `level` + `permissions` +
  `effect`. Each level converts to the role of the same name, v1; `writer` gains Trash/Restore and
  `organizer` gains Share and loses Delete (D53).
- Added one bit per ability and a separate Download (`Permission::all()` 4095). Was: CRUDS
  bits where View and Download shared READ.
- Changed effective access to the union of roles up to the first `inherit_grants = false` node
  plus the driver's roles; removed deny grants, `max(rank)` and the final veto. The host
  `override()` is the only block. `GrantSet`, `Decision`, `AccessExplanation` and `AccessScope`
  changed shape; `GrantLevel`, `AclFolder`, `AclMask`, `AccessAce` and the Gate `node_owner`
  context were removed. `GrantInput` is `{subject, role, scope, expiresAt}`.
- Added `access()->restrictInheritance()` (`empty`, `copy_current`) and `restoreInheritance()`,
  both requiring Manage; `explain()` now lists role sources and the boundary node. Copy requires
  View + Download + Copy on every copied node (D30).
- Fixed grant management to require Manage, preventing organizer self-promotion to owner.
- Made access migration resume after partial role-column DDL and remove node `owner_id` when
  the grants table is absent. Legacy share masks retain their abilities through a resumable,
  versioned backfill; rollback refuses masks without an exact 0.x equivalent.
- Fixed Vue's anonymous role requests to supply explicit masks without Comment.
- Removed `v_nodes.owner_id` without creating grants (D46, D53). The migration
  `2026_10_07_000005_access_model_v1` refuses deny grants and permission bits beyond the level.
- Changed the HTTP grant field to `role` (response adds `role_version`) and activity/webhook grant
  payloads to `role`/`role_version`. Gate/Spatie maps are keyed by role key and gain
  `previewer => vaulter.preview`; unknown keys throw `unknown_role` (422).
- Share links keep working with the new bit layout and stop at inheritance boundaries; the
  redesign is separate.

### Node data contract (PLAN2.D39–D43; PLAN2.P4.11)

- Added `v_nodes.name_key` (NFC + full case folding, byte-compared: PG `COLLATE "C"`, SQLite
  `BINARY`, MySQL `utf8mb4_bin` + `name_key_hash`). Was: sibling uniqueness by `lower(name)`,
  collation-dependent and blind to NFC/casefold twins. Upgrade renames later colliding siblings
  `"<base> (conflict N)"` with a `node.renamed` system activity.
- Added name policies `portable-v1` (default) and `permissive-v1` (`Vaulter\Naming`), selected by
  `DrivePolicy::$namePolicy`. Was: names were trimmed and only base rules applied. Now: nothing is
  trimmed; `InvalidNodeNameException` renders `invalid_node_name` with a `reason`.
- Added `DrivePolicy` (`Profile::drivePolicy()`): `maxFolderDepth` 100, `maxFolderChildren` 10 000,
  `maxFileBytes`, `maxPropertiesBytes` 16 KiB, `maxPropertyCount` 100, `maxShareLinkTtlDays`.
  Checked in every create/upload/move/copy/restore path; new errors
  `folder_children_limit_exceeded` (409), `tree_depth_limit_exceeded` (409),
  `file_size_limit_exceeded` (413, was 422 `upload_rejected`), `invalid_properties` (422).
- Added `nodes()->star()/unstar()/starred()` (personal, `v_node_user_states`) and
  `nodes()->properties()/setProperties()` (namespaced strings). Removed `nodes()->update()`,
  `UpdateNodeOptions`, `v_nodes.is_pinned` and `v_nodes.position`. Was: global pin and manual
  position on the node.
- Added `v_nodes.content_modified_at/by`, `v_file_revisions.client_modified_at` and
  `UploadOptions::clientModifiedAt()`; REST nodes carry `viewer.starred` and `content_modified_at`.
- Removed `extra_data` from every table, `HasExtraData`, `VaulterConfig::extraDataEnabled()`,
  `VaulterSchema::extraData()` and `DriveConfig::nameMaxLength()`; `vaulter.extra_data.enabled`
  and `vaulter-drive.names.max_length` are deprecated and ignored. Node `extra_data` moves to
  `properties['legacy']`.

### Document aggregate with working state and immutable checkpoints (AD16; PLAN2.D34, PLAN2.D35, PLAN2.P4.1)

- Added `v_documents` (one aggregate per document node: working state, head, `state_version`,
  `collab_epoch`) and `v_document_revisions` (immutable checkpoints); the 0.2
  `v_node_document_versions` rows migrate with their ids. Composite head foreign keys protect
  file and document heads on PostgreSQL/MySQL.
- Removed `v_nodes.current_version_id`, `file_id` and `deleted_at`, `Node::currentVersion()` and
  `SoftDeletes` on `Node`; `File::currentVersion()` → `File::currentRevision()`. Was: one
  polymorphic head on the node that a same-id file revision could shadow. Now: heads live on
  their own aggregate.
- Changed document saves: `DocumentStateWriter` writes the working state; autosave checkpoints
  follow `vaulter-documents.revisions.checkpoint_interval_seconds` (300 s). Was: the head
  autosave row was rewritten in place within `autosave_debounce_seconds` (now deprecated and
  ignored). Stored checkpoints only change `keep_forever`.
- Changed validators: document content writes move `state_version` (412
  `document_state_mismatch`, `expectedStateVersion`) and never `v_nodes.sequence`. Was: every
  document save bumped the node sequence.
- Added `restored_from_revision_id` to file and document revisions; added
  `StaleDocumentStateException` and `CollaborationEpochMismatchException` (409
  `collaboration_epoch_mismatch`).

### Strict tenant isolation (AD10; PLAN2.P3.8)

- Add read-only `TenantResolver` and optional `ResolvesOwnerTenant`, with fail-closed boot validation in every environment. Strict mode stamps authoritative tenant IDs and checks current scope on PHP, HTTP, listings, Filament, queues and private scanner access.
- Replace delegated-only tenant warnings with central boundaries; retained handles and mutable supplied models cannot carry authority between tenants. Bearer shares retain only their token scope.
- Implement nested, fiber-local `withoutTenancy` for explicit maintenance; queued work never inherits it. See UPGRADE.md for resolver binding, existing data and middleware ordering.

### Unified uploads and durable content availability (AD13; PLAN2.P3.7, D26–D29)

- PHP, single HTTP, tus and S3 completion use counted, hashed, bounded streaming preparation and the same inspector chain. Persist detected MIME separately from the declared MIME; inspectors reject before SQL/CAS writes.
- Added scoped immutable scan evidence, independent global/profile rollout boundaries, atomic revision obligations, fenced scanner attempts and bounded recovery/backfill commands. Infection remains sticky across reupload, copy, restore and rescan; required bytes need compatible clean evidence.
- Added `UploadOperations` and context-first upload actions. Completion stores exact result pointers/fingerprint and durable cleanup intent atomically; replay checks current ownership/scope/visibility and never re-ingests. Failed outer-commit cleanup is recoverable.
- PHP/share downloads use fresh content authorization and exact immutable selection with `private, no-store`. Inline document export requires an explicit operation context. Direct signed delivery is an explicit opt-in with bounded TTL and revocation.

### Canonical authorized Drive API (AD06–AD08, AD13, AD17, AD23; PLAN2.P3.3)

- Removed `Vaulter::for/forStorage` and flat DriveHandle mutation/list methods.
  Use `drive()/findDrive()` and `nodes()/folders()/files()/trash()/access()/shares()/activity()`.
  Handle identity/correlation transformations are immutable; HTTP and Filament
  use the same explicit-actor permission boundary: hidden nodes return 404,
  visible capability denials 403, and missing actors 401.
- Activated typed uploads, append-only revision restore and BlobStore-backed
  `FileStream` reads. `files()->addRevision()` returns `FileRevision` instead of
  the old `uploadVersion()` Node result. Node lists use signed forward cursors,
  bounded NodePage results and no totals. Rename resolution tries suffixes
  (2)–(6); upload-only AddRevision checks Edit on the target.
- Moved document type/exporter registration out of core into
  `Documents::types()->register()/all()` and
  `Documents::exporters()->register()/get()/formats()`, preserving current
  definitions, exporter FQCN/instance registration and configured formats.
- Added an upgrade migration for nonnegative signed BIGINT `v_nodes.sequence`,
  default 0, with a 64-bit PHP requirement. Changed existing aggregates increment
  once under lock, including current Document/Yjs writers and revision restore;
  new nodes stay at 0. Optional stale expectations fail with 412 before side effects,
  exhaustion fails with 409, and effective no-ops check expectations without a bump/event.
- Purge physically removes each subtree and dependent Document/Yjs rows atomically;
  batch cleanup reports failed roots, continues and exposes failure. Folder/file
  copy preflights the whole subtree and rejects unsupported Document/Shortcut
  payloads before inserts. See UPGRADE.md for the migration map and current scope.

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
Public downloads now enforce versioned required-mode evidence through PLAN2.P3.7;
metadata remains visible under current ACL.

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

### Added

- Full profile builder, immutable upload policies and container-resolved frozen
  registry snapshots (AD05, PLAN2.P3.6). Profiles control drive defaults, owners,
  comments, document types and retention. OperationGuard enforces effective
  policy for user and system actors; maintenance purge has no upload/egress gate.
- Schema-free scan modes/specs and independent global/profile predicates
  (PLAN2.D26, PLAN2.D27). Canonical and alias conflicts fail validation; persisted
  scan activation and evidence remain PLAN2.P3.7.

- Core operation security primitives: `Actor`/serializable `ActorRef`, immutable
  `OperationContext`, scoped `NodeLocator` and `OperationGuard` (AD05, AD07,
  PLAN2.P3.1). Node models supply persisted keys; caller attributes and relations
  cannot establish authority. Missing actor maps to `actor_required`/401, hidden
  or foreign nodes to 404, and visible capability denials to 403. Public operation
  categories distinguish metadata, mutations, content egress and purge.
  Profile and tenant enforcement connect in the following integration steps.

### Changed

- Profile APIs use `key`/`owners` and named feature methods; duplicate keys and
  post-boot mutation fail explicitly. Implicit feature flags inherit globals;
  explicit enabling cannot override a globally disabled feature. New drive
  disk/quota defaults, per-drive trash retention and existing document/comment
  HTTP policy boundaries now use the frozen profile (AD05, PLAN2.P3.6).

- Configuration now belongs to each package (`vaulter`, `vaulter-media`, `vaulter-drive`,
  `vaulter-documents`, `vaulter-azgard`, `vaulter-filament`) and is read through typed
  `final readonly` `Configuration\*Config` singletons (AD26, P2.5). Removed the static
  configuration/model helpers; architecture checks prohibit direct Vaulter-key reads
  outside configuration classes. Media publishes `vaulter-media.php` in place of
  `vaulter-cas.php`.
- Published 0.2 configuration keys normalize with one `E_USER_DEPRECATED` per old key;
  conflicting custom old/new values fail boot. Security bindings, models and profiles
  validate at boot. Exact legacy built-in FQCN values translate without PHP class aliases;
  custom strings are preserved. See UPGRADE.md for the migration map.
- Documents are enabled when their package is installed; blobs and file revisions are
  always enabled. Removed `features.blobs`, `features.versions` and
  `features.document_versions`; old `false` values warn and are ignored. Comments now
  default to `true` at `vaulter-documents.comments.enabled`; explicit legacy `false`
  remains false. Collaboration stays opt-in. Exporters remain a format-to-FQCN map;
  `enabled_document_types` and `features.spreadsheets` remain core keys in this transition.

- AzGuard's driver, guard contract, permission map and fake now live in
  `vaulter-azgard`, which automatically registers the `azgard` driver and real
  adapter. Its permission configuration uses `vaulter-azgard.*`; core no longer
  contains the integration (P2.4; see UPGRADE.md).
- Media GC and reconcile commands are `vaulter:blobs:gc` and
  `vaulter:blobs:reconcile`; previous signatures have no aliases.
- Filament exposes `DriveResource`, `ListDrives` and `ViewDrive`, with Drive labels
  and the `vaulter-filament.resources.drive` toggle.

- Document classes now use revisions, canonical Action/Input names and domain controller namespaces (AD03, AD04). Documents registration is split into independently tested bindings, HTTP and collaboration registrars.

- Drive classes use canonical names and domain namespaces; DriveHandle exposes
  `model(): Drive` and `profile(): Profile` through its core interface, and the
  provider delegates to tested registrars (AD03, AD04). Unused WorkspaceRef and
  BulkAction removed; see UPGRADE.md for the FQN migration map.

- Canonical 0.3 schema and upgrade migrations №1, №2, №4, №8, №9: drives,
  file revisions, ordinary grant uniqueness, event and subscription conversion;
  fresh tables are independent of runtime features (P2.7; see UPGRADE.md).

- Canonical core API: Storage → Drive, FileVersion → FileRevision, Panels → Profiles,
  HostRoleDriver/PermissionResolver → PermissionDriver, Engines → Handlers; no PHP aliases
  (AD03, AD04; see UPGRADE.md for the complete FQN map).
- File handlers use vendor/name `key()` and `viewer()`; viewer descriptors expose key/props,
  and `NodePresenter::present()` replaces `describe()` (AD14).
- Activity and webhook enums merged into EventType with canonical 0.3 values;
  webhook subscriptions exclude upload lifecycle events (AD03, AD04).
- Core provider delegates to five registrars; own-tables profile API and hooks, including
  the previously invoked booted(), removed. Profile scaffolding is vaulter:make:profile
  (AD05, AD11). Configuration keys normalize in P2.5 (see above).


### Added
- `BlobStore::commitStaged()` for quota-first uploads and reusable staging across SQL retries (AD21).
- Lossless `BlobScope`, `scope_key`, upgrade migration №7 and resumable
  `vaulter:blobs:repair-scopes --dry-run|--execute` (AD20).
- Batch ACL evaluation (`AccessResolver::evaluateMany`, `AclFolder`), `NodePage`,
  `ExactListing`, signed query-bound cursors and `listing.scan_budget` (AD12).
- Independent `ids.host_keys` configuration (default `varchar(64)`), `HostKeyColumns`
  and `VaulterSchema::hostKey()` for host-owned keys (N02, AD25).
- `InvalidDestinationException` (409 `invalid_destination`), `NodeNotTrashedException`
  (409 `node_not_trashed`), `InvalidNodeNameException` (422 `validation_failed`) and
  `Vaulter\Support\NodeName::normalize()` for tree and name invariants (AD13).
- `VaulterDatabase::lockTreeShared()`: the shared mode of the drive tree lock, taken by
  creates, uploads, copies and renames (AD13, D21).

### Changed
- CAS commit and content-key contracts take `BlobScope`; UUID/ULID scopes use
  NULL-free uniqueness and the same `cas:{scope_key}:{sha256}` lock in commit, GC
  and reconcile. Existing blob paths and disks remain authoritative (AD20).
- Children, search, trash, shared, folder children and Filament now display only nodes
  with effective View for every driver. Shared uses cursors instead of page/total;
  REST listings omit totals and reuse batch ACL results for abilities (AD12).
- `vaulter-documents` and `vaulter-filament` require `vaulter-drive` at runtime (AD02).
- HTTP routes use separate `routes.middleware.authenticated`, `public` and `hub`
  stacks; protected routes default to `auth:sanctum`. Legacy `middleware` overrides
  only the authenticated stack, and metrics are appended to all stacks (AD31).
- Domain events carry a readonly `eventId` ULID with an optional final constructor
  argument for replay. Webhook `event_id` now identifies an event occurrence rather
  than its resulting state; retries retain the same id (AD22).
- Domain HTTP errors include a stable `code` through `ProblemException` (AD29).
  Name conflicts now return 409 `node_name_conflict` (previously 422); assembled
  upload MIME mismatches return 422 `upload_rejected` with `reason: mime_mismatch`
  (previously 415). Existing validation fields and tus headers are preserved.
- `vaulter-azgard` now follows the public AzGuard 0.3 line and its widened
  `BackedEnum` panel/role contracts; CI checks out the latest public AzGuard commit
  directly from GitHub without a private repository token.

### Fixed
- Atomic storage quota reservations for uploads and subtree copies (E2, AD21),
  fresh head-size deltas, file revision counters under `FOR UPDATE`, canonical
  drive/node/file/quota/CAS lock order and three-attempt transaction retries.
- A node created with no parent (notably documents) is placed under the drive root
  instead of becoming a hidden second root; upgrade migration №6 moves existing second
  roots under their root (renaming clashes ` (legacy N)`, logged as `node.moved`) and
  adds a unique one-root-per-drive index. The repair is irreversible (N04, AD13).
- Create, upload, move, copy and restore only target live folders of the same drive,
  checked under row locks; the root can no longer be renamed, moved, copied, trashed
  or deleted; moves take the drive tree lock so concurrent moves cannot form a cycle;
  `forceDelete` only purges trashed nodes and node names are normalized (N05, AD13).
- Host owner, subject, author, tenant and actor columns now use `ids.host_keys`
  instead of Vaulter's entity-ID strategy. Upgrade migration №3 preserves existing
  values and restores the strategy's types on rollback (N02, AD25).
- Repository metadata, exception documentation, collaboration errors and install
  star prompt now point to `axiomasoft/vaulter`; package vendor names stay
  `axioma-studio/vaulter-*` (N20/E6, AD02).
- Filament Move filters destinations and checks workspace creation and destination
  Create access at action time, notifying the actor on denial (N14).
- Vaulter resource resolution no longer registers global `node`/`storage` bindings
  that interfere with host routes. Missing resources return their 404 problem code (N10, AD31).
- Outbox deduplication no longer loses repeated rename or trash/restore operations
  returning to the same state; only a replay of the same event id is deduplicated (E8).
- All domain exceptions now return their documented 4xx status instead of HTTP 500
  on JSON requests and Vaulter routes (N11, AD29); configuration and infrastructure
  errors retain Laravel's default handling.
- Vaulter transactions, CAS/tree locks, raw queries and after-commit callbacks now use
  `vaulter.connection` through the new `Vaulter\Database\VaulterDatabase` singleton,
  preserving atomicity when the host default connection differs (N01, AD25).
  UploadSession, UploadPart, NodeComment and DocumentRevision also resolve that
  connection so their writes cannot autocommit outside those transactions.
- Release workflows now keep the root npm lockfile, test the npm workspace before
  publishing, pass the PHP split token through the action's supported environment,
  reject tags whose exact SHA lacks the ordinary CI checks, and publish the GHCR
  image under the source repository owner.

### Removed

- Reverb Yjs collaboration driver and its configuration; use Hocuspocus. Routes, request names, JSON, error codes and revision storage columns remain unchanged.

## [0.2.0] — 2026-07-15

### Changed
- **Mutation testing moved off Infection onto native Pest mutation, with real thresholds
  everywhere** (VLT-CANON Open risk #4 / VLT-REMED D38). Every package lane —
  `documents`, `drive`, `media`, `filament` — carried an Infection step under `|| true`, and
  **none of them had ever executed a single mutant**: Infection 0.33.3 ships no Pest adapter
  (its `testFramework` enum is `phpunit`/`phpspec`/`codeception`/`testo`), so it could not map
  this suite's tests to mutants at all. `|| true` reported that as green. The root lane had
  already moved to `pest --mutate` for `vaulter-core`; the four package lanes now follow.
  Each has its own mutation job with a coverage driver and a **real threshold with no
  `|| true`** — Pest exits non-zero below it. `--min=95` (= `floor(Score) - 5`) from a measured
  baseline: `core` and all four packages score **100.00%** (documents 1472 mutants, drive 1569,
  media 380, filament 189). The four `infection.json5` files are gone, `infection/infection` is
  out of every `require-dev`, and the `mutate` composer scripts plus `make mutate` (which still
  invoked the `vendor/bin/infection` removed in P5.9) now call Pest.
- **Re-platformed into an embeddable virtual document storage** (#20). The document/folder
  model gave way to a **Drive** domain — storages and a recursive node tree (folders/files) —
  backed by a content-addressable blob store (CAS). Collaboration became **node-attached** and
  standardised on **Hocuspocus** (Reverb is now for notifications, not collaboration). The
  release artifacts are the six split Composer packages `vaulter-core`, `vaulter-media`,
  `vaulter-drive`, `vaulter-documents`, `vaulter-filament` and `vaulter-azgard`.
- **`@vaulter/vue` (npm) ships outside this git tag, on its own track** (D3). The monorepo git
  tag `v0.2.0` is a Composer/PHP versioning event covering the six packages above; the Vue client
  (`@vaulter/vue`, `0.0.0`) versions on its independent npm cycle and is not part of this
  release's frozen public surface.
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
  (P2 #5, D2). The document Actions (`CreateDocumentAction`, `UpdateDocumentContentAction`,
  `CreateNamedRevisionAction`, `RestoreDocumentRevisionAction`, `AddCommentAction`,
  `ResolveCommentAction`, `DeleteCommentAction`, `PersistCollaborationStateAction`), the default
  `Access\HostUserResolver`, the `Collaboration\*` drivers (`HocuspocusCollaborationDriver`,
  `ReverbNodeCollaborationDriver`, `CollaborationDriverManager`, `CollaborationCall`,
  `CollaborationCallResolver`), the document handlers (`TipTapHandler`, `PreviewHandler`,
  `DownloadOnlyHandler`, `UniverHandler`), the `HtmlExporter` / `MarkdownExporter`, the
  `NodeComment` / `DocumentRevision` models and the document/collaboration/comment HTTP
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
  `Documents\Attributes\TiptapExtensions`; `Exceptions\Documents\InvalidDocumentContentException` →
  `Documents\Exceptions\InvalidDocumentContentException`. The store-time allow-list, read-time
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
  `UniverHandler`). The baseline `PreviewHandler`/`DownloadOnlyHandler` are also registered under
  the container tag `'vaulter.handlers'`, so a host carrying a pre-P0.4 published config
  (`'handlers' => []`, which wins the shallow config merge) does not silently lose preview. See
  `UPGRADE.md`.
- **BREAKING (pre-1.0): `v_node_document_versions.format` no longer carries a schema
  `default('tiptap')`; the autosave and Yjs-snapshot writers now stamp the format explicitly**
  (VLT-CANON P0.4). `UpdateDocumentContentAction` and `PersistCollaborationStateAction` previously let
  the DB default fill `format` when appending a NEW version row to an existing document — so a
  **markdown or univer document's autosave rows were stamped `'tiptap'` regardless of its real
  format**. They now carry the head version's format forward. The value reaches clients through
  `DocumentRevisionResource.format` and the version-history API: **a client branching on
  `format === 'tiptap'` must be updated.** `CreateNamedRevisionAction`'s no-head fallback likewise
  changed from the literal `'tiptap'` to `DocumentType::RichText->value` (`'richtext'`). No
  backfill ships — pre-existing rows keep their stored value; `format` is an advisory label, the
  handler is always resolved from the node's mime (D4). See `UPGRADE.md`.
- **BREAKING (pre-1.0): `HandlerCapabilities::$richText` now defaults to `false` (was `true`)**
  (VLT-CANON P0.5). A kind-neutral handler makes no rich-text claim unless it opts in. The two
  built-in editable handlers are unaffected in behaviour (`TipTapHandler` now declares
  `richText: true` explicitly; `UniverHandler` already declared `richText: false`); a
  third-party handler relying on the implicit `true` default must now set it explicitly.
- **`NodeExportService::contentOf()` is now kind-neutral** (VLT-CANON P0.5, closing a P0.2/P0.3/
  P0.4 open finding). A non-rich-text node's export now returns its own `OpaqueDocumentContent`
  shape instead of decaying to an empty TipTap document — `HtmlExporter`/`MarkdownExporter`'s
  existing narrowing guard now correctly rejects it with `UnsupportedDocumentContentException`
  instead of silently rendering blank content. `CreateDocumentInput::$format`'s default
  changed from the invalid literal `'tiptap'` to `DocumentType::RichText->value` (`'richtext'`).
  See `UPGRADE.md`.
- **The AzGuard bridge is named `axioma-studio/vaulter-azgard`, not
  `axioma-studio/vaulter-permissions-azgard`** (VLT-CANON P2.1, final structural canon). The
  package directory moved `packages/permissions-azgard/` → `packages/azgard/` and the namespace
  `Vaulter\Permissions\Azgard\*` → `Vaulter\Azgard\*`. The core-owned contract seam
  `Vaulter\Contracts\Access\AzgardGuard` is unaffected — only the bridge package's own name and
  namespace changed. **Note this is not a released-package break:** the bridge has never been
  published (the repo carries no tag, and the split/Packagist pipeline is tag-triggered), so
  `vaulter-permissions-azgard` never existed on Packagist and the `Added` entry below lists the
  bridge under its final name. Only a host tracking this monorepo's dev branch — requiring the old
  package name via a path/VCS repo, or importing `Vaulter\Permissions\Azgard\*` FQCNs — has to
  update. See `UPGRADE.md`.
- **BREAKING (pre-1.0): catch-all folders resolved by role** (VLT-CANON P2.3, final structural
  canon). `documents/src/Documents/` (tautological name), the TipTap-only slice of
  `documents/src/Support/`, and the pure-helper slice of `drive/src/Uploads/` were dissolved into
  the domain-service folder/layer matching each class's actual role: `TipTapHandler`/`UniverHandler`
  → `Vaulter\Documents\Engines\Handlers`; `NodeExportService` → `Vaulter\Documents\Export`;
  `CollaborationSessionService` → `Vaulter\Documents\Collaboration`; `TipTapRenderer`/
  `HasTiptapExtensions` → `Vaulter\Documents\Support\TipTap(\Concerns)`; `MagicByteSniffer`/
  `StagedUpload`/`UploadModels` → `Vaulter\Drive\Support\Uploads`. Pure move, zero behaviour
  change. See `UPGRADE.md`.
- **BREAKING (pre-1.0): `documents/src/Access/HostUserResolver` moved to
  `Vaulter\Documents\Collaboration\HostUserResolver`** (VLT-CANON P2.4, final structural canon).
  `Access/` held a single class that wasn't an access-control class — it implements the
  collaboration webhook's `HostUserResolver` contract, not anything ACL-shaped — so it now sits
  next to the collaboration drivers it serves. core's `Access/` and `Webhooks/` domain-service
  folders were audited against ADR-0017 in the same pass and left unchanged (they conform). Pure
  move, zero behaviour change. See `UPGRADE.md`.
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
- **`@vaulter/vue` explicit engine-component registry** (VLT-CANON P1.2) —
  `registerEngineComponent(id, component)` / `unregisterEngineComponent(id)` /
  `resolveEngineComponent(id)` / `registeredEngineComponentIds()` / `clearEngineComponents()`,
  formalising `VaulterDocumentNode`'s `ui.component`→editor mapping as a public, explicitly
  imported API (not an ambient global). `componentMap` stays fully supported and takes
  precedence; the registry is an additional global fallback for hosts with many mount points.
  In development builds, an unmapped handler now logs one `console.warn` per unique
  `ui.component`/`id`. Purely additive — no existing prop or behaviour changed.
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
- **AzGuard bridge** (`axioma-studio/vaulter-azgard`) — a real `AzgardGuard` adapter
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

[Unreleased]: https://github.com/axioma-studio/vaulter/compare/v0.2.0...HEAD
[0.2.0]: https://github.com/axioma-studio/vaulter/releases/tag/v0.2.0
