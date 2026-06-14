# Templates

Author a document once, reuse it many times. A document whose `type` is **`template`** acts as a
blueprint: instantiating it clones the content into a brand-new document, replacing
`{{ placeholder }}` tokens with values you supply.

Creating a new document from a template requires the **`create`** ability on the target workspace —
the same authorization as creating any document. Reading the template itself requires **`view`**.

## Placeholders

Placeholders are written as `{{ name }}` inside any text of the template's TipTap content. Token
names may contain letters, digits, underscores, dots and hyphens. Surrounding whitespace is
ignored, so `{{name}}` and `{{ name }}` are equivalent.

```json
{
  "type": "doc",
  "content": [
    { "type": "paragraph", "content": [
      { "type": "text", "text": "Dear {{ name }}, welcome to {{ company }}." }
    ] }
  ]
}
```

Substitution (`DocVault\Support\TemplateVariableSubstitutor`) walks the document tree and replaces
tokens only inside text nodes. **Unknown placeholders are left intact** — a missing variable never
throws and never blanks out, so a partially-filled template still renders predictably.

## API

| Method | URL | Ability |
|---|---|---|
| POST | `/documents/{id}/instantiate` | `view` on the template, `create` on the target workspace |

Body (every field optional):

| Field | Type | Default |
|---|---|---|
| `variables` | `object<string,string>` | `{}` |
| `title` | `string` | the template's title |
| `type` | `richtext \| table \| markdown` | `richtext` |
| `workspace_type` | `string` | the template's workspace type |
| `workspace_id` | `string \| number` | the template's workspace id |
| `folder_id` | `string \| number \| null` | the template's folder |

The source document **must** be of type `template` (otherwise `422`) — use the
[`duplicate`](/guide/quick-start) endpoint to clone an ordinary document. The new document is a
normal, editable document: its type defaults to `richtext` and may be set to any editable type, but
**not** to `template` (instantiating never produces another template). The response is the created
document, wrapped in the usual `{ data: … }` envelope.

```http
POST /api/docvault/documents/42/instantiate
{
  "variables": { "name": "Ada", "company": "Axioma" },
  "title": "Letter to Ada"
}
```

## Front-end

```ts
import { useDocuments } from '@docvault/vue'

const { instantiate } = useDocuments()

const doc = await instantiate(templateId, {
  variables: { name: 'Ada', company: 'Axioma' },
  title: 'Letter to Ada',
})
```

The new document is appended to the reactive `documents` list. Or call the client directly:

```ts
import { createDocVaultClient } from '@docvault/vue'

const client = createDocVaultClient({ baseUrl: '/api/docvault' })
const doc = await client.documents.instantiate(templateId, {
  variables: { name: 'Ada' },
})
```

::: tip Building a template gallery
List templates with the standard document query filtered to `type: 'template'`, render their
titles, and call `instantiate` on selection. Collect variable values from a small form keyed by the
placeholder names you ship in the template.
:::
