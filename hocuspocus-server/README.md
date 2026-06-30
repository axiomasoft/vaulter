# Vaulter collaboration hub (Hocuspocus)

The optional Node service that fronts realtime document editing for Vaulter (M4b).
It is **only** needed when the collaboration driver is `hocuspocus`; the default
`reverb` driver needs no extra service.

## How it fits

```
Vue client ──ws(token=room JWT, doc=room name)──▶  this hub
                                                     │
   onAuthenticate ─POST /collab/authenticate────────┤  (forwards the JWT)
   onStoreDocument ─POST /collab/store──────────────▶  Laravel (api/vaulter)
                                                        persists yjs_state (bytea)
```

1. The client calls `POST /api/vaulter/nodes/{node}/collab/token` on Laravel and
   gets a room JWT (`{room, sub, readOnly, exp}` HS256) plus the hub `url`.
2. It opens a WebSocket to this hub with that JWT as the **token** and the
   **room name** (`vaulter.documents.<nodeId>`) as the document name.
3. `onAuthenticate` forwards the JWT + room to `POST {laravel}/api/vaulter/collab/authenticate`.
   `{allowed:false}` (or any non-2xx) refuses the socket; `readOnly` downgrades the
   connection so the server drops a viewer's edits.
4. `onStoreDocument` (debounced by the server) base64-encodes the **binary** Yjs state
   and POSTs it to `POST {laravel}/api/vaulter/collab/store`, which persists it into
   `v_node_document_versions.yjs_state`. The Yjs binary is the source of truth — the
   hub never reconstructs it from JSON.

The webhook calls authenticate with the connection's room-bound JWT (forwarded as
`Authorization: Bearer …`) and, when configured, the shared `X-Vaulter-Collab-Secret`
header. The Laravel endpoints are never open — a call with neither is 401'd.

## Environment contract

> `.env.example` in this repo is a protected file. The authoritative contract is here.
> Copy these into the service's environment (compose `environment:` / a real `.env`).

| Variable | Default | Purpose |
| --- | --- | --- |
| `VAULTER_COLLAB_PORT` | `1234` | WebSocket port the hub listens on (the client connects to `ws://host:PORT`). |
| `VAULTER_COLLAB_HEALTH_PORT` | `1235` | HTTP health port — `GET /health` → `200 {"status":"ok"}` for the compose healthcheck. |
| `VAULTER_BACKEND_URL` | `http://localhost` | Laravel app base URL the hub calls back into (no trailing slash). In Docker, the app service name or `host.docker.internal`. |
| `VAULTER_API_PREFIX` | `api/vaulter` | The Vaulter API route prefix. Webhooks resolve to `{VAULTER_BACKEND_URL}/{VAULTER_API_PREFIX}/collab/{authenticate,store}`. |
| `VAULTER_LARAVEL_WEBHOOK_URL` | *(derived)* | Optional explicit full webhook base (`…/api/vaulter`). Overrides the two values above (reverse proxy / split host). |
| `VAULTER_COLLAB_WEBHOOK_SECRET` | *(unset)* | Shared secret presented as `X-Vaulter-Collab-Secret`. **Must equal** the Laravel `vaulter.collaboration.webhook_secret` (`VAULTER_COLLAB_WEBHOOK_SECRET` on the Laravel side). When unset, the hub authenticates the webhooks with the forwarded room JWT alone. |
| `VAULTER_COLLAB_WEBHOOK_TIMEOUT_MS` | `10000` | Webhook round-trip timeout in ms. |

The hub does **not** need the JWT signing secret (`VAULTER_COLLAB_SECRET` on the
Laravel side, `vaulter.collaboration.drivers.hocuspocus.secret`). Laravel mints the
room JWT and the client forwards it; the hub only relays that token to the webhooks,
which verify the signature themselves.

On the Laravel side, point the client at this hub with
`VAULTER_COLLAB_DRIVER=hocuspocus` and `VAULTER_COLLAB_URL=ws://<hub-host>:1234`.

## Run

```bash
npm install
npm start            # production
npm run dev          # watch
npm run check        # node --check on every source file (syntax gate)
npm test             # node:test unit suite (mocks fetch; no running stack)
```

## Compose note

`docker-compose.yml` builds and runs the hub. Add a healthcheck against the health
port so dependents wait for readiness:

```yaml
services:
  vaulter-collab:
    build: .
    image: vaulter/hocuspocus-server:dev
    ports:
      - "${VAULTER_COLLAB_PORT:-1234}:1234"
    environment:
      VAULTER_COLLAB_PORT: 1234
      VAULTER_COLLAB_HEALTH_PORT: 1235
      VAULTER_BACKEND_URL: ${VAULTER_BACKEND_URL:-http://host.docker.internal}
      VAULTER_COLLAB_WEBHOOK_SECRET: ${VAULTER_COLLAB_WEBHOOK_SECRET:-}
    healthcheck:
      test: ["CMD", "node", "-e", "fetch('http://localhost:1235/health').then(r=>process.exit(r.ok?0:1)).catch(()=>process.exit(1))"]
      interval: 10s
      timeout: 3s
      retries: 3
    restart: unless-stopped
```

The published image is built/pushed by the release pipeline — building it is out of
scope for this slice.

## Verification

- Syntax: `npm run check` (`node --check` per file).
- Unit: `npm test` — `node:test` covers the webhook seam (bearer/secret headers,
  base64 yjs_state, allowed/readOnly parsing, fail-closed on non-2xx) and the webhook
  base URL derivation, all with a mocked `fetch`.
- **Needs a running stack (out of scope here):** a true end-to-end realtime check —
  two browsers editing through a live hub + Laravel + Postgres, the JWT actually
  verified, and the snapshot landing in `v_node_document_versions`. That requires the
  compose stack up and is documented, not executed, in this slice.
```
