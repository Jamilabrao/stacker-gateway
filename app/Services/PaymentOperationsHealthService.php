<?php

namespace App\Services;

use App\Gateways\CajuPay\CajuPayDriver;
use App\Gateways\GatewayRegistry;
use App\Gateways\MercadoPago\MercadoPagoDriver;
use App\Jobs\ProcessPaymentWebhook;
use App\Models\GatewayCredential;
use App\Models\Order;
use App\Services\MercadoPago\MercadoPagoCheckoutCompletionService;
use App\Support\GatewayPaymentCredentials;
use App\Support\GatewayWebhookTelemetry;
use App\Support\GatewayWebhookUrl;
use App\Support\MercadoPagoCredentialCandidates;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class PaymentOperationsHealthService
{
    private const MONITORED_GATEWAYS = ['mercadopago', 'cajupay'];

    private const QUEUE_NAMES = ['webhooks-inbound', 'payments', 'default'];

    /**
     * @return array<string, mixed>
     */
    public function buildDashboard(int $pendingLimit = 30): array
    {
        $infrastructure = $this->buildInfrastructure();
        $webhooks = $this->buildWebhookConfig();
        $telemetry = $this->buildTelemetry();
        $pendingSummary = $this->buildPendingSummary();
        $pendingOrders = $this->buildPendingOrdersList($pendingLimit);
        $issues = $this->buildIssues($infrastructure, $webhooks, $telemetry, $pendingSummary, $pendingOrders);

        return [
            'infrastructure' => $infrastructure,
            'webhooks' => $webhooks,
            'telemetry' => $telemetry,
            'pending_summary' => $pendingSummary,
            'pending_orders' => $pendingOrders,
            'issues' => $issues,
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function probeOrder(Order $order): array
    {
        $gateway = is_string($order->gateway) ? trim($order->gateway) : '';

        if (! in_array($gateway, self::MONITORED_GATEWAYS, true)) {
            return [
                'order_id' => $order->id,
                'gateway' => $gateway,
                'status' => 'unsupported',
                'message' => 'Gateway não suportado nesta ferramenta.',
                'gateway_api_status' => null,
                'has_credentials' => false,
                'reconcile_window_expired' => $this->isPastReconcileWindow($order),
                'details' => [],
            ];
        }

        $credentials = GatewayPaymentCredentials::resolve($order->tenant_id, $gateway, $order);
        $hasCredentials = $credentials !== null && $credentials !== [];

        $result = [
            'order_id' => $order->id,
            'gateway' => $gateway,
            'order_status' => $order->status,
            'gateway_id' => $order->gateway_id,
            'tenant_id' => $order->tenant_id,
            'has_credentials' => $hasCredentials,
            'reconcile_window_expired' => $this->isPastReconcileWindow($order),
            'gateway_api_status' => null,
            'can_reconcile' => false,
            'message' => null,
            'details' => [],
        ];

        if ($order->status !== 'pending') {
            $result['status'] = 'not_pending';
            $result['message'] = 'Pedido não está pendente.';

            return $result;
        }

        if (! $hasCredentials) {
            $result['status'] = 'no_credentials';
            $result['message'] = 'Credencial do gateway não encontrada para este pedido.';

            return $result;
        }

        if ($gateway === 'mercadopago') {
            return array_merge($result, $this->probeMercadoPagoOrder($order));
        }

        return array_merge($result, $this->probeCajuPayOrder($order, $credentials));
    }

    /**
     * @return array{checked: int, paid: int, cancelled: int, skipped: int, errors: list<string>}
     */
    public function reconcileBatch(int $limit = 50): array
    {
        $limit = max(1, min(100, $limit));
        $errors = [];

        try {
            Artisan::call('payments:reconcile-pending', [
                '--limit' => $limit,
                '--days' => 45,
                '--min-age-minutes' => 0,
            ]);
            $pendingOutput = trim(Artisan::output());
        } catch (\Throwable $e) {
            $errors[] = 'reconcile-pending: '.$e->getMessage();
            $pendingOutput = '';
        }

        try {
            Artisan::call('payments:reconcile-mercadopago', [
                '--limit' => $limit,
                '--days' => 45,
                '--min-age-minutes' => 0,
            ]);
            $mpOutput = trim(Artisan::output());
        } catch (\Throwable $e) {
            $errors[] = 'reconcile-mercadopago: '.$e->getMessage();
            $mpOutput = '';
        }

        $pendingStats = $this->parseReconcileOutput($pendingOutput);
        $mpStats = $this->parseReconcileOutput($mpOutput);

        return [
            'checked' => $pendingStats['checked'] + $mpStats['checked'],
            'paid' => $pendingStats['paid'] + $mpStats['paid'],
            'cancelled' => $pendingStats['cancelled'] + $mpStats['cancelled'],
            'skipped' => $pendingStats['skipped'] + $mpStats['skipped'],
            'errors' => $errors,
            'pending_output' => $pendingOutput,
            'mercadopago_output' => $mpOutput,
        ];
    }

    /**
     * @return array{success: bool, message: string, order_status: string, probe: array<string, mixed>}
     */
    public function reconcileOrder(Order $order): array
    {
        $probe = $this->probeOrder($order);

        if ($order->status !== 'pending') {
            return [
                'success' => false,
                'message' => 'Pedido não está pendente.',
                'order_status' => $order->status,
                'probe' => $probe,
            ];
        }

        $gateway = is_string($order->gateway) ? trim($order->gateway) : '';

        if ($gateway === 'mercadopago') {
            return $this->reconcileMercadoPagoOrder($order, $probe);
        }

        if ($gateway === 'cajupay') {
            return $this->reconcileCajuPayOrder($order, $probe);
        }

        return [
            'success' => false,
            'message' => 'Gateway não suportado.',
            'order_status' => $order->status,
            'probe' => $probe,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildInfrastructure(): array
    {
        $scheduleHeartbeat = Cache::get('schedule_heartbeat');
        $queueHeartbeat = Cache::get('queue_heartbeat');
        $queueDefault = (string) config('queue.default', 'sync');
        $asyncWebhooks = (bool) config('getfy.api.inbound_webhooks_async', true);
        $queues = $this->readQueueDepths($queueDefault);

        return [
            'schedule_heartbeat' => is_string($scheduleHeartbeat) ? $scheduleHeartbeat : null,
            'schedule_healthy' => self::isHeartbeatRecent($scheduleHeartbeat, 5),
            'queue_heartbeat' => is_string($queueHeartbeat) ? $queueHeartbeat : null,
            'queue_healthy' => self::isHeartbeatRecent($queueHeartbeat, 5),
            'queue_connection' => $queueDefault,
            'inbound_webhooks_async' => $asyncWebhooks,
            'queues' => $queues,
            'cron_secret_configured' => trim((string) (config('getfy.cron_secret') ?? '')) !== '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildWebhookConfig(): array
    {
        $appUrl = rtrim((string) config('app.url'), '/');
        $publicUrl = trim((string) (config('getfy.webhook_public_url') ?? ''));

        $gateways = [];
        foreach (self::MONITORED_GATEWAYS as $slug) {
            $url = GatewayWebhookUrl::forGateway($slug);
            $gateways[$slug] = [
                'url' => $url,
                'is_https' => str_starts_with($url, 'https://'),
                'is_localhost' => str_contains(strtolower($url), 'localhost')
                    || str_contains($url, '127.0.0.1'),
            ];
        }

        return [
            'app_url' => $appUrl,
            'webhook_public_url' => $publicUrl !== '' ? $publicUrl : null,
            'gateways' => $gateways,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildTelemetry(): array
    {
        $items = [];
        foreach (self::MONITORED_GATEWAYS as $slug) {
            $lastAt = GatewayWebhookTelemetry::lastReceivedAt($slug);
            $items[$slug] = [
                'last_received_at' => $lastAt,
                'minutes_ago' => $lastAt !== null ? $this->minutesAgo($lastAt) : null,
            ];
        }

        return ['gateways' => $items];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPendingSummary(): array
    {
        $counts = [];
        foreach (self::MONITORED_GATEWAYS as $slug) {
            $counts[$slug] = Order::query()
                ->where('status', 'pending')
                ->where('gateway', $slug)
                ->where('created_at', '>=', now()->subDays(45))
                ->count();
        }

        return [
            'by_gateway' => $counts,
            'total' => array_sum($counts),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildPendingOrdersList(int $limit): array
    {
        $orders = Order::query()
            ->where('status', 'pending')
            ->whereIn('gateway', self::MONITORED_GATEWAYS)
            ->where('created_at', '>=', now()->subDays(45))
            ->orderByDesc('created_at')
            ->limit(max(1, min(50, $limit)))
            ->get();

        return $orders->map(function (Order $order): array {
            $meta = is_array($order->metadata) ? $order->metadata : [];
            $gatewayId = trim((string) ($order->gateway_id ?? ''));
            $credentials = GatewayPaymentCredentials::resolve($order->tenant_id, (string) $order->gateway, $order);

            return [
                'id' => $order->id,
                'gateway' => $order->gateway,
                'gateway_id' => $gatewayId !== '' ? $gatewayId : null,
                'tenant_id' => $order->tenant_id,
                'amount' => $order->amount,
                'created_at' => $order->created_at?->toIso8601String(),
                'age_minutes' => $order->created_at !== null
                    ? (int) $order->created_at->diffInMinutes(now())
                    : null,
                'has_gateway_id' => $gatewayId !== '',
                'has_credentials' => $credentials !== null && $credentials !== [],
                'reconcile_until' => is_string($meta['reconcile_until'] ?? null) ? $meta['reconcile_until'] : null,
                'reconcile_window_expired' => $this->isPastReconcileWindow($order),
            ];
        })->values()->all();
    }

    /**
     * @param  array<string, mixed>  $infrastructure
     * @param  array<string, mixed>  $webhooks
     * @param  array<string, mixed>  $telemetry
     * @param  array<string, mixed>  $pendingSummary
     * @param  list<array<string, mixed>>  $pendingOrders
     * @return list<array{severity: string, code: string, message: string, action: ?string}>
     */
    private function buildIssues(
        array $infrastructure,
        array $webhooks,
        array $telemetry,
        array $pendingSummary,
        array $pendingOrders,
    ): array {
        $issues = [];

        if (! $infrastructure['schedule_healthy']) {
            $issues[] = [
                'severity' => 'critical',
                'code' => 'schedule_stale',
                'message' => 'O agendador (cron/scheduler) não registrou heartbeat nos últimos 5 minutos. A reconciliação automática pode não estar rodando.',
                'action' => 'Verifique o container scheduler ou configure o cron em /cron?token=...',
            ];
        }

        $webhooksInboundDepth = (int) ($infrastructure['queues']['webhooks-inbound']['depth'] ?? 0);
        if ($webhooksInboundDepth > 0 && ! $infrastructure['queue_healthy']) {
            $issues[] = [
                'severity' => 'critical',
                'code' => 'webhooks_queue_stuck',
                'message' => "Há {$webhooksInboundDepth} job(s) na fila webhooks-inbound e os workers parecem parados.",
                'action' => 'Execute docker/verify-workers.sh ou reinicie o worker webhooks-inbound.',
            ];
        } elseif ($webhooksInboundDepth > 50) {
            $issues[] = [
                'severity' => 'warning',
                'code' => 'webhooks_queue_backlog',
                'message' => "Fila webhooks-inbound com {$webhooksInboundDepth} job(s) — processamento pode estar lento.",
                'action' => 'Verifique logs do worker webhooks-inbound.',
            ];
        }

        foreach ($webhooks['gateways'] ?? [] as $slug => $config) {
            if (! ($config['is_https'] ?? false)) {
                $issues[] = [
                    'severity' => 'critical',
                    'code' => "webhook_not_https_{$slug}",
                    'message' => "URL de webhook {$slug} não é HTTPS — o gateway não conseguirá notificar em produção.",
                    'action' => 'Defina GETFY_WEBHOOK_PUBLIC_URL com URL HTTPS pública.',
                ];
            }
            if ($config['is_localhost'] ?? false) {
                $issues[] = [
                    'severity' => 'critical',
                    'code' => "webhook_localhost_{$slug}",
                    'message' => "URL de webhook {$slug} aponta para localhost.",
                    'action' => 'Corrija GETFY_WEBHOOK_PUBLIC_URL no .env.',
                ];
            }
        }

        foreach ($telemetry['gateways'] ?? [] as $slug => $item) {
            $minutesAgo = $item['minutes_ago'] ?? null;
            if ($minutesAgo === null && ($pendingSummary['by_gateway'][$slug] ?? 0) > 0) {
                $issues[] = [
                    'severity' => 'warning',
                    'code' => "no_webhook_received_{$slug}",
                    'message' => "Nenhum webhook {$slug} registrado recentemente, mas há pedidos pendentes.",
                    'action' => 'Verifique se a URL de webhook está cadastrada no painel do gateway e se o servidor é acessível publicamente.',
                ];
            } elseif (is_int($minutesAgo) && $minutesAgo > 24 * 60 && ($pendingSummary['by_gateway'][$slug] ?? 0) > 0) {
                $issues[] = [
                    'severity' => 'warning',
                    'code' => "webhook_stale_{$slug}",
                    'message' => "Último webhook {$slug} recebido há mais de 24h.",
                    'action' => 'Confirme que o gateway consegue alcançar a URL pública do servidor.',
                ];
            }
        }

        $noCredentials = collect($pendingOrders)->filter(fn (array $o) => ! ($o['has_credentials'] ?? false))->count();
        if ($noCredentials > 0) {
            $issues[] = [
                'severity' => 'warning',
                'code' => 'pending_without_credentials',
                'message' => "{$noCredentials} pedido(s) pendente(s) sem credencial resolvível para consulta no gateway.",
                'action' => 'Verifique Financeiro → Adquirentes (MP) e Contas CajuPay.',
            ];
        }

        $noGatewayId = collect($pendingOrders)->filter(fn (array $o) => ! ($o['has_gateway_id'] ?? false))->count();
        if ($noGatewayId > 0) {
            $issues[] = [
                'severity' => 'info',
                'code' => 'pending_without_gateway_id',
                'message' => "{$noGatewayId} pedido(s) pendente(s) sem gateway_id — reconciliação por API pode falhar.",
                'action' => 'Para MP, tente reconciliar por external_reference (order id).',
            ];
        }

        $expiredWindow = collect($pendingOrders)->filter(fn (array $o) => $o['reconcile_window_expired'] ?? false)->count();
        if ($expiredWindow > 0) {
            $issues[] = [
                'severity' => 'info',
                'code' => 'reconcile_window_expired',
                'message' => "{$expiredWindow} pedido(s) com janela de reconciliação automática expirada.",
                'action' => 'Use reconciliação manual nesta página (com confirmação no gateway).',
            ];
        }

        if (($pendingSummary['total'] ?? 0) === 0 && $issues === []) {
            $issues[] = [
                'severity' => 'info',
                'code' => 'all_clear',
                'message' => 'Nenhum pedido pendente monitorado e infraestrutura aparenta saudável.',
                'action' => null,
            ];
        }

        return $issues;
    }

    /**
     * @return array<string, mixed>
     */
    private function probeMercadoPagoOrder(Order $order): array
    {
        $driver = GatewayRegistry::driver('mercadopago');
        if (! $driver instanceof MercadoPagoDriver) {
            return [
                'status' => 'driver_unavailable',
                'message' => 'Driver Mercado Pago indisponível.',
                'gateway_api_status' => null,
                'can_reconcile' => false,
                'details' => [],
            ];
        }

        $details = [];
        $candidates = MercadoPagoCredentialCandidates::forOrder($order);
        $paymentId = trim((string) ($order->gateway_id ?? ''));

        foreach ($candidates as $candidate) {
            $credentials = $candidate['credentials'];
            $entry = ['label' => $candidate['label']];

            if ($paymentId !== '') {
                $apiDetails = $driver->getPaymentDetails($paymentId, $credentials);
                $entry['payment_lookup'] = $apiDetails !== null
                    ? ($apiDetails['raw_status'] ?? $apiDetails['status'] ?? 'unknown')
                    : 'not_found';
            }

            $foundId = $driver->findApprovedPaymentByExternalReference((string) $order->id, $credentials);
            $entry['external_reference_lookup'] = $foundId ?? 'none';
            $details[] = $entry;
        }

        $approved = MercadoPagoCredentialCandidates::findApprovedPaymentForOrder($order, $driver);
        $apiStatus = $approved !== null ? 'paid' : null;

        if ($apiStatus === null && $paymentId !== '' && $candidates !== []) {
            $apiStatus = $driver->getTransactionStatus($paymentId, $candidates[0]['credentials']);
        }

        return [
            'status' => $apiStatus === 'paid' ? 'paid' : ($apiStatus ?? 'unknown'),
            'gateway_api_status' => $apiStatus,
            'can_reconcile' => $approved !== null,
            'message' => $approved !== null
                ? 'Pagamento aprovado encontrado no Mercado Pago.'
                : 'Pagamento não encontrado como aprovado no Mercado Pago.',
            'details' => $details,
            'approved_payment_id' => $approved['payment_id'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @return array<string, mixed>
     */
    private function probeCajuPayOrder(Order $order, array $credentials): array
    {
        $driver = GatewayRegistry::driver('cajupay');
        if (! $driver instanceof CajuPayDriver) {
            return [
                'status' => 'driver_unavailable',
                'message' => 'Driver CajuPay indisponível.',
                'gateway_api_status' => null,
                'can_reconcile' => false,
                'details' => [],
            ];
        }

        $transactionId = trim((string) ($order->gateway_id ?? ''));
        if ($transactionId === '') {
            return [
                'status' => 'no_gateway_id',
                'message' => 'Pedido sem gateway_id — não é possível consultar na API.',
                'gateway_api_status' => null,
                'can_reconcile' => false,
                'details' => [],
            ];
        }

        try {
            $apiStatus = $driver->getTransactionStatus($transactionId, $credentials);
        } catch (\Throwable $e) {
            return [
                'status' => 'api_error',
                'message' => 'Erro ao consultar CajuPay: '.$e->getMessage(),
                'gateway_api_status' => null,
                'can_reconcile' => false,
                'details' => [],
            ];
        }

        return [
            'status' => $apiStatus ?? 'unknown',
            'gateway_api_status' => $apiStatus,
            'can_reconcile' => $apiStatus === 'paid',
            'message' => $apiStatus === 'paid'
                ? 'Pagamento confirmado como pago na API CajuPay.'
                : 'Pagamento ainda não está pago na API CajuPay ('.($apiStatus ?? 'desconhecido').').',
            'details' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $probe
     * @return array{success: bool, message: string, order_status: string, probe: array<string, mixed>}
     */
    private function reconcileMercadoPagoOrder(Order $order, array $probe): array
    {
        $completion = app(MercadoPagoCheckoutCompletionService::class);
        $completion->applyPendingForOrder($order);
        $order->refresh();

        if ($order->status === 'completed') {
            return [
                'success' => true,
                'message' => 'Pedido concluído a partir de webhook pendente em cache.',
                'order_status' => $order->status,
                'probe' => $this->probeOrder($order),
            ];
        }

        $driver = GatewayRegistry::driver('mercadopago');
        if (! $driver instanceof MercadoPagoDriver) {
            return [
                'success' => false,
                'message' => 'Driver Mercado Pago indisponível.',
                'order_status' => $order->status,
                'probe' => $probe,
            ];
        }

        $approved = MercadoPagoCredentialCandidates::findApprovedPaymentForOrder($order, $driver);
        if ($approved === null) {
            return [
                'success' => false,
                'message' => 'Gateway não confirmou pagamento aprovado. Pedido não alterado.',
                'order_status' => $order->status,
                'probe' => $probe,
            ];
        }

        $completion->applyPaid($order, $approved['payment_id'], [
            'webhook_source' => 'payment_health_reconcile',
            'source' => 'payment_health_reconcile',
            'mp_credential_label' => $approved['label'],
        ]);

        $order->refresh();

        return [
            'success' => $order->status === 'completed',
            'message' => $order->status === 'completed'
                ? 'Pedido marcado como pago após confirmação no Mercado Pago.'
                : 'Falha ao concluir pedido após confirmação no gateway.',
            'order_status' => $order->status,
            'probe' => $this->probeOrder($order),
        ];
    }

    /**
     * @param  array<string, mixed>  $probe
     * @return array{success: bool, message: string, order_status: string, probe: array<string, mixed>}
     */
    private function reconcileCajuPayOrder(Order $order, array $probe): array
    {
        if (($probe['gateway_api_status'] ?? null) !== 'paid' && ! ($probe['can_reconcile'] ?? false)) {
            $freshProbe = $this->probeOrder($order);
            if (($freshProbe['gateway_api_status'] ?? null) !== 'paid') {
                return [
                    'success' => false,
                    'message' => 'Gateway não confirmou pagamento. Pedido não alterado.',
                    'order_status' => $order->status,
                    'probe' => $freshProbe,
                ];
            }
            $probe = $freshProbe;
        }

        $transactionId = trim((string) ($order->gateway_id ?? ''));
        if ($transactionId === '') {
            return [
                'success' => false,
                'message' => 'Pedido sem gateway_id.',
                'order_status' => $order->status,
                'probe' => $probe,
            ];
        }

        ProcessPaymentWebhook::dispatchSync(
            'cajupay',
            $transactionId,
            'checkout.payment.paid',
            'paid',
            [
                'source' => 'payment_health_reconcile',
                'webhook_source' => 'payment_health_reconcile',
            ]
        );

        $order->refresh();

        return [
            'success' => $order->status === 'completed',
            'message' => $order->status === 'completed'
                ? 'Pedido marcado como pago após confirmação na API CajuPay.'
                : 'Processamento executado, mas pedido permanece pendente.',
            'order_status' => $order->status,
            'probe' => $this->probeOrder($order),
        ];
    }

    /**
     * @return array<string, array{depth: int|null, error: ?string}>
     */
    private function readQueueDepths(string $queueConnection): array
    {
        $result = [];
        foreach (self::QUEUE_NAMES as $name) {
            $result[$name] = ['depth' => null, 'error' => null];
        }

        if ($queueConnection === 'redis') {
            try {
                foreach (self::QUEUE_NAMES as $name) {
                    $result[$name]['depth'] = (int) Redis::connection()->llen('queues:'.$name);
                }
            } catch (\Throwable $e) {
                foreach (self::QUEUE_NAMES as $name) {
                    $result[$name]['error'] = $e->getMessage();
                }
            }

            return $result;
        }

        if ($queueConnection === 'database') {
            try {
                foreach (self::QUEUE_NAMES as $name) {
                    $result[$name]['depth'] = (int) DB::table('jobs')->where('queue', $name)->count();
                }
            } catch (\Throwable $e) {
                foreach (self::QUEUE_NAMES as $name) {
                    $result[$name]['error'] = $e->getMessage();
                }
            }
        }

        return $result;
    }

    /**
     * @return array{checked: int, paid: int, cancelled: int, skipped: int}
     */
    private function parseReconcileOutput(string $output): array
    {
        $stats = ['checked' => 0, 'paid' => 0, 'cancelled' => 0, 'skipped' => 0];

        if (preg_match('/Checados:\s*(\d+)\s*\|\s*Pagos:\s*(\d+)/', $output, $m)) {
            $stats['checked'] = (int) $m[1];
            $stats['paid'] = (int) $m[2];
        }

        if (preg_match('/Cancelados:\s*(\d+)/', $output, $m)) {
            $stats['cancelled'] = (int) $m[1];
        }

        if (preg_match('/Ignorados \(janela\):\s*(\d+)/', $output, $m)) {
            $stats['skipped'] = (int) $m[1];
        }

        return $stats;
    }

    private function isPastReconcileWindow(Order $order): bool
    {
        $meta = is_array($order->metadata) ? $order->metadata : [];
        $until = $meta['reconcile_until'] ?? null;

        if (! is_string($until) || trim($until) === '') {
            return false;
        }

        try {
            return now()->greaterThan(Carbon::parse($until));
        } catch (\Throwable) {
            return false;
        }
    }

    private function minutesAgo(string $iso): ?int
    {
        try {
            return (int) Carbon::parse($iso)->diffInMinutes(now());
        } catch (\Throwable) {
            return null;
        }
    }

    public static function isHeartbeatRecent(?string $value, int $minutes = 5): bool
    {
        if ($value === null || $value === '') {
            return false;
        }

        try {
            return Carbon::parse($value)->gte(now()->subMinutes($minutes));
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function mercadoPagoCliDiagnostics(int $pendingLimit = 5): array
    {
        $globalCred = GatewayCredential::resolveForPayment(null, 'mercadopago');

        $tenantCredentials = [];
        $tenantIds = Order::query()
            ->where('status', 'pending')
            ->where('gateway', 'mercadopago')
            ->distinct()
            ->pluck('tenant_id')
            ->filter()
            ->take(5);

        foreach ($tenantIds as $tenantId) {
            $tenantCred = GatewayCredential::query()
                ->where('tenant_id', $tenantId)
                ->where('gateway_slug', 'mercadopago')
                ->where('is_connected', true)
                ->first();
            $tenantCredentials[(int) $tenantId] = $tenantCred !== null
                ? "conectada (id {$tenantCred->id})"
                : 'ausente';
        }

        $pending = Order::query()
            ->where('status', 'pending')
            ->where('gateway', 'mercadopago')
            ->orderByDesc('created_at')
            ->limit(max(1, $pendingLimit))
            ->get(['id', 'gateway_id', 'created_at', 'tenant_id']);

        return [
            'global_credential' => $globalCred !== null && $globalCred->is_connected
                ? 'conectada (id '.$globalCred->id.')'
                : 'ausente ou desconectada',
            'tenant_credentials' => $tenantCredentials,
            'pending_orders' => $pending->map(fn (Order $o) => [
                'id' => $o->id,
                'gateway_id' => $o->gateway_id ?: '-',
                'tenant_id' => $o->tenant_id ?? '-',
                'created_at' => $o->created_at?->toDateTimeString() ?? '-',
            ])->all(),
        ];
    }
}
