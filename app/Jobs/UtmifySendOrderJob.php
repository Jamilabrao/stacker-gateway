<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\UtmifyIntegration;
use App\Models\UtmifyOrderDispatch;
use App\Services\SellerIntegrationVisibility;
use App\Services\UtmifyService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class UtmifySendOrderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries;

    public int $timeout;

    public function __construct(
        public int $utmifyIntegrationId,
        public int $orderId,
        public string $utmifyStatus,
        public ?string $approvedAt = null,
        public ?string $refundedAt = null
    ) {
        $this->tries = (int) config('utmify.retry.tries', 10);
        $this->timeout = (int) config('utmify.retry.timeout', 60);
        $this->onQueue((string) config('utmify.queue', 'utmify-tracking'));
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        $backoff = config('utmify.retry.backoff', [30, 60, 120, 300, 600, 1200, 1800, 3600]);

        return is_array($backoff) ? array_map('intval', $backoff) : [30, 60, 120];
    }

    public function handle(UtmifyService $utmifyService): void
    {
        $integration = UtmifyIntegration::with('products:id')
            ->find($this->utmifyIntegrationId);

        if (! $integration || ! $integration->is_active || ! $integration->api_key) {
            return;
        }

        $tenantId = $integration->tenant_id !== null ? (int) $integration->tenant_id : null;
        if (! SellerIntegrationVisibility::effectiveForTenant(SellerIntegrationVisibility::UTMIFY, $tenantId)) {
            return;
        }

        $order = Order::with(['user', 'product', 'orderItems.product', 'orderItems.productOffer', 'orderItems.subscriptionPlan'])
            ->find($this->orderId);

        if (! $order) {
            return;
        }

        if ($this->shouldSkipSend($order)) {
            Log::debug('UtmifySendOrderJob skipped', [
                'order_id' => $this->orderId,
                'utmify_integration_id' => $this->utmifyIntegrationId,
                'status' => $this->utmifyStatus,
                'order_status' => $order->status,
            ]);

            return;
        }

        $dispatch = UtmifyOrderDispatch::query()->firstOrCreate(
            [
                'order_id' => $this->orderId,
                'utmify_integration_id' => $this->utmifyIntegrationId,
                'utmify_status' => $this->utmifyStatus,
            ],
            [
                'tenant_id' => $order->tenant_id,
                'dispatch_status' => UtmifyOrderDispatch::DISPATCH_PENDING,
                'attempts' => 0,
            ]
        );

        if ($dispatch->dispatch_status === UtmifyOrderDispatch::DISPATCH_SENT) {
            return;
        }

        $dispatch->increment('attempts');

        try {
            $utmifyService->sendOrder($order, $this->utmifyStatus, $integration->api_key, [
                'approved_at' => $this->approvedAt,
                'refunded_at' => $this->refundedAt,
            ]);

            $dispatch->update([
                'dispatch_status' => UtmifyOrderDispatch::DISPATCH_SENT,
                'sent_at' => now(),
                'last_error' => null,
            ]);

            $this->markOrderSentMetadata($order);

            Log::info('UtmifySendOrderJob sent', [
                'order_id' => $this->orderId,
                'utmify_integration_id' => $this->utmifyIntegrationId,
                'status' => $this->utmifyStatus,
            ]);
        } catch (\Throwable $e) {
            $dispatch->update([
                'dispatch_status' => UtmifyOrderDispatch::DISPATCH_PENDING,
                'last_error' => mb_substr($e->getMessage(), 0, 500),
            ]);

            Log::warning('UtmifySendOrderJob failed', [
                'order_id' => $this->orderId,
                'utmify_integration_id' => $this->utmifyIntegrationId,
                'status' => $this->utmifyStatus,
                'attempt' => $this->attempts(),
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function failed(?\Throwable $exception): void
    {
        $order = Order::query()->find($this->orderId);
        if (! $order) {
            return;
        }

        $meta = is_array($order->metadata) ? $order->metadata : [];
        if ($this->utmifyStatus === 'paid' && ! empty($meta['utmify_paid_sent_at'])) {
            return;
        }

        UtmifyOrderDispatch::query()
            ->where('order_id', $this->orderId)
            ->where('utmify_integration_id', $this->utmifyIntegrationId)
            ->where('utmify_status', $this->utmifyStatus)
            ->update([
                'dispatch_status' => UtmifyOrderDispatch::DISPATCH_FAILED,
                'last_error' => $exception !== null
                    ? mb_substr($exception->getMessage(), 0, 500)
                    : null,
            ]);

        $meta['utmify_failed_at'] = now()->toIso8601String();
        if ($exception !== null) {
            $meta['utmify_last_error'] = mb_substr($exception->getMessage(), 0, 500);
        }
        $order->update(['metadata' => $meta]);

        Log::error('UtmifySendOrderJob failed after retries', [
            'order_id' => $this->orderId,
            'utmify_integration_id' => $this->utmifyIntegrationId,
            'status' => $this->utmifyStatus,
            'message' => $exception?->getMessage(),
        ]);
    }

    private function markOrderSentMetadata(Order $order): void
    {
        $meta = is_array($order->metadata) ? $order->metadata : [];
        $now = now()->toIso8601String();

        if ($this->utmifyStatus === 'paid') {
            $meta['utmify_paid_sent_at'] = $now;
            unset($meta['utmify_last_error'], $meta['utmify_failed_at']);
        } elseif ($this->utmifyStatus === 'waiting_payment') {
            $meta['utmify_waiting_sent_at'] = $now;
        }

        $order->update(['metadata' => $meta]);
    }

    private function shouldSkipSend(Order $order): bool
    {
        $meta = is_array($order->metadata) ? $order->metadata : [];
        $paidAlreadySent = ! empty($meta['utmify_paid_sent_at']);
        $waitingAlreadySent = ! empty($meta['utmify_waiting_sent_at']);

        if ($this->dispatchAlreadySent()) {
            return true;
        }

        if ($this->utmifyStatus === 'paid' && $paidAlreadySent) {
            return true;
        }

        if ($this->utmifyStatus === 'waiting_payment') {
            if ($order->status === 'completed' || $paidAlreadySent || $waitingAlreadySent) {
                return true;
            }
        }

        if (in_array($this->utmifyStatus, ['refused', 'refunded'], true) && $order->status === 'pending') {
            return true;
        }

        return false;
    }

    private function dispatchAlreadySent(): bool
    {
        return UtmifyOrderDispatch::query()
            ->where('order_id', $this->orderId)
            ->where('utmify_integration_id', $this->utmifyIntegrationId)
            ->where('utmify_status', $this->utmifyStatus)
            ->where('dispatch_status', UtmifyOrderDispatch::DISPATCH_SENT)
            ->exists();
    }
}
