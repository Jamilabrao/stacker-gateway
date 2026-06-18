#!/usr/bin/env sh
# Imprime o valor de GETFY_COMPOSE_FILES a usar no deploy (uma linha, sem newline extra).
set -eu

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT_DIR"

if [ -n "${GETFY_COMPOSE_FILES:-}" ]; then
  printf '%s' "$GETFY_COMPOSE_FILES"
  exit 0
fi

if [ -f .docker/compose-profile ]; then
  PROFILE="$(tr -d ' \t\r\n' < .docker/compose-profile)"
  case "$PROFILE" in
    standard)
      printf '%s' "docker-compose.yml"
      exit 0
      ;;
    caddy)
      printf '%s' "docker-compose.caddy.yml"
      exit 0
      ;;
    no-redis)
      printf '%s' "docker-compose.no-redis.yml"
      exit 0
      ;;
  esac
fi

if command -v docker >/dev/null 2>&1; then
  if docker volume ls --format '{{.Name}}' 2>/dev/null | grep -q 'caddy_data'; then
    printf '%s' "docker-compose.caddy.yml"
    exit 0
  fi
fi

printf '%s' "docker-compose.yml"
