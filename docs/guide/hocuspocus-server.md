# Hocuspocus Server (Docker)

The optional Node service that powers Vaulter's realtime **document collaboration**
(Yjs/TipTap). It is the recommended — and TipTap's own — collaboration backend.

> **Reverb is not a collaboration transport.** Laravel Reverb is a general broadcast bus
> (notifications, presence, activity). Collaborative editing runs on Hocuspocus; the
> `reverb` collaboration driver is deprecated. See [Realtime Collaboration](./collaboration).

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
        │                                                            │
        │                                  onConnect → Origin allowlist (CSWSH)
        │                                  onAuthenticate ─POST /collab/authenticate─┐
        │                                  onStoreDocument ─POST /collab/store───────┤
        └──── POST /api/vaulter/nodes/{node}/collab/token ──▶ Laravel ◀──────────────┘
                                                              persists yjs_state (bytea)
```

1. The client calls `POST /api/vaulter/nodes/{node}/collab/token` on Laravel and gets a
   room JWT (`{room, sub, readOnly, exp}`, HS256) plus the hub `url`.
2. It opens a WebSocket to the hub with that JWT as the **token** and the **room name**
   (`vaulter.documents.<nodeId>`) as the document name.
3. The hub validates the `Origin`, then forwards the JWT + room to
   `POST {backend}/api/vaulter/collab/authenticate`. `{allowed:false}` (or any non-2xx)
   refuses the socket; `readOnly` downgrades the connection so the server drops a viewer's
   edits.
4. `onStoreDocument` (debounced) base64-encodes the **binary** Yjs state and POSTs it to
   `POST {backend}/api/vaulter/collab/store`, which persists it into
   `v_node_document_versions.yjs_state`. The Yjs binary is the source of truth.

The hub does **not** need the JWT signing secret — Laravel mints the token and the client
forwards it; the hub only relays it to the webhook, which verifies the signature.

## Environment contract

All configuration is via environment variables (12-factor; no secrets are baked into the
image). The hub **validates on startup and fails fast** if required production values are
missing.

### Connectivity

| Variable | Default | Purpose |
| --- | --- | --- |
| `VAULTER_COLLAB_PORT` | `1234` | WebSocket port clients connect to (`ws(s)://host:PORT`). |
| `VAULTER_COLLAB_HEALTH_PORT` | `1235` | HTTP health port — `GET /health` → `200`. Separate from the WS port. |
| `VAULTER_BACKEND_URL` | `http://localhost` | Your Laravel app base URL the hub calls back into. **Required in production.** |
| `VAULTER_API_PREFIX` | `api/vaulter` | The Vaulter route prefix. Webhooks resolve to `{BACKEND}/{PREFIX}/collab/{authenticate,store}`. |
| `VAULTER_LARAVEL_WEBHOOK_URL` | *(derived)* | Explicit full webhook base; overrides the two above (reverse proxy / split host). |
| `VAULTER_COLLAB_WEBHOOK_SECRET` | *(unset)* | Shared secret presented as `X-Vaulter-Collab-Secret`. Must equal the Laravel `vaulter.collaboration.webhook_secret`. Unset ⇒ the hub authenticates webhooks with the forwarded room JWT alone (never open). |
| `VAULTER_COLLAB_WEBHOOK_TIMEOUT_MS` | `10000` | Webhook round-trip timeout. |

### Security

| Variable | Default | Purpose |
| --- | --- | --- |
| `VAULTER_COLLAB_ALLOWED_ORIGINS` | *(empty)* | **CSWSH defence.** Comma-separated list of EXACT browser origins allowed to connect. **Required in production** (the hub refuses to boot without it). `*` allows any (dev only). A non-browser client (no `Origin`) is always allowed — it is not a CSWSH vector and is still gated by the auth webhook. |
| `VAULTER_COLLAB_MAX_PAYLOAD_BYTES` | `4194304` (4 MiB) | Max WebSocket frame size (the `ws` default of 100 MiB is far too high for Yjs updates). |
| `VAULTER_COLLAB_THROTTLE` | `15` | Per-IP connection attempts allowed before a temporary ban. |
| `VAULTER_COLLAB_THROTTLE_SECONDS` | `60` | Window (seconds) the attempts are counted over. |
| `VAULTER_COLLAB_BAN_TIME` | `5` | Ban duration (minutes) after the throttle is exceeded. |

### Liveness / persistence pressure

| Variable | Default | Purpose |
| --- | --- | --- |
| `VAULTER_COLLAB_TIMEOUT_MS` | `30000` | Dead-peer heartbeat interval. Align with your load-balancer/proxy idle timeout. |
| `VAULTER_COLLAB_DEBOUNCE_MS` | `5000` | `onStoreDocument` debounce — higher = fewer persistence writes during active editing. |
| `VAULTER_COLLAB_MAX_DEBOUNCE_MS` | `30000` | Hard cap before a forced store (bounds the worst-case unsaved window). |

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
- **Keep `MAX_PAYLOAD` small** — Yjs updates are incremental and tiny; a low cap blunts
  oversized-message DoS. (The hub now applies this cap to the underlying `ws` server; the
  library default of ~100 MiB is never used.)
- The **room JWT is short-lived** and bound to exactly one node; the webhook re-checks
  access, so a viewer can never write (the server drops their updates).
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
