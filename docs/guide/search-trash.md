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
