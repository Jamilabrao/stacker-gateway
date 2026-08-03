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

set -a
# shellcheck disable=SC1090
. "$ENV_FILE"
set +a

COMPOSE_FILES="$(sh docker/detect-compose-files.sh)"
COMPOSE_ARGS=""
for f in $COMPOSE_FILES; do
  if [ -n "$f" ]; then
    COMPOSE_ARGS="$COMPOSE_ARGS -f $f"
  fi
done

persist_compose_files_in_stack_env() {
  local files="$1"
  local tmp
  if grep -Eq '^\s*GETFY_COMPOSE_FILES\s*=' "$ENV_FILE" 2>/dev/null; then
    tmp="$(mktemp)"
    awk -v v="$files" '
      BEGIN { done=0 }
      $0 ~ /^[[:space:]]*GETFY_COMPOSE_FILES[[:space:]]*=/ { print "GETFY_COMPOSE_FILES=" v; done=1; next }
      { print }
      END { if (!done) print "GETFY_COMPOSE_FILES=" v }
    ' "$ENV_FILE" > "$tmp"
    mv "$tmp" "$ENV_FILE"
  else
    echo "GETFY_COMPOSE_FILES=$files" >> "$ENV_FILE"
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
  if [ -n "${GETFY_COMPOSE_PROJECT_NAME:-}" ]; then
    printf '%s' "$GETFY_COMPOSE_PROJECT_NAME"
    return
  fi

  # Instalação real em /opt/getfy — agente monta como /gateway (basename engana).
  if docker volume ls --format '{{.Name}}' 2>/dev/null | grep -qx 'getfy_postgres_data'; then
    printf 'getfy'
    return
  fi

  local running
  running="$(docker ps --format '{{.Names}}' 2>/dev/null | grep -E 'app-1$' | grep -v '^gateway-' | head -1 | sed 's/-app-1$//' || true)"
  if [ -n "$running" ]; then
    printf '%s' "$running"
    return
  fi

  local detected
  detected="$(docker volume ls --format '{{.Name}}' 2>/dev/null | grep '_postgres_data$' | grep -v '^gateway_' | head -1 | sed 's/_postgres_data$//' || true)"
  if [ -n "$detected" ]; then
    printf '%s' "$detected"
    return
  fi

  local base
  base="$(basename "$ROOT_DIR")"
  if [ "$base" != "gateway" ]; then
    printf '%s' "$base"
    return
  fi

  echo "GETFY_COMPOSE_PROJECT_NAME não definido em .docker/stack.env (ex.: getfy)." >&2
  exit 1
}

PROJECT_NAME="$(resolve_compose_project_name)"

if [ "$PROJECT_NAME" = "gateway" ] && docker volume ls --format '{{.Name}}' 2>/dev/null | grep -qx 'getfy_postgres_data'; then
  echo "Aviso: compose project 'gateway' ignorado — usando getfy (stack de produção)." >&2
  PROJECT_NAME=getfy
fi

export COMPOSE_PROJECT_NAME="$PROJECT_NAME"

if ! grep -Eq '^\s*GETFY_COMPOSE_PROJECT_NAME\s*=' "$ENV_FILE" 2>/dev/null; then
  echo "GETFY_COMPOSE_PROJECT_NAME=$PROJECT_NAME" >> "$ENV_FILE"
fi

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
  sh docker/ensure-host-dotenv.sh
elif [ ! -f "$ROOT_DIR/.env" ]; then
  echo "Aviso: .env ausente — stacker-agent precisa de STACKER_AGENT_TOKEN em $ROOT_DIR/.env" >&2
fi

