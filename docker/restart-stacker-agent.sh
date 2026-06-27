#!/usr/bin/env sh
# Sobe o stacker-agent mesmo com app fora — necessário para updates remotos.
# Uso na VPS: cd /opt/getfy && sh docker/restart-stacker-agent.sh
set -eu

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT_DIR"

ENV_FILE=".docker/stack.env"
if [ ! -f "$ENV_FILE" ]; then
  echo "Erro: $ENV_FILE não encontrado." >&2
  exit 1
fi

set -a
# shellcheck disable=SC1091
. "$ENV_FILE"
set +a

HOST_DIR="${GETFY_HOST_DIR:-$ROOT_DIR}"
PROJECT="${GETFY_COMPOSE_PROJECT_NAME:-getfy}"
COMPOSE_FILE="$(sh docker/detect-compose-files.sh 2>/dev/null || echo 'docker-compose.yml')"

if [ -f docker/ensure-host-dotenv.sh ]; then
  sh docker/ensure-host-dotenv.sh "$HOST_DIR"
elif [ ! -f "$HOST_DIR/.env" ] || [ ! -s "$HOST_DIR/.env" ]; then
  echo "Erro: .env ausente. Rode: sh docker/ensure-host-dotenv.sh $HOST_DIR" >&2
  exit 1
fi

if ! grep -Eq '^\s*STACKER_AGENT_TOKEN\s*=' "$HOST_DIR/.env" 2>/dev/null; then
  echo "Erro: STACKER_AGENT_TOKEN não está em $HOST_DIR/.env" >&2
  exit 1
fi

echo "=== Subindo stacker-agent (project=$PROJECT, host=$HOST_DIR) ==="
docker compose -p "$PROJECT" --project-directory "$HOST_DIR" \
  -f "$COMPOSE_FILE" --env-file "$HOST_DIR/.docker/stack.env" \
  up -d --no-deps --force-recreate stacker-agent

echo ""
echo "Logs (últimas 20 linhas):"
sleep 3
docker compose -p "$PROJECT" --project-directory "$HOST_DIR" \
  -f "$COMPOSE_FILE" --env-file "$HOST_DIR/.docker/stack.env" \
  logs stacker-agent --tail 20 2>/dev/null \
  || docker logs "${PROJECT}-stacker-agent-1" --tail 20 2>/dev/null \
  || true

echo ""
echo "Pronto. O agente deve aparecer online no portal em ~30s."
