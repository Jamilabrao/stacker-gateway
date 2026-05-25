#!/usr/bin/env sh
set -eu
ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT_DIR"
ENV_FILE=".docker/stack.env"
COMPOSE="-f docker-compose.caddy.yml --env-file $ENV_FILE"

echo "=== Containers ==="
docker compose $COMPOSE ps -a 2>/dev/null || docker compose -f docker-compose.yml --env-file "$ENV_FILE" ps -a

echo ""
echo "=== Portas 80/443 no host ==="
ss -ltnp 2>/dev/null | grep -E ':80 |:443 ' || netstat -ltnp 2>/dev/null | grep -E ':80 |:443 ' || true

echo ""
echo "=== Logs app (últimas 60 linhas) ==="
docker compose $COMPOSE logs app --tail 60 2>/dev/null || true

echo ""
echo "=== Logs caddy (últimas 40 linhas) ==="
docker compose $COMPOSE logs caddy --tail 40 2>/dev/null || true

echo ""
echo "=== Caddyfile.domains (volume) ==="
docker run --rm -v getfy_getfy_env:/v alpine cat /v/Caddyfile.domains 2>/dev/null || true

echo ""
echo "=== curl local ==="
curl -sI --max-time 5 http://127.0.0.1/ | head -8 || echo "HTTP 80 falhou"
curl -skI --max-time 5 https://127.0.0.1/ | head -8 || echo "HTTPS 443 falhou"
