#!/usr/bin/env sh
# Compila assets Vite (public/build) via container Node — não exige npm instalado no host.
set -e

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT_DIR"

if [ "${GETFY_SKIP_FRONTEND_BUILD:-}" = "1" ] || [ "${GETFY_SKIP_FRONTEND_BUILD:-}" = "true" ]; then
  echo "GETFY_SKIP_FRONTEND_BUILD ativo — pulando npm run build."
  exit 0
fi

if ! command -v docker >/dev/null 2>&1; then
  echo "docker não encontrado — não foi possível compilar o frontend." >&2
  exit 1
fi

if [ ! -f package.json ] || [ ! -f package-lock.json ]; then
  echo "package.json ou package-lock.json ausente em $ROOT_DIR" >&2
  exit 1
fi

NODE_IMAGE="${GETFY_NODE_IMAGE:-node:22-alpine}"

echo "Compilando frontend (npm ci + npm run build) com imagem $NODE_IMAGE ..."

docker run --rm \
  -v "$ROOT_DIR:/app" \
  -w /app \
  "$NODE_IMAGE" \
  sh -lc '
    set -e
    npm ci --no-audit --no-fund
    npm run build
    rm -rf node_modules
  '

if [ ! -f public/build/manifest.json ]; then
  echo "Erro: public/build/manifest.json não foi gerado." >&2
  exit 1
fi

echo "Frontend compilado: public/build/manifest.json"
