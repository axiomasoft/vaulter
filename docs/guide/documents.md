---
title: Document Nodes
description: Create, edit and version document nodes — TipTap content, autosave vs named versions, restore and prune — over the Documents REST surface.
outline: deep
---

# Document Nodes

A **document node** is a `kind=document` entry in the unified node tree (`v_nodes`) whose
payload is structural TipTap JSON, versioned in `v_node_document_versions`. It lives in the
same tree as folders and files, so the Drive Actions (move / rename / trash / restore) and
the quota + sibling-unique guards apply to it unchanged — a document is just a node with an
attached version history.

This page covers creating document nodes, reading and autosaving their content, the
**autosave vs named version** model, restoring a prior version, and how the history is
**pruned** (with `keep_forever` pins). For the format-routing layer — which editor a node
opens in — see [Document Handlers](./document-handlers.md). For rendering content out to
HTML/Markdown, see [Export](./export.md).

::: warning Opt-in feature
The document-node surface is gated on the **`document_versions`** feature flag, which is
**off by default**. The `v_node_document_versions` table is only created and the Documents
routes are only registered when it is on:

```php
// config/vaulter.php
'features' => [
    'document_versions' => true, // enables the node-attached document path
],
```

With the flag off, `Vaulter\Documents\Models\NodeDocumentVersion` has no table and the
`storages/{storage}/documents`, `nodes/{node}/content`, `nodes/{node}/document-versions`
routes do not exist.
:::

## The data model

A document node carries its head pointer the same way a file node does — `current_version_id`
on the node points at the head `NodeDocumentVersion`. The version table is **append-only**:
a committed version is immutable (no `updated_at`, no soft-delete), `version_no` is monotonic
per node, and the unique `(node_id, version_no)` index is the backbone two concurrent commits
serialise on.

`v_node_document_versions` columns (from `PanelTablesBuilder::defineDocumentVersions`):

| Column | Type | Meaning |
|---|---|---|
| `id` | id-strategy | Primary key (bigint / ulid / uuid7 per `ids.default`). |
| `tenant_id` | nullable | Present only when `tenant.enabled`. |
| `node_id` | FK → `v_nodes` | The owning `kind=document` node (cascade on delete). |
| `version_no` | integer | Monotonic per node; `unique(node_id, version_no)`. |
| `content` | json / jsonb | The TipTap/ProseMirror JSON projection. |
| `yjs_state` | bytea / longBlob | The binary Yjs CRDT update — the realtime source of truth (collaboration). |
| `yjs_state_size` | integer, nullable | Cached byte length of `yjs_state` (a list never streams the blob to size it). |
| `format` | string(16), default `tiptap` | The version format key. |
| `content_hash` | char(64), nullable | Content hash (dedup / change detection). |
| `snapshot_label` | string, nullable | The label of a **named** version; null for autosaves. |
| `is_autosave` | boolean, default `false` | Distinguishes the autosave churn from named checkpoints. |
| `status` | string(12), default `committed` | Lifecycle status (indexed). |
| `keep_forever` | boolean, default `false` | Pins the version out of the prune window. |
| `created_by` | actor, nullable | The actor id. |
| `created_at` | timestamp, nullable | Append-only — there is no `updated_at`. |

::: info Distinct from the legacy version table
`NodeDocumentVersion` (node-attached, `v_node_document_versions`) is **separate** from the
legacy `DocumentVersion` (document-id-attached, `v_document_versions`). The legacy path stays
intact; this page documents only the node-attached path.
:::

## Creating a document node

A document node is created under a folder (or the storage root) together with its **v1**
version, atomically. Content is a TipTap document wrapped in
`Vaulter\ValueObjects\Documents\TipTapContent` — the root node must be `{"type":"doc", ...}`.

::: code-group

