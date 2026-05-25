#!/usr/bin/env sh
# Recupera stack Getfy para Cloudflare SSL Flexible (origem HTTP na porta 80).
set -eu

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT_DIR"

ENV_FILE=".docker/stack.env"
COMPOSE_FILE="docker-compose.caddy.yml"

if [ ! -f "$ENV_FILE" ]; then
  echo "Erro: $ENV_FILE não encontrado. Rode install/up antes." >&2
  exit 1
fi

# Cloudflare Flexible conecta na porta 80 do host por padrão.
if grep -Eq '^\s*GETFY_HTTP_PORT\s*=' "$ENV_FILE"; then
  PORT="$(grep -E '^GETFY_HTTP_PORT=' "$ENV_FILE" | head -1 | cut -d= -f2- | tr -d ' \r')"
else
  PORT="80"
fi
if [ "$PORT" != "80" ]; then
  echo "AVISO: GETFY_HTTP_PORT=$PORT no stack.env"
  echo "  Cloudflare Flexible usa porta 80 na origem por padrão."
  echo "  Ajuste no painel CF (Origin Port) ou defina GETFY_HTTP_PORT=80 e recrie os containers."
  echo ""
fi

VOLUME_NAME="getfy_getfy_env"
docker volume inspect "$VOLUME_NAME" >/dev/null 2>&1 || VOLUME_NAME="$(basename "$ROOT_DIR" | tr '[:upper:]' '[:lower:]' | tr -cd 'a-z0-9-')_getfy_env"

echo "=== Caddyfile (HTTP :80, sem redirect) ==="
tee docker/Caddyfile >/dev/null <<'EOF'
{
	http_port 80
	https_port 443
	auto_https disable_redirects
}

:80 {
	reverse_proxy app:80
	encode zstd gzip
}

import /etc/getfy/Caddyfile.domains
EOF

echo "=== Caddyfile.domains (placeholder se vazio) ==="
docker run --rm -v "${VOLUME_NAME}:/v" alpine sh -c '
  if [ ! -s /v/Caddyfile.domains ]; then
    echo "# domains via docker-setup" > /v/Caddyfile.domains
  fi
  cat /v/Caddyfile.domains
'

echo ""
echo "=== Subindo stack (Caddy) ==="
docker compose -f "$COMPOSE_FILE" --env-file "$ENV_FILE" up -d --build --remove-orphans

echo ""
echo "Aguardando containers (15s)..."
sleep 15

echo ""
echo "=== Status ==="
docker compose -f "$COMPOSE_FILE" --env-file "$ENV_FILE" ps -a

echo ""
echo "=== Porta 80 no host ==="
ss -ltnp 2>/dev/null | grep -E ':80 ' || netstat -ltnp 2>/dev/null | grep ':80 ' || echo "Nada escutando na 80!"

echo ""
echo "=== Teste HTTP local (deve responder em < 5s) ==="
curl -sI --max-time 8 http://127.0.0.1/ | head -10 || echo "FALHOU: origem não responde na 80"

echo ""
echo "=== Logs app (se Restarting, corrija antes do CF) ==="
docker compose -f "$COMPOSE_FILE" --env-file "$ENV_FILE" logs app --tail 25 2>/dev/null || true

echo ""
echo "=== Logs caddy ==="
docker compose -f "$COMPOSE_FILE" --env-file "$ENV_FILE" logs caddy --tail 15 2>/dev/null || true

APP_STATE="$(docker compose -f "$COMPOSE_FILE" --env-file "$ENV_FILE" ps app --format '{{.State}}' 2>/dev/null || true)"
if echo "$APP_STATE" | grep -qi restarting; then
  echo ""
  echo ">>> Container APP em restart loop — 522 até corrigir. Veja: docker compose ... logs app --tail 80"
  exit 1
fi

if ! curl -sf --max-time 5 -o /dev/null http://127.0.0.1/ 2>/dev/null; then
  echo ""
  echo ">>> HTTP local falhou — Cloudflare continuará com 522."
  exit 1
fi

echo ""
echo "OK: origem responde na 80. Se CF ainda der 522, confira DNS (IP da VPS) e SSL Flexible."
