---
title: Document Nodes
description: Create, edit and version document nodes — working state, immutable checkpoints, restore and prune — over the Documents REST surface.
outline: deep
---

# Document Nodes

A **document node** is a `kind=document` entry in the unified node tree (`v_nodes`) whose
payload lives in its **document aggregate**: a working state in `v_documents` and immutable
checkpoints in `v_document_revisions`. It lives in the same tree as folders and files, so the
Drive Actions (move / rename / trash / restore) and the quota + sibling-unique guards apply to
it unchanged — a document is a node with an attached aggregate.

This page covers creating document nodes, reading and autosaving their content, the
**working state vs checkpoints** model, restoring a prior checkpoint, and how the history is
**pruned** (with `keep_forever` pins). For the format-routing layer — which editor a node
opens in — see [Document Handlers](/extending/file-handlers). For rendering content out to
HTML/Markdown, see [Export](./export.md).

::: info Documents are enabled by installing the package
Install `axioma-studio/vaulter-documents`. Editing, revisions and document routes
are available without a `document_versions` flag. Enable real-time collaboration
separately with `vaulter-documents.collaboration.enabled`.
:::

## The data model

A document has two parts (ADR-0022, amendment D34):

- **The working state** — one `v_documents` row per `kind=document` node (`node_id` unique,
  cascade on node delete). It holds the content every editor sees right now and is rewritten by
  every save.
- **Checkpoints** — rows of `v_document_revisions`. A checkpoint is **immutable**: after INSERT
  only `keep_forever` changes, so a revision id always names the same bytes. Autosave
  checkpoints are taken on an interval, named ones on demand, and a restore copies an older
  checkpoint forward.

The head pointer lives on the aggregate (`v_documents.current_revision_id`), never on the node:
`v_nodes` has no `current_version_id`/`file_id` any more, so a file revision and a document
revision with the same id can never be confused. On PostgreSQL and MySQL a composite foreign
key `(current_revision_id, id) → v_document_revisions(id, document_id)` with `ON DELETE
RESTRICT` makes the head always one of the document's own checkpoints and keeps pruning from
deleting it (SQLite: see [Database compatibility](/reference/compatibility)).

`v_documents` columns:

| Column | Type | Meaning |
|---|---|---|
| `id` | id-strategy | Primary key. |
| `tenant_id` | host key, nullable | Present only when tenancy is enabled. |
| `node_id` | FK → `v_nodes`, unique | The owning `kind=document` node (cascade on delete). |
| `type` | string(64) | The document type key, e.g. `vaulter/rich-text`, `vaulter/markdown`, `vaulter/spreadsheet`. |
| `schema_version` | integer | Version of the content schema of the working state. |
| `current_revision_id` | ref, nullable | The head checkpoint. |
| `revision_count` | integer | Monotonic checkpoint counter; pruning never lowers it, so a number is never reused. |
| `collab_epoch` | BIGINT ≥ 0 | Collaboration generation; moves on restore and on a JSON replace of Yjs state. |
| `state_version` | BIGINT ≥ 0 | Content validator; +1 on every effective write of the working state. |
| `checkpoint_state_version` | BIGINT ≥ 0 | The `state_version` the head captured. |
| `content`, `content_hash`, `content_size` | json, char(64), integer | The canonical JSON projection, its hash and byte size. |
| `yjs_state`, `yjs_state_size` | binary, integer | The Yjs CRDT state (collaboration source of truth) and its size. |
| `last_checkpoint_at` | timestamp | When the last checkpoint was taken. |
| `created_by`, `updated_by`, timestamps | | Audit. |

`v_document_revisions` columns: `id`, `tenant_id`, `document_id` (FK, cascade), `number`
(unique per document), `schema_version`, `content`, `content_hash`, `content_size`, `yjs_state`,
`yjs_state_size`, `state_version` (the working state version captured), `label`, `is_autosave`,
`keep_forever`, `status` (`committed`), `restored_from_revision_id` (FK, set null) and
`created_by`/`created_at`.

The content validator is **independent of the node**: `v_nodes.sequence` moves on rename, move
and trash, `state_version` on content writes. Renaming a document never invalidates an open
editor, and saving it never invalidates a rename (PLAN2.D35).
The full writer matrix and the ETag formats are in [Concurrency](../reference/concurrency.md).

