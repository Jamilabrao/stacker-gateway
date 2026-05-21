#!/bin/sh
# Garante ficheiros de limite PHP no host (Laragon + volume Docker).
# Chamado por install.sh e update.sh antes de subir o stack.
set -e

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
INI_SRC="$ROOT_DIR/docker/php/uploads.ini"
USER_INI="$ROOT_DIR/public/.user.ini"

if [ ! -f "$INI_SRC" ]; then
  echo "Aviso: $INI_SRC não encontrado." >&2
  exit 0
fi

mkdir -p "$(dirname "$USER_INI")"
cp "$INI_SRC" "$USER_INI"

echo "Limites de upload PHP:"
grep -E '^(upload_max_filesize|post_max_size|memory_limit)\s*=' "$INI_SRC" | sed 's/^/  /'
echo "  (Docker monta docker/php/uploads.ini no container; Laragon usa public/.user.ini)"
