<?php

namespace App\Listeners;

use App\Events\OrderCompleted;
use App\Services\InertiaSharedPropsCache;

class ForgetInertiaSharedCacheOnOrderCompleted
{
    public function handle(OrderCompleted $event): void
    {
        $tenantId = (int) ($event->order->tenant_id ?? 0);
        if ($tenantId > 0) {
            InertiaSharedPropsCache::forgetAchievementsProgress($tenantId);
        }
    }
}
