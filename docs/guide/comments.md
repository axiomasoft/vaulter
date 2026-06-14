# Comments

Documents support threaded, optionally anchored comments. Posting a comment requires the
**`comment`** ability from your `PermissionResolver`; resolving requires `comment`; deleting
requires `manage`. New comments are broadcast on the document's private channel
(`comment.added`) so open editors can show them live.

## API

| Method | URL | Ability |
|---|---|---|
| GET | `/documents/{id}/comments` | `view` |
| POST | `/documents/{id}/comments` | `comment` |
| POST | `/documents/{id}/comments/{commentId}/resolve` | `comment` |
| DELETE | `/documents/{id}/comments/{commentId}` | `manage` |

A comment carries an `author` (the authenticated user, polymorphic), a `body`, an optional
`anchor` (JSON describing the text range it pins to), a `parent_id` for threads, and a
`resolved_at` timestamp.

## Front-end

```ts
import { useComments } from '@docvault/vue'

const { comments, list, add, resolve } = useComments(documentId)

await list()
await add({ body: 'Needs a citation', anchor: { from: 10, to: 24 } })
```

Or drop in the slot-driven panel:

```vue
<DocVaultCommentsPanel :document-id="documentId" @added="scrollToBottom">
  <template #item="{ comment, resolve }">
    <CommentBubble :comment="comment" @resolve="resolve" />
  </template>
</DocVaultCommentsPanel>
```

## Events

| Event | Type | When |
|---|---|---|
| `DocVault\Events\CommentAdded` | broadcast (`comment.added`) | a comment is posted |
| `DocVault\Events\CommentResolved` | domain (after commit) | a comment is resolved |

Listen for these in your app to send notifications or mirror activity.
