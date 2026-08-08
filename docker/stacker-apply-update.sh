#!/usr/bin/env bash
# Aplica release Stacker no host: deps, rebuild da imagem app e migrate.
# Chamado pelo stacker-agent após extrair o zip em /gateway (GETFY_DIR).
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT_DIR"

echo "=== Stacker apply update ==="
echo "Diretório: $ROOT_DIR"

ensure_php_uploads_ini() {
  local ini="$ROOT_DIR/docker/php/uploads.ini"
  if [ -e "$ini" ] && { [ -d "$ini" ] || [ ! -f "$ini" ]; }; then
    echo "Corrigindo docker/php/uploads.ini (não era arquivo regular)." >&2
    rm -rf "$ini"
  fi
  mkdir -p "$(dirname "$ini")"
  cat > "$ini" <<'EOF'
upload_max_filesize = 512M
post_max_size = 512M
memory_limit = 512M
max_execution_time = 300
EOF
  if [ ! -f "$ini" ] || [ -d "$ini" ]; then
    echo "FATAL: não foi possível criar $ini como arquivo." >&2
    exit 1
  fi
  if [ -f docker/ensure-upload-limits.sh ]; then
    sh docker/ensure-upload-limits.sh || true
  fi
}

# Primeiro: uploads.ini como arquivo (Docker cria pasta se faltar no compose up).
ensure_php_uploads_ini

if [ ! -f docker/detect-compose-files.sh ]; then
  echo "docker/detect-compose-files.sh ausente." >&2
  exit 1
fi

chmod +x docker/detect-compose-files.sh docker/build-frontend.sh docker/install-composer-deps.sh docker/ensure-upload-limits.sh docker/ensure-host-dotenv.sh 2>/dev/null || true

ENV_FILE=".docker/stack.env"
if [ ! -f "$ENV_FILE" ]; then
  echo ".docker/stack.env ausente — rode install/update legado uma volta." >&2
  exit 1
fi

# Nunca use `source`/`.` em stack.env: valores com espaço sem aspas
# (ex.: GETFY_COMPOSE_FILES=a.yml b.yml) viram "command not found" e abortam o apply.
load_stack_env_safe() {
  local file="$1"
  local line key val
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
    val="${val#\"}"
    val="${val%\"}"
    val="${val#\'}"
    val="${val%\'}"
    export "$key=$val"
  done < "$file"
}

# Corrige linha clássica que quebra o apply:
# GETFY_COMPOSE_FILES=docker-compose.yml docker-compose.dev.yml
sanitize_compose_files_in_stack_env() {
  local raw fixed tmp
  raw="$(grep -E '^[[:space:]]*GETFY_COMPOSE_FILES[[:space:]]*=' "$ENV_FILE" 2>/dev/null | tail -1 | cut -d= -f2- | tr -d '\r' || true)"
  raw="$(printf '%s' "$raw" | sed 's/^[[:space:]]*//;s/[[:space:]]*$//;s/^"//;s/"$//;s/^'\''//;s/'\''$//')"
  [ -n "$raw" ] || return 0

  # Em VPS de produção com Caddy, nunca manter compose.dev
  if docker ps --format '{{.Names}}' 2>/dev/null | grep -qi 'caddy' \
    || docker volume ls --format '{{.Name}}' 2>/dev/null | grep -q 'caddy_data'; then
    fixed="docker-compose.caddy.yml"
  elif printf '%s' "$raw" | grep -q 'docker-compose.no-redis.yml'; then
    fixed="docker-compose.no-redis.yml"
  elif printf '%s' "$raw" | grep -q 'docker-compose.caddy.yml'; then
    fixed="docker-compose.caddy.yml"
  else
    # pega só o primeiro .yml e descarta .dev
    fixed="$(printf '%s' "$raw" | tr ' ' '\n' | grep -E '\.yml$' | grep -v '\.dev\.yml$' | head -1 || true)"
    [ -n "$fixed" ] || fixed="docker-compose.yml"
  fi

  tmp="$(mktemp)"
  awk -v v="$fixed" '
    BEGIN { done=0 }
    $0 ~ /^[[:space:]]*GETFY_COMPOSE_FILES[[:space:]]*=/ {
      print "GETFY_COMPOSE_FILES=\"" v "\""
      done=1
      next
    }
    { print }
    END { if (!done) print "GETFY_COMPOSE_FILES=\"" v "\"" }
  ' "$ENV_FILE" > "$tmp"
  mv "$tmp" "$ENV_FILE"
  echo "GETFY_COMPOSE_FILES sanitizado: $fixed"
}

