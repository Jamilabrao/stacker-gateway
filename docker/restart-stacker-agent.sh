#!/usr/bin/env sh
# Sobe o stacker-agent mesmo com app fora — necessário para updates remotos.
# Uso na VPS: cd /opt/getfy && sh docker/restart-stacker-agent.sh
# (Delega para fix-stacker-agent.sh com diagnóstico completo.)
set -eu

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
exec sh "$ROOT_DIR/docker/fix-stacker-agent.sh" "$@"
