#!/usr/bin/env sh
# Remove containers de serviços que NÃO fazem parte do compose ativo,
# ANTES do `docker compose up`, para evitar conflito de portas.
#
# Caso típico: migração Caddy → app publicando :80 — getfy-caddy-1 órfão
# mantém 80/443 e o novo app falha ao bindar; --remove-orphans no up
# não chega a limpar se o start do app falhar antes.
#
# Seguro por desenho:
#   - Nunca remove volumes (postgres_data, redis, storage, caddy_data, etc.)
#   - Nunca altera .env / .docker/stack.env / credenciais
#   - Nunca remove serviços do compose/perfil ativo (EXPECTED)
#   - Nunca remove postgres/app (defesa em profundidade)
#   - Só remove candidatos conhecidos de transição (caddy/queue/workers/scheduler)
#   - Preserva instalações Caddy válidas (serviço caddy no compose → intocado)
#
# Modo:
#   remove (padrão) — stop + rm (comportamento completo)
#   stop            — só para (libera portas; permite rollback com docker start)
# Dry-run: GETFY_ORPHANS_DRY_RUN=1
set -eu

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT_DIR"

ENV_FILE="${1:-.docker/stack.env}"
COMPOSE_FILES="${2:-${GETFY_COMPOSE_FILES:-}}"
DRY_RUN="${GETFY_ORPHANS_DRY_RUN:-0}"
ORPHANS_MODE="${GETFY_ORPHANS_MODE:-remove}"
STOPPED_LIST_FILE="${GETFY_ORPHANS_STOPPED_LIST:-}"
case "$ORPHANS_MODE" in
  stop|remove) ;;
  *) ORPHANS_MODE="remove" ;;
esac

if [ -z "$COMPOSE_FILES" ]; then
  if [ -f docker/detect-compose-files.sh ]; then
    COMPOSE_FILES="$(sh docker/detect-compose-files.sh)"
  else
    COMPOSE_FILES="docker-compose.yml"
  fi
fi

COMPOSE_ARGS=""
OLD_IFS="$IFS"
IFS=' '
# shellcheck disable=SC2086
for f in $COMPOSE_FILES; do
  if [ -n "$f" ]; then
    # Recusa path traversal / arquivos inexistentes — evita EXPECTED errado.
    case "$f" in
      *..*)
        echo "[orphans] compose file inválido: $f — abortando limpeza." >&2
        exit 0
        ;;
    esac
    if [ ! -f "$f" ]; then
      echo "[orphans] compose file ausente: $f — abortando limpeza (fail-safe)." >&2
      exit 0
    fi
    COMPOSE_ARGS="$COMPOSE_ARGS -f $f"
  fi
done
IFS="$OLD_IFS"

if [ -z "$COMPOSE_ARGS" ]; then
  echo "[orphans] nenhum compose file — pulando."
  exit 0
fi

if [ ! -f "$ENV_FILE" ]; then
  echo "[orphans] $ENV_FILE ausente — pulando limpeza de órfãos."
  exit 0
fi

read_stack_kv() {
  key="$1"
  grep -E "^[[:space:]]*${key}[[:space:]]*=" "$ENV_FILE" 2>/dev/null | tail -1 | cut -d= -f2- | tr -d '\r' | sed 's/^[[:space:]]*//;s/[[:space:]]*$//;s/^"//;s/"$//;s/^'"'"'//;s/'"'"'$//' || true
}

PROJECT="${GETFY_COMPOSE_PROJECT_NAME:-}"
if [ -z "$PROJECT" ]; then
  PROJECT="$(read_stack_kv GETFY_COMPOSE_PROJECT_NAME)"
fi
if [ -z "$PROJECT" ]; then
  PROJECT="$(basename "$ROOT_DIR")"
fi
# Compose normaliza o nome do projeto para minúsculas.
PROJECT="$(printf '%s' "$PROJECT" | tr '[:upper:]' '[:lower:]' | tr -cd 'a-z0-9_-')"
if [ -z "$PROJECT" ]; then
  echo "[orphans] nome de projeto inválido — pulando."
  exit 0
