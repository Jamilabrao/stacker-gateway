#!/usr/bin/env sh
# Health check pós-atualização / pós-up.
# Valida app, Postgres, Redis (se aplicável), HTTP e workers/scheduler do perfil ativo.
#
# Uso: sh docker/post-update-healthcheck.sh [compose-files]
# Exit 0 = OK; Exit 1 = falha (imprime diagnóstico + comandos recomendados).
set -eu

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT_DIR"

ENV_FILE=".docker/stack.env"
if [ ! -f "$ENV_FILE" ]; then
  echo "[health] ERRO: $ENV_FILE não encontrado." >&2
  exit 1
fi

COMPOSE_FILES="${1:-${GETFY_COMPOSE_FILES:-}}"
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
  [ -n "$f" ] || continue
  COMPOSE_ARGS="$COMPOSE_ARGS -f $f"
done
IFS="$OLD_IFS"

unset GETFY_DB_CONNECTION GETFY_DB_HOST GETFY_DB_PORT GETFY_DB_DATABASE GETFY_DB_USERNAME GETFY_DB_PASSWORD 2>/dev/null || true

dc() {
  # shellcheck disable=SC2086
  docker compose $COMPOSE_ARGS --env-file "$ENV_FILE" "$@"
}

PROFILE=""
if [ -f .docker/compose-profile ]; then
  PROFILE="$(tr -d ' \t\r\n' < .docker/compose-profile)"
fi
if [ -z "$PROFILE" ]; then
  case "$COMPOSE_FILES" in
    *caddy*) PROFILE="caddy" ;;
    *no-redis*) PROFILE="no-redis" ;;
    *) PROFILE="standard" ;;
  esac
fi

ERRORS=0
FAILURES=""

fail() {
  msg="$1"
  echo "[health] FALHA: $msg" >&2
  ERRORS=$((ERRORS + 1))
  FAILURES="${FAILURES}
  - ${msg}"
}

ok() {
  echo "[health] OK: $1"
}

echo "=== Health check pós-atualização ==="
echo "Compose: $COMPOSE_FILES"
echo "Perfil:  $PROFILE"
echo ""

service_in_compose() {
  dc config --services 2>/dev/null | grep -qx "$1"
}

is_running() {
  svc="$1"
  cid="$(dc ps -q "$svc" 2>/dev/null | head -1 || true)"
  [ -n "$cid" ] || return 1
  state="$(docker inspect -f '{{.State.Status}}' "$cid" 2>/dev/null || true)"
  [ "$state" = "running" ]
}

is_healthy_or_running() {
  svc="$1"
  cid="$(dc ps -q "$svc" 2>/dev/null | head -1 || true)"
  [ -n "$cid" ] || return 1
  status="$(docker inspect -f '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' "$cid" 2>/dev/null || true)"
  case "$status" in
    healthy|running) return 0 ;;
    starting)
      # Healthcheck ainda aquecendo — aceita se o processo está up.
      state="$(docker inspect -f '{{.State.Status}}' "$cid" 2>/dev/null || true)"
      [ "$state" = "running" ]
      ;;
    *) return 1 ;;
  esac
}

# --- Containers críticos ---
if service_in_compose app; then
  if is_running app; then
    ok "container app running"
  else
    fail "container app não está running"
  fi
else
  fail "serviço app ausente no compose ativo"
fi

if service_in_compose postgres; then
  if is_healthy_or_running postgres; then
    ok "PostgreSQL healthy/running"
  else
    fail "PostgreSQL não está healthy/running"
  fi
fi

if service_in_compose redis; then
  if is_healthy_or_running redis; then
    ok "Redis healthy/running"
  else
    fail "Redis não está healthy/running"
  fi
else
  ok "Redis não faz parte deste perfil ($PROFILE) — pulado"
fi

# Workers / scheduler conforme perfil
case "$PROFILE" in
  standard)
    for svc in scheduler worker-payments worker-webhooks-out worker-webhooks-in worker-payouts; do
      if service_in_compose "$svc"; then
        if is_running "$svc"; then
          ok "$svc running"
        else
          fail "$svc não está running"
        fi
      fi
    done
    ;;
  caddy)
    for svc in caddy queue; do
      if service_in_compose "$svc"; then
        if is_running "$svc"; then
          ok "$svc running"
        else
          fail "$svc não está running"
        fi
      fi
    done
    ;;
  no-redis)
    if service_in_compose queue; then
      if is_running queue; then
        ok "queue running"
      else
        fail "queue não está running"
      fi
    fi
    ;;
esac

# --- HTTP ---
echo ""
HTTP_OK=0
HTTP_CODE=""

# Preferência: via caddy na porta publicada, senão via app no host, senão exec interno.
if command -v curl >/dev/null 2>&1; then
  HTTP_CODE="$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 http://127.0.0.1/ 2>/dev/null || echo '000')"
  case "$HTTP_CODE" in
    2??|3??)
      ok "HTTP local respondeu $HTTP_CODE"
      HTTP_OK=1
      ;;
  esac
fi

if [ "$HTTP_OK" -ne 1 ] && is_running app; then
  if dc exec -T app php -r "\$c=@file_get_contents('http://127.0.0.1/up'); exit((\$c===false||\$c==='')?1:0);" 2>/dev/null; then
    ok "HTTP interno /up respondeu"
    HTTP_OK=1
  elif dc exec -T app php -r "\$h=@get_headers('http://127.0.0.1/', 1); exit((\$h===false)?1:0);" 2>/dev/null; then
    ok "HTTP interno / respondeu (headers)"
    HTTP_OK=1
  fi
fi

if [ "$HTTP_OK" -ne 1 ]; then
  fail "HTTP da aplicação não respondeu 2xx/3xx (código local: ${HTTP_CODE:-n/a})"
fi

echo ""
if [ "$ERRORS" -gt 0 ]; then
  echo "[health] RESUMO: $ERRORS componente(s) falhou(aram):$FAILURES" >&2
  echo "" >&2
  echo "Comandos recomendados para diagnóstico:" >&2
  echo "  cd \"$ROOT_DIR\"" >&2
  echo "  docker compose $COMPOSE_ARGS --env-file $ENV_FILE ps -a" >&2
  echo "  docker compose $COMPOSE_ARGS --env-file $ENV_FILE logs app --tail 80" >&2
  echo "  docker compose $COMPOSE_ARGS --env-file $ENV_FILE logs postgres --tail 40" >&2
  if service_in_compose redis; then
    echo "  docker compose $COMPOSE_ARGS --env-file $ENV_FILE logs redis --tail 40" >&2
  fi
  if [ "$PROFILE" = "caddy" ]; then
    echo "  docker compose $COMPOSE_ARGS --env-file $ENV_FILE logs caddy --tail 40" >&2
  fi
  echo "  sh docker/diagnose-stack.sh" >&2
  echo "  sh docker/recover-stack.sh" >&2
  exit 1
fi

echo "[health] Todos os checks passaram."
exit 0