sanitize_compose_files_in_stack_env || true
load_stack_env_safe "$ENV_FILE"

COMPOSE_FILES="$(sh docker/detect-compose-files.sh)"
# Nunca persistir compose.dev em apply remoto
case "$COMPOSE_FILES" in
  *docker-compose.dev.yml*|*docker-compose.local-db.yml*)
    if docker ps --format '{{.Names}}' 2>/dev/null | grep -qi 'caddy' \
      || docker volume ls --format '{{.Name}}' 2>/dev/null | grep -q 'caddy_data'; then
      COMPOSE_FILES="docker-compose.caddy.yml"
    else
      COMPOSE_FILES="docker-compose.yml"
    fi
    ;;
esac

COMPOSE_ARGS=""
for f in $COMPOSE_FILES; do
  if [ -n "$f" ]; then
    COMPOSE_ARGS="$COMPOSE_ARGS -f $f"
  fi
done

persist_compose_files_in_stack_env() {
  local files="$1"
  local tmp
  # Sempre aspas — espaços no valor quebram `source` de scripts legados
  if grep -Eq '^\s*GETFY_COMPOSE_FILES\s*=' "$ENV_FILE" 2>/dev/null; then
    tmp="$(mktemp)"
    awk -v v="$files" '
      BEGIN { done=0 }
      $0 ~ /^[[:space:]]*GETFY_COMPOSE_FILES[[:space:]]*=/ {
        print "GETFY_COMPOSE_FILES=\"" v "\""
        done=1
        next
      }
      { print }
      END { if (!done) print "GETFY_COMPOSE_FILES=\"" v "\"" }
    ' "$ENV_FILE" > "$tmp"
    mv "$tmp" "$ENV_FILE"
  else
    echo "GETFY_COMPOSE_FILES=\"$files\"" >> "$ENV_FILE"
  fi
}

persist_compose_files_in_stack_env "$COMPOSE_FILES"

if [ "$COMPOSE_FILES" = "docker-compose.caddy.yml" ]; then
  mkdir -p .docker
  if [ ! -f .docker/compose-profile ] || [ "$(tr -d ' \t\r\n' < .docker/compose-profile)" != "caddy" ]; then
    echo "caddy" > .docker/compose-profile
  fi
fi

resolve_compose_project_name() {
  # Produção real tem volumes/containers getfy_* — nunca criar stacker-gateway paralelo.
  if docker volume ls --format '{{.Name}}' 2>/dev/null | grep -qx 'getfy_postgres_data'; then
    printf 'getfy'
    return
  fi
  if docker ps -a --format '{{.Names}}' 2>/dev/null | grep -Eqx 'getfy-app-1|getfy_app_1'; then
    printf 'getfy'
    return
  fi

  local configured
  configured="${GETFY_COMPOSE_PROJECT_NAME:-}"
  case "$configured" in
    gateway|stacker-gateway|stacker_gateway)
      configured=""
      ;;
  esac

  if [ -n "$configured" ]; then
    # Só confia no env se já existir container desse projeto
    if docker ps -a --format '{{.Names}}' 2>/dev/null | grep -Eq "^${configured}-(app|postgres)-1$"; then
      printf '%s' "$configured"
      return
    fi
  fi

  local running
  running="$(docker ps --format '{{.Names}}' 2>/dev/null | grep -E 'app-1$' | grep -Ev '^(gateway|stacker-gateway)-' | head -1 | sed 's/-app-1$//' || true)"
  if [ -n "$running" ]; then
    printf '%s' "$running"
    return
  fi

  local detected
  detected="$(docker volume ls --format '{{.Name}}' 2>/dev/null | grep '_postgres_data$' | grep -Ev '^(gateway|stacker-gateway)_' | head -1 | sed 's/_postgres_data$//' || true)"
  if [ -n "$detected" ]; then
    printf '%s' "$detected"
    return
  fi

  local base
  base="$(basename "$ROOT_DIR")"
  case "$base" in
    gateway|stacker-gateway) ;;
    *)
      if [ -n "$base" ]; then
        printf '%s' "$base"
        return
      fi
      ;;
  esac

  echo "GETFY_COMPOSE_PROJECT_NAME não definido em .docker/stack.env (ex.: getfy)." >&2
  exit 1
}

