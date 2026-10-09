---
title: Realtime Collaboration
description: Realtime, conflict-free document editing in Vaulter with Yjs and an external Hocuspocus hub — the session handshake, the epoch fence that keeps a restore from being overwritten, server-side loading and seeding, read-only enforcement and the @vaulter/vue wiring.
outline: deep
---

# Realtime Collaboration

Vaulter edits **document nodes** (`kind = document`, content in the `v_documents` aggregate)
collaboratively in realtime. The conflict-free merge is powered by **Yjs** — the binary
CRDT state is the **source of truth**; the TipTap JSON is a projection of it. The transport
is an external **Hocuspocus** hub (`hocuspocus-server/`) behind the
[`CollaborationDriver`](#extending) contract.

Reverb for Yjs collaboration has been removed: `vaulter-documents.collaboration.driver =
reverb` now fails with an `InvalidConfigurationException`. Laravel broadcast channels still
carry comment events with their existing access checks.

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
    'max_state_bytes' => 16777216,
    'broadcast' => ['channel_prefix' => 'vaulter.documents'],
],
```

| Key | Env | Default | Purpose |
|---|---|---|---|
| `enabled` | — | `false` | Opt-in. Off: the routes are not mounted and `collaborationSession()` answers `409 collaboration_unavailable`. |
| `driver` | `VAULTER_COLLAB_DRIVER` | `hocuspocus` | The only transport. `reverb` is refused. |
| `broadcast.channel_prefix` | — | `vaulter.documents` | Room namespace: a room is `<prefix>.<driveId>.<nodeId>.<epoch>`. |
| `hub.url` | `VAULTER_COLLAB_URL` | `ws://localhost:1234` | The hub WebSocket URL the client connects to. |
| `hub.jwt_secret` | `VAULTER_COLLAB_SECRET` | *(unset)* | HS256 key Vaulter mints and verifies the room JWT with. Lives only on Laravel. |
| `hub.webhook_secret` | `VAULTER_COLLAB_WEBHOOK_SECRET` | *(unset)* | Key of the HMAC body signature of every hub call. Shared with the hub; must differ from `jwt_secret`. |
| `hub.token_ttl` | — | `3600` | Room JWT lifetime in seconds, between 1 and 3600 (anything else is an `InvalidConfigurationException`). Rights are re-checked on every hub call, so a token never outlives an hour. |
| `hub.timestamp_tolerance` | — | `300` | Accepted clock skew of a signed hub call; also the replay window. |
| `max_state_bytes` | — | `16777216` | Largest binary Yjs state one store may write (`413 document_state_too_large`). |

::: warning Collaboration is opt-in
Enable `vaulter-documents.collaboration.enabled`, require `firebase/php-jwt`, and set both
hub secrets — present and different. The replay cache of signed hub calls lives in the
application's `Cache` store; use a store shared by every instance (Redis, database) in
production, not `array` or `file`.
:::

## Generations: the epoch

Every document has a **collaboration epoch** (`v_documents.collab_epoch`). It moves on when
the working state is replaced from outside the room:

- `restore()` of a revision;
- `update()` of a collaborative document that already has a Yjs state (an API/PHP JSON
  replace — the Yjs state is dropped and the next room seeds from the new JSON).

The epoch is part of the room name, the token and every store. That is the **storage fence**:
a store carries the room's epoch and is compared with `collab_epoch` on the document row
locked `FOR UPDATE`; the restore moves the epoch under the same lock. A store of a room
opened before the restore therefore either commits before it (and is replaced by it) or is
refused with `409 collaboration_epoch_mismatch` — however late the hub's debounce fires, it
never lands on the restored document. Cancelling a debounce is not possible; the fence makes
it unnecessary.

The hub does not learn about a restore until its next store of that room (at most
`VAULTER_COLLAB_MAX_DEBOUNCE_MS` after the next edit). It then closes the room with close
code **4409** `epoch_changed`; clients open a new session and a **new** `Y.Doc`.

## The connect flow

```
Vue client ──POST /api/vaulter/nodes/{node}/collab/token {schema_version?}──▶ Laravel
            ◀──────────────── CollaborationSession (room, epoch, mode, token) ─┘
            │
            ▼
      ws to the hub, document name = room, token = room JWT
            │   onAuthenticate ──▶ POST collab/authenticate   (live mode)
            │   onLoadDocument ──▶ POST collab/load           (Yjs state, or JSON to seed once)
            │   onStoreDocument ─▶ POST collab/store          (behind the epoch fence)
```

