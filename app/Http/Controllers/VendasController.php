<?php

namespace App\Http\Controllers;

use App\Models\CheckoutSession;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\User;
use App\Support\SaleOrigin;
use App\Services\AccessEmailService;
use App\Services\ManualOrderRefundService;
use App\Services\Med\MedPolicyService;
use App\Services\PixGoAccess;
use App\Services\OrderFeeBreakdownService;
use App\Services\TeamAccessService;
use App\Support\OrderManualRefund;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class VendasController extends Controller
{
    private const STATUS_FILTERS = ['aprovadas', 'med', 'todas'];

    private function normalizeStatusFilter(Request $request): string
    {
        $statusFilter = $request->query('status_filter', 'todas');
        if (! in_array($statusFilter, self::STATUS_FILTERS, true)) {
            $statusFilter = 'todas';
        }

        return $statusFilter;
    }

    private function normalizeString(?string $value): ?string
    {
        $v = trim((string) ($value ?? ''));

        return $v !== '' ? $v : null;
    }

    private function applyStatusFilter($query, string $statusFilter)
    {
        return match ($statusFilter) {
            'aprovadas' => $query->where('status', 'completed'),
            'med' => $query->where(function ($q) {
                $q->whereHas('medDisputes', function ($d) {
                    $d->tenantManaged()->open();
                })->orWhere(function ($q2) {
                    app(MedPolicyService::class)->scopeApiPixRestOrders($q2);
                    $q2->where('status', 'disputed');
                });
            }),
            default => $query,
        };
    }

    private function applySaleChannelFilter($query, Request $request)
    {
        $channel = $this->normalizeString($request->query('sale_channel'));
        if ($channel === 'api_pix') {
            return app(MedPolicyService::class)->scopeApiPixRestOrders($query);
        }
        if ($channel === 'pixgo') {
            return $query->where('metadata->source', 'pixgo');
        }

        return $query;
    }

    private function applyPeriodFilter($query, Request $request)
    {
        $period = $this->normalizeString($request->query('period'));
        $from = $this->normalizeString($request->query('date_from'));
        $to = $this->normalizeString($request->query('date_to'));

        if ($period === null || $period === 'all') {
            return $query;
        }

        $now = now();
        $start = null;
        $end = null;

        if ($period === 'today') {
            $start = $now->copy()->startOfDay();
            $end = $now->copy()->endOfDay();
        } elseif ($period === '7d') {
            $start = $now->copy()->subDays(6)->startOfDay();
            $end = $now->copy()->endOfDay();
        } elseif ($period === '30d') {
            $start = $now->copy()->subDays(29)->startOfDay();
            $end = $now->copy()->endOfDay();
        } elseif ($period === 'this_month') {
            $start = $now->copy()->startOfMonth();
            $end = $now->copy()->endOfDay();
        } elseif ($period === 'last_month') {
            $start = $now->copy()->subMonthNoOverflow()->startOfMonth();
            $end = $now->copy()->subMonthNoOverflow()->endOfMonth();
        } elseif ($period === 'custom') {
            $start = $from ? \Illuminate\Support\Carbon::parse($from)->startOfDay() : null;
            $end = $to ? \Illuminate\Support\Carbon::parse($to)->endOfDay() : null;
        }

        if ($start && $end) {
            return $query->whereBetween('created_at', [$start, $end]);
        }
        if ($start) {
            return $query->where('created_at', '>=', $start);
        }
        if ($end) {
            return $query->where('created_at', '<=', $end);
        }

        return $query;
    }

    private function applySearchFilter($query, Request $request)
    {
        $q = $this->normalizeString($request->query('q'));
        if ($q === null) {
            return $query;
        }
        $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $q).'%';

        return $query->where(function ($sub) use ($like) {
            $sub->where('orders.id', 'like', $like)
                ->orWhere('orders.email', 'like', $like)
                ->orWhereHas('user', fn ($uq) => $uq->where('name', 'like', $like)->orWhere('email', 'like', $like))
                ->orWhereHas('product', fn ($pq) => $pq->where('name', 'like', $like))
                ->orWhereHas('productOffer', fn ($oq) => $oq->where('name', 'like', $like))
                ->orWhereHas('subscriptionPlan', fn ($sq) => $sq->where('name', 'like', $like));
        });
    }

    /**
     * @return list<string>
     */
    private function normalizeProductIds(Request $request): array
    {
        $raw = $request->query('product_ids');
        $ids = [];
        if (is_array($raw)) {
            foreach ($raw as $id) {
                $s = $this->normalizeString(is_scalar($id) ? (string) $id : null);
                if ($s !== null) {
                    $ids[$s] = $s;
                }
            }
        } elseif (is_string($raw) && trim($raw) !== '') {
            foreach (array_filter(array_map('trim', explode(',', $raw))) as $id) {
                $s = $this->normalizeString($id);
                if ($s !== null) {
                    $ids[$s] = $s;
                }
            }
        }
        $legacy = $this->normalizeString($request->query('product_id'));
        if ($legacy !== null) {
            $ids[$legacy] = $legacy;
        }

        return array_values($ids);
    }

    private function applyProductFilters($query, Request $request)
    {
        $productIds = $this->normalizeProductIds($request);
        if (auth()->user()?->isTeam()) {
            $allowed = app(TeamAccessService::class)->allowedProductIdsFor(auth()->user());
            $productIds = array_values(array_intersect($productIds, $allowed));
        }

        if (count($productIds) === 1) {
            $query->where('product_id', $productIds[0]);
        } elseif (count($productIds) > 1) {
            $query->whereIn('product_id', $productIds);
        }

        $offerId = $request->query('offer_id');
        $offerId = is_string($offerId) || is_int($offerId) ? (string) $offerId : null;
        $offerId = $this->normalizeString($offerId);

        if ($offerId !== null) {
            $query->where('product_offer_id', (int) $offerId);
        }

        return $query;
    }

    private function applyPaymentFilters($query, Request $request)
    {
        $method = $this->normalizeString($request->query('payment_method'));
        if ($method !== null) {
            $m = strtolower($method);
            if ($m === 'pix') {
                $query->where(function ($q) {
                    $q->whereIn('gateway', ['spacepag'])
                        ->orWhereRaw("LOWER(gateway) LIKE '%pix%'")
                        ->orWhere(function ($q2) {
                            $q2->where('metadata->checkout_payment_method', 'pix')
                                ->orWhere('metadata->checkout_payment_method', 'pix_auto');
                        });
                });
            } elseif ($m === 'card') {
                $query->where(function ($q) {
                    $q->where('gateway', 'card')
                        ->orWhereRaw("LOWER(gateway) LIKE '%card%'")
                        ->orWhereRaw("LOWER(gateway) LIKE '%cartao%'")
                        ->orWhereRaw("LOWER(gateway) LIKE '%cartão%'")
                        ->orWhereRaw("LOWER(gateway) LIKE '%credito%'")
                        ->orWhere('metadata->checkout_payment_method', 'card');
                });
            } elseif ($m === 'boleto') {
                $query->where(function ($q) {
                    $q->where('gateway', 'boleto')
                        ->orWhereRaw("LOWER(gateway) LIKE '%boleto%'")
                        ->orWhere('metadata->checkout_payment_method', 'boleto');
                });
            }
        }

        $paymentStatus = $this->normalizeString($request->query('payment_status'));
        if ($paymentStatus !== null && $paymentStatus !== 'all') {
            $query->where('status', $paymentStatus);
        }

        return $query;
    }

    private function applyUtmFilters($query, Request $request, ?int $tenantId)
    {
        $utmSource = $this->normalizeString($request->query('utm_source'));
        $utmMedium = $this->normalizeString($request->query('utm_medium'));
        $utmCampaign = $this->normalizeString($request->query('utm_campaign'));

        if ($utmSource === null && $utmMedium === null && $utmCampaign === null) {
            return $query;
        }

        return $query->where(function ($outer) use ($tenantId, $utmSource, $utmMedium, $utmCampaign) {
            $outer->whereExists(function ($q) use ($tenantId, $utmSource, $utmMedium, $utmCampaign) {
                $q->select(DB::raw(1))
                    ->from('checkout_sessions')
                    ->whereColumn('checkout_sessions.order_id', 'orders.id');

                if ($tenantId === null) {
                    $q->whereNull('checkout_sessions.tenant_id');
                } else {
                    $q->where('checkout_sessions.tenant_id', $tenantId);
                }

                if ($utmSource !== null) {
                    $q->where('checkout_sessions.utm_source', $utmSource);
                }
                if ($utmMedium !== null) {
                    $q->where('checkout_sessions.utm_medium', $utmMedium);
                }
                if ($utmCampaign !== null) {
                    $q->where('checkout_sessions.utm_campaign', $utmCampaign);
                }
            });
            $outer->orWhere(function ($metaQ) use ($utmSource, $utmMedium, $utmCampaign) {
                if ($utmSource !== null) {
                    $metaQ->where('orders.metadata->utm_source', $utmSource);
                }
                if ($utmMedium !== null) {
                    $metaQ->where('orders.metadata->utm_medium', $utmMedium);
                }
                if ($utmCampaign !== null) {
                    $metaQ->where('orders.metadata->utm_campaign', $utmCampaign);
                }
            });
        });
    }

    private function applyAffiliateFilter($query, Request $request)
    {
        $affiliate = $this->normalizeString($request->query('affiliate'));
        if ($affiliate !== '1' && $affiliate !== 'yes') {
            return $query;
        }

        return $query->where(function ($q) {
            $q->whereNotNull('affiliate_user_id');
            if (DB::getDriverName() === 'pgsql') {
                $q->orWhereRaw("(metadata->>'affiliate_user_id') IS NOT NULL AND (metadata->>'affiliate_user_id') <> ''");
            }
        });
    }

    private function applySaleOriginFilter($query, Request $request)
    {
        $origin = $this->normalizeString($request->query('sale_origin'));
        if ($origin === null) {
            return $query;
        }

        return $query->where(function ($q) use ($origin) {
            $q->where('sale_origin', $origin)
                ->orWhere('metadata->sale_origin', $origin);
        });
    }

    private function buildFilteredQuery(Request $request, ?int $tenantId)
    {
        $statusFilter = $this->normalizeStatusFilter($request);
        $query = Order::forTenant($tenantId);

        if (auth()->user()?->isTeam()) {
            $allowed = app(TeamAccessService::class)->allowedProductIdsFor(auth()->user());
            $query->whereIn('product_id', $allowed ?: ['__none__']);
        }

        $query = $this->applyStatusFilter($query, $statusFilter);
        $query = $this->applyPeriodFilter($query, $request);
        $query = $this->applySearchFilter($query, $request);
        $query = $this->applyProductFilters($query, $request);
        $query = $this->applyPaymentFilters($query, $request);
        $query = $this->applySaleChannelFilter($query, $request);
        $query = $this->applyAffiliateFilter($query, $request);
        $query = $this->applySaleOriginFilter($query, $request);
        $query = $this->applyUtmFilters($query, $request, $tenantId);

        return [$query, $statusFilter];
    }

    /**
     * @return array<string, mixed>
     */
    private function orderToVendaArray(Order $o): array
    {
        $arr = $o->toArray();
        $arr['gateway_label'] = $o->paymentMethodDisplayLabel();
        $arr['product_display_name'] = $this->productDisplayName($o);
        $arr['checkout_url'] = url('/c/'.$o->getCheckoutSlug());
        $arr['payment_type_label'] = $this->paymentTypeLabel($o);
        $breakdown = OrderFeeBreakdownService::forOrder($o);
        $arr['amount_total'] = $breakdown['gross'];
        $arr['amount_gross'] = $breakdown['gross'];
        $arr['amount_fee'] = $breakdown['fee'];
        $arr['amount_net'] = $breakdown['net'];
        $policy = app(MedPolicyService::class);
        $arr['is_api_pix'] = $policy->isApiPixRestOrder($o);
        $arr['is_pixgo'] = $o->isPixGoSale();
        $arr['sale_channel_label'] = $o->isPixGoSale()
            ? PixGoAccess::sidebarLabel()
            : ($policy->isApiPixRestOrder($o) ? 'API PIX' : null);
        $arr['can_manual_refund'] = OrderManualRefund::canManualRefund($o);
        $arr['manual_refund'] = OrderManualRefund::snapshot($o);
        $arr['is_affiliate_sale'] = $o->isAffiliateSale();
        $arr['sale_origin'] = $o->sale_origin ?? (is_array($o->metadata) ? ($o->metadata['sale_origin'] ?? null) : null);
        $arr['sale_origin_label'] = SaleOrigin::label($arr['sale_origin']);

        $commission = $o->relationLoaded('affiliateCommission')
            ? $o->affiliateCommission
            : $o->affiliateCommission()->first();

        if ($commission) {
            $arr['affiliate_name'] = $commission->metadata['affiliate_name'] ?? $commission->affiliate?->name;
            $arr['affiliate_commission_gross'] = (float) $commission->commission_gross;
            $arr['affiliate_commission_percent'] = (float) $commission->commission_percent;
            $arr['affiliate_commission_net'] = (float) $commission->commission_net;
        } elseif ($o->isAffiliateSale()) {
            $affiliateUserId = (int) ($o->affiliate_user_id ?? ($o->metadata['affiliate_user_id'] ?? 0));
            $affiliate = $affiliateUserId > 0 ? User::query()->find($affiliateUserId) : null;
            $arr['affiliate_name'] = $affiliate?->name;
            $product = $o->relationLoaded('product') ? $o->product : null;
            $pct = $product ? (float) $product->affiliate_commission_percent : 0;
            $gross = (float) $breakdown['gross'];
            $arr['affiliate_commission_percent'] = $pct;
            $arr['affiliate_commission_gross'] = round($gross * $pct / 100, 2);
            $arr['affiliate_commission_net'] = null;
        } else {
            $arr['affiliate_name'] = null;
            $arr['affiliate_commission_gross'] = null;
            $arr['affiliate_commission_percent'] = null;
            $arr['affiliate_commission_net'] = null;
        }

        return $arr;
    }

    /**
     * @return array{vendas_encontradas: int, valor_liquido: float, vendas_pix: int, vendas_cartao: int, vendas_boleto: int}
     */
    private function resolveVendasStats(Request $request, int $tenantId, string $statusFilter): array
    {
        $cacheKey = 'vendas.stats.'.$tenantId.'.'.md5(json_encode([
            'status_filter' => $statusFilter,
            'q' => $this->normalizeString($request->query('q')),
            'period' => $this->normalizeString($request->query('period')) ?? 'all',
            'date_from' => $this->normalizeString($request->query('date_from')),
            'date_to' => $this->normalizeString($request->query('date_to')),
            'product_ids' => $this->normalizeProductIds($request),
            'offer_id' => $this->normalizeString((string) ($request->query('offer_id') ?? '')),
            'payment_method' => $this->normalizeString($request->query('payment_method')) ?? 'all',
            'payment_status' => $this->normalizeString($request->query('payment_status')) ?? 'all',
            'utm_source' => $this->normalizeString($request->query('utm_source')),
            'utm_medium' => $this->normalizeString($request->query('utm_medium')),
            'utm_campaign' => $this->normalizeString($request->query('utm_campaign')),
            'sale_channel' => $this->normalizeString($request->query('sale_channel')),
            'team' => auth()->user()?->isTeam() ? app(TeamAccessService::class)->allowedProductIdsFor(auth()->user()) : null,
        ]));

        return Cache::remember($cacheKey, 60, function () use ($request, $tenantId) {
            return $this->computeVendasStats($request, $tenantId);
        });
    }

    /**
     * @return array{vendas_encontradas: int, valor_liquido: float, vendas_pix: int, vendas_cartao: int, vendas_boleto: int}
     */
    private function computeVendasStats(Request $request, int $tenantId): array
    {
        [$statsQuery] = $this->buildFilteredQuery($request, $tenantId);

        $vendasEncontradas = (clone $statsQuery)->count();

        $valorLiquido = 0.0;
        (clone $statsQuery)
            ->where('status', 'completed')
            ->select(['id', 'tenant_id', 'amount', 'payment_method', 'gateway', 'metadata'])
            ->with(['orderItems:id,order_id,amount'])
            ->chunkById(200, function ($orders) use (&$valorLiquido) {
                foreach ($orders as $order) {
                    $valorLiquido += OrderFeeBreakdownService::forOrder($order)['net'];
                }
            });

        $vendasPix = (clone $statsQuery)
            ->where(function ($q) {
                $q->whereIn('gateway', ['spacepag'])
                    ->orWhereRaw("LOWER(gateway) LIKE '%pix%'")
                    ->orWhere(function ($q2) {
                        $q2->where('metadata->checkout_payment_method', 'pix')
                            ->orWhere('metadata->checkout_payment_method', 'pix_auto');
                    });
            })
            ->count();

        $vendasCartao = (clone $statsQuery)
            ->where(function ($q) {
                $q->where('gateway', 'card')
                    ->orWhereRaw("LOWER(gateway) LIKE '%card%'")
                    ->orWhereRaw("LOWER(gateway) LIKE '%cartao%'")
                    ->orWhereRaw("LOWER(gateway) LIKE '%cartão%'")
                    ->orWhereRaw("LOWER(gateway) LIKE '%credito%'")
                    ->orWhere('metadata->checkout_payment_method', 'card');
            })
            ->count();

        $vendasBoleto = (clone $statsQuery)
            ->where(function ($q) {
                $q->where('gateway', 'boleto')
                    ->orWhereRaw("LOWER(gateway) LIKE '%boleto%'")
                    ->orWhere('metadata->checkout_payment_method', 'boleto');
            })
            ->count();

        return [
            'vendas_encontradas' => $vendasEncontradas,
            'valor_liquido' => round($valorLiquido, 2),
            'vendas_pix' => $vendasPix,
            'vendas_cartao' => $vendasCartao,
            'vendas_boleto' => $vendasBoleto,
        ];
    }

    public function index(Request $request): InertiaResponse
    {
        $tenantId = auth()->user()->tenant_id;
        [$filteredQuery, $statusFilter] = $this->buildFilteredQuery($request, $tenantId);

        $vendas = $filteredQuery
            ->with([
                'product:id,name,slug,checkout_slug',
                'user:id,name,email',
                'productOffer:id,name,checkout_slug',
                'subscriptionPlan:id,name,checkout_slug',
                'orderItems:id,order_id,product_id,product_offer_id,subscription_plan_id,amount,position',
                'orderItems.product:id,name',
                'orderItems.productOffer:id,name',
                'orderItems.subscriptionPlan:id,name',
                'checkoutSession:'.CheckoutSession::eagerSelectForOrderRelation(),
                'affiliateCommission.affiliate:id,name,email',
            ])
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Order $o) => $this->orderToVendaArray($o));

        $stats = $this->resolveVendasStats($request, (int) $tenantId, $statusFilter);

        $productsQuery = Product::forTenant($tenantId)->orderBy('name');
        if (auth()->user()->isTeam()) {
            $allowed = app(TeamAccessService::class)->allowedProductIdsFor(auth()->user());
            $productsQuery->whereIn('id', $allowed ?: ['__none__']);
        }
        $products = $productsQuery->get(['id', 'name']);
        $offers = ProductOffer::query()
            ->whereHas('product', fn ($q) => $q->forTenant($tenantId))
            ->with('product:id,name')
            ->orderBy('product_id')
            ->orderBy('position')
            ->get()
            ->map(fn (ProductOffer $o) => [
                'id' => $o->id,
                'name' => $o->name,
                'product_id' => $o->product_id,
                'product_name' => $o->product?->name,
            ])
            ->values()
            ->all();

        if (auth()->user()->isTeam()) {
            $allowed = app(TeamAccessService::class)->allowedProductIdsFor(auth()->user());
            $offers = array_values(array_filter($offers, fn ($o) => in_array($o['product_id'], $allowed, true)));
        }

        return Inertia::render('Vendas/Index', [
            'vendas' => $vendas,
            'stats' => $stats,
            'status_filter' => $statusFilter,
            'filters' => [
                'q' => $this->normalizeString($request->query('q')),
                'period' => $this->normalizeString($request->query('period')) ?? 'all',
                'date_from' => $this->normalizeString($request->query('date_from')),
                'date_to' => $this->normalizeString($request->query('date_to')),
                'product_ids' => $this->normalizeProductIds($request),
                'offer_id' => $this->normalizeString((string) ($request->query('offer_id') ?? '')),
                'payment_method' => $this->normalizeString($request->query('payment_method')) ?? 'all',
                'payment_status' => $this->normalizeString($request->query('payment_status')) ?? 'all',
                'utm_source' => $this->normalizeString($request->query('utm_source')),
                'utm_medium' => $this->normalizeString($request->query('utm_medium')),
                'utm_campaign' => $this->normalizeString($request->query('utm_campaign')),
                'sale_channel' => $this->normalizeString($request->query('sale_channel')),
                'affiliate' => $this->normalizeString($request->query('affiliate')),
                'sale_origin' => $this->normalizeString($request->query('sale_origin')),
            ],
            'sale_origin_options' => collect(SaleOrigin::labels())->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values()->all(),
            'products' => $products,
            'offers' => $offers,
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $format = $request->query('format', 'csv');
        if (! in_array($format, ['csv', 'xls'], true)) {
            $format = 'csv';
        }

        $tenantId = auth()->user()->tenant_id;
        [$filteredQuery] = $this->buildFilteredQuery($request, $tenantId);

        $vendas = $filteredQuery
            ->with([
                'product:id,name',
                'user:id,name,email',
                'orderItems:id,order_id,amount',
            ])
            ->orderByDesc('created_at')
            ->get();

        $rows = $vendas->map(function (Order $o) {
            $net = OrderFeeBreakdownService::forOrder($o)['net'];

            return [
                'data' => $o->created_at?->format('d/m/Y H:i'),
                'produto' => $this->productDisplayName($o),
                'cliente' => $o->user?->name ?? $o->email ?? '–',
                'email' => $o->email ?? '–',
                'status' => $this->statusLabel($o->status),
                'gateway' => $o->paymentMethodDisplayLabel(),
                'valor_liquido' => number_format($net, 2, ',', '.'),
            ];
        })->all();

        $headers = ['Data', 'Produto', 'Cliente', 'E-mail', 'Status', 'Método', 'Valor líquido'];

        if ($format === 'csv') {
            $filename = 'vendas_'.date('Y-m-d_His').'.csv';

            return response()->streamDownload(function () use ($headers, $rows) {
                $out = fopen('php://output', 'w');
                fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM UTF-8
                fputcsv($out, $headers, ';');
                foreach ($rows as $r) {
                    fputcsv($out, array_values($r), ';');
                }
                fclose($out);
            }, $filename, [
                'Content-Type' => 'text/csv; charset=UTF-8',
            ]);
        }

        $filename = 'vendas_'.date('Y-m-d_His').'.xls';

        return response()->streamDownload(function () use ($headers, $rows) {
            $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
            $xml .= '<?mso-application progid="Excel.Sheet"?>'."\n";
            $xml .= '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">'."\n";
            $xml .= '<Worksheet ss:Name="Vendas">'."\n";
            $xml .= '<Table>'."\n";

            foreach (array_merge([$headers], array_map(fn ($r) => array_values($r), $rows)) as $row) {
                $xml .= '<Row>';
                foreach ($row as $cell) {
                    $cell = htmlspecialchars((string) $cell, ENT_XML1, 'UTF-8');
                    $xml .= '<Cell><Data ss:Type="String">'.$cell.'</Data></Cell>';
                }
                $xml .= '</Row>'."\n";
            }

            $xml .= '</Table></Worksheet></Workbook>';

            echo $xml;
        }, $filename, [
            'Content-Type' => 'application/vnd.ms-excel; charset=UTF-8',
        ]);
    }

    private function statusLabel(?string $status): string
    {
        $map = [
            'completed' => 'Pago',
            'pending' => 'Pendente',
            'disputed' => 'MED',
            'cancelled' => 'Cancelado',
            'refunded' => 'Reembolsado',
        ];

        return $map[$status ?? ''] ?? ($status ?? '–');
    }

    public function resendAccessEmail(Order $order, AccessEmailService $accessEmailService): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        if ($order->tenant_id !== $tenantId) {
            return response()->json(['success' => false, 'message' => 'Pedido não encontrado.'], 404);
        }

        $result = $accessEmailService->sendForOrder($order, true);
        if ($result->success) {
            return response()->json(['success' => true]);
        }

        return response()->json([
            'success' => false,
            'message' => $result->message,
        ], 422);
    }

    public function refundManually(Request $request, Order $order, ManualOrderRefundService $refundService): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        if ((int) $order->tenant_id !== (int) $tenantId) {
            return response()->json(['success' => false, 'message' => 'Pedido não encontrado.'], 404);
        }

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'min:3', 'max:500'],
        ]);

        try {
            $result = $refundService->refund(
                $order,
                $request->user(),
                'seller',
                $validated['reason'] ?? null
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Não foi possível reembolsar: '.$e->getMessage(),
            ], 500);
        }

        if (! $result['success']) {
            return response()->json([
                'success' => false,
                'message' => $result['message'],
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => $result['message'],
            'gateway_status' => $result['gateway_status'],
        ]);
    }

    public function approveManually(Order $order): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'A aprovação manual de pedidos pendentes é feita apenas pelo operador da plataforma (área Financeiro → Pedidos pendentes).',
        ], 403);
    }

    private function productDisplayName(Order $order): string
    {
        if ($order->isPixGoSale()) {
            return 'Venda '.PixGoAccess::sidebarLabel();
        }

        $product = $order->product;
        if (! $product) {
            return '—';
        }
        $name = $product->name;
        if ($order->productOffer) {
            $name .= ' - '.$order->productOffer->name;
        } elseif ($order->subscriptionPlan) {
            $name .= ' - '.$order->subscriptionPlan->name;
        }

        return $name;
    }

    private function paymentTypeLabel(Order $order): string
    {
        if ($order->subscription_plan_id || $order->is_renewal) {
            return 'Pagamento recorrente';
        }

        return 'Pagamento único';
    }
}
