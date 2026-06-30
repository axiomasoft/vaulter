# Vaulter collaboration hub (Hocuspocus)

The Node service that powers Vaulter's realtime **document collaboration** (Yjs/TipTap).
It is designed to be consumed as an **external, reusable image** — one published image
(`ghcr.io/axioma-studio/vaulter-hocuspocus`) serves many projects. You **reference** it and
configure it entirely through environment variables; you never copy its source into your app.

> **This is the collaboration backend.** Laravel Reverb is a general broadcast bus
> (notifications, presence) and is **not** a collaboration transport — the `reverb`
> collaboration driver is deprecated. Collaborative editing runs on this hub.

> **📖 The authoritative docs live in [`docs/guide/hocuspocus-server.md`](../docs/guide/hocuspocus-server.md):**
> the full environment contract, security hardening (TLS/wss, Origin allowlist, throttle +
> reverse-proxy IP headers, maxPayload), multi-instance scaling, and graceful shutdown.
> This README is a quick reference only.

## How it fits

```
Vue client ──ws(token=room JWT, doc=room name)──▶  this hub
                                                     │
   onConnect ── Origin allowlist (CSWSH) ───────────┤
   onAuthenticate ─POST /collab/authenticate────────┤  (forwards the JWT)
   onStoreDocument ─POST /collab/store──────────────▶  Laravel (api/vaulter)
                                                        persists yjs_state (bytea)
```

1. The client calls `POST /api/vaulter/nodes/{node}/collab/token` on Laravel and gets a
   room JWT (`{room, sub, readOnly, exp}` HS256) plus the hub `url`.
2. It opens a WebSocket to this hub with that JWT as the **token** and the **room name**
   (`vaulter.documents.<nodeId>`) as the document name.
3. The hub validates the `Origin`, then `onAuthenticate` forwards the JWT + room to
   `POST {laravel}/api/vaulter/collab/authenticate`. `{allowed:false}` (or any non-2xx)
   refuses the socket; `readOnly` downgrades the connection so the server drops a viewer's edits.
4. `onStoreDocument` (debounced) base64-encodes the **binary** Yjs state and POSTs it to
   `POST {laravel}/api/vaulter/collab/store`, which persists it into
   `v_node_document_versions.yjs_state`. The Yjs binary is the source of truth.

The hub does **not** need the JWT signing secret — Laravel mints the token and the client
forwards it; the hub only relays it to the webhooks, which verify the signature themselves.

## Environment contract (quick reference)

All configuration is via environment variables (12-factor; no secrets baked into the image).
The hub **validates on startup and fails fast** when a required production value is missing or
a numeric value is malformed. The most common variables:

| Variable | Default | Purpose |
| --- | --- | --- |
| `VAULTER_BACKEND_URL` | `http://localhost` | Laravel base URL the hub calls back into. **Required in production.** |
| `VAULTER_COLLAB_ALLOWED_ORIGINS` | *(empty)* | CSWSH allowlist — exact browser origins. **Required in production** (`*` = dev only). |
| `VAULTER_COLLAB_WEBHOOK_SECRET` | *(unset)* | Optional shared secret (`X-Vaulter-Collab-Secret`); must match the Laravel config. |
| `VAULTER_COLLAB_PORT` / `_HEALTH_PORT` | `1234` / `1235` | WebSocket port / HTTP `GET /health` port. |
| `NODE_ENV` | `production` | `production` enforces the allowlist + backend URL. The published image defaults to it. |

**The full contract** — security (`MAX_PAYLOAD_BYTES`, `THROTTLE*`, `BAN_TIME`), liveness
(`TIMEOUT_MS`, `DEBOUNCE_MS`, `MAX_DEBOUNCE_MS`), scaling (`REDIS_URL`, `INSTANCE_NAME`),
webhook routing (`API_PREFIX`, `LARAVEL_WEBHOOK_URL`, `WEBHOOK_TIMEOUT_MS`) and ops
(`QUIET`, `LOG`) — is documented in [`docs/guide/hocuspocus-server.md`](../docs/guide/hocuspocus-server.md).

On the Laravel side, point the client at this hub with `VAULTER_COLLAB_DRIVER=hocuspocus`
and `VAULTER_COLLAB_URL=ws://<hub-host>:1234`.

## Run

```bash
npm install
npm start            # production
npm run dev          # watch
npm run check        # node --check on every source file (syntax gate)
npm test             # node:test unit suite (mocks fetch; no running stack)
```

## Docker

The reference [`docker-compose.yml`](./docker-compose.yml) pulls the published image (or
`build: .` for local dev) and shows the full security/scaling env surface. The multi-stage
[`Dockerfile`](./Dockerfile) runs as the non-root `node` user from a committed lockfile; the
image is built and pushed to GHCR by `.github/workflows/hocuspocus-image.yml` on a
`vaulter-hocuspocus-v*` tag (multi-arch, with SBOM + build-provenance attestation).

> **Security:** never expose the WebSocket port directly — bind it to loopback / your private
> network and front it with a TLS reverse proxy that **overwrites** `X-Real-IP` /
> `X-Forwarded-For` (the per-IP throttle trusts those headers). See the guide.

## Verification

- Syntax: `npm run check` (`node --check` per file).
- Unit: `npm test` — `node:test` covers the webhook seam (bearer/secret headers, base64
  `yjs_state`, allowed/readOnly parsing, fail-closed on non-2xx), the Origin allowlist + CSWSH
  policy, fail-fast config validation, and that the `maxPayload`/`perMessageDeflate` hardening
  actually reaches the underlying `ws` server — all with a mocked `fetch` / ephemeral ports.
- A true end-to-end realtime check (two browsers through a live hub + Laravel + Postgres) lives
  in the repo's `e2e/` stand (`make e2e-*`), not here.
