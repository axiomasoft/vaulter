# Collaboration

Real-time, conflict-free editing is powered by **Yjs** (CRDT) over a **Hocuspocus**
WebSocket server.

## How it works

1. The frontend requests a JWT: `POST /api/docvault/documents/{id}/collab/token`.
2. `DocVaultEditor` connects to the Hocuspocus server with that token.
3. The server validates the token against a Laravel webhook.
4. Yjs syncs changes between all connected clients in real time.
5. On save (debounced or manual), the content is written to `dv_documents.content` and,
   when requested, to `dv_document_versions`.

## Running the server

The package includes a Node.js Hocuspocus service under `hocuspocus-server/` with a
`Dockerfile` and `docker-compose.yml`:

```bash
cd hocuspocus-server
cp .env.example .env
docker compose up -d
```

::: tip Transport decision
The standalone Hocuspocus server is the default. In some host apps (e.g. FlexCRM) the same
Yjs documents can instead ride on **Laravel Reverb** + `y-websocket`. The transport choice is
an open architecture decision for the collaboration milestone.
:::
