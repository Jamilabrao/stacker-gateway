#!/usr/bin/env bash
# Empacota release de produção (sem fontes Vue/CSS de dev).
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT_DIR"

VERSION="$(tr -d ' \r\n' < VERSION 2>/dev/null || echo "0.0.0")"
if [ -n "${1:-}" ] && [ "${1#-}" = "$1" ]; then
  VERSION="$1"
fi
OUT_DIR="${2:-$ROOT_DIR/dist-releases}"
ZIP_PATH="$OUT_DIR/stacker-gateway-${VERSION}.zip"

if [ "${GETFY_SKIP_FRONTEND_BUILD:-}" != "1" ] && [ -f docker/build-frontend.sh ]; then
  sh docker/build-frontend.sh
fi

if [ ! -f vendor/autoload.php ] && [ "${GETFY_SKIP_COMPOSER_INSTALL:-}" != "1" ]; then
  if [ -f docker/install-composer-deps.sh ]; then
    echo "=== Composer install (vendor ausente) ==="
    sh docker/install-composer-deps.sh
  else
    echo "vendor/ ausente — rode docker/install-composer-deps.sh antes." >&2
    exit 1
  fi
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
rm -rf "$TMP/agent/node_modules" 2>/dev/null || true

# Reduz vendor/ (tests, docs) para caber no limite de upload (~100MB via Cloudflare)
if [ -d "$TMP/vendor" ]; then
  find "$TMP/vendor" -type d \( -name tests -o -name Tests -o -name test -o -name docs -o -name .github \) -prune -exec rm -rf {} + 2>/dev/null || true
  find "$TMP/vendor" -type f \( -name '*.md' -o -name '*.markdown' -o -name 'CHANGELOG*' -o -name 'UPGRADE*' \) -delete 2>/dev/null || true
fi

# uploads.ini deve ser arquivo (Docker cria diretório se faltar no bind mount).
UPLOADS_INI="$TMP/docker/php/uploads.ini"
if [ -d "$UPLOADS_INI" ]; then
  rm -rf "$UPLOADS_INI"
fi
mkdir -p "$(dirname "$UPLOADS_INI")"
if [ ! -f "$UPLOADS_INI" ]; then
  cat > "$UPLOADS_INI" <<'EOF'
upload_max_filesize = 512M
post_max_size = 512M
memory_limit = 512M
max_execution_time = 300
EOF
fi

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
