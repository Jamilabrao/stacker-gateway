<?php

namespace App\Services\MetricsTracking;

use App\Models\MetricsDailyStat;
use App\Models\MetricsEvent;
use App\Models\MetricsSession;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class MetricsDailyAggregationService
{
    /**
     * Agrega um dia civil (timezone da app) para todos os tenants com dados.
     *
     * @return array{tenants:int,rows:int}
     */
    public function aggregateDay(Carbon $day): array
    {
        $day = $day->copy()->startOfDay();
        $start = $day->copy()->startOfDay();
        $end = $day->copy()->endOfDay();

        $tenantIds = MetricsEvent::query()
            ->whereBetween('occurred_at', [$start, $end])
            ->distinct()
            ->pluck('tenant_id')
            ->merge(
                MetricsSession::query()
                    ->whereBetween('first_touch_at', [$start, $end])
                    ->distinct()
                    ->pluck('tenant_id')
            )
            ->unique()
            ->values();

        $rows = 0;
        foreach ($tenantIds as $tenantId) {
            $tid = $tenantId !== null ? (int) $tenantId : null;
            $rows += $this->upsertTenantTotals($tid, $day, $start, $end);
            $rows += $this->upsertTenantProducts($tid, $day, $start, $end);
        }

        return ['tenants' => $tenantIds->count(), 'rows' => $rows];
    }

    /**
     * @return array{days:int,tenants:int,rows:int}
     */
    public function aggregateRange(Carbon $from, Carbon $to): array
    {
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->startOfDay();
        if ($to->lt($from)) {
            [$from, $to] = [$to, $from];
        }

        $days = 0;
        $tenants = 0;
        $rows = 0;
        for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
            $result = $this->aggregateDay($d);
            $days++;
            $tenants += $result['tenants'];
            $rows += $result['rows'];
        }

        return compact('days', 'tenants', 'rows');
    }

    private function upsertTenantTotals(?int $tenantId, Carbon $day, Carbon $start, Carbon $end): int
    {
        $payload = $this->computeBucket($tenantId, $start, $end, null);
        $this->upsertRow([
            'tenant_id' => $tenantId,
            'stat_date' => $day->toDateString(),
            'product_id' => null,
            'dimension' => 'total',
            'dimension_value' => null,
        ], $payload);

        return 1;
    }

    private function upsertTenantProducts(?int $tenantId, Carbon $day, Carbon $start, Carbon $end): int
    {
        $productIds = MetricsEvent::query()
            ->when($tenantId === null, fn ($q) => $q->whereNull('tenant_id'), fn ($q) => $q->where('tenant_id', $tenantId))
            ->whereBetween('occurred_at', [$start, $end])
            ->whereNotNull('product_id')
            ->distinct()
            ->pluck('product_id')
            ->merge(
                MetricsSession::query()
                    ->when($tenantId === null, fn ($q) => $q->whereNull('tenant_id'), fn ($q) => $q->where('tenant_id', $tenantId))
                    ->whereBetween('first_touch_at', [$start, $end])
                    ->whereNotNull('product_id')
                    ->distinct()
                    ->pluck('product_id')
            )
            ->unique()
            ->filter()
            ->values();

        $n = 0;
        foreach ($productIds as $productId) {
            $payload = $this->computeBucket($tenantId, $start, $end, (string) $productId);
            $this->upsertRow([
                'tenant_id' => $tenantId,
                'stat_date' => $day->toDateString(),
                'product_id' => (string) $productId,
                'dimension' => 'product',
                'dimension_value' => (string) $productId,
            ], $payload);
            $n++;
        }

        return $n;
    }

    /**
     * @return array<string, int|float>
     */
    private function computeBucket(?int $tenantId, Carbon $start, Carbon $end, ?string $productId): array
    {
        $sessions = MetricsSession::query()
            ->when($tenantId === null, fn ($q) => $q->whereNull('tenant_id'), fn ($q) => $q->where('tenant_id', $tenantId))
            ->whereBetween('first_touch_at', [$start, $end]);
        $events = MetricsEvent::query()
            ->when($tenantId === null, fn ($q) => $q->whereNull('tenant_id'), fn ($q) => $q->where('tenant_id', $tenantId))
            ->whereBetween('occurred_at', [$start, $end]);

        if ($productId !== null) {
            $sessions->where('product_id', $productId);
            $events->where('product_id', $productId);
        }

        $uniqueVisitors = (int) (clone $sessions)->distinct('visitor_key')->count('visitor_key');
        $sessionsCount = (int) (clone $sessions)->count();
        $clicks = (int) (clone $sessions)->sum('clicks_count');
        if ($clicks === 0) {
            $clicks = (int) (clone $events)->whereIn('event_name', [
                MetricsEvent::PAGE_VIEW, MetricsEvent::CHECKOUT_VIEW, MetricsEvent::LINK_CLICKED,
            ])->count();
        }

        $checkoutViews = (int) (clone $events)->where('event_name', MetricsEvent::CHECKOUT_VIEW)->count();
        $checkoutsStarted = (int) (clone $events)->where('event_name', MetricsEvent::CHECKOUT_STARTED)->count();
        $pixCreated = (int) (clone $events)->where('event_name', MetricsEvent::PIX_CREATED)->count();
        $approved = (int) (clone $events)->where('event_name', MetricsEvent::PAYMENT_APPROVED)->count();
        $refused = (int) (clone $events)->where('event_name', MetricsEvent::PAYMENT_REFUSED)->count();
        $refunds = (int) (clone $events)->where('event_name', MetricsEvent::PAYMENT_REFUNDED)->count();

        $gross = (float) (clone $events)->where('event_name', MetricsEvent::PAYMENT_APPROVED)->sum('amount');
        $refundAmount = (float) (clone $events)->where('event_name', MetricsEvent::PAYMENT_REFUNDED)->sum('amount');
        $net = max(0, $gross - $refundAmount);

        $secSum = (int) (clone $events)
            ->where('event_name', MetricsEvent::PAYMENT_APPROVED)
            ->whereNotNull('seconds_to_convert')
            ->sum('seconds_to_convert');
        $secCount = (int) (clone $events)
            ->where('event_name', MetricsEvent::PAYMENT_APPROVED)
            ->whereNotNull('seconds_to_convert')
            ->count();

        return [
            'unique_visitors' => $uniqueVisitors,
            'sessions' => $sessionsCount,
            'clicks' => $clicks,
            'checkout_views' => $checkoutViews,
            'checkouts_started' => $checkoutsStarted,
            'pix_created' => $pixCreated,
            'payments_approved' => $approved,
            'payments_refused' => $refused,
            'refunds' => $refunds,
            'gross_revenue' => round($gross, 2),
            'net_revenue' => round($net, 2),
            'seconds_to_convert_sum' => $secSum,
            'seconds_to_convert_count' => $secCount,
        ];
    }

    /**
     * @param  array<string, mixed>  $keys
     * @param  array<string, int|float>  $payload
     */
    private function upsertRow(array $keys, array $payload): void
    {
        try {
            MetricsDailyStat::query()->updateOrCreate($keys, $payload);
        } catch (\Throwable $e) {
            // Postgres unique com NULLs: fallback manual
            $q = MetricsDailyStat::query()
                ->where('stat_date', $keys['stat_date'])
                ->where('dimension', $keys['dimension']);

            if ($keys['tenant_id'] === null) {
                $q->whereNull('tenant_id');
            } else {
                $q->where('tenant_id', $keys['tenant_id']);
            }
            if ($keys['product_id'] === null) {
                $q->whereNull('product_id');
            } else {
                $q->where('product_id', $keys['product_id']);
            }
            if ($keys['dimension_value'] === null) {
                $q->whereNull('dimension_value');
            } else {
                $q->where('dimension_value', $keys['dimension_value']);
            }

            $existing = $q->first();
            if ($existing) {
                $existing->fill($payload)->save();
            } else {
                MetricsDailyStat::query()->create(array_merge($keys, $payload));
            }

            Log::debug('metrics.daily_upsert_fallback', ['message' => $e->getMessage()]);
        }
    }
}
