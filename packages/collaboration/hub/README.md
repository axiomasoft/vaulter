# Vaulter collaboration hub (Hocuspocus)

The Node service that powers Vaulter's realtime **document collaboration** (Yjs/TipTap).
It is designed to be consumed as an **external, reusable image** — one published image
(`ghcr.io/axiomasoft/vaulter-collaboration-hub`) serves many projects. You **reference** it and
configure it entirely through environment variables; you never copy its source into your app.

> **This is the collaboration backend.** Laravel Reverb is a general broadcast bus
> (notifications, presence) and is **not** a collaboration transport — the `reverb`
> collaboration driver was removed. Collaborative editing runs on this hub.

> **📖 The authoritative docs live in [`docs/guide/hocuspocus-server.md`](../docs/guides/hub.md):**
> the full environment contract, security hardening (TLS/wss, Origin allowlist, throttle +
> reverse-proxy IP headers, maxPayload), multi-instance scaling, and graceful shutdown.
> This README is a quick reference only.

## How it fits

```
Vue client ──ws(token=room JWT, doc=room name)──▶  this hub
                                                     │
   onConnect ── Origin allowlist (CSWSH) ───────────┤
   onAuthenticate ─POST /v1/collaboration/hub/authenticate┤  every call: room JWT + HMAC signature
   onLoadDocument ─POST /v1/collaboration/hub/load────────┤
   onStoreDocument ─POST /v1/collaboration/hub/store───────▶  Laravel (api/vaulter), epoch fence
```

1. The client gets a session from `POST /api/vaulter/nodes/{node}/collab/token`: the room
   `<prefix>.<driveId>.<nodeId>.<epoch>`, the mode (`rw`|`ro`) and a room JWT.
2. It connects with the JWT as the **token** and the room as the document name.
3. `onAuthenticate` asks `collaboration/hub/authenticate`; `mode: ro` makes the connection read-only.
4. `onLoadDocument` loads the Yjs working state from `collaboration/hub/load`, or — for a document
   without one — builds the Y.Doc from its JSON **once** (`@hocuspocus/transformer` +
   `src/schema.js`) and stores it with `seed: true` and the `state_version` it loaded (a
   document that changed in between is refused with `409` and loaded again). **Clients never
   seed.** A seed that would lose anything (see the schema note below) is refused. In every
   deployment a read-only first connection to an unseeded document is refused until a writer has
   seeded it.
