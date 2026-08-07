#!/bin/sh
set -e

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT_DIR"

mkdir -p .docker

ENV_FILE=".docker/stack.env"
if [ ! -f "$ENV_FILE" ]; then
  HTTP_PORT="${GETFY_HTTP_PORT:-80}"
  APP_URL="${GETFY_APP_URL:-http://localhost}"
  WEBHOOK_PUBLIC="${GETFY_WEBHOOK_PUBLIC_URL:-$APP_URL}"

  # Só na 1ª instalação (sem stack.env). Se já houver volume, ensure-db-credentials ajusta.
  U="getfy_$(tr -dc 'a-z0-9' < /dev/urandom | head -c 8)"
  P="$(tr -dc 'A-Za-z0-9' < /dev/urandom | head -c 32)"

  cat > "$ENV_FILE" <<EOF
GETFY_DB_CONNECTION=pgsql
GETFY_DB_HOST=postgres
GETFY_DB_PORT=5432
GETFY_DB_DATABASE=getfy
GETFY_DB_USERNAME=$U
GETFY_DB_PASSWORD=$P
GETFY_APP_URL=$APP_URL
GETFY_WEBHOOK_PUBLIC_URL=$WEBHOOK_PUBLIC
GETFY_HTTP_PORT=$HTTP_PORT
GETFY_QUEUE_CONNECTION=${GETFY_QUEUE_CONNECTION:-redis}
GETFY_CACHE_STORE=${GETFY_CACHE_STORE:-redis}
GETFY_SESSION_DRIVER=${GETFY_SESSION_DRIVER:-file}
GETFY_REDIS_MAXMEMORY=${GETFY_REDIS_MAXMEMORY:-128mb}
GETFY_REDIS_MAXMEMORY_POLICY=${GETFY_REDIS_MAXMEMORY_POLICY:-allkeys-lru}
GETFY_QUEUE_WORKER_MEMORY=${GETFY_QUEUE_WORKER_MEMORY:-128}
GETFY_QUEUE_WORKER_MAX_TIME=${GETFY_QUEUE_WORKER_MAX_TIME:-3600}
GETFY_QUEUE_WORKER_MAX_JOBS=${GETFY_QUEUE_WORKER_MAX_JOBS:-1000}
GETFY_CADDY_HOST=${GETFY_CADDY_HOST:-:80}
API_INBOUND_WEBHOOKS_ASYNC=${API_INBOUND_WEBHOOKS_ASYNC:-true}
GETFY_APP_ENV=production
GETFY_APP_DEBUG=false
GETFY_COMPOSE_PROJECT_NAME=$(basename "$ROOT_DIR")
EOF
fi

# Alinha/recupera DB: NUNCA gera user aleatório se o volume Postgres já existe.
# (Bug antigo: stack.env sem U/P → gerava getfy_xxx → 521 no update).
if [ -f docker/ensure-db-credentials.sh ]; then
  chmod +x docker/ensure-db-credentials.sh 2>/dev/null || true
  sh docker/ensure-db-credentials.sh
fi

if [ -f "$ENV_FILE" ] && ! grep -Eq '^\s*GETFY_COMPOSE_PROJECT_NAME\s*=' "$ENV_FILE" 2>/dev/null; then
  echo "GETFY_COMPOSE_PROJECT_NAME=$(basename "$ROOT_DIR")" >> "$ENV_FILE"
fi

if [ -f "$ENV_FILE" ] && ! grep -Eq '^\s*GETFY_WEBHOOK_PUBLIC_URL\s*=' "$ENV_FILE"; then
  LINE_APP="$(grep -E '^GETFY_APP_URL=' "$ENV_FILE" 2>/dev/null | head -1 || true)"
  VAL_APP="${LINE_APP#GETFY_APP_URL=}"
  VAL_APP="${GETFY_APP_URL:-${VAL_APP:-http://localhost}}"
  echo "GETFY_WEBHOOK_PUBLIC_URL=${GETFY_WEBHOOK_PUBLIC_URL:-$VAL_APP}" >> "$ENV_FILE"
fi

# Normaliza host/porta (não toca USERNAME/PASSWORD).
TMP_DB="$(mktemp)"
awk '
  BEGIN { c=0; h=0; p=0 }
  $0 ~ /^GETFY_DB_CONNECTION=/ { print "GETFY_DB_CONNECTION=pgsql"; c=1; next }
  $0 ~ /^GETFY_DB_HOST=/ { print "GETFY_DB_HOST=postgres"; h=1; next }
  $0 ~ /^GETFY_DB_PORT=/ { print "GETFY_DB_PORT=5432"; p=1; next }
  { print }
  END {
    if (!c) print "GETFY_DB_CONNECTION=pgsql"
    if (!h) print "GETFY_DB_HOST=postgres"
    if (!p) print "GETFY_DB_PORT=5432"
  }
