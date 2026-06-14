# Vue Components

`@docvault/vue` ships ready-to-use Vue 3 components. Register them globally with the plugin
or import individually.

| Component | Purpose |
|---|---|
| `DocVaultFileManager` | Folder tree + document list/grid; the main entry point. |
| `DocVaultEditor` | TipTap editor wrapper with optional collaborative editing. |
| `DocVaultVersionHistory` | Side panel with the version history and diff preview. |
| `DocVaultPermissionsPanel` | Grant/revoke access to a document. |
| `DocVaultBreadcrumb` | Folder navigation breadcrumbs. |

## Global registration

```js
import { DocVaultPlugin } from '@docvault/vue'

app.use(DocVaultPlugin, { baseUrl: '/api/docvault' })
```

## Individual import

```js
import { DocVaultFileManager, DocVaultEditor } from '@docvault/vue'
```

> The components are currently skeletons (props and events are defined; rendering and data
> loading follow in the frontend milestone).
