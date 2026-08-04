<?php

namespace App\Http\Controllers;

use App\Models\MetricsEvent;
use App\Models\MetricsReportAccessLog;
use App\Models\Product;
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
        $tenantId = auth()->user()->tenant_id;
        $period = $this->analytics->normalizePeriod((string) $request->query('period', 'hoje'));
        [$start, $end] = $this->analytics->resolveDateRange($request, $period);
        $filters = $this->analytics->filtersFromRequest($request);

        $this->logAccess('dashboard', $filters, $period);

        $summary = $this->analytics->summary($tenantId, $start, $end, $filters);
        $timeseries = $this->analytics->timeseries($tenantId, $start, $end, $filters);
        $funnel = $this->analytics->funnel($tenantId, $start, $end, $filters);
        $bySource = $this->analytics->breakdown($tenantId, $start, $end, 'utm_source', $filters);
        $byCampaign = $this->analytics->breakdown($tenantId, $start, $end, 'utm_campaign', $filters);
        $byDevice = $this->analytics->distribution($tenantId, $start, $end, 'device_type', $filters);
        $byCountry = $this->analytics->distribution($tenantId, $start, $end, 'country', $filters);

        $products = Product::forTenant($tenantId)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Product $p) => ['id' => $p->id, 'name' => $p->name])
            ->values()
            ->all();

        return Inertia::render('Metrics/Index', [
            'period' => $period,
            'date_from' => $request->query('date_from'),
            'date_to' => $request->query('date_to'),
            'filters' => $filters,
            'summary' => $summary,
            'timeseries' => $timeseries,
            'funnel' => $funnel,
            'by_source' => array_slice($bySource, 0, 15),
            'by_campaign' => array_slice($byCampaign, 0, 15),
            'by_device' => $byDevice,
            'by_country' => $byCountry,
            'products' => $products,
            'tab' => 'dashboard',
        ]);
    }

    public function origins(Request $request): Response
    {
        $tenantId = auth()->user()->tenant_id;
        $period = $this->analytics->normalizePeriod((string) $request->query('period', '7dias'));
        [$start, $end] = $this->analytics->resolveDateRange($request, $period);
        $filters = $this->analytics->filtersFromRequest($request);
        $dimension = (string) $request->query('dimension', 'utm_source');

        $this->logAccess('origins', array_merge($filters, ['dimension' => $dimension]), $period);

        $rows = $this->analytics->breakdown($tenantId, $start, $end, $dimension, $filters);
        $products = Product::forTenant($tenantId)->orderBy('name')->get(['id', 'name'])
            ->map(fn (Product $p) => ['id' => $p->id, 'name' => $p->name])->values()->all();

        return Inertia::render('Metrics/Origins', [
            'period' => $period,
            'date_from' => $request->query('date_from'),
            'date_to' => $request->query('date_to'),
            'filters' => $filters,
            'dimension' => $dimension,
            'rows' => $rows,
            'products' => $products,
            'tab' => 'origins',
        ]);
    }

    public function funnel(Request $request): Response
    {
        $tenantId = auth()->user()->tenant_id;
        $period = $this->analytics->normalizePeriod((string) $request->query('period', '7dias'));
        [$start, $end] = $this->analytics->resolveDateRange($request, $period);
        $filters = $this->analytics->filtersFromRequest($request);

        $this->logAccess('funnel', $filters, $period);

        return Inertia::render('Metrics/Funnel', [
            'period' => $period,
            'date_from' => $request->query('date_from'),
            'date_to' => $request->query('date_to'),
            'filters' => $filters,
            'funnel' => $this->analytics->funnel($tenantId, $start, $end, $filters),
            'summary' => $this->analytics->summary($tenantId, $start, $end, $filters),
            'products' => Product::forTenant($tenantId)->orderBy('name')->get(['id', 'name'])
                ->map(fn (Product $p) => ['id' => $p->id, 'name' => $p->name])->values()->all(),
            'tab' => 'funnel',
        ]);
    }

    public function clicks(Request $request): Response
    {
        $tenantId = auth()->user()->tenant_id;
        $period = $this->analytics->normalizePeriod((string) $request->query('period', '7dias'));
        [$start, $end] = $this->analytics->resolveDateRange($request, $period);
        $filters = $this->analytics->filtersFromRequest($request);
        $search = trim((string) $request->query('q', ''));

        $this->logAccess('clicks', array_merge($filters, ['q' => $search]), $period);

        $query = $this->analytics->eventsQuery($tenantId, $start, $end, $filters)
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

        $rows = collect($paginator->items())->map(function (MetricsEvent $e) {
            return [
                'id' => $e->id,
                'occurred_at' => optional($e->occurred_at)?->toIso8601String(),
                'event_name' => $e->event_name,
                'ip_masked' => $e->ip_masked,
                'session_key' => $e->session_key,
                'visitor_key' => $e->visitor_key,
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

        return Inertia::render('Metrics/Clicks', [
            'period' => $period,
            'date_from' => $request->query('date_from'),
            'date_to' => $request->query('date_to'),
            'filters' => $filters,
            'q' => $search,
            'rows' => $rows,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'products' => Product::forTenant($tenantId)->orderBy('name')->get(['id', 'name'])
                ->map(fn (Product $p) => ['id' => $p->id, 'name' => $p->name])->values()->all(),
            'tab' => 'clicks',
        ]);
    }

    public function map(Request $request): Response
    {
        $tenantId = auth()->user()->tenant_id;
        $period = $this->analytics->normalizePeriod((string) $request->query('period', '7dias'));
        [$start, $end] = $this->analytics->resolveDateRange($request, $period);
        $filters = $this->analytics->filtersFromRequest($request);
        $metric = in_array($request->query('metric'), ['conversions', 'events', 'revenue'], true)
            ? (string) $request->query('metric')
            : 'conversions';

        $this->logAccess('map', array_merge($filters, ['metric' => $metric]), $period);

        $geo = $this->analytics->geoMap($tenantId, $start, $end, $filters);

        return Inertia::render('Metrics/Map', [
            'period' => $period,
            'date_from' => $request->query('date_from'),
            'date_to' => $request->query('date_to'),
            'filters' => $filters,
            'metric' => $metric,
            'points' => $geo['points'],
            'by_city' => $geo['by_city'],
            'by_region' => $geo['by_region'],
            'by_country' => $geo['by_country'],
            'totals' => $geo['totals'],
            'products' => Product::forTenant($tenantId)->orderBy('name')->get(['id', 'name'])
                ->map(fn (Product $p) => ['id' => $p->id, 'name' => $p->name])->values()->all(),
            'tab' => 'map',
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
        $tenantId = auth()->user()->tenant_id;
        $period = $this->analytics->normalizePeriod((string) $request->query('period', '7dias'));
        [$start, $end] = $this->analytics->resolveDateRange($request, $period);
        $filters = $this->analytics->filtersFromRequest($request);
        $type = (string) $request->query('type', 'origins');
        $dimension = (string) $request->query('dimension', 'utm_source');

        $this->logAccess(
            $format === 'xlsx' ? 'export_xlsx' : 'export_csv',
            array_merge($filters, ['type' => $type, 'dimension' => $dimension]),
            $period
        );

        return app(MetricsExportService::class)->download(
            $format,
            $type,
            $tenantId,
            $start,
            $end,
            $filters,
            $dimension,
            false,
        );
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function logAccess(string $report, array $filters, string $period): void
    {
        try {
            MetricsReportAccessLog::query()->create([
                'user_id' => auth()->id(),
                'tenant_id' => auth()->user()?->tenant_id,
                'report' => $report,
                'filters' => array_merge($filters, ['period' => $period]),
                'ip_masked' => MetricsClientParser::maskIp(request()->ip()),
            ]);
        } catch (\Throwable) {
            // logging auxiliar
        }
    }
}
