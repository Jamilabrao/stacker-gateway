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

chmod +x docker/detect-compose-files.sh docker/build-frontend.sh docker/install-composer-deps.sh docker/ensure-upload-limits.sh 2>/dev/null || true

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

ENV_FILE_ABS="$HOST_DIR/.docker/stack.env"
if [ ! -f "$ENV_FILE_ABS" ]; then
  ENV_FILE_ABS="$ROOT_DIR/.docker/stack.env"
fi

export COMPOSE_PROJECT_NAME="$PROJECT_NAME"

echo "Compose project: $COMPOSE_PROJECT_NAME"
echo "Compose host dir: $HOST_DIR"
echo "Compose files: $COMPOSE_FILES"

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

COMPOSE=(docker compose -p "$COMPOSE_PROJECT_NAME" --project-directory "$HOST_DIR" $COMPOSE_ARGS --env-file "$ENV_FILE_ABS")

echo "=== Rebuild imagem app ==="
"${COMPOSE[@]}" build app

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

"${COMPOSE[@]}" up -d --remove-orphans "${COMPOSE_UP_SERVICES[@]}"

echo "=== Migrate + config clear ==="
if "${COMPOSE[@]}" exec -T app php artisan migrate --force; then
  :
else
  echo "Aviso: migrate falhou (schema pode já estar atualizado)." >&2
fi
"${COMPOSE[@]}" exec -T app php artisan config:clear || true

echo "=== Stacker apply update concluído ==="