5. `onStoreDocument` (debounced) sends the binary state and its JSON projection to
   `collaboration/hub/store`. A `409` (the room's epoch is over after a restore, or a seed conflict) is
   terminal: the hub closes the room with **4409 `epoch_changed`**, never retries, and drops
   the room's later stores; clients reopen with a new Y.Doc. `404` closes with `4404`; `5xx`,
   `408`/`425`/`429` and network errors are retried. `401`, `403`, `413` and `422` are **not** a
   successful save: the room is closed with `4001` / `4403` / `4422` and the hook throws, so
   Hocuspocus keeps the unsaved state in memory (see the guide for the close codes).

The hub never holds the JWT signing secret and never logs tokens. Expired tokens are refused
before every message (close `4001`); a reconnect with an already expired token is refused at
authentication with the reason `Collaboration token expired`, which makes the client renew its
session.

**Document types and the TipTap schema.** `src/schema.js` mirrors the backend allow-list
(`test/schema-parity.test.js` compares it with `packages/documents/tests/Fixtures/tiptap-schema.json`).
The parity covers nodes, marks **and attributes**: whatever an extension here serializes
(`toJSON()` includes every declared attribute, `null`s too) must be on the backend allow-list.

Hosts that add TipTap nodes or attributes on the backend (`vaulter-documents.tiptap.extra_*`)
add the matching extensions here too. This is enforced fail-closed: ProseMirror silently drops
what its schema does not declare, so a document whose JSON the hub schema cannot carry whole
(say a `textAlign` attribute without the TextAlign extension) is **not seeded** — the hub
refuses with `Collaboration seed refused: … would drop paragraph.textAlign …` (names only,
never values) instead of saving a cut document back. Register the extension in `src/schema.js`
(`extensionsFor`) and the room opens.

## Environment contract (quick reference)

All configuration is via environment variables (12-factor; no secrets baked into the image).
The hub **validates on startup and fails fast** when a required production value is missing or
a numeric value is malformed. The most common variables:

| Variable | Default | Purpose |
| --- | --- | --- |
| `VAULTER_API_URL` | *(derived)* | Versioned API base the hub calls (`https://app/api/vaulter/v1`). **Must end with `/v1`**, otherwise the hub refuses to start. |
| `VAULTER_BACKEND_URL` | `http://localhost` | Laravel base URL the hub calls back into. **Required in production.** |
| `VAULTER_COLLAB_ALLOWED_ORIGINS` | *(empty)* | CSWSH allowlist — exact browser origins. **Required in production** (`*` = dev only). |
| `VAULTER_COLLAB_WEBHOOK_SECRET` | *(unset)* | Key of the HMAC signature of every backend call; must match the Laravel config. Required — without it the backend refuses every call. |
| `VAULTER_COLLAB_PORT` / `_HEALTH_PORT` | `1234` / `1235` | WebSocket port / HTTP `GET /health` port. |
| `NODE_ENV` | `production` | `production` enforces the allowlist + backend URL. The published image defaults to it. |

**The full contract** — security (`MAX_PAYLOAD_BYTES`, `THROTTLE*`, `BAN_TIME`), liveness
(`TIMEOUT_MS`, `DEBOUNCE_MS`, `MAX_DEBOUNCE_MS`), scaling (`REDIS_URL`, `INSTANCE_NAME`),
webhook routing (`API_PREFIX`, `LARAVEL_WEBHOOK_URL`, `WEBHOOK_TIMEOUT_MS`) and ops
(`QUIET`, `LOG`) — is documented in [`docs/guide/hocuspocus-server.md`](../docs/guides/hub.md).

On the Laravel side, point the client at this hub with `VAULTER_COLLAB_DRIVER=hocuspocus`
and `VAULTER_COLLAB_URL=ws://<hub-host>:1234`.

## Run

```bash
npm install
npm start            # production
npm run dev          # watch
npm run check        # node --check on every source file (syntax gate)
npm test             # node:test unit suite (stub backend; no running stack)
npm run test:e2e     # needs a running backend + hub — use ../scripts/collab-e2e.sh
```

## Docker

The reference [`docker-compose.yml`](./docker-compose.yml) pulls the published image (or
`build: .` for local dev) and shows the full security/scaling env surface. The multi-stage
[`Dockerfile`](./Dockerfile) runs as the non-root `node` user from a committed lockfile; the
image is built and pushed to GHCR by `.github/workflows/collaboration-hub-image.yml` on a
`vaulter-collaboration-hub-v*` tag (multi-arch, with SBOM + build-provenance attestation).

> **Security:** never expose the WebSocket port directly — bind it to loopback / your private
> network and front it with a TLS reverse proxy that **overwrites** `X-Real-IP` /
> `X-Forwarded-For` (the per-IP throttle trusts those headers). See the guide.

## Verification

- Syntax: `npm run check` (`node --check` per file).
- Unit: `npm test` — `node:test` covers the backend client (bearer JWT + body signature with a
  nonce, 409 as a terminal answer), server-side load and one-time seeding with seed conflicts,
  the epoch fence (4409, no retry, fenced rooms), read-only and token expiry, the TipTap schema
  parity with the backend, the Origin allowlist, config validation and the transport hardening.
- End to end: `bash scripts/collab-e2e.sh` from the repo root starts `vendor/bin/testbench serve`
  on a throwaway SQLite file and this hub, then runs `test/e2e/restore-epoch.e2e.js` (restore
  during a live session → 4409 → the reopened room shows the restored document; read-only and
  outdated-schema clients change nothing).
