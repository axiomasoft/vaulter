# Search & Trash

## Search

The document list accepts a `q` term, matched (case-insensitively) against the title **and**
the raw content, scoped to the workspace and filtered by the `view` ability:

```
GET /api/vaulter/documents?workspace_type=App\Models\Project&workspace_id=1&q=roadmap
```

```ts
import { useDocuments } from '@vaulter/vue'

const { documents, list } = useDocuments()
await list({ workspace_type: 'App\\Models\\Project', workspace_id: 1, q: 'roadmap' })
```

::: tip Scaling search
The default search uses a `LIKE` scan — fine for typical workspaces. For large datasets, swap in
a real search index (e.g. Laravel Scout / Meilisearch) by pointing `config('vaulter.models.document')`
at a searchable model and overriding the read repository.
:::

## Trash

Deleting a document soft-deletes it. The trash can be listed, restored, or permanently purged.

| Method | URL | Ability |
|---|---|---|
| GET | `/documents/trash` | `view` |
| POST | `/documents/{id}/restore` | `manage` |
| DELETE | `/documents/{id}/force` | `manage` |

```ts
const { trashed, listTrashed, forceDelete } = useDocuments()

await listTrashed({ workspace_type: 'App\\Models\\Project', workspace_id: 1 })
await forceDelete(documentId) // permanent — dispatches DocumentPurged
```

A permanent delete dispatches `Vaulter\Events\DocumentPurged` (distinct from the reversible
`DocumentDeleted`), so your app can treat hard deletes differently in audit logs.

## Move & duplicate

| Method | URL | Ability |
|---|---|---|
| PATCH | `/documents/{id}/move` | `edit` |
| POST | `/documents/{id}/duplicate` | `view` + `create` |

`move` accepts `{ folder_id: id \| null }` (null moves to the root); moving into a folder from a
different workspace is rejected with `422`, and a successful move dispatches `DocumentMoved`.
`duplicate` clones the document (title prefixed with `Copy of `) into the same workspace/folder and
dispatches `DocumentCreated`.

```ts
const { move, duplicate } = useDocuments()

await move(documentId, folderId)   // or move(documentId, null) to un-file
const copy = await duplicate(documentId)
```

## Bulk operations

`POST /documents/bulk` applies one action to many documents, authorizing each individually and
reporting which were processed vs skipped (denied / missing):

```
POST /api/vaulter/documents/bulk
{ "action": "move", "ids": [1, 2, 3], "folder_id": 9 }
→ { "data": { "action": "move", "processed": [1, 3], "skipped": [2] } }
```

Actions: `delete`, `restore`, `move` (with `folder_id`), `force-delete` — each gated by the same
ability as its single-document counterpart (`delete` / `manage` / `edit` / `manage`).

```ts
const { bulk } = useDocuments()
await bulk({ action: 'delete', ids: selectedIds })
```