## The PHP API

`Documents::in($drive)` and `Documents::comments($drive)` are the public PHP API. They take a drive
handle (`Vaulter::drive($owner)`, optionally `->actingAs($user)` or `->asSystem($reason)`) and return
`Vaulter\Documents\Operations\DocumentOperations` and `CommentOperations`. Every method locates the
node in that drive and authorizes it with the same guard as files: a trashed node, a node of another
drive or tenant, and a node the actor cannot see all throw `NodeNotFoundException`
(`404 node_not_found`); a visible node without the needed ability throws `PermissionDeniedException`
(`403 permission_denied`); a profile restriction throws `ProfileRestrictionException`.

| Method | Needs | Returns |
|---|---|---|
| `create($name, $type = 'vaulter/rich-text', ?array $content, $parent, ?CreateFolderOptions)` | `Create` on the parent, profile document types | `NodeSnapshot` |
| `content($document, $revision = null)` | `View` | `DocumentSnapshot` |
| `update($document, array $content, ?int $expectedStateVersion)` | `Edit` | `DocumentSnapshot` |
| `snapshot($document, ?string $label, bool $keepForever)` | `Edit` | `DocumentRevisionSnapshot` |
| `revisions($document, ?ListOptions)` | `View` | `Page<DocumentRevisionSnapshot>`, newest first |
| `restore($document, $revision, ?int $expectedStateVersion)` | `Edit` | `DocumentSnapshot` |
| `setRetention($document, $revision, bool $keepForever)` | `Edit` | `DocumentRevisionSnapshot` |
| `export($document, string $format, $revision = null)` | `Download` | `DocumentExport` |
| `collaborationSession($document)` | `View` (read-write with `Edit`); a user, not the system | `CollaborationSession` |

Comments (any node kind, the drive profile must enable comments):

| Method | Needs | Returns |
|---|---|---|
| `list($node, ?ListOptions)` | `View` | `Page<CommentSnapshot>`, oldest first |
| `add($node, AddCommentInput $input)` | `Comment`; the author is the acting user | `CommentSnapshot` |
| `resolve($node, $comment)` | `Comment`, and authorship or `Edit` | `CommentSnapshot` |
| `delete($node, $comment)` | authorship or `Manage` | — |

Nodes, revisions and comments are passed as their id (string or int) or their snapshot. A revision or
comment of another node is "not found" (`document_revision_not_found`, `comment_not_found`). Export
needs `Download`, not `View`: a previewer reads a document in the app but cannot take a copy out.

<!-- recipe: packages/documents/tests/Recipes/DocumentsRecipeTest.php#documents-operations -->

```php
use Vaulter\Documents\Data\Comments\AddCommentInput;
use Vaulter\Documents\Facades\Documents;
use Vaulter\Drive\Facades\Vaulter;

$drive = Vaulter::drive($project)->actingAs($user);
$documents = Documents::in($drive);

$node = $documents->create('Design notes', content: ['type' => 'doc', 'content' => []]);
$doc = $documents->content($node->id);            // DocumentSnapshot: content, stateVersion, epoch, …
$doc = $documents->update($node->id, $tipTapDoc, expectedStateVersion: $doc->stateVersion);
$named = $documents->snapshot($node->id, 'Before review', keepForever: true);
$html = $documents->export($node->id, 'html')->bytes;

Documents::comments($drive)->add($node->id, new AddCommentInput('Looks good', anchor: ['from' => 1, 'to' => 4]));
```

`DocumentSnapshot` carries `nodeId`, `documentId`, `type`, `schemaVersion`, `typeAvailable`,
`editable` (false for a historical revision, a type that is not installed, or content that reads
only partly under its type — see [Document Types](/extending/document-types)), `epoch`, `stateVersion`,
`dirty` (the working state runs ahead of the head checkpoint), `headRevisionId`, `content` (the
type's `DocumentContent`: `TipTapContent` for rich text and markdown, `OpaqueContent` for a
spreadsheet or a type that is not installed), `revision`
(the head, or the revision asked for), `contentHash`, `yjsStateSize`, `updatedAt` and `updatedBy`.
Ids are strings. The Actions behind these methods are internal.

## Creating a document node

