<?php

namespace App\Jobs;

use App\Models\Order;
use App\Services\Meta\MetaTrackingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Enqueues Meta Purchase CAPI events for an order (one SendMetaTrackingEventJob per pixel).
 */
class MetaConversionsSendPurchaseJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public int $orderId)
    {
        $this->onQueue((string) config('meta_tracking.queue', 'meta-tracking'));
    }

    public function handle(MetaTrackingService $service): void
    {
        $order = Order::query()->find($this->orderId);
        if (! $order) {
            return;
        }

        $meta = is_array($order->metadata) ? $order->metadata : [];
        if (! empty($meta['meta_capi_sent_purchase'])) {
            return;
        }

        $queued = $service->queuePurchaseForOrder($order);

        if ($queued === []) {
            Log::info('Meta CAPI purchase skipped: no eligible Meta pixel configured for order', [
                'order_id' => $order->id,
                'tenant_id' => $order->tenant_id,
            ]);
        }
    }
}
