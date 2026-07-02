---
title: Drive — storages, folders, files & nodes
description: The Vaulter Drive — one storage per host entity, a unified v_nodes tree, and the DriveContext handle (createFolder / upload / move / copy / rename / trash / restore / forceDelete / list).
outline: deep
---

# Drive

The **Drive** is Vaulter's core domain: every host entity (a project, a team, a
user, …) gets a **storage container**, and every storage holds a single
adjacency tree of **nodes** — folders, files and documents in the same `v_nodes`
table. You reach all of it through one ergonomic handle: `Vaulter::for($entity)`,
which returns a [`DriveContext`](/reference/facade).

::: tip One handle, one storage
A `DriveContext` is bound to exactly one `(Storage, panel)` pair. Open it once
per request and reuse it — every method below delegates to a small Action (write)
or read repository; the handle itself adds no business logic and performs no
access checks (authorization is enforced at the [REST boundary](/reference/rest-api)).
:::

## Storages

A **storage** is an ownable, polymorphic disk container (`v_storages`). It is
mounted on a host entity through the `owner_type` / `owner_id` morph, scoped to a
[panel](/guide/storage-panels), and carries a root folder node plus the
used/quota accounting the UI shows.

### Opening a Drive

`Vaulter::for()` is the primary integration entry point. It **get-or-creates** the
storage row for the entity (idempotent — a second call returns the same storage),
auto-provisions the root folder, and hands back a `DriveContext`:

```php
use Vaulter\Facades\Vaulter;

$project = Project::find(1);

// Default panel.
$drive = Vaulter::for($project);

// A named panel (see Storage Panels).
$drive = Vaulter::for($project, panel: 'attachments');
```

The owner triple `(owner_type, owner_id, panel)` is the idempotency key, guarded by
a partial UNIQUE index — concurrent first-touches converge on one row.

::: details Already have a Storage model? Use `forStorage()`
When the storage row is already resolved (e.g. a route-model-bound `Storage`), skip
the owner lookup with `Vaulter::forStorage($storage)`. This is the seam the HTTP
layer uses; the handle's panel mirrors the storage's own `panel`.

```php
$drive = Vaulter::forStorage($storage);
```
:::

### The root node

Each storage opens into a `kind=folder` root node (its `name` is the empty string).
Reach it with `root()`; pass it — or any folder node — as the destination for the
write methods below. When you omit a destination, the root is used.

```php
$root = $drive->root();          // Vaulter\Models\Drive\Node
$storage = $drive->storage();    // Vaulter\Models\Drive\Storage
$panel = $drive->panel();        // string
```

### Quota & usage

