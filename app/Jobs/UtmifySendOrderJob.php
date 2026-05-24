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

        if ($this->utmifyStatus === 'paid') {
            $meta = is_array($order->metadata) ? $order->metadata : [];
            if (! empty($meta['utmify_paid_sent_at'])) {
                return;
            }
        }

        try {
            $utmifyService->sendOrder($order, $this->utmifyStatus, $integration->api_key, [
                'approved_at' => $this->approvedAt,
                'refunded_at' => $this->refundedAt,
            ]);

            if ($this->utmifyStatus === 'paid') {
                $meta = is_array($order->metadata) ? $order->metadata : [];
                $meta['utmify_paid_sent_at'] = now()->toIso8601String();
                $order->update(['metadata' => $meta]);
            }
        } catch (\Throwable $e) {
            Log::warning('UtmifySendOrderJob failed', [
                'order_id' => $this->orderId,
                'utmify_integration_id' => $this->utmifyIntegrationId,
                'status' => $this->utmifyStatus,
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
