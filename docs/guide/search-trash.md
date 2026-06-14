# Search & Trash

## Search

The document list accepts a `q` term, matched (case-insensitively) against the title **and**
the raw content, scoped to the workspace and filtered by the `view` ability:

```
GET /api/docvault/documents?workspace_type=App\Models\Project&workspace_id=1&q=roadmap
```

```ts
import { useDocuments } from '@docvault/vue'

const { documents, list } = useDocuments()
await list({ workspace_type: 'App\\Models\\Project', workspace_id: 1, q: 'roadmap' })
```

::: tip Scaling search
The default search uses a `LIKE` scan — fine for typical workspaces. For large datasets, swap in
a real search index (e.g. Laravel Scout / Meilisearch) by pointing `config('docvault.models.document')`
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

A permanent delete dispatches `DocVault\Events\DocumentPurged` (distinct from the reversible
`DocumentDeleted`), so your app can treat hard deletes differently in audit logs.
