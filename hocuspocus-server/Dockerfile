# Vaulter collaboration server
FROM node:22-alpine

WORKDIR /app

# Install only this service's dependencies (it is not part of the npm workspace
# graph at build time — copy its own manifest).
COPY package.json ./
RUN npm install --omit=dev

COPY src ./src

ENV VAULTER_COLLAB_PORT=1234
ENV VAULTER_COLLAB_HEALTH_PORT=1235
EXPOSE 1234 1235

# The compose healthcheck hits the health port; see docker-compose.yml / README.
HEALTHCHECK --interval=10s --timeout=3s --retries=3 \
  CMD node -e "fetch('http://localhost:'+(process.env.VAULTER_COLLAB_HEALTH_PORT||1235)+'/health').then(r=>process.exit(r.ok?0:1)).catch(()=>process.exit(1))"

CMD ["node", "src/index.js"]
