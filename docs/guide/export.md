# Export

Render a document's TipTap content to **HTML** or **Markdown** for download, email, or display
outside the editor. Exporting requires the **`view`** ability.

The renderer (`DocVault\Support\TipTapRenderer`) handles the common node and mark set —
headings, paragraphs, bullet/ordered lists, blockquotes, code blocks, horizontal rules, and the
bold / italic / strike / code / link marks — with HTML output escaped. Unknown nodes fall back to
rendering their children.

## API

| Method | URL | Query | Ability |
|---|---|---|---|
| GET | `/documents/{id}/export` | `format=html` (default) \| `markdown` | `view` |

Returns `{ data: { format, content, filename } }`. An unsupported format yields `422`.

## Front-end

```ts
import { useDocumentExport } from '@docvault/vue'

const { fetch, download, exporting } = useDocumentExport(documentId)

const { content } = await fetch('markdown') // get the rendered string
await download('html')                      // trigger a browser download
```

Or call the client directly:

```ts
import { createDocVaultClient } from '@docvault/vue'

const client = createDocVaultClient({ baseUrl: '/api/docvault' })
const exported = await client.documents.export(documentId, 'html')
// → { format: 'html', content: '<h1>…</h1>', filename: 'my-doc.html' }
```

::: tip PDF / DOCX
PDF and DOCX export are intentionally out of scope (they require heavy renderers). Convert the
HTML output with your tool of choice, or layer a dedicated exporter on top.
:::
