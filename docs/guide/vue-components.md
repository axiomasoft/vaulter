# Frontend (`@docvault/vue`)

`@docvault/vue` is a **TypeScript-first** Vue 3 package: a typed API client, composables, and
slot-driven components. It ships ES + UMD bundles and full `.d.ts` declarations.

## Install & register

```ts
// main.ts
import { DocVaultPlugin } from '@docvault/vue'

app.use(DocVaultPlugin, {
  baseUrl: '/api/docvault',
  csrfToken: document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content,
})
```

The plugin provides a `DocVaultClient` to the whole app and registers every component globally.

## Typed API client

Use it directly, or via `useDocVaultClient()` inside components:

```ts
import { createDocVaultClient } from '@docvault/vue'

const client = createDocVaultClient({ baseUrl: '/api/docvault' })

const documents = await client.documents.list({ workspace_type: 'App\\Project', workspace_id: 1 })
const doc = await client.documents.create({
  workspace_type: 'App\\Project',
  workspace_id: 1,
  type: 'richtext',
  title: 'Spec',
  content: { type: 'doc', content: [] },
})
```

Every method is fully typed against the backend resources (`DocVaultDocument`, `DocVaultVersion`,
`DocVaultFolder`, `DocVaultPermission`, `CollaborationConnection`). Failed requests throw a
`DocVaultApiError` carrying `status`, `message`, and validation `errors`.

## Composables

| Composable | Purpose |
|---|---|
| `useDocVaultClient()` | Resolve the injected client. |
| `useDocuments()` | Reactive workspace document CRUD (`documents`, `list`, `create`, `update`, `remove`). |
| `useDocVaultEditor(id, opts)` | Load a document, track unsaved changes, save (optional autosave) and snapshot. |
| `useCollaboration(id)` | Fetch the realtime connection descriptor (channel/room + token). |
| `usePermissions(abilities)` | Reactive flags (`canView`, `canEdit`, …) from `document.abilities`. |

```ts
import { usePermissions } from '@docvault/vue'

const { canEdit, canManage } = usePermissions(() => doc.value?.abilities ?? null)
```

## Components

| Component | Purpose |
|---|---|
| `DocVaultFileManager` | Loads & lists a workspace's documents; the main entry point. |
| `DocVaultEditor` | Loads a document, tracks dirty state, autosaves; TipTap surface in M3. |
| `DocVaultVersionHistory` | Lists versions and restores them. |
| `DocVaultPermissionsPanel` | Lists, grants and revokes document access. |
| `DocVaultBreadcrumb` | Folder navigation breadcrumbs. |

All components are **slot-driven** — they handle data loading via the client and let you control
the markup:

```vue
<DocVaultFileManager
  workspace-type="App\Models\Project"
  :workspace-id="project.id"
  @open-document="openEditor"
>
  <template #item="{ document }">
    <DocCard :document="document" :can-edit="document.abilities.edit" />
  </template>
</DocVaultFileManager>
```

::: tip Real-time editing
The components consume `abilities` to drive what the UI offers, and `useCollaboration` returns the
connection descriptor. Binding TipTap + Yjs (Reverb `y-websocket` / Hocuspocus) on top of that
descriptor lands in the collaboration milestone.
:::
