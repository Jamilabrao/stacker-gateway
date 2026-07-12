#!/usr/bin/env sh
# Correção rápida para instalação com PIX parado, workers down ou demo ligado por engano.
# Uso: cd /opt/getfy && sh docker/fix-installation-issues.sh
set -eu

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT_DIR"

echo "=== Diagnóstico ==="
sh docker/diagnose-installation-health.sh

echo ""
echo "=== Aplicando correções (workers + reconcile PIX) ==="
sh docker/diagnose-installation-health.sh --restart-workers --reconcile-pix

DEMO_LINE="$(docker compose -f "$(sh docker/detect-compose-files.sh)" --env-file .docker/stack.env exec -T app grep -E '^GETFY_DEMO_MODE=' .env 2>/dev/null | tr -d '\r' || true)"
case "$DEMO_LINE" in
  *true*|*TRUE*|*True*)
    echo ""
    echo "Modo demo detectado como ativo — desligando..."
    sh docker/diagnose-installation-health.sh --fix-demo-off
    sh docker/diagnose-installation-health.sh --restart-workers
    ;;
esac

echo ""
echo "=== Atualizar código (login, KYC, sidebar iOS) ==="
echo "Opção A — git público:"
echo '  bash -c "$(curl -fsSL https://raw.githubusercontent.com/stacker-builders/stacker-gateway/main/update.sh)"'
echo ""
echo "Opção B — Stacker Agent (repo privado): painel Stacker → Instalações → novapay.com.br → Atualizar"
echo ""
echo "Depois teste: salvar infoprodutor, upload KYC, PIX e sidebar no iPhone."
