<?php

namespace App\Listeners;

use App\Events\OrderCompleted;
use App\Services\MetaConversionsApiService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class SendMetaPurchaseCapiOnOrderCompleted implements ShouldQueue
{
    use InteractsWithQueue;

    public int $tries = 3;

    public int $backoff = 30;

    public function handle(OrderCompleted $event, MetaConversionsApiService $metaConversionsApiService): void
    {
        $metaConversionsApiService->sendPurchaseForCompletedOrder($event->order);
    }
}
