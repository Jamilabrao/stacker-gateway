#!/usr/bin/env bash
# Verifica STACKER_AGENT_TOKEN no .env; solicita se ausente; exibe status.
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
ENV_FILE="${STACKER_ENV_FILE:-$ROOT_DIR/.env}"

# shellcheck source=prompt-stacker-agent-token.sh
. "$ROOT_DIR/docker/prompt-stacker-agent-token.sh"

ensure_stacker_agent_token() {
  prompt_stacker_agent_token

  if [ ! -f "$ENV_FILE" ]; then
    echo "Aviso: .env não encontrado em $ENV_FILE — token Stacker não configurado." >&2
    return 0
  fi

  TOKEN="$(read_stacker_agent_token || true)"
  if [ -z "$TOKEN" ]; then
    echo ""
    echo "=== Stacker Agent ==="
    echo "Token ainda não configurado."
    echo "Edite $ENV_FILE ou rode: bash docker/prompt-stacker-agent-token.sh"
    echo "Depois: docker compose ... up -d --force-recreate stacker-agent"
    return 0
  fi

  APP_URL_VAL="$(grep -E '^\s*APP_URL\s*=' "$ENV_FILE" | tail -1 | cut -d= -f2- | tr -d '"' | tr -d "'" || true)"
  if [ -z "$APP_URL_VAL" ]; then
    APP_URL_VAL="$(grep -E '^\s*GETFY_APP_URL\s*=' "$ENV_FILE" 2>/dev/null | tail -1 | cut -d= -f2- | tr -d '"' | tr -d "'" || true)"
  fi

  echo ""
  echo "=== Stacker Agent ==="
  echo "Token configurado (${#TOKEN} caracteres). Domínio: ${APP_URL_VAL:-configure APP_URL}"
}

ensure_stacker_agent_token "$@"
