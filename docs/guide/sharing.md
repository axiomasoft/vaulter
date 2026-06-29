# Share Links

Share a document publicly via a secret token that grants a fixed access level (`view`,
`comment`, or `edit`) until an optional expiry — without the recipient being a registered
subject. Managing share links requires the **`manage`** ability; resolving a token is public.

## API

| Method | URL | Ability |
|---|---|---|
| GET | `/documents/{id}/shares` | `manage` |
| POST | `/documents/{id}/shares` | `manage` |
| DELETE | `/documents/{id}/shares/{shareId}` | `manage` |
| GET | `/shares/{token}` | public |

`POST` accepts `{ permission: 'view' \| 'comment' \| 'edit', expires_at?: ISO-8601 }` and returns
the share, including its `token`. The public `GET /shares/{token}` returns the document with
`abilities` derived from the share's level — and `404` if the token is unknown or expired.

## Front-end

```ts
import { useShares } from '@vaulter/vue'

const { shares, list, create, revoke } = useShares(documentId)

await list()
const link = await create({ permission: 'view', expires_at: '2026-12-31T23:59:59Z' })
const url = `https://app.example.com/shared/${link.token}`
```

Resolve a shared document on the public page:

```ts
import { createVaulterClient } from '@vaulter/vue'

const client = createVaulterClient({ baseUrl: '/api/vaulter' })
const shared = await client.shares.resolve(token) // → { title, content, abilities, share }
```

Or use the slot-driven `VaulterSharePanel`:

```vue
<VaulterSharePanel :document-id="documentId" @created="copyLink" />
```

## Events

| Event | When |
|---|---|
| `Vaulter\Events\ShareLinkCreated` | a share link is created |
| `Vaulter\Events\ShareLinkRevoked` | a share link is revoked |
