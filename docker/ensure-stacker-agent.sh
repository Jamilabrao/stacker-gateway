#!/usr/bin/env bash
# Verifica STACKER_AGENT_TOKEN no .env e exibe instruções de vinculação no painel Stacker.
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
ENV_FILE="${STACKER_ENV_FILE:-$ROOT_DIR/.env}"

ensure_stacker_agent_token() {
  if [ ! -f "$ENV_FILE" ]; then
    echo "Aviso: .env não encontrado em $ENV_FILE — token Stacker não configurado." >&2
    return 0
  fi

  TOKEN=""
  if grep -Eq '^\s*STACKER_AGENT_TOKEN\s*=' "$ENV_FILE"; then
    TOKEN="$(grep -E '^\s*STACKER_AGENT_TOKEN\s*=' "$ENV_FILE" | tail -1 | cut -d= -f2- | tr -d '"' | tr -d "'")"
  fi

  if [ -z "$TOKEN" ]; then
    if ! grep -Eq '^\s*STACKER_API_URL\s*=' "$ENV_FILE"; then
      {
        echo ""
        echo "# Stacker Agent (licença + updates remotos)"
        echo "STACKER_API_URL=https://api.stacker.builders"
        echo "STACKER_AGENT_TOKEN="
        echo "STACKER_SUPPORT_WHATSAPP=${STACKER_SUPPORT_WHATSAPP:-}"
      } >> "$ENV_FILE"
    fi
    echo ""
    echo "=== Stacker Agent ==="
    echo "1. No painel Stacker: Gateway → Instalações → Nova instalação"
    echo "2. Copie o token gerado (exibido uma vez) para STACKER_AGENT_TOKEN em $ENV_FILE"
    echo "3. Vincule a instalação ao cliente e defina o domínio (ex.: app.kursa.com.br)"
    echo "4. Reinicie o agente: docker compose ... up -d stacker-agent"
    return 0
  fi

  APP_URL_VAL="$(grep -E '^\s*APP_URL\s*=' "$ENV_FILE" | tail -1 | cut -d= -f2- | tr -d '"' | tr -d "'" || true)"
  if [ -z "$APP_URL_VAL" ]; then
    APP_URL_VAL="$(grep -E '^\s*GETFY_APP_URL\s*=' "$ENV_FILE" 2>/dev/null | tail -1 | cut -d= -f2- | tr -d '"' | tr -d "'" || true)"
  fi

  echo ""
  echo "=== Stacker Agent ==="
  echo "Token configurado (${#TOKEN} caracteres). Domínio: ${APP_URL_VAL:-configure APP_URL}"
  echo "Se o agente reinicia em loop, confira se o token é o mesmo criado no painel Stacker."
}

ensure_stacker_agent_token "$@"