PROJECT_NAME="$(resolve_compose_project_name)"

if [ "$PROJECT_NAME" = "gateway" ] || [ "$PROJECT_NAME" = "stacker-gateway" ]; then
  if docker volume ls --format '{{.Name}}' 2>/dev/null | grep -qx 'getfy_postgres_data' \
    || docker ps -a --format '{{.Names}}' 2>/dev/null | grep -Eqx 'getfy-app-1|getfy_app_1'; then
    echo "Aviso: compose project '$PROJECT_NAME' ignorado — usando getfy (stack de produção)." >&2
    PROJECT_NAME=getfy
  fi
fi

export COMPOSE_PROJECT_NAME="$PROJECT_NAME"

# Persiste o nome correto (corrige stacker-gateway gravado por engano).
if grep -Eq '^\s*GETFY_COMPOSE_PROJECT_NAME\s*=' "$ENV_FILE" 2>/dev/null; then
  _pn_tmp="$(mktemp)"
  awk -v v="$PROJECT_NAME" '
    $0 ~ /^[[:space:]]*GETFY_COMPOSE_PROJECT_NAME[[:space:]]*=/ { print "GETFY_COMPOSE_PROJECT_NAME=" v; next }
    { print }
  ' "$ENV_FILE" > "$_pn_tmp"
  mv "$_pn_tmp" "$ENV_FILE"
else
  echo "GETFY_COMPOSE_PROJECT_NAME=$PROJECT_NAME" >> "$ENV_FILE"
fi
# limpa env exportado errado da load anterior
export GETFY_COMPOSE_PROJECT_NAME="$PROJECT_NAME"

