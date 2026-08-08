#!/usr/bin/env sh
# Corrige stacker-agent offline: sincroniza token, recria container e valida heartbeat.
#
# Uso na VPS:
#   cd /opt/getfy && sh docker/fix-stacker-agent.sh
#   cd /opt/getfy && sh docker/fix-stacker-agent.sh --check-only
#
# Causas comuns de offline:
#   - STACKER_AGENT_TOKEN ausente ou dessincronizado entre .env e .docker/stack.env
#   - container stacker-agent em crash loop (Restarting)
#   - token inválido ou revogado no painel Stacker
set -eu

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT_DIR"

CHECK_ONLY=0
REBUILD=0
for arg in "$@"; do
  case "$arg" in
    --check-only) CHECK_ONLY=1 ;;
    --rebuild) REBUILD=1 ;;
    -h|--help)
      echo "Uso: sh docker/fix-stacker-agent.sh [--check-only] [--rebuild]"
      echo "  --check-only  só diagnostica, não recria o container"
      echo "  --rebuild     força docker compose build stacker-agent antes de subir"
      exit 0
      ;;
    *)
      echo "Opção desconhecida: $arg (use --help)" >&2
      exit 2
      ;;
  esac
done

ENV_FILE="$ROOT_DIR/.env"
STACK_ENV="$ROOT_DIR/.docker/stack.env"
LICENSE_FILE="$ROOT_DIR/storage/stacker/license.json"

read_env_var() {
  file="$1"
  key="$2"
  grep -E "^[[:space:]]*${key}[[:space:]]*=" "$file" 2>/dev/null | tail -1 | cut -d= -f2- | tr -d '"' | tr -d "'" | tr -d '\r\n' | sed 's/^[[:space:]]*//;s/[[:space:]]*$//' || true
}

