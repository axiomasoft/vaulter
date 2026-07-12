---
title: Architecture
description: The layered architecture of Vaulter, its domain structure, the contracts a host plugs into, and the 13 ADRs that decide it.
outline: deep
---

# Architecture

Vaulter is a strictly-typed, layered Laravel package built to **embed** in an existing
application. The guiding rule is a clean split of ownership:

- **Everything the host must provide is a contract** — authorization, the byte plane, the
  collaboration transport, the share-token shape, the document-format handlers. Each is an
  interface bound in the container and swappable through config.
- **Everything Vaulter owns is `final` and typed** — the effective-permission engine, the
  Drive Actions, the CAS lifecycle, the panel registry. These are not contracts: they are
  the package's guarantees.

The result is one package that drops into a plain Laravel app *and* a multi-tenant CoreX SaaS
without a fork — see [ADR-0003](/adr/0003-embeddability-data-model) and its successors
[ADR-0009](/adr/0009-storage-panels) / [ADR-0011](/adr/0011-id-strategy).

::: tip Where to start
If you just want to use Vaulter, read [Quick Start](/guide/quick-start) and
[Configuration](/guide/configuration). This page is the mental model behind those — useful
when you extend a seam or debug a layer.
:::

## The request, end to end

```
@vaulter/vue  ──HTTP/JSON──►  Controllers ─► FormRequests ─► DTOs (Data\*)
              ──WebSocket──►  CollaborationDriver (Reverb | Hocuspocus)
       │
  AccessResolver (Vaulter-owned)  ◄── HostRoleDriver (host, swappable)
       │                              + v_node_grants ACL
  Actions (final readonly · transactional · dispatch domain Events)
       │
  Repositories (read / store split)
       │
  CAS:  BlobStore ─► ContentStore (sha256) ─► StorageDriver (Flysystem disk)
       │
  Models (swappable via config) ─► Database (v_* tables, one id-strategy)
```

A typical write — say `POST storages/{storage}/uploads` — flows top to bottom:

