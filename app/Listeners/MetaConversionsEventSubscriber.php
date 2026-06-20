<?php

namespace App\Listeners;

use App\Events\OrderCompleted;
use App\Jobs\MetaConversionsSendPurchaseJob;
use App\Services\MetaPurchaseTrackingDiagnostics;
use Illuminate\Contracts\Events\Dispatcher;

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

        MetaConversionsSendPurchaseJob::dispatch($orderId)
            ->onQueue((string) config('meta_tracking.queue', 'meta-tracking'));

        app(MetaPurchaseTrackingDiagnostics::class)->logQueueHintOnDispatch($orderId);
    }
}
