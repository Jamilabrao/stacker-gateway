<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\UtmifyIntegration;
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

    public int $tries = 10;

    public function backoff(): array
    {
        return [30, 60, 120, 300, 600, 1200, 1800, 3600];
    }

    public function __construct(
        public int $utmifyIntegrationId,
        public int $orderId,
        public string $utmifyStatus,
        public ?string $approvedAt = null,
        public ?string $refundedAt = null
    ) {}

    public function handle(UtmifyService $utmifyService): void
    {
        $integration = UtmifyIntegration::with('products:id')
            ->find($this->utmifyIntegrationId);

        if (! $integration || ! $integration->is_active || ! $integration->api_key) {
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

        try {
            $utmifyService->sendOrder($order, $this->utmifyStatus, $integration->api_key, [
                'approved_at' => $this->approvedAt,
                'refunded_at' => $this->refundedAt,
            ]);

            if ($this->utmifyStatus === 'paid') {
                $meta = is_array($order->metadata) ? $order->metadata : [];
                $meta['utmify_paid_sent_at'] = now()->toIso8601String();
                unset($meta['utmify_last_error'], $meta['utmify_failed_at']);
                $order->update(['metadata' => $meta]);
            }

            Log::info('UtmifySendOrderJob sent', [
                'order_id' => $this->orderId,
                'utmify_integration_id' => $this->utmifyIntegrationId,
                'status' => $this->utmifyStatus,
            ]);
        } catch (\Throwable $e) {
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

    private function shouldSkipSend(Order $order): bool
    {
        $meta = is_array($order->metadata) ? $order->metadata : [];
        $paidAlreadySent = ! empty($meta['utmify_paid_sent_at']);

        if ($this->utmifyStatus === 'paid' && $paidAlreadySent) {
            return true;
        }

        if ($this->utmifyStatus === 'waiting_payment') {
            if ($order->status === 'completed' || $paidAlreadySent) {
                return true;
            }
        }

        if (in_array($this->utmifyStatus, ['refused', 'refunded'], true) && $order->status === 'pending') {
            return true;
        }

        return false;
    }
}
