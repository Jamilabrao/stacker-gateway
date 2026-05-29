<?php

namespace App\Http\Controllers;

use App\Models\CheckoutSession;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Support\SqlDialect;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RelatoriosController extends Controller
{
    private const PERIODS = ['hoje', 'ontem', '7dias', 'mes', 'ano', 'total', 'personalizado'];

    public function index(Request $request): Response
    {
        $period = $request->query('period', 'hoje');
        if (! in_array($period, self::PERIODS, true)) {
            $period = 'hoje';
        }

        $tenantId = auth()->user()->tenant_id;
        [$start, $end] = $this->resolveDateRange($request, $period);

        $ordersQuery = Order::forTenant($tenantId);
        if ($start && $end) {
            $ordersQuery->whereBetween('created_at', [$start, $end]);
        } elseif ($start) {
            $ordersQuery->where('created_at', '>=', $start);
        } elseif ($end) {
            $ordersQuery->where('created_at', '<=', $end);
        }

        $ordersCompleted = (clone $ordersQuery)->where('status', 'completed');
        $ordersRefunded = (clone $ordersQuery)->where('status', 'refunded');

        $receitaTotal = (float) $ordersCompleted->sum('amount');
        $quantidadeVendas = $ordersCompleted->count();
        $ticketMedio = $quantidadeVendas > 0 ? $receitaTotal / $quantidadeVendas : 0.0;
        $reembolsosCount = $ordersRefunded->count();
        $reembolsosTotal = (float) $ordersRefunded->sum('amount');

        $totalAlunos = User::whereIn('role', User::buyerRoleValues())
            ->whereHas('products', fn ($q) => $tenantId === null ? $q->whereNull('tenant_id') : $q->where('tenant_id', $tenantId))
            ->count();
        $totalProdutos = Product::forTenant($tenantId)->count();

        $formasPagamento = (clone $ordersQuery)
            ->where('status', 'completed')
            ->selectRaw('gateway, SUM(amount) as total, COUNT(*) as quantidade')
            ->groupBy('gateway')
            ->get()
            ->map(function ($row) {
                $label = $this->gatewayLabel($row->gateway);

                return [
                    'metodo' => $row->gateway ?? 'outro',
                    'label' => $label,
                    'total' => (float) $row->total,
                    'quantidade' => (int) $row->quantidade,
                ];
            })
            ->values()
            ->all();

        $graficoReceita = $this->buildGraficoReceita($tenantId, $start, $end);

        $receitaPorProduto = Order::query()
            ->when($tenantId === null, fn ($q) => $q->whereNull('orders.tenant_id'), fn ($q) => $q->where('orders.tenant_id', $tenantId))
            ->where('orders.status', 'completed');
        if ($start && $end) {
            $receitaPorProduto->whereBetween('orders.created_at', [$start, $end]);
        } elseif ($start) {
            $receitaPorProduto->where('orders.created_at', '>=', $start);
        } elseif ($end) {
            $receitaPorProduto->where('orders.created_at', '<=', $end);
        }
        $receitaPorProduto = $receitaPorProduto
            ->join('products', 'orders.product_id', '=', 'products.id')
            ->selectRaw('products.id as product_id, products.name as product_name, SUM(orders.amount) as total, COUNT(*) as quantidade')
            ->groupBy('products.id', 'products.name')
            ->orderByDesc('total')
            ->limit(10)
            ->get()
            ->map(fn ($r) => [
                'product_id' => $r->product_id,
                'product_name' => $r->product_name,
                'total' => (float) $r->total,
                'quantidade' => (int) $r->quantidade,
            ])
            ->values()
            ->all();

        $sessionsQuery = CheckoutSession::forTenant($tenantId);
        if ($start && $end) {
            $sessionsQuery->whereBetween('created_at', [$start, $end]);
        } elseif ($start) {
            $sessionsQuery->where('created_at', '>=', $start);
        } elseif ($end) {
            $sessionsQuery->where('created_at', '<=', $end);
        }

        $abandonadosVisit = (clone $sessionsQuery)
            ->whereAbandonmentVisitEligible()
            ->count();

        $abandonadosForm = (clone $sessionsQuery)
            ->whereAbandonmentFormEligible()
            ->count();

        $converted = (clone $sessionsQuery)
            ->whereFunnelConversionCompleted()
            ->count();

        $abandonadosTotal = $abandonadosVisit + $abandonadosForm;
        $totalSessions = (clone $sessionsQuery)->count();
        $taxaConversao = $totalSessions > 0 ? round((float) $converted / $totalSessions * 100, 1) : 0.0;

        $abandonadosComEmail = CheckoutSession::forTenant($tenantId)
            ->whereAbandonmentFormEligible()
            ->whereNotNull('email')
            ->where('email', '!=', '');
        if ($start && $end) {
            $abandonadosComEmail->whereBetween('created_at', [$start, $end]);
        } elseif ($start) {
            $abandonadosComEmail->where('created_at', '>=', $start);
        } elseif ($end) {
            $abandonadosComEmail->where('created_at', '<=', $end);
        }
        $abandonadosComEmail = $abandonadosComEmail
            ->with('product:id,name')
            ->orderByDesc('updated_at')
            ->limit(20)
            ->get()
            ->map(fn ($s) => [
                'id' => $s->id,
                'email' => $s->email,
                'name' => $s->name,
                'phone' => $s->phone,
                'product_name' => $s->product?->name ?? '–',
                'updated_at' => $s->updated_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        return Inertia::render('Relatorios/Index', [
            'period' => $period,
            'date_from' => $period === 'personalizado' ? ($this->normalizeDateQuery($request->query('date_from')) ?? '') : null,
            'date_to' => $period === 'personalizado' ? ($this->normalizeDateQuery($request->query('date_to')) ?? '') : null,
            'receita_total' => round($receitaTotal, 2),
            'quantidade_vendas' => $quantidadeVendas,
            'ticket_medio' => round($ticketMedio, 2),
            'total_alunos' => $totalAlunos,
            'total_produtos' => $totalProdutos,
            'formas_pagamento' => $formasPagamento,
            'grafico_receita' => $graficoReceita,
            'receita_por_produto' => $receitaPorProduto,
            'abandonados_visit' => $abandonadosVisit,
            'abandonados_form' => $abandonadosForm,
            'abandonados_total' => $abandonadosTotal,
            'taxa_conversao' => $taxaConversao,
            'abandonados_com_email' => $abandonadosComEmail,
            'reembolsos_count' => $reembolsosCount,
            'reembolsos_total' => round($reembolsosTotal, 2),
        ]);
    }

    public function exportAbandonedCarts(Request $request): StreamedResponse
    {
        $period = $request->query('period', 'hoje');
        if (! in_array($period, self::PERIODS, true)) {
            $period = 'hoje';
        }

        $tenantId = auth()->user()->tenant_id;
        [$start, $end] = $this->resolveDateRange($request, $period);

        $query = $this->abandonedCheckoutSessionsQuery($tenantId, $start, $end)
            ->with(['product:id,name', 'productOffer:id,name'])
            ->orderByDesc('updated_at');

        $filename = 'carrinhos_abandonados_'.date('Y-m-d_His').'.csv';

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
            fputcsv($out, [
                'ID',
                'Criado em',
                'Atualizado em',
                'Etapa',
                'E-mail',
                'Nome',
                'Telefone',
                'Produto',
                'Oferta',
                'Checkout',
                'utm_source',
                'utm_medium',
                'utm_campaign',
                'Pedido',
            ], ';');

            $query->chunkById(500, function ($sessions) use ($out) {
                foreach ($sessions as $s) {
                    fputcsv($out, [
                        $s->id,
                        $s->created_at?->format('d/m/Y H:i'),
                        $s->updated_at?->format('d/m/Y H:i'),
                        $s->step,
                        $s->email ?? '',
                        $s->name ?? '',
                        $s->phone ?? '',
                        $s->product?->name ?? '',
                        $s->productOffer?->name ?? '',
                        $s->checkout_slug ?? '',
                        $s->utm_source ?? '',
                        $s->utm_medium ?? '',
                        $s->utm_campaign ?? '',
                        $s->order_id ? (string) $s->order_id : '',
                    ], ';');
                }
            }, 'id');
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * Sessões consideradas carrinho abandonado (visitou checkout sem pedido ou formulário sem conclusão).
     *
     * @param  string|null  $start  datetime string
     * @param  string|null  $end  datetime string
     */
    private function abandonedCheckoutSessionsQuery(?int $tenantId, ?string $start, ?string $end)
    {
        $q = CheckoutSession::forTenant($tenantId);
        if ($start && $end) {
            $q->whereBetween('created_at', [$start, $end]);
        } elseif ($start) {
            $q->where('created_at', '>=', $start);
        } elseif ($end) {
            $q->where('created_at', '<=', $end);
        }

        return $q->where(function ($outer) {
            $outer->where(function ($visit) {
                $visit->whereAbandonmentVisitEligible();
            })->orWhere(function ($form) {
                $form->whereAbandonmentFormEligible();
            });
        });
    }

    private function normalizeDateQuery(mixed $v): ?string
    {
        if (! is_string($v)) {
            return null;
        }
        $v = trim($v);
        if ($v === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
            return null;
        }

        return $v;
    }

    /**
     * @return array{0: ?string, 1: ?string} [$start, $end] as datetime strings for SQL
     */
    private function resolveDateRange(Request $request, string $period): array
    {
        if ($period === 'personalizado') {
            $df = $this->normalizeDateQuery($request->query('date_from'));
            $dt = $this->normalizeDateQuery($request->query('date_to'));
            if ($df !== null && $dt !== null) {
                $start = Carbon::parse($df)->startOfDay();
                $end = Carbon::parse($dt)->endOfDay();
                if ($start->gt($end)) {
                    $tmp = $start->copy();
                    $start = $end->copy()->startOfDay();
                    $end = $tmp->endOfDay();
                }

                return [$start->toDateTimeString(), $end->toDateTimeString()];
            }

            $now = Carbon::now();

            return [$now->copy()->subDays(6)->startOfDay()->toDateTimeString(), $now->copy()->endOfDay()->toDateTimeString()];
        }

        return $this->rangeForPeriod($period);
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

    private function gatewayLabel(?string $gateway): string
    {
        if ($gateway === null || $gateway === '') {
            return 'Outro';
        }
        $g = strtolower($gateway);
        if (str_contains($g, 'pix') || in_array($g, ['spacepag'], true)) {
            return 'Pix';
        }
        if (str_contains($g, 'card') || str_contains($g, 'cartao') || str_contains($g, 'cartão') || str_contains($g, 'credito')) {
            return 'Cartão';
        }
        if (str_contains($g, 'boleto')) {
            return 'Boleto';
        }

        return ucfirst($gateway);
    }

    private function buildGraficoReceita(?int $tenantId, ?string $start, ?string $end): array
    {
        $query = Order::forTenant($tenantId)->where('status', 'completed');

        if ($start && $end) {
            $query->whereBetween('created_at', [$start, $end]);
        } elseif ($start) {
            $query->where('created_at', '>=', $start);
        } elseif ($end) {
            $query->where('created_at', '<=', $end);
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
