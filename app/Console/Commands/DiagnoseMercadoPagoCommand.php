<?php

namespace App\Console\Commands;

use App\Services\PaymentOperationsHealthService;
use App\Support\GatewayWebhookUrl;
use Illuminate\Console\Command;

class DiagnoseMercadoPagoCommand extends Command
{
    protected $signature = 'payments:diagnose-mercadopago
                            {--pending-limit=5 : Quantidade de pedidos pendentes recentes para listar}';

    protected $description = 'Diagnóstico rápido de PIX Mercado Pago (URL webhook, credenciais, fila, pedidos pendentes).';

    public function handle(PaymentOperationsHealthService $healthService): int
    {
        $this->info('=== Diagnóstico Mercado Pago PIX ===');
        $this->newLine();

        $dashboard = $healthService->buildDashboard(5);
        $webhooks = $dashboard['webhooks'] ?? [];
        $infrastructure = $dashboard['infrastructure'] ?? [];
        $mpCli = $healthService->mercadoPagoCliDiagnostics(
            max(1, (int) $this->option('pending-limit'))
        );

        $webhookUrl = GatewayWebhookUrl::forGateway('mercadopago');

        $this->line('APP_URL: '.($webhooks['app_url'] ?? '-'));
        $publicUrl = $webhooks['webhook_public_url'] ?? null;
        $this->line('GETFY_WEBHOOK_PUBLIC_URL: '.($publicUrl !== null ? $publicUrl : '(não definido)'));
        $this->line('Webhook MP: '.$webhookUrl);

        if (str_contains(strtolower($webhookUrl), 'localhost') || str_contains($webhookUrl, '127.0.0.1')) {
            $this->warn('AVISO: URL de webhook aponta para localhost — o MP não conseguirá notificar em produção.');
        }
        if (! str_starts_with($webhookUrl, 'https://')) {
            $this->warn('AVISO: URL de webhook não é HTTPS — o MP exige HTTPS.');
        }

        $this->newLine();
        $this->line('Credencial global MP: '.$mpCli['global_credential']);

        foreach ($mpCli['tenant_credentials'] as $tenantId => $status) {
            $this->line('Credencial tenant '.(int) $tenantId.': '.$status);
        }

        $async = (bool) ($infrastructure['inbound_webhooks_async'] ?? true);
        $queueDefault = (string) ($infrastructure['queue_connection'] ?? 'sync');
        $this->newLine();
        $this->line('API_INBOUND_WEBHOOKS_ASYNC: '.($async ? 'true' : 'false'));
        $this->line('QUEUE_CONNECTION: '.$queueDefault);

        $inboundDepth = $infrastructure['queues']['webhooks-inbound']['depth'] ?? null;
        $inboundError = $infrastructure['queues']['webhooks-inbound']['error'] ?? null;

        if ($inboundDepth !== null) {
            $this->line('Fila webhooks-inbound: '.$inboundDepth.' job(s)');
            if ($inboundDepth > 0) {
                $profile = is_file(base_path('.docker/compose-profile'))
                    ? trim((string) file_get_contents(base_path('.docker/compose-profile')))
                    : '';
                $hint = $profile === 'caddy'
                    ? 'verifique o container queue (perfil Caddy)'
                    : 'verifique worker-webhooks-in';
                $this->warn("AVISO: há jobs na fila webhooks-inbound — {$hint}.");
            }
        } elseif ($inboundError !== null) {
            $this->warn('Não foi possível ler fila webhooks-inbound: '.$inboundError);
        } elseif ($async && $queueDefault !== 'redis') {
            $this->warn('AVISO: webhooks async ativos mas fila não é redis — confirme workers.');
        }

        $this->newLine();
        $this->line('Pedidos MP pendentes recentes ('.count($mpCli['pending_orders']).'):');
        if ($mpCli['pending_orders'] === []) {
            $this->line('  (nenhum)');
        } else {
            foreach ($mpCli['pending_orders'] as $order) {
                $this->line(sprintf(
                    '  #%d | gateway_id=%s | tenant=%s | criado=%s',
                    $order['id'],
                    $order['gateway_id'],
                    $order['tenant_id'],
                    $order['created_at']
                ));
            }
            $this->newLine();
            $this->line('Sugestão: php artisan payments:reconcile-mercadopago --limit=20 --min-age-minutes=0');
            $this->line('Painel: /plataforma/ops/saude-pagamentos');
        }

        $scheduleHealthy = (bool) ($infrastructure['schedule_healthy'] ?? false);
        if (! $scheduleHealthy) {
            $this->newLine();
            $this->warn('AVISO: scheduler sem heartbeat recente — reconciliação automática pode não estar rodando.');
        }

        $this->newLine();
        $this->line('Painel MP: cadastre '.$webhookUrl.' com evento Payments (payment).');

        return self::SUCCESS;
    }
}