A document node is created under a folder (or the storage root) together with its first
checkpoint, atomically. Rich-text content is a TipTap document — the root node must be
`{"type":"doc", ...}`.

::: code-group

<!-- recipe: packages/documents/tests/Recipes/DocumentsRecipeTest.php#documents-create -->

```php [PHP]
use Vaulter\Drive\Data\CreateFolderOptions;
use Vaulter\Documents\Facades\Documents;
use Vaulter\Drive\Enums\OnNameConflict;

$node = Documents::in($drive)->create(
    name: 'Design notes',
    type: 'vaulter/rich-text',              // or 'vaulter/markdown', 'vaulter/spreadsheet'
    content: [
        'type' => 'doc',
        'content' => [
            ['type' => 'paragraph', 'content' => [
                ['type' => 'text', 'text' => 'Hello.'],
            ]],
        ],
    ],
    parent: $folder->id,                     // null = drive root
    options: new CreateFolderOptions(OnNameConflict::Rename),
);
```

<!-- snippet: shell -->

```bash [HTTP]
# POST {prefix}/v1/drives/{drive}/documents
curl -X POST https://app.test/api/vaulter/v1/drives/42/documents \
  -H 'Content-Type: application/json' \
  -d '{
        "name": "Design notes",
        "parent_id": null,
        "type": "vaulter/rich-text",
        "content": { "type": "doc", "content": [] }
      }'
# → 201, the Node of the new kind=document node (with its ETag)
```

<!-- recipe: packages/vue/tests/recipes/documents.recipe.test.ts#vue-documents-create -->

```ts [Vue — the client]
import { useVaulterClient } from '@vaulter/vue'

const client = useVaulterClient()

const node = await client.documents.create(driveId, {
  name: 'Design notes',
  parentId: null,             // null = drive root
  type: 'vaulter/rich-text',
  content: { type: 'doc', content: [] },
})
```

:::

What happens:

- The sibling-unique guard runs first — a name clash throws `NodeNameConflictException`, or picks
  `Name (2)` with `OnNameConflict::Rename`. A type that is not registered throws
  `UnknownDocumentTypeException`; the content is decoded by the type's codec.
- One transaction creates the node and its aggregate, writes the working state
  (`state_version = 1`) and takes checkpoint **#1** (`is_autosave = false`), then fires
  `DocumentCreated` after commit.
- The node is stamped with the Vaulter document mime
  (`application/vnd.vaulter.document+json`); it is presented by its
  [document type](/extending/document-types), never by a file handler.

The request validates `name`, an optional `parent_id`, the document type `type` (`vendor/name`;
the configured default when absent) and an optional `content`. The type's codec validates the
content (`422 invalid_document_content`; for a TipTap-based type the root must be `doc`) and a type
that is not registered answers `422 unknown_document_type`. The actor needs the `Create` ability on
the destination (and the workspace-create gate of the permission driver), and the drive profile
must allow the document type.

## Reading content

Fetch the document's **working state** — the latest content, including the full TipTap doc:

::: code-group

<!-- recipe: packages/vue/tests/recipes/documents.recipe.test.ts#vue-documents-read -->

```ts [Vue — composable]
import { useDocument } from '@vaulter/vue'

const { content, head, load, loading } = useDocument(nodeId)

await load()
// content.value → TipTapDocument | null
// head.value    → the head DocumentContent (its `etag` goes into the next save)
```

<!-- snippet: shell -->

```bash [HTTP]
# GET {prefix}/v1/documents/{node}/content
curl -i https://app.test/api/vaulter/v1/documents/77/content
# → ETag: "77.0.3"
# → { "data": { "type": "vaulter/rich-text", "schema_version": 1, "state_version": 3,
#               "collab_epoch": 0, "revision_id": "12", "content": { "type": "doc", ... } } }
```

:::

The body holds the document's `type`, the `schema_version` of the content, the generation it
belongs to (`collab_epoch` and `state_version`), the head `revision_id` and the `content`. Nothing
in it depends on who reads it, so a reader and an editor receive the same body and the same tag:
`ETag: "{node}.{collab_epoch}.{state_version}"`. Renaming or moving the document does not change
the tag. `If-None-Match` with the current tag answers `304` — after the document has been found
and authorized, so a reader whose access was revoked gets `404`. The history listing (below)
omits `content` to stay light.

