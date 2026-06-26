#!/usr/bin/env bash
# Aplica release Stacker no host: deps, rebuild da imagem app e migrate.
# Chamado pelo stacker-agent após extrair o zip em /gateway (GETFY_DIR).
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT_DIR"

echo "=== Stacker apply update ==="
echo "Diretório: $ROOT_DIR"

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

# Carrega variáveis do deploy existente (incl. GETFY_COMPOSE_FILES se definido).
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
  local detected
  detected="$(docker volume ls --format '{{.Name}}' 2>/dev/null | grep '_postgres_data$' | head -1 | sed 's/_postgres_data$//' || true)"
  if [ -n "$detected" ]; then
    printf '%s' "$detected"
    return
  fi
  basename "$ROOT_DIR"
}

PROJECT_NAME="$(resolve_compose_project_name)"
export COMPOSE_PROJECT_NAME="$PROJECT_NAME"
echo "Compose project: $COMPOSE_PROJECT_NAME"
echo "Compose files: $COMPOSE_FILES"

ensure_php_uploads_ini() {
  local ini="$ROOT_DIR/docker/php/uploads.ini"
  if [ -d "$ini" ]; then
    echo "Corrigindo docker/php/uploads.ini (era diretório — provável mount Docker anterior)." >&2
    rm -rf "$ini"
  fi
  if [ ! -f "$ini" ]; then
    mkdir -p "$(dirname "$ini")"
    cat > "$ini" <<'EOF'
upload_max_filesize = 512M
post_max_size = 512M
memory_limit = 512M
max_execution_time = 300
EOF
  fi
  if [ -f docker/ensure-upload-limits.sh ]; then
    sh docker/ensure-upload-limits.sh || true
  fi
}

ensure_php_uploads_ini

# Frontend e vendor podem vir no zip; só rebuild se faltar ou forçado.
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

COMPOSE=(docker compose -p "$COMPOSE_PROJECT_NAME" $COMPOSE_ARGS --env-file "$ENV_FILE")

echo "=== Rebuild imagem app ==="
"${COMPOSE[@]}" build app

echo "=== Subindo stack (projeto existente) ==="
"${COMPOSE[@]}" up -d --remove-orphans

echo "=== Migrate + config clear ==="
if "${COMPOSE[@]}" exec -T app php artisan migrate --force; then
  :
else
  echo "Aviso: migrate falhou (schema pode já estar atualizado)." >&2
fi
"${COMPOSE[@]}" exec -T app php artisan config:clear || true

echo "=== Stacker apply update concluído ==="
