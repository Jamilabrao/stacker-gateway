<?php

namespace App\Http\Controllers;

use App\Events\DashboardLoading;
use App\Models\CheckoutSession;
use App\Models\Order;
use App\Models\Product;
use App\Support\DashboardBannerSettings;
use App\Support\SqlDialect;
use Carbon\Carbon;
use App\Services\Checkout\CheckoutAbandonmentMetrics;
use App\Services\TeamAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    private const PERIODS = ['hoje', 'ontem', '7dias', 'mes', 'ano', 'total'];

    private const CACHE_TTL_SECONDS = 300; // 5 minutes

    public function __invoke(Request $request): Response
    {
        $period = $request->query('period', 'hoje');
        if (! in_array($period, self::PERIODS, true)) {
            $period = 'hoje';
        }

        $tenantId = auth()->user()->tenant_id;
        $cacheKey = 'dashboard:v4:' . ($tenantId ?? 'global') . ':' . $period;

        $payload = Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, function () use ($tenantId, $period) {
            [$start, $end] = $this->rangeForPeriod($period);

            $ordersQuery = Order::forTenant($tenantId);
            if (auth()->user()?->isTeam()) {
                $allowed = app(TeamAccessService::class)->allowedProductIdsFor(auth()->user());
                $ordersQuery->whereIn('product_id', $allowed ?: ['__none__']);
            }
        if ($start && $end) {
            $ordersQuery->whereBetween('created_at', [$start, $end]);
        } elseif ($start) {
            $ordersQuery->where('created_at', '>=', $start);
        } elseif ($end) {
            $ordersQuery->where('created_at', '<=', $end);
        }

        $ordersCompleted = (clone $ordersQuery)->where('status', 'completed');
        $ordersPending = (clone $ordersQuery)->where('status', 'pending');
        $ordersRefunded = (clone $ordersQuery)->where('status', 'refunded');

        $vendasTotais = (float) $ordersCompleted->sum('amount');
        $quantidadeVendas = $ordersCompleted->count();
        $ticketMedio = $quantidadeVendas > 0 ? $vendasTotais / $quantidadeVendas : 0.0;
        $vendasPendentes = (float) $ordersPending->sum('amount');
        $reembolsosCount = $ordersRefunded->count();
        $reembolsosTotal = (float) (clone $ordersQuery)->where('status', 'refunded')->sum('amount');

        $formasPagamentoRows = (clone $ordersQuery)
            ->where('status', 'completed')
            ->select(['payment_method', 'metadata', 'gateway', 'amount'])
            ->get();

        $formasPagamento = $formasPagamentoRows
            ->groupBy(fn (Order $o) => $o->paymentMethodReportKey())
            ->map(function ($rows, $method) {
                return [
                    'metodo' => $method,
                    'label' => Order::paymentMethodReportLabel($method),
                    'total' => (float) $rows->sum(fn (Order $o) => (float) $o->amount),
                    'quantidade' => (int) $rows->count(),
                    '_sort' => Order::paymentMethodReportSort($method),
                ];
            })
            ->sortBy('_sort')
            ->map(function (array $row) {
                unset($row['_sort']);
                return $row;
            })
            ->values()
            ->all();

        $graficoVendas = $this->buildGraficoVendas($tenantId, $period, $start, $end);

        $productsQuery = Product::forTenant($tenantId);
        if (auth()->user()?->isTeam()) {
            $allowed = app(TeamAccessService::class)->allowedProductIdsFor(auth()->user());
            $productsQuery->whereIn('id', $allowed ?: ['__none__']);
        }
        $quantidadeProdutos = $productsQuery->count();

            $funnel = $this->checkoutFunnelStats($tenantId, $start, $end);

            return [
                'period' => $period,
                'vendas_totais' => round($vendasTotais, 2),
                'vendas_pendentes' => round($vendasPendentes, 2),
                'quantidade_vendas' => $quantidadeVendas,
                'ticket_medio' => round($ticketMedio, 2),
                'formas_pagamento' => $formasPagamento,
                'taxa_conversao' => $funnel['taxa_conversao'],
                'abandono_carrinho' => $funnel['abandono_carrinho'],
                'reembolsos_count' => $reembolsosCount,
                'reembolsos_total' => round($reembolsosTotal, 2),
                'quantidade_produtos' => $quantidadeProdutos,
                'grafico_vendas' => $graficoVendas,
            ];
        });

        $data = new \ArrayObject($payload);
        $data['dashboard_banners'] = DashboardBannerSettings::banners(activeOnly: true, resolveUrls: true);
        event(new DashboardLoading($data));

        return Inertia::render('Dashboard/Index', $data->getArrayCopy());
    }

    /**
     * Abandono: sessões válidas deduplicadas (e-mail + produto, form com e-mail, após graça).
     * Taxa de conversão: sessões com pedido completed / total de sessões no período (created_at).
     *
     * @return array{taxa_conversao: float, abandono_carrinho: int}
     */
    private function checkoutFunnelStats(?int $tenantId, ?string $start, ?string $end): array
    {
        $productIds = null;
        $sessionsQuery = CheckoutSession::forTenant($tenantId);
        if (auth()->user()?->isTeam()) {
            $allowed = app(TeamAccessService::class)->allowedProductIdsFor(auth()->user());
            $productIds = $allowed ?: ['__none__'];
            $sessionsQuery->whereIn('product_id', $productIds);
        }

        if ($start && $end) {
            $sessionsQuery->whereBetween('created_at', [$start, $end]);
        } elseif ($start) {
            $sessionsQuery->where('created_at', '>=', $start);
        } elseif ($end) {
            $sessionsQuery->where('created_at', '<=', $end);
        }

        $converted = (clone $sessionsQuery)
            ->whereFunnelConversionCompleted()
            ->count();

        $abandonadosTotal = app(CheckoutAbandonmentMetrics::class)
            ->countValidAbandoned($tenantId, $productIds, $start, $end);

        $totalSessions = (clone $sessionsQuery)->count();
        $taxaConversao = $totalSessions > 0 ? round((float) $converted / $totalSessions * 100, 1) : 0.0;

        return [
            'taxa_conversao' => $taxaConversao,
            'abandono_carrinho' => $abandonadosTotal,
        ];
    }

    private function rangeForPeriod(string $period): array
    {
        $now = Carbon::now();
        $start = null;
        $end = null;

        switch ($period) {
            case 'hoje':
                $start = $now->copy()->startOfDay();
                $end = $now->copy()->endOfDay();
                break;
            case 'ontem':
                $start = $now->copy()->subDay()->startOfDay();
                $end = $now->copy()->subDay()->endOfDay();
                break;
            case '7dias':
                $start = $now->copy()->subDays(6)->startOfDay();
                $end = $now->copy()->endOfDay();
                break;
            case 'mes':
                $start = $now->copy()->startOfMonth();
                $end = $now->copy()->endOfMonth();
                break;
            case 'ano':
                $start = $now->copy()->startOfYear();
                $end = $now->copy()->endOfYear();
                break;
            case 'total':
                break;
        }

        return [$start?->toDateTimeString(), $end?->toDateTimeString()];
    }

    private function buildGraficoVendas(?int $tenantId, string $period, ?string $start, ?string $end): array
    {
        $query = Order::forTenant($tenantId)->where('status', 'completed');
        if (auth()->user()?->isTeam()) {
            $allowed = app(TeamAccessService::class)->allowedProductIdsFor(auth()->user());
            $query->whereIn('product_id', $allowed ?: ['__none__']);
        }

        if ($start && $end) {
            $query->whereBetween('created_at', [$start, $end]);
        } elseif ($start) {
            $query->where('created_at', '>=', $start);
        } elseif ($end) {
            $query->where('created_at', '<=', $end);
        }

        $isHourly = in_array($period, ['hoje', 'ontem'], true);

        if ($isHourly) {
            $hour = SqlDialect::hourExpression('created_at');
            $rows = $query
                ->selectRaw($hour.' as hora, SUM(amount) as total')
                ->groupBy('hora')
                ->orderBy('hora')
                ->get()
                ->keyBy('hora');

            $result = [];
            for ($h = 0; $h <= 23; $h++) {
                $result[] = [
                    'data' => (string) $h,
                    'total' => (float) ($rows->get($h)?->total ?? 0),
                ];
            }

            return $result;
        }

        $dateExpr = SqlDialect::dateExpression('created_at');
        $rows = $query
            ->selectRaw($dateExpr.' as data, SUM(amount) as total')
            ->groupBy('data')
            ->orderBy('data')
            ->get();

        return $rows->map(fn ($r) => [
            'data' => $r->data,
            'total' => (float) $r->total,
        ])->values()->all();
    }

}
