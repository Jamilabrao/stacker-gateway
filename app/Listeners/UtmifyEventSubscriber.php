<?php

namespace App\Listeners;

use App\Events\BoletoGenerated;
use App\Events\OrderCompleted;
use App\Events\OrderPending;
use App\Events\OrderRefunded;
use App\Events\OrderRejected;
use App\Events\PixGenerated;
use App\Jobs\UtmifySendOrderJob;
use App\Models\Order;
use App\Models\UtmifyIntegration;
use App\Support\IntegrationJobDispatch;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

class UtmifyEventSubscriber
{
    /**
     * PIX/boleto: waiting_payment via PixGenerated/BoletoGenerated (após cobrança criada).
     * Cartão/wallets (CajuPay SDK, MP card, etc.): waiting_payment via OrderPending
     * (não há PixGenerated — senão a UTMify nunca via “initiate checkout”).
     *
     * @return array<string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            OrderPending::class => 'handleOrderPending',
            PixGenerated::class => 'handlePixGenerated',
            BoletoGenerated::class => 'handleBoletoGenerated',
            OrderCompleted::class => 'handleOrderCompleted',
            OrderRefunded::class => 'handleOrderRefunded',
            OrderRejected::class => 'handleOrderRejected',
        ];
    }

    public function handleOrderPending(OrderPending $event): void
    {
        if (! $this->shouldSendWaitingOnPending($event->order)) {
            return;
        }

        $this->dispatchForOrder($event->order, 'waiting_payment');
    }

    public function handlePixGenerated(PixGenerated $event): void
    {
        $this->dispatchForOrder($event->order, 'waiting_payment');
    }

    public function handleBoletoGenerated(BoletoGenerated $event): void
    {
        $this->dispatchForOrder($event->order, 'waiting_payment');
    }

    public function handleOrderCompleted(OrderCompleted $event): void
    {
        $approvedAt = $event->order->updated_at->utc()->format('Y-m-d H:i:s');
        $this->dispatchForOrder($event->order, 'paid', $approvedAt, null);
    }

    public function handleOrderRefunded(OrderRefunded $event): void
    {
        $refundedAt = $event->order->updated_at->utc()->format('Y-m-d H:i:s');
        $this->dispatchForOrder($event->order, 'refunded', null, $refundedAt);
    }

    public function handleOrderRejected(OrderRejected $event): void
    {
        $this->dispatchForOrder($event->order, 'refused');
    }

    /**
     * waiting_payment no OrderPending só para métodos sem PixGenerated/BoletoGenerated.
     */
    private function shouldSendWaitingOnPending(Order $order): bool
    {
        $method = $order->resolveCheckoutPaymentMethodKey();

        if (in_array($method, ['pix', 'pix_auto', 'boleto'], true)) {
            return false;
        }

        // card / apple_pay / google_pay / null (legado) → initiate checkout na UTMify
        return true;
    }

    private function dispatchForOrder(
        Order $order,
        string $utmifyStatus,
        ?string $approvedAt = null,
        ?string $refundedAt = null
    ): void {
        $tenantId = $order->tenant_id;
        $order->loadMissing('orderItems');

        $integrations = UtmifyIntegration::forTenant($tenantId)
            ->where('is_active', true)
            ->with('products:id')
            ->get();

        if ($integrations->isEmpty()) {
            Log::info('UtmifyEventSubscriber: no active integration for tenant', [
                'tenant_id' => $tenantId,
                'order_id' => $order->id,
                'status' => $utmifyStatus,
            ]);

            return;
        }

        $queue = (string) config('utmify.queue', 'utmify-tracking');
        $dispatched = 0;

        foreach ($integrations as $integration) {
            if (! $integration->api_key) {
                Log::debug('UtmifyEventSubscriber: integration skipped (no api key)', [
                    'utmify_integration_id' => $integration->id,
                    'order_id' => $order->id,
                ]);
                continue;
            }
            if (! $integration->appliesToOrder($order)) {
                Log::debug('UtmifyEventSubscriber: integration skipped (product filter)', [
                    'utmify_integration_id' => $integration->id,
                    'order_id' => $order->id,
                    'product_id' => $order->product_id,
                ]);
                continue;
            }

            $this->enqueueSendJob(
                $integration->id,
                (int) $order->id,
                $utmifyStatus,
                $approvedAt,
                $refundedAt,
                $queue
            );

            $dispatched++;

            Log::debug('UtmifyEventSubscriber: job dispatched', [
                'utmify_integration_id' => $integration->id,
                'order_id' => $order->id,
                'status' => $utmifyStatus,
                'queue' => $queue,
                'queue_connection' => config('queue.default'),
                'sync' => IntegrationJobDispatch::shouldDispatchSync(),
                'queue_size' => $this->queueSize($queue),
            ]);
        }

        if ($dispatched === 0) {
            Log::info('UtmifyEventSubscriber: no integration matched order', [
                'tenant_id' => $tenantId,
                'order_id' => $order->id,
                'status' => $utmifyStatus,
                'integrations_checked' => $integrations->count(),
            ]);
        }
    }

    private function enqueueSendJob(
        int $integrationId,
        int $orderId,
        string $utmifyStatus,
        ?string $approvedAt,
        ?string $refundedAt,
        string $queue
    ): void {
        if (IntegrationJobDispatch::shouldDispatchSync()) {
            try {
                UtmifySendOrderJob::dispatchSync(
                    $integrationId,
                    $orderId,
                    $utmifyStatus,
                    $approvedAt,
                    $refundedAt
                );
            } catch (\Throwable $e) {
                // Não abortar a cadeia de listeners (métricas, NF, Cademi, etc.).
                Log::warning('UtmifyEventSubscriber: sync send failed (listeners continue)', [
                    'utmify_integration_id' => $integrationId,
                    'order_id' => $orderId,
                    'status' => $utmifyStatus,
                    'message' => $e->getMessage(),
                ]);
            }

            return;
        }

        $pending = UtmifySendOrderJob::dispatch(
            $integrationId,
            $orderId,
            $utmifyStatus,
            $approvedAt,
            $refundedAt
        )->onQueue($queue);

        // Igual Meta: em request HTTP adia para afterResponse. Em testes o terminate não roda.
        if (! app()->runningUnitTests()) {
            $pending->afterResponse();
        }
    }

    private function queueSize(string $queue): ?int
    {
        try {
            return Queue::size($queue);
        } catch (\Throwable) {
            return null;
        }
    }
}
