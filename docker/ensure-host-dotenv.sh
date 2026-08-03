#!/usr/bin/env sh
# Garante .env na raiz — docker compose exige para env_file do stacker-agent.
# Roda no host ou dentro do stacker-agent (cwd /gateway — não use /opt/getfy aqui).
# Uso: sh docker/ensure-host-dotenv.sh
set -eu

GATEWAY_DIR="$(cd "$(dirname "$0")/.." && pwd)"
STACK_ENV="$GATEWAY_DIR/.docker/stack.env"
DOTENV="$GATEWAY_DIR/.env"

# Fallback: path do host passado por engano (ex.: /opt/getfy dentro do container).
if [ ! -f "$STACK_ENV" ] && [ -n "${1:-}" ] && [ "$1" != "$GATEWAY_DIR" ] && [ -f "$1/.docker/stack.env" ]; then
  STACK_ENV="$1/.docker/stack.env"
  DOTENV="$1/.env"
fi

if [ ! -f "$STACK_ENV" ]; then
  echo "ensure-host-dotenv: $STACK_ENV ausente (gateway dir: $GATEWAY_DIR)" >&2
  exit 1
fi

unset GETFY_DB_CONNECTION GETFY_DB_HOST GETFY_DB_PORT GETFY_DB_DATABASE GETFY_DB_USERNAME GETFY_DB_PASSWORD 2>/dev/null || true
set -a
# shellcheck disable=SC1091
. "$STACK_ENV"
set +a

read_env_var() {
  local file="$1"
  local key="$2"
  grep -E "^[[:space:]]*${key}[[:space:]]*=" "$file" 2>/dev/null | tail -1 | cut -d= -f2- | tr -d '"' | tr -d "'" | tr -d '\r\n' | sed 's/^[[:space:]]*//;s/[[:space:]]*$//' || true
}

set_env_var_in_file() {
  local file="$1"
  local key="$2"
  local val="$3"
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

VOLUME_STACK="$(docker run --rm -v getfy_getfy_env:/v alpine cat /v/stack.env 2>/dev/null || true)"
VOLUME_STACK_FILE=""
if [ -n "$VOLUME_STACK" ]; then
  VOLUME_STACK_FILE="$(mktemp)"
  printf '%s\n' "$VOLUME_STACK" > "$VOLUME_STACK_FILE"
fi

if [ ! -f "$DOTENV" ] || [ ! -s "$DOTENV" ]; then
  {
    echo "GETFY_DB_CONNECTION=${GETFY_DB_CONNECTION:-pgsql}"
    echo "GETFY_DB_HOST=${GETFY_DB_HOST:-postgres}"
    echo "GETFY_DB_PORT=${GETFY_DB_PORT:-5432}"
    echo "GETFY_DB_DATABASE=${GETFY_DB_DATABASE:-getfy}"
    echo "GETFY_DB_USERNAME=${GETFY_DB_USERNAME:-getfy}"
    echo "GETFY_DB_PASSWORD=${GETFY_DB_PASSWORD:-getfy}"
    echo "GETFY_APP_URL=${GETFY_APP_URL:-http://localhost}"
  } > "$DOTENV"
  echo "ensure-host-dotenv: criado $DOTENV"
fi

for var in STACKER_AGENT_TOKEN STACKER_API_URL STACKER_RELEASE_SIGNING_KEY; do
  dotenv_val="$(read_env_var "$DOTENV" "$var")"
  stack_val="$(read_env_var "$STACK_ENV" "$var")"
  val="$dotenv_val"
  if [ -z "$val" ]; then
    val="$stack_val"
  fi
  if [ -z "$val" ] && [ -n "$VOLUME_STACK_FILE" ]; then
    val="$(read_env_var "$VOLUME_STACK_FILE" "$var")"
  fi
  if [ -z "$val" ]; then
    cid="$(docker ps -q --filter 'name=stacker-agent' 2>/dev/null | head -1 || true)"
    if [ -n "$cid" ]; then
      val="$(docker inspect -f '{{range .Config.Env}}{{println .}}{{end}}' "$cid" 2>/dev/null | grep "^${var}=" | cut -d= -f2- | tr -d '\r\n' || true)"
    fi
  fi
  if [ -z "$val" ]; then
    continue
  fi
  # Bidirecional: .env ↔ stack.env (compose interpola STACKER_* do stack.env)
  if [ -z "$dotenv_val" ]; then
    set_env_var_in_file "$DOTENV" "$var" "$val"
    echo "ensure-host-dotenv: ${var} sincronizado em .env"
  fi
  if [ -z "$stack_val" ] || [ "$stack_val" != "$val" ]; then
    set_env_var_in_file "$STACK_ENV" "$var" "$val"
    echo "ensure-host-dotenv: ${var} sincronizado em stack.env"
  fi
done

[ -n "$VOLUME_STACK_FILE" ] && rm -f "$VOLUME_STACK_FILE"

chmod 600 "$DOTENV" 2>/dev/null || true

if ! grep -Eq '^[[:space:]]*STACKER_AGENT_TOKEN=[^[:space:]]' "$DOTENV" 2>/dev/null; then
  echo "ensure-host-dotenv: STACKER_AGENT_TOKEN vazio em $DOTENV — configure antes do apply." >&2
  exit 1
fi

echo "ensure-host-dotenv: OK ($DOTENV)"