1. The client calls `POST nodes/{node}/collab/token`, optionally with the `schema_version`
   its editor supports.
2. `DocumentOperations::collaborationSession($document, $clientSchemaVersion)` locates the
   node, authorizes `View` (a node the actor cannot see answers `404 node_not_found`) and
   decides the mode. The session is **read-write** only when all of these hold:
   - the actor holds `Edit`;
   - the document's type is installed and its content reads without loss in a schema this
     installation writes;
   - the client's schema version is absent or equals the type's;
   - the drive is active (an archived drive is read-only).

   Collaboration switched off, or a type that does not implement `SupportsCollaboration`,
   is `409 collaboration_unavailable`.
3. The driver mints the [session](#the-session-descriptor).

### The session descriptor

```json
{
  "driver": "hocuspocus",
  "room": "vaulter.documents.01JDRIVE.01JNODE.3",
  "node_id": "01JNODE",
  "drive_id": "01JDRIVE",
  "epoch": 3,
  "schema_version": 1,
  "mode": "rw",
  "token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...",
  "url": "wss://collab.example.com"
}
```

The token is an HS256 JWT with the claims `sub` (the user), `node`, `drive`, `tenant`,
`epoch`, `schema_version`, `mode` (`rw`|`ro`), `room`, `iat` and `exp`. The room repeats
drive, node and epoch, so a token is bound to one generation of one document.

## The hub protocol (server-to-server)

The hub endpoints carry no Laravel auth middleware — only `ResolveCollaborationCall`, which
requires **both**:

- the room JWT in `Authorization: Bearer` (signature and `exp` verified; the room must parse
  and agree with the `node`/`drive`/`epoch` claims);
- an HMAC-SHA256 signature of `"{timestamp}.{rawBody}"` keyed by `hub.webhook_secret` in
  `X-Vaulter-Collab-Timestamp` + `X-Vaulter-Collab-Signature`. A stale timestamp or a
  signature seen before is refused. The hub puts a random `nonce` in every body so two
  genuine calls with the same payload never share a signature.

Anything else is `401`. The shared secret is no longer an identity by itself.

Every call re-reads what the token was issued for: the node exists, is not in the trash,
belongs to the token's drive and tenant, the drive is active or archived, the room's epoch is
the document's current one, and the token subject's rights **now**. A revoked `Edit`
therefore takes effect on the hub's next call (instant disconnection on every instance is a
later feature).

| Endpoint | Hub hook | Body | Answers |
|---|---|---|---|
| `POST collab/authenticate` | `onAuthenticate` | `{ room }` | `{ allowed, mode, userId, epoch }`; `allowed:false` when the user lost `View`; `409 collaboration_epoch_mismatch` for a past generation. |
| `POST collab/load` | `onLoadDocument` | `{ room }` | `{ state, content, epoch, schemaVersion, stateVersion, type }` — `state` is the base64 Yjs working state, or `null` with the JSON `content` to seed from. |
| `POST collab/store` | `onStoreDocument` | `{ room, state, content, seed? }` | `{ changed, epoch, state_version, yjs_state_size }`. |

`collab/store` rules:

- a read-only token, a user without `Edit` now, an archived drive or a token for another
  schema version is `403`;
- the room's epoch is the fence: `409 collaboration_epoch_mismatch`;
- `seed: true` writes only while the document has no Yjs state: `409
  collaboration_seed_conflict` otherwise, so two hub instances never both seed;
- `content` is required for a type whose `projectFromState()` returns `null` (both built-in
  types): the hub sends `TiptapTransformer.fromYdoc(document, 'default')`. It is decoded by the
  type's codec with its size limit (`422 invalid_document_content`);
- a state above `max_state_bytes` is `413 document_state_too_large`;
- content this installation cannot save back keeps its `409 document_type_unavailable` /
  `document_content_degraded`, as an autosave does.

### Loading and seeding happen on the hub only

The hub's `onLoadDocument` applies the stored Yjs state. A document without one (new, or
just restored to a revision without state) is built from its JSON **once**, on the server,
with `@hocuspocus/transformer` and the hub's TipTap schema (`hocuspocus-server/src/schema.js`),
and stored with `seed: true` before any client sees it.

::: danger Never seed in the client
In collaborative mode do **not** call the editor's `setContent()` (or insert the JSON into
the `Y.Doc`). Two clients converting the same JSON create different Yjs insertions, and the
merge shows the content twice. `VaulterDocumentNode` passes `content: null` to the editor in
collaborative mode for this reason.
:::

### Read-only