set_env_var_in_file() {
  file="$1"
  key="$2"
  val="$3"
  dir="$(dirname "$file")"
  mkdir -p "$dir"
  touch "$file"
  if grep -Eq "^[[:space:]]*${key}[[:space:]]*=" "$file" 2>/dev/null; then
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

sync_token_to_stack_env() {
  token="$1"
  api_url="$2"
  [ -n "$token" ] || return 0
  mkdir -p "$(dirname "$STACK_ENV")"
  touch "$STACK_ENV"
  set_env_var_in_file "$STACK_ENV" "STACKER_AGENT_TOKEN" "$token"
  if [ -n "$api_url" ]; then
    set_env_var_in_file "$STACK_ENV" "STACKER_API_URL" "$api_url"
  fi
}

mask_token() {
  token="$1"
  len="${#token}"
  if [ "$len" -le 8 ]; then
    printf '%s' '********'
    return
  fi
  prefix="$(printf '%.4s' "$token")"
  suffix="$(printf '%s' "$token" | tail -c 5)"
  printf '%s...%s' "$prefix" "$suffix"
}

# POSIX: sem arrays bash — wrapper para docker compose
dc() {
  # stack.env primeiro; .env depois só para STACKER_*.
  # GETFY_DB_* devem estar sincronizados (ensure-db-credentials / fix espelha stack→.env).
  unset GETFY_DB_CONNECTION GETFY_DB_HOST GETFY_DB_PORT GETFY_DB_DATABASE GETFY_DB_USERNAME GETFY_DB_PASSWORD 2>/dev/null || true
  docker compose -p "$PROJECT" --project-directory "$HOST_DIR" \
    -f "$COMPOSE_FILE" \
    --env-file "$STACK_ENV" \
    --env-file "$ENV_FILE" \
    "$@"
}

echo "=== Stacker Agent — diagnóstico e correção ==="
echo "Diretório: $ROOT_DIR"
echo ""

if [ ! -f "$STACK_ENV" ]; then
  echo "Erro: $STACK_ENV não encontrado. Rode install/update ou crie o stack Docker." >&2
  exit 1
fi

if [ -f docker/ensure-host-dotenv.sh ]; then
  echo "→ Sincronizando .docker/stack.env → .env ..."
  if ! sh docker/ensure-host-dotenv.sh; then
    echo ""
    echo "Falha: STACKER_AGENT_TOKEN ausente." >&2
    echo "Obtenha em Stacker → Gateway → Instalações → token da instalação." >&2
    echo "Depois edite $ENV_FILE ou rode: bash docker/prompt-stacker-agent-token.sh" >&2
    exit 1
  fi
elif [ ! -f "$ENV_FILE" ]; then
  echo "Erro: $ENV_FILE não encontrado." >&2
  exit 1
fi

TOKEN_DOTENV="$(read_env_var "$ENV_FILE" STACKER_AGENT_TOKEN)"
TOKEN_STACK="$(read_env_var "$STACK_ENV" STACKER_AGENT_TOKEN)"
API_URL="$(read_env_var "$ENV_FILE" STACKER_API_URL)"
APP_URL="$(read_env_var "$ENV_FILE" APP_URL)"
if [ -z "$APP_URL" ]; then
  APP_URL="$(read_env_var "$ENV_FILE" GETFY_APP_URL)"
fi
if [ -z "$API_URL" ]; then
  API_URL="$(read_env_var "$STACK_ENV" STACKER_API_URL)"
fi
[ -z "$API_URL" ] && API_URL="https://api.stacker.builders"

# Token no .env mas ausente/diferente no stack.env — compose interpola STACKER_AGENT_TOKEN daí
if [ -n "$TOKEN_DOTENV" ] && [ "$TOKEN_DOTENV" != "$TOKEN_STACK" ]; then
  echo "→ Gravando STACKER_AGENT_TOKEN em .docker/stack.env ..."
  sync_token_to_stack_env "$TOKEN_DOTENV" "$API_URL"
  TOKEN_STACK="$(read_env_var "$STACK_ENV" STACKER_AGENT_TOKEN)"
fi

echo ""
echo "--- Configuração ---"
if [ -n "$TOKEN_DOTENV" ]; then
  echo "Token (.env):     $(mask_token "$TOKEN_DOTENV") (${#TOKEN_DOTENV} chars)"
else
  echo "Token (.env):     AUSENTE"
fi
if [ -n "$TOKEN_STACK" ]; then
  echo "Token (stack.env): $(mask_token "$TOKEN_STACK") (${#TOKEN_STACK} chars)"
else
  echo "Token (stack.env): AUSENTE"
fi
echo "API:              $API_URL"
echo "APP_URL:          ${APP_URL:-não definido}"

if [ -z "$TOKEN_DOTENV" ]; then
  echo ""
  echo "Erro: configure STACKER_AGENT_TOKEN em $ENV_FILE" >&2
  echo "Painel Stacker → Gateway → Instalações" >&2
  exit 1
fi

if [ -z "$TOKEN_STACK" ]; then
  echo ""
  echo "Erro: token não chegou em $STACK_ENV" >&2
  exit 1
fi

if [ -f "$LICENSE_FILE" ]; then
  CACHED_AT="$(grep -o '"cachedAt"[[:space:]]*:[[:space:]]*"[^"]*"' "$LICENSE_FILE" 2>/dev/null | head -1 | sed 's/.*"\([^"]*\)"$/\1/' || true)"
  echo "Licença cache:    ${CACHED_AT:-arquivo presente}"
else
  echo "Licença cache:    ausente (normal se nunca conectou)"
fi

HOST_DIR="${GETFY_HOST_DIR:-$ROOT_DIR}"
PROJECT="${GETFY_COMPOSE_PROJECT_NAME:-getfy}"
COMPOSE_FILE="$(sh docker/detect-compose-files.sh 2>/dev/null || echo 'docker-compose.yml')"

AGENT_CID="$(dc ps -q stacker-agent 2>/dev/null | head -1 || true)"
AGENT_HEALTH="missing"
if [ -z "$AGENT_CID" ]; then
  AGENT_CID="$(docker ps -aq --filter "name=${PROJECT}-stacker-agent" 2>/dev/null | head -1 || true)"
fi

