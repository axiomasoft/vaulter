---
title: Architecture
description: The layered architecture of Vaulter, its domain structure, the contracts a host plugs into, and the ADRs that decide it.
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
If you just want to use Vaulter, read [Quick Start](/getting-started/quick-start) and
[Configuration](/guides/configuration). This page is the mental model behind those — useful
when you extend a seam or debug a layer.
:::

## The request, end to end

```
@vaulter/vue  ──HTTP/JSON──►  Controllers ─► FormRequests ─► DTOs (Data\*)
              ──WebSocket──►  CollaborationDriver (Hocuspocus)
       │
  AccessResolver (Vaulter-owned)  ◄── PermissionDriver (host, swappable)
       │                              + v_node_grants ACL
  Actions (final readonly · transactional · dispatch domain Events)
       │
  Repositories (read / store split)
       │
  CAS:  BlobStore ─► ContentStore (sha256) ─► StorageDriver (Flysystem disk)
       │
  Models (swappable via config) ─► Database (v_* tables, one id-strategy)
```

A typical write — say `POST v1/drives/{drive}/files` — flows top to bottom:

1. A single-action **controller** resolves the `{drive}` (or `{node}`) id through the
   tenant-scoped `HttpResourceResolver` — there is no route-model binding — and calls the
   operations as the request's user (`Vaulter::drive($drive)->actingAs($user)`), which
   authorize against the [`AccessResolver`](#authorization).
2. A **FormRequest** validates and a **DTO** (`Vaulter\Data\*`) carries the typed payload.
3. An **Action** (`final readonly`, e.g. `IngestFileAction`) opens the transaction, calls the
   **CAS** to stage + dedup the bytes, writes the `v_nodes` / `v_files` rows through **store
   repositories**, and dispatches a domain **Event** after commit.
4. Subscribers (`RecordsActivity`, `RecordsOutbox`) react to the event to write the activity
   feed and stage the transactional outbox — Vaulter never couples the Action to them.

The ergonomic entry point `Vaulter::drive($owner)` returns a
[`DriveHandle`](#the-drive-handle) (`Vaulter\Drive\DriveHandle`, shipped by
`axioma-studio/vaulter-drive`) that wraps exactly this pipeline for programmatic use.

## Layers

### HTTP edge — Controllers, FormRequests, Resources

The thin edge. Each package keeps the routes of its own concept (`packages/drive/routes/drive.php`,
`packages/documents/routes/documents.php`, `packages/webhooks/routes/webhooks.php`), mounted by its module under
`config('vaulter.routes.prefix')` (default `api/vaulter`) and carrying the `vaulter.module:<id>` middleware. Controllers authorize, hand a DTO to
an Action, and serialize the result through a Resource that embeds a computed `abilities`
object so the Vue client renders only allowed actions.

`{drive}` and `{node}` route parameters are resolved by the tenant-scoped `HttpResourceResolver` against the
**swappable** models (`config('vaulter-drive.models.drive|node')`) — so a host can point the config at a subclass and
resolution keeps working.

### Actions — `Vaulter\Drive\Actions\*` / `Vaulter\Documents\Actions\*` / `Vaulter\Webhooks\Actions\*`

One use case each, `final readonly`. An Action opens the transaction, enforces business rules,
calls repositories/CAS, and dispatches a domain event **after commit**. The Drive write set
(`Vaulter\Drive\Actions\*`, requires `axioma-studio/vaulter-drive`):
`CreateFolderAction`, `IngestFileAction`, `MoveNodeAction`, `RenameNodeAction`,
`CopyNodeAction`, `TrashNodeAction`, `RestoreNodeAction`, `PurgeNodeAction`. Document-node
Actions live under `Vaulter\Documents\Actions\*` (requires `axioma-studio/vaulter-documents`); the webhook
endpoint Actions live in `Vaulter\Webhooks\Actions\*` (`axioma-studio/vaulter-webhooks`). The kernel has none: it
only runs operations.

::: info Authorization placement
Actions perform **no** access checks. Authorization is enforced once, at the HTTP boundary
(`NodeController` + `AccessResolver`), before the Action runs. `DriveHandle` is likewise a
pure delegation surface. This keeps the access decision in exactly one place.
:::

### Repositories — read / store split

Reads (`NodeReadRepository`, `GrantReadRepository`, `DriveReadRepository`, …) build queries;
stores (`NodeStoreRepository`, `DriveStoreRepository`, …) perform writes and never open
transactions or dispatch events — the Action owns that. The tree-descent reads use
`staudenmeir/laravel-adjacency-list` recursive relations scoped by `drive_id`.

### Models — swappable, resolved through `Config`

Vaulter never references a concrete model class directly in domain code; each package resolves its models through
its own typed config reader (`Vaulter\Drive\Configuration\DriveConfig`, `Vaulter\Media\Configuration\MediaConfig`,
`Vaulter\Webhooks\Configuration\WebhooksConfig`) against `config('vaulter-drive.models.*')`,
`config('vaulter-media.models.*')`, `config('vaulter-webhooks.models.*')`. A host subclasses a model to add
ULID behaviour, tenant scoping, or audit. Models are the one part of the package that is **not**
`final` (they must be extendable); everything else is.

### Value objects, DTOs, enums

`Vaulter\Drive\Data\*` are the `final readonly` DTOs Actions consume. `Vaulter\Drive\ValueObjects\*` hold
domain values (`FileDescriptor`, `ContentCapabilities`, the ACL
`Subject`/`Decision`/`GrantSet`/`AccessScope`) — kind-neutral only; core does not know the shape of
any handler's content (`TipTapContent` and friends live in `vaulter-documents`).
`Vaulter\Drive\Enums\*` and the other packages' `Enums\*` are the sealed vocabularies (`Ability`, `NodeKind`, `BlobStatus`, …).

## Domain structure

Ownership follows the Composer graph (`core <- media <- drive <- documents <- collaboration`; `webhooks`, `azgard` and
`filament` build on `drive`) and is recorded in [ADR-0030](/adr/0030-package-ownership-and-schema) and
[ADR-0031](/adr/0031-collaboration-package-and-durability). **`axioma-studio/vaulter-core`**
(namespace `Vaulter\`) is the kernel only: it contains no domain table, model or service of a module.

| Directory (core) | Responsibility |
|---|---|
| `Modules/` · `Kernel.php` | The module system: manifests, compilation and seal, capabilities, slots, activation ([Modules](/concepts/modules)). |
| `Operations/` | `OperationRunner`, `OperationContext` (actor, tenant, idempotency key) and the middleware pipeline every write runs through ([Operations](/concepts/operations)). |
| `Events/` | `DomainEvent`, `EventCatalog` (the event types the modules contribute), the transactional outbox and its relay (`Events/Outbox/`) ([The outbox](/concepts/events-outbox)). |
| `Idempotency/` | The `Idempotency-Key` store and middleware ([Idempotency](/concepts/idempotency)). |
| `Http/` | The HTTP framework: middleware stacks, problem rendering (`Problems/`), the route-label slot contract. No module routes. |
| `Configuration/` · `Doctor/` · `Console/` | The kernel config reader and validator, the doctor framework, and the kernel commands (`vaulter:install`, `vaulter:modules`, `vaulter:module:uninstall`, `vaulter:doctor`, `vaulter:relay`, the reference generators). |
| `Schema/` | Generic helpers only: the id strategies (`AutoIncrementIds`, `UlidIds`, `Uuid7Ids`), host key columns, `SchemaHelpers`, the DDL of the kernel's four tables and `ModuleSchemas`, which walks the schema classes of the compiled modules. |
| `Tenancy/` · `Observability/` · `Support/` | The tenant resolver contract, telemetry context and recorders, identity and request helpers. |
| `Testing/` | The public `Vaulter\Testing` kit every package's `TestCase` builds on. |

| Package | Namespace | Owns |
|---|---|---|
| `axioma-studio/vaulter-media` | `Vaulter\Media\` | `v_blobs` and `v_blob_references` (the pins), the CAS contracts and engine (`Cas\{BlobStore,Sha256ContentStore,FlysystemStorageDriver,BlobGarbageCollector,BlobReconciler,StagedTempObject}`, `Contracts\Cas\BlobPins`), `vaulter:blobs:gc` and `vaulter:blobs:reconcile`. |
| `axioma-studio/vaulter-drive` | `Vaulter\Drive\` | The entry point `Vaulter\Drive\Vaulter` (facade `Vaulter\Drive\Facades\Vaulter`, alias `Vaulter`), `DriveHandle`, the Actions and Events, access (`AccessResolver`, the permission drivers), sharing, activity, change journal, quotas, uploads, scan state, profiles, file handlers and the drive tables, routes, commands and doctor checks. |
| `axioma-studio/vaulter-documents` | `Vaulter\Documents\` | Document-node Actions/Events/Models, the document type registry (`Types\*`), the document presenter, export, the collaboration contracts (`CollaborationDriver`, `SupportsCollaboration`) and the neutral state contract `State\CollaborativeState` with the pending marker, the content shapes (`Content\OpaqueContent`, `Content\TipTap\*`). |
| `axioma-studio/vaulter-collaboration` | `Vaulter\Collaboration\` | Realtime editing: the session endpoint and room token, the hub protocol 2 (`authenticate`, `load`, `append`, `renew`, `recheck`, compaction lease and checkpoint), the update log, receipts and permission versions (`v_collaboration_*`), the drain and prune commands, the control consumer. The hub `@vaulter/collaboration-hub` (Node) lives in `packages/collaboration/hub`. Opt-in. |
| `axioma-studio/vaulter-webhooks` | `Vaulter\Webhooks\` | Outbound webhooks: endpoints, `WebhookFanoutConsumer` (the `webhooks.fanout` outbox consumer), `WebhookSigner`, the URL guard, retries, the admin HTTP and `vaulter:webhooks:*` commands. Opt-in. |
| `axioma-studio/vaulter-filament` | `Vaulter\Filament\` | The Filament 5 admin plugin, built on the drive's authorized Operations. |
| `axioma-studio/vaulter-azgard` | `Vaulter\Azgard\` | The AzGuard permission driver, guard contract, permission map, real adapter and test fake. |
| `@vaulter/vue-collaboration` | — | The browser side of collaboration (npm): update envelopes, the retry queue, the save status; plugs into `@vaulter/vue` through its `collaboration` adapter. The only package with `yjs` and `@hocuspocus/provider`. |
| `@vaulter/vue` | — | The Drive UI + composables (npm). `src/api` imports no Vue, editor or realtime packages and nothing from `src/` outside `src/api` (arch test `packages/vue/tests/arch/api-no-vue.test.ts`). |

The metapackage `axioma-studio/vaulter` installs core, media, drive and documents; webhooks, collaboration, azgard and
filament are installed separately (see [Installation](/getting-started/installation)). Each package ships its own migrations
(not published), its own config file (`vaulter`, `vaulter-media`, `vaulter-drive`, `vaulter-documents`,
`vaulter-webhooks`, `vaulter-collaboration`, `vaulter-azgard`, `vaulter-filament`) and its own test kit.

## The Drive handle

`Vaulter::drive($owner, profile: '…')` is the primary integration entry point. `Vaulter` is the facade of
`Vaulter\Drive\Vaulter` (the drive package declares the `Vaulter` alias), returning a `DriveHandle` — the package
must be installed for this handle to be usable:

```php
use Vaulter\Drive\Data\UploadOptions;
use Vaulter\Drive\Data\UploadSource;
use Vaulter\Drive\Facades\Vaulter;

// Get-or-create the drive mounted on $project in the 'default' profile,
// returning an ergonomic DriveHandle.
$drive = Vaulter::drive($project)->actingAs($user);

$folder = $drive->folders()->create('Contracts');
$node   = $drive->files()->upload(
    UploadSource::fromUploadedFile($request->file('doc')),
    new UploadOptions(parent: $folder),
);
```

`Vaulter::drive()` get-or-creates the `v_drives` row through `DriveProvisioner` (the polymorphic
`(owner_type, owner_id, profile)` triple is the idempotency key, guarded by a unique index), auto-provisions a
`kind=folder` root node, and returns a `DriveHandle` whose methods each delegate to one Operation. The handle adds no
business logic — only a pre-resolved drive and upload-source normalisation.

::: details Vaulter
`drive()`, `findDrive()`, `findDriveById()`, `drives()`, `profiles()`, `handlers()`, `permissions()`.
[Facade](/reference/php-api).
:::

## Storage Panels — the embeddability axis

A **Storage Profile** is a named config envelope bound to *any* host entity, living over the
**one shared `v_*` schema with no tables of its own** (zero migrations). It is the
isolation/configuration axis, separate from the *storage type* (the policy preset
`private | team | public`).

A panel is built fluently and registered by FQCN only (config-cache-safe — no closures):

```php
use Vaulter\Drive\Profiles\Profile;
use Vaulter\Drive\Profiles\ProfileProvider;

final class ProjectDriveProfile extends ProfileProvider
{
    public function profile(Profile $panel): Profile
    {
        return $panel
            ->id('project_drive')
            ->host(\App\Models\Project::class)
            ->storageType('team');
    }
}
```

Register the provider in `config('vaulter-drive.profiles.providers')`. `ProfileRegistry` hydrates profiles
during application boot. Scaffold a provider with `php artisan vaulter:make:profile <name>`.
Profiles share one schema; own tables, model maps, pipelines and resource hooks are removed.
See [Drive profiles](/concepts/drives-and-profiles).

## Data model

Everything lives in the prefixed `v_*` schema (`config('vaulter.database.table_prefix')`, default `v_`),
with one id strategy per install (`config('vaulter.ids.strategy')`, default `ulid`). Each table belongs to exactly one
package, which creates it from its own `database/migrations` ([ADR-0030](/adr/0030-package-ownership-and-schema)).

| Table | Package | Holds |
|---|---|---|
| `v_outbox` / `v_outbox_consumers` / `v_outbox_deliveries` | core | The transactional outbox of every event and the delivery state of its consumers ([The outbox](/concepts/events-outbox)). |
| `v_idempotency_keys` | core | Receipts of `Idempotency-Key` writes ([Idempotency](/concepts/idempotency)). |
| `v_blobs` | media | The CAS: one row per `(scope, sha256)` with a `BlobStatus` lifecycle ([ADR-0006](/adr/0006-content-addressed-blobs-gc)). |
| `v_blob_references` | media | The **pins**: who keeps a blob alive, as `(blob, owner_type, reference_key)`. Garbage collection reads only this table. |
| `v_drives` | drive | One drive per `(owner_type, owner_id, profile)`; carries the root node id. |
| `v_nodes` | drive | The **unified tree** — every folder, file, document, shortcut as one row with `parent_id` + a `kind` discriminator ([ADR-0005](/adr/0005-unified-node-tree)). |
| `v_files` / `v_file_revisions` / `v_file_revision_blobs` | drive | The binary side tables: a file's head + its revision chain + the links to blobs (each link also pins its blob). |
| `v_scan_*` | drive | Content-scan policy, obligations, requests, evidence and backfill runs. |
| `v_node_grants` / `v_node_user_states` | drive | The per-node ACL with inheritance + deny-veto, and per-user node state. |
| `v_node_shares` | drive | Bearer share links — hash-only token storage ([ADR-0013](/adr/0013-two-sharing-channels)). |
| `v_upload_sessions` / `v_upload_parts` | drive | Resumable upload sessions. |
| `v_activity` | drive | The append-only activity feed. |
| `v_drive_change_heads` / `v_drive_changes` | drive | The per-drive change journal. |
| `v_documents` / `v_document_revisions` / `v_node_comments` | documents | The document aggregate of a `kind=document` node (working state, head, validators), its immutable checkpoints and its comments. |
| `v_webhook_endpoints` / `v_webhook_deliveries` | webhooks | The webhook endpoints and their deliveries. |

The tree is an **adjacency list**: move/rename is a single `UPDATE` of `parent_id` / `name`
(no path rewriting). Descent uses recursive CTEs scoped by `drive_id`; recursive descendant
*aggregation* (`whereHas` / `withCount('descendants')`) is **banned from request paths** (fatal
on MariaDB) and enforced by an arch test — see [Troubleshooting](/guides/troubleshooting#mariadb-cte).

## Authorization

Two lanes, evaluated by the Vaulter-owned `AccessResolver` ([ADR-0007](/adr/0007-pluggable-authorization)):

- **`PermissionDriver`** — the host's pluggable, per-profile role lane. It never sees a `Node` and
  never deals in magic-string actions; it answers `resolve(Subject, Authorizable, AccessScope)`,
  `override(...)` (super-admin / `Gate::before`), `subjectsFor(Authorizable)` (actor → users +
  roles + teams) and `canCreateInWorkspace(...)`. Shipped lanes: `database` (default,
  standalone), `gate`, `spatie`, `azgard`, and the non-prod `null`. Swap via
  `config('vaulter-drive.access.permission_driver')`.
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

Abilities (`Vaulter\Drive\Enums\Access\Ability`) map to CRUDS bits: `View/Download/Comment → READ`,
`Edit → UPDATE`, `Create/Copy → CREATE`, `Move → UPDATE+CREATE`, `Trash/Restore/Delete → DELETE`,
`Share → SHARE`, `Manage → ALL`.

::: warning No driver = fail-loud in production
The open `null` driver is selectable **only outside production**; `PermissionDriverManager`
throws `MissingPermissionDriverException` if a production app resolves it. Bind a real driver
before you deploy — see [Troubleshooting](/guides/troubleshooting#no-permission-driver).
:::

The identity ACL and the **bearer-link** channel are evaluated as **separate passes, never
unioned** ([ADR-0013](/adr/0013-two-sharing-channels)) — a link cannot escalate via a viewer's
grants, and an identity grant cannot leak through a link.

## Content-addressed storage (CAS)

The binary plane is a content-addressed store, not `spatie/laravel-medialibrary`
([ADR-0008](/adr/0008-media-library-decision)). Three swappable services
(`config('vaulter-media.*')`):

- **`ContentStore`** (`Sha256ContentStore`) — stages bytes to a seekable temp file and computes
  the `sha256` content address from the staged file (never from a live stream or a client-sent
  hash).
- **`StorageDriver`** (`FlysystemStorageDriver`) — the byte plane over a Flysystem disk, with a
  conditional `put` (`If-None-Match: *` on S3) and `temporaryUrl`.
- **`BlobStore`** — the race-free lifecycle orchestrator. Liveness is the *existence of a pin* in
  `v_blob_references` (media's own table, written through the `BlobPins` contract by the package that keeps the bytes
  alive — the drive pins in the same transaction as its revision link); `ref_count` is a rebuildable cache. GC is two-phase and lock-first
  (`PENDING → COMMITTED → TOMBSTONED → DELETED`) with resurrection of a tombstoned blob that a
  concurrent upload re-references.

The physical key is **scope-qualified** `<scope>/ab/cd/<sha256>` where `<scope>` follows
`config('vaulter-media.dedup.scope')` (`tenant` by default), so one scope's GC can never delete
another scope's bytes ([ADR-0012](/adr/0012-dedup-scope)). See the
[blob GC recipe](/guides/recipes#blob-gc) and the
[custom storage driver recipe](/guides/recipes#s3-disk).

## Realtime collaboration

Yjs updates are the source of truth; TipTap JSON is the projection of a checkpoint. Collaboration is the opt-in package
`vaulter-collaboration` ([ADR-0031](/adr/0031-collaboration-package-and-durability); the transport choice is
[ADR-0001](/adr/0001-collaboration-transport)); documents keeps the aggregate and the `CollaborationDriver` contract:

- **`hocuspocus`** — the Node hub `@vaulter/collaboration-hub`. The client connects with a signed room JWT; the hub calls back
  into the protocol 2 endpoints (`v1/collaboration/hub/{authenticate,load,append,renew,recheck}` and the compaction
  `lease`/`checkpoint`). A save is an `append` that returns a committed receipt; the hub folds the log into a checkpoint.
  Neither the drive nor documents depends on it. See [Realtime Collaboration](/guides/collaboration).

## Extension points

Every seam a host plugs into, and where it is documented:

| Contract | Bind via | Documented in |
|---|---|---|
| `Vaulter\Drive\Contracts\Access\PermissionDriver` | `config('vaulter-drive.access.permission_driver')` | [Recipes — custom permission driver](/guides/recipes#permission-driver) |
| `Vaulter\Azgard\Contracts\AzgardGuard` | config key `vaulter-azgard.guard` (default: the bridge's adapter) | [Troubleshooting — Azgard morphs](/guides/troubleshooting#azgard-morphs) |
| `Vaulter\Media\Contracts\Cas\StorageDriver` / `ContentStore` / `BlobStore` | `config('vaulter-media.*')` | [Recipes — swap to S3](/guides/recipes#s3-disk) |
| `Vaulter\Documents\Contracts\CollaborationDriver` | `config('vaulter-collaboration.driver')` | [ADR-0001](/adr/0001-collaboration-transport), [ADR-0031](/adr/0031-collaboration-package-and-durability) |
| `Vaulter\Drive\Contracts\Handlers\FileHandler` | `config('vaulter-drive.handlers')` / `Vaulter::handlers()->register()` | [Recipes — custom format handler](/guides/recipes#format-handler) |
| `Vaulter\Documents\Contracts\DocumentExporter` | `config('vaulter-documents.exporters')` / `Documents::exporters()->register()` | [ADR-0004](/adr/0004-content-format) |
| `Vaulter\Drive\Contracts\Sharing\ShareTokenGenerator` | `config('vaulter-drive.sharing.token_generator')` | [ADR-0013](/adr/0013-two-sharing-channels) |
| `Vaulter\Drive\Contracts\Uploads\UploadProtocolHandler` | container tag | [ADR-0006](/adr/0006-content-addressed-blobs-gc) |
| `Vaulter\Contracts\IdStrategy` | `config('vaulter.ids.strategies')` | [ADR-0011](/adr/0011-id-strategy) |
| `config('vaulter-drive.models.*')`, `config('vaulter-media.models.*')`, `config('vaulter-webhooks.models.*')` | config | [ADR-0003](/adr/0003-embeddability-data-model) |
| Domain `Events` | event subscriber | hook audit/notifications without Vaulter depending on them |

## The decision record

The reasoning behind every choice above lives in the [ADRs](/adr/). The full set:

| ADR | Decides | Status |
|---|---|---|
| [0001](/adr/0001-collaboration-transport) | Collaboration transport — `CollaborationDriver`, Hocuspocus room JWTs; Reverb Yjs transport removed. | accepted |
| [0002](/adr/0002-authorization-delegation) | Authorization delegated to the host (`PermissionResolver`). | refined by 0007 |
| [0003](/adr/0003-embeddability-data-model) | Embeddability via swappable models + dual key type. | superseded by 0009 / 0011 |
| [0004](/adr/0004-content-format) | TipTap JSON content + append-only bounded version history. | accepted |
| [0005](/adr/0005-unified-node-tree) | One adjacency-list tree (`v_nodes`) for folders/files/documents/shortcuts. | accepted |
| [0006](/adr/0006-content-addressed-blobs-gc) | Content-addressed blobs + two-phase race-free GC. | accepted |
| [0007](/adr/0007-pluggable-authorization) | `PermissionDriver` (pluggable) + `AccessResolver` (owned), deny-veto. | accepted |
| [0008](/adr/0008-media-library-decision) | A custom CAS instead of `spatie/laravel-medialibrary`. | accepted |
| [0009](/adr/0009-storage-panels) | Storage Panels — config-first, zero-migration envelopes. | accepted |
| [0010](/adr/0010-php-npm-monorepo-release) | One repo, two package managers, two release channels. | accepted |
| [0011](/adr/0011-id-strategy) | `bigint | ulid | uuid7` behind an `IdStrategy` seam (ULID default). | accepted |
| [0012](/adr/0012-dedup-scope) | Per-tenant dedup by default + scope-qualified storage keys. | accepted |
| [0013](/adr/0013-two-sharing-channels) | Identity ACL and bearer links evaluated as separate passes. | accepted |
| [0027](/adr/0027-kernel-boundaries-and-modules) | What the kernel is; the module system (manifest, activation, capabilities, slots). | accepted |
| [0028](/adr/0028-extension-slots-and-operation-pipeline) | Extension slots and the operation pipeline. | accepted |
| [0029](/adr/0029-idempotency) | `Idempotency-Key` receipts written in the transaction of the mutation. | accepted |
| [0030](/adr/0030-package-ownership-and-schema) | One owner per table, model and config root; pins; per-package migrations and test kits. | accepted |
| [0031](/adr/0031-collaboration-package-and-durability) | The collaboration package; protocol 2 receipts, buffered/durable modes, hub-only compaction, the pending state, revocation bound, drain. | accepted |

Supersession chains to keep in mind: **0003 → 0009 / 0011** (embeddability mechanics), **0002 → 0007** (authorization
delegation), **0019 → 0027 → 0030** (who owns what) and **0001 → 0031** (collaboration persistence and placement).

## Next

- [Recipes](/guides/recipes) — worked, copy-pasteable integrations of these seams.
- [Troubleshooting](/guides/troubleshooting) — the failure modes this architecture creates and
  how to avoid them.
- [Configuration](/guides/configuration) — every config key, by section.