## Working state and checkpoints

- **Autosave** — every save replaces the **working state** (no history row). An **autosave
  checkpoint** (`is_autosave = true`) is taken when the head is missing or
  `vaulter-documents.revisions.checkpoint_interval_seconds` (default 300) has passed since the
  last one; `0` checkpoints every effective save. Stored checkpoints are never rewritten.
- **Named** (`is_autosave = false`, `label` set) — a durable, human-meaningful checkpoint of the
  current working state, taken on demand even when nothing changed; with `keep_forever` it is
  pinned out of pruning.

### Autosaving content

Every content write goes through `DocumentStateWriter`, the only writer of the working state:

- an identical save (same content hash and Yjs state) is a **no-op**: no version bump, no event;
- otherwise `state_version` grows by exactly one; pass `expectedStateVersion` to refuse a save
  based on a stale read (`412 document_state_mismatch`);
- replacing the JSON of a document that has Yjs state drops that state and moves
  `collab_epoch`, so open collaboration rooms reload instead of merging their old history back.

::: code-group

<!-- recipe: packages/vue/tests/recipes/documents.recipe.test.ts#vue-documents-autosave -->

```ts [Vue — debounced autosave]
import { useDocument } from '@vaulter/vue'

const { load, setContent, flush, save } = useDocument(nodeId, {
  debounceMs: 800,            // the CLIENT-side debounce before a save is sent
})

// Load first: a save carries the ETag of the content it is based on.
await load()

// As the editor emits changes, schedule a debounced save:
setContent(nextTipTapDoc)

// Force-flush a pending save (e.g. on blur / before navigation):
await flush()

// Save immediately, no debounce:
await save()
```

<!-- snippet: shell -->

```bash [HTTP]
# PUT {prefix}/v1/documents/{node}/content  — 200; If-Match is required
curl -X PUT https://app.test/api/vaulter/v1/documents/77/content \
  -H 'Content-Type: application/json' \
  -H 'If-Match: "77.0.3"' \
  -d '{ "content": { "type": "doc", "content": [] } }'
```

<!-- snippet: host -->

```php [PHP]
use Vaulter\Documents\Facades\Documents;

$doc = Documents::in($drive)->update(
    $node->id,
    $tipTapDoc,
    expectedStateVersion: 3,         // optional: 412 when another save landed first
);
```

:::

The PUT returns `200` with the new working state and its new `ETag`; `update()` returns the new
`DocumentSnapshot`. Over HTTP the write needs `If-Match` with the content tag you read: without it
`428 precondition_required` (so does `*`), with a stale or weak one `412 document_state_mismatch`
— for instance after the collaboration hub stored a newer state in between. Only the JSON
`content` travels; the binary Yjs state is written by the hub. When the save changed something the action fires `DocumentContentUpdated` after
commit: `version` is the head checkpoint after the save and `created` tells whether this save
took it. The event's `actorId` is the acting user (null for the system).

::: tip Client debounce vs checkpoint interval
`useDocument({ debounceMs })` is the **client** timer before a save is sent (default
800 ms). `revisions.checkpoint_interval_seconds` is the **server** interval between autosave
checkpoints (default 300 s). Tune the client for network chatter and the server for how
granular the history is. The 0.2 key `revisions.autosave_debounce_seconds` is deprecated and
ignored: it rewrote the head in place, which checkpoints never do.
:::

### Creating a named version

Snapshot the current head as a labelled checkpoint:

::: code-group

<!-- recipe: packages/vue/tests/recipes/documents.recipe.test.ts#vue-documents-snapshot -->

```ts [Vue]
import { useRevisions } from '@vaulter/vue'

const { snapshot } = useRevisions(nodeId)

// keepForever = true pins it out of the prune window
const version = await snapshot({ label: 'Before client review', keepForever: true })
```

<!-- snippet: shell -->

```bash [HTTP]
# POST {prefix}/v1/documents/{node}/revisions  → 201
curl -X POST https://app.test/api/vaulter/v1/documents/77/revisions \
  -H 'Content-Type: application/json' \
  -d '{ "label": "Before client review", "keep_forever": true }'
```

<!-- snippet: host -->

```php [PHP]
$revision = Documents::in($drive)->snapshot($node->id, 'Before client review', keepForever: true);
```

