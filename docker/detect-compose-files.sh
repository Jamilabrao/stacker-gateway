#!/usr/bin/env sh
# Imprime o valor de GETFY_COMPOSE_FILES a usar no deploy (uma linha, sem newline extra).
#
# Prioridade:
#   1) GETFY_COMPOSE_FILES (env)
#   2) .docker/compose-profile (standard|caddy|no-redis) — fonte de verdade da instalação
#   3) GETFY_COMPOSE_FILES em .docker/stack.env (se definido e conhecido)
#   4) Heurística: volume caddy_data OU container caddy em execução
#   5) docker-compose.yml
#
# Nota: um container caddy órfão NÃO deve sobrescrever compose-profile=standard.
# A limpeza de órfãos (remove-stale-compose-orphans.sh) libera portas antes do up.
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

# Sem perfil: se stack.env já fixou o compose, respeita (evita flip por caddy órfão).
if [ -f .docker/stack.env ]; then
  STACK_COMPOSE="$(grep -E '^[[:space:]]*GETFY_COMPOSE_FILES[[:space:]]*=' .docker/stack.env 2>/dev/null | tail -1 | cut -d= -f2- | tr -d '\r' | tr -d '"' | tr -d "'" | sed 's/^[[:space:]]*//;s/[[:space:]]*$//' || true)"
  case "$STACK_COMPOSE" in
    docker-compose.yml|docker-compose.caddy.yml|docker-compose.no-redis.yml)
      printf '%s' "$STACK_COMPOSE"
      exit 0
      ;;
  esac
fi

if command -v docker >/dev/null 2>&1; then
  if docker volume ls --format '{{.Name}}' 2>/dev/null | grep -q 'caddy_data'; then
    printf '%s' "docker-compose.caddy.yml"
    exit 0
  fi
  if docker ps --format '{{.Names}}' 2>/dev/null | grep -qi 'caddy'; then
    printf '%s' "docker-compose.caddy.yml"
    exit 0
  fi
fi

printf '%s' "docker-compose.yml"
