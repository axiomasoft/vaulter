---
title: Facade & Manager
description: The Vaulter facade and VaulterManager — the public entry point for host-app integration.
outline: deep
---

# Facade &amp; Manager

`Vaulter\Facades\Vaulter` is the package's public entry point. It is a thin Laravel
facade over `Vaulter\VaulterManager`, which is registered as a container singleton
(aliased `'vaulter'`) by `VaulterServiceProvider`. Every method below is a real
method on `VaulterManager`.

```php
use Vaulter\Facades\Vaulter;

$drive = Vaulter::for($project);          // open a Drive handle
$panel = Vaulter::profiles()->get('project_drive'); // resolve a storage panel
```

::: info Resolve it however you like
The facade, `app(VaulterManager::class)`, and the `'vaulter'` container alias all
return the same singleton. Inject `VaulterManager` in a constructor if you prefer DI
over the facade.
:::

## Method summary

| Method | Returns | Purpose |
|---|---|---|
| `for(Model $entity, ?string $panel = null)` | `DriveHandle` | Open a Drive handle bound to a host entity's storage in a panel |
| `forStorage(Drive $storage)` | `DriveHandle` | Open a Drive handle bound to an already-resolved storage row |
| `profiles()` | `ProfileRegistry` | Named drive profiles (`get`, `register`, `registerProvider`) |
| `handlers()` | `FileHandlerRegistry` | The document-handler registry |

The facade annotates all public manager methods. Use `profiles()` and `handlers()` for registrations.

## Drive entry points

### `for(Model $entity, ?string $panel = null): DriveHandle`

The primary integration entry point. Get-or-creates the `v_storages` row for the
host entity within a panel (idempotent — the root folder is auto-provisioned) and
returns an ergonomic `DriveHandle` whose methods delegate to the Drive Actions and
repositories. The panel defaults to `'default'`.

```php
$drive = Vaulter::for($project);                 // default panel
$drive = Vaulter::for($project, 'project_drive'); // a named panel
```

### `forStorage(Drive $storage): DriveHandle`

Open a Drive handle bound to an already-resolved `Drive` row. This is the seam the
HTTP layer uses: a route-model-bound `Drive` (resolved through the swappable
`config('vaulter.models.drive')`) maps straight to a `DriveHandle` without
re-deriving the owner. The handle's panel mirrors `$storage->profile`, so its Actions
run inside the right panel scope.

```php
$drive = Vaulter::forStorage($storage);
```

See [`DriveHandle`](/concepts/nodes-and-revisions) for the handle's full method surface. Every operation of the handle
returns immutable snapshots and `Page<T>` lists (`$drive->snapshot()`, `$drive->quota()`,
`$drive->nodes()->children()`); models are internal — see [API compatibility](/reference/compatibility).

## Profiles

### `profiles(): ProfileRegistry`

Use `Vaulter::profiles()->get('project')` to resolve a profile and
`Vaulter::profiles()->registerProvider(new ProjectProfile)` to register a provider.
See [Drive profiles](/concepts/drives-and-profiles).

## Document types and handlers

### Document types

Document types are not on this facade: they live in `vaulter-documents`, as
`Documents::types()` (`Vaulter\Documents\Types\DocumentTypeRegistry`). See
[Document Types](/extending/document-types).

### `handlers(): FileHandlerRegistry`

The format-extensibility seam. Adding a new format is one class + one call —
`handlers()->register()` accepts an FQCN or an instance and delegates to the
container-bound `Vaulter\Handlers\FileHandlerRegistry` (core-owned since P0.4/D10 —
the single source of truth for mime → extension → capability → priority resolution,
always resolvable even without `vaulter-documents` installed). No migration, no core change,
no package required.

```php
Vaulter::handlers()->register(\App\Vaulter\CadHandler::class);
```

You can also list handler FQCNs in `config('vaulter.handlers')` or bind them to the
tagged container binding `'vaulter.file_handlers'`. See
[Recipes → Custom handler](/guides/recipes).

## See also

- [Configuration reference](/reference/config) — every config key
- [Contracts reference](/reference/php-api) — the interfaces these methods accept and return
- [Events reference](/reference/events) — what gets dispatched as you drive
- [Commands reference](/reference/commands) — the Artisan surface

## Contracts

