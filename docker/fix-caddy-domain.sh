#!/usr/bin/env sh
# Regrava Caddyfile.domains no volume getfy_env a partir de .docker/app.url (rodar na raiz do projeto).
set -eu

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT_DIR"

ENV_FILE=".docker/stack.env"
VOLUME_NAME="${GETFY_ENV_VOLUME:-}"
if [ -z "$VOLUME_NAME" ]; then
  PROJECT="$(basename "$ROOT_DIR" | tr '[:upper:]' '[:lower:]' | tr -cd 'a-z0-9-')"
  VOLUME_NAME="${PROJECT}_getfy_env"
  if ! docker volume inspect "$VOLUME_NAME" >/dev/null 2>&1; then
    VOLUME_NAME="getfy_getfy_env"
  fi
fi

APP_URL="$(docker run --rm -v "${VOLUME_NAME}:/v" alpine cat /v/app.url 2>/dev/null || true)"
if [ -z "$APP_URL" ]; then
  echo "Erro: não achei app.url no volume ${VOLUME_NAME}" >&2
  exit 1
fi

DOMAIN="$(printf '%s' "$APP_URL" | sed -E 's#https?://##; s#/.*##; s/:.*//')"
if [ -z "$DOMAIN" ]; then
  echo "Erro: domínio inválido em app.url: $APP_URL" >&2
  exit 1
fi

TLS_BLOCK="	tls internal"
if docker run --rm -v "${VOLUME_NAME}:/v" alpine sh -c 'test -s /v/certs/origin.pem && test -s /v/certs/origin-key.pem'; then
  TLS_BLOCK="	tls /etc/getfy/certs/origin.pem /etc/getfy/certs/origin-key.pem"
  echo "Usando certificado Origin Cloudflare (Full strict)."
else
  echo "Usando tls internal (Cloudflare Full; para strict, coloque certs em .docker/certs/)."
fi

docker run --rm -v "${VOLUME_NAME}:/v" alpine sh -c "cat > /v/Caddyfile.domains <<EOF
${DOMAIN} {
${TLS_BLOCK}
	reverse_proxy app:80
}
EOF"

echo "Caddyfile.domains:"
docker run --rm -v "${VOLUME_NAME}:/v" alpine cat /v/Caddyfile.domains

if [ -f docker-compose.caddy.yml ] && [ -f "$ENV_FILE" ]; then
  echo ""
  echo "Recriando Caddy..."
  COMPOSE_FILE="$(sh docker/detect-compose-files.sh 2>/dev/null || echo 'docker-compose.caddy.yml')"
  PROJECT="${GETFY_COMPOSE_PROJECT_NAME:-getfy}"
  COMPOSE=(docker compose -p "$PROJECT" -f "$COMPOSE_FILE" --env-file "$ENV_FILE")
  if [ -f .env ]; then
    COMPOSE+=(--env-file .env)
  fi
  "${COMPOSE[@]}" up -d --force-recreate --no-deps caddy
fi
