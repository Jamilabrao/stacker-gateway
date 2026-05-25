#!/usr/bin/env bash
set -euo pipefail

REPO_URL="${GETFY_REPO_URL:-https://github.com/LeonardoIsrael0516/getfy-gateway.git}"
BRANCH="${GETFY_BRANCH:-main}"
INSTALL_DIR="${GETFY_DIR:-/opt/getfy}"

if [ "$(uname -s)" != "Linux" ]; then
  echo "Este script é para Linux." >&2
  exit 1
fi

SUDO=""
if [ "$(id -u)" -ne 0 ]; then
  if command -v sudo >/dev/null 2>&1; then
    SUDO="sudo"
  else
    echo "Rode como root ou instale sudo." >&2
    exit 1
  fi
fi

if ! command -v git >/dev/null 2>&1; then
  echo "git não encontrado." >&2
  exit 1
fi

if ! command -v docker >/dev/null 2>&1; then
  echo "docker não encontrado." >&2
  exit 1
fi

if [ ! -d "$INSTALL_DIR" ]; then
  echo "Diretório não encontrado: $INSTALL_DIR" >&2
  exit 1
fi

if [ ! -d "$INSTALL_DIR/.git" ]; then
  echo "Atualização manual indisponível: diretório não é um repositório Git (.git ausente)." >&2
  exit 1
fi

GIT_BASE=(git -c safe.directory="$INSTALL_DIR" -C "$INSTALL_DIR")
$SUDO "${GIT_BASE[@]}" remote set-url origin "$REPO_URL" >/dev/null 2>&1 || true

# Merge/rebase interrompido deixa o índice travado ("needs merge" / "resolve your current index first").
if [ -f "$INSTALL_DIR/.git/MERGE_HEAD" ] || [ -f "$INSTALL_DIR/.git/REBASE_HEAD" ]; then
  echo "Aviso: merge/rebase anterior incompleto — abortando para permitir a atualização." >&2
  $SUDO "${GIT_BASE[@]}" merge --abort >/dev/null 2>&1 || true
  $SUDO "${GIT_BASE[@]}" rebase --abort >/dev/null 2>&1 || true
fi

HAS_LOCAL_CHANGES=0
if [ -n "$($SUDO "${GIT_BASE[@]}" status --porcelain 2>/dev/null || true)" ]; then
  HAS_LOCAL_CHANGES=1
  $SUDO "${GIT_BASE[@]}" stash push -u -m "getfy-update" >/dev/null 2>&1 || true
fi

$SUDO "${GIT_BASE[@]}" fetch --all --prune
$SUDO "${GIT_BASE[@]}" checkout -B "$BRANCH" "origin/$BRANCH"
$SUDO "${GIT_BASE[@]}" reset --hard "origin/$BRANCH"

if [ "$HAS_LOCAL_CHANGES" -eq 1 ]; then
  if ! $SUDO "${GIT_BASE[@]}" stash pop >/dev/null 2>&1; then
    echo "Aviso: havia alterações locais. O script fez stash, mas não conseguiu reaplicar automaticamente." >&2
    echo "Para ver e resolver manualmente: cd \"$INSTALL_DIR\" && git stash list && git stash show -p" >&2
  fi
fi

cd "$INSTALL_DIR"

$SUDO chmod +x docker/ensure-upload-limits.sh 2>/dev/null || true
echo ""
echo "=== Limites de upload (PHP / Member Builder) ==="
$SUDO sh docker/ensure-upload-limits.sh

if [ -f docker/build-frontend.sh ]; then
  $SUDO chmod +x docker/build-frontend.sh 2>/dev/null || true
  echo ""
  echo "=== Build do frontend ==="
  $SUDO sh docker/build-frontend.sh
else
  echo "Aviso: docker/build-frontend.sh não encontrado — assets do painel podem ficar desatualizados." >&2
fi

echo ""
echo "=== Reiniciando stack Docker ==="
$SUDO chmod +x docker/detect-compose-files.sh 2>/dev/null || true
COMPOSE_FILES="$($SUDO sh docker/detect-compose-files.sh)"
echo "Compose: $COMPOSE_FILES"
$SUDO env GETFY_COMPOSE_FILES="$COMPOSE_FILES" GETFY_APP_ENV=production GETFY_APP_DEBUG=false sh docker/up.sh

echo ""
echo "Atualização concluída (git + build frontend + stack reiniciado)."
