<?php

namespace App\Console\Commands;

use App\Gateways\GatewayRegistry;
use App\Gateways\MercadoPago\MercadoPagoDriver;
use App\Models\GatewayCredential;
use App\Models\Order;
use App\Services\MercadoPago\MercadoPagoCheckoutCompletionService;
use App\Support\GatewayPaymentCredentials;
use App\Support\GatewayWebhookUrl;
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
        $this->line('created_at: '.($order->created_at?->toDateTimeString() ?? '-'));

        $meta = is_array($order->metadata) ? $order->metadata : [];
        $this->line('metadata.mercadopago_payment_id: '.($meta['mercadopago_payment_id'] ?? '-'));
        $this->line('metadata.gateway_credential_id: '.($meta['gateway_credential_id'] ?? '-'));

        $this->newLine();
        $this->line('Webhook URL: '.GatewayWebhookUrl::forGateway('mercadopago'));

        $global = GatewayCredential::resolveForPayment(null, 'mercadopago');
        $this->line('Credencial global MP: '.($global !== null && $global->is_connected ? "ok (id {$global->id})" : 'ausente'));

        $credentials = GatewayPaymentCredentials::resolve($order->tenant_id, 'mercadopago', $order);
        if ($credentials === null) {
            $this->error('Nenhuma credencial MP resolvida para este pedido.');

            return self::FAILURE;
        }

        $token = (string) ($credentials['access_token'] ?? '');
        $this->line('Access token usado: '.($token !== '' ? substr($token, 0, 8).'…'.substr($token, -4) : '(vazio)'));

        $driver = GatewayRegistry::driver('mercadopago');
        if (! $driver instanceof MercadoPagoDriver) {
            $this->error('Driver Mercado Pago indisponível.');

            return self::FAILURE;
        }

        $paymentId = trim((string) ($order->gateway_id ?? ''));
        $this->newLine();

        if ($paymentId !== '') {
            $details = $driver->getPaymentDetails($paymentId, $credentials);
            $this->line("GET /v1/payments/{$paymentId}:");
            if ($details === null) {
                $this->warn('  (sem resposta — token errado, pagamento inexistente nesta conta ou HTTP erro)');
            } else {
                $this->line('  status mapeado: '.($details['status'] ?? '?'));
                $this->line('  status MP: '.($details['raw_status'] ?? '?'));
                $this->line('  external_reference: '.($details['external_reference'] ?? '-'));
            }
        } else {
            $this->warn('Pedido sem gateway_id — não dá para consultar payment direto.');
        }

        $foundId = $driver->findApprovedPaymentByExternalReference((string) $order->id, $credentials);
        $this->newLine();
        $this->line('Busca approved por external_reference='.(string) $order->id.':');
        $this->line($foundId !== null ? "  encontrado payment_id {$foundId}" : '  nenhum pagamento approved');

        if ($foundId !== null && $foundId !== $paymentId) {
            $details = $driver->getPaymentDetails($foundId, $credentials);
            $this->line("  GET /v1/payments/{$foundId} status: ".($details['status'] ?? '?'));
        }

        if ($this->option('apply')) {
            $completion = app(MercadoPagoCheckoutCompletionService::class);
            $completion->applyPendingForOrder($order);
            $order->refresh();

            if ($order->status !== 'completed') {
                $useId = $foundId ?? ($paymentId !== '' ? $paymentId : null);
                if ($useId !== null) {
                    $status = $driver->getTransactionStatus($useId, $credentials);
                    if ($status === 'paid') {
                        $completion->applyPaid($order, $useId, ['webhook_source' => 'debug_mercadopago']);
                        $order->refresh();
                    }
                }
            }

            $this->newLine();
            $this->line('Status após --apply: '.$order->fresh()->status);
        } else {
            $this->newLine();
            $this->line('Para tentar concluir: php artisan payments:debug-mercadopago-order '.$orderId.' --apply');
        }

        return self::SUCCESS;
    }
}
