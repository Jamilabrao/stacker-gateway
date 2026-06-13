#!/usr/bin/env sh
# Instala vendor/ no host via container Composer (rede do host) — evita timeout do
# api.github.com durante o docker build (rede isolada do BuildKit).
set -e

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT_DIR"

if [ "${GETFY_SKIP_COMPOSER_INSTALL:-}" = "1" ] || [ "${GETFY_SKIP_COMPOSER_INSTALL:-}" = "true" ]; then
  echo "GETFY_SKIP_COMPOSER_INSTALL ativo — pulando composer install."
  exit 0
fi

if ! command -v docker >/dev/null 2>&1; then
  echo "docker não encontrado — não foi possível instalar dependências PHP." >&2
  exit 1
fi

if [ ! -f composer.json ] || [ ! -f composer.lock ]; then
  echo "composer.json ou composer.lock ausente em $ROOT_DIR" >&2
  exit 1
fi

COMPOSER_IMAGE="${GETFY_COMPOSER_IMAGE:-composer:2}"

echo "Instalando dependências PHP (composer install) com imagem $COMPOSER_IMAGE e rede do host ..."

docker run --rm --network host \
  -e COMPOSER_PROCESS_TIMEOUT="${GETFY_COMPOSER_PROCESS_TIMEOUT:-900}" \
  -e COMPOSER_HTTP_TIMEOUT="${GETFY_COMPOSER_HTTP_TIMEOUT:-300}" \
  -e COMPOSER_ALLOW_SUPERUSER=1 \
  -v "$ROOT_DIR:/app" \
  -w /app \
  "$COMPOSER_IMAGE" \
  composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts

if [ ! -f vendor/autoload.php ]; then
  echo "Erro: vendor/autoload.php não foi gerado." >&2
  exit 1
fi

echo "Dependências PHP instaladas: vendor/autoload.php"
