#!/usr/bin/env sh
set -eu
ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT_DIR"
ENV_FILE=".docker/stack.env"
if [ ! -f "$ENV_FILE" ]; then
  echo "Erro: $ENV_FILE não encontrado." >&2
  exit 1
fi

COMPOSE_FILE="$(sh docker/detect-compose-files.sh 2>/dev/null || echo 'docker-compose.yml')"
COMPOSE_ARGS="-f $COMPOSE_FILE --env-file $ENV_FILE"

PROFILE=""
if [ -f .docker/compose-profile ]; then
  PROFILE="$(tr -d ' \t\r\n' < .docker/compose-profile)"
fi

echo "=== Containers (perfil: ${PROFILE:-auto}, compose: $COMPOSE_FILE) ==="
docker compose $COMPOSE_ARGS ps -a 2>/dev/null || docker compose -f docker-compose.yml --env-file "$ENV_FILE" ps -a

echo ""
echo "=== Portas 80/443 no host ==="
ss -ltnp 2>/dev/null | grep -E ':80 |:443 ' || netstat -ltnp 2>/dev/null | grep -E ':80 |:443 ' || true

echo ""
echo "=== Logs app (últimas 60 linhas) ==="
docker compose $COMPOSE_ARGS logs app --tail 60 2>/dev/null || true

if [ "$COMPOSE_FILE" = "docker-compose.caddy.yml" ]; then
  echo ""
  echo "=== Logs caddy (últimas 40 linhas) ==="
  docker compose $COMPOSE_ARGS logs caddy --tail 40 2>/dev/null || true
fi

echo ""
echo "=== Logs workers / queue (últimas 30 linhas) ==="
if [ "$COMPOSE_FILE" = "docker-compose.yml" ]; then
  for svc in worker-payments worker-webhooks-out worker-webhooks-in worker-payouts scheduler; do
    echo "--- $svc ---"
    docker compose $COMPOSE_ARGS logs "$svc" --tail 15 2>/dev/null || true
  done
else
  docker compose $COMPOSE_ARGS logs queue --tail 30 2>/dev/null || true
fi

echo ""
echo "=== Verificação de filas (API) ==="
sh docker/verify-workers.sh 2>/dev/null || true

echo ""
echo "=== Caddyfile.domains (volume) ==="
docker run --rm -v getfy_getfy_env:/v alpine cat /v/Caddyfile.domains 2>/dev/null || true

echo ""
echo "=== curl local ==="
curl -sI --max-time 5 http://127.0.0.1/ | head -8 || echo "HTTP 80 falhou"
curl -skI --max-time 5 https://127.0.0.1/ | head -8 || echo "HTTPS 443 falhou"
