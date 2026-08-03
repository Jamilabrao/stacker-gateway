<?php

namespace App\Services;

use App\Models\MetricsEvent;
use App\Models\Order;
use App\Models\User;
use App\Models\UtmifyIntegration;
use App\Models\UtmifyOrderDispatch;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

class UtmifyMetricsHealthService
{
    /**
     * Compara métricas internas x dispatches UTMify (write-only; sem API remota).
     *
     * @return array<string, mixed>
     */
    public function buildDashboard(int $days = 7, ?int $sellerId = null): array
    {
        $days = max(1, min(90, $days));
        $start = now()->subDays($days - 1)->startOfDay();
        $end = now()->endOfDay();

        $activeIntegrations = UtmifyIntegration::query()->activeWithApiKey()->count();
        $activeTenants = UtmifyIntegration::query()->activeWithApiKey()->distinct('tenant_id')->count('tenant_id');

        $queueName = (string) config('utmify.queue', 'utmify-tracking');
        $queueSize = null;
        try {
            $queueSize = Queue::size($queueName);
        } catch (\Throwable) {
            $queueSize = null;
        }

        $metricsApproved = $this->metricsApprovedQuery($start, $end, $sellerId)->count();
        $ordersCompleted = $this->ordersCompletedQuery($start, $end, $sellerId)->count();

        $paidSent = $this->dispatchesQuery($start, $end, $sellerId)
            ->where('utmify_status', 'paid')
            ->where('dispatch_status', UtmifyOrderDispatch::DISPATCH_SENT)
            ->count();

        $paidFailed = $this->dispatchesQuery($start, $end, $sellerId)
            ->where('utmify_status', 'paid')
            ->where('dispatch_status', UtmifyOrderDispatch::DISPATCH_FAILED)
            ->count();

        $paidPending = $this->dispatchesQuery($start, $end, $sellerId)
            ->where('utmify_status', 'paid')
            ->where('dispatch_status', UtmifyOrderDispatch::DISPATCH_PENDING)
            ->count();

        $waitingSent = $this->dispatchesQuery($start, $end, $sellerId)
            ->where('utmify_status', 'waiting_payment')
            ->where('dispatch_status', UtmifyOrderDispatch::DISPATCH_SENT)
            ->count();

        $pixCreated = MetricsEvent::query()
            ->where('event_name', MetricsEvent::PIX_CREATED)
            ->whereBetween('occurred_at', [$start, $end])
            ->when($sellerId, fn ($q) => $q->where('tenant_id', $sellerId))
            ->count();

        $missingPaid = $this->missingPaidOrders($start, $end, $sellerId, 40);
        $failedRecent = $this->failedDispatches($start, $end, $sellerId, 30);
        $stuckPending = $this->stuckPendingDispatches($sellerId, 20);
        $lag = $this->paidLagStats($start, $end, $sellerId);
        $bySeller = $this->bySellerBreakdown($start, $end, $sellerId);
        $timeseries = $this->dailyTimeseries($start, $end, $sellerId);

        $coverageBase = max($metricsApproved, $ordersCompleted);
        $coveragePct = $coverageBase > 0
            ? round(($paidSent / $coverageBase) * 100, 2)
            : ($paidSent > 0 ? 100.0 : 0.0);

        $gapTotal = $this->missingPaidCount($start, $end, $sellerId);

        $issues = $this->buildIssues([
            'active_integrations' => $activeIntegrations,
            'gap_total' => $gapTotal,
            'paid_failed' => $paidFailed,
            'paid_pending' => $paidPending,
            'coverage_pct' => $coveragePct,
            'queue_size' => $queueSize,
            'lag_p95_seconds' => $lag['p95_seconds'],
        ]);

        return [
            'period_days' => $days,
            'seller_id' => $sellerId,
            'window' => [
                'start' => $start->toIso8601String(),
                'end' => $end->toIso8601String(),
            ],
            'note' => 'Comparação local (metrics_events / orders × utmify_order_dispatches). A UTMify é write-only — não há contagem remota no painel deles.',
            'infrastructure' => [
                'metrics_enabled' => (bool) config('metrics_tracking.enabled', true),
                'utmify_queue' => $queueName,
                'utmify_queue_size' => $queueSize,
                'active_integrations' => $activeIntegrations,
                'tenants_with_utmify' => $activeTenants,
            ],
            'kpis' => [
                'metrics_payment_approved' => $metricsApproved,
                'orders_completed' => $ordersCompleted,
                'utmify_paid_sent' => $paidSent,
                'utmify_paid_failed' => $paidFailed,
                'utmify_paid_pending' => $paidPending,
                'utmify_waiting_sent' => $waitingSent,
                'metrics_pix_created' => $pixCreated,
                'gap_missing_paid_sent' => $gapTotal,
                'coverage_pct' => $coveragePct,
                'failure_rate_pct' => ($paidSent + $paidFailed) > 0
                    ? round(($paidFailed / ($paidSent + $paidFailed)) * 100, 2)
                    : 0.0,
            ],
            'lag' => $lag,
            'issues' => $issues,
            'missing_paid' => $missingPaid,
            'failed_dispatches' => $failedRecent,
            'stuck_pending' => $stuckPending,
            'by_seller' => $bySeller,
            'timeseries' => $timeseries,
            'sellers' => $this->sellersList(),
        ];
    }

