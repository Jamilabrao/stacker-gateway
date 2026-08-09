#!/usr/bin/env bash
set -euo pipefail

REPO_URL="${GETFY_REPO_URL:-https://github.com/stacker-builders/stacker-gateway.git}"
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

export GETFY_REPO_URL="$REPO_URL"
SYNC_SCRIPT="$INSTALL_DIR/docker/git-sync-for-deploy.sh"
if [ -f "$SYNC_SCRIPT" ]; then
  $SUDO chmod +x "$SYNC_SCRIPT" 2>/dev/null || true
  $SUDO env GETFY_REPO_URL="$REPO_URL" sh "$SYNC_SCRIPT" "$INSTALL_DIR" "$BRANCH" "$SUDO"
else
  echo "Aviso: git-sync-for-deploy.sh ainda não existe no disco — sync Git mínimo (bootstrap)." >&2
  GIT_BASE=(git -c safe.directory="$INSTALL_DIR" -C "$INSTALL_DIR")
  $SUDO "${GIT_BASE[@]}" remote set-url origin "$REPO_URL" >/dev/null 2>&1 || true
  $SUDO "${GIT_BASE[@]}" merge --abort >/dev/null 2>&1 || true
  $SUDO "${GIT_BASE[@]}" rebase --abort >/dev/null 2>&1 || true
  $SUDO rm -rf "$INSTALL_DIR/public/build"
  $SUDO "${GIT_BASE[@]}" reset --hard HEAD >/dev/null 2>&1 || true
  $SUDO "${GIT_BASE[@]}" fetch --all --prune
  $SUDO "${GIT_BASE[@]}" reset --hard "origin/$BRANCH"
fi

cd "$INSTALL_DIR"

if [ -f docker/prompt-stacker-agent-token.sh ]; then
  $SUDO chmod +x docker/prompt-stacker-agent-token.sh docker/ensure-stacker-agent.sh 2>/dev/null || true
  echo ""
  echo "=== Agente Stacker (licença + métricas) ==="
  $SUDO bash docker/prompt-stacker-agent-token.sh || true
fi

$SUDO chmod +x docker/ensure-upload-limits.sh docker/detect-compose-files.sh docker/verify-workers.sh 2>/dev/null || true
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

if [ -f docker/install-composer-deps.sh ]; then
  $SUDO chmod +x docker/install-composer-deps.sh 2>/dev/null || true
  echo ""
  echo "=== Dependências PHP (Composer) ==="
  $SUDO sh docker/install-composer-deps.sh
else
  echo "Aviso: docker/install-composer-deps.sh não encontrado — o build Docker pode falhar sem vendor/." >&2
fi

echo ""
echo "=== Reiniciando stack Docker ==="
COMPOSE_FILES="$($SUDO sh docker/detect-compose-files.sh)"
echo "Compose: $COMPOSE_FILES"
$SUDO env GETFY_COMPOSE_FILES="$COMPOSE_FILES" GETFY_APP_ENV=production GETFY_APP_DEBUG=false sh docker/up.sh

echo ""
echo "=== Push PWA (VAPID) + caches Laravel ==="
COMPOSE_EXEC_ARGS=""
for f in $COMPOSE_FILES; do
  if [ -n "$f" ]; then
    COMPOSE_EXEC_ARGS="$COMPOSE_EXEC_ARGS -f $f"
  fi
done
ENV_FILE="$INSTALL_DIR/.env"
STACK_ENV_FILE="$INSTALL_DIR/.docker/stack.env"
COMPOSE_ENV_ARGS=""
if [ -f "$STACK_ENV_FILE" ]; then
  COMPOSE_ENV_ARGS="--env-file $STACK_ENV_FILE"
elif [ -f "$ENV_FILE" ]; then
  COMPOSE_ENV_ARGS="--env-file $ENV_FILE"
fi
# shellcheck disable=SC2086
$SUDO docker compose $COMPOSE_EXEC_ARGS $COMPOSE_ENV_ARGS exec -T app php artisan pwa:ensure-vapid || true
# shellcheck disable=SC2086
$SUDO docker compose $COMPOSE_EXEC_ARGS $COMPOSE_ENV_ARGS exec -T app php artisan optimize:clear || true

echo ""
echo "=== Health check pós-atualização ==="
$SUDO chmod +x docker/post-update-healthcheck.sh 2>/dev/null || true
if ! $SUDO env GETFY_COMPOSE_FILES="$COMPOSE_FILES" sh docker/post-update-healthcheck.sh "$COMPOSE_FILES"; then
  echo "Health check falhou — a stack pode estar parcial. Veja os comandos de diagnóstico acima." >&2
  exit 1
fi

echo ""
echo "=== Verificação de workers (API) ==="
$SUDO sh docker/verify-workers.sh || true

echo ""
echo "Atualização concluída (git + build frontend + stack reiniciado)."