resolve_compose_host_dir() {
  if [ -n "${GETFY_HOST_DIR:-}" ]; then
    printf '%s' "$GETFY_HOST_DIR"
    return
  fi
  if grep -Eq '^\s*GETFY_HOST_DIR\s*=' "$ENV_FILE" 2>/dev/null; then
    grep -E '^\s*GETFY_HOST_DIR\s*=' "$ENV_FILE" | tail -1 | sed 's/^[^=]*=\s*//' | tr -d ' "'\'''
    return
  fi
  # Apply roda dentro do stacker-agent (cwd /gateway); volumes relativos viram /gateway/... no host.
  if [ "$ROOT_DIR" = "/gateway" ] || [ "$(basename "$ROOT_DIR")" = "gateway" ]; then
    local cid src
    for cid in $(docker ps -q --filter 'name=stacker-agent' 2>/dev/null); do
      src="$(docker inspect -f '{{range .Mounts}}{{if eq .Destination "/gateway"}}{{.Source}}{{end}}{{end}}' "$cid" 2>/dev/null || true)"
      if [ -n "$src" ]; then
        printf '%s' "$src"
        return
      fi
    done
  fi
  printf '%s' "$ROOT_DIR"
}

HOST_DIR="$(resolve_compose_host_dir)"
if [ -z "$HOST_DIR" ]; then
  echo "GETFY_HOST_DIR não detectado." >&2
  exit 1
fi

if ! grep -Eq '^\s*GETFY_HOST_DIR\s*=' "$ENV_FILE" 2>/dev/null; then
  echo "GETFY_HOST_DIR=$HOST_DIR" >> "$ENV_FILE"
fi

ENV_FILE_ABS="$ROOT_DIR/.docker/stack.env"

# Apply dentro do stacker-agent: /opt/getfy não existe no container — só /gateway (bind mount).
if [ "$ROOT_DIR" = "/gateway" ] || [ ! -d "$HOST_DIR" ]; then
  COMPOSE_WORK_DIR="$ROOT_DIR"
else
  COMPOSE_WORK_DIR="$HOST_DIR"
fi

export COMPOSE_PROJECT_NAME="$PROJECT_NAME"

echo "Compose project: $COMPOSE_PROJECT_NAME"
echo "Compose host dir: $HOST_DIR"
echo "Compose work dir: $COMPOSE_WORK_DIR"
echo "Compose files: $COMPOSE_FILES"

if [ -f docker/ensure-host-dotenv.sh ]; then
  if ! sh docker/ensure-host-dotenv.sh; then
    echo "Aviso: ensure-host-dotenv falhou (token ausente?). Continuando rebuild do app." >&2
  fi
elif [ ! -f "$ROOT_DIR/.env" ]; then
  echo "Aviso: .env ausente — stacker-agent precisa de STACKER_AGENT_TOKEN em $ROOT_DIR/.env" >&2
fi

# Preserva / reconcilia GETFY_DB_* (nunca regenera user se volume Postgres existe).
# Falha = aborta o apply (evita recreate do app com user fantasma → 522).
unset GETFY_DB_CONNECTION GETFY_DB_HOST GETFY_DB_PORT GETFY_DB_DATABASE GETFY_DB_USERNAME GETFY_DB_PASSWORD 2>/dev/null || true
if [ -f docker/ensure-db-credentials.sh ]; then
  chmod +x docker/ensure-db-credentials.sh 2>/dev/null || true
  if ! sh docker/ensure-db-credentials.sh; then
    echo "FATAL: ensure-db-credentials falhou — apply abortado." >&2
    exit 1
  fi
fi

# Soft-upgrade + limpeza cedo: se disco estiver cheio, liberar storage/logs antes do rebuild.
# Script é idempotente; roda de novo no final com app up (artisan).
if [ -f docker/cleanup-storage-logs.sh ]; then
  chmod +x docker/cleanup-storage-logs.sh 2>/dev/null || true
  echo "=== Limpeza storage/logs (pré-rebuild) ==="
  bash docker/cleanup-storage-logs.sh || true
fi

if [ ! -f "$ROOT_DIR/.env" ]; then
  echo "Aviso: $ROOT_DIR/.env ausente — compose pode falhar no stacker-agent; seguindo rebuild." >&2
  touch "$ROOT_DIR/.env" || true
fi

if [ ! -f public/build/manifest.json ]; then
  echo "=== Build frontend (manifest ausente) ==="
  sh docker/build-frontend.sh
else
  echo "public/build/manifest.json presente — pulando build frontend."
fi

if [ ! -f vendor/autoload.php ]; then
  echo "=== Composer install (vendor ausente) ==="
  sh docker/install-composer-deps.sh
else
  echo "vendor/autoload.php presente — pulando composer install."
fi

COMPOSE=(docker compose -p "$COMPOSE_PROJECT_NAME" --project-directory "$COMPOSE_WORK_DIR" $COMPOSE_ARGS --env-file "$ENV_FILE_ABS")
if [ -f "$ROOT_DIR/.env" ]; then
  # .env depois: só para STACKER_* etc. GETFY_DB_* já foram espelhados do stack.env
  # por ensure-db-credentials — se divergirem, stack.env venceu na sync.
  COMPOSE+=(--env-file "$ROOT_DIR/.env")
fi
# Shell exports de DB_* sobrescrevem qualquer --env-file.
unset GETFY_DB_CONNECTION GETFY_DB_HOST GETFY_DB_PORT GETFY_DB_DATABASE GETFY_DB_USERNAME GETFY_DB_PASSWORD 2>/dev/null || true
unset DB_USERNAME DB_PASSWORD DB_HOST DB_PORT DB_DATABASE DB_CONNECTION 2>/dev/null || true

echo "=== Rebuild imagem app ==="
echo "Build context: $COMPOSE_WORK_DIR"
docker build -t getfy_app:latest -f "$COMPOSE_WORK_DIR/Dockerfile" "$COMPOSE_WORK_DIR"

ensure_php_uploads_ini

echo "=== Subindo stack (sem recriar stacker-agent — apply roda dentro dele) ==="
COMPOSE_UP_SERVICES=()
HAS_CADDY=0
while IFS= read -r svc; do
  [ -z "$svc" ] && continue
  [ "$svc" = "stacker-agent" ] && continue
  if [ "$svc" = "caddy" ]; then
    HAS_CADDY=1
    continue
  fi
  COMPOSE_UP_SERVICES+=("$svc")
done < <("${COMPOSE[@]}" config --services)

if [ "${#COMPOSE_UP_SERVICES[@]}" -eq 0 ]; then
  echo "Nenhum serviço para subir." >&2
  exit 1
fi

# Recria serviços que usam getfy_app:latest — sem hardcode de "queue"
# (compose padrão tem app/scheduler/worker-*; caddy/no-redis têm queue).
# Nunca inclui caddy aqui — recrear o proxy derruba 80/443.
RECREATE_SERVICES=()
for svc in "${COMPOSE_UP_SERVICES[@]}"; do
  case "$svc" in
    app|queue|scheduler|worker|worker-*) RECREATE_SERVICES+=("$svc") ;;
  esac
done

has_app=0
for svc in "${RECREATE_SERVICES[@]+"${RECREATE_SERVICES[@]}"}"; do
  [ "$svc" = "app" ] && has_app=1
done
if [ "$has_app" -ne 1 ]; then
  echo "FATAL: serviço 'app' não encontrado para recreate." >&2
  echo "Serviços no compose: ${COMPOSE_UP_SERVICES[*]}" >&2
  exit 1
fi

wait_for_app_http() {
  local attempt=0
  local max=90
  while [ "$attempt" -lt "$max" ]; do
    if "${COMPOSE[@]}" exec -T app php -r "exit(@file_get_contents('http://127.0.0.1/up')===false?1:0);" 2>/dev/null; then
      echo "App respondeu em /up."
      return 0
    fi
    attempt=$((attempt + 1))
    sleep 2
  done
  echo "FATAL: app não respondeu em /up após ${max} tentativas." >&2
  "${COMPOSE[@]}" logs app --tail 80 2>/dev/null || true
  return 1
}

# Caddy: manter processo (TLS/portas). Só sobe se estiver parado; reload atualiza upstream.
ensure_caddy_proxy() {
  if [ "$HAS_CADDY" -ne 1 ] && [ "$COMPOSE_FILES" != "docker-compose.caddy.yml" ]; then
    return 0
  fi

  local caddy_cid caddy_state
  caddy_cid="$("${COMPOSE[@]}" ps -q caddy 2>/dev/null | head -1 || true)"
  caddy_state="missing"
  if [ -n "$caddy_cid" ]; then
    caddy_state="$(docker inspect -f '{{if .State.Running}}running{{else}}{{.State.Status}}{{end}}' "$caddy_cid" 2>/dev/null || echo unknown)"
  fi

  if [ "${GETFY_FORCE_RECREATE_CADDY:-0}" = "1" ]; then
    echo "=== Recriando Caddy (GETFY_FORCE_RECREATE_CADDY=1) ==="
    "${COMPOSE[@]}" up -d --force-recreate --no-deps caddy
  elif [ "$caddy_state" != "running" ]; then
    echo "=== Subindo Caddy (estava: $caddy_state) ==="
    "${COMPOSE[@]}" up -d --no-deps caddy
  else
    echo "=== Caddy permanece no ar — reload do proxy (sem recreate) ==="
    if ! "${COMPOSE[@]}" exec -T caddy caddy reload --config /etc/caddy/Caddyfile 2>/dev/null; then
      echo "Aviso: caddy reload falhou — tentando start sem recreate." >&2
      "${COMPOSE[@]}" up -d --no-deps caddy || true
    fi
  fi

  sleep 2
  if command -v curl >/dev/null 2>&1; then
    if ! curl -sI --max-time 8 "http://127.0.0.1/" 2>/dev/null | head -1 | grep -qE 'HTTP/[0-9.]+ [23]'; then
      echo "Aviso: HTTP local ainda não retornou 2xx — verifique logs do Caddy." >&2
      "${COMPOSE[@]}" logs caddy --tail 40 2>/dev/null || true
    else
      echo "HTTP local via Caddy OK."
    fi
  fi
}

# App primeiro (tráfego HTTP), depois workers — reduz janela fora do ar no Caddy.
APP_ONLY=(app)
OTHER_RECREATE=()
for svc in "${RECREATE_SERVICES[@]}"; do
  [ "$svc" = "app" ] && continue
  OTHER_RECREATE+=("$svc")
done

echo "=== Recriando app com imagem nova ==="
"${COMPOSE[@]}" up -d --force-recreate --no-deps "${APP_ONLY[@]}"

echo "=== Aguardando app ficar saudável ==="
wait_for_app_http

ensure_caddy_proxy

if [ "${#OTHER_RECREATE[@]}" -gt 0 ]; then
  echo "=== Recriando workers (${OTHER_RECREATE[*]}) ==="
  "${COMPOSE[@]}" up -d --force-recreate --no-deps "${OTHER_RECREATE[@]}"
fi

echo "=== Garantindo demais serviços (sem recreate / sem tocar no Caddy) ==="
"${COMPOSE[@]}" up -d --remove-orphans --no-recreate "${COMPOSE_UP_SERVICES[@]}"

echo "=== Migrate + config clear ==="
if "${COMPOSE[@]}" exec -T app php artisan migrate --force; then
  :
else
  echo "Aviso: migrate falhou (schema pode já estar atualizado)." >&2
fi
"${COMPOSE[@]}" exec -T app php artisan config:clear || true

echo "=== Verificando versão em runtime ==="
HOST_VERSION="$(tr -d ' \n\r' < VERSION)"
RUNTIME_VERSION="$("${COMPOSE[@]}" exec -T app php artisan tinker --execute="echo config('getfy.version');" 2>/dev/null | tr -d ' \n\r' || true)"
if [ -z "$RUNTIME_VERSION" ]; then
  echo "FATAL: não foi possível ler a versão do container app." >&2
  exit 1
fi
if [ "$RUNTIME_VERSION" != "$HOST_VERSION" ]; then
  echo "FATAL: VERSION no host ($HOST_VERSION) difere do app em execução ($RUNTIME_VERSION)." >&2
  exit 1
fi
echo "Versão runtime OK: $RUNTIME_VERSION"

echo "=== Limpeza de imagens Docker antigas ==="
if [ -f docker/prune-docker-images.sh ]; then
  chmod +x docker/prune-docker-images.sh 2>/dev/null || true
  # Após rebuild + recreate, imagens antigas ficam órfãs — remove dangling e unused.
  GETFY_DOCKER_PRUNE_UNUSED="${GETFY_DOCKER_PRUNE_UNUSED:-1}" \
    GETFY_SKIP_DOCKER_PRUNE="${GETFY_SKIP_DOCKER_PRUNE:-0}" \
    bash docker/prune-docker-images.sh || true
else
  echo "docker/prune-docker-images.sh ausente — pulando."
fi

# Só storage/logs + failed jobs + harden LOG_* — nunca down -v / DB / volumes.
echo "=== Limpeza segura storage/logs (pós-update) ==="
if [ -f docker/cleanup-storage-logs.sh ]; then
  chmod +x docker/cleanup-storage-logs.sh 2>/dev/null || true
  LOG_DAILY_DAYS="${LOG_DAILY_DAYS:-7}" \
    GETFY_COMPOSE_PROJECT_NAME="${COMPOSE_PROJECT_NAME:-getfy}" \
    bash docker/cleanup-storage-logs.sh || true
else
  echo "cleanup-storage-logs.sh ausente — prune mínimo via artisan."
  "${COMPOSE[@]}" exec -T app php artisan logs:prune --days="${LOG_DAILY_DAYS:-7}" --max-mb=50 --max-total-mb=200 || true
fi

echo "=== Stacker apply update concluído ==="
