#!/usr/bin/env sh
# Diagnóstico completo de uma instalação (salvar, KYC, PIX, workers).
# Uso: cd /opt/getfy && sh docker/diagnose-installation-health.sh
# Opções:
#   --restart-workers   Reinicia app + scheduler + workers de fila
#   --reconcile-pix     Roda payments:reconcile-mercadopago (limit=50)
#   --fix-demo-off      Define GETFY_DEMO_MODE=false no .env e limpa config
set -eu

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT_DIR"

ENV_FILE=".docker/stack.env"
RESTART_WORKERS=0
RECONCILE_PIX=0
FIX_DEMO_OFF=0

for arg in "$@"; do
  case "$arg" in
    --restart-workers) RESTART_WORKERS=1 ;;
    --reconcile-pix) RECONCILE_PIX=1 ;;
    --fix-demo-off) FIX_DEMO_OFF=1 ;;
  esac
done

if [ ! -f "$ENV_FILE" ]; then
  echo "Erro: $ENV_FILE não encontrado." >&2
  exit 1
fi

unset GETFY_DB_CONNECTION GETFY_DB_HOST GETFY_DB_PORT GETFY_DB_DATABASE GETFY_DB_USERNAME GETFY_DB_PASSWORD 2>/dev/null || true
set -a
# shellcheck disable=SC1091
. "$ENV_FILE"
set +a

COMPOSE_FILE="$(sh docker/detect-compose-files.sh 2>/dev/null || echo 'docker-compose.yml')"
COMPOSE_ARGS="-f $COMPOSE_FILE --env-file $ENV_FILE"

dc() {
  docker compose $COMPOSE_ARGS "$@"
}

echo "=== Instalação: ${GETFY_APP_URL:-?} (compose: $COMPOSE_FILE) ==="
echo ""

echo "=== Versão ==="
git log -1 --oneline 2>/dev/null || true
cat VERSION 2>/dev/null || true
echo ""

echo "=== Modo demo ==="
dc exec -T app grep -E '^GETFY_DEMO_MODE=' .env 2>/dev/null || echo "(GETFY_DEMO_MODE não definido no .env)"
dc exec -T app php artisan tinker --execute="echo config('getfy.demo_mode') ? 'demo_mode=ON (bloqueia POST/PUT)' : 'demo_mode=OFF';" 2>/dev/null || true
echo ""

echo "=== URLs e fila (stack.env) ==="
grep -E '^GETFY_APP_URL=|^GETFY_WEBHOOK_PUBLIC_URL=|^GETFY_QUEUE_CONNECTION=' "$ENV_FILE" 2>/dev/null || true
echo ""

echo "=== Containers ==="
dc ps
echo ""

echo "=== Workers / filas ==="
sh docker/verify-workers.sh 2>/dev/null || true
echo ""

echo "=== Profundidade das filas Redis ==="
for q in payments webhooks-inbound webhooks-outbound webhooks payouts metrics-tracking; do
  len="$(dc exec -T redis redis-cli LLEN "queues:$q" 2>/dev/null | tr -d '\r' || echo '?')"
  echo "  queues:$q = $len"
done
echo ""

echo "=== Mercado Pago PIX ==="
dc exec -T app php artisan payments:diagnose-mercadopago 2>/dev/null || true
echo ""

echo "=== Scheduler heartbeat (últimos 5 min) ==="
dc exec -T app php artisan tinker --execute="
\$h = \\Illuminate\\Support\\Facades\\Cache::get('schedule:last_heartbeat');
echo \$h ? 'heartbeat=' . \$h : 'heartbeat=ausente (scheduler pode estar parado)';
" 2>/dev/null || true
echo ""

echo "=== Erros recentes (laravel.log) ==="
dc exec -T app sh -c 'tail -n 20 storage/logs/laravel.log 2>/dev/null' || true
echo ""

if [ "$FIX_DEMO_OFF" -eq 1 ]; then
  echo "=== Aplicando GETFY_DEMO_MODE=false ==="
  dc exec -T app sh -c 'grep -q "^GETFY_DEMO_MODE=" .env && sed -i "s/^GETFY_DEMO_MODE=.*/GETFY_DEMO_MODE=false/" .env || echo "GETFY_DEMO_MODE=false" >> .env'
  dc exec -T app php artisan config:clear
  echo "Demo mode desligado. Reinicie o app se ainda não reiniciou."
  echo ""
fi

if [ "$RESTART_WORKERS" -eq 1 ]; then
  echo "=== Reiniciando app, scheduler e workers ==="
  case "$COMPOSE_FILE" in
    *caddy*)
      dc restart app scheduler queue
      ;;
    *)
      dc restart app scheduler worker-webhooks-in worker-payments worker-webhooks-out worker-payouts worker-metrics-tracking 2>/dev/null \
        || dc restart app scheduler worker-webhooks-in worker-payments
      ;;
  esac
  echo "Containers reiniciados."
  echo ""
fi

if [ "$RECONCILE_PIX" -eq 1 ]; then
  echo "=== Reconciliando PIX Mercado Pago pendentes ==="
  dc exec -T app php artisan payments:reconcile-mercadopago --limit=50 --min-age-minutes=0
  echo ""
fi

echo "=== Próximos passos ==="
echo "1. Webhook MP: https://\${dominio}/webhooks/gateways/mercadopago (evento Payments)"
echo "2. Saúde pagamentos: /plataforma/ops/saude-pagamentos"
echo "3. Atualizar código: bash -c \"\$(curl -fsSL https://raw.githubusercontent.com/stacker-builders/stacker-gateway/main/update.sh)\""
echo "4. Correção rápida workers+PIX: sh docker/diagnose-installation-health.sh --restart-workers --reconcile-pix"
