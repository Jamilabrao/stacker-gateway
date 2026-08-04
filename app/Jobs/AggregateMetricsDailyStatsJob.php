<?php

namespace App\Jobs;

use App\Services\MetricsTracking\MetricsDailyAggregationService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class AggregateMetricsDailyStatsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 300;

    public function __construct(
        public string $date,
    ) {}

    public function handle(MetricsDailyAggregationService $service): void
    {
        if (! config('metrics_tracking.enabled', true)) {
            return;
        }
        if (! config('metrics_tracking.daily_stats_enabled', true)) {
            return;
        }

        $day = Carbon::parse($this->date)->startOfDay();
        $result = $service->aggregateDay($day);

        Log::info('metrics.daily_aggregated', [
            'date' => $day->toDateString(),
            'tenants' => $result['tenants'],
            'rows' => $result['rows'],
        ]);
    }
}