The storage tracks `used_bytes` and an optional `quota_bytes` (nullable = no
quota). Both are integer-cast on the model and surfaced verbatim by the
[`StorageResource`](/reference/rest-api#storages) so the UI can render a usage bar:

```php
$storage = $drive->storage();

$storage->used_bytes;   // int — bytes currently consumed
$storage->quota_bytes;  // ?int — null means unlimited
```

::: info `used_bytes` is maintained by the Drive
`used_bytes` is advanced/released by the upload and force-delete Actions (a purge
detaches the version↔blob edges and releases the quota). There is no public
"set quota" method on the handle — set `quota_bytes` on the storage model directly,
or via a [Storage Panel](/guide/storage-panels) default.
:::

## Nodes

Every folder, file and document is a row in `v_nodes`, discriminated by its
[`NodeKind`](/reference/contracts):

| Kind | Value | Payload lives in |
|---|---|---|
| `NodeKind::Folder` | `folder` | — (a virtual directory, holds children) |
| `NodeKind::File` | `file` | `v_files` / `v_file_versions` / `v_blobs` |
| `NodeKind::Document` | `document` | `v_node_document_versions` (TipTap/Yjs) |
| `NodeKind::Shortcut` | `shortcut` | a pointer to another node (`shortcut_target_id`) |

This page covers folders and files through the `DriveContext`. Document nodes
(rich-text/Yjs) have their own create/content/version flow — see
[Editing](/guide/documents) and [Versions](/guide/documents).

## DriveContext methods

All write methods accept an optional `$actorId` (the host user id to attribute the
operation to) and return the affected `Node`; `forceDelete` returns `void`.

### `createFolder()`

```php
public function createFolder(
    string $name,
    ?Node $parent = null,        // null = storage root
    int|string|null $actorId = null,
): Node
```

```php
$folder = $drive->createFolder('Invoices');

// Nested under an existing folder.
$sub = $drive->createFolder('2026', parent: $folder, actorId: $user->id);
```

A name is a single tree **segment** — it may not contain path separators
(`/` or `\`). Build a path by creating folders level by level.

### `upload()`

Stage bytes and create a new **file** node. The `$source` is flexible — Vaulter
normalises any of these to a seekable stream before the Action stages and dedups it:

- a PSR-7 `StreamInterface`
- an `SplFileInfo` / Laravel `UploadedFile`
- an open stream **resource**
- a raw **string** of bytes

```php
public function upload(
    mixed $source,
    ?Node $into = null,          // null = storage root
    ?string $name = null,        // overrides the stored name
    ?string $mimeType = null,
    ?string $originalName = null,
    int|string|null $actorId = null,
): Node
```

::: code-group
```php [From an UploadedFile]
// e.g. inside a controller
$node = $drive->upload(
    source: $request->file('document'),
    into: $folder,
    actorId: $request->user()->id,
);
```

```php [From a string]
$node = $drive->upload(
    source: "id,name\n1,Ada\n",
    name: 'people.csv',
    mimeType: 'text/csv',
);
```

```php [From a path]
$node = $drive->upload(
    source: new SplFileInfo('/tmp/report.pdf'),
    into: $folder,
);
```
:::

When `$name` is omitted, Vaulter derives the stored name from the source (an
`UploadedFile`'s client original name, an `SplFileInfo`'s basename, else
`upload.bin`).

::: tip Resumable / large uploads
`DriveContext::upload()` is the in-process path — it reads the whole source into a
stream. For browser uploads of large files, use the **resumable upload** endpoints
(tus / S3-multipart) instead, which assemble the bytes through the *same* race-free
CAS commit. See [Uploads](/guide/uploads).
:::

### `uploadVersion()`

Commit a **new version** onto an existing file node — `version_no` bumps, the head
repoints, the prior version is superseded:

```php
public function uploadVersion(
    Node $target,
    mixed $source,
    ?string $mimeType = null,
    ?string $originalName = null,
    int|string|null $actorId = null,
): Node
```

```php
$node = $drive->uploadVersion($existingFile, $request->file('document'));
```

### `move()`

Move a node under a new parent folder (null = root):

```php
public function move(Node $node, ?Node $into = null, int|string|null $actorId = null): Node
```

```php
$drive->move($node, into: $archiveFolder);
$drive->move($node);  // back to the root
```

### `rename()`

```php
public function rename(Node $node, string $name, int|string|null $actorId = null): Node
```

```php
$drive->rename($node, 'Q4 Report (final).pdf');
```

### `copy()`

Deep-copy a node (and a folder's subtree) into a destination; `$name` overrides the
copy's name (null keeps the source's):

```php
public function copy(Node $node, ?Node $into = null, ?string $name = null, int|string|null $actorId = null): Node
```

```php
$copy = $drive->copy($node, into: $templatesFolder, name: 'Template');
```

### `trash()`

Soft-delete a node (sets `trashed_at`). The subtree is recoverable until it is
either restored or purged. See [Trash](/guide/trash).

```php
public function trash(Node $node, int|string|null $actorId = null): Node
```

```php
$drive->trash($node);
```

### `restore()`

Bring a trashed node (and its subtree) back:

```php
public function restore(Node $node, int|string|null $actorId = null): Node
```

```php
$drive->restore($node);
```

### `forceDelete()`

Permanently delete a node and its subtree — this detaches the version↔blob edges
(the bytes become GC-eligible) and releases the quota. Returns `void`.

```php
public function forceDelete(Node $node, int|string|null $actorId = null): void
```

```php
$drive->forceDelete($node);
```

::: danger Irreversible
`forceDelete()` cannot be undone. For a recoverable delete use `trash()`; let the
[`vaulter:purge-trash`](/guide/trash#scheduled-purge) command apply your retention
policy instead of force-deleting in app code.
:::

### `list()`

List the **direct children** of a folder (or the root when null) — the hot listing:

```php
/** @return \Illuminate\Database\Eloquent\Collection<int, Node> */
public function list(?Node $folder = null): Collection
```

```php
foreach ($drive->list() as $node) {        // root contents
    echo $node->name;
}

$children = $drive->list($folder);          // a folder's contents
```

::: info Listing live nodes only
`list()` returns live (non-trashed) direct children. For paginated listings,
search and the trash view, use the [REST endpoints](/reference/rest-api#drive)
(`storages/{storage}/nodes`, `…/search`, `…/trash`) which cursor-paginate.
:::

## Events

Drive writes dispatch domain events you can listen to:

| Event | Dispatched by |
|---|---|
| `Vaulter\Drive\Events\StorageCreated` | first provisioning of a storage |
| `Vaulter\Drive\Events\NodeCreated` | `createFolder()` |
| `Vaulter\Drive\Events\FileUploaded` | `upload()` (new file) |
| `Vaulter\Drive\Events\NodeVersionCommitted` | `uploadVersion()` / resumable commit |
| `Vaulter\Drive\Events\NodeMoved` | `move()` |
| `Vaulter\Drive\Events\NodeRenamed` | `rename()` |
| `Vaulter\Drive\Events\NodeCopied` | `copy()` |
| `Vaulter\Drive\Events\NodeTrashed` | `trash()` |
| `Vaulter\Drive\Events\NodeRestored` | `restore()` |
| `Vaulter\Drive\Events\NodeForceDeleted` | `forceDelete()` |

See the full payloads in the [events reference](/reference/events).

## In the browser

The same operations are exposed over REST and wrapped by the `@vaulter/vue`
`useNodes` composable, which the `<VaulterDrive>` UI binds against:

```ts
import { useNodes } from '@vaulter/vue'

const { list, createFolder, upload, move, rename, copy, trash, restore } = useNodes(storageId)

await createFolder({ name: 'Invoices' })
await list({ parentId: folderId })
```

## See also

- [Trash & restore](/guide/trash) — the trash lifecycle and scheduled purge
- [REST API reference](/reference/rest-api) — every Drive route with params and responses
- [Uploads](/guide/uploads) — resumable (tus / S3-multipart) uploads
- [Storage Panels](/guide/storage-panels) — configuring isolated drives per entity
- [`DriveContext` API](/reference/facade) — full method signatures
