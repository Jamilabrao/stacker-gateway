<?php

namespace App\Console\Commands;

use App\Gateways\GatewayRegistry;
use App\Gateways\MercadoPago\MercadoPagoDriver;
use App\Models\Order;
use App\Services\MercadoPago\MercadoPagoCheckoutCompletionService;
use App\Support\GatewayWebhookUrl;
use App\Support\MercadoPagoCredentialCandidates;
use Illuminate\Console\Command;

class DebugMercadoPagoOrderCommand extends Command
{
    protected $signature = 'payments:debug-mercadopago-order
                            {order : ID do pedido (orders.id)}
                            {--apply : Se aprovado no MP, marcar pedido como completed}';

    protected $description = 'Diagnóstico detalhado de um pedido PIX Mercado Pago (API, credencial, reconcile).';

    public function handle(): int
    {
        $orderId = (int) $this->argument('order');
        $order = Order::query()->find($orderId);

        if ($order === null) {
            $this->error("Pedido #{$orderId} não encontrado.");

            return self::FAILURE;
        }

        $this->info("=== Pedido #{$order->id} ===");
        $this->line('status: '.$order->status);
        $this->line('gateway: '.($order->gateway ?? '-'));
        $this->line('gateway_id: '.($order->gateway_id ?? '-'));
        $this->line('tenant_id: '.($order->tenant_id ?? '-'));
        $this->line('amount: '.$order->amount);

        $meta = is_array($order->metadata) ? $order->metadata : [];
        $this->line('metadata.gateway_credential_id: '.($meta['gateway_credential_id'] ?? '-'));

        $this->newLine();
        $this->line('Webhook URL: '.GatewayWebhookUrl::forGateway('mercadopago'));

        $driver = GatewayRegistry::driver('mercadopago');
        if (! $driver instanceof MercadoPagoDriver) {
            $this->error('Driver Mercado Pago indisponível.');

            return self::FAILURE;
        }

        $paymentId = trim((string) ($order->gateway_id ?? ''));
        $candidates = MercadoPagoCredentialCandidates::forOrder($order);

        if ($candidates === []) {
            $this->error('Nenhuma credencial MP disponível.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->line('Credenciais testadas:');
        foreach ($candidates as $candidate) {
            $credentials = $candidate['credentials'];
            $token = (string) ($credentials['access_token'] ?? '');
            $this->line("  [{$candidate['label']}] token ".($token !== '' ? substr($token, 0, 8).'…' : '(vazio)'));

            if ($paymentId !== '') {
                $details = $driver->getPaymentDetails($paymentId, $credentials);
                if ($details === null) {
                    $this->line("    GET /payments/{$paymentId}: (não encontrado nesta conta)");
                } else {
                    $this->line("    GET /payments/{$paymentId}: {$details['raw_status']} → {$details['status']}");
                }
            }

            $foundId = $driver->findApprovedPaymentByExternalReference((string) $order->id, $credentials);
            $this->line('    search external_reference='.(string) $order->id.': '.($foundId ?? 'nenhum'));
        }

        $approved = MercadoPagoCredentialCandidates::findApprovedPaymentForOrder($order, $driver);
        $this->newLine();
        if ($approved !== null) {
            $this->info("Pagamento aprovado encontrado: {$approved['payment_id']} via {$approved['label']}");
        } else {
            $this->warn('Nenhum pagamento approved encontrado em nenhuma credencial (global/tenant).');
            $this->line('Confira se o PIX foi pago na mesma conta MP do Access Token configurado.');
        }

        if ($this->option('apply') && $approved !== null) {
            app(MercadoPagoCheckoutCompletionService::class)->applyPaid($order, $approved['payment_id'], [
                'webhook_source' => 'debug_mercadopago',
                'mp_credential_label' => $approved['label'],
            ]);
            $this->line('Status após --apply: '.$order->fresh()->status);
        } elseif ($this->option('apply')) {
            $this->warn('Nada a aplicar — pagamento não encontrado como approved.');
        } else {
            $this->line('Para concluir: php artisan payments:debug-mercadopago-order '.$orderId.' --apply');
        }

        return self::SUCCESS;
    }
}
