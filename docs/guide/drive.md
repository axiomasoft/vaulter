---
title: Drive — storages, folders, files & nodes
description: The Vaulter Drive — one storage per host entity, a unified v_nodes tree, and the DriveHandle handle (createFolder / upload / move / copy / rename / trash / restore / forceDelete / list).
outline: deep
---

# Drive

The **Drive** is Vaulter's core domain: every host entity (a project, a team, a
user, …) gets a **storage container**, and every storage holds a single
adjacency tree of **nodes** — folders, files and documents in the same `v_nodes`
table. You reach all of it through one ergonomic handle: `Vaulter::for($entity)`,
which returns a [`DriveHandle`](/reference/facade).

::: tip One handle, one storage
A `DriveHandle` is bound to exactly one `(Drive, profile)` pair. Open it once
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
auto-provisions the root folder, and hands back a `DriveHandle`:

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

::: details Already have a Drive model? Use `forStorage()`
When the storage row is already resolved (e.g. a route-model-bound `Drive`), skip
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

The tree keeps these rules on every write, checked under row locks inside the
operation's transaction:

- **One root per drive.** Only the root has no parent (a unique index enforces it);
  a missing destination always resolves to the root.
- **Destinations are live folders of the same drive.** Creating, uploading, moving or
  copying into a file, a document or a trashed folder raises
  `InvalidDestinationException` (409 `invalid_destination`); a folder of another drive
  raises `CrossDriveOperationException` (409 `cross_drive_operation`).
- **The root is never an operand.** Renaming, moving, copying, trashing or
  force-deleting it raises `InvalidDestinationException`.
- **No cycles.** A folder cannot move into itself or its own subtree
  (`NodeCycleException`, 409 `node_move_cycle`), even when two moves race.

Every write takes the drive's tree lock first — exclusively for move, restore, trash and
force-delete, shared for create, upload, copy and rename — and only then locks node rows.
On PostgreSQL uploads into one drive stay concurrent; a structural operation waits for
them. A custom Action that changes the tree should follow the same order
(`VaulterDatabase::lockTree()` / `lockTreeShared()`, then node rows).

```php
$root = $drive->root();          // Vaulter\Models\Drive\Node
$storage = $drive->model();    // Vaulter\Models\Drive\Drive
$profile = $drive->profile();  // Vaulter\Profiles\Profile
$profileId = $profile->getId(); // string
```

### Quota & usage

