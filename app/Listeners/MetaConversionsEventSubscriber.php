<?php

namespace App\Listeners;

use App\Events\OrderCompleted;
use App\Jobs\MetaConversionsSendPurchaseJob;
use App\Services\MetaPurchaseTrackingDiagnostics;
use App\Support\IntegrationJobDispatch;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Log;

class MetaConversionsEventSubscriber
{
    /**
     * @return array<string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            OrderCompleted::class => 'handleOrderCompleted',
        ];
    }

    public function handleOrderCompleted(OrderCompleted $event): void
    {
        $orderId = (int) $event->order->id;

        try {
            if (IntegrationJobDispatch::shouldDispatchSync()) {
                MetaConversionsSendPurchaseJob::dispatchSync($orderId);
            } else {
                MetaConversionsSendPurchaseJob::dispatch($orderId)
                    ->onQueue((string) config('meta_tracking.queue', 'meta-tracking'))
                    ->afterResponse();
            }

            app(MetaPurchaseTrackingDiagnostics::class)->logQueueHintOnDispatch($orderId);
        } catch (\Throwable $e) {
            // Não abortar a cadeia de listeners (métricas, NF, Cademi, etc.).
            Log::warning('MetaConversionsEventSubscriber: send failed (listeners continue)', [
                'order_id' => $orderId,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
