#!/usr/bin/env sh
# Garante .env na raiz — docker compose exige para env_file do stacker-agent.
# Roda no host ou dentro do stacker-agent (cwd /gateway — não use /opt/getfy aqui).
# Uso: sh docker/ensure-host-dotenv.sh
#
# Nunca aborta o apply remoto por token ausente (exit 0 + aviso).
# Antes: exit 1 fazia o agente antigo falhar com
#   Command failed: sh "/gateway/docker/ensure-host-dotenv.sh"
# e deixava VERSION nova + runtime antiga.
set -u

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
  exit 0
fi

unset GETFY_DB_CONNECTION GETFY_DB_HOST GETFY_DB_PORT GETFY_DB_DATABASE GETFY_DB_USERNAME GETFY_DB_PASSWORD 2>/dev/null || true
# Não usar `source` — GETFY_COMPOSE_FILES sem aspas vira "command not found".
while IFS= read -r line || [ -n "$line" ]; do
  line="$(printf '%s' "$line" | tr -d '\r')"
  case "$line" in
    ''|\#*) continue ;;
  esac
  case "$line" in
    *=*) ;;
    *) continue ;;
  esac
  key="${line%%=*}"
  val="${line#*=}"
  key="$(printf '%s' "$key" | sed 's/^[[:space:]]*//;s/[[:space:]]*$//')"
  case "$key" in
    ''|*[!A-Za-z0-9_]*) continue ;;
  esac
  val="$(printf '%s' "$val" | sed 's/^[[:space:]]*//;s/[[:space:]]*$//')"
  val="${val#\"}"; val="${val%\"}"
  val="${val#\'}"; val="${val%\'}"
  export "$key=$val"
done < "$STACK_ENV"

read_env_var() {
  file="$1"
  key="$2"
  grep -E "^[[:space:]]*${key}[[:space:]]*=" "$file" 2>/dev/null | tail -1 | cut -d= -f2- | tr -d '"' | tr -d "'" | tr -d '\r\n' | sed 's/^[[:space:]]*//;s/[[:space:]]*$//' || true
}

set_env_var_in_file() {
  file="$1"
  key="$2"
  val="$3"
  touch "$file" 2>/dev/null || return 0
  if grep -Eq "^[[:space:]]*${key}[[:space:]]*=" "$file" 2>/dev/null; then
    tmp="$(mktemp)"
    awk -v k="$key" -v v="$val" '
      $0 ~ "^[[:space:]]*" k "[[:space:]]*=" { print k "=" v; next }
      { print }
    ' "$file" > "$tmp" && mv "$tmp" "$file" || rm -f "$tmp"
  else
    echo "${key}=${val}" >> "$file"
  fi
}

VOLUME_STACK=""
VOLUME_STACK_FILE=""
if command -v docker >/dev/null 2>&1; then
  VOLUME_STACK="$(docker run --rm -v getfy_getfy_env:/v alpine cat /v/stack.env 2>/dev/null || true)"
fi
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
    echo "GETFY_DB_USERNAME=${GETFY_DB_USERNAME}"
    echo "GETFY_DB_PASSWORD=${GETFY_DB_PASSWORD}"
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
  if [ -z "$val" ] && command -v docker >/dev/null 2>&1; then
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
  echo "ensure-host-dotenv: AVISO — STACKER_AGENT_TOKEN vazio em $DOTENV (apply segue; configure o token depois)." >&2
  exit 0
fi

echo "ensure-host-dotenv: OK ($DOTENV)"
exit 0
