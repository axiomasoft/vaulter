# Collaboration

Real-time, conflict-free editing is powered by **Yjs** (CRDT). The transport is abstracted
behind the `CollaborationDriver` contract, with two implementations.

## Drivers

| Driver | Transport | When to use |
|---|---|---|
| **Reverb** (default) | Yjs over Laravel Reverb | Pure-Laravel apps — no extra service to deploy. |
| **Hocuspocus** | External Node Hocuspocus hub | When you want a dedicated collaboration server. |

Select one with `config('docvault.collaboration.driver')` (or `DOCVAULT_COLLAB_DRIVER`).

## How it works

1. The front-end requests a connection descriptor:
   `POST /api/docvault/documents/{id}/collab/token`.
2. The configured driver authorizes the user via your `PermissionResolver` (the `edit`
   action) and returns a `connectionConfig` — channel/room, transport URL, and a token.
3. The front-end opens a Yjs session over that descriptor and binds it to the editor.
4. On save, the merged content is persisted: `POST /api/docvault/documents/{id}/collab/sync`
   writes it to the document and broadcasts `content.synced` on the private channel so other
   clients refresh. Named snapshots go through the versions endpoint.

## Front-end session

`@docvault/vue` turns the descriptor into a Yjs session:

```ts
import { useCollaboration } from '@docvault/vue'
import { Editor } from '@tiptap/vue-3'
import Collaboration from '@tiptap/extension-collaboration'

const { doc, connect, disconnect } = useCollaboration(documentId)

// Hocuspocus works out of the box; for Reverb pass a `createReverbProvider`
// that bridges Laravel Echo to Yjs awareness.
await connect()

const editor = new Editor({
  extensions: [Collaboration.configure({ document: doc })],
})

// on unmount
disconnect()
```

## Persisting the merged state

```ts
import { useDocVaultClient } from '@docvault/vue'

const client = useDocVaultClient()
// after a debounced save, send the TipTap JSON derived from the Yjs doc:
await client /* POST .../collab/sync */
```

### Reverb

Returns a private channel `docvault.documents.{id}`. Authorization runs through the broadcast
channel registered by the package, which calls your resolver — no room token needed.

### Hocuspocus

Mints a short-lived JWT (`firebase/php-jwt`, signed with `DOCVAULT_COLLAB_SECRET`) for the
Node hub under `hocuspocus-server/`:

```bash
cd hocuspocus-server && cp .env.example .env && docker compose up -d
```

::: tip
Server-side persistence (`/collab/sync` + `content.synced` broadcast), token issuance, channel
authorization and the typed front-end session factory are in place. Presence/cursors and the
TipTap awareness binding for the Reverb transport are the remaining runtime pieces. See
[ADR-0001](/adr/0001-collaboration-transport).
:::
