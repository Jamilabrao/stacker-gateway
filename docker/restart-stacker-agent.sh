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
  sh docker/ensure-host-dotenv.sh
elif [ ! -f "$ROOT_DIR/.env" ] || ! grep -Eq '^[[:space:]]*STACKER_AGENT_TOKEN=[^[:space:]]' "$ROOT_DIR/.env" 2>/dev/null; then
  echo "Erro: .env ausente ou sem STACKER_AGENT_TOKEN." >&2
  exit 1
fi

echo "=== Subindo stacker-agent (project=$PROJECT, host=$HOST_DIR) ==="
COMPOSE=(docker compose -p "$PROJECT" --project-directory "$HOST_DIR" \
  -f "$COMPOSE_FILE" --env-file "$ROOT_DIR/.docker/stack.env" --env-file "$ROOT_DIR/.env")

"${COMPOSE[@]}" up -d --no-deps --force-recreate stacker-agent

echo ""
echo "Logs (últimas 20 linhas):"
sleep 3
"${COMPOSE[@]}" logs stacker-agent --tail 20 2>/dev/null \
  || docker logs "${PROJECT}-stacker-agent-1" --tail 20 2>/dev/null \
  || true

echo ""
echo "Pronto. O agente deve aparecer online no portal em ~30s."