echo ""
echo "--- Container ---"
if [ -n "$AGENT_CID" ]; then
  AGENT_HEALTH="$(docker inspect -f '{{if .State.Restarting}}restarting{{else}}{{.State.Status}}{{end}}' "$AGENT_CID" 2>/dev/null || echo unknown)"
  echo "ID:               $AGENT_CID"
  echo "Status:           $AGENT_HEALTH"
  echo ""
  echo "Últimas linhas do log:"
  dc logs stacker-agent --tail 15 2>/dev/null \
    || docker logs "$AGENT_CID" --tail 15 2>/dev/null \
    || true
else
  echo "stacker-agent:    não encontrado (parado ou nunca criado)"
fi

if [ "$CHECK_ONLY" -eq 1 ]; then
  echo ""
  echo "Modo --check-only: nenhuma alteração feita."
  if [ "$AGENT_HEALTH" = "running" ]; then
    exit 0
  fi
  exit 1
fi

echo ""
echo "→ Recriando stacker-agent ..."
if [ "$REBUILD" -eq 1 ]; then
  echo "→ Rebuild da imagem ..."
  dc build stacker-agent
fi

dc up -d --no-deps --force-recreate stacker-agent

echo ""
echo "→ Aguardando heartbeat (até 45s) ..."
ATTEMPTS=0
MAX_ATTEMPTS=9
OK=0
while [ "$ATTEMPTS" -lt "$MAX_ATTEMPTS" ]; do
  sleep 5
  ATTEMPTS=$((ATTEMPTS + 1))

  AGENT_CID="$(dc ps -q stacker-agent 2>/dev/null | head -1 || true)"
  if [ -z "$AGENT_CID" ]; then
    continue
  fi

  STATE="$(docker inspect -f '{{if .State.Restarting}}restarting{{else}}{{.State.Status}}{{end}}' "$AGENT_CID" 2>/dev/null || echo unknown)"
  if [ "$STATE" = "restarting" ] || [ "$STATE" = "exited" ]; then
    echo "  [$((ATTEMPTS * 5))s] container em $STATE — verificando logs..."
    LOG_TAIL="$(dc logs stacker-agent --tail 5 2>/dev/null || true)"
    if printf '%s' "$LOG_TAIL" | grep -q 'STACKER_AGENT_TOKEN não configurado'; then
      echo ""
      echo "Erro: token não chegou ao container. Confira .env e .docker/stack.env." >&2
      echo "$LOG_TAIL"
      exit 2
    fi
    continue
  fi

  LOG_TAIL="$(dc logs stacker-agent --tail 20 2>/dev/null || true)"
  if printf '%s' "$LOG_TAIL" | grep -q 'Stacker Agent '; then
    if ! printf '%s' "$LOG_TAIL" | grep -q 'Heartbeat falhou'; then
      OK=1
      break
    fi
  fi

  if [ -f "$LICENSE_FILE" ]; then
    LICENSE_MTIME="$(stat -c %Y "$LICENSE_FILE" 2>/dev/null || stat -f %m "$LICENSE_FILE" 2>/dev/null || echo 0)"
    NOW="$(date +%s)"
    AGE=$((NOW - LICENSE_MTIME))
    if [ "$AGE" -lt 60 ]; then
      OK=1
      break
    fi
  fi
done

echo ""
echo "Logs recentes:"
dc logs stacker-agent --tail 25 2>/dev/null \
  || docker logs "${PROJECT}-stacker-agent-1" --tail 25 2>/dev/null \
  || true

echo ""
if [ "$OK" -eq 1 ]; then
  echo "OK — agente rodando. O painel Stacker deve marcar online em ~1–2 min."
  exit 0
fi

echo "Aviso: container subiu, mas heartbeat ainda não confirmado." >&2
echo "Verifique:" >&2
echo "  1. Token correto no painel (Gateway → Instalações)" >&2
echo "  2. Saída HTTPS para $API_URL" >&2
echo "  3. Logs: docker logs ${PROJECT}-stacker-agent-1 --tail 50" >&2
exit 3