fi

# Serviços esperados no compose ativo (um por linha).
# shellcheck disable=SC2086
EXPECTED="$(docker compose $COMPOSE_ARGS --env-file "$ENV_FILE" config --services 2>/dev/null || true)"
if [ -z "$EXPECTED" ]; then
  echo "[orphans] não foi possível listar serviços do compose — pulando (fail-safe)."
  exit 0
fi

# Defesa em profundidade: nunca tocar nestes serviços, mesmo se ausentes do EXPECTED
# (compose malformado / detecção errada não pode derrubar o banco ou o app).
is_never_remove() {
  case "$1" in
    postgres|app) return 0 ;;
    *) return 1 ;;
  esac
}

# Só removemos serviços conhecidos de troca de perfil (não serviços custom/desconhecidos).
is_known_transition_orphan() {
  case "$1" in
    caddy|queue|scheduler|worker) return 0 ;;
    worker-*) return 0 ;;
    *) return 1 ;;
  esac
}

service_expected() {
  svc="$1"
  printf '%s\n' "$EXPECTED" | grep -qx "$svc"
}

REMOVED=0
SKIPPED_PROTECTED=0
# Containers do projeto (running + exited), via label oficial do Compose.
for cid in $(docker ps -aq --filter "label=com.docker.compose.project=${PROJECT}" 2>/dev/null || true); do
  [ -n "$cid" ] || continue
  svc="$(docker inspect -f '{{index .Config.Labels "com.docker.compose.service"}}' "$cid" 2>/dev/null || true)"
  name="$(docker inspect -f '{{.Name}}' "$cid" 2>/dev/null | sed 's#^/##' || true)"
  [ -n "$svc" ] || continue

  if service_expected "$svc"; then
    continue
  fi

  if is_never_remove "$svc"; then
    echo "[orphans] PROTEGIDO (não remove): ${name:-$cid} (serviço '$svc')"
    SKIPPED_PROTECTED=$((SKIPPED_PROTECTED + 1))
    continue
  fi

  if ! is_known_transition_orphan "$svc"; then
    echo "[orphans] ignorando serviço desconhecido (não é órfão de transição): ${name:-$cid} ($svc)"
    continue
  fi

  if [ "$DRY_RUN" = "1" ]; then
    echo "[orphans] DRY-RUN removeria: ${name:-$cid} (serviço '$svc' ausente em: $COMPOSE_FILES)"
    REMOVED=$((REMOVED + 1))
    continue
  fi

  if [ "$ORPHANS_MODE" = "stop" ]; then
    echo "[orphans] parando órfão (rollback possível): ${name:-$cid} (serviço '$svc')"
    docker stop "$cid" >/dev/null 2>&1 || true
    if [ -n "$STOPPED_LIST_FILE" ]; then
      echo "$cid" >> "$STOPPED_LIST_FILE"
    fi
    REMOVED=$((REMOVED + 1))
    continue
  fi

  echo "[orphans] removendo container órfão: ${name:-$cid} (serviço '$svc' ausente em: $COMPOSE_FILES)"
  docker stop "$cid" >/dev/null 2>&1 || true
  docker rm -f "$cid" >/dev/null 2>&1 || true
  REMOVED=$((REMOVED + 1))
done

if [ "$REMOVED" -gt 0 ]; then
  if [ "$DRY_RUN" = "1" ]; then
    echo "[orphans] DRY-RUN: $REMOVED candidato(s). Volumes e serviços do compose ativo intactos."
  elif [ "$ORPHANS_MODE" = "stop" ]; then
    echo "[orphans] $REMOVED container(es) órfão(s) parado(s). Volumes preservados; rm após up OK."
  else
    echo "[orphans] $REMOVED container(es) órfão(s) removido(s). Volumes preservados."
  fi
else
  echo "[orphans] nenhum container órfão elegível para o projeto '$PROJECT'."
fi
if [ "$SKIPPED_PROTECTED" -gt 0 ]; then
  echo "[orphans] $SKIPPED_PROTECTED container(es) protegido(s) preservado(s)."
fi
