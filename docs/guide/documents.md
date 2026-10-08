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
opens in — see [Document Handlers](./document-handlers.md). For rendering content out to
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
deleting it (SQLite: see [Database compatibility](./database-compatibility.md)).

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

## Creating a document node

A document node is created under a folder (or the storage root) together with its **v1**
version, atomically. Content is a TipTap document wrapped in
`Vaulter\Documents\ValueObjects\TipTapContent` — the root node must be `{"type":"doc", ...}`.

::: code-group

```php [PHP — the Action]
use Vaulter\Documents\Actions\Documents\CreateDocumentAction;
use Vaulter\Documents\Data\Documents\CreateDocumentInput;
use Vaulter\Documents\ValueObjects\TipTapContent;

$node = app(CreateDocumentAction::class)->execute(new CreateDocumentInput(
    storage: $storage,                       // the resolved v_storages row
    name:    'Design notes',
    content: TipTapContent::fromArray([
        'type'    => 'doc',
        'content' => [
            ['type' => 'paragraph', 'content' => [
                ['type' => 'text', 'text' => 'Hello.'],
            ]],
        ],
    ]),
    parent:  $folder,                        // null = storage root
    format:  'richtext',                     // a DocumentType value (default 'tiptap')
    actorId: $user->getKey(),
));
```

```bash [HTTP]
# POST {prefix}/storages/{storage}/documents
curl -X POST https://app.test/api/vaulter/storages/42/documents \
  -H 'Content-Type: application/json' \
  -d '{
        "name": "Design notes",
        "parent_id": null,
        "format": "richtext",
        "content": { "type": "doc", "content": [] }
      }'
# → 201, a NodeResource for the new kind=document node
```

```ts [Vue — the client]
import { useVaulterClient } from '@vaulter/vue'

const client = useVaulterClient()

const node = await client.documentNodes.createNode(storageId, {
  name: 'Design notes',
  parentId: null,             // null = storage root
  format: 'richtext',
  content: { type: 'doc', content: [] },
})
```

:::

What happens (`CreateDocumentAction`):

- The sibling-unique guard runs first — a name clash throws `NodeNameConflictException`.
- One transaction creates the node and its aggregate, writes the working state
  (`state_version = 1`) and takes checkpoint **#1** (`is_autosave = false`), then fires
  `DocumentCreated` after commit.
- The node is stamped with the Vaulter document mime
  (`application/vnd.vaulter.document+json`) so it always routes to the TipTap editor handler
  regardless of any extension in its name. See [Document Handlers](./document-handlers.md).

