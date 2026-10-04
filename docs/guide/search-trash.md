# Search & Trash

## Search

A storage's node tree accepts a `q` term, matched (case-insensitively) against the node name,
scoped to the storage and filtered by the `view` ability:

```
GET /api/vaulter/storages/{storage}/search?q=roadmap
```

```ts
import { useVaulterClient } from '@vaulter/vue'

const client = useVaulterClient()
const page = await client.nodes.search(storageId, 'roadmap')
```

::: tip Scaling search
The default search uses a `LIKE` scan — fine for typical storages. For large datasets, swap in
a real search index (e.g. Laravel Scout / Meilisearch) by pointing `config('vaulter.models.node')`
at a searchable model and overriding the read repository.
:::

## Exact visibility and cursors

Search and trash only return nodes with effective `View`, including when the host
uses Gate, Spatie or AzGuard. Explicit denies and inheritance cut-offs hide the node
and its metadata. Listing `abilities` come from the same batch evaluation.

Pages expose `meta.next_cursor`, `per_page`, `scanned`, `path` and a null
`prev_cursor`. They have no `total` or numbered page links. Send `cursor` from the
previous response until `next_cursor` is null. An empty page may still have a cursor:
`vaulter.listing.scan_budget` (default 1000) limits inspected candidates per request.
Cursors are signed with the application key and bound to the listing query; reuse
with another storage, parent or search term returns 422 `validation_failed` and
`errors.cursor`. Restart without a cursor after changing the query.

## Trash

Deleting a node soft-deletes it (and its subtree). The trash can be listed, restored, or
permanently purged.

| Method | URL | Ability |
|---|---|---|
| GET | `/storages/{storage}/trash` | `view` |
| DELETE | `/nodes/{node}` | `edit` |
| POST | `/nodes/{node}/restore` | `edit` |
| DELETE | `/nodes/{node}/force` | `manage` |

```ts
const client = useVaulterClient()

const trashed = await client.nodes.trashed(storageId)
await client.nodes.trash(nodeId)        // reversible soft-delete
await client.nodes.restore(nodeId)      // bring it back
await client.nodes.forceDelete(nodeId)  // permanent
```

Both the reversible delete and the permanent purge are recorded in the
[activity feed](/reference/rest-api#activity-feed), so your app can treat hard deletes differently in audit logs.

## Move & copy

| Method | URL | Ability |
|---|---|---|
| POST | `/nodes/{node}/move` | `edit` |
| POST | `/nodes/{node}/copy` | `edit` (source) + `create` (destination) |

`move` and `copy` accept `{ parent_id: id \| null }` (null targets the storage root). Moving a node
into its own subtree is rejected with `422`; `copy` clones the node (and, for a folder, its subtree)
into the destination.

```ts
const client = useVaulterClient()

await client.nodes.move(nodeId, folderId)   // or move(nodeId, null) to un-file
const copy = await client.nodes.copy(nodeId, folderId)
```

For listing and folder operations backed by reactive state, use the
[`useNodes` composable](./vue.md#drive-composables).
