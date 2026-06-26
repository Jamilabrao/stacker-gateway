FROM node:22-alpine AS build
WORKDIR /app
COPY package.json tsconfig.json ./
COPY src ./src
RUN npm install && npm run build && npm prune --omit=dev

FROM node:22-alpine
RUN apk add --no-cache docker-cli bash unzip curl \
  && mkdir -p /usr/lib/docker/cli-plugins \
  && ARCH="$(uname -m | sed 's/x86_64/linux-x86_64/;s/aarch64/linux-aarch64/')" \
  && curl -fsSL "https://github.com/docker/compose/releases/download/v2.32.4/docker-compose-${ARCH}" \
    -o /usr/lib/docker/cli-plugins/docker-compose \
  && chmod +x /usr/lib/docker/cli-plugins/docker-compose
WORKDIR /agent
COPY --from=build /app/dist ./dist
COPY --from=build /app/package.json ./
ENV NODE_ENV=production
CMD ["node", "dist/index.js"]