:::

A named snapshot checkpoints the current **working state** into a fresh
`is_autosave = false` revision, makes it the head, prunes, and fires `DocumentRevisionCreated`
(`restored = false`). It changes neither `state_version` nor `collab_epoch`, so it needs no
precondition.

## Listing the version history

`GET {prefix}/v1/documents/{node}/revisions`, and one revision with its content at
`GET {prefix}/v1/documents/{node}/revisions/{revision}`. A revision can be pinned or released with
`PATCH {prefix}/v1/documents/{node}/revisions/{revision}` (`{ "keep_forever": true }`); its label is
set when it is created and not changed afterwards.

::: code-group

<!-- recipe: packages/vue/tests/recipes/documents.recipe.test.ts#vue-documents-revisions -->

```ts [Vue]
import { useRevisions } from '@vaulter/vue'

const { revisions, list } = useRevisions(nodeId)

await list()
// revisions.value → DocumentRevision[] (newest first; `content` omitted)
```

<!-- snippet: shell -->

```bash [HTTP — cursor-paginated]
# GET {prefix}/v1/documents/{node}/revisions?per_page=50
curl 'https://app.test/api/vaulter/v1/documents/77/revisions?per_page=50'
```

<!-- recipe: packages/documents/tests/Recipes/DocumentsRecipeTest.php#documents-revisions -->

```php [PHP]
use Vaulter\Drive\Data\ListOptions;

$page = Documents::in($drive)->revisions($node->id, new ListOptions(perPage: 50));
$next = Documents::in($drive)->revisions($node->id, new ListOptions(perPage: 50, cursor: $page->nextCursor));
```

:::

The listing is cursor-paginated (`per_page` clamped to 1–200, default 50), newest first (by
creation time), and omits `content`. Each item is a `DocumentRevision`; ids are strings:

<!-- snippet: host -->

```ts
interface DocumentRevision {
  id: string
  number: number
  schema_version: number
  label: string | null             // set on named revisions
  is_autosave: boolean
  keep_forever: boolean
  content_hash: string | null
  is_head: boolean
  created_by: string | null
  created_at: string
  content?: object                 // only in `GET documents/{node}/revisions/{revision}`
}
```

## Restoring a prior version

A restore is **copy-forward**: it never mutates the source checkpoint — its content and Yjs
state become the working state and a fresh checkpoint records `restored_from_revision_id`, so
the history stays an immutable, auditable log.

::: code-group

<!-- recipe: packages/vue/tests/recipes/documents.recipe.test.ts#vue-documents-restore -->

```ts [Vue]
import { useDocument, useRevisions } from '@vaulter/vue'

const { head, load } = useDocument(nodeId)
const { restore } = useRevisions(nodeId)

await load()
const restored = await restore(versionId, { etag: head.value!.etag! })
// the new head is a copy of versionId, labelled "Restored from #N"
```

<!-- snippet: shell -->

```bash [HTTP]
# POST {prefix}/v1/documents/{node}/revisions/{revision}/restore  → 200; If-Match is required
curl -X POST https://app.test/api/vaulter/v1/documents/77/revisions/12/restore \
  -H 'If-Match: "77.0.3"'
```

<!-- snippet: host -->

```php [PHP]
$doc = Documents::in($drive)->restore($node->id, '12', expectedStateVersion: $doc->stateVersion);
// $doc->revision is the new head; $doc->revision->restoredFromRevisionId === '12'
```

:::

Over HTTP a restore is a content write, so it needs `If-Match` with the content tag, answers
`200` with the new working state and its tag, and moves the `collab_epoch`.

A restore resolves the source checkpoint **within the node's own document** (a revision id of another document, or a file revision with the same id, never
restores here), writes its payload as the working state (`state_version` +1, `collab_epoch` +1;
optional `expectedStateVersion`), takes a checkpoint labelled `Restored from #N` with
`restored_from_revision_id`, prunes, and fires `DocumentRevisionCreated` with `restored = true`.

## Pruning & retention

The history is bounded by `config('vaulter-documents.revisions.limit')` — the number of most-recent
checkpoints kept (`0` = unlimited). Pruning runs **inside** the write transaction right after
each checkpoint (`DocumentRevisionStoreRepository::prune`).

