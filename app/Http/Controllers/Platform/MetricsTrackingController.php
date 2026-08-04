<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\MetricsEvent;
use App\Models\MetricsReportAccessLog;
use App\Models\Product;
use App\Models\User;
use App\Services\MetricsTracking\MetricsAnalyticsService;
use App\Services\MetricsTracking\MetricsClientParser;
use App\Services\MetricsTracking\MetricsExportService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MetricsTrackingController extends Controller
{
    public function __construct(
        private readonly MetricsAnalyticsService $analytics,
    ) {}

    public function index(Request $request): Response
    {
        [$tenantId, $period, $start, $end, $filters] = $this->resolveScope($request);
        $this->logAccess('platform_dashboard', $filters, $period, $tenantId);

        return Inertia::render('Platform/Metrics/Index', [
            'period' => $period,
            'date_from' => $request->query('date_from'),
            'date_to' => $request->query('date_to'),
            'filters' => $filters,
            'seller_id' => $tenantId,
            'sellers' => $this->sellersList(),
            'summary' => $this->analytics->summary($tenantId, $start, $end, $filters, true),
            'timeseries' => $this->analytics->timeseries($tenantId, $start, $end, $filters, true),
            'funnel' => $this->analytics->funnel($tenantId, $start, $end, $filters, true),
            'by_seller' => array_slice($this->analytics->breakdown($tenantId, $start, $end, 'tenant_id', $filters, true), 0, 20),
            'by_source' => array_slice($this->analytics->breakdown($tenantId, $start, $end, 'utm_source', $filters, true), 0, 15),
            'by_campaign' => array_slice($this->analytics->breakdown($tenantId, $start, $end, 'utm_campaign', $filters, true), 0, 15),
            'by_device' => $this->analytics->distribution($tenantId, $start, $end, 'device_type', $filters, true),
            'by_country' => $this->analytics->distribution($tenantId, $start, $end, 'country', $filters, true),
            'products' => $this->productsForSeller($tenantId),
            'tab' => 'dashboard',
            'base_path' => '/plataforma/metricas',
        ]);
    }

    public function origins(Request $request): Response
    {
        [$tenantId, $period, $start, $end, $filters] = $this->resolveScope($request);
        $dimension = (string) $request->query('dimension', 'tenant_id');
        $this->logAccess('platform_origins', array_merge($filters, ['dimension' => $dimension]), $period, $tenantId);

        return Inertia::render('Platform/Metrics/Origins', [
            'period' => $period,
            'date_from' => $request->query('date_from'),
            'date_to' => $request->query('date_to'),
            'filters' => $filters,
            'seller_id' => $tenantId,
            'sellers' => $this->sellersList(),
            'dimension' => $dimension,
            'rows' => $this->analytics->breakdown($tenantId, $start, $end, $dimension, $filters, true),
            'products' => $this->productsForSeller($tenantId),
            'tab' => 'origins',
            'base_path' => '/plataforma/metricas',
        ]);
    }

    public function funnel(Request $request): Response
    {
        [$tenantId, $period, $start, $end, $filters] = $this->resolveScope($request);
        $this->logAccess('platform_funnel', $filters, $period, $tenantId);

        return Inertia::render('Platform/Metrics/Funnel', [
            'period' => $period,
            'date_from' => $request->query('date_from'),
            'date_to' => $request->query('date_to'),
            'filters' => $filters,
            'seller_id' => $tenantId,
            'sellers' => $this->sellersList(),
            'funnel' => $this->analytics->funnel($tenantId, $start, $end, $filters, true),
            'summary' => $this->analytics->summary($tenantId, $start, $end, $filters, true),
            'products' => $this->productsForSeller($tenantId),
            'tab' => 'funnel',
            'base_path' => '/plataforma/metricas',
        ]);
    }

    public function clicks(Request $request): Response
    {
        [$tenantId, $period, $start, $end, $filters] = $this->resolveScope($request);
        $search = trim((string) $request->query('q', ''));
        $this->logAccess('platform_clicks', array_merge($filters, ['q' => $search]), $period, $tenantId);

        $query = $this->analytics->eventsQuery($tenantId, $start, $end, $filters, true)
            ->whereIn('event_name', [
                MetricsEvent::PAGE_VIEW,
                MetricsEvent::CHECKOUT_VIEW,
                MetricsEvent::LINK_CLICKED,
                MetricsEvent::CHECKOUT_STARTED,
                MetricsEvent::PIX_CREATED,
                MetricsEvent::PAYMENT_APPROVED,
                MetricsEvent::PAYMENT_REFUNDED,
            ])
            ->with(['product:id,name'])
            ->orderByDesc('occurred_at');

        if ($search !== '') {
            $like = '%'.mb_strtolower($search).'%';
            $query->where(function ($q) use ($like) {
                $q->whereRaw('LOWER(COALESCE(utm_campaign, \'\')) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(utm_source, \'\')) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(affiliate_ref, \'\')) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(destination_url, \'\')) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(city, \'\')) LIKE ?', [$like]);
            });
        }

        $paginator = $query->paginate(50)->withQueryString();
        $sellerIds = collect($paginator->items())->pluck('tenant_id')->filter()->unique()->values()->all();
        $sellerMap = $sellerIds
            ? User::query()->whereIn('id', $sellerIds)->get(['id', 'name', 'email'])->keyBy('id')
            : collect();

        $rows = collect($paginator->items())->map(function (MetricsEvent $e) use ($sellerMap) {
            $seller = $e->tenant_id ? $sellerMap->get($e->tenant_id) : null;

            return [
                'id' => $e->id,
                'occurred_at' => optional($e->occurred_at)?->toIso8601String(),
                'event_name' => $e->event_name,
                'ip_masked' => $e->ip_masked,
                'seller_name' => $seller ? (($seller->name ?: $seller->email).' #'.$seller->id) : '—',
                'tenant_id' => $e->tenant_id,
                'product_name' => $e->product?->name,
                'destination_url' => $e->destination_url,
                'utm_source' => $e->utm_source,
                'utm_campaign' => $e->utm_campaign,
                'device_type' => $e->device_type,
                'city' => $e->city,
                'region' => $e->region,
                'affiliate_ref' => $e->affiliate_ref,
                'conversion_status' => $e->conversion_status,
                'amount' => $e->amount !== null ? (float) $e->amount : null,
                'seconds_to_convert' => $e->seconds_to_convert,
            ];
        })->values()->all();

        return Inertia::render('Platform/Metrics/Clicks', [
            'period' => $period,
            'date_from' => $request->query('date_from'),
            'date_to' => $request->query('date_to'),
            'filters' => $filters,
            'seller_id' => $tenantId,
            'sellers' => $this->sellersList(),
            'q' => $search,
            'rows' => $rows,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'products' => $this->productsForSeller($tenantId),
            'tab' => 'clicks',
            'base_path' => '/plataforma/metricas',
        ]);
    }

    public function map(Request $request): Response
    {
        [$tenantId, $period, $start, $end, $filters] = $this->resolveScope($request);
        $metric = in_array($request->query('metric'), ['conversions', 'events', 'revenue'], true)
            ? (string) $request->query('metric')
            : 'conversions';
        $this->logAccess('platform_map', array_merge($filters, ['metric' => $metric]), $period, $tenantId);

        $geo = $this->analytics->geoMap($tenantId, $start, $end, $filters, true);

        return Inertia::render('Platform/Metrics/Map', [
            'period' => $period,
            'date_from' => $request->query('date_from'),
            'date_to' => $request->query('date_to'),
            'filters' => $filters,
            'seller_id' => $tenantId,
            'sellers' => $this->sellersList(),
            'metric' => $metric,
            'points' => $geo['points'],
            'by_city' => $geo['by_city'],
            'by_region' => $geo['by_region'],
            'by_country' => $geo['by_country'],
            'totals' => $geo['totals'],
            'products' => $this->productsForSeller($tenantId),
            'tab' => 'map',
            'base_path' => '/plataforma/metricas',
        ]);
    }

    public function exportCsv(Request $request): StreamedResponse
    {
        return $this->export($request, 'csv');
    }

    public function exportXlsx(Request $request): StreamedResponse
    {
        return $this->export($request, 'xlsx');
    }

    private function export(Request $request, string $format): StreamedResponse
    {
        [$tenantId, $period, $start, $end, $filters] = $this->resolveScope($request);
        $type = (string) $request->query('type', 'origins');
        $dimension = (string) $request->query('dimension', 'tenant_id');
        $this->logAccess(
            $format === 'xlsx' ? 'platform_export_xlsx' : 'platform_export_csv',
            array_merge($filters, compact('type', 'dimension')),
            $period,
            $tenantId
        );

        return app(MetricsExportService::class)->download(
            $format,
            $type,
            $tenantId,
            $start,
            $end,
            $filters,
            $dimension,
            true,
            'metricas-plataforma',
        );
    }

    /**
     * @return array{0:?int,1:string,2:?\Carbon\Carbon,3:?\Carbon\Carbon,4:array<string,mixed>}
     */
    private function resolveScope(Request $request): array
    {
        $period = $this->analytics->normalizePeriod((string) $request->query('period', 'hoje'));
        [$start, $end] = $this->analytics->resolveDateRange($request, $period);
        $filters = $this->analytics->filtersFromRequest($request);
        $sellerId = $request->integer('seller_id') ?: null;

        return [$sellerId, $period, $start, $end, $filters];
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

    /**
     * @return list<array{id:string,name:string}>
     */
    private function productsForSeller(?int $tenantId): array
    {
        $q = Product::query()->orderBy('name');
        if ($tenantId !== null) {
            $q->where('tenant_id', $tenantId);
        } else {
            $q->limit(300);
        }

        return $q->get(['id', 'name'])
            ->map(fn (Product $p) => ['id' => $p->id, 'name' => $p->name])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function logAccess(string $report, array $filters, string $period, ?int $tenantId): void
    {
        try {
            MetricsReportAccessLog::query()->create([
                'user_id' => auth()->id(),
                'tenant_id' => $tenantId,
                'report' => $report,
                'filters' => array_merge($filters, ['period' => $period, 'seller_id' => $tenantId]),
                'ip_masked' => MetricsClientParser::maskIp(request()->ip()),
            ]);
        } catch (\Throwable) {
        }
    }
}
