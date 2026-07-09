<?php

namespace App\Console\Commands;

use App\Models\GatewayCredential;
use App\Models\Order;
use App\Support\GatewayWebhookUrl;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;

class DiagnoseMercadoPagoCommand extends Command
{
    protected $signature = 'payments:diagnose-mercadopago
                            {--pending-limit=5 : Quantidade de pedidos pendentes recentes para listar}';

    protected $description = 'Diagnóstico rápido de PIX Mercado Pago (URL webhook, credenciais, fila, pedidos pendentes).';

    public function handle(): int
    {
        $this->info('=== Diagnóstico Mercado Pago PIX ===');
        $this->newLine();

        $appUrl = rtrim((string) config('app.url'), '/');
        $publicUrl = trim((string) (config('getfy.webhook_public_url') ?? ''));
        $webhookUrl = GatewayWebhookUrl::forGateway('mercadopago');

        $this->line('APP_URL: '.$appUrl);
        $this->line('GETFY_WEBHOOK_PUBLIC_URL: '.($publicUrl !== '' ? $publicUrl : '(não definido)'));
        $this->line('Webhook MP: '.$webhookUrl);

        if (str_contains(strtolower($webhookUrl), 'localhost') || str_contains($webhookUrl, '127.0.0.1')) {
            $this->warn('AVISO: URL de webhook aponta para localhost — o MP não conseguirá notificar em produção.');
        }
        if (! str_starts_with($webhookUrl, 'https://')) {
            $this->warn('AVISO: URL de webhook não é HTTPS — o MP exige HTTPS.');
        }

        $globalCred = GatewayCredential::resolveForPayment(null, 'mercadopago');
        $this->newLine();
        $this->line('Credencial global MP: '.($globalCred !== null && $globalCred->is_connected ? 'conectada (id '.$globalCred->id.')' : 'ausente ou desconectada'));

        $async = (bool) config('getfy.api.inbound_webhooks_async', true);
        $queueDefault = (string) config('queue.default');
        $this->newLine();
        $this->line('API_INBOUND_WEBHOOKS_ASYNC: '.($async ? 'true' : 'false'));
        $this->line('QUEUE_CONNECTION: '.$queueDefault);

        if ($queueDefault === 'redis') {
            try {
                $len = (int) Redis::connection()->llen('queues:webhooks-inbound');
                $this->line('Fila webhooks-inbound (Redis): '.$len.' job(s)');
                if ($len > 0) {
                    $this->warn('AVISO: há jobs na fila webhooks-inbound — verifique worker-webhooks-in.');
                }
            } catch (\Throwable $e) {
                $this->warn('Não foi possível ler fila Redis: '.$e->getMessage());
            }
        } elseif ($async) {
            $this->warn('AVISO: webhooks async ativos mas fila não é redis — confirme workers.');
        }

        $limit = max(1, (int) $this->option('pending-limit'));
        $pending = Order::query()
            ->where('status', 'pending')
            ->where('gateway', 'mercadopago')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get(['id', 'gateway_id', 'created_at', 'tenant_id']);

        $this->newLine();
        $this->line('Pedidos MP pendentes recentes ('.$pending->count().'):');
        if ($pending->isEmpty()) {
            $this->line('  (nenhum)');
        } else {
            foreach ($pending as $order) {
                $this->line(sprintf(
                    '  #%d | gateway_id=%s | tenant=%s | criado=%s',
                    $order->id,
                    $order->gateway_id ?: '-',
                    $order->tenant_id ?? '-',
                    $order->created_at?->toDateTimeString() ?? '-'
                ));
            }
            $this->newLine();
            $this->line('Sugestão: php artisan payments:reconcile-mercadopago --limit=20 --min-age-minutes=0');
        }

        $this->newLine();
        $this->line('Painel MP: cadastre '.$webhookUrl.' com evento Payments (payment).');

        return self::SUCCESS;
    }
}
