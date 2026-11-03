FROM node:22.21.1-bookworm-slim
RUN apt-get update && apt-get install -y --no-install-recommends ca-certificates \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /opt/herokid-agentic
COPY package.json package-lock.json ./
RUN npm ci --omit=dev --ignore-scripts && npm cache clean --force
COPY ./*.mjs ./decision-schema.json ./
RUN chmod 755 codex-entry.mjs && mkdir -p /run/bridge /run/agentic-auth && chown node:node /run/bridge
USER node
ENV NODE_ENV=production
CMD ["node", "bridge.mjs"]
