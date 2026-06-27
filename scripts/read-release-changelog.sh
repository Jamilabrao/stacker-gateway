#!/usr/bin/env bash
# Imprime o changelog de docs/releases/{VERSION}.md (stdout). Vazio se ausente.
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
VERSION="$(tr -d ' \r\n' < "${ROOT_DIR}/VERSION" 2>/dev/null || echo "")"
if [ -n "${1:-}" ] && [ "${1#-}" = "$1" ]; then
  VERSION="$1"
fi

CHANGELOG_FILE="${ROOT_DIR}/docs/releases/${VERSION}.md"
if [ ! -f "$CHANGELOG_FILE" ]; then
  exit 0
fi

cat "$CHANGELOG_FILE"
