# Vaulter collaboration server
FROM node:22-alpine

WORKDIR /app

# Install only this service's dependencies (it is not part of the npm workspace
# graph at build time — copy its own manifest).
COPY package.json ./
RUN npm install --omit=dev

COPY src ./src

ENV VAULTER_COLLAB_PORT=1234
EXPOSE 1234

CMD ["node", "src/index.js"]
