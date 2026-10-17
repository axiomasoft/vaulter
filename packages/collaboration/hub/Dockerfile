# Vaulter collaboration hub — a reusable, env-configured external image
# (master plan Addendum A). One published image serves many projects; consumers
# reference it via `image: ghcr.io/axiomasoft/vaulter-hocuspocus:<ver>` and configure
# it through environment variables only — no sources copied, no secrets baked.
#
# syntax=docker/dockerfile:1
# Debian-slim base: musl/alpine's npm hits "Exit handler never called" on `npm ci`; the
# deps are pure-JS so glibc-vs-musl is irrelevant, and slim stays small + non-root.
ARG NODE_VERSION=22-slim

# ── deps: reproducible production install from the committed lockfile ────────────────
FROM node:${NODE_VERSION} AS deps
WORKDIR /app
COPY package.json package-lock.json ./
# --no-audit/--no-fund avoid the post-install network steps that can crash alpine's npm
# ("Exit handler never called"); --omit=dev keeps the runtime image lean.
RUN npm ci --omit=dev --no-audit --no-fund

# ── runtime: minimal, non-root ──────────────────────────────────────────────────────
FROM node:${NODE_VERSION} AS runtime
WORKDIR /app

# Secure default: the published image runs as production, so the hub demands an explicit
# Origin allowlist (CSWSH) and backend URL rather than booting open (see config.js).
ENV NODE_ENV=production \
    VAULTER_COLLAB_PORT=1234 \
    VAULTER_COLLAB_HEALTH_PORT=1235

COPY --chown=node:node --from=deps /app/node_modules ./node_modules
COPY --chown=node:node package.json ./
COPY --chown=node:node src ./src

# Run as the unprivileged built-in `node` user (never root).
USER node

EXPOSE 1234 1235

# Liveness for compose/orchestrator — hits the SEPARATE health port (never the WS port).
HEALTHCHECK --interval=10s --timeout=3s --start-period=10s --retries=3 \
  CMD node -e "fetch('http://localhost:'+(process.env.VAULTER_COLLAB_HEALTH_PORT||1235)+'/health').then(r=>process.exit(r.ok?0:1)).catch(()=>process.exit(1))"

LABEL org.opencontainers.image.title="Vaulter Hocuspocus hub" \
      org.opencontainers.image.description="Reusable, env-configured Hocuspocus collaboration server for Vaulter (Yjs realtime document editing)." \
      org.opencontainers.image.source="https://github.com/axiomasoft/vaulter" \
      org.opencontainers.image.licenses="MIT"

# The hub's command line (src/cli.js, package bin vaulter-collaboration-hub). By default the image
# serves (WebSocket hub + compaction worker); the same image runs the offline recovery one-shot:
#   docker run --rm --env-file hub.env <image> compact --pending --deadline=120
# (exit 0: nothing left to compact; 1: the pending documents are listed on stderr).
ENTRYPOINT ["node", "src/cli.js"]
CMD ["serve"]
