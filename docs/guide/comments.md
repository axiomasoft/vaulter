# Comments

Document **nodes** support threaded, optionally anchored comments. Reading requires the
**`view`** ability; posting requires **`comment`**; resolving or deleting requires being the
comment's **author** or holding **`manage`** on the node. Comments are gated on the `comments`
feature and are broadcast so open editors can show them live.

## API

| Method | URL | Ability |
|---|---|---|
| GET | `/nodes/{node}/comments` | `view` |
| POST | `/nodes/{node}/comments` | `comment` |
| POST | `/nodes/{node}/comments/{commentId}/resolve` | author or `manage` |
| DELETE | `/nodes/{node}/comments/{commentId}` | author or `manage` |

A comment carries an `author` (the authenticated user, polymorphic), a `body`, an optional
`anchor` (JSON describing the text range it pins to), a `parent_id` for threads, and a
`resolved_at` timestamp.

## Front-end

```ts
import { useComments } from '@vaulter/vue'

const { comments, list, add, resolve } = useComments(nodeId)

await list()
await add({ body: 'Needs a citation', anchor: { from: 10, to: 24 } })
```

Or drop in the slot-driven panel:

```vue
<VaulterCommentsPanel :node-id="nodeId" @added="scrollToBottom">
  <template #item="{ comment, resolve }">
    <CommentBubble :comment="comment" @resolve="resolve" />
  </template>
</VaulterCommentsPanel>
```

## Events

| Event | Type | When |
|---|---|---|
| `Vaulter\Documents\Events\Comments\NodeCommentAdded` | broadcast | a comment is posted |
| `Vaulter\Documents\Events\Comments\NodeCommentResolved` | domain (after commit) | a comment is resolved |
| `Vaulter\Documents\Events\Comments\NodeCommentDeleted` | domain (after commit) | a comment is deleted |

Listen for these in your app to send notifications or mirror activity.
