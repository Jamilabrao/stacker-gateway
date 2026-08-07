#!/usr/bin/env sh
# Garante GETFY_DB_USERNAME/PASSWORD em .docker/stack.env de forma segura no update/install.
#
# Regras:
# - Nunca gera USER aleatório se já existir volume/dados Postgres (quebra 521/522).
# - Não apaga volumes nem roda compose down -v.
# - Se faltarem credenciais com cluster existente: preenche defaults seguros (getfy)
#   e, se necessário, redefine a senha do role via single-user para combinar com stack.env.
#
# Uso: sh docker/ensure-db-credentials.sh
#      (chamado por docker/up.sh)
set -eu

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT_DIR"

ENV_FILE=".docker/stack.env"
mkdir -p .docker

if [ ! -f "$ENV_FILE" ]; then
  echo "ensure-db-credentials: $ENV_FILE ausente (up.sh deve criar)." >&2
  exit 0
fi

read_kv() {
  file="$1"
  key="$2"
  grep -E "^[[:space:]]*${key}[[:space:]]*=" "$file" 2>/dev/null | tail -1 | cut -d= -f2- | tr -d '\r' | sed 's/^[[:space:]]*//;s/[[:space:]]*$//;s/^"//;s/"$//;s/^'"'"'//;s/'"'"'$//' || true
}

set_kv() {
  file="$1"
  key="$2"
  val="$3"
  if grep -Eq "^[[:space:]]*${key}[[:space:]]*=" "$file" 2>/dev/null; then
    tmp="$(mktemp)"
    awk -v k="$key" -v v="$val" '
      $0 ~ "^[[:space:]]*" k "[[:space:]]*=" { print k "=" v; next }
      { print }
    ' "$file" > "$tmp" && mv "$tmp" "$file"
  else
    echo "${key}=${val}" >> "$file"
  fi
}

DB_USER="$(read_kv "$ENV_FILE" GETFY_DB_USERNAME)"
DB_PASS="$(read_kv "$ENV_FILE" GETFY_DB_PASSWORD)"
DB_NAME="$(read_kv "$ENV_FILE" GETFY_DB_DATABASE)"
[ -n "$DB_NAME" ] || DB_NAME=getfy
set_kv "$ENV_FILE" GETFY_DB_DATABASE "$DB_NAME"
set_kv "$ENV_FILE" GETFY_DB_CONNECTION pgsql
set_kv "$ENV_FILE" GETFY_DB_HOST postgres
set_kv "$ENV_FILE" GETFY_DB_PORT 5432

postgres_volume() {
  # Prefer canonical production volume.
  if docker volume ls --format '{{.Name}}' 2>/dev/null | grep -qx 'getfy_postgres_data'; then
    echo getfy_postgres_data
    return 0
  fi
  docker volume ls --format '{{.Name}}' 2>/dev/null | grep '_postgres_data$' | grep -Ev '^(gateway|stacker-gateway)_' | head -1 || true
}

postgres_container() {
  for c in getfy-postgres-1 getfy_postgres_1; do
    if docker ps -a --format '{{.Names}}' 2>/dev/null | grep -qx "$c"; then
      echo "$c"
      return 0
    fi
  done
  return 1
}

pg_data_exists() {
  vol="$(postgres_volume)"
  [ -n "$vol" ] || return 1
  # Cluster já inicializado = PG_VERSION presente
  docker run --rm -v "${vol}:/var/lib/postgresql/data" alpine \
    test -f /var/lib/postgresql/data/PG_VERSION 2>/dev/null
}

try_recover_from_text() {
  text="$1"
  [ -n "$text" ] || return 1
  tmp="$(mktemp)"
  printf '%s\n' "$text" > "$tmp"
  u="$(read_kv "$tmp" GETFY_DB_USERNAME)"
  p="$(read_kv "$tmp" GETFY_DB_PASSWORD)"
  rm -f "$tmp"
  if [ -n "$u" ] && [ -n "$p" ]; then
    DB_USER="$u"
    DB_PASS="$p"
    return 0
  fi
  return 1
}

# --- Já tem credenciais? ---
if [ -n "$DB_USER" ] && [ -n "$DB_PASS" ]; then
  # Opcional: se postgres up, testa; se falhar e role for getfy, repara senha (não cria user novo).
  if c="$(postgres_container)" 2>/dev/null && docker ps --format '{{.Names}}' | grep -qx "$c"; then
    if ! docker exec -e PGPASSWORD="$DB_PASS" "$c" \
      psql -U "$DB_USER" -d "$DB_NAME" -c 'SELECT 1' >/dev/null 2>&1; then
      echo "ensure-db-credentials: login falhou com credenciais atuais (user=$DB_USER)." >&2
      if [ "$DB_USER" = "getfy" ] && pg_data_exists; then
        echo "ensure-db-credentials: redefinindo senha do role getfy para bater com stack.env..." >&2
        vol="$(postgres_volume)"
        docker stop "$c" >/dev/null 2>&1 || true
        printf "ALTER USER \"%s\" WITH PASSWORD '%s';\n" "$DB_USER" "$DB_PASS" | docker run --rm -i \
          -v "${vol}:/var/lib/postgresql/data" -u postgres postgres:16-alpine \
          postgres --single -D /var/lib/postgresql/data getfy >/dev/null 2>&1 || true
        docker start "$c" >/dev/null 2>&1 || true
        sleep 2
        if docker exec -e PGPASSWORD="$DB_PASS" "$c" \
          psql -U "$DB_USER" -d "$DB_NAME" -c 'SELECT 1' >/dev/null 2>&1; then
          echo "ensure-db-credentials: senha do role getfy alinhada com stack.env."
        else
          echo "ensure-db-credentials: AVISO — ainda sem login. Rode sh docker/recover-stack.sh" >&2
        fi
      fi
    fi
  fi
  chmod 600 "$ENV_FILE" 2>/dev/null || true
  exit 0
