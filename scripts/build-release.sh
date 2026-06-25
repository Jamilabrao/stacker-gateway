#!/usr/bin/env bash
# Empacota release de produção (sem fontes Vue/CSS de dev).
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT_DIR"

VERSION="$(tr -d ' \r\n' < VERSION 2>/dev/null || echo "0.0.0")"
OUT_DIR="${1:-$ROOT_DIR/dist-releases}"
ZIP_PATH="$OUT_DIR/stacker-gateway-${VERSION}.zip"

if [ "${GETFY_SKIP_FRONTEND_BUILD:-}" != "1" ] && [ -f docker/build-frontend.sh ]; then
  sh docker/build-frontend.sh
fi

mkdir -p "$OUT_DIR"
rm -f "$ZIP_PATH"

INCLUDE=(
  app bootstrap config database public routes vendor
  artisan VERSION composer.json composer.lock
  Dockerfile docker-compose.yml docker-compose.caddy.yml docker-compose.no-redis.yml
  docker install.sh update.sh install-caddy.sh update-caddy.sh install-no-redis.sh update-no-redis.sh
  agent
)

if [ -d vendor ] && [ ! -f vendor/autoload.php ]; then
  echo "vendor/ incompleto — rode docker/install-composer-deps.sh antes." >&2
  exit 1
fi

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

for item in "${INCLUDE[@]}"; do
  if [ -e "$item" ]; then
    cp -a "$item" "$TMP/"
  fi
done

# Garante build de produção sem resources/js
rm -rf "$TMP/resources" "$TMP/node_modules" "$TMP/tests" "$TMP/.git" 2>/dev/null || true

(
  cd "$TMP"
  if command -v zip >/dev/null 2>&1; then
    zip -rq "$ZIP_PATH" .
  else
    tar -caf "${ZIP_PATH%.zip}.tar.gz" .
    echo "zip não encontrado — gerado ${ZIP_PATH%.zip}.tar.gz"
    exit 0
  fi
)

SHA256="$(sha256sum "$ZIP_PATH" | awk '{print $1}')"
echo "Release: $ZIP_PATH"
echo "SHA256: $SHA256"
echo "Tamanho: $(wc -c < "$ZIP_PATH") bytes"