1. The **controller** route-model-binds the `{storage}` (or `{node}`) through the swappable
   model, then authorizes the action against the [`AccessResolver`](#authorization).
2. A **FormRequest** validates and a **DTO** (`Vaulter\Data\*`) carries the typed payload.
3. An **Action** (`final readonly`, e.g. `UploadFileAction`) opens the transaction, calls the
   **CAS** to stage + dedup the bytes, writes the `v_nodes` / `v_files` rows through **store
   repositories**, and dispatches a domain **Event** after commit.
4. Subscribers (`RecordsActivity`, `RecordsOutbox`) react to the event to write the activity
   feed and stage the transactional outbox — Vaulter never couples the Action to them.

The ergonomic entry point `Vaulter::for($entity)` returns a
[`DriveContext`](#the-drive-handle) (`Vaulter\Drive\DriveContext`, shipped by
`axioma-studio/vaulter-drive`) that wraps exactly this pipeline for programmatic use.

## Layers

### HTTP edge — Controllers, FormRequests, Resources

The thin edge. Routes live in `packages/core/routes/api.php`, mounted under
`config('vaulter.route_prefix')` (default `api/vaulter`). Controllers authorize, hand a DTO to
an Action, and serialize the result through a Resource that embeds a computed `abilities`
object so the Vue client renders only allowed actions.

`{storage}` and `{node}` route parameters bind to the **swappable** models
(`config('vaulter.models.storage|node')`) via `Route::bind` registered in the service
provider — so a host can point the config at a subclass and binding keeps working.

### Actions — `Vaulter\Drive\Actions\*` / `Vaulter\Documents\Actions\*` / `Vaulter\Actions\*`

One use case each, `final readonly`. An Action opens the transaction, enforces business rules,
calls repositories/CAS, and dispatches a domain event **after commit**. The Drive write set
(`Vaulter\Drive\Actions\*`, requires `axioma-studio/vaulter-drive`):
`CreateFolderAction`, `UploadFileAction`, `MoveNodeAction`, `RenameNodeAction`,
`CopyNodeAction`, `TrashNodeAction`, `RestoreNodeAction`, `ForceDeleteNodeAction`. Document-node
Actions live under `Vaulter\Documents\Actions\*` (requires `axioma-studio/vaulter-documents`);
core itself only keeps `Vaulter\Actions\{Access,Sharing,Webhooks}\*`.

::: info Authorization placement
Actions perform **no** access checks. Authorization is enforced once, at the HTTP boundary
(`NodeController` + `AccessResolver`), before the Action runs. `DriveContext` is likewise a
pure delegation surface. This keeps the access decision in exactly one place.
:::

### Repositories — read / store split

Reads (`NodeReadRepository`, `GrantReadRepository`, `StorageReadRepository`, …) build queries;
stores (`NodeStoreRepository`, `StorageStoreRepository`, …) perform writes and never open
transactions or dispatch events — the Action owns that. The tree-descent reads use
`staudenmeir/laravel-adjacency-list` recursive relations scoped by `storage_id`.

### Models — swappable, resolved through `Config`

Vaulter never references a concrete model class directly in domain code; it resolves through
`Vaulter\Support\Config` against `config('vaulter.models.*')`. A host subclasses a model to add
ULID behaviour, tenant scoping, or audit. Models are the one part of the package that is **not**
`final` (they must be extendable); everything else is.

### Value objects, DTOs, enums

`Vaulter\Data\*` are the `final readonly` DTOs Actions consume. `Vaulter\ValueObjects\*` hold
domain values (`TipTapContent`, `FileDescriptor`, `DocumentCapabilities`, the ACL
`Subject`/`Decision`/`GrantSet`/`AclScope`). `Vaulter\Enums\*` are the sealed vocabularies
(`Ability`, `NodeKind`, `BlobStatus`, …).

## Domain structure

Since the P2 #5 package extraction split the monolith, `axioma-studio/vaulter-core`
(namespace `Vaulter\`) holds only the cross-cutting seams — authorization, CAS
value-objects/contracts, sharing, panels, webhooks, activity — under `packages/core/src`;
Drive and Documents each ship as their own package:

| Directory (core) | Responsibility |
|---|---|
| `Access/` | `AccessResolver` (the effective-permission engine), `HostRoleDriverManager`, `HostUserResolver`. |
| `Drivers/Permission/` | The shipped `HostRoleDriver` lanes: `database`, `gate`, `spatie`, `azgard`. |
| `Cas/` | `BlobLock` — the last CAS class left in core; the store engine itself moved to `vaulter-media`. |
| `Panels/` | `Panel` builder, `StoragePanelProvider`, `PanelRegistry`, `PanelTablesBuilder`, `PanelMigrationGenerator`. |
| `Schema/` | The id-strategy classes (`AutoIncrementIds`/`UlidIds`/`Uuid7Ids`) + `IdStrategyResolver`, `PanelTablesBuilder` columns. |
| `Sharing/` | The bearer-link channel: `ShareResolver`, `ShareContext`, `ShareResult`. |
| `Webhooks/` | The reliability layer: `OutboxRecorder`, `OutboxRelay`, `WebhookSigner`. |
| `Activity/` | The append-only activity feed (`ActivityRecorder`, `RecordsActivity`). |
| `Actions/` · `Data/` · `Events/` | Access, Sharing and Webhooks only — Drive and Documents actions/events now live in their own packages (see below). |
| `ValueObjects/Documents/` · `Exceptions/Documents/` | Format-agnostic value objects that both Drive and Documents depend on: `TipTapContent`, `DocumentCapabilities`, `FileDescriptor`, `HandlerUi`, `InvalidTipTapContentException`. |
| `Contracts/` | Every host-facing seam, including `Contracts/Drive/*` and `Contracts/Documents/*` (the interfaces the split packages implement). |
| `Models/` · `Repositories/` | Access, Activity, Cas, Drive, Sharing, Webhooks — the shared/read-side pieces; Drive and Documents ship their own write-side models too. |
| `Testing/` | Ship-with-package doubles: `NullRoleDriver`, `FakeAzgardGuard`. |

| Package | Namespace | Adds |
|---|---|---|
| `axioma-studio/vaulter-drive` | `Vaulter\Drive\` | `DriveContext`, `StorageManager`, the Drive Actions (`CreateFolderAction`, `UploadFileAction`, `MoveNodeAction`, …) and their Events. |
| `axioma-studio/vaulter-documents` | `Vaulter\Documents\` | Document-node Actions/Events/Models, `DocumentHandlerRegistry`, node export, the node-collaboration session service. |
| `axioma-studio/vaulter-media` | `Vaulter\Media\` | The CAS engine: `Cas\{BlobStore,Sha256ContentStore,FlysystemStorageDriver,BlobGarbageCollector,BlobReconciler,StagedTempObject}`. |
| `axioma-studio/vaulter-filament` | `Vaulter\Filament\` | The Filament 5 admin plugin. |
| `@vaulter/vue` | — | The Drive UI + composables (npm). |

All are siblings that build on `vaulter-core`; Drive and document-editing features require
installing the matching package (see [Installation](/guide/installation)).

## The Drive handle

`Vaulter::for($entity, panel: '…')` is the primary integration entry point. It is a thin
facade over `VaulterManager`, returning a `DriveContext` from `axioma-studio/vaulter-drive`
(the package must be installed for this handle to be usable):

```php
use Vaulter\Facades\Vaulter;

// Get-or-create the storage mounted on $project in the 'default' panel,
// returning an ergonomic DriveContext.
$drive = Vaulter::for($project);

$folder = $drive->createFolder('Contracts');
$node   = $drive->upload($request->file('doc'), into: $folder);
$drive->rename($node, 'signed.pdf');
$children = $drive->list($folder); // Collection<Node>
```

`VaulterManager::for()` get-or-creates the `v_storages` row through `StorageManager` (the
polymorphic `(owner_type, owner_id, panel)` triple is the idempotency key, guarded by a partial
unique index), auto-provisions a `kind=folder` root node, and returns a `DriveContext` whose
methods each delegate to one Action or the read repository. The handle adds no business logic —
only a pre-resolved storage and upload-source normalisation (a PSR stream, `SplFileInfo` /
`UploadedFile`, an open resource, or a raw string all normalise to a seekable stream).

::: details VaulterManager — the full facade surface
`for()`, `forStorage()`, `panel()`, `panels()`, `registerPanel()`,
`resolvePermissionsWith()`, `permissionResolver()`, `registerDocumentType()`,
`documentTypes()`, `registerHandler()`, `handlers()`, `registerExporter()`,
`exporter()`, `exporterFormats()`, `driver()`. See the
[Recipes](/guide/recipes) for the extension points (`registerHandler` / `registerExporter` /
`resolvePermissionsWith`).
:::

## Storage Panels — the embeddability axis

A **Storage Panel** is a named config envelope bound to *any* host entity, living over the
**one shared `v_*` schema with no tables of its own by default** (zero migrations). It is the
isolation/configuration axis, separate from the *storage type* (the policy preset
`private | team | public`).

A panel is built fluently and registered by FQCN only (config-cache-safe — no closures):

```php
use Vaulter\Panels\Panel;
use Vaulter\Panels\StoragePanelProvider;

final class ProjectDriveStoragePanel extends StoragePanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('project_drive')
            ->host(\App\Models\Project::class)
            ->storageType('team')
            ->feature('document_versions', true);
    }
}
```

Register it under `config('vaulter.panels')`. Unset values fall back to the global config
(**override → prefix → global**), so a panel declares only what it overrides. The
`PanelRegistry` is a container singleton that **validates every panel at boot** (H.1):
referenced model/pipe/resource FQCNs must exist and satisfy their contract, and a per-panel id
strategy is rejected on the shared schema — a broken panel fails loud at boot rather than
corrupting a request. Scaffold one with `php artisan vaulter:make-panel <name>`.

The **own-tables** mode (declare `->tablePrefix()` / `->tables()`) is the rare advanced path
for a schema-divergent domain; `vaulter:panel:migrations <panel>` then generates per-panel
tables through the same `PanelTablesBuilder` definition so the schema never drifts. See
[ADR-0009](/adr/0009-storage-panels).

## Data model

Everything lives in the prefixed `v_*` schema (`config('vaulter.table_prefix')`, default `v_`),
with one id strategy per install (`config('vaulter.ids.default')`, default `ulid`).

| Table | Holds |
|---|---|
| `v_storages` | One storage per `(owner_type, owner_id, panel)`; carries the disk + root node id. |
| `v_nodes` | The **unified tree** — every folder, file, document, shortcut as one row with `parent_id` + a `kind` discriminator ([ADR-0005](/adr/0005-unified-node-tree)). |
| `v_files` / `v_file_versions` / `v_file_version_blobs` | The binary side tables: a file's head + its version chain + the edges to blobs. |
| `v_blobs` | The CAS: one row per `(scope, sha256)` with a `BlobStatus` lifecycle ([ADR-0006](/adr/0006-content-addressed-blobs-gc)). |
| `v_node_grants` | The per-node ACL with inheritance + deny-veto. |
| `v_node_shares` | Bearer share links — hash-only token storage ([ADR-0013](/adr/0013-two-sharing-channels)). |
| `v_node_document_versions` | Documents: node-attached document version history (gated by `document_versions`). |
| `v_upload_sessions` / `v_upload_parts` | Resumable upload sessions. |
| `v_activity` | The append-only activity feed. |
| `v_outbox` / `v_webhook_endpoints` / `v_webhook_deliveries` | The transactional-outbox + webhook reliability layer. |

The tree is an **adjacency list**: move/rename is a single `UPDATE` of `parent_id` / `name`
(no path rewriting). Descent uses recursive CTEs scoped by `storage_id`; recursive descendant
*aggregation* (`whereHas` / `withCount('descendants')`) is **banned from request paths** (fatal
on MariaDB) and enforced by an arch test — see [Troubleshooting](/guide/troubleshooting#mariadb-cte).

## Authorization

Two lanes, evaluated by the Vaulter-owned `AccessResolver` ([ADR-0007](/adr/0007-pluggable-authorization)):

- **`HostRoleDriver`** — the host's pluggable, per-panel role lane. It never sees a `Node` and
  never deals in magic-string actions; it answers `resolve(Subject, Authorizable, AclScope)`,
  `override(...)` (super-admin / `Gate::before`), `subjectsFor(Authorizable)` (actor → users +
  roles + teams) and `canCreateInWorkspace(...)`. Shipped lanes: `database` (default,
  standalone), `gate`, `spatie`, `azgard`, and the non-prod `null`. Swap via
  `config('vaulter.drivers.permission')`.
- **`AccessResolver`** — Vaulter-owned, `final`, **not** swappable. It runs the canonical
  algorithm over `v_node_grants` combined with the driver.

The algorithm (deny-veto, explicit-beats-inherited):

1. **Host override** (super-admin) is absolute, both directions.
2. Walk ancestors **nearest-first**, bounded to the storage root, collecting direct +
   `scope=subtree` grants for the actor's subject-set; **stop** at the first ancestor with
   `inherit_grants = false`.
3. `aclAllow = max(allow bits)`; `deniedBits = OR(deny bits)`; a **nearer** explicit allow
   pierces a **farther** inherited deny (a same-node deny always wins).
4. `granted = max(aclAllow, roleAllow)` where `roleAllow` comes from the driver.
5. **Final veto**: if `deniedBits` covers the requested ability → DENY, regardless of role.

Abilities (`Vaulter\Enums\Access\Ability`) map to CRUDS bits: `View/Download/Comment → READ`,
`Edit → UPDATE`, `Create/Copy → CREATE`, `Move → UPDATE+CREATE`, `Trash/Restore/Delete → DELETE`,
`Share → SHARE`, `Manage → ALL`.

::: warning No driver = fail-loud in production
The open `null` driver is selectable **only outside production**; `HostRoleDriverManager`
throws `MissingHostRoleDriverException` if a production app resolves it. Bind a real driver
before you deploy — see [Troubleshooting](/guide/troubleshooting#no-permission-driver).
:::

The identity ACL and the **bearer-link** channel are evaluated as **separate passes, never
unioned** ([ADR-0013](/adr/0013-two-sharing-channels)) — a link cannot escalate via a viewer's
grants, and an identity grant cannot leak through a link.

## Content-addressed storage (CAS)

The binary plane is a content-addressed store, not `spatie/laravel-medialibrary`
([ADR-0008](/adr/0008-media-library-decision)). Three swappable services
(`config('vaulter.cas.*')`):

- **`ContentStore`** (`Sha256ContentStore`) — stages bytes to a seekable temp file and computes
  the `sha256` content address from the staged file (never from a live stream or a client-sent
  hash).
- **`StorageDriver`** (`FlysystemStorageDriver`) — the byte plane over a Flysystem disk, with a
  conditional `put` (`If-None-Match: *` on S3) and `temporaryUrl`.
- **`BlobStore`** — the race-free lifecycle orchestrator. Liveness is the *existence of an edge*
  in `v_file_version_blobs`; `ref_count` is a rebuildable cache. GC is two-phase and lock-first
  (`PENDING → COMMITTED → TOMBSTONED → DELETED`) with resurrection of a tombstoned blob that a
  concurrent upload re-references.

The physical key is **scope-qualified** `<scope>/ab/cd/<sha256>` where `<scope>` follows
`config('vaulter.dedup.scope')` (`tenant` by default), so one scope's GC can never delete
another scope's bytes ([ADR-0012](/adr/0012-dedup-scope)). See the
[blob GC recipe](/guide/recipes#blob-gc) and the
[custom storage driver recipe](/guide/recipes#s3-disk).

## Realtime collaboration

Yjs is the source of truth; TipTap JSON is the derived projection. The transport is swappable
behind `CollaborationDriver` ([ADR-0001](/adr/0001-collaboration-transport)):

- **`reverb`** (default) — Yjs over Laravel Reverb. The Vue client joins the **private**
  broadcast channel `vaulter.documents.{nodeId}`; the channel-auth callback gates it through the
  `AccessResolver` (≥ `View` to connect). No extra service, no room JWT.
- **`hocuspocus`** — an external Node hub (`hocuspocus-server/`). The client connects with a
  signed room JWT; the hub calls back into the `collab/authenticate` + `collab/store` webhooks.

## Extension points

Every seam a host plugs into, and where it is documented:

| Contract | Bind via | Documented in |
|---|---|---|
| `Contracts\Access\HostRoleDriver` | `config('vaulter.drivers.permission')` | [Recipes — custom permission driver](/guide/recipes#permission-driver) |
| `Contracts\Access\PermissionResolver` (legacy) | `Vaulter::resolvePermissionsWith()` / `config('vaulter.permission_resolver')` | [ADR-0002](/adr/0002-authorization-delegation) |
| `Contracts\Access\AzgardGuard` | container binding (host adapter) | [Troubleshooting — Azgard morphs](/guide/troubleshooting#azgard-morphs) |
| `Contracts\Cas\StorageDriver` / `ContentStore` / `BlobStore` | `config('vaulter.cas.*')` | [Recipes — swap to S3](/guide/recipes#s3-disk) |
| `Contracts\Collaboration\CollaborationDriver` | `config('vaulter.collaboration.drivers')` | [ADR-0001](/adr/0001-collaboration-transport) |
| `Contracts\Handlers\FileHandler` | `config('vaulter.handlers')` / `Vaulter::registerHandler()` | [Recipes — custom format handler](/guide/recipes#format-handler) |
| `Contracts\Handlers\DocumentExporter` | `config('vaulter.exporters')` / `Vaulter::registerExporter()` | [ADR-0004](/adr/0004-content-format) |
| `Contracts\Sharing\ShareTokenGenerator` | `config('vaulter.share.token_generator')` | [ADR-0013](/adr/0013-two-sharing-channels) |
| `Contracts\Uploads\UploadProtocolHandler` | container tag | [ADR-0006](/adr/0006-content-addressed-blobs-gc) |
| `Contracts\IdStrategy` | `config('vaulter.ids.strategies')` | [ADR-0011](/adr/0011-id-strategy) |
| `config('vaulter.models.*')` | config | [ADR-0003](/adr/0003-embeddability-data-model) |
| Domain `Events` | event subscriber | hook audit/notifications without Vaulter depending on them |

## The decision record

The reasoning behind every choice above lives in the [ADRs](/adr/). The full set:

| ADR | Decides | Status |
|---|---|---|
| [0001](/adr/0001-collaboration-transport) | Collaboration transport — `CollaborationDriver`, Reverb default vs Hocuspocus. | accepted |
| [0002](/adr/0002-authorization-delegation) | Authorization delegated to the host (`PermissionResolver`). | refined by 0007 |
| [0003](/adr/0003-embeddability-data-model) | Embeddability via swappable models + dual key type. | superseded by 0009 / 0011 |
| [0004](/adr/0004-content-format) | TipTap JSON content + append-only bounded version history. | accepted |
| [0005](/adr/0005-unified-node-tree) | One adjacency-list tree (`v_nodes`) for folders/files/documents/shortcuts. | accepted |
| [0006](/adr/0006-content-addressed-blobs-gc) | Content-addressed blobs + two-phase race-free GC. | accepted |
| [0007](/adr/0007-pluggable-authorization) | `HostRoleDriver` (pluggable) + `AccessResolver` (owned), deny-veto. | accepted |
| [0008](/adr/0008-media-library-decision) | A custom CAS instead of `spatie/laravel-medialibrary`. | accepted |
| [0009](/adr/0009-storage-panels) | Storage Panels — config-first, zero-migration envelopes. | accepted |
| [0010](/adr/0010-php-npm-monorepo-release) | One repo, two package managers, two release channels. | accepted |
| [0011](/adr/0011-id-strategy) | `bigint | ulid | uuid7` behind an `IdStrategy` seam (ULID default). | accepted |
| [0012](/adr/0012-dedup-scope) | Per-tenant dedup by default + scope-qualified storage keys. | accepted |
| [0013](/adr/0013-two-sharing-channels) | Identity ACL and bearer links evaluated as separate passes. | accepted |

Two supersession chains to keep in mind: **0003 → 0009 / 0011** (embeddability mechanics) and
**0002 → 0007** (authorization delegation).

## Next

- [Recipes](/guide/recipes) — worked, copy-pasteable integrations of these seams.
- [Troubleshooting](/guide/troubleshooting) — the failure modes this architecture creates and
  how to avoid them.
- [Configuration](/guide/configuration) — every config key, by section.
