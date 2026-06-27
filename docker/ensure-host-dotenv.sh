#!/usr/bin/env sh
# Garante .env na raiz — docker compose exige para env_file do stacker-agent (--project-directory).
# Uso: sh docker/ensure-host-dotenv.sh [HOST_DIR]
set -eu

HOST_DIR="${1:-$(cd "$(dirname "$0")/.." && pwd)}"
STACK_ENV="$HOST_DIR/.docker/stack.env"
DOTENV="$HOST_DIR/.env"

if [ ! -f "$STACK_ENV" ]; then
  echo "ensure-host-dotenv: $STACK_ENV ausente" >&2
  exit 1
fi

if [ -f "$DOTENV" ] && [ -s "$DOTENV" ]; then
  exit 0
fi

unset GETFY_DB_CONNECTION GETFY_DB_HOST GETFY_DB_PORT GETFY_DB_DATABASE GETFY_DB_USERNAME GETFY_DB_PASSWORD 2>/dev/null || true
set -a
# shellcheck disable=SC1091
. "$STACK_ENV"
set +a

{
  echo "GETFY_DB_CONNECTION=${GETFY_DB_CONNECTION:-pgsql}"
  echo "GETFY_DB_HOST=${GETFY_DB_HOST:-postgres}"
  echo "GETFY_DB_PORT=${GETFY_DB_PORT:-5432}"
  echo "GETFY_DB_DATABASE=${GETFY_DB_DATABASE:-getfy}"
  echo "GETFY_DB_USERNAME=${GETFY_DB_USERNAME:-getfy}"
  echo "GETFY_DB_PASSWORD=${GETFY_DB_PASSWORD:-getfy}"
  echo "GETFY_APP_URL=${GETFY_APP_URL:-http://localhost}"
} > "$DOTENV"

cid="$(docker ps -q --filter 'name=stacker-agent' 2>/dev/null | head -1 || true)"
if [ -n "$cid" ]; then
  docker inspect -f '{{range .Config.Env}}{{println .}}{{end}}' "$cid" 2>/dev/null \
    | grep -E '^STACKER_' >> "$DOTENV" || true
fi

chmod 600 "$DOTENV" 2>/dev/null || true
echo "ensure-host-dotenv: criado $DOTENV a partir de stack.env"
