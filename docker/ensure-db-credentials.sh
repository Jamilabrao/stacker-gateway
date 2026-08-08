#!/usr/bin/env sh
# Credenciais PostgreSQL — multi-instalação / multi-VPS.
#
# POLÍTICA:
# - Instalação NOVA (sem cluster): gera GETFY_DB_USERNAME aleatório (getfy_xxxxxxxx) + senha.
# - Cluster EXISTENTE: NUNCA gera user novo, NUNCA rotaciona senha, NUNCA assume role "getfy".
# - Validade = autentica no Postgres (psql SELECT 1). Nome da role é irrelevante.
# - Prioridade: stack.env → .env → volume getfy_*_env → abortar se nenhum autenticar.
# - Após válido: sincroniza stack.env → .env → volume env.
#
# Uso: sh docker/ensure-db-credentials.sh
set -eu

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT_DIR"

ENV_FILE=".docker/stack.env"
DOTENV_FILE=".env"
mkdir -p .docker

if [ ! -f "$ENV_FILE" ]; then
  echo "[DB] $ENV_FILE ausente (up.sh deve criar)." >&2
  exit 0
fi

read_kv() {
  file="$1"
  key="$2"
  [ -f "$file" ] || return 0
  grep -E "^[[:space:]]*${key}[[:space:]]*=" "$file" 2>/dev/null | tail -1 | cut -d= -f2- | tr -d '\r' | sed 's/^[[:space:]]*//;s/[[:space:]]*$//;s/^"//;s/"$//;s/^'"'"'//;s/'"'"'$//' || true
}