# Soft-upgrade: single+debug enche o disco (já vimos 40GB+ em storage/logs).
harden_host_log_env() {
  local envf="$ROOT_DIR/.env"
  [ -f "$envf" ] || return 0
  set_kv() {
    local key="$1" val="$2"
    if grep -Eq "^[[:space:]]*${key}[[:space:]]*=" "$envf" 2>/dev/null; then
      local tmp
      tmp="$(mktemp)"
      awk -v k="$key" -v v="$val" '
        $0 ~ "^[[:space:]]*" k "[[:space:]]*=" { print k "=" v; next }
        { print }
      ' "$envf" > "$tmp"
      mv "$tmp" "$envf"
    else
      echo "${key}=${val}" >> "$envf"
    fi
  }
  local stack level
  stack="$(grep -E '^[[:space:]]*LOG_STACK[[:space:]]*=' "$envf" 2>/dev/null | tail -1 | cut -d= -f2- | sed 's/[\"'\'']//g;s/\r//g;s/^[[:space:]]*//;s/[[:space:]]*$//' || true)"
  level="$(grep -E '^[[:space:]]*LOG_LEVEL[[:space:]]*=' "$envf" 2>/dev/null | tail -1 | cut -d= -f2- | sed 's/[\"'\'']//g;s/\r//g;s/^[[:space:]]*//;s/[[:space:]]*$//' || true)"
  if [ -z "$stack" ] || [ "$stack" = "single" ]; then
    set_kv LOG_STACK daily
    echo "LOG_STACK=daily (antes: ${stack:-ausente})"
  fi
  if [ -z "$level" ] || [ "$level" = "debug" ]; then
    set_kv LOG_LEVEL warning
    echo "LOG_LEVEL=warning (antes: ${level:-ausente})"
  fi
  if ! grep -Eq '^[[:space:]]*LOG_DAILY_DAYS[[:space:]]*=' "$envf" 2>/dev/null; then
    set_kv LOG_DAILY_DAYS 7
  fi
  if ! grep -Eq '^[[:space:]]*LOG_CHANNEL[[:space:]]*=' "$envf" 2>/dev/null; then
    set_kv LOG_CHANNEL stack
  fi
}
echo "=== Hardening LOG_* no .env do host ==="
harden_host_log_env || true

if [ ! -f "$ROOT_DIR/.env" ]; then
  echo "FATAL: $ROOT_DIR/.env ausente (STACKER_AGENT_TOKEN). Corrija antes do apply." >&2
  exit 1
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
  COMPOSE+=(--env-file "$ROOT_DIR/.env")
fi

echo "=== Rebuild imagem app ==="
echo "Build context: $COMPOSE_WORK_DIR"
docker build -t getfy_app:latest -f "$COMPOSE_WORK_DIR/Dockerfile" "$COMPOSE_WORK_DIR"

ensure_php_uploads_ini

echo "=== Subindo stack (sem recriar stacker-agent — apply roda dentro dele) ==="
COMPOSE_UP_SERVICES=()
while IFS= read -r svc; do
  [ -z "$svc" ] && continue
  [ "$svc" = "stacker-agent" ] && continue
  COMPOSE_UP_SERVICES+=("$svc")
done < <("${COMPOSE[@]}" config --services)

if [ "${#COMPOSE_UP_SERVICES[@]}" -eq 0 ]; then
  echo "Nenhum serviço para subir." >&2
  exit 1
fi

# Recria serviços que usam getfy_app:latest — sem hardcode de "queue"
# (compose padrão tem app/scheduler/worker-*; caddy/no-redis têm queue).
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

echo "=== Recriando containers com imagem nova (${RECREATE_SERVICES[*]}) ==="
"${COMPOSE[@]}" up -d --force-recreate --no-deps "${RECREATE_SERVICES[@]}"

echo "=== Subindo demais serviços ==="
"${COMPOSE[@]}" up -d --remove-orphans "${COMPOSE_UP_SERVICES[@]}"

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

echo "=== Aguardando app ficar saudável ==="
wait_for_app_http

if [ "$COMPOSE_FILES" = "docker-compose.caddy.yml" ]; then
  echo "=== Recriando Caddy (proxy → app) ==="
  "${COMPOSE[@]}" up -d --force-recreate --no-deps caddy
  sleep 3
  if command -v curl >/dev/null 2>&1; then
    if ! curl -sI --max-time 8 "http://127.0.0.1/" 2>/dev/null | head -1 | grep -qE 'HTTP/[0-9.]+ [23]'; then
      echo "Aviso: HTTP local ainda não retornou 2xx — verifique logs do Caddy." >&2
      "${COMPOSE[@]}" logs caddy --tail 40 2>/dev/null || true
    fi
  fi
fi

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

echo "=== Limpeza de logs Laravel (storage) ==="
"${COMPOSE[@]}" exec -T app php artisan logs:prune --days="${LOG_DAILY_DAYS:-7}" --max-mb=50 || true

echo "=== Stacker apply update concluído ==="
