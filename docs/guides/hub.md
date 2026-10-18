# Hocuspocus Server (Docker)

The optional Node service that powers Vaulter's realtime **document collaboration**
(Yjs/TipTap). It is the recommended — and TipTap's own — collaboration backend.

> **Reverb is not a collaboration transport.** Laravel Reverb is a general broadcast bus
> (notifications, presence, activity). Collaborative editing runs on Hocuspocus; the
> `reverb` collaboration driver has been removed; use Hocuspocus. See [Realtime Collaboration](./collaboration).

It is designed to be consumed as an **external, reusable image** — one published image
serves many projects. You **reference** it, configure it entirely through environment
variables, and never copy its source into your app:

```yaml
services:
  vaulter-collab:
    image: ghcr.io/axiomasoft/vaulter-hocuspocus:1   # pin @sha256:… in production
    ports: ["127.0.0.1:1234:1234"]   # loopback only — front it with a TLS reverse proxy
    environment:
      VAULTER_BACKEND_URL: "https://app.example.com"
      VAULTER_COLLAB_ALLOWED_ORIGINS: "https://app.example.com"
    restart: unless-stopped
```

The image is **backend-agnostic**: it authenticates and persists by calling back into
*your* Laravel app's webhooks, so the same image works across every project that embeds
Vaulter.

## How it fits

```
 browser (writer/viewer) ──ws(token = room JWT, doc = room name)──▶  hub
        │                                  onConnect → Origin allowlist (CSWSH)
        │                                  onAuthenticate ─POST /v1/collaboration/hub/authenticate┐
        │                                  onLoadDocument ─POST /v1/collaboration/hub/load────────┤
        │                                  onStoreDocument ─POST /v1/collaboration/hub/store───────┤
        └──── POST /api/vaulter/nodes/{node}/collab/token ──▶ Laravel ◀──────────────┘
                                                 working state + epoch fence (v_documents)
```

1. The client calls `POST /api/vaulter/nodes/{node}/collab/token` and gets a session: the
   room `<prefix>.<driveId>.<nodeId>.<epoch>`, the mode (`rw`|`ro`) and a room JWT.
2. It opens a WebSocket to the hub with the JWT as the **token** and the room as the
   document name.
3. The hub validates the `Origin`, then asks `collaboration/hub/authenticate`. A refusal closes the
   socket; `mode: ro` makes the connection read-only (Hocuspocus drops its updates).
4. `onLoadDocument` asks `collaboration/hub/load`: the binary Yjs working state, or — for a document
   without one — its JSON. The hub builds the Y.Doc from the JSON **once** with
   `@hocuspocus/transformer` and `src/schema.js` and stores it with `seed: true` and the
   `state_version` it loaded (a document updated in between answers `409
   collaboration_seed_conflict` and is loaded again). Clients never seed. A seed that would
   lose anything is refused (see the schema section), and with Redis a read-only first
   connection to a document without Yjs state is refused until a writer has seeded it.
5. `onStoreDocument` (debounced) sends the binary state and its JSON projection to
   `collaboration/hub/store`. The backend fences stores by the room's epoch: after a restore, the old
   room's store answers `409 collaboration_epoch_mismatch`. The hub does **not** retry then:
   it closes the room's connections with close code **4409** `epoch_changed` and drops every
   later store of that room; clients reopen the document with a new Y.Doc. A `404` (document
   removed) closes the room with `4404`; network errors, `5xx` and `408`/`425`/`429` are retried.
   Every other refusal is **not** a successful save: `401` closes the room with `4001`, `403`
   with `4403` `forbidden`, `413`/`422` with `4422` `store_refused`, and the hook throws so
   Hocuspocus keeps the unsaved state in memory instead of treating it as persisted. The
   close codes and what the client does with each are in
   [Collaboration](/guides/collaboration#when-the-backend-refuses-a-store).
6. Every call is signed (HMAC of the exact body with `VAULTER_COLLAB_WEBHOOK_SECRET`, with a
   timestamp and a random nonce) and carries the room JWT; the backend requires both.

The hub does **not** need the JWT signing secret — Laravel mints the token and verifies it on
every call. The hub never logs tokens. Once a token expires the hub refuses its connection's
messages and closes it with code `4001`. A reconnect with a token that has already expired is
refused at authentication with the reason `Collaboration token expired`, so the client renews
its session.

### The TipTap schema of the hub

`src/schema.js` lists the TipTap extensions the hub seeds and projects with: StarterKit,
Link, Image and Table for `vaulter/rich-text`; the Markdown subset for `vaulter/markdown`.
It must match the backend allow-list — `test/schema-parity.test.js` compares it with
`packages/documents/tests/Fixtures/tiptap-schema.json` for nodes, marks **and attributes**
(what an extension serializes, `null`s included, must be allowed by the backend). A host that
adds nodes or attributes through `vaulter-documents.tiptap.extra_*` adds the matching
extensions to `src/schema.js` too.

This is enforced fail-closed. ProseMirror silently drops whatever its schema does not declare,
so the hub checks every seed: if the schema would lose anything from the document's JSON (an
allowed `textAlign` attribute without the TextAlign extension, say) the seed is **refused**
with `Collaboration seed refused: … would drop paragraph.textAlign …` — attribute names only,
never values — and the room does not open, instead of the cut document being saved back.
Register the extension in `extensionsFor` and the room opens. A document type the hub has no schema for cannot be seeded (its connection is
refused while it has no Yjs state).

