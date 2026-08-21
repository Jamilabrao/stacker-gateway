<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Platform\PlatformDashboardAnalytics;
use App\Support\DemoMode;
use App\Support\Demo\DemoPlatformData;
use App\Support\PlatformDashboardPeriod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    private const CACHE_TTL_SECONDS = 120;

    public function __invoke(Request $request): Response
    {
        $period = PlatformDashboardPeriod::normalize($request->query('period', 'hoje'));

        if (DemoMode::isEnabled()) {
            return Inertia::render('Platform/Dashboard', DemoPlatformData::dashboard($period));
        }

        $resolver = function () use ($period): array {
            [$start, $end] = PlatformDashboardPeriod::range($period);
            $analytics = PlatformDashboardAnalytics::compute($period, $start, $end);
            $analytics['period'] = $period;
            $analytics['grafico_vendas'] = collect($analytics['grafico']['points'] ?? [])
                ->map(fn (array $p) => [
                    'data' => $p['key'],
                    'total' => $p['volume'],
                ])
                ->values()
                ->all();
            $analytics['ultimas_transacoes'] = self::latestTransactions();

            return $analytics;
        };

        if (defined('PHPUNIT_COMPOSER_INSTALL')) {
            return Inertia::render('Platform/Dashboard', $resolver());
        }

        [$start, $end] = PlatformDashboardPeriod::range($period);
        $cacheKey = 'platform-dashboard:v2:'.$period.':'.md5((string) $start.'|'.(string) $end);
        $payload = Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, $resolver);

        return Inertia::render('Platform/Dashboard', $payload);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function latestTransactions(): array
    {
        return Order::query()
            ->with(['product:id,name', 'tenantOwner:id,name'])
            ->orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->map(function (Order $o) {
                $methodKey = $o->paymentMethodReportKey();
                $raw = strtolower(trim((string) ($o->payment_method ?? '')));
                if (in_array($raw, ['apple_pay', 'google_pay'], true)) {
                    $methodLabel = $raw === 'apple_pay' ? 'Apple Pay' : 'Google Pay';
                } else {
                    $methodLabel = Order::paymentMethodReportLabel($methodKey);
                }

                return [
                    'id' => $o->id,
                    'email' => $o->email,
                    'product_name' => $o->product?->name,
                    'seller_name' => $o->tenantOwner?->name,
                    'amount' => (float) $o->amount,
                    'status' => $o->status,
                    'gateway' => $o->gateway,
                    'gateway_label' => $o->acquirerDisplayName(),
                    'payment_method' => $methodLabel,
                    'created_at' => $o->created_at?->toIso8601String(),
                ];
            })
            ->all();
    }
}
