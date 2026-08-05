<?php

namespace App\Console\Commands;

use App\Models\MetricsEvent;
use App\Models\Order;
use App\Services\MetricsTracking\MetricsCaptureService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class BackfillMetricsOrderEventsCommand extends Command
{
    protected $signature = 'metrics:backfill-order-events
        {--limit=0 : Máximo de pedidos (0 = todos)}
        {--days=90 : Considerar pedidos completed dos últimos N dias (0 = sem filtro de data)}
        {--tenant= : Filtrar por tenant_id}
        {--dry-run : Só lista o gap, sem gravar}
        {--aggregate : Após backfill, agrega metrics_daily_stats no período}';

    protected $description = 'Cria payment_approved em metrics_events para pedidos completed sem conversão registrada';

    public function handle(MetricsCaptureService $capture): int
    {
        if (! $capture->enabled()) {
            $this->error('Métricas desabilitadas ou tabelas ausentes. Rode as migrations / verifique METRICS_TRACKING_ENABLED.');

            return self::FAILURE;
        }

        if (! Schema::hasTable('orders')) {
            $this->error('Tabela orders não existe.');

            return self::FAILURE;
        }

        $limit = max(0, (int) $this->option('limit'));
        $days = max(0, (int) $this->option('days'));
        $tenantId = $this->option('tenant') !== null && $this->option('tenant') !== ''
            ? (int) $this->option('tenant')
            : null;
        $dryRun = (bool) $this->option('dry-run');

        $query = Order::query()
            ->where('status', 'completed')
            ->whereNotExists(function ($q) {
                $q->selectRaw('1')
                    ->from('metrics_events')
                    ->whereColumn('metrics_events.order_id', 'orders.id')
                    ->where('metrics_events.event_name', MetricsEvent::PAYMENT_APPROVED);
            })
            ->orderBy('id');

        if ($days > 0) {
            $query->where('updated_at', '>=', now()->subDays($days));
        }
        if ($tenantId !== null) {
            $query->where('tenant_id', $tenantId);
        }
        if ($limit > 0) {
            $query->limit($limit);
        }

        $processed = 0;
        $created = 0;
        $skipped = 0;
        $minDate = null;
        $maxDate = null;

        $query->chunkById(100, function ($orders) use ($capture, $dryRun, &$processed, &$created, &$skipped, &$minDate, &$maxDate) {
            foreach ($orders as $order) {
                $processed++;
                $occurred = $order->updated_at ?? $order->created_at;
                if ($occurred) {
                    $minDate = $minDate === null || $occurred->lt($minDate) ? $occurred->copy() : $minDate;
                    $maxDate = $maxDate === null || $occurred->gt($maxDate) ? $occurred->copy() : $maxDate;
                }

                if ($dryRun) {
                    $this->line("would create payment_approved for order #{$order->id} tenant={$order->tenant_id}");
                    $created++;
                    continue;
                }

                $before = MetricsEvent::query()
                    ->where('order_id', $order->id)
                    ->where('event_name', MetricsEvent::PAYMENT_APPROVED)
                    ->exists();

                $capture->recordOrderEvent(
                    $order,
                    MetricsEvent::PAYMENT_APPROVED,
                    'approved',
                    $order->updated_at ?? $order->created_at
                );

                $after = MetricsEvent::query()
                    ->where('order_id', $order->id)
                    ->where('event_name', MetricsEvent::PAYMENT_APPROVED)
                    ->exists();

                if (! $before && $after) {
                    $created++;
                } else {
                    $skipped++;
                }
            }
        });

        $this->info(($dryRun ? '[dry-run] ' : '')."Processados: {$processed} | Conversões: {$created} | Ignorados: {$skipped}");

        if (! $dryRun && (bool) $this->option('aggregate') && $minDate && $maxDate) {
            $from = Carbon::parse($minDate)->toDateString();
            $to = Carbon::parse($maxDate)->toDateString();
            $this->call('metrics:aggregate-daily', [
                '--from' => $from,
                '--to' => $to,
                '--sync' => true,
            ]);
        }

        return self::SUCCESS;
    }
}