```php [PHP — the Action]
use Vaulter\Documents\Actions\CreateDocumentNodeAction;
use Vaulter\Data\CreateDocumentNodeData;
use Vaulter\ValueObjects\Documents\TipTapContent;

$node = app(CreateDocumentNodeAction::class)->execute(new CreateDocumentNodeData(
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

What happens (`CreateDocumentNodeAction`):

- The sibling-unique guard runs first — a name clash throws `NodeNameConflictException`.
- One transaction creates the node, commits the **v1** version (`version_no = 1`,
  `is_autosave = false`, `status = committed`), repoints `current_version_id` to it, then
  fires `DocumentNodeCreated` after commit.
- The node is stamped with the Vaulter document mime
  (`application/vnd.vaulter.document+json`) so it always routes to the TipTap editor handler
  regardless of any extension in its name. See [Document Handlers](./document-handlers.md).

The request validates `name`, an optional `parent_id`, an optional `format` (must be a
[`DocumentType`](./document-handlers.md#documenttype) value: `richtext`, `table`, `markdown`,
`template`), and an optional `content` whose `content.type` must be `doc`. Authorization is
enforced upstream in the controller — the actor needs both `canCreateInWorkspace` and the
`Create` ability on the destination.

## Reading content

Fetch the node's **head** content — the single version that includes the full TipTap doc:

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

Server-side the controller resolves `head($node) ?? latest($node)` (the head pointer, else
the highest `version_no`) and returns a `NodeDocumentVersionResource` **with** content. The
history listing (below) omits `content` to stay light — only this single-version fetch carries
the full doc.

## Autosave vs named versions

The history has two lanes, and the distinction is the heart of the version model:

- **Autosave** (`is_autosave = true`) — the churn of an editing session. Within the
  **debounce window** of the head autosave version, a new autosave **overwrites it in place**
  (no new row); outside the window a fresh autosave row is appended. This keeps a typing
  session from flooding the history.
- **Named** (`is_autosave = false`, `snapshot_label` set) — a durable, human-meaningful
  checkpoint. A named version is **never** overwritten by the debounce path; it always
  creates a fresh row, and with `keep_forever` it is pinned out of pruning entirely.

### Autosaving content

`UpdateDocumentContentAction` is the autosave path. The decision to overwrite-vs-append is
`shouldOverwrite(head)`:

- the head must be an **autosave** version (a committed/named head always forces a fresh row), and
- it must be within `config('vaulter.versions.autosave_debounce_seconds')` of now
  (a window of `0` means every autosave overwrites the head autosave).

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
use Vaulter\Documents\Actions\UpdateDocumentContentAction;
use Vaulter\Data\UpdateDocumentContentData;
use Vaulter\ValueObjects\Documents\TipTapContent;

app(UpdateDocumentContentAction::class)->execute(new UpdateDocumentContentData(
    node:     $node,
    content:  TipTapContent::fromArray($tipTapDoc),
    yjsState: null,                  // optional raw Yjs binary update
    actorId:  $user->getKey(),
));
```

:::

The PATCH returns `200` even when it appended a fresh row — an autosave is semantically an
update. `yjs_state` is the optional **base64-encoded** Yjs CRDT update on the wire; the request
decodes it back to raw bytes (and is null for the non-collaborative path). The TipTap `content`
is its projection. The action fires `DocumentContentUpdated` after commit with `created`
true/false telling listeners whether a row was appended.

::: tip Two debounce windows
There are two independent debounce knobs. `useDocumentNode({ debounceMs })` is the **client**
timer before a PATCH is sent (default 800 ms). `versions.autosave_debounce_seconds` is the
**server** window that decides overwrite-vs-append (default 30 s). They are unrelated — tune
the client for network chatter and the server for how granular the autosave history is.
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
use Vaulter\Documents\Actions\CreateNamedVersionAction;
use Vaulter\Data\CreateNamedVersionData;

