<?php

namespace App\Console\Commands;

use App\Jobs\AggregateMetricsDailyStatsJob;
use App\Services\MetricsTracking\MetricsDailyAggregationService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class AggregateMetricsDailyStatsCommand extends Command
{
    protected $signature = 'metrics:aggregate-daily
        {--date= : Dia YYYY-MM-DD (default: ontem)}
        {--from= : Início do backfill YYYY-MM-DD}
        {--to= : Fim do backfill YYYY-MM-DD}
        {--queue : Dispara jobs na fila em vez de processar sync}
        {--sync : Força execução síncrona (default)}';

    protected $description = 'Agrega métricas diárias (metrics_daily_stats) a partir dos eventos/sessões brutos';

    public function handle(MetricsDailyAggregationService $service): int
    {
        if (! config('metrics_tracking.enabled', true)) {
            $this->warn('METRICS_TRACKING_ENABLED=false — abortado.');

            return self::SUCCESS;
        }

        $useQueue = (bool) $this->option('queue') && ! $this->option('sync');
        $queue = (string) config('metrics_tracking.queue', 'metrics-tracking');

        if ($this->option('from') || $this->option('to')) {
            $from = Carbon::parse((string) ($this->option('from') ?: $this->option('to')))->startOfDay();
            $to = Carbon::parse((string) ($this->option('to') ?: $this->option('from')))->startOfDay();
            if ($to->lt($from)) {
                [$from, $to] = [$to, $from];
            }

            if ($useQueue) {
                $n = 0;
                for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
                    AggregateMetricsDailyStatsJob::dispatch($d->toDateString())->onQueue($queue);
                    $n++;
                }
                $this->info("Jobs enfileirados: {$n} ({$from->toDateString()} → {$to->toDateString()})");

                return self::SUCCESS;
            }

            $result = $service->aggregateRange($from, $to);
            $this->info("Backfill sync: days={$result['days']} rows={$result['rows']}");

            return self::SUCCESS;
        }

        $date = $this->option('date')
            ? Carbon::parse((string) $this->option('date'))->startOfDay()
            : now()->subDay()->startOfDay();

        if ($useQueue) {
            AggregateMetricsDailyStatsJob::dispatch($date->toDateString())->onQueue($queue);
            $this->info("Job enfileirado para {$date->toDateString()}");

            return self::SUCCESS;
        }

        $result = $service->aggregateDay($date);
        $this->info("Agregado {$date->toDateString()}: tenants={$result['tenants']} rows={$result['rows']}");

        return self::SUCCESS;
    }
}