set_kv() {
  file="$1"
  key="$2"
  val="$3"
  touch "$file" 2>/dev/null || true
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
# Nome do DATABASE (não da role). Default histórico do produto.
[ -n "$DB_NAME" ] || DB_NAME=getfy
set_kv "$ENV_FILE" GETFY_DB_DATABASE "$DB_NAME"
set_kv "$ENV_FILE" GETFY_DB_CONNECTION pgsql
set_kv "$ENV_FILE" GETFY_DB_HOST postgres
set_kv "$ENV_FILE" GETFY_DB_PORT 5432

postgres_volume() {
  if docker volume ls --format '{{.Name}}' 2>/dev/null | grep -qx 'getfy_postgres_data'; then
    echo getfy_postgres_data
    return 0
  fi
  docker volume ls --format '{{.Name}}' 2>/dev/null | grep '_postgres_data$' | grep -Ev '^(gateway|stacker-gateway)_' | head -1 || true
}

env_volume() {
  if docker volume ls --format '{{.Name}}' 2>/dev/null | grep -qx 'getfy_getfy_env'; then
    echo getfy_getfy_env
    return 0
  fi
  docker volume ls --format '{{.Name}}' 2>/dev/null | grep '_getfy_env$' | grep -Ev '^(gateway|stacker-gateway)_' | head -1 || true
}

postgres_container() {
  # Heurística de NOME DO CONTAINER (compose project), não da role SQL.
  project="$(read_kv "$ENV_FILE" GETFY_COMPOSE_PROJECT_NAME)"
  [ -n "$project" ] || project=getfy
  for c in "${project}-postgres-1" "${project}_postgres_1" getfy-postgres-1 getfy_postgres_1; do
    if docker ps -a --format '{{.Names}}' 2>/dev/null | grep -qx "$c"; then
      echo "$c"
      return 0
    fi
  done
  # Qualquer *-postgres-1 em execução (exceto projetos espúrios).
  found="$(docker ps --format '{{.Names}}' 2>/dev/null | grep -E 'postgres-1$' | grep -Ev '^(gateway|stacker-gateway)-' | head -1 || true)"
  if [ -n "$found" ]; then
    echo "$found"
    return 0
  fi
  return 1
}

pg_data_exists() {
  vol="$(postgres_volume)"
  [ -n "$vol" ] || return 1
  docker run --rm -v "${vol}:/var/lib/postgresql/data" alpine \
    test -f /var/lib/postgresql/data/PG_VERSION 2>/dev/null
}

pg_running() {
  c="$(postgres_container 2>/dev/null || true)"
  [ -n "$c" ] || return 1
  docker ps --format '{{.Names}}' 2>/dev/null | grep -qx "$c"
}

# ÚNICO critério de validade: autentica no cluster.
# Uso: validate_candidate USER PASS → exit 0 se OK.
validate_candidate() {
  user="$1"
  pass="$2"
  [ -n "$user" ] && [ -n "$pass" ] || return 1
  pg_running || return 1
  c="$(postgres_container)"
  docker exec -e PGPASSWORD="$pass" "$c" \
    psql -U "$user" -d "$DB_NAME" -c 'SELECT 1' >/dev/null 2>&1
}

# Espelha credenciais JÁ VALIDADAS: stack.env → .env → volume env.
sync_validated_credentials() {
  user="$1"
  pass="$2"
  set_kv "$ENV_FILE" GETFY_DB_USERNAME "$user"
  set_kv "$ENV_FILE" GETFY_DB_PASSWORD "$pass"
  set_kv "$ENV_FILE" GETFY_DB_DATABASE "$DB_NAME"
  set_kv "$ENV_FILE" GETFY_DB_CONNECTION pgsql
  set_kv "$ENV_FILE" GETFY_DB_HOST postgres
  set_kv "$ENV_FILE" GETFY_DB_PORT 5432
  chmod 600 "$ENV_FILE" 2>/dev/null || true

  if [ ! -f "$DOTENV_FILE" ]; then
    touch "$DOTENV_FILE"
  fi
  set_kv "$DOTENV_FILE" GETFY_DB_CONNECTION pgsql
  set_kv "$DOTENV_FILE" GETFY_DB_HOST postgres
  set_kv "$DOTENV_FILE" GETFY_DB_PORT 5432
  set_kv "$DOTENV_FILE" GETFY_DB_DATABASE "$DB_NAME"
  set_kv "$DOTENV_FILE" GETFY_DB_USERNAME "$user"
  set_kv "$DOTENV_FILE" GETFY_DB_PASSWORD "$pass"
  chmod 600 "$DOTENV_FILE" 2>/dev/null || true

  env_vol="$(env_volume 2>/dev/null || true)"
  if [ -n "$env_vol" ] && command -v docker >/dev/null 2>&1; then
    if ! docker run --rm -i -v "${env_vol}:/v" alpine sh -c 'cat > /v/stack.env && chmod 600 /v/stack.env' < "$ENV_FILE" >/dev/null 2>&1; then
      echo "[DB] aviso: não foi possível espelhar stack.env no volume $env_vol" >&2
    fi
  fi

  DB_USER="$user"
  DB_PASS="$pass"
}

# Espelha U/P do stack.env para secundárias SEM testar (postgres ainda down).
# Só usado quando cluster existe e stack.env já tem U+P — para .env fantasma não vencer no compose.
mirror_stack_to_secondaries() {
  user="$1"
  pass="$2"
  [ -n "$user" ] && [ -n "$pass" ] || return 1
  if [ ! -f "$DOTENV_FILE" ]; then
    touch "$DOTENV_FILE"
  fi
  set_kv "$DOTENV_FILE" GETFY_DB_CONNECTION pgsql
  set_kv "$DOTENV_FILE" GETFY_DB_HOST postgres
  set_kv "$DOTENV_FILE" GETFY_DB_PORT 5432
  set_kv "$DOTENV_FILE" GETFY_DB_DATABASE "$DB_NAME"
  set_kv "$DOTENV_FILE" GETFY_DB_USERNAME "$user"
  set_kv "$DOTENV_FILE" GETFY_DB_PASSWORD "$pass"
  chmod 600 "$DOTENV_FILE" 2>/dev/null || true

  env_vol="$(env_volume 2>/dev/null || true)"
  if [ -n "$env_vol" ] && command -v docker >/dev/null 2>&1; then
    docker run --rm -i -v "${env_vol}:/v" alpine sh -c 'cat > /v/stack.env && chmod 600 /v/stack.env' < "$ENV_FILE" >/dev/null 2>&1 || true
  fi
}

extract_creds_from_text_to_files() {
  text="$1"
  user_file="$2"
  pass_file="$3"
  [ -n "$text" ] || return 1
  tmp="$(mktemp)"
  printf '%s\n' "$text" > "$tmp"
  u="$(read_kv "$tmp" GETFY_DB_USERNAME)"
  [ -z "$u" ] && u="$(read_kv "$tmp" DB_USERNAME)"
  p="$(read_kv "$tmp" GETFY_DB_PASSWORD)"
  [ -z "$p" ] && p="$(read_kv "$tmp" DB_PASSWORD)"
  rm -f "$tmp"
  if [ -n "$u" ] && [ -n "$p" ]; then
    printf '%s' "$u" > "$user_file"
    printf '%s' "$p" > "$pass_file"
    return 0
  fi
  return 1
}

# Valida e, se OK, sincroniza. label só para log.
accept_if_valid() {
  label="$1"
  user="$2"
  pass="$3"
  [ -n "$user" ] && [ -n "$pass" ] || return 1
  if validate_candidate "$user" "$pass"; then
    echo "[DB] OK via $label (user=$user)."
    sync_validated_credentials "$user" "$pass"
    return 0
  fi
  echo "[DB] candidato inválido: $label (user=$user)." >&2
  return 1
}

abort_no_credentials() {
  echo "[DB] ERRO: nenhuma credencial conhecida autentica no cluster PostgreSQL existente." >&2
  echo "[DB] Update abortado para preservar o banco." >&2
  echo "[DB] Nenhuma role foi criada ou alterada." >&2
  exit 1
}

# --- Cluster existente + Postgres acessível ---
if pg_data_exists && pg_running; then
  # 1) stack.env (fonte principal) — se autenticar, vence; não troca por outro.
  if accept_if_valid "stack.env" "$DB_USER" "$DB_PASS"; then
    exit 0
  fi

  # 2) .env
  if [ -f "$DOTENV_FILE" ]; then
    u="$(read_kv "$DOTENV_FILE" GETFY_DB_USERNAME)"
    [ -z "$u" ] && u="$(read_kv "$DOTENV_FILE" DB_USERNAME)"
    p="$(read_kv "$DOTENV_FILE" GETFY_DB_PASSWORD)"
    [ -z "$p" ] && p="$(read_kv "$DOTENV_FILE" DB_PASSWORD)"
    if accept_if_valid ".env" "$u" "$p"; then
      exit 0
    fi
  fi

  # 3) volume env
  env_vol="$(env_volume 2>/dev/null || true)"
  if [ -n "$env_vol" ]; then
    raw="$(docker run --rm -v "${env_vol}:/v" alpine cat /v/stack.env 2>/dev/null || true)"
    uf="$(mktemp)"; pf="$(mktemp)"
    if extract_creds_from_text_to_files "$raw" "$uf" "$pf"; then
      cand_u="$(cat "$uf")"; cand_p="$(cat "$pf")"
      rm -f "$uf" "$pf"
      if accept_if_valid "volume $env_vol" "$cand_u" "$cand_p"; then
        exit 0
      fi
    else
      rm -f "$uf" "$pf"
    fi
  fi

  # 4) Nenhum candidato — abortar. Sem gerar user, sem reset de senha, sem adivinhar role.
  abort_no_credentials
fi

# --- Cluster existe, Postgres ainda down (pré-compose) ---
if pg_data_exists; then
  echo "[DB] volume Postgres existe; container ainda não está up."

  if [ -n "$DB_USER" ] && [ -n "$DB_PASS" ]; then
    # Preserva identidade desta instalação; espelha para .env não sobrescrever no compose.
    mirror_stack_to_secondaries "$DB_USER" "$DB_PASS"
    echo "[DB] mantendo stack.env (user=$DB_USER); validação completa após postgres up."
    exit 0
  fi

  # stack.env incompleto: pré-carregar candidatos (sem inventar role) para o compose subir postgres.
  recovered=0
  if [ -f "$DOTENV_FILE" ]; then
    u="$(read_kv "$DOTENV_FILE" GETFY_DB_USERNAME)"
    [ -z "$u" ] && u="$(read_kv "$DOTENV_FILE" DB_USERNAME)"
    p="$(read_kv "$DOTENV_FILE" GETFY_DB_PASSWORD)"
    [ -z "$p" ] && p="$(read_kv "$DOTENV_FILE" DB_PASSWORD)"
    if [ -n "$u" ] && [ -n "$p" ]; then
      DB_USER="$u"
      DB_PASS="$p"
      recovered=1
      echo "[DB] pré-carregado de .env (user=$DB_USER) — validará quando postgres subir."
    fi
  fi

  if [ "$recovered" -eq 0 ]; then
    env_vol="$(env_volume 2>/dev/null || true)"
    if [ -n "$env_vol" ]; then
      raw="$(docker run --rm -v "${env_vol}:/v" alpine cat /v/stack.env 2>/dev/null || true)"
      uf="$(mktemp)"; pf="$(mktemp)"
      if extract_creds_from_text_to_files "$raw" "$uf" "$pf"; then
        DB_USER="$(cat "$uf")"
        DB_PASS="$(cat "$pf")"
        recovered=1
        echo "[DB] pré-carregado do volume $env_vol (user=$DB_USER) — validará quando postgres subir."
      fi
      rm -f "$uf" "$pf"
    fi
  fi

  if [ -z "$DB_USER" ] || [ -z "$DB_PASS" ]; then
    echo "[DB] ERRO: cluster PostgreSQL existente, mas nenhuma credencial conhecida em stack.env/.env/volume." >&2
    echo "[DB] Update abortado para preservar o banco." >&2
    echo "[DB] Nenhuma role foi criada ou alterada." >&2
    exit 1
  fi

  set_kv "$ENV_FILE" GETFY_DB_USERNAME "$DB_USER"
  set_kv "$ENV_FILE" GETFY_DB_PASSWORD "$DB_PASS"
  mirror_stack_to_secondaries "$DB_USER" "$DB_PASS"
  echo "[DB] stack.env preenchido com candidato (user=$DB_USER). Validação obrigatória após postgres up."
  exit 0
fi

# --- Instalação nova (sem cluster): gerar user aleatório uma única vez ---
if [ -z "$DB_USER" ] || [ -z "$DB_PASS" ]; then
  U="getfy_$(tr -dc 'a-z0-9' < /dev/urandom | head -c 8)"
  P="$(tr -dc 'A-Za-z0-9' < /dev/urandom | head -c 32)"
  [ -n "$DB_USER" ] || DB_USER="$U"
  [ -n "$DB_PASS" ] || DB_PASS="$P"
  set_kv "$ENV_FILE" GETFY_DB_USERNAME "$DB_USER"
  set_kv "$ENV_FILE" GETFY_DB_PASSWORD "$DB_PASS"
  echo "[DB] Postgres novo — credenciais geradas (user=$DB_USER)."
fi

# Sem cluster ainda: só grava/espelha; a imagem Postgres cria a role no 1º start.
mirror_stack_to_secondaries "$DB_USER" "$DB_PASS"
exit 0
