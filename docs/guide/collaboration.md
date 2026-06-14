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
3. `DocVaultEditor` connects and Yjs syncs changes between all clients in real time.
4. On save, content is written to the document and, when requested, snapshotted as a version.

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
The full Yjs runtime (presence/cursors, snapshot persistence, offline merge) lands in the
collaboration milestone. M1 ships the driver abstraction, channel authorization and token
issuance. See [ADR-0001](/adr/0001-collaboration-transport).
:::