Vaulter's variance seams live under the `Vaulter\Contracts\*` namespace. Each is a
SemVer-frozen public interface you can implement to swap a behaviour — authorization,
storage, collaboration transport, document formats, sharing tokens, id strategy, or
upload protocols. This page lists every contract by domain with its exact method
signatures and the binding point you swap it through.

| Domain | Contract | Swap via |
|---|---|---|
| Access | `PermissionDriver` | `config('vaulter.access.permission_driver')` + `access.permission_drivers` map |
| Access | `AzgardGuard` (azgard) | config key `vaulter-azgard.guard` (default: the bridge's adapter) |
| Access | `AzgardBackedUser` (azgard) | implemented by the host `User` model when `drivers.permission=azgard` |
| Access | `GrantAuthoritativeDriver` | opt-in capability a `PermissionDriver` implements |
| Cas | `BlobStore` | `config('vaulter-media.blob_store')` |
| Cas | `ContentStore` | `config('vaulter-media.content_store')` |
| Cas | `ListableContentStore` | opt-in capability a `ContentStore` implements |
| Cas | `ListableStorageDriver` | opt-in capability a `StorageDriver` implements |
| Cas | `StorageDriver` | `config('vaulter-media.storage_driver')` |
| Cas | `StagedObject` | returned by `ContentStore::stage()` |
| Cas | `StorageAdapter` | `config('vaulter.storage_adapter')` (legacy) |
| Collaboration | `CollaborationDriver` | container binding (node path) |
| Collaboration | `HostUserResolver` | container binding |
| Handlers | `DocumentContent` | returned by a `FileHandler`'s content parser |
| Handlers | `FileHandler` | `config('vaulter.handlers')` / `Vaulter::handlers()->register()` |
| Handlers | `DocumentExporter` | `config('vaulter-documents.exporters')` / `Documents::exporters()->register()` |
| Handlers | `FileHandlerRegistry` | `Vaulter::handlers()` / `Vaulter::handlers()->register()` |
| Handlers | `NodePresenter` | container binding (core) |
| Sharing | `ShareTokenGenerator` | `config('vaulter.sharing.token_generator')` / `config('vaulter.sharing.token_generator')` |
| Uploads | `UploadProtocolHandler` | bundled (tus / S3-multipart) |
| — | `IdStrategy` | `config('vaulter.ids.strategies')` |

---

### Access

#### `Vaulter\Contracts\Access\PermissionDriver`

The pluggable, per-panel **role lane** the Vaulter-owned (non-swappable)
`AccessResolver` combines with the `v_node_grants` ACL. It bridges your identity +
role system into Vaulter without leaking Vaulter's domain: it never receives a `Node`
and never deals in magic-string actions — only an `Authorizable` actor and an
`AccessScope` (panel + workspace coordinates + node owner). Swap it via
`config('vaulter.access.permission_driver')` against the `access.permission_drivers` map; the
default is the standalone `DatabasePermissionDriver`.

```php
interface PermissionDriver
{
    public function resolve(Subject $subject, Authorizable $actor, AccessScope $scope): ?Decision;

    public function override(Authorizable $actor, AccessScope $scope): ?Effect;

    /** @return list<Subject> */
    public function subjectsFor(Authorizable $actor): array;

    public function canCreateInWorkspace(Authorizable $actor, string $workspaceType, int|string $workspaceId): bool;
}
```

- `resolve()` — the host's role decision, or `null` when the host role system has
  nothing to say. Folded as `roleAllow` into `granted = max(aclAllow, roleAllow)` —
  it can **raise** access but can never override a deny (the final veto still
  applies).
- `override()` — an absolute host override (super-admin / `Gate::before`):
  `Effect::Allow` short-circuits to full access, `Effect::Deny` to no access, both
  before any ACL walk.
- `subjectsFor()` — expand an actor into the set of `Subject`s whose grants apply
  (the user plus its host roles/teams). Vaulter builds
  `WHERE (subject_type, subject_id) IN (...)` from this list.
- `canCreateInWorkspace()` — the folder/upload create gate, where there is no
  existing node to resolve against.

::: info Frozen from M2a
Every bundled driver (`database`, `gate`, `spatie`, `azgard`, `null`) implements
exactly this surface, and a contract test asserts they all yield identical
`AccessResolver` decisions on a shared fixture.
:::

**Implement when** you have a role system the bundled drivers don't cover. See
[Recipes → Custom resolver](/guides/recipes).

#### `Vaulter\Azgard\Contracts\AzgardGuard`

The narrow Vaulter-owned seam the host's AzGuard-backed actor satisfies, so
`vaulter-core` carries **no** dependency on AzGuard. The `azgard` permission driver
talks only to this interface — never AzGuard's classes. Both methods are AzGuard v1
decisions (`hasPermission(on: $entity)` and `hasPermission(on: AssignmentScopeRef::of($type, $id))`);
there is no super-admin method — an AzGuard super-admin reaches the owner role through
these decisions.

```php
interface AzgardGuard
{
    public function hasScopedPermission(string $permission, object $entity): bool;

    public function hasPermissionIn(string $contextType, int|string $contextId, string $permission): bool;
}
```

The contract ships in `axioma-studio/vaulter-azgard`, alongside the real
`AzgardGuardAdapter` and `Vaulter\Azgard\Testing\FakeAzgardGuard` test double.
Its provider registers the driver and adapter automatically; select a custom guard with `vaulter-azgard.guard`.

**Use when** `config('vaulter.access.permission_driver') = 'azgard'`: install with
`composer require axioma-studio/vaulter-azgard`, add
`Vaulter\Azgard\Contracts\AzgardBackedUser` to your user model, register morph aliases for
the user and workspace models and declare the workspace aliases as assignment scope types
of the AzGuard panel.
Fake-based tests also require the bridge and its installed dependencies.
See [Permission drivers](/extending/permission-drivers).

#### `Vaulter\Azgard\Contracts\AzgardBackedUser` (azgard)

The host `User` model contract the `AzgardGuardAdapter` bridges into Vaulter: AzGuard v1's
own public subject contract `AzGuard\Contracts\AzGuardSubject` — no re-declared signature
to drift out of sync.

```php
interface AzgardBackedUser extends AzGuardSubject {}
```

**Implement when** `config('vaulter.access.permission_driver') = 'azgard'` — the host
`User` model satisfies it with no extra method bodies through the
`AzGuard\Concerns\HasAzGuard` trait, and is a subject model of the AzGuard panel.

#### `Vaulter\Contracts\Access\GrantAuthoritativeDriver`

An opt-in capability a `PermissionDriver` implements to declare that `v_node_grants` is
the sole read-visibility authority for that driver instance, so a listing may safely
narrow to the actor's grant-reachable candidate set. Absence is the conservative,
BC-safe default (the listing stays open, never wrongly hiding a row) — only the
bundled `database` driver declares it.

```php
interface GrantAuthoritativeDriver
{
    public function isGrantAuthoritative(): bool;
}
```

**Implement when** your `PermissionDriver` confers access ONLY through
`v_node_grants` (no host roles/teams, no workspace-create gate, no super-admin
override) — returning `false` (or not implementing the interface) is always safe.

---

### Cas (content-addressed storage)

#### `Vaulter\Media\Contracts\Cas\BlobStore`

The high-level, race-free CAS blob lifecycle. `commit()` is the single entry point —
stage → hash → find-or-create the blob by its per-scope dedup key inside one locked
transaction, resurrecting a tombstoned match and promoting bytes only when the row is
newly created. Liveness truth is the edge in `v_file_version_blobs`, not a
synchronous `ref_count`. Swap via `config('vaulter-media.blob_store')`.

```php
interface BlobStore
{
    /** @param resource $stream */
    public function commit(mixed $stream, ?int $tenantId = null, ?int $storageId = null, ?string $disk = null): Blob;

    /** @param array{role?: string, ordinal?: int, offset_bytes?: int|null, length_bytes?: int|null} $attributes */
    public function attach(FileRevision $version, Blob $blob, array $attributes = []): void;

    public function detach(FileRevision $version, Blob $blob, string $role = 'data', int $ordinal = 0): void;

    /** @return resource|null */
    public function read(Blob $blob): mixed;
}
```

#### `Vaulter\Media\Contracts\Cas\ContentStore`

The low-level byte side of the CAS: stage an incoming stream to a local seekable temp
file (hashing those durable bytes server-side), then promote the staged bytes to
their canonical scope-qualified key. Knows only bytes, hashes and keys — never the
`v_blobs` row. Swap via `config('vaulter-media.content_store')`.

```php
interface ContentStore
{
    /** @param resource $stream */
    public function stage(mixed $stream): StagedObject;

    public function promote(StagedObject $staged, string $key, ?string $disk = null): void;

    /** @return resource|null */
    public function get(string $key, ?string $disk = null): mixed;

    public function exists(string $key, ?string $disk = null): bool;

    public function delete(string $key, ?string $disk = null): void;

    /** Build the scope-qualified, sharded key "<scope>/ab/cd/<sha256>". */
    public function keyFor(string $scope, string $sha256): string;
}
```

#### `Vaulter\Media\Contracts\Cas\ListableContentStore`

An opt-in capability a `ContentStore` implements to declare it can enumerate stored
objects and report their age — the seam the orphan disk-walk (CAS reconciler) uses
to find bytes with no `v_blobs` row. Absence is the conservative default: the orphan
walk refuses cleanly (skipped, never a fatal error) instead of erroring.

```php
interface ListableContentStore
{
    public function lastModified(string $key, ?string $disk = null): ?int;

    /** @return iterable<string> */
    public function listKeys(?string $disk = null): iterable;
}
```

**Implement when** your `ContentStore` should participate in orphan reconciliation.

#### `Vaulter\Media\Contracts\Cas\StagedObject`

The result of staging an upload to a local, seekable temp file. The sha256 is
recomputed from these durable bytes — never the live stream, a client hash, or an S3
multipart ETag. Read-only and self-cleaning. Returned by `ContentStore::stage()`.

```php
interface StagedObject
{
    public function path(): string;

    public function sha256(): string;

    public function sizeBytes(): int;

    /** @return resource */
    public function readStream(): mixed;

    public function discard(): void;
}
```

#### `Vaulter\Media\Contracts\Cas\StorageDriver`

A thin abstraction over a Flysystem disk — the byte plane under the CAS. Swap to add
object-store specifics (`x-amz-checksum-sha256`, `If-None-Match: *`) while keeping
the local/sqlite test disk working. Swap via `config('vaulter-media.storage_driver')`.

```php
interface StorageDriver
{
    public function disk(?string $disk = null): Filesystem;

    /**
     * @param resource             $stream
     * @param array<string, mixed> $options
     */
    public function put(string $key, mixed $stream, ?string $disk = null, bool $ifNoneMatch = false, array $options = []): bool;

    /** @return resource|null */
    public function get(string $key, ?string $disk = null): mixed;

    public function exists(string $key, ?string $disk = null): bool;

    public function delete(string $key, ?string $disk = null): void;

    public function temporaryUrl(string $key, \DateTimeInterface $expiresAt, ?string $disk = null): ?string;
}
```

When `$ifNoneMatch` is true the driver attempts a conditional create; a 412/409 means
we were overtaken and must re-SELECT + reuse — surfaced as `put()` returning `false`,
not an exception. On a local disk the condition degrades to a best-effort `exists()`
guard.

#### `Vaulter\Media\Contracts\Cas\ListableStorageDriver`

An opt-in capability a `StorageDriver` implements to declare it can enumerate the
disk and report object age — the seam the orphan disk-walk (CAS reconciler) uses to
find bytes with no `v_blobs` row. Absence is the conservative default: the driver is
reported as non-listable and skipped, never a fatal error.

```php
interface ListableStorageDriver
{
    public function lastModified(string $key, ?string $disk = null): ?int;

    /** @return iterable<string> */
    public function listContents(?string $disk = null): iterable;
}
```

**Implement when** your `StorageDriver` should participate in orphan reconciliation.
The bundled Flysystem storage driver implements it out of the box.

#### `Vaulter\Contracts\Cas\StorageAdapter` (legacy)

Pluggable storage for **legacy** binary attachments. Vaulter itself stores JSON
content; binary files in the legacy document path are delegated here. Swap via
`config('vaulter.storage_adapter')`.

```php
interface StorageAdapter
{
    /** @param string|resource $contents */
    public function store(string $filename, mixed $contents): string;

    public function delete(string $url): void;

    public function url(string $path): string;
}
```

::: tip Which CAS contract do I implement?
For a custom byte backend (S3 / local), implement `StorageDriver` and bind it — the
`ContentStore` and `BlobStore` stay as-is. See
[Recipes → Storage driver](/guides/recipes).
:::

---

### Collaboration

#### `Vaulter\Documents\Contracts\CollaborationDriver`

The **node-tree** realtime-collaboration transport (the current path). Abstracts how
a document node (`kind=document`, content in the `v_documents` aggregate) is joined for
realtime editing. The authorization decision is **not** the driver's job — the
controller resolves it through `AccessResolver` and passes the granted ability in; the
driver only encodes it into the session descriptor (`readOnly = ability is not Edit`).

```php
interface CollaborationDriver
{
    public function type(): CollaborationDriverType;

    /** @param int|string $actorId the connecting host user's id (the JWT `sub`) */
    public function issueSession(Node $node, Ability $ability, int|string $actorId): CollaborationSession;
}
```

#### `Vaulter\Documents\Contracts\HostUserResolver`

Materialises a host user (`Authorizable`) by id, so the server-to-server
collaboration webhook — which carries only a user id, no session — can run the
`AccessResolver` against a real actor. Returns `null` when the id resolves to nothing
(the webhook then answers `allowed=false` rather than failing open). The default
`Vaulter\Documents\Collaboration\HostUserResolver` defers to the configured auth user-provider; bind
your own to override.

```php
interface HostUserResolver
{
    public function find(int|string $id): ?Authorizable;
}
```

See [Collaboration → Drivers](/guides/collaboration).

---

### Handlers

#### `Vaulter\Contracts\Handlers\FileHandler`

A pluggable document-processing handler — the format-extensibility headline. A
handler claims a set of mime types (exact or wildcards like `"image/*"`) and/or
extensions, declares its capabilities and front-end mount descriptor per file, and the
`Vaulter\Handlers\FileHandlerRegistry` resolves the best one for a `FileDescriptor`. Register via
`config('vaulter.handlers')` (FQCN list), the tagged binding `'vaulter.file_handlers'`, or
`Vaulter::handlers()->register()`.

```php
interface FileHandler
{
    public function key(): string;

    /** @return list<string> */
    public function mimeTypes(): array;

    /** @return list<string> */
    public function extensions(): array;

    public function priority(): int;

    public function capabilities(FileDescriptor $file): ContentCapabilities;

    public function viewer(FileDescriptor $file): ViewerDescriptor;
}
```

- `key()` — a stable unique string key in `vendor/name` form (`'vaulter/tiptap'`, `'vaulter/preview'`,
  `'vaulter/download'`, `'vaulter/univer'`, …). Surfaced to the client so it can pick the editor/preview impl.
- `mimeTypes()` — exact (`"application/pdf"`), wildcard (`"image/*"`), or catch-all
  (`"*"`). Routing prefers the sniffed mime over the claimed one.
- `extensions()` — lower-case, no dot; the extension fallback used when the mime
  didn't match.
- `priority()` — higher wins among handlers matching the same file + capability; the
  `DownloadOnlyHandler` null-object sits lowest.
- `capabilities()` — editable / previewable / downloadable / thumbnailable /
  collaborative, computed per file.
- `viewer()` — a viewer key and serialisable props.

See [Recipes → Custom handler](/guides/recipes) and [Writing a Handler/Engine](/extending/viewers)
for a full worked example.

`Vaulter\Handlers\AbstractFileHandler` is an optional DX base a third-party
handler MAY extend — it only defaults `extensions()` to `[]`; every other method stays
handler-specific. Implementing `FileHandler` directly is always enough; the base class
saves nothing beyond that one line. The built-in handlers do not extend it — they stay
`final` with zero inheritance (H.5).

#### `Vaulter\Documents\Contracts\DocumentExporter`

Renders the decoded content of a document (`DocumentContent`) into a file format. Registered in
`Documents::exporters()` and resolved by (document type, format). It sees decoded inline content
only — never storage, blobs or URLs. Built-in HTML and Markdown exporters ship; register your own
(PDF, DOCX, …) via `config('vaulter-documents.exporters')` or `Documents::exporters()->register()`.
Binary formats return the encoded bytes of the file and advertise the matching mime type.

```php
interface DocumentExporter
{
    public function key(): string;          // 'vaulter/html'
    public function format(): string;       // the ?format= value
    public function mimeType(): string;
    public function extension(): string;    // without the dot
    /** @return list<string> keys of the document types it can render */
    public function documentTypes(): array;
    public function export(DocumentContent $content, DocumentType $type): string;
}
```

See [Recipes → Custom export](/guides/recipes).

#### `Vaulter\Documents\Contracts\DocumentContent`

The content payload a document version stores. A handler decides the shape: a
rich-text handler produces a validated TipTap doc (`TipTapContent`); a non-rich-text
handler produces an opaque JSON snapshot the core stores verbatim
(`OpaqueContent`).

```php
interface DocumentContent
{
    /** @return array<string, mixed> */
    public function toArray(): array;

    public function contentHash(): string;

    public function sizeInBytes(): int;
}
```

**Implement when** your `FileHandler` needs a content shape other than TipTap or
the bundled opaque snapshot.

#### `Vaulter\Contracts\Handlers\FileHandlerRegistry`

The format-extensibility registry seam behind `Vaulter::handlers()->register()` /
`Vaulter::handlers()`. Both the contract and the concrete engine
(`Vaulter\Handlers\FileHandlerRegistry`, the SSOT resolver — final, H.5) are
core-owned: this binding always resolves, even without vaulter-documents installed
(a drive-only host still gets preview/download resolution). Packages like
vaulter-documents only ADD handlers (TipTap, Univer, …) to this same registry.

```php
interface FileHandlerRegistry
{
    /** @param FileHandler|class-string<FileHandler> $handler */
    public function register(FileHandler|string $handler): void;

    public function resolve(FileDescriptor $file, Capability $capability = Capability::Download): FileHandler;

    /** @return list<FileHandler> */
    public function all(): array;
}
```

#### `Vaulter\Contracts\Handlers\NodePresenter`

Resolves the format-handler descriptor for a node — the `(id + capabilities + ui)`
bundle the `GET .../handler` endpoint returns, so the Vue client routes the node to
the right editor/preview/download surface. Both the contract and the concrete
`Vaulter\Handlers\NodePresenter` are core-owned (D10): `app(NodePresenter::class)`
always resolves, even without vaulter-documents installed — a drive-only host still gets a
preview/download descriptor. Packages like vaulter-documents only ADD handlers to the
registry the resolver consults.

```php
interface NodePresenter
{
    public function present(Node|NodeSnapshot $node): NodePresentation;
}
```

---

### Sharing

#### `Vaulter\Contracts\Sharing\ShareTokenGenerator`

Generates the opaque token for a public share link. A token must be unique and fit the
token column (≤ 64 chars). Configure the generator at `config('vaulter.sharing.token_generator')`.

```php
interface ShareTokenGenerator
{
    public function generate(): string;
}
```

The default is `Vaulter\Support\RandomShareTokenGenerator`. See
[Recipes → Share token generator](/guides/recipes).

---

### Uploads

#### `Vaulter\Drive\Contracts\Uploads\UploadProtocolHandler`

The per-protocol strategy behind a resumable-upload session. Two implementations
ship — `TusUploadHandler` (tus.io CORE: Creation + HEAD + PATCH, any Flysystem disk)
and `S3MultipartHandler` (object-store multipart, S3-style disks only). Every handler
accumulates bytes resumably onto the staged temp, advancing the authoritative
server-side `received_bytes`, and reports when the session is fully assembled. The
assemble → commit step is **outside** the handler (`CompleteUploadAction` →
`BlobStore::commit`), so the "assembled bytes always go through the one race-free CAS"
invariant holds for both protocols.

```php
interface UploadProtocolHandler
{
    public function protocol(): UploadProtocol;

    /** @return array<string, mixed> */
    public function initialise(UploadSession $session): array;

    public function isComplete(UploadSession $session): bool;

    public function abort(UploadSession $session): void;
}
```

See [Usage → Uploads](/guides/uploads).

---

### Identity

#### `Vaulter\Contracts\IdStrategy`

The primary-key strategy: drives column types in migrations plus the matching model
concern, so "ulid vs uuid7 vs bigint" is decided from `config('vaulter.ids.strategy')`
rather than hard-coded. Implementations live in `Vaulter\Schema\*`
(`AutoIncrementIds`, `UlidIds`, `Uuid7Ids`); a host may register its own under
`config('vaulter.ids.strategies')`.

```php
interface IdStrategy
{
    public function key(Blueprint $table, string $column = 'id'): void;

    public function reference(Blueprint $table, string $column, bool $nullable = false): void;

    public function actor(Blueprint $table, string $column, bool $nullable = false): void;

    /** Eloquent cast for the key type. */
    public function cast(): string;

    /** @return class-string|null Model concern (trait FQCN), or null for bigint. */
    public function modelConcern(): ?string;

    /** Mint a fresh key, or null for auto-increment keys. */
    public function newId(): string|int|null;
}
```

- `key()` — define the primary-key column.
- `reference()` — define a FK to another Vaulter entity (same key type).
- `actor()` — define a reference to a host user id (no DB-level FK; the host owns the
  users table).

::: info Default is `ulid`
ULID is the default so morph keys line up with the Corex/Azgard ecosystem; `uuid7`
maps onto native Postgres `uuid` columns; `bigint` is the legacy auto-increment path.
See [Auth → Multi-tenancy](/concepts/tenancy) for morph alignment.
:::

### See also

- [Facade & Manager](/reference/php-api) — the methods that accept and return these
- [Configuration reference](/reference/config) — the binding keys
- [Events reference](/reference/events) — what's dispatched across these seams

---

### Operations results: snapshots and pages

The operations contracts (`NodeOperations`, `FolderOperations`, `FileOperations`, `TrashOperations`,
`AccessOperations`, `ShareOperations`, `ActivityOperations`) and `DriveHandle` take ids or snapshots
(`NodeSnapshot|string|int`, `FileRevisionSnapshot|string|int`, `GrantSnapshot|string|int`,
`ShareLinkSnapshot|string|int`) and return only the immutable snapshots of `Vaulter\Data\Snapshots` and
`Vaulter\Data\Page<T>` — never an Eloquent model, builder, collection or paginator. See
[API compatibility](/reference/compatibility) and [ADR-0026](/adr/0026-public-api-snapshots).

```php
interface NodeOperations
{
    public function find(NodeSnapshot|string|int $node): NodeSnapshot;

    /** @return Page<NodeSnapshot> */
    public function children(NodeSnapshot|string|int|null $folder = null, ?ListOptions $options = null): Page;

    public function rename(NodeSnapshot|string|int $node, string $name, ?int $expectedSequence = null): NodeSnapshot;
    // move, copy, trash, star, unstar, starred, properties, setProperties, abilities …
}
```

`Vaulter\Contracts\Drive\NodeSnapshotContributor` is the `drive` slot (Contributor) the documents module
fills with `documents.heads` to supply `NodeSnapshot::$document`; without an active contributor a document node
has no head in its snapshot. `Vaulter\Contracts\Drive\DriveExtension` marks the operations a module adds to a
drive handle, declared in the `drive` slot under the operations' FQCN and resolved by `DriveHandle::extension()`.

## The supported surface

What Vaulter promises to keep is marked in the code, not listed by hand (D27). Every type of `packages/*/src` carries
exactly one attribute from `Vaulter\Attributes`:

| Attribute | Meaning |
|---|---|
| `#[Api]` | Supported for code that uses Vaulter. Public and protected members are frozen with the type, parameter names included (named arguments are API). |
| `#[Spi(SpiKind::Implement)]` | A contract a host or a module implements; the kernel calls it positionally. |
| `#[Spi(SpiKind::Call)]` | A service Vaulter implements and a host or a module calls. |
| `#[Internal]` | Not supported; may change in any release. On a member it removes just that member (a technical constructor, a hook) from an `#[Api]` type. |

The manifest of each package — the `#[Api]` and `#[Spi]` types with their supported members — is generated into
`packages/<package>/tests/Arch/__snapshots__/surface.txt` by `scripts/php84 php scripts/inventory/inventory.php surface --write`;
`--check` (and `SurfaceManifestTest`) fails when the code and the manifest differ, so a change of the surface is always a visible
change of a committed file. A supported signature that names an `#[Internal]` type or a domain Eloquent model is reported by the
I13 scan (`scan-i13`, report mode until the 1.0 freeze).
