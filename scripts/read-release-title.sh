#!/usr/bin/env bash
# Título da release: primeira linha markdown de docs/releases/{VERSION}.md (sem #).
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
VERSION="$(tr -d ' \r\n' < "${ROOT_DIR}/VERSION" 2>/dev/null || echo "")"
if [ -n "${1:-}" ] && [ "${1#-}" = "$1" ]; then
  VERSION="$1"
fi

CHANGELOG_FILE="${ROOT_DIR}/docs/releases/${VERSION}.md"
if [ -f "$CHANGELOG_FILE" ]; then
  head -1 "$CHANGELOG_FILE" | sed 's/^#* *//' | sed 's/^[[:space:]]*//;s/[[:space:]]*$//'
else
  printf 'Release %s' "$VERSION"
fi
