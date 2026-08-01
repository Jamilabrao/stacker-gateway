<?php

namespace App\Listeners;

use App\Events\OrderCompleted;
use App\Jobs\SyncSalesAchievementsForTenantJob;

class SyncSalesAchievementsOnOrderCompleted
{
    public function handle(OrderCompleted $event): void
    {
        $order = $event->order;
        $tenantId = (int) ($order->tenant_id ?? 0);
        if ($tenantId <= 0) {
            return;
        }

        SyncSalesAchievementsForTenantJob::dispatch($tenantId);
    }
}