<!-- snippet: config -->

```php
// config/vaulter-documents.php
'revisions' => [
    'limit' => 50,                          // stored checkpoints per document before pruning (0 = ∞)
    'checkpoint_interval_seconds' => 300,   // autosave checkpoint interval described above
],
```

The prune **never** deletes:

- a **`keep_forever`** version — these are excluded before the window is counted, so they
  never count against `max` and are never deleted; and
- the **current head** — always excluded.

The newest `max` of the remaining (prunable) rows are kept; everything past that window is
deleted. `versions.max` falls back to the legacy `max_versions` key if unset. The head is also
protected by the head foreign key on PostgreSQL/MySQL.

::: warning Pruning is permanent
A pruned checkpoint is hard-deleted. Pin anything you must keep with a named,
`keep_forever` checkpoint **before** it falls out of the `max` window.
:::

## Copying and deleting

Copying a document node — alone or inside a copied folder — creates a **new document** holding the
source's working state as its only checkpoint: same type and schema version, the JSON content and
Yjs state of the source, `state_version` 1, `collab_epoch` 0, a non-autosave first checkpoint. The
history, the open collaboration rooms and the **comments are not copied**; the source is not
changed. A document whose type is no longer registered is copied as opaque content with its type
kept. The copy does not change `used_bytes` (inline content is outside the drive quota). Like for
files, the whole copy is refused with `permission_denied` before any row is created when the
copier lacks `View`, `Copy` or `Download` on any document in the subtree.

Purging a trashed document deletes its checkpoints in batches of 500, then the document row, before
the node itself goes. Without the documents package, drive purges documents by the foreign-key
cascade and refuses to copy them (`unsupported_node_kind`); shortcuts still cannot be copied.

## Events

Every document-node write fires an after-commit event (`ShouldDispatchAfterCommit`) — wire
listeners, the activity feed or [webhooks](./recipes.md#webhooks) to them:

| Event | Fired by | Payload |
|---|---|---|
| `Vaulter\Documents\Events\Documents\DocumentCreated` | create | `node`, `version` (checkpoint #1), `stateVersion`, `epoch`, `actorId` |
| `Vaulter\Documents\Events\Documents\DocumentContentUpdated` | autosave / collaboration store (only when something changed) | `node`, `version` (the head checkpoint after the save), `created` (bool — this save took a checkpoint), `stateVersion`, `epoch` (after the save), `actorId` |
| `Vaulter\Documents\Events\Revisions\DocumentRevisionCreated` | named / restore | `node`, `version`, `stateVersion`, `epoch`, `restored` (bool), `actorId` |

## Errors

| Exception | When |
|---|---|
| `Documents\Exceptions\NotADocumentException` | A content/version operation targets a node that is not `kind=document`. |
| `Exceptions\Nodes\NodeNotFoundException` | The node is missing, trashed, in another drive or tenant, or the actor cannot see it (`404 node_not_found`). |
| `Exceptions\Access\PermissionDeniedException` | The actor sees the node but lacks the ability (`403 permission_denied`), e.g. export without `Download`. |
| `Documents\Exceptions\UnknownDocumentTypeException` | `create()` names a type that is unknown or not enabled (`422 unknown_document_type`). |
| `Documents\Exceptions\CommentNotFoundException` | A comment id that does not belong to the addressed node (`404 comment_not_found`). |
| `Documents\Exceptions\InvalidDocumentContentException` | The root content node is not `{"type":"doc"}`, or it exceeds `max_document_size_kb`. |
| `Documents\Exceptions\DocumentRevisionNotFoundException` | A revision id that does not belong to the node's document. |
| `Documents\Exceptions\StaleDocumentStateException` | `expectedStateVersion` no longer matches (`412 document_state_mismatch`). |
| `Documents\Exceptions\CollaborationEpochMismatchException` | A collaboration write from an older room generation (`409 collaboration_epoch_mismatch`). |
| `Exceptions\Drive\NodeNameConflictException` | A create collides with a sibling name. |

## Next

- [Document Handlers](/extending/file-handlers) — which editor / preview / download surface a
  node opens in, and adding a **new format with no migration**.
- [Export](./export.md) — render a document's content to HTML / Markdown, and register your
  own PDF/DOCX exporter.