' "$ENV_FILE" > "$TMP_DB"
mv "$TMP_DB" "$ENV_FILE"

# Revalida credenciais após normalização de host
if [ -f docker/ensure-db-credentials.sh ]; then
  sh docker/ensure-db-credentials.sh
fi

# Sempre produção (install/update e deploy Docker).
TMP_PROD="$(mktemp)"
awk '
  BEGIN { env=0; dbg=0 }
  $0 ~ /^GETFY_APP_ENV=/ { print "GETFY_APP_ENV=production"; env=1; next }
  $0 ~ /^GETFY_APP_DEBUG=/ { print "GETFY_APP_DEBUG=false"; dbg=1; next }
  { print }
  END {
    if (!env) print "GETFY_APP_ENV=production"
    if (!dbg) print "GETFY_APP_DEBUG=false"
  }
' "$ENV_FILE" > "$TMP_PROD"
mv "$TMP_PROD" "$ENV_FILE"

# stacker-agent e outros serviços usam env_file: .env na raiz do projeto
if [ ! -f .env ] || [ ! -s .env ]; then
  APP_URL_VAL="$(grep -E '^GETFY_APP_URL=' "$ENV_FILE" 2>/dev/null | head -1 | cut -d= -f2- | tr -d '"' | tr -d "'")"
  if [ -z "$APP_URL_VAL" ]; then
    APP_URL_VAL="http://localhost"
  fi
  cat > .env <<EOF
# Host: Stacker agent + compose. O Laravel usa .env dentro do container app.
APP_URL=${APP_URL_VAL}
GETFY_APP_URL=${APP_URL_VAL}
STACKER_API_URL=https://api.stacker.builders
STACKER_AGENT_TOKEN=
STACKER_RELEASE_SIGNING_KEY=
STACKER_SUPPORT_WHATSAPP=
EOF
fi

# Espelha GETFY_DB_* no .env do host (evita compose/app sem senha após update)
if [ -f .env ] && [ -f "$ENV_FILE" ]; then
  for var in GETFY_DB_CONNECTION GETFY_DB_HOST GETFY_DB_PORT GETFY_DB_DATABASE GETFY_DB_USERNAME GETFY_DB_PASSWORD; do
    val="$(grep -E "^[[:space:]]*${var}[[:space:]]*=" "$ENV_FILE" 2>/dev/null | tail -1 | cut -d= -f2- | tr -d '"' | tr -d "'" || true)"
    if [ -n "$val" ]; then
      if grep -Eq "^[[:space:]]*${var}[[:space:]]*=" .env 2>/dev/null; then
        TMP_E="$(mktemp)"
        awk -v k="$var" -v v="$val" '
          $0 ~ "^[[:space:]]*" k "[[:space:]]*=" { print k "=" v; next }
          { print }
        ' .env > "$TMP_E"
        mv "$TMP_E" .env
      else
        echo "${var}=${val}" >> .env
      fi
    fi
  done
fi

# Compose interpola ${STACKER_AGENT_TOKEN} a partir de stack.env — sincroniza do .env raiz.
if [ -f .env ]; then
  for var in STACKER_AGENT_TOKEN STACKER_API_URL STACKER_RELEASE_SIGNING_KEY; do
    val="$(grep -E "^[[:space:]]*${var}[[:space:]]*=" .env 2>/dev/null | tail -1 | cut -d= -f2- | tr -d '"' | tr -d "'" | tr -d '[:space:]' || true)"
    if [ -n "$val" ]; then
      if grep -Eq "^[[:space:]]*${var}[[:space:]]*=" "$ENV_FILE" 2>/dev/null; then
        TMP_SYNC="$(mktemp)"
        awk -v k="$var" -v v="$val" '
          $0 ~ "^[[:space:]]*" k "[[:space:]]*=" { print k "=" v; next }
          { print }
        ' "$ENV_FILE" > "$TMP_SYNC"
        mv "$TMP_SYNC" "$ENV_FILE"
      else
        echo "${var}=${val}" >> "$ENV_FILE"
      fi
    fi
  done
fi

COMPOSE_FILES="${GETFY_COMPOSE_FILES:-docker-compose.yml}"
COMPOSE_ARGS=""
OLD_IFS="$IFS"
IFS=';'
for f in $COMPOSE_FILES; do
  if [ -n "$f" ]; then
    COMPOSE_ARGS="$COMPOSE_ARGS -f $f"
  fi
done
IFS="$OLD_IFS"

# NUNCA usa "down -v". Volumes (postgres/redis/storage) são preservados.
UP_ARGS="-d --remove-orphans"
if [ "${GETFY_SKIP_DOCKER_BUILD:-0}" != "1" ]; then
  UP_ARGS="--build ${UP_ARGS}"
fi

echo "docker compose up (volumes Postgres/Redis preservados)..."
# shellcheck disable=SC2086
docker compose $COMPOSE_ARGS --env-file "$ENV_FILE" up $UP_ARGS
