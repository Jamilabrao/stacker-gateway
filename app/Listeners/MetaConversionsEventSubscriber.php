<?php

namespace App\Listeners;

use App\Events\OrderCompleted;
use App\Jobs\MetaConversionsSendPurchaseJob;
use App\Services\MetaPurchaseTrackingDiagnostics;
use App\Support\IntegrationJobDispatch;
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

        if (IntegrationJobDispatch::shouldDispatchSync()) {
            MetaConversionsSendPurchaseJob::dispatchSync($orderId);
        } else {
            MetaConversionsSendPurchaseJob::dispatch($orderId);
            app(MetaPurchaseTrackingDiagnostics::class)->logQueueHintOnDispatch($orderId);
        }
    }
}