## Environment contract

All configuration is via environment variables (12-factor; no secrets are baked into the
image). The hub **validates on startup and fails fast** if required production values are
missing.

### Connectivity

| Variable | Default | Purpose |
| --- | --- | --- |
| `VAULTER_COLLAB_PORT` | `1234` | WebSocket port clients connect to (`ws(s)://host:PORT`). |
| `VAULTER_COLLAB_HEALTH_PORT` | `1235` | HTTP health port — `GET /health` → `200`. Separate from the WS port. |
| `VAULTER_API_URL` | *(derived)* | Versioned API base the hub calls, e.g. `https://app.example.com/api/vaulter/v1`. **Must end with `/v1`**: a base without it stops the hub at start. |
| `VAULTER_BACKEND_URL` | `http://localhost` | Your Laravel app base URL the hub calls back into. **Required in production.** |
| `VAULTER_API_PREFIX` | `api/vaulter` | The Vaulter route prefix. Calls resolve to `{BACKEND}/{PREFIX}/v1/collaboration/hub/{authenticate,load,store}`. |
| `VAULTER_LARAVEL_WEBHOOK_URL` | *(derived)* | Explicit full base (…/v1); overrides all the above (reverse proxy / split host). |
| `VAULTER_COLLAB_WEBHOOK_SECRET` | *(unset)* | Key of the HMAC signature of every backend call. Must equal the Laravel `vaulter-documents.collaboration.hub.webhook_secret`; the secret itself is never sent. Required: without it every call is refused. |
| `VAULTER_COLLAB_WEBHOOK_TIMEOUT_MS` | `10000` | Webhook round-trip timeout. |

### Security

| Variable | Default | Purpose |
| --- | --- | --- |
| `VAULTER_COLLAB_ALLOWED_ORIGINS` | *(empty)* | **CSWSH defence.** Comma-separated list of EXACT browser origins allowed to connect. **Required in production** (the hub refuses to boot without it). `*` allows any (dev only). A non-browser client (no `Origin`) is always allowed — it is not a CSWSH vector and is still gated by the auth webhook. |
| `VAULTER_COLLAB_MAX_PAYLOAD_BYTES` | `16778240` (`MAX_DOCUMENT_BYTES` + 1 KiB) | Max WebSocket frame size (the `ws` default of 100 MiB is never used). It must hold the largest message the limits permit — a sync step 2 of a whole document (`VAULTER_COLLAB_MAX_DOCUMENT_BYTES`) or the longest envelope — so it defaults to exactly that, and a smaller value stops the hub at start. |
| `VAULTER_COLLAB_THROTTLE` | `15` | Per-IP connection attempts allowed before a temporary ban. |
| `VAULTER_COLLAB_THROTTLE_SECONDS` | `60` | Window (seconds) the attempts are counted over. |
| `VAULTER_COLLAB_BAN_TIME` | `5` | Ban duration (minutes) after the throttle is exceeded. |

### Liveness / persistence pressure

| Variable | Default | Purpose |
| --- | --- | --- |
| `VAULTER_COLLAB_TIMEOUT_MS` | `30000` | Dead-peer heartbeat interval. Align with your load-balancer/proxy idle timeout. |
| `VAULTER_COLLAB_DEBOUNCE_MS` | `5000` | `onStoreDocument` debounce — higher = fewer persistence writes during active editing. |
| `VAULTER_COLLAB_MAX_DEBOUNCE_MS` | `30000` | Hard cap before a forced store (bounds the worst-case unsaved window, and how long an open room takes to learn about a restore). |

### Scaling / ops

