<?php

namespace App\Jobs;

use App\Services\SalesAchievementGrantService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncSalesAchievementsForTenantJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $tenantId,
        public string $metricType = 'revenue',
    ) {}

    public function handle(SalesAchievementGrantService $grant): void
    {
        $grant->syncTenant($this->tenantId, $this->metricType);
    }
}
