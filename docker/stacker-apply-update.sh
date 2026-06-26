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

chmod +x docker/detect-compose-files.sh docker/build-frontend.sh docker/install-composer-deps.sh 2>/dev/null || true

COMPOSE_FILES="$(sh docker/detect-compose-files.sh)"
COMPOSE_ARGS=""
for f in $COMPOSE_FILES; do
  if [ -n "$f" ]; then
    COMPOSE_ARGS="$COMPOSE_ARGS -f $f"
  fi
done

ENV_FILE=".docker/stack.env"
if [ ! -f "$ENV_FILE" ]; then
  echo ".docker/stack.env ausente — rode install/update legado uma vez." >&2
  exit 1
fi

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

echo "=== Rebuild imagem app ==="
docker compose $COMPOSE_ARGS --env-file "$ENV_FILE" build app

echo "=== Subindo stack ==="
docker compose $COMPOSE_ARGS --env-file "$ENV_FILE" up -d --remove-orphans

echo "=== Migrate + config clear ==="
if docker compose $COMPOSE_ARGS --env-file "$ENV_FILE" exec -T app php artisan migrate --force; then
  :
else
  echo "Aviso: migrate falhou (schema pode já estar atualizado)." >&2
fi
docker compose $COMPOSE_ARGS --env-file "$ENV_FILE" exec -T app php artisan config:clear || true

echo "=== Stacker apply update concluído ==="