Read-only is enforced twice: the hub marks a `ro` connection read-only (Hocuspocus drops its
updates) and never stores under a read-only context, and the backend refuses a store under a
`ro` token. Once the token expires the hub refuses every further message and closes the
connection with code `4001`; the client asks for a new session.

## Deploying `hocuspocus-server`

See [Hocuspocus hub](/guide/hocuspocus-server) for the environment contract, Docker and
health endpoints. On the Laravel side:

```dotenv
VAULTER_COLLAB_DRIVER=hocuspocus
VAULTER_COLLAB_URL=wss://collab.example.com
VAULTER_COLLAB_SECRET=<32+ char random>            # room-JWT signing key, Laravel only
VAULTER_COLLAB_WEBHOOK_SECRET=<another random>     # shared with the hub, signs every hub call
```

## Frontend wiring (`@vaulter/vue`)

`useNodeCollaboration` fetches the session, opens `@hocuspocus/provider` over a fresh
`Y.Doc` and handles the hub's terminal closes:

- **4409 `epoch_changed`** — the provider and the old `Y.Doc` are destroyed, a new session is
  fetched and `doc` is **replaced** by a new `Y.Doc`. Rebind the editor; never copy the old
  document's content over.
- **4001** (token expired) — a new session is fetched; the `Y.Doc` is kept while the room is
  the same generation.

```ts
import { watch } from 'vue'
import { useNodeCollaboration } from '@vaulter/vue'
import { Editor } from '@tiptap/vue-3'
import Collaboration from '@tiptap/extension-collaboration'

const collab = useNodeCollaboration(nodeId, { supportedSchemaVersion: 1 })
await collab.connect()

let editor = makeEditor()
// A restore replaced the room: rebuild the editor on the new document.
watch(collab.doc, () => { editor.destroy(); editor = makeEditor() })

function makeEditor() {
  return new Editor({
    editable: !collab.readOnly.value,
    extensions: [Collaboration.configure({ document: collab.doc.value, field: 'default' })],
  })
}

// on unmount
collab.disconnect()
```

| Member | Type | Notes |
|---|---|---|
| `doc` | `ShallowRef<Y.Doc>` | The document to bind; replaced on `epoch_changed`. |
| `session` | `Ref<NodeCollaborationSession \| null>` | The current session (room, epoch, mode, schema version). |
| `connecting` / `connected` | `Ref<boolean>` | Handshake in flight / live provider connection. |
| `readOnly` | `Ref<boolean>` | `mode: 'ro'`, or the session's schema is newer than `supportedSchemaVersion`. |
| `schemaOutdated` | `Ref<boolean>` | The document is in a newer schema than the editor supports — ask the user to update the application. |
| `collaborators` | `Ref<Collaborator[]>` | Remote participants from awareness. |
| `error` | `Ref<unknown>` | Any error from the session fetch. |
| `fetchSession()` / `connect()` / `disconnect()` | | Session only / session and provider / teardown (idempotent). |

### `VaulterDocumentNode`

When the resolved handler is collaborative (`capabilities.collaborative === true`) and
collaboration is enabled, `VaulterDocumentNode` opens a session via `useNodeCollaboration`
and hands the editor `collaborative: true`, `content: null`, `ydoc` (replaced after a
restore — rebind on change), presence and connection state. Otherwise it uses the autosave
path.

```vue
<VaulterDocumentNode
  :node-id="nodeId"
  :component-map="componentMap"
  :supported-schema-version="1"
  @collaborating="(readonly) => { /* a session opened */ }"
  @schema-outdated="(version) => { /* ask the user to update the application */ }"
/>
```

- `collaboration` (default `true`) — `false` forces the autosave path.
- `collaboration-providers` — `createHocuspocusProvider` override.
- `supported-schema-version` — sent with the session request; a newer document schema opens
  read-only and emits `schema-outdated`.
- If the session request fails (collaboration unavailable) it falls back to the autosave path.

## Extending

The transport is one seam: a `CollaborationDriverType` case and a `CollaborationDriver`
implementation. `issueSession($node, $epoch, $schemaVersion, $writable, $actorId)` only
encodes the decision `collaborationSession()` made; it never authorizes.

## See also

- [Hocuspocus hub](/guide/hocuspocus-server)
- [Errors](/guide/errors) — `collaboration_*` and `document_state_too_large`
- [ADR-0001 — Collaboration transport](/adr/0001-collaboration-transport)
- [Authorization](/guide/abilities) — how `View` / `Comment` / `Edit` map to read/write
