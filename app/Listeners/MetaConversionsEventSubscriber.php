<?php

namespace App\Listeners;

use App\Events\OrderCompleted;
use App\Jobs\MetaConversionsSendPurchaseJob;
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
        MetaConversionsSendPurchaseJob::dispatch($event->order->id);
    }
}

