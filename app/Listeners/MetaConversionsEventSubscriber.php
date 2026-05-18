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
        // CAPI deve ser server-side e idempotente (job checa metadata).
        $orderId = (int) $event->order->id;
        MetaConversionsSendPurchaseJob::dispatch($orderId);
        app(MetaPurchaseTrackingDiagnostics::class)->logQueueHintOnDispatch($orderId);
    }
}

