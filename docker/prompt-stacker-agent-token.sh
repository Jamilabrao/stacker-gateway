#!/usr/bin/env bash
# Solicita STACKER_AGENT_TOKEN (painel Stacker) e grava em .env + .docker/stack.env
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
ENV_FILE="${STACKER_ENV_FILE:-$ROOT_DIR/.env}"
STACK_ENV="${STACKER_STACK_ENV:-$ROOT_DIR/.docker/stack.env}"

read_stacker_agent_token() {
  if [ ! -f "$ENV_FILE" ]; then
    return 0
  fi
  grep -E '^\s*STACKER_AGENT_TOKEN\s*=' "$ENV_FILE" 2>/dev/null | tail -1 | cut -d= -f2- | tr -d '"' | tr -d "'" | tr -d '[:space:]'
}

set_env_var_in_file() {
  local file="$1"
  local key="$2"
  local val="$3"
  local dir
  dir="$(dirname "$file")"
  mkdir -p "$dir"
  touch "$file"
  if grep -Eq "^[[:space:]]*${key}[[:space:]]*=" "$file" 2>/dev/null; then
    local tmp
    tmp="$(mktemp)"
    awk -v k="$key" -v v="$val" '
      $0 ~ "^[[:space:]]*" k "[[:space:]]*=" { print k "=" v; next }
      { print }
    ' "$file" > "$tmp"
    mv "$tmp" "$file"
  else
    echo "${key}=${val}" >> "$file"
  fi
}

ensure_stacker_env_scaffold() {
  if [ ! -f "$ENV_FILE" ] || [ ! -s "$ENV_FILE" ]; then
    local app_url_val="http://localhost"
    if [ -f "$STACK_ENV" ]; then
      app_url_val="$(grep -E '^GETFY_APP_URL=' "$STACK_ENV" 2>/dev/null | head -1 | cut -d= -f2- | tr -d '"' | tr -d "'" || true)"
    fi
    if [ -z "$app_url_val" ]; then
      app_url_val="${GETFY_APP_URL:-${APP_URL:-http://localhost}}"
    fi
    cat > "$ENV_FILE" <<EOF
# Host: Stacker agent + compose. O Laravel usa .env dentro do container app.
APP_URL=${app_url_val}
GETFY_APP_URL=${app_url_val}
STACKER_API_URL=https://api.stacker.builders
STACKER_AGENT_TOKEN=
STACKER_RELEASE_SIGNING_KEY=
STACKER_SUPPORT_WHATSAPP=
EOF
    return
  fi
  if ! grep -Eq '^\s*STACKER_API_URL\s*=' "$ENV_FILE"; then
    echo "STACKER_API_URL=https://api.stacker.builders" >> "$ENV_FILE"
  fi
  if ! grep -Eq '^\s*STACKER_AGENT_TOKEN\s*=' "$ENV_FILE"; then
    echo "STACKER_AGENT_TOKEN=" >> "$ENV_FILE"
  fi
}

sync_stacker_var_to_stack_env() {
  local var="$1"
  local val="$2"
  [ -n "$val" ] || return 0
  [ -f "$STACK_ENV" ] || mkdir -p "$(dirname "$STACK_ENV")" && touch "$STACK_ENV"
  if grep -Eq "^[[:space:]]*${var}[[:space:]]*=" "$STACK_ENV" 2>/dev/null; then
    local tmp
    tmp="$(mktemp)"
    awk -v k="$var" -v v="$val" '
      $0 ~ "^[[:space:]]*" k "[[:space:]]*=" { print k "=" v; next }
      { print }
    ' "$STACK_ENV" > "$tmp"
    mv "$tmp" "$STACK_ENV"
  else
    echo "${var}=${val}" >> "$STACK_ENV"
  fi
}

sync_stacker_vars_from_env() {
  local var val
  for var in STACKER_AGENT_TOKEN STACKER_API_URL STACKER_RELEASE_SIGNING_KEY; do
    val="$(grep -E "^[[:space:]]*${var}[[:space:]]*=" "$ENV_FILE" 2>/dev/null | tail -1 | cut -d= -f2- | tr -d '"' | tr -d "'" | tr -d '[:space:]' || true)"
    sync_stacker_var_to_stack_env "$var" "$val"
  done
}

write_stacker_agent_token() {
  local token="$1"
  ensure_stacker_env_scaffold
  set_env_var_in_file "$ENV_FILE" "STACKER_AGENT_TOKEN" "$token"
  if ! grep -Eq '^\s*STACKER_API_URL\s*=' "$ENV_FILE"; then
    set_env_var_in_file "$ENV_FILE" "STACKER_API_URL" "https://api.stacker.builders"
  fi
  sync_stacker_vars_from_env
}

prompt_stacker_agent_token() {
  if [ "${GETFY_SKIP_STACKER_TOKEN_PROMPT:-0}" = "1" ]; then
    return 0
  fi

  ensure_stacker_env_scaffold

  local token
  token="$(read_stacker_agent_token || true)"
  if [ -n "$token" ]; then
    sync_stacker_vars_from_env
    return 0
  fi

  if [ -n "${STACKER_AGENT_TOKEN:-}" ]; then
    token="$(echo "$STACKER_AGENT_TOKEN" | tr -d '[:space:]')"
    if [ -n "$token" ]; then
      write_stacker_agent_token "$token"
      echo "STACKER_AGENT_TOKEN definido via variável de ambiente."
      return 0
    fi
  fi

  local tty=/dev/tty
  if [ ! -e "$tty" ]; then
    echo ""
    echo "=== Stacker Agent ==="
    echo "STACKER_AGENT_TOKEN não configurado. Defina em $ENV_FILE ou exporte STACKER_AGENT_TOKEN antes do install/update."
    return 0
  fi

  {
    echo ""
    echo "=== Stacker Agent (painel Stacker) ==="
    echo "No admin: Gateway → Instalações → Nova instalação"
    echo "Copie o token (exibido uma vez) e cole abaixo."
    echo "Vincule ao cliente e informe o domínio (ex.: app.seudominio.com.br)."
    echo ""
    printf "STACKER_AGENT_TOKEN (Enter para configurar depois): "
  } > "$tty"

  local input=""
  read -r input < "$tty" || input=""
  input="$(echo "$input" | tr -d '[:space:]')"

  if [ -z "$input" ]; then
    echo "Token não informado — configure depois em $ENV_FILE e reinicie stacker-agent." > "$tty"
    return 0
  fi

  write_stacker_agent_token "$input"
  echo "Token salvo em $ENV_FILE e sincronizado com .docker/stack.env." > "$tty"
}

if [ "$(basename "$0")" = "prompt-stacker-agent-token.sh" ]; then
  prompt_stacker_agent_token "$@"
fi