The request validates `name`, an optional `parent_id`, an optional `format` (must be a
[`DocumentType`](./document-handlers.md#documenttype) value: `richtext`, `table`, `markdown`,
`template`), and an optional `content` whose `content.type` must be `doc`. Authorization is
enforced upstream in the controller — the actor needs both `canCreateInWorkspace` and the
`Create` ability on the destination.

## Reading content

Fetch the document's **working state** — the latest content, including the full TipTap doc:

::: code-group

```ts [Vue — composable]
import { useDocumentNode } from '@vaulter/vue'

const { content, head, load, loading } = useDocumentNode(nodeId)

await load()
// content.value → TipTapDocument | null
// head.value    → the head DocumentVersion (its `content` is populated)
```

```bash [HTTP]
# GET {prefix}/nodes/{node}/content
curl https://app.test/api/vaulter/nodes/77/content
# → { "data": { ...version, "content": { "type": "doc", ... } } }
```

:::

The response keeps the shape of a version: `id` and `version_no` name the head checkpoint,
`content`/`content_hash`/`yjs_state_size` are the working state, and `is_autosave` is `true`
while the working state runs ahead of the head checkpoint. The history listing (below) omits
`content` to stay light.

## Working state and checkpoints

- **Autosave** — every save replaces the **working state** (no history row). An **autosave
  checkpoint** (`is_autosave = true`) is taken when the head is missing or
  `vaulter-documents.revisions.checkpoint_interval_seconds` (default 300) has passed since the
  last one; `0` checkpoints every effective save. Stored checkpoints are never rewritten.
- **Named** (`is_autosave = false`, `label` set) — a durable, human-meaningful checkpoint of the
  current working state, taken on demand even when nothing changed; with `keep_forever` it is
  pinned out of pruning.

### Autosaving content

`UpdateDocumentContentAction` writes through `DocumentStateWriter`, the only writer of the
working state:

- an identical save (same content hash and Yjs state) is a **no-op**: no version bump, no event;
- otherwise `state_version` grows by exactly one; pass `expectedStateVersion` to refuse a save
  based on a stale read (`412 document_state_mismatch`);
- replacing the JSON of a document that has Yjs state drops that state and moves
  `collab_epoch`, so open collaboration rooms reload instead of merging their old history back.

::: code-group

```ts [Vue — debounced autosave]
import { useDocumentNode } from '@vaulter/vue'

const { content, setContent, flush, save, saving } = useDocumentNode(nodeId, {
  debounceMs: 800,            // the CLIENT-side debounce before a PATCH is sent
})

// As the editor emits changes, schedule a debounced save:
setContent(nextTipTapDoc)

// Force-flush a pending save (e.g. on blur / before navigation):
await flush()

// Save immediately, no debounce:
await save()
```

```bash [HTTP]
# PATCH {prefix}/nodes/{node}/content  — always 200 (semantically an UPDATE)
curl -X PATCH https://app.test/api/vaulter/nodes/77/content \
  -H 'Content-Type: application/json' \
  -d '{
        "content": { "type": "doc", "content": [] },
        "yjs_state": null
      }'
```

```php [PHP — the Action]
use Vaulter\Documents\Actions\Documents\UpdateDocumentContentAction;
use Vaulter\Documents\Data\Documents\UpdateDocumentContentInput;
use Vaulter\Documents\ValueObjects\TipTapContent;

app(UpdateDocumentContentAction::class)->execute(new UpdateDocumentContentInput(
    node:     $node,
    content:  TipTapContent::fromArray($tipTapDoc),
    yjsState: null,                  // optional raw Yjs binary update
    actorId:  $user->getKey(),
    expectedStateVersion: 3,         // optional: 412 when another save landed first
));
```

:::

The PATCH returns `200` with the working state — an autosave is semantically an update.
`yjs_state` is the optional **base64-encoded** Yjs CRDT update on the wire; the request decodes
it back to raw bytes (and is null for the non-collaborative path). The TipTap `content` is its
projection. When the save changed something the action fires `DocumentContentUpdated` after
commit: `version` is the head checkpoint after the save and `created` tells whether this save
took it.

::: tip Client debounce vs checkpoint interval
`useDocumentNode({ debounceMs })` is the **client** timer before a PATCH is sent (default
800 ms). `revisions.checkpoint_interval_seconds` is the **server** interval between autosave
checkpoints (default 300 s). Tune the client for network chatter and the server for how
granular the history is. The 0.2 key `revisions.autosave_debounce_seconds` is deprecated and
ignored: it rewrote the head in place, which checkpoints never do.
:::

### Creating a named version

Snapshot the current head as a labelled checkpoint:

::: code-group

```ts [Vue]
const { createNamedVersion } = useDocumentNode(nodeId)

// keepForever = true pins it out of the prune window
const version = await createNamedVersion('Before client review', true)
```

```bash [HTTP]
# POST {prefix}/nodes/{node}/document-versions  → 201
curl -X POST https://app.test/api/vaulter/nodes/77/document-versions \
  -H 'Content-Type: application/json' \
  -d '{ "label": "Before client review", "keep_forever": true }'
```

```php [PHP — the Action]
use Vaulter\Documents\Actions\Revisions\CreateNamedRevisionAction;
use Vaulter\Documents\Data\Revisions\CreateNamedRevisionInput;

app(CreateNamedRevisionAction::class)->execute(new CreateNamedRevisionInput(
    node:        $node,
    label:       'Before client review',
    keepForever: true,
    actorId:     $user->getKey(),
));
```

:::

`CreateNamedRevisionAction` checkpoints the current **working state** into a fresh
`is_autosave = false` revision, makes it the head, prunes, and fires `DocumentRevisionCreated`
(`restored = false`). It changes neither `state_version` nor `collab_epoch`, so it needs no
precondition.

## Listing the version history

::: code-group

```ts [Vue]
const { versions, loadVersions } = useDocumentNode(nodeId)

await loadVersions()
// versions.value → DocumentVersion[] (newest version_no first; `content` omitted)
```

```bash [HTTP — cursor-paginated]
# GET {prefix}/nodes/{node}/document-versions?per_page=50
curl 'https://app.test/api/vaulter/nodes/77/document-versions?per_page=50'
```

:::

The listing is cursor-paginated (`per_page` clamped to 1–200, default 50), newest
`version_no` first, and omits `content`. Each item is a `DocumentVersion`:

```ts
interface DocumentVersion {
  id: NodeId
  node_id: NodeId
  version_no: number
  format: 'richtext' | 'table' | 'markdown' | string
  snapshot_label: string | null   // set on named versions
  is_autosave: boolean
  keep_forever: boolean
  status: string                   // 'committed'
  content_hash: string | null
  yjs_state_size: number | null    // the binary state is NOT serialised, only its size
  created_by: WorkspaceId | null
  created_at: string | null
  content: TipTapDocument | null   // populated only by the single-version `content` fetch
}
```

## Restoring a prior version

A restore is **copy-forward**: it never mutates the source checkpoint — its content and Yjs
state become the working state and a fresh checkpoint records `restored_from_revision_id`, so
the history stays an immutable, auditable log.

::: code-group

```ts [Vue]
const { restoreVersion } = useDocumentNode(nodeId)

const restored = await restoreVersion(versionId)
// the new head is a copy of versionId, labelled "Restored from #N"
```

```bash [HTTP]
# POST {prefix}/nodes/{node}/document-versions/{version}/restore  → 201
curl -X POST https://app.test/api/vaulter/nodes/77/document-versions/12/restore
```

```php [PHP — the Action]
use Vaulter\Documents\Actions\Revisions\RestoreDocumentRevisionAction;

app(RestoreDocumentRevisionAction::class)->execute(
    node:      $node,
    versionId: 12,
    actorId:   $user->getKey(),
);
```

:::

`RestoreDocumentRevisionAction` resolves the source checkpoint **within the node's own
document** (a revision id of another document, or a file revision with the same id, never
restores here), writes its payload as the working state (`state_version` +1, `collab_epoch` +1;
optional `expectedStateVersion`), takes a checkpoint labelled `Restored from #N` with
`restored_from_revision_id`, prunes, and fires `DocumentRevisionCreated` with `restored = true`.

## Pruning & retention

The history is bounded by `config('vaulter-documents.revisions.limit')` — the number of most-recent
checkpoints kept (`0` = unlimited). Pruning runs **inside** the write transaction right after
each checkpoint (`DocumentRevisionStoreRepository::prune`).

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
| `Vaulter\Documents\Events\Documents\DocumentCreated` | create | `node`, `version` (checkpoint #1), `actorId` |
| `Vaulter\Documents\Events\Documents\DocumentContentUpdated` | autosave / collaboration store (only when something changed) | `node`, `version` (the head checkpoint after the save), `created` (bool — this save took a checkpoint), `actorId` |
| `Vaulter\Documents\Events\Revisions\DocumentRevisionCreated` | named / restore | `node`, `version`, `restored` (bool), `actorId` |

## Errors

| Exception | When |
|---|---|
| `Exceptions\NotADocumentException` | A content/version operation targets a node that is not `kind=document`. |
| `Documents\Exceptions\InvalidDocumentContentException` | The root content node is not `{"type":"doc"}`, or it exceeds `max_document_size_kb`. |
| `Exceptions\DocumentRevisionNotFoundException` | A restore targets a version id that does not belong to the node. |
| `Documents\Exceptions\StaleDocumentStateException` | `expectedStateVersion` no longer matches (`412 document_state_mismatch`). |
| `Documents\Exceptions\CollaborationEpochMismatchException` | A collaboration write from an older room generation (`409 collaboration_epoch_mismatch`). |
| `Exceptions\Drive\NodeNameConflictException` | A create collides with a sibling name. |

## Next

- [Document Handlers](./document-handlers.md) — which editor / preview / download surface a
  node opens in, and adding a **new format with no migration**.
- [Export](./export.md) — render a document's content to HTML / Markdown, and register your
  own PDF/DOCX exporter.
