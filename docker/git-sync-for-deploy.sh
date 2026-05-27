#!/usr/bin/env sh
# Sincroniza o repositório no servidor com origin/$BRANCH (deploy).
# Uso: sh docker/git-sync-for-deploy.sh <INSTALL_DIR> <BRANCH> [sudo_cmd]
#   sudo_cmd: vazio ou "sudo" (com espaço no fim se precisar: não — passar só "sudo")
set -eu

INSTALL_DIR="${1:?INSTALL_DIR}"
BRANCH="${2:?BRANCH}"
SUDO="${3:-}"

GIT_BASE="git -c safe.directory=$INSTALL_DIR -C $INSTALL_DIR"

# shellcheck disable=SC2086
$SUDO $GIT_BASE remote set-url origin "${GETFY_REPO_URL:-https://github.com/LeonardoIsrael0516/getfy-gateway.git}" >/dev/null 2>&1 || true

# Merge/rebase interrompido
if [ -f "$INSTALL_DIR/.git/MERGE_HEAD" ] || [ -f "$INSTALL_DIR/.git/REBASE_HEAD" ]; then
  echo "Aviso: merge/rebase anterior incompleto — abortando." >&2
  # shellcheck disable=SC2086
  $SUDO $GIT_BASE merge --abort >/dev/null 2>&1 || true
  # shellcheck disable=SC2086
  $SUDO $GIT_BASE rebase --abort >/dev/null 2>&1 || true
fi

# public/build é gerado no servidor (build-frontend.sh). Versionar no Git causa
# "needs merge" em manifest.json após cada npm run build.
# shellcheck disable=SC2086
$SUDO rm -rf "$INSTALL_DIR/public/build"

UNMERGED=""
# shellcheck disable=SC2086
UNMERGED=$($SUDO $GIT_BASE diff --name-only --diff-filter=U 2>/dev/null || true)
if [ -n "$UNMERGED" ]; then
  echo "Aviso: índice Git com ficheiros por resolver — a repor estado limpo." >&2
  # shellcheck disable=SC2086
  $SUDO $GIT_BASE reset --hard HEAD 2>/dev/null || true
fi

# shellcheck disable=SC2086
$SUDO $GIT_BASE fetch --all --prune

# checkout -B falha com "needs merge" se o índice ainda tiver conflitos; reset --hard resolve.
# shellcheck disable=SC2086
$SUDO $GIT_BASE reset --hard "origin/$BRANCH"

# Garantir branch local alinhada (opcional, após hard reset)
# shellcheck disable=SC2086
$SUDO $GIT_BASE checkout -B "$BRANCH" "origin/$BRANCH" 2>/dev/null || true
