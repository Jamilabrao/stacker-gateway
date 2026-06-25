#!/usr/bin/env bash
# Gera STACKER_AGENT_TOKEN no .env e exibe instruções de vinculação no painel Stacker.
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
ENV_FILE="${STACKER_ENV_FILE:-$ROOT_DIR/.env}"

ensure_stacker_agent_token() {
  if [ ! -f "$ENV_FILE" ]; then
    echo "Aviso: .env não encontrado em $ENV_FILE — token Stacker não configurado." >&2
    return 0
  fi

  if grep -Eq '^\s*STACKER_AGENT_TOKEN\s*=' "$ENV_FILE"; then
    TOKEN="$(grep -E '^\s*STACKER_AGENT_TOKEN\s*=' "$ENV_FILE" | tail -1 | cut -d= -f2- | tr -d '"' | tr -d "'")"
  else
    TOKEN="$(openssl rand -hex 32)"
    {
      echo ""
      echo "# Stacker Agent (licença + updates remotos)"
      echo "STACKER_API_URL=https://api.stacker.builders"
      echo "STACKER_AGENT_TOKEN=$TOKEN"
      echo "STACKER_SUPPORT_WHATSAPP=${STACKER_SUPPORT_WHATSAPP:-}"
    } >> "$ENV_FILE"
  fi

  APP_URL_VAL="$(grep -E '^\s*APP_URL\s*=' "$ENV_FILE" | tail -1 | cut -d= -f2- | tr -d '"' | tr -d "'" || true)"
  if [ -z "$APP_URL_VAL" ]; then
    APP_URL_VAL="$(grep -E '^\s*GETFY_APP_URL\s*=' "$ENV_FILE" 2>/dev/null | tail -1 | cut -d= -f2- | tr -d '"' | tr -d "'" || true)"
  fi

  echo ""
  echo "=== Stacker Agent ==="
  echo "Token (vincule no painel admin Stacker — exibido uma vez):"
  echo "$TOKEN"
  echo ""
  echo "Domínio reportado: ${APP_URL_VAL:-configure APP_URL}"
  echo "Após vincular, o agente enviará heartbeat para api.stacker.builders"
}

ensure_stacker_agent_token "$@"