    private function metricsApprovedQuery(Carbon $start, Carbon $end, ?int $sellerId)
    {
        return MetricsEvent::query()
            ->where('event_name', MetricsEvent::PAYMENT_APPROVED)
            ->whereBetween('occurred_at', [$start, $end])
            ->when($sellerId, fn ($q) => $q->where('tenant_id', $sellerId));
    }

    private function ordersCompletedQuery(Carbon $start, Carbon $end, ?int $sellerId)
    {
        return Order::query()
            ->where('status', 'completed')
            ->where(function ($q) use ($start, $end) {
                $q->whereBetween('updated_at', [$start, $end])
                    ->orWhereBetween('created_at', [$start, $end]);
            })
            ->when($sellerId, fn ($q) => $q->where('tenant_id', $sellerId));
    }

    private function dispatchesQuery(Carbon $start, Carbon $end, ?int $sellerId)
    {
        return UtmifyOrderDispatch::query()
            ->where(function ($q) use ($start, $end) {
                $q->whereBetween('sent_at', [$start, $end])
                    ->orWhere(function ($q2) use ($start, $end) {
                        $q2->whereNull('sent_at')->whereBetween('updated_at', [$start, $end]);
                    });
            })
            ->when($sellerId, fn ($q) => $q->where('tenant_id', $sellerId));
    }