The storage tracks `used_bytes` and an optional `quota_bytes` (nullable = no
quota). Both are integer-cast on the model and surfaced verbatim by the
[`DriveResource`](/reference/rest-api#storages) so the UI can render a usage bar:

```php
$storage = $drive->model();

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
| `NodeKind::Document` | `document` | `v_documents` + `v_document_revisions` (TipTap/Yjs) |
| `NodeKind::Shortcut` | `shortcut` | a pointer to another node (`shortcut_target_id`) |

This page covers folders and files through the `DriveHandle`. Document nodes
(rich-text/Yjs) have their own create/content/version flow — see
[Editing](/guide/documents) and [Versions](/guide/documents).

## DriveHandle methods

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

### Names, limits, stars and properties

**Names.** Every write that sets a new name (create, upload, rename, copy with a name, a
conflict rename on move or restore) normalizes it to Unicode NFC and checks it against the
profile's name policy. Nothing is trimmed: `" a.txt"` is refused, not changed. Two policies
ship, selected per profile:

- `portable-v1` (default) — names every desktop OS and ZIP can hold: no `< > : " | ? *`,
  no leading/trailing whitespace, no trailing dot, no Windows device names (`CON`, `PRN`,
  `AUX`, `NUL`, `COM0`–`COM9`, `LPT0`–`LPT9`, with any extension), no `desktop.ini` or
  `.DS_Store`, at most 255 UTF-8 bytes and 255 UTF-16 code units;
- `permissive-v1` — only the base rules: not empty, not `.`/`..`, no `/`, `\` or control
  characters, at most 255 UTF-8 bytes.

A refused name throws `InvalidNodeNameException` (422 `invalid_node_name` with a `reason`).
Names already stored are never changed; renaming to them must pass the policy.

Siblings are unique by `name_key` = NFC(full case folding(NFC(name))), compared byte for
byte on every database: `Report.pdf`/`report.pdf`, `Résumé.pdf` in NFC/NFD and
`STRASSE`/`straße` are the same name, while a node may change only the case of its own
name. Trashed siblings do not count.

**Limits.** The profile's drive policy holds the tree and content limits; Actions check
them under the locks of the rows they change, on every path:

```php
$profile->drivePolicy(fn (DrivePolicyBuilder $p) => $p
    ->namePolicy('portable-v1')
    ->maxFolderDepth(100)        // levels below the root
    ->maxFolderChildren(10_000)  // trashed children count until purged
    ->maxFileBytes(null)         // null = only the installation upload limit
    ->maxPropertiesBytes(16_384)
    ->maxPropertyCount(100));
```

Exceeding them throws `FolderChildrenLimitExceededException` (409), `TreeDepthLimitExceededException`
(409) or `FileSizeLimitExceededException` (413). Lowering a limit deletes nothing: only
operations that make an excess worse are refused.

`maxFileBytes` also applies to every new file in a copy, including files inside a
copied folder. Restoring a revision must fit the current limit when it increases
the file's current size; restoring a smaller payload remains allowed after a limit
was lowered. A refusal leaves revisions, quota and stored bytes unchanged.

**Stars** are personal: `nodes()->star($node)`, `unstar($node)` and `starred(?ListOptions)`
(the actor's visible starred nodes, newest star first). A star changes neither the node's
sequence nor its timestamps; REST responses carry `viewer.starred` for the request user.
There is no manual order of children; sort with `ListOptions`.

**Properties** are namespaced strings: `nodes()->properties($node)` returns
`[namespace => [key => value]]`, and `setProperties($node, 'acme.crm', set: ['deal' => '42'],
unset: ['old'], expectedSequence: …)` patches one namespace atomically and bumps the node
sequence. Namespaces match `^[a-z0-9][a-z0-9.-]{0,127}$`, keys `^[A-Za-z0-9_.-]{1,64}$`,
values are UTF-8 strings of at most 1024 bytes; `vaulter` and `vaulter.*` are written by the
system only. A broken rule throws `InvalidPropertiesException` (422 `invalid_properties`).

**Times.** `content_modified_at`/`content_modified_by` record the last content change (a file
revision, a document state write); `updated_at` moves on any change.
`UploadOptions::make()->clientModifiedAt($time)` stores the uploader's own modification time
on the revision as information only.

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
`DriveHandle::upload()` is the in-process path — it reads the whole source into a
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

Move a node under a new parent folder (null = root). The root itself cannot move, and
the destination must be a live folder outside the node's own subtree:

```php
public function move(Node $node, ?Node $into = null, int|string|null $actorId = null): Node
```

```php
$drive->move($node, into: $archiveFolder);
$drive->move($node);  // back to the root
```

### `rename()`

Every node except the root can be renamed:

```php
public function rename(Node $node, string $name, int|string|null $actorId = null): Node
```

```php
$drive->rename($node, 'Q4 Report (final).pdf');
```

### `copy()`

Deep-copy a node (and a folder's subtree, without its trashed nodes) into a live folder
of the same drive; `$name` overrides the copy's name (null keeps the source's). The root
cannot be copied:

```php
public function copy(Node $node, ?Node $into = null, ?string $name = null, int|string|null $actorId = null): Node
```

```php
$copy = $drive->copy($node, into: $templatesFolder, name: 'Template');
```

### `trash()`

Soft-delete a node (sets `trashed_at`). The subtree is recoverable until it is
either restored or purged. The root cannot be trashed, and trashing a node already in
the trash raises `InvalidDestinationException`. See [Trash](/guide/trash).

```php
public function trash(Node $node, int|string|null $actorId = null): Node
```

```php
$drive->trash($node);
```

### `restore()`

Bring a trash root (and the members trashed with it) back — to its original parent. When that
parent is gone or trashed the restore raises `RestoreDestinationRequiredException` unless a
destination is passed (`RestoreOptions`); a member trashed with a folder raises
`NodeTrashedByAncestorException`. A node that is not in the trash raises
`NodeNotTrashedException` (409 `node_not_trashed`). See [Trash](/guide/trash#restoring):

```php
public function restore(Node $node, int|string|null $actorId = null): Node
```

```php
$drive->restore($node);
```

### `forceDelete()`

Permanently delete a **trashed** node and its subtree — this detaches the version↔blob
edges (the bytes become GC-eligible) and releases the quota. Returns `void`. A live node
raises `NodeNotTrashedException`: trash it first. The root is never deleted.

```php
public function forceDelete(Node $node, int|string|null $actorId = null): void
```

```php
$drive->trash($node);
$drive->forceDelete($node);
```

::: danger Irreversible
`forceDelete()` cannot be undone. For a recoverable delete use `trash()`; let the
[`vaulter:trash:purge`](/guide/trash#scheduled-purge) command apply your retention
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
(`v1/drives/{drive}/nodes`, `…/search`, `…/trash`) which cursor-paginate.
:::

## Drive lifecycle

A drive has a `status` — `Vaulter\Enums\Drive\DriveStatus`: `active`, `archived` or `deleting`
(a closed enum: a client must treat an unknown value as unavailable). The handle moves it:

```php
$snapshot = $drive->archive();     // active → archived: read-only
$snapshot = $drive->unarchive();   // archived → active
$snapshot = $drive->delete();      // active|archived → deleting, then an asynchronous purge
```

All three need `Manage` on the root (or the system actor) and return a `DriveSnapshot` whose
`status` is the new state; a transition from the wrong state raises `InvalidDriveStateException`.

- **archived** — every mutation, purge and creation by a non-system actor raises
  `DriveArchivedException` (409 `drive_archived`), including grant and share-link changes and
  deleting your own comment. Reads and downloads work, and existing share links keep serving
  reads. The check runs again under the tree lock, so a write racing the archive either lands
  before it or is refused.
- **deleting** — the drive no longer exists for anyone: `drive_not_found` / `node_not_found`.
  Only the system actor `drive-purge` sees it. `PurgeDriveJob` (unique, retried five times) purges
  100 children of the root per step, each in its own transaction through the same lifecycle, CAS
  and quota path as `forceDelete`, then removes the root and the drive row. Activity and outbox
  rows stay as history; the bytes are reclaimed by the blob GC. `vaulter:drives:purge` resumes an
  interrupted purge.

`DriveArchived`, `DriveUnarchived` and `DriveDeleted` are dispatched after commit (`DriveDeleted`
carries ids only; the row is gone).

### Deleting with the owner — `HasDrives`

```php
use Vaulter\Concerns\HasDrives;

class Project extends Model
{
    use HasDrives;
}

$project->drive();          // DriveHandle (as Vaulter::drive($project))
$project->drives;           // the drives mounted on it (morphMany)
```

With `vaulter.drives.delete_with_owner = true` (default `false`) deleting the owner deletes its
drives as the system actor `owner-deleted`. A model with `SoftDeletes` reacts to `forceDeleted`,
not to `deleted`, so a soft-deleted owner keeps its drives.

## Events

Drive writes dispatch domain events you can listen to:

| Event | Dispatched by |
|---|---|
| `Vaulter\Drive\Events\Drives\DriveCreated` | first provisioning of a storage |
| `Vaulter\Drive\Events\Nodes\FolderCreated` | `createFolder()` |
| `Vaulter\Drive\Events\Files\FileUploaded` | `upload()` (new file) |
| `Vaulter\Drive\Events\Files\FileRevisionAdded` | `uploadVersion()` / resumable commit |
| `Vaulter\Drive\Events\Nodes\NodeMoved` | `move()` |
| `Vaulter\Drive\Events\Nodes\NodeRenamed` | `rename()` |
| `Vaulter\Drive\Events\Nodes\NodeCopied` | `copy()` |
| `Vaulter\Drive\Events\Nodes\NodeTrashed` | `trash()` |
| `Vaulter\Drive\Events\Nodes\NodeRestored` | `restore()` |
| `Vaulter\Drive\Events\Nodes\NodePurged` | `forceDelete()` |

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
- [`DriveHandle` API](/reference/facade) — full method signatures