| Variable | Default | Purpose |
| --- | --- | --- |
| `VAULTER_COLLAB_REDIS_URL` | *(unset)* | When set, enables the Redis extension for **multi-instance** document + awareness sync. Accepts a full `redis://`/`rediss://` URL (auth, TLS, db). |
| `VAULTER_COLLAB_INSTANCE_NAME` | `vaulter-hocuspocus` | Unique name per instance (log correlation; required for Redis fan-out). |
| `VAULTER_COLLAB_QUIET` | `true` | Suppress the startup banner. |
| `VAULTER_COLLAB_LOG` | `false` | Enable the Logger extension (verbose; off at scale). |
| `NODE_ENV` | `production` | `production` tightens validation (origin allowlist + backend URL required). The published image defaults to `production`; set `development` for a local open-origin stand. |

## Security in production

- **Terminate TLS at a reverse proxy and serve only `wss://`.** The hub speaks `ws`; put
  nginx/Traefik in front, forward the WebSocket upgrade, and block plaintext. Keep the
  proxy's read timeout longer than `VAULTER_COLLAB_TIMEOUT_MS` so it doesn't sever healthy
  sockets, but finite.
- **Set `VAULTER_COLLAB_ALLOWED_ORIGINS`** to your exact app origins. Never use `*` in
  production. (The hub won't boot in `production` without it.)
- **Keep `MAX_PAYLOAD` at what the limits need** — the frame cap blunts oversized-message DoS,
  but it must carry a sync step 2 of a whole document; to lower it, lower
  `VAULTER_COLLAB_MAX_DOCUMENT_BYTES` with it. (The hub applies this cap to the underlying `ws`
  server; the library default of ~100 MiB is never used.)
- The **room JWT is short-lived** (`token_ttl` ≤ 3600) and bound to one generation of one
  document; every backend call re-checks access, so a viewer can never write (the hub drops
  their updates, the backend refuses a read-only store).
- **Never expose the WebSocket port (1234) directly to the internet.** Bind it to the host
  loopback / your private network and reach it only through the reverse proxy. The reference
  `docker-compose.yml` publishes it on `127.0.0.1` for exactly this reason.
- **The per-IP throttle trusts proxy headers — the proxy MUST set them.** The Throttle
  extension derives the client IP from `X-Real-IP` / `X-Forwarded-For`, falling back to the
  socket address. A client that reaches the hub directly can forge those headers and bypass
  the handshake-flood ban entirely. So the reverse proxy must **overwrite** (not append) both
  headers with the real client address, and the hub must not be reachable except through it.

Minimal nginx upgrade block (note the IP headers — they are load-bearing for the throttle):

```nginx
location /collab/ {
    proxy_pass http://vaulter-collab:1234/;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header Host $host;
    proxy_set_header X-Forwarded-Proto $scheme;
    # OVERWRITE the client IP so a forged X-Real-IP / X-Forwarded-For can't defeat the throttle.
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_read_timeout 75s;   # > the hub heartbeat, but finite
}
```

Traefik does this for you: `forwardedHeaders` is honoured only from `trustedIPs`, so leaving
the default (trust nothing) makes Traefik rewrite `X-Forwarded-For`/`X-Real-IP` from the real
connection. Behind any proxy, restrict `trustedIPs`/`set_real_ip_from` to the proxy itself and
keep the hub off any public interface.

## Scaling (multi-instance)

Run several instances behind a load balancer and set `VAULTER_COLLAB_REDIS_URL` so clients
connected to different instances converge on the same document via Redis pub/sub. Give each
instance a unique `VAULTER_COLLAB_INSTANCE_NAME`.

> Redis here is for **cross-instance sync, not persistence** (your Laravel app still owns
> the durable `yjs_state`). Note that every message is processed on every instance, so
> Redis buys correctness/HA across nodes — not raw throughput. If you can route a given
> document deterministically to one node (sticky sessions / sharding), you can avoid that
> fan-out.

## Graceful shutdown

The hub stops on `SIGINT`/`SIGTERM` (Hocuspocus `stopOnSignals`), letting in-flight
debounce windows flush before exit. Give your orchestrator a `preStop`/drain grace period
so existing sockets finish.

## Reuse across projects

The image is intentionally project-agnostic. Point each deployment at its own Laravel
backend (`VAULTER_BACKEND_URL`) and origins; the same `ghcr.io/axiomasoft/vaulter-hocuspocus`
tag serves them all. In production, pin a digest (`@sha256:…`) so every consumer runs the
exact same artifact.

## Local development

This repo ships the source under `hocuspocus-server/` and a `docker-compose.yml` that can
either pull the published image or `build: .` from source. For the realtime e2e stand, see
[`e2e/README.md`](https://github.com/axioma-studio/vaulter/tree/main/e2e) and the
`make e2e-hub` target.