    private function missingPaidCount(Carbon $start, Carbon $end, ?int $sellerId): int
    {
        $activeTenantIds = UtmifyIntegration::query()->activeWithApiKey()->pluck('tenant_id')->unique()->filter()->all();
        if ($activeTenantIds === [] && $sellerId === null) {
            return 0;
        }

        return Order::query()
            ->where('status', 'completed')
            ->whereBetween('updated_at', [$start, $end])
            ->when($sellerId, fn ($q) => $q->where('tenant_id', $sellerId), function ($q) use ($activeTenantIds) {
                $q->whereIn('tenant_id', $activeTenantIds);
            })
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('utmify_order_dispatches as d')
                    ->whereColumn('d.order_id', 'orders.id')
                    ->where('d.utmify_status', 'paid')
                    ->where('d.dispatch_status', UtmifyOrderDispatch::DISPATCH_SENT);
            })
            ->count();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function missingPaidOrders(Carbon $start, Carbon $end, ?int $sellerId, int $limit): array
    {
        $activeTenantIds = UtmifyIntegration::query()->activeWithApiKey()->pluck('tenant_id')->unique()->filter()->all();
        if ($activeTenantIds === [] && $sellerId === null) {
            return [];
        }

        $orders = Order::query()
            ->where('status', 'completed')
            ->whereBetween('updated_at', [$start, $end])
            ->when($sellerId, fn ($q) => $q->where('tenant_id', $sellerId), function ($q) use ($activeTenantIds) {
                $q->whereIn('tenant_id', $activeTenantIds);
            })
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('utmify_order_dispatches as d')
                    ->whereColumn('d.order_id', 'orders.id')
                    ->where('d.utmify_status', 'paid')
                    ->where('d.dispatch_status', UtmifyOrderDispatch::DISPATCH_SENT);
            })
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get(['id', 'tenant_id', 'product_id', 'amount', 'status', 'updated_at', 'metadata']);

        $tenantIds = $orders->pluck('tenant_id')->filter()->unique()->all();
        $sellers = $tenantIds
            ? User::query()->whereIn('id', $tenantIds)->get(['id', 'name', 'email'])->keyBy('id')
            : collect();

        $orderIds = $orders->pluck('id')->all();
        $withMetrics = $orderIds
            ? MetricsEvent::query()
                ->whereIn('order_id', $orderIds)
                ->where('event_name', MetricsEvent::PAYMENT_APPROVED)
                ->pluck('order_id')
                ->unique()
                ->flip()
            : collect();

        return $orders->map(function (Order $o) use ($sellers, $withMetrics) {
            $seller = $o->tenant_id ? $sellers->get($o->tenant_id) : null;
            $meta = is_array($o->metadata) ? $o->metadata : [];

            return [
                'order_id' => $o->id,
                'tenant_id' => $o->tenant_id,
                'seller' => $seller ? (($seller->name ?: $seller->email).' #'.$seller->id) : '—',
                'amount' => (float) $o->amount,
                'updated_at' => optional($o->updated_at)?->toIso8601String(),
                'utmify_failed_at' => $meta['utmify_failed_at'] ?? null,
                'utmify_last_error' => $meta['utmify_last_error'] ?? null,
                'has_metrics_approved' => $withMetrics->has($o->id),
            ];
        })->values()->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function failedDispatches(Carbon $start, Carbon $end, ?int $sellerId, int $limit): array
    {
        return UtmifyOrderDispatch::query()
            ->where('dispatch_status', UtmifyOrderDispatch::DISPATCH_FAILED)
            ->whereBetween('updated_at', [$start, $end])
            ->when($sellerId, fn ($q) => $q->where('tenant_id', $sellerId))
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get()
            ->map(fn (UtmifyOrderDispatch $d) => [
                'id' => $d->id,
                'order_id' => $d->order_id,
                'tenant_id' => $d->tenant_id,
                'utmify_status' => $d->utmify_status,
                'attempts' => $d->attempts,
                'last_error' => $d->last_error,
                'updated_at' => optional($d->updated_at)?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function stuckPendingDispatches(?int $sellerId, int $limit): array
    {
        $cutoff = now()->subMinutes(30);

        return UtmifyOrderDispatch::query()
            ->where('dispatch_status', UtmifyOrderDispatch::DISPATCH_PENDING)
            ->where('attempts', '>', 0)
            ->where('updated_at', '<', $cutoff)
            ->when($sellerId, fn ($q) => $q->where('tenant_id', $sellerId))
            ->orderBy('updated_at')
            ->limit($limit)
            ->get()
            ->map(fn (UtmifyOrderDispatch $d) => [
                'id' => $d->id,
                'order_id' => $d->order_id,
                'tenant_id' => $d->tenant_id,
                'utmify_status' => $d->utmify_status,
                'attempts' => $d->attempts,
                'last_error' => $d->last_error,
                'updated_at' => optional($d->updated_at)?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array{samples:int,avg_seconds:?float,p50_seconds:?float,p95_seconds:?float}
     */
    private function paidLagStats(Carbon $start, Carbon $end, ?int $sellerId): array
    {
        $driver = DB::connection()->getDriverName();
        $lagExpr = $driver === 'pgsql'
            ? 'EXTRACT(EPOCH FROM (d.sent_at - m.occurred_at))'
            : 'TIMESTAMPDIFF(SECOND, m.occurred_at, d.sent_at)';

        $rows = DB::table('utmify_order_dispatches as d')
            ->join('metrics_events as m', function ($join) {
                $join->on('m.order_id', '=', 'd.order_id')
                    ->where('m.event_name', '=', MetricsEvent::PAYMENT_APPROVED);
            })
            ->where('d.utmify_status', 'paid')
            ->where('d.dispatch_status', UtmifyOrderDispatch::DISPATCH_SENT)
            ->whereNotNull('d.sent_at')
            ->whereBetween('d.sent_at', [$start, $end])
            ->when($sellerId, fn ($q) => $q->where('d.tenant_id', $sellerId))
            ->selectRaw("{$lagExpr} as lag_seconds")
            ->limit(5000)
            ->pluck('lag_seconds')
            ->map(fn ($v) => (float) $v)
            ->filter(fn ($v) => $v >= 0)
            ->sort()
            ->values();

        $n = $rows->count();
        if ($n === 0) {
            return ['samples' => 0, 'avg_seconds' => null, 'p50_seconds' => null, 'p95_seconds' => null];
        }

        return [
            'samples' => $n,
            'avg_seconds' => round((float) $rows->avg(), 1),
            'p50_seconds' => round((float) $rows[(int) floor(($n - 1) * 0.5)], 1),
            'p95_seconds' => round((float) $rows[(int) floor(($n - 1) * 0.95)], 1),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function bySellerBreakdown(Carbon $start, Carbon $end, ?int $sellerId): array
    {
        $approved = MetricsEvent::query()
            ->where('event_name', MetricsEvent::PAYMENT_APPROVED)
            ->whereBetween('occurred_at', [$start, $end])
            ->when($sellerId, fn ($q) => $q->where('tenant_id', $sellerId))
            ->selectRaw('tenant_id, COUNT(*) as approved')
            ->groupBy('tenant_id')
            ->pluck('approved', 'tenant_id');

        $sent = UtmifyOrderDispatch::query()
            ->where('utmify_status', 'paid')
            ->where('dispatch_status', UtmifyOrderDispatch::DISPATCH_SENT)
            ->where(function ($q) use ($start, $end) {
                $q->whereBetween('sent_at', [$start, $end])
                    ->orWhere(function ($q2) use ($start, $end) {
                        $q2->whereNull('sent_at')->whereBetween('updated_at', [$start, $end]);
                    });
            })
            ->when($sellerId, fn ($q) => $q->where('tenant_id', $sellerId))
            ->selectRaw('tenant_id, COUNT(*) as sent')
            ->groupBy('tenant_id')
            ->pluck('sent', 'tenant_id');

        $failed = UtmifyOrderDispatch::query()
            ->where('utmify_status', 'paid')
            ->where('dispatch_status', UtmifyOrderDispatch::DISPATCH_FAILED)
            ->whereBetween('updated_at', [$start, $end])
            ->when($sellerId, fn ($q) => $q->where('tenant_id', $sellerId))
            ->selectRaw('tenant_id, COUNT(*) as failed')
            ->groupBy('tenant_id')
            ->pluck('failed', 'tenant_id');

        $tenantIds = collect($approved->keys())->merge($sent->keys())->merge($failed->keys())->unique()->filter()->values();
        $sellers = $tenantIds->isNotEmpty()
            ? User::query()->whereIn('id', $tenantIds)->get(['id', 'name', 'email'])->keyBy('id')
            : collect();

        $out = [];
        foreach ($tenantIds as $tid) {
            $a = (int) ($approved[$tid] ?? 0);
            $s = (int) ($sent[$tid] ?? 0);
            $f = (int) ($failed[$tid] ?? 0);
            $u = $sellers->get($tid);
            $out[] = [
                'tenant_id' => (int) $tid,
                'seller' => $u ? (($u->name ?: $u->email).' #'.$u->id) : '#'.$tid,
                'metrics_approved' => $a,
                'utmify_paid_sent' => $s,
                'utmify_paid_failed' => $f,
                'gap' => max(0, $a - $s),
                'coverage_pct' => $a > 0 ? round(($s / $a) * 100, 2) : ($s > 0 ? 100.0 : 0.0),
            ];
        }

        usort($out, fn ($x, $y) => $y['gap'] <=> $x['gap'] ?: $y['metrics_approved'] <=> $x['metrics_approved']);

        return array_slice($out, 0, 40);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function dailyTimeseries(Carbon $start, Carbon $end, ?int $sellerId): array
    {
        $driver = DB::connection()->getDriverName();
        $dayExprMetrics = $driver === 'pgsql'
            ? "to_char(occurred_at, 'YYYY-MM-DD')"
            : 'DATE(occurred_at)';
        $dayExprDisp = $driver === 'pgsql'
            ? "to_char(COALESCE(sent_at, updated_at), 'YYYY-MM-DD')"
            : 'DATE(COALESCE(sent_at, updated_at))';

        $approved = MetricsEvent::query()
            ->where('event_name', MetricsEvent::PAYMENT_APPROVED)
            ->whereBetween('occurred_at', [$start, $end])
            ->when($sellerId, fn ($q) => $q->where('tenant_id', $sellerId))
            ->selectRaw("{$dayExprMetrics} as bucket, COUNT(*) as total")
            ->groupBy('bucket')
            ->pluck('total', 'bucket');

        $sent = UtmifyOrderDispatch::query()
            ->where('utmify_status', 'paid')
            ->where('dispatch_status', UtmifyOrderDispatch::DISPATCH_SENT)
            ->where(function ($q) use ($start, $end) {
                $q->whereBetween('sent_at', [$start, $end])
                    ->orWhere(function ($q2) use ($start, $end) {
                        $q2->whereNull('sent_at')->whereBetween('updated_at', [$start, $end]);
                    });
            })
            ->when($sellerId, fn ($q) => $q->where('tenant_id', $sellerId))
            ->selectRaw("{$dayExprDisp} as bucket, COUNT(*) as total")
            ->groupBy('bucket')
            ->pluck('total', 'bucket');

        $buckets = collect($approved->keys())->merge($sent->keys())->unique()->sort()->values();
        $out = [];
        foreach ($buckets as $b) {
            $a = (int) ($approved[$b] ?? 0);
            $s = (int) ($sent[$b] ?? 0);
            $out[] = [
                'bucket' => (string) $b,
                'metrics_approved' => $a,
                'utmify_paid_sent' => $s,
                'gap' => max(0, $a - $s),
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $ctx
     * @return list<array{severity:string,title:string,detail:string}>
     */
    private function buildIssues(array $ctx): array
    {
        $issues = [];

        if ((int) $ctx['active_integrations'] === 0) {
            $issues[] = [
                'severity' => 'warning',
                'title' => 'Nenhuma integração UTMify ativa',
                'detail' => 'Sem integrações com API key ativa, pedidos não são enviados à UTMify (métricas internas continuam).',
            ];
        }

        if ((int) $ctx['gap_total'] > 0) {
            $sev = (int) $ctx['gap_total'] >= 10 ? 'critical' : 'warning';
            $issues[] = [
                'severity' => $sev,
                'title' => 'Pedidos pagos sem dispatch paid/sent',
                'detail' => "{$ctx['gap_total']} pedido(s) completed de tenants com UTMify ativa sem envio `paid` confirmado no período.",
            ];
        }

        if ((int) $ctx['paid_failed'] > 0) {
            $issues[] = [
                'severity' => 'critical',
                'title' => 'Falhas de envio paid',
                'detail' => "{$ctx['paid_failed']} dispatch(es) paid com status failed no período.",
            ];
        }

        if ((int) $ctx['paid_pending'] > 5) {
            $issues[] = [
                'severity' => 'warning',
                'title' => 'Fila de paid pendente',
                'detail' => "{$ctx['paid_pending']} dispatches paid ainda pending — verifique o worker da fila utmify.",
            ];
        }

        if ($ctx['queue_size'] !== null && (int) $ctx['queue_size'] > 100) {
            $issues[] = [
                'severity' => 'warning',
                'title' => 'Fila UTMify acumulada',
                'detail' => 'Queue size = '.$ctx['queue_size'].'. Considere subir workers em `utmify-tracking`.',
            ];
        }

        if ($ctx['lag_p95_seconds'] !== null && (float) $ctx['lag_p95_seconds'] > 600) {
            $issues[] = [
                'severity' => 'warning',
                'title' => 'Lag alto (P95)',
                'detail' => 'P95 entre payment_approved e UTMify sent = '
                    .round((float) $ctx['lag_p95_seconds']).'s.',
            ];
        }

        if ((float) $ctx['coverage_pct'] < 85 && (int) $ctx['gap_total'] > 0) {
            $issues[] = [
                'severity' => 'info',
                'title' => 'Cobertura abaixo de 85%',
                'detail' => 'Coverage atual: '.$ctx['coverage_pct'].'%. Gaps esperados se a integração não cobre todos os produtos.',
            ];
        }

        return $issues;
    }

    /**
     * @return list<array{id:int,label:string}>
     */
    private function sellersList(): array
    {
        return User::query()
            ->whereIn('role', [User::ROLE_INFOPRODUTOR, 'admin'])
            ->orderBy('name')
            ->limit(500)
            ->get(['id', 'name', 'email'])
            ->map(fn (User $u) => [
                'id' => $u->id,
                'label' => ($u->name ?: $u->email).' #'.$u->id,
            ])
            ->values()
            ->all();
    }
}
