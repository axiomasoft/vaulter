---
title: Realtime Collaboration
description: Realtime, conflict-free document editing in Vaulter with Yjs and an external Hocuspocus hub, the collab-token handshake, read-only-from-ability, deploying hocuspocus-server, and the @vaulter/vue wiring.
outline: deep
---

# Realtime Collaboration

Vaulter edits **document nodes** (`kind = document`, content on `v_node_document_versions`)
collaboratively in realtime. The conflict-free merge is powered by **Yjs** — the binary
CRDT state is the **source of truth**; the TipTap JSON is a derived projection. The
transport sits behind the [`CollaborationDriver`](#drivers) contract, with two
implementations you choose by config.

::: info This page covers the node-tree path (M4b)
This is the **Drive** collaboration surface — `POST nodes/{node}/collab/token` and the
[`CollaborationSession`](#the-session-descriptor) descriptor. The package also ships a
legacy document-centric lane (`documents/{id}/collab/token`, `CollaborationDriver`) bound
to the `v_documents` model; both coexist and a host picks the same transport for both via
`config('vaulter-documents.collaboration.driver')`. New integrations should use the node path
documented here. See [ADR-0001](/adr/0001-collaboration-transport).
:::

## The collaboration driver

Yjs is the source of truth; Hocuspocus provides the collaboration transport.
The default `hocuspocus` driver connects to an external Node hub
(`hocuspocus-server/`) with a signed room JWT and server-to-server webhooks.
`CollaborationDriverType` retains its `hocuspocus` case; unknown driver names
produce an explicit configuration error.

Reverb for Yjs collaboration has been removed. Use Hocuspocus for document editing.
Laravel broadcast channels still carry comment events with their existing access checks.

## Config

All keys live under `config('vaulter-documents.collaboration')`:

```php
// config/vaulter-documents.php
'collaboration' => [
    'enabled' => false,
    'driver' => 'hocuspocus',
    'hub' => [
        'url' => env('VAULTER_COLLAB_URL', 'ws://localhost:1234'),
        'jwt_secret' => env('VAULTER_COLLAB_SECRET'),
        'webhook_secret' => env('VAULTER_COLLAB_WEBHOOK_SECRET'),
        'token_ttl' => 3600,
        'timestamp_tolerance' => 300,
    ],
    'broadcast' => ['channel_prefix' => 'vaulter.documents'],
],
```

| Key | Env | Default | Purpose |
|---|---|---|---|
| `vaulter-documents.collaboration.driver` | `VAULTER_COLLAB_DRIVER` | `hocuspocus` | Active transport (`hocuspocus`). |
| `vaulter-documents.collaboration.broadcast.channel_prefix` | — | `vaulter.documents` | Room namespace. A room is **always** `channel_prefix.nodeId`. |
| `vaulter-documents.collaboration.hub.webhook_secret` | `VAULTER_COLLAB_WEBHOOK_SECRET` | *(unset)* | Shared secret the Hocuspocus hub presents on the webhooks. Required to sign webhook bodies when collaboration is enabled. |
| `vaulter-documents.collaboration.hub.url` | `VAULTER_COLLAB_URL` | `ws://localhost:1234` | The hub WebSocket URL the client connects to. |
| `vaulter-documents.collaboration.hub.jwt_secret` | `VAULTER_COLLAB_SECRET` | *(unset)* | HS256 key Vaulter mints the room JWT with **and** verifies an incoming JWT against. Required for the Hocuspocus path. |
| `vaulter-documents.collaboration.hub.token_ttl` | — | `3600` | Lifetime (seconds) of a minted room JWT. |

::: warning Collaboration is opt-in
Install Documents and enable `vaulter-documents.collaboration.enabled`.
Document editing and revisions are enabled by installing the package.
Both hub secrets must be present and distinct.
:::

## The connect flow

The client never holds a long-lived secret. It asks Laravel for a per-node session, then
opens the transport with what it gets back.

```
Vue client ──POST /api/vaulter/nodes/{node}/collab/token──▶ Laravel
                                                            │ AccessResolver:
                                                            │   ≥ View/Comment → connect (read-only)
                                                            │   Edit           → writable
            ◀────────── CollaborationSession ──────────────┘
            │
            ▼
  hocuspocus → open ws to hub url with the room JWT as the token
```

1. The client calls `POST nodes/{node}/collab/token`.
2. `CollaborationSessionService` resolves the actor's **highest** ability on the node
   through the Vaulter-owned `AccessResolver` (`Edit` > `Comment` > `View`). An actor with
   **no** view access gets a `403` before a token is ever minted.
3. `read_only` is **encoded from the ability** — `View`/`Comment` → `true`, `Edit` →
   `false`. The client cannot request write; it is granted or it is not.
4. The configured driver mints the [session descriptor](#the-session-descriptor) and the
   client opens the transport with it.

::: tip Read-only is derived, never requested
A token never over-grants relative to the actor's ability. The `read_only` flag rides the
session, and the Hocuspocus hub drops a read-only connection's document updates server-side.
:::

### The session descriptor

The driver returns (`Vaulter\Documents\Data\Collaboration\CollaborationSession::toArray()`):

```json
{
  "driver": "hocuspocus",
  "room": "vaulter.documents.01J...",
  "node_id": "01J...",
  "read_only": false,
  "token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...",
  "url": "ws://collab.example.com:1234",
  "connection": null
}
```

| Field | Hocuspocus |
|---|---|
| `driver` | `hocuspocus` |
| `room` | `channel_prefix.nodeId`, carried in the JWT `room` claim |
| `node_id` | The document node id |
| `read_only` | Derived from the actor's ability |
| `token` | Signed HS256 JWT `{room, sub, readOnly, iat, exp}` |
| `url` | The hub WebSocket URL |
| `connection` | `null`, retained for JSON compatibility |

A session is bound to exactly one node: its signed room claim is always
`channel_prefix.nodeId`.

## Hocuspocus (external Node hub)

With `driver = hocuspocus` the client connects to the hub's WebSocket URL presenting the
**room JWT** as the connection token. The hub does not trust the JWT on its own — it calls
**back** into Laravel:

- **`onAuthenticate`** → `POST {laravel}/api/vaulter/collab/authenticate` — Laravel
  resolves the user's ability on the node and answers `{allowed, readOnly, user}`.
- **`onStoreDocument`** (debounced by the hub) → `POST {laravel}/api/vaulter/collab/store`
  — the base64 binary Yjs state is persisted into `v_node_document_versions.yjs_state`.

Vaulter mints the JWT with `firebase/php-jwt` (HS256, signed with `VAULTER_COLLAB_SECRET`).

```bash
composer require firebase/php-jwt
```

If the package is missing or `drivers.hocuspocus.secret` is unset, the driver throws a
`CollaborationConfigException` at token time rather than minting an unverifiable token.

### The webhook contract (server-to-server)

The `collab/authenticate` and `collab/store` routes carry **no** Laravel auth middleware —
only `ResolveCollaborationCall`, which authenticates the hub by **either** of:

- the shared webhook secret in the **`X-Vaulter-Collab-Secret`** header (compared
  timing-safely against `vaulter-documents.collaboration.hub.webhook_secret`), **or**
- a valid signed room JWT in the **`Authorization: Bearer`** header (signature + `exp`
  verified by `firebase/php-jwt`).

These endpoints are **never open**: a call with neither a valid secret nor a valid JWT is
`401`'d, and when no verifier is configured at all every call is rejected.

Node binding is enforced on every call: a **JWT** call may only address the room it was
minted for (a token for node A cannot store to node B), and a **read-only** JWT call may
not drive a store. A shared-secret call is trusted server-to-server and may address any
room and write.

| Endpoint | Hub hook | Body | Answers |
|---|---|---|---|
| `POST collab/authenticate` | `onAuthenticate` | `{ room, node_id }` | `{ allowed, readOnly, user }` — `allowed:false` (not a 403) when the user holds no view access, so the hub refuses the socket cleanly. |
| `POST collab/store` | `onStoreDocument` | `{ room, node_id, yjs_state (base64), user_id? }` | `{ node_id, version_no, yjs_state_size }` |

The Yjs binary is the source of truth — `collab/store` rejects a missing/empty/invalid
`yjs_state` with a `422` and never reconstructs it from the TipTap JSON. A `content` /
`tiptap` body field, when present, updates the projection.

### Deploying `hocuspocus-server`

The hub lives under `hocuspocus-server/` (`@hocuspocus/server` + `yjs`). It needs the
backend URL to call the webhooks; it does **not** need the JWT signing secret (Laravel
mints the token and the client forwards it; the hub only relays it to the webhooks, which
verify it).

::: code-group

```bash [Run directly]
cd hocuspocus-server
npm install
npm start            # production
npm run dev          # watch
```

```yaml [docker-compose.yml]
services:
  vaulter-collab:
    build: ./hocuspocus-server
    image: vaulter/hocuspocus-server:dev
    ports:
      - "${VAULTER_COLLAB_PORT:-1234}:1234"
    environment:
      VAULTER_COLLAB_PORT: 1234
      VAULTER_COLLAB_HEALTH_PORT: 1235
      VAULTER_BACKEND_URL: ${VAULTER_BACKEND_URL:-http://host.docker.internal}
      VAULTER_API_PREFIX: ${VAULTER_API_PREFIX:-api/vaulter}
      # MUST equal the Laravel vaulter-documents.collaboration.hub.webhook_secret.
      VAULTER_COLLAB_WEBHOOK_SECRET: ${VAULTER_COLLAB_WEBHOOK_SECRET:-}
    healthcheck:
      test: ["CMD", "node", "-e", "fetch('http://localhost:1235/health').then(r=>process.exit(r.ok?0:1)).catch(()=>process.exit(1))"]
      interval: 10s
      timeout: 3s
      retries: 3
    restart: unless-stopped
```

```dockerfile [Dockerfile]
FROM node:22-alpine
WORKDIR /app
COPY package.json ./
RUN npm install --omit=dev
COPY src ./src
ENV VAULTER_COLLAB_PORT=1234
ENV VAULTER_COLLAB_HEALTH_PORT=1235
EXPOSE 1234 1235
HEALTHCHECK --interval=10s --timeout=3s --retries=3 \
  CMD node -e "fetch('http://localhost:'+(process.env.VAULTER_COLLAB_HEALTH_PORT||1235)+'/health').then(r=>process.exit(r.ok?0:1)).catch(()=>process.exit(1))"
CMD ["node", "src/index.js"]
```

:::

The hub serves a tiny health endpoint on a **separate** port (so it never interferes with
the WebSocket upgrade on the main port): `GET /health` → `200 {"status":"ok"}`. Point a
compose/orchestrator healthcheck at it before letting dependents start.

#### Environment contract

| Variable | Default | Purpose |
|---|---|---|
| `VAULTER_COLLAB_PORT` | `1234` | WebSocket port the hub listens on (the client connects to `ws://host:PORT`). |
| `VAULTER_COLLAB_HEALTH_PORT` | `1235` | HTTP health port — `GET /health`. |
| `VAULTER_BACKEND_URL` | `http://localhost` | Laravel app base URL the hub calls back into (no trailing slash). In Docker, the app service name or `host.docker.internal`. |
| `VAULTER_API_PREFIX` | `api/vaulter` | The Vaulter API route prefix. Webhooks resolve to `{VAULTER_BACKEND_URL}/{VAULTER_API_PREFIX}/collab/{authenticate,store}`. |
| `VAULTER_LARAVEL_WEBHOOK_URL` | *(derived)* | Optional explicit full webhook base; overrides the two above (reverse proxy / split host). |
| `VAULTER_COLLAB_WEBHOOK_SECRET` | *(unset)* | Shared secret presented as `X-Vaulter-Collab-Secret`. **Must equal** the Laravel `vaulter-documents.collaboration.hub.webhook_secret`. When unset, the hub authenticates the webhooks with the forwarded room JWT alone. |
| `VAULTER_COLLAB_WEBHOOK_TIMEOUT_MS` | `10000` | Webhook round-trip timeout in ms. |

::: danger Keep the secrets aligned
`VAULTER_COLLAB_WEBHOOK_SECRET` on the **hub** must equal `vaulter-documents.collaboration.hub.webhook_secret`
on **Laravel** (same env name on both sides). The hub's JWT signing secret
(`VAULTER_COLLAB_SECRET`) lives **only** on Laravel — the hub never sees it.
:::

On the Laravel side, point the client at the hub:

```dotenv
VAULTER_COLLAB_DRIVER=hocuspocus
VAULTER_COLLAB_URL=ws://collab.example.com:1234
VAULTER_COLLAB_SECRET=<32+ char random>            # HS256 room-JWT signing key
VAULTER_COLLAB_WEBHOOK_SECRET=<another random>     # shared with the hub
```

## Frontend wiring (`@vaulter/vue`)

The `useNodeCollaboration` composable does the full handshake: it fetches the room token
from the node collab endpoint, then builds a Yjs session over it — the Hocuspocus
transport via `@hocuspocus/provider`.

```ts
import { useNodeCollaboration } from '@vaulter/vue'
import { Editor } from '@tiptap/vue-3'
import Collaboration from '@tiptap/extension-collaboration'

const collab = useNodeCollaboration(nodeId)

// fetch the token AND open the Yjs session
await collab.connect()

const editor = new Editor({
  editable: !collab.readOnly.value,
  extensions: [Collaboration.configure({ document: collab.doc })],
})

// on unmount
collab.disconnect()
```

The composable returns a stable reactive surface:

| Member | Type | Notes |
|---|---|---|
| `doc` | `Y.Doc` | The shared document to bind to TipTap's `Collaboration` extension. |
| `session` | `Ref<NodeCollaborationSession \| null>` | The resolved backend descriptor (null until connected). |
| `connecting` | `Ref<boolean>` | True while the token handshake is in flight. |
| `connected` | `Ref<boolean>` | Live connection flag from the provider. |
| `readOnly` | `Ref<boolean>` | Mirrors the backend ability — mount a read-only editor when `true`. |
| `collaborators` | `Ref<Collaborator[]>` | Remote participants from awareness; empty when the transport has no presence. |
| `error` | `Ref<unknown>` | Any error from the token fetch / session build. |
| `fetchSession()` | `() => Promise<NodeCollaborationSession>` | Token only — for callers building their own provider. |
| `connect()` | `() => Promise<...>` | Token **and** open the Yjs session. |
| `disconnect()` | `() => void` | Tear down (idempotent). |

### `VaulterDocumentNode`

`VaulterDocumentNode` wires this automatically. When the resolved handler declares the
**collaborative** capability (`capabilities.collaborative === true`) and collaboration is
enabled, it opens a Yjs session via `useNodeCollaboration`, binds the shared doc, and
forwards presence/connection state. Otherwise it uses the non-collab autosave path.

```vue
<script setup lang="ts">
import { VaulterDocumentNode } from '@vaulter/vue'
</script>

<template>
  <VaulterDocumentNode
    :node-id="nodeId"
    :component-map="componentMap"
    :collaboration="true"
    @collaborating="(readonly) => { /* a Yjs session opened */ }"
  />
</template>
```

- `collaboration` (default `true`) — set `false` to force the autosave path even for a
  collaborative handler.
- `collaboration-providers` — forwarded to `useNodeCollaboration`; supply
  `createHocuspocusProvider` to override the default Hocuspocus provider.
- It emits `collaborating(readonly: boolean)` once a Yjs session opens. If the token
  handshake fails (collab unavailable / not configured) it **falls back** to the autosave
  path rather than blocking the editor.

Read-only is enforced end to end: `isReadonly` is `true` when the `readonly` prop is set
**or** the session is read-only, and in collaborative mode the Yjs session drives
persistence so the client autosave is inert.

## Extending

The transport is a single seam. To add one, add a `CollaborationDriverType` case and a
`CollaborationDriver` implementation — the manager resolves it by enum, no
stringly-typed lookup. The driver's only job is to encode the AccessResolver's decision
(the `Ability`) into the session descriptor's `read_only` flag (and, for a JWT transport,
the room claim); it never makes the authorization decision itself.

## See also

- [ADR-0001 — Collaboration transport](/adr/0001-collaboration-transport)
- [Authorization](/guide/abilities) — how `View` / `Comment` / `Edit` map to read/write
- [Configuration](/guide/configuration) — the full config key walkthrough
