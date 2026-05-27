#!/usr/bin/env sh
# Recuperação rápida quando o site está fora (522 / connection reset / timeout).
# Uso na VPS: cd /opt/getfy && sh docker/recover-stack.sh
set -eu

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT_DIR"

ENV_FILE=".docker/stack.env"
if [ ! -f "$ENV_FILE" ]; then
  echo "Erro: $ENV_FILE não encontrado. Rode install ou crie o stack.env." >&2
  exit 1
fi

echo "=== Getfy: recuperação do stack ==="
echo "Diretório: $ROOT_DIR"
echo ""

# Exports antigos na shell root sobrescrevem o --env-file e quebram o Postgres.
unset GETFY_DB_CONNECTION GETFY_DB_HOST GETFY_DB_PORT GETFY_DB_DATABASE GETFY_DB_USERNAME GETFY_DB_PASSWORD 2>/dev/null || true
set -a
# shellcheck disable=SC1091
. "$ENV_FILE"
set +a

COMPOSE_FILE="$(sh docker/detect-compose-files.sh 2>/dev/null || echo 'docker-compose.yml')"
echo "Compose detectado: $COMPOSE_FILE"
echo "GETFY_DB_USERNAME=${GETFY_DB_USERNAME:-?}"
echo "GETFY_DB_DATABASE=${GETFY_DB_DATABASE:-getfy}"
echo ""

echo "=== 1) Estado dos containers ==="
docker compose -f "$COMPOSE_FILE" --env-file "$ENV_FILE" ps -a 2>/dev/null || true
echo ""

echo "=== 2) Últimas linhas do app (procure 'Banco indisponível' ou 'role does not exist') ==="
docker compose -f "$COMPOSE_FILE" --env-file "$ENV_FILE" logs app --tail 40 2>/dev/null || docker logs getfy-app-1 --tail 40 2>/dev/null || true
echo ""

if [ "$COMPOSE_FILE" = "docker-compose.caddy.yml" ]; then
  echo "=== 3) Logs Caddy ==="
  docker compose -f "$COMPOSE_FILE" --env-file "$ENV_FILE" logs caddy --tail 25 2>/dev/null || true
  echo ""
fi

echo "=== 4) Teste PostgreSQL com credenciais do stack.env ==="
DB_USER="${GETFY_DB_USERNAME:-getfy}"
DB_NAME="${GETFY_DB_DATABASE:-getfy}"
PG_CONTAINER=""
for c in getfy-postgres-1 getfy_postgres_1; do
  if docker ps -a --format '{{.Names}}' | grep -qx "$c"; then
    PG_CONTAINER="$c"
    break
  fi
done
if [ -n "$PG_CONTAINER" ]; then
  if docker exec "$PG_CONTAINER" psql -U "$DB_USER" -d "$DB_NAME" -c 'SELECT 1' >/dev/null 2>&1; then
    echo "OK: psql -U $DB_USER -d $DB_NAME"
  else
    echo "FALHOU: $ENV_FILE não coincide com o volume Postgres (role inexistente ou senha errada)." >&2
  VOLUME_ENV="$(docker run --rm -v getfy_getfy_env:/v alpine cat /v/stack.env 2>/dev/null || true)"
  if [ -n "$VOLUME_ENV" ]; then
    echo ""
    echo "Credenciais no volume getfy_env (instalação original):" >&2
    echo "$VOLUME_ENV" | grep GETFY_DB_ || true
    echo ""
    echo "A restaurar $ENV_FILE a partir do volume..." >&2
    printf '%s\n' "$VOLUME_ENV" > "$ENV_FILE"
    chmod 600 "$ENV_FILE" 2>/dev/null || true
    unset GETFY_DB_CONNECTION GETFY_DB_HOST GETFY_DB_PORT GETFY_DB_DATABASE GETFY_DB_USERNAME GETFY_DB_PASSWORD 2>/dev/null || true
    set -a
    # shellcheck disable=SC1091
    . "$ENV_FILE"
    set +a
    DB_USER="${GETFY_DB_USERNAME:-getfy}"
    if docker exec "$PG_CONTAINER" psql -U "$DB_USER" -d "$DB_NAME" -c 'SELECT 1' >/dev/null 2>&1; then
      echo "OK após sync: psql -U $DB_USER -d $DB_NAME"
    else
      echo "Ainda falhou após sync. Roles no Postgres:" >&2
      docker exec "$PG_CONTAINER" psql -U postgres -d "$DB_NAME" -c '\du' 2>/dev/null || true
    fi
  else
    echo "  Volume getfy_getfy_env sem stack.env — ajuste GETFY_DB_* manualmente." >&2
    docker exec "$PG_CONTAINER" psql -U postgres -d "$DB_NAME" -c '\du' 2>/dev/null || true
  fi
  fi
else
  echo "Aviso: container postgres não encontrado." >&2
fi
echo ""

echo "=== 5) Sincronizar .env do host (Compose lê .env na raiz) ==="
if [ ! -f .env ] || [ ! -s .env ]; then
  {
    echo "GETFY_DB_CONNECTION=${GETFY_DB_CONNECTION:-pgsql}"
    echo "GETFY_DB_HOST=${GETFY_DB_HOST:-postgres}"
    echo "GETFY_DB_PORT=${GETFY_DB_PORT:-5432}"
    echo "GETFY_DB_DATABASE=${GETFY_DB_DATABASE:-getfy}"
    echo "GETFY_DB_USERNAME=${GETFY_DB_USERNAME}"
    echo "GETFY_DB_PASSWORD=${GETFY_DB_PASSWORD}"
    echo "GETFY_APP_URL=${GETFY_APP_URL:-http://localhost}"
  } > .env
  echo "Criado .env a partir de $ENV_FILE"
else
  echo ".env já existe ($(wc -c < .env | tr -d ' ') bytes)"
fi
echo ""

echo "=== 6) Subir stack completo (Caddy + app + postgres + redis + queue) ==="
docker compose -f "$COMPOSE_FILE" --env-file "$ENV_FILE" up -d --remove-orphans
echo ""

echo "Aguardando app (15s)..."
sleep 15
echo ""

echo "=== 7) Teste HTTP local ==="
if curl -sI --max-time 8 http://127.0.0.1/ 2>/dev/null | head -5; then
  echo ""
  echo "HTTP no servidor OK. Se o browser ainda mostra 522, o problema é Cloudflare → IP/porta do VPS."
else
  echo "HTTP local ainda falhou." >&2
  echo "Logs app:" >&2
  docker compose -f "$COMPOSE_FILE" --env-file "$ENV_FILE" logs app --tail 30 2>/dev/null || true
  exit 1
fi

echo ""
echo "=== Recuperação concluída ==="
echo "Se precisar atualizar código: bash -c \"\$(curl -fsSL https://raw.githubusercontent.com/LeonardoIsrael0516/getfy-gateway/main/update.sh)\""
