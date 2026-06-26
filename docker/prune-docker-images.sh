#!/usr/bin/env bash
# Remove imagens Docker órfãs após rebuild. Nunca mexe em volumes nem containers em execução.
set -euo pipefail

if [ "${GETFY_SKIP_DOCKER_PRUNE:-0}" = "1" ]; then
  echo "Limpeza Docker ignorada (GETFY_SKIP_DOCKER_PRUNE=1)."
  exit 0
fi

if ! command -v docker >/dev/null 2>&1; then
  echo "docker não encontrado." >&2
  exit 1
fi

show_images_line() {
  docker system df 2>/dev/null | awk '
    $1 == "Images" {
      printf "  Images: %s total, %s reclaimable\n", $4, $5
      exit
    }
  ' || true
}

echo "Antes:"
show_images_line
docker system df 2>/dev/null | head -4 || true

echo ""
echo "Removendo imagens dangling (sem tag)..."
docker image prune -f || true

echo ""
echo "Removendo cache de build com mais de 14 dias..."
docker builder prune -f --filter until=336h 2>/dev/null || true

if [ "${GETFY_DOCKER_PRUNE_UNUSED:-0}" = "1" ]; then
  echo ""
  echo "Removendo imagens não usadas por containers (GETFY_DOCKER_PRUNE_UNUSED=1)..."
  docker image prune -af || true
fi

echo ""
echo "Depois:"
show_images_line
