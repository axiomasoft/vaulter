---
id: D39
date: 2026-09-20
status: accepted
item: P8.1
items: [P8.1, P8.2, P8.3, P8.4, P8.5, P8.6, P8.7]
supersedes: []
superseded_by: null
---
# D39 — Close remaining executability holes; P8.2 may start

**Actor:** designer/cursor-grok-4.6-high/frontier/high

**Evidence:** RAG:✅ 2026-09-20 Perplexity + primary docs — PHP-FPM status
`?json&full`, `pm.status_path`, `pm.status_listen`
https://www.php.net/manual/en/fpm.status.php ·
https://www.php.net/manual/en/install.fpm.configuration.php · Redis EVAL
atomicity + GETDEL since 6.2 https://redis.io/docs/latest/develop/programmability/eval-intro/
https://redis.io/docs/latest/commands/getdel/ · Hocuspocus `Server#getDocuments()` /
`getConnectionsCount()` https://tiptap.dev/docs/hocuspocus/server/hooks ·
Prometheus classic histogram https://prometheus.io/docs/practices/histograms/ ·
nginx `internal` / FastCGI https://nginx.org/en/docs/http/ngx_http_core_module.html.
RAG:— live `load/docker-compose.load.yml` (no Redis, no queue-worker, no hocuspocus,
no `TIER` on app, prometheus self-scrape only) · `load/docker/Dockerfile.app` (no
`redis` ext) · `load/docker/php-fpm.conf` / `nginx.conf` (no status) ·
`hocuspocus-server/src/index.js` (`/health` only) · `load/k6/scenarios/docs-collab.js`
(HTTP token, no Yjs join) · owner 2026-09-20: допроектировать недочёты в этой сессии,
чтобы можно было начать реальную работу.

## Решение

1. **P8.2 start gate.** Independent Sol re-audit is waived for *start*. Two RED
   audits already spent the review budget on the same risk class. Remaining holes
   are closed in `observability-spec.md` §15–§19. P8.2 Inputs are this spec + D35–D39.
   `findings/P8.1-design-review.md` stays historical RED, not a start blocker.
   `findings/P8.1-design-complete.md` is the owner-authorized completion note.

2. **Redis Lua is one HASH per series**, not multi-key HINCRBY from PHP. Script
   `histogram_observe.lua` takes KEYS[1] + ARGV observation + ordered `le` labels
   including `+Inf`. Invariant `_count == HGET le:+Inf`. Load Redis image is
   `redis:7-alpine` (GETDEL exists). Heartbeat nonce uses GETDEL on a string key.

3. **FPM stream gauge.** `pm.status_path=/fpm-status` **and** `pm.status_listen=127.0.0.1:9001`
   so status still answers when the request pool is saturated. nginx loopback
   `127.0.0.1:8081` `/fpm-status` (not `internal`, not public 8080). PHP location
   sets `fastcgi_param REQUEST_URI $request_uri` so status `request uri` keeps the
   original download path under the front controller. Count `processes[]` with
   `state=Running` and URI matching §9.1 download paths. Vaulter streams from PHP
   (no X-Accel); this gauge is FPM workers in the download, not nginx client sockets.

4. **Collaboration rooms.** Compose service `hocuspocus` (build
   `hocuspocus-server/Dockerfile`). Hub `GET /stats` on the **health** port returns
   `{rooms, connections}` where `rooms = server.getDocuments().length`.
   `docs-collab.js` HTTP token is **not** a room. P8.7/P8.3 open rooms with
   `load/scripts/p8-collab-join.mjs` (`@hocuspocus/provider`). Silent zero is still
   forbidden if the join script or hub is absent.

5. **Class-A PromQL.** `promql_map.py` is the §16 table (42 class-A rows), not
   bare names and not interpolated quantile for caps. `increase(_count)==0` →
   `not-evaluated`. Class B stays on the k6 plane. Historical «66 not-checkable»
   flips as: 42 class-A become PromQL-evaluated; remaining named-Prometheus class-B
   rows stay k6 (not a second PromQL obligation).

6. **Stand wiring (exact deltas in §17).** App `TIER`; Redis + `queue-worker`;
   PHP Redis extension; Prometheus job `vaulter-sut`; `/up`/`/ready` as specified
   in §4; `/load/health` remains until P8.6 then compose healthcheck → `/up`.
   `CACHE_STORE=redis` so FPM and worker share nonce keys.

Family names, types, and §5 label **keys** are unchanged.

## Почему

D38 wrote the contract language; the live stand still could not host it. Owner
asked to finish those holes here instead of another audit loop. P8.2 is machinery
(Null/Fake/factory) and does not depend on Redis/hocuspocus existing yet; later
items consume §15–§17.

## Альтернативы

- Wait for a third independent design audit — rejected by owner for this start.
- Count collab rooms from k6 HTTP token — rejected: token ≠ loaded document.
- Query FPM status through the busy `www` pool — rejected: saturates exactly when
  `download_stream_active` is needed (`pm.status_listen`).
- `histogram_quantile` for class-A caps — rejected (D38).

## Consequences

P8.2 may `task:plan-run` / `task:plan-exec` against D39 + `observability-spec.md`.
P8.3–P8.7 must implement §15–§17, not reinvent transport. Independent audit remains
optional later; it is not a start gate.
