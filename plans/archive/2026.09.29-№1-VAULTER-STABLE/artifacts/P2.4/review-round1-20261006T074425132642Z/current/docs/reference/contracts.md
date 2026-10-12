---
title: Contracts
description: Every Vaulter\Contracts interface by domain — method signatures and when to implement.
outline: deep
---

# Contracts

Vaulter's variance seams live under the `Vaulter\Contracts\*` namespace. Each is a
SemVer-frozen public interface you can implement to swap a behaviour — authorization,
storage, collaboration transport, document formats, sharing tokens, id strategy, or
upload protocols. This page lists every contract by domain with its exact method
signatures and the binding point you swap it through.

| Domain | Contract | Swap via |
|---|---|---|
| Access | `PermissionDriver` | `config('vaulter.drivers.permission')` + `permission_drivers` map |
| Access | `AzgardGuard` (azgard) | binding supplied by `vaulter-azgard` |
| Access | `AzgardBackedUser` (azgard) | implemented by the host `User` model when `drivers.permission=azgard` |
| Access | `GrantAuthoritativeDriver` | opt-in capability a `PermissionDriver` implements |
| Cas | `BlobStore` | `config('vaulter.cas.blob_store')` |
| Cas | `ContentStore` | `config('vaulter.cas.content_store')` |
| Cas | `ListableContentStore` | opt-in capability a `ContentStore` implements |
| Cas | `ListableStorageDriver` | opt-in capability a `StorageDriver` implements |
| Cas | `StorageDriver` | `config('vaulter.cas.storage_driver')` |
| Cas | `StagedObject` | returned by `ContentStore::stage()` |
| Cas | `StorageAdapter` | `config('vaulter.storage_adapter')` (legacy) |
| Collaboration | `CollaborationDriver` | container binding (node path) |
| Collaboration | `HostUserResolver` | container binding |
| Handlers | `DocumentContent` | returned by a `FileHandler`'s content parser |
| Handlers | `FileHandler` | `config('vaulter.handlers')` / `Vaulter::handlers()->register()` |
| Handlers | `DocumentExporter` | `config('vaulter.exporters')` / `Vaulter::registerExporter()` |
| Handlers | `FileHandlerRegistry` | `Vaulter::handlers()` / `Vaulter::handlers()->register()` |
| Handlers | `NodePresenter` | container binding (core) |
| Sharing | `ShareTokenGenerator` | `config('vaulter.share_token_generator')` / `config('vaulter.share.token_generator')` |
| Uploads | `UploadProtocolHandler` | bundled (tus / S3-multipart) |
| — | `IdStrategy` | `config('vaulter.ids.strategies')` |

---

## Access

### `Vaulter\Contracts\Access\PermissionDriver`

The pluggable, per-panel **role lane** the Vaulter-owned (non-swappable)
`AccessResolver` combines with the `v_node_grants` ACL. It bridges your identity +
role system into Vaulter without leaking Vaulter's domain: it never receives a `Node`
and never deals in magic-string actions — only an `Authorizable` actor and an
`AccessScope` (panel + workspace coordinates + node owner). Swap it via
`config('vaulter.drivers.permission')` against the `permission_drivers` map; the
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
[Recipes → Custom resolver](/guide/recipes).

### `Vaulter\Azgard\Contracts\AzgardGuard`

The narrow Vaulter-owned seam the host's AzGuard-backed actor satisfies, so
`vaulter-core` carries **no** hard dependency on `azguard`/`corex`. The
`azgard` permission driver talks only to this interface — never AzGuard's classes.

```php
interface AzgardGuard
{
    public function hasScopedPermission(string $permission, object $entity): bool;

    public function hasPermissionIn(string $contextType, int|string $contextId, string $permission): bool;

    public function isSuperAdmin(): bool;
}
```

The contract ships in `axioma-studio/vaulter-azgard`, alongside the real
`AzgardGuardAdapter` and `Vaulter\Azgard\Testing\FakeAzgardGuard` test double.
Its provider registers the driver and adapter automatically.

**Use when** `config('vaulter.drivers.permission') = 'azgard'`: install with
`composer require axioma-studio/vaulter-azgard`, add
`Vaulter\Azgard\Contracts\AzgardBackedUser` to your user model and align ULID morphs
(`vaulter.ids.default=ulid` + `corex.ids.strategy=Ulid` + `AZ_GUARD_MORPH_TYPE=ulid`).
Fake-based tests also require the bridge and its installed dependencies.
See [Permission drivers](/guide/permission-drivers).

### `Vaulter\Azgard\Contracts\AzgardBackedUser` (azgard)

The host `User` model contract the `AzgardGuardAdapter` bridges into Vaulter,
composed straight from AzGuard's own public contracts (`HasPermissions` +
`HasScopedRoles`) — no re-declared signature to drift out of sync.

```php
interface AzgardBackedUser extends HasPermissions, HasScopedRoles {}
```

**Implement when** `config('vaulter.drivers.permission') = 'azgard'` — the host
`User` model satisfies it with no extra method bodies by composing
`AzGuard\Concerns\HasAzGuard` + `AzGuard\Concerns\HasScopedRoles`.