fi

echo "ensure-db-credentials: USERNAME/PASSWORD incompletos em $ENV_FILE — recuperando sem regenerar user..."

# 1) Volume getfy_env (backup da 1ª instalação)
if command -v docker >/dev/null 2>&1; then
  for v in getfy_getfy_env; do
    raw="$(docker run --rm -v "${v}:/v" alpine cat /v/stack.env 2>/dev/null || true)"
    if try_recover_from_text "$raw"; then
      echo "ensure-db-credentials: restaurado de volume $v"
      break
    fi
  done
fi

# 2) .env na raiz
if { [ -z "$DB_USER" ] || [ -z "$DB_PASS" ]; } && [ -f .env ]; then
  u="$(read_kv .env GETFY_DB_USERNAME)"; [ -z "$u" ] && u="$(read_kv .env DB_USERNAME)"
  p="$(read_kv .env GETFY_DB_PASSWORD)"; [ -z "$p" ] && p="$(read_kv .env DB_PASSWORD)"
  [ -n "$u" ] && DB_USER="$u"
  [ -n "$p" ] && DB_PASS="$p"
  [ -n "$DB_USER" ] && [ -n "$DB_PASS" ] && echo "ensure-db-credentials: restaurado de .env"
fi

# 3) Cluster já existe → NUNCA inventar user aleatório. Use getfy (padrão histórico) + senha no stack.env.
if pg_data_exists; then
  if [ -z "$DB_USER" ]; then
    DB_USER=getfy
    echo "ensure-db-credentials: USERNAME vazio + Postgres existente → GETFY_DB_USERNAME=getfy"
  fi
  if [ -z "$DB_PASS" ]; then
    DB_PASS="$(tr -dc 'A-Za-z0-9' < /dev/urandom | head -c 32)"
    echo "ensure-db-credentials: PASSWORD vazio + Postgres existente → gerando senha e aplicando no role $DB_USER via single-user"
    vol="$(postgres_volume)"
    c="$(postgres_container 2>/dev/null || true)"
    [ -n "$c" ] && docker stop "$c" >/dev/null 2>&1 || true
    printf "ALTER USER \"%s\" WITH PASSWORD '%s';\n" "$DB_USER" "$DB_PASS" | docker run --rm -i \
      -v "${vol}:/var/lib/postgresql/data" -u postgres postgres:16-alpine \
      postgres --single -D /var/lib/postgresql/data getfy >/dev/null 2>&1 || \
    printf "ALTER USER \"%s\" WITH PASSWORD '%s';\n" "$DB_USER" "$DB_PASS" | docker run --rm -i \
      -v "${vol}:/var/lib/postgresql/data" -u postgres postgres:16-alpine \
      postgres --single -D /var/lib/postgresql/data postgres >/dev/null 2>&1 || true
    [ -n "$c" ] && docker start "$c" >/dev/null 2>&1 || true
  fi
  set_kv "$ENV_FILE" GETFY_DB_USERNAME "$DB_USER"
  set_kv "$ENV_FILE" GETFY_DB_PASSWORD "$DB_PASS"
  chmod 600 "$ENV_FILE" 2>/dev/null || true
  echo "ensure-db-credentials: stack.env atualizado (user=$DB_USER). Volume Postgres preservado."
  exit 0
fi

# 4) Instalação sem volume: só então gerar user aleatório (primeira subida).
if [ -z "$DB_USER" ] || [ -z "$DB_PASS" ]; then
  U="getfy_$(tr -dc 'a-z0-9' < /dev/urandom | head -c 8)"
  P="$(tr -dc 'A-Za-z0-9' < /dev/urandom | head -c 32)"
  [ -n "$DB_USER" ] || DB_USER="$U"
  [ -n "$DB_PASS" ] || DB_PASS="$P"
  set_kv "$ENV_FILE" GETFY_DB_USERNAME "$DB_USER"
  set_kv "$ENV_FILE" GETFY_DB_PASSWORD "$DB_PASS"
  echo "ensure-db-credentials: Postgres novo — credenciais geradas (user=$DB_USER)."
fi

chmod 600 "$ENV_FILE" 2>/dev/null || true
exit 0
