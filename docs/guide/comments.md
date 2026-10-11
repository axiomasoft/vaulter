# Comments

Document **nodes** support threaded, optionally anchored comments. Reading requires the
**`view`** ability; posting requires **`comment`**; resolving requires **`comment`** and being the
comment's **author** or holding **`edit`**; deleting requires being the **author** or holding
**`manage`** on the node. A node the user cannot see answers `404 node_not_found`. In PHP use
`Documents::comments($drive)` (`list`, `add`, `resolve`, `delete`) — see
[Document Nodes](./documents.md#the-php-api). Comments default to enabled at `vaulter-documents.comments.enabled` and are
broadcast so open editors can show them live.

## API

| Method | URL | Ability |
|---|---|---|
| GET | `/v1/nodes/{node}/comments` | `view` |
| POST | `/v1/nodes/{node}/comments` | `comment` |
| POST | `/v1/nodes/{node}/comments/{comment}/resolve` | `comment`, and author or `edit` |
| DELETE | `/v1/nodes/{node}/comments/{comment}` | author or `manage` |

The routes are always registered; the operations check the switch. With comments off (globally or
in the drive's profile) a node you can see answers `403 profile_restriction`, a node you cannot
see the usual `404 node_not_found`. The list is a cursor page (`?cursor=&per_page=`, oldest first),
`POST` answers `201` and `DELETE` `204`; a comment of another node is `404 comment_not_found`.

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
| `Vaulter\Documents\Events\Comments\CommentAdded` | broadcast | a comment is posted |
| `Vaulter\Documents\Events\Comments\CommentResolved` | domain (after commit) | a comment is resolved |
| `Vaulter\Documents\Events\Comments\CommentDeleted` | domain (after commit) | a comment is deleted |

Listen for these in your app to send notifications or mirror activity.