app(CreateNamedVersionAction::class)->execute(new CreateNamedVersionData(
    node:        $node,
    label:       'Before client review',
    keepForever: true,
    actorId:     $user->getKey(),
));
```

:::

`CreateNamedVersionAction` copies the current head's content/yjs/format into a fresh
`is_autosave = false` row, repoints the head to it, prunes, and fires `DocumentVersionCreated`
(`restored = false`). A named version converts the working state into a durable checkpoint —
distinct from autosave churn.

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

A restore is **append-only**: it never mutates the target version — it copies its content
into a fresh version that becomes the new head, so the history stays an immutable, auditable
log and the restore is itself a versioned event.

::: code-group

```ts [Vue]
const { restoreVersion } = useDocumentNode(nodeId)

const restored = await restoreVersion(versionId)
// the new head is a copy of versionId, labelled "Restored from vN"
```

```bash [HTTP]
# POST {prefix}/nodes/{node}/document-versions/{version}/restore  → 201
curl -X POST https://app.test/api/vaulter/nodes/77/document-versions/12/restore
```

```php [PHP — the Action]
use Vaulter\Documents\Actions\RestoreDocumentVersionAction;

app(RestoreDocumentVersionAction::class)->execute(
    node:      $node,
    versionId: 12,
    actorId:   $user->getKey(),
);
```

:::

`RestoreDocumentVersionAction` resolves the source version **bounded to the node** (a version
id from another node never restores here), copies its content/yjs/format into a new committed
row labelled `Restored from vN`, repoints the head, prunes, and fires `DocumentVersionCreated`
with `restored = true`.

## Pruning & retention

The history is bounded by `config('vaulter.versions.max')` — the number of most-recent
versions kept (`0` = unlimited). Pruning runs **inside** each write transaction
(`NodeDocumentVersionStoreRepository::prune`) after a create/named/restore.

```php
// config/vaulter.php
'versions' => [
    'max' => 50,                       // stored versions per node before pruning (0 = ∞)
    'autosave_debounce_seconds' => 30, // the server overwrite window described above
],
```

The prune **never** deletes:

- a **`keep_forever`** version — these are excluded before the window is counted, so they
  never count against `max` and are never deleted; and
- the **current head** — always excluded.

The newest `max` of the remaining (prunable) rows are kept; everything past that window is
deleted. `versions.max` falls back to the legacy `max_versions` key if unset.

::: warning Pruning is permanent
A pruned version is hard-deleted (the table is append-only with no soft-delete). Pin anything
you must keep with a named, `keep_forever` version **before** it falls out of the `max`
window.
:::

## Events

Every document-node write fires an after-commit event (`ShouldDispatchAfterCommit`) — wire
listeners, the activity feed or [webhooks](./recipes.md#webhooks) to them:

| Event | Fired by | Payload |
|---|---|---|
| `Vaulter\Documents\Events\DocumentNodeCreated` | create | `node`, `version` (v1), `actorId` |
| `Vaulter\Documents\Events\DocumentContentUpdated` | autosave | `node`, `version`, `created` (bool — appended vs overwritten), `actorId` |
| `Vaulter\Documents\Events\DocumentVersionCreated` | named / restore | `node`, `version`, `restored` (bool), `actorId` |

## Errors

| Exception | When |
|---|---|
| `Exceptions\Documents\NotADocumentNodeException` | A content/version operation targets a node that is not `kind=document`. |
| `Exceptions\Documents\InvalidTipTapContentException` | The root content node is not `{"type":"doc"}`, or it exceeds `max_document_size_kb`. |
| `Exceptions\Documents\NodeDocumentVersionNotFoundException` | A restore targets a version id that does not belong to the node. |
| `Exceptions\Drive\NodeNameConflictException` | A create collides with a sibling name. |

## Next

- [Document Handlers](./document-handlers.md) — which editor / preview / download surface a
  node opens in, and adding a **new format with no migration**.
- [Export](./export.md) — render a document's content to HTML / Markdown, and register your
  own PDF/DOCX exporter.