### `Vaulter\Contracts\Access\GrantAuthoritativeDriver`

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

## Cas (content-addressed storage)

### `Vaulter\Contracts\Cas\BlobStore`

The high-level, race-free CAS blob lifecycle. `commit()` is the single entry point —
stage → hash → find-or-create the blob by its per-scope dedup key inside one locked
transaction, resurrecting a tombstoned match and promoting bytes only when the row is
newly created. Liveness truth is the edge in `v_file_version_blobs`, not a
synchronous `ref_count`. Swap via `config('vaulter.cas.blob_store')`.

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

### `Vaulter\Contracts\Cas\ContentStore`

The low-level byte side of the CAS: stage an incoming stream to a local seekable temp
file (hashing those durable bytes server-side), then promote the staged bytes to
their canonical scope-qualified key. Knows only bytes, hashes and keys — never the
`v_blobs` row. Swap via `config('vaulter.cas.content_store')`.

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

### `Vaulter\Contracts\Cas\ListableContentStore`

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

### `Vaulter\Contracts\Cas\StagedObject`

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

### `Vaulter\Contracts\Cas\StorageDriver`

A thin abstraction over a Flysystem disk — the byte plane under the CAS. Swap to add
object-store specifics (`x-amz-checksum-sha256`, `If-None-Match: *`) while keeping
the local/sqlite test disk working. Swap via `config('vaulter.cas.storage_driver')`.

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

### `Vaulter\Contracts\Cas\ListableStorageDriver`

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

### `Vaulter\Contracts\Cas\StorageAdapter` (legacy)

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
[Recipes → Storage driver](/guide/recipes).
:::

---

## Collaboration

### `Vaulter\Documents\Contracts\CollaborationDriver`

The **node-tree** realtime-collaboration transport (the current path). Abstracts how
a document node (`kind=document`, content on `v_node_document_versions`) is joined for
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

### `Vaulter\Documents\Contracts\HostUserResolver`

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

See [Collaboration → Drivers](/guide/collaboration).

---

## Handlers

### `Vaulter\Contracts\Handlers\FileHandler`

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

See [Recipes → Custom handler](/guide/recipes) and [Writing a Handler/Engine](/guide/writing-an-engine)
for a full worked example.

`Vaulter\Handlers\AbstractFileHandler` is an optional DX base a third-party
handler MAY extend — it only defaults `extensions()` to `[]`; every other method stays
handler-specific. Implementing `FileHandler` directly is always enough; the base class
saves nothing beyond that one line. The built-in handlers do not extend it — they stay
`final` with zero inheritance (H.5).

### `Vaulter\Contracts\Handlers\DocumentExporter`

Renders a document's content (`DocumentContent`, the same opaque payload the write path
stores) into an export format — the contract does not assume TipTap. Built-in HTML and
Markdown exporters ship; register your own (PDF, DOCX, …) via `config('vaulter.exporters')`
or `Vaulter::registerExporter()`. Binary formats should return an encoded string (e.g.
base64) and advertise the matching mime type.

```php
interface DocumentExporter
{
    /** The format key (e.g. 'html', 'pdf'); also echoed in the API. */
    public function format(): string;

    public function mimeType(): string;

    /** File extension, without the dot. */
    public function extension(): string;

    public function export(DocumentContent $content): string;
}
```

See [Recipes → Custom export](/guide/recipes).

### `Vaulter\Contracts\Handlers\DocumentContent`

The content payload a document version stores. A handler decides the shape: a
rich-text handler produces a validated TipTap doc (`TipTapContent`); a non-rich-text
handler produces an opaque JSON snapshot the core stores verbatim
(`OpaqueDocumentContent`).

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

### `Vaulter\Contracts\Handlers\FileHandlerRegistry`

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

### `Vaulter\Contracts\Handlers\NodePresenter`

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
    public function present(Node $node): NodePresentation;
}
```

---

## Sharing

### `Vaulter\Contracts\Sharing\ShareTokenGenerator`

Generates the opaque token for a public share link. A token must be unique and fit the
token column (≤ 64 chars). Swap the legacy document-share token via
`config('vaulter.share_token_generator')`, and the bearer node-share token via
`config('vaulter.share.token_generator')`.

```php
interface ShareTokenGenerator
{
    public function generate(): string;
}
```

The default is `Vaulter\Support\RandomShareTokenGenerator`. See
[Recipes → Share token generator](/guide/recipes).

---

## Uploads

### `Vaulter\Drive\Contracts\Uploads\UploadProtocolHandler`

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

See [Usage → Uploads](/guide/uploads).

---

## Identity

### `Vaulter\Contracts\IdStrategy`

The primary-key strategy: drives column types in migrations plus the matching model
concern, so "ulid vs uuid7 vs bigint" is decided from `config('vaulter.ids.default')`
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
See [Auth → Multi-tenancy](/guide/multi-tenancy) for morph alignment.
:::

## See also

- [Facade & Manager](/reference/facade) — the methods that accept and return these
- [Configuration reference](/reference/configuration) — the binding keys
- [Events reference](/reference/events) — what's dispatched across these seams
