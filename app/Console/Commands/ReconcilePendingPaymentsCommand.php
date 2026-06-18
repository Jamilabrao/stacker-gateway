<?php

namespace App\Console\Commands;

use App\Gateways\GatewayRegistry;
use App\Jobs\ProcessPaymentWebhook;
use App\Models\GatewayCredential;
use App\Models\Order;
use Illuminate\Console\Command;

class ReconcilePendingPaymentsCommand extends Command
{
    protected $signature = 'payments:reconcile-pending
                            {--limit=200 : Máximo de pedidos para checar por execução}
                            {--days=30 : Considerar pedidos criados nos últimos X dias}
                            {--min-age-minutes=2 : Não checar pedidos atualizados muito recentemente}
                            {--source= : Filtrar por metadata.source (ex.: pixgo)}';

    protected $description = 'Reconfirma pagamentos pendentes no gateway e aprova automaticamente quando liquidado.';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $days = max(1, (int) $this->option('days'));
        $minAgeMinutes = max(0, (int) $this->option('min-age-minutes'));
        $sourceFilter = trim((string) $this->option('source'));

        $query = Order::query()
            ->where('status', 'pending')
            ->whereNotNull('gateway')
            ->where('gateway', '!=', '')
            ->whereNotNull('gateway_id')
            ->where('gateway_id', '!=', '')
            ->where('created_at', '>=', now()->subDays($days));

        if ($sourceFilter !== '') {
            $query->where('metadata->source', $sourceFilter);
        }

        if ($minAgeMinutes > 0) {
            $query->where('updated_at', '<=', now()->subMinutes($minAgeMinutes));
        }

        $orders = $query
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get();

        $checked = 0;
        $paid = 0;
        $cancelled = 0;
        $skipped = 0;

        foreach ($orders as $order) {
            if ($this->isPastReconcileWindow($order)) {
                $skipped++;
                continue;
            }

            $checked++;

            $gatewaySlug = is_string($order->gateway) ? $order->gateway : '';
            $transactionId = is_string($order->gateway_id) ? $order->gateway_id : (string) $order->gateway_id;

            if ($gatewaySlug === '' || $transactionId === '') {
                continue;
            }

            $credential = GatewayCredential::resolveForPayment($order->tenant_id, $gatewaySlug);

            if (! $credential) {
                continue;
            }

            $driver = GatewayRegistry::driver($gatewaySlug);
            if (! $driver) {
                continue;
            }

            $credentials = $credential->getDecryptedCredentials();
            if ($credentials === []) {
                continue;
            }

            try {
                $apiStatus = $driver->getTransactionStatus($transactionId, $credentials);
            } catch (\Throwable) {
                $apiStatus = null;
            }

            if ($apiStatus === 'paid') {
                $paidEvent = $gatewaySlug === 'cajupay' ? 'checkout.payment.paid' : 'order.paid';
                $paidPayload = ['source' => 'reconcile_pending'];
                if ($gatewaySlug === 'cajupay') {
                    $paidPayload['webhook_source'] = 'reconcile_pending';
                }
                ProcessPaymentWebhook::dispatchSync($gatewaySlug, $transactionId, $paidEvent, 'paid', $paidPayload);
                $paid++;
                continue;
            }

            if ($apiStatus === 'cancelled') {
                ProcessPaymentWebhook::dispatchSync($gatewaySlug, $transactionId, 'order.cancelled', 'cancelled', [
                    'source' => 'reconcile_pending',
                ]);
                $cancelled++;
                continue;
            }
        }

        $this->info("Checados: {$checked} | Pagos: {$paid} | Cancelados: {$cancelled} | Ignorados (janela): {$skipped}");

        return self::SUCCESS;
    }

    private function isPastReconcileWindow(Order $order): bool
    {
        $meta = is_array($order->metadata) ? $order->metadata : [];
        $until = $meta['reconcile_until'] ?? null;

        if (! is_string($until) || trim($until) === '') {
            return false;
        }

        try {
            return now()->greaterThan(\Illuminate\Support\Carbon::parse($until));
        } catch (\Throwable) {
            return false;
        }
    }
}
