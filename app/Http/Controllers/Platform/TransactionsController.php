<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\CheckoutSession;
use App\Models\MedDispute;
use App\Models\Order;
use App\Services\EffectiveMerchantFees;
use App\Services\OrderManualApprovalService;
use App\Services\PlatformAdminDeletionService;
use App\Services\PlatformAuditService;
use App\Jobs\PollCajuPayPixRefundJob;
use App\Services\OrderRefundGatewayBridge;
use App\Services\PlatformOrderAdminService;
use App\Support\DemoMode;
use App\Support\Demo\DemoPlatformData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class TransactionsController extends Controller
{
    private const STATUS_OPTIONS = ['all', 'pending', 'completed', 'disputed', 'cancelled', 'refunded'];

    private function paymentMethodForFees(Order $order): string
    {
        return EffectiveMerchantFees::feeMethodForOrder($order);
    }

    private function orderSourceForFees(Order $order): ?string
    {
        $meta = $order->metadata ?? [];
        if (! is_array($meta)) {
            return null;
        }
        $s = $meta['source'] ?? null;

        return is_string($s) && $s !== '' ? $s : null;
    }

    /**
     * @return array{gross: float, fee: float, net: float}
     */
    private function orderFeeBreakdown(Order $order): array
    {
        $gross = (float) $order->lineItemsTotalAmount();
        $tenantId = (int) $order->tenant_id;
        if ($tenantId < 1) {
            return ['gross' => $gross, 'fee' => 0.0, 'net' => $gross];
        }
        $calc = EffectiveMerchantFees::calculateSaleFee(
            $tenantId,
            $this->paymentMethodForFees($order),
            $gross,
            $this->orderSourceForFees($order)
        );

        return [
            'gross' => $gross,
            'fee' => $calc['fee'],
            'net' => $calc['net'],
        ];
    }

    private function productDisplayName(Order $order): string
    {
        return $this->orderProductLabel($order);
    }

    private function paymentTypeLabel(Order $order): string
    {
        if ($order->subscription_plan_id || $order->is_renewal) {
            return 'Pagamento recorrente';
        }

        return 'Pagamento único';
    }

    public function index(Request $request): Response
    {
        $status = $request->query('status', 'all');
        if (! in_array($status, self::STATUS_OPTIONS, true)) {
            $status = 'all';
        }
        $q = trim((string) $request->query('q', ''));

        if (DemoMode::isEnabled()) {
            $payload = DemoPlatformData::transactions(
                $status,
                $q,
                $request->url(),
                $request->query()
            );

            return Inertia::render('Platform/Transactions/Index', $payload);
        }

        $ordersPaginator = new LengthAwarePaginator([], 0, 40, 1, [
            'path' => $request->url(),
            'query' => $request->query(),
        ]);

        if (Schema::hasTable('orders')) {
            $query = Order::query()
                ->with([
                    'user:id,name,email',
                    'tenantOwner:id,name,email',
                    'product:id,name,slug,checkout_slug',
                    'productOffer:id,name,checkout_slug',
                    'subscriptionPlan:id,name,checkout_slug',
                    'checkoutSession:'.CheckoutSession::eagerSelectForOrderRelation(),
                    'orderItems:id,order_id,product_id,product_offer_id,subscription_plan_id,amount,position',
                    'orderItems.product:id,name',
                    'orderItems.productOffer:id,name',
                    'orderItems.subscriptionPlan:id,name',
                ])
                ->orderByDesc('created_at');

            if ($status !== 'all') {
                $query->where('orders.status', $status);
            }

            if ($q !== '') {
                $query->where(function ($w) use ($q) {
                    $w->where('orders.email', 'like', '%'.$q.'%')
                        ->orWhereHas('user', function ($u) use ($q) {
                            $u->where('name', 'like', '%'.$q.'%')
                                ->orWhere('email', 'like', '%'.$q.'%');
                        });
                    if (ctype_digit($q)) {
                        $w->orWhere('orders.id', $q);
                    }
                });
            }

            $paginated = $query->paginate(40)->withQueryString();
            $openMedOrderIds = MedDispute::query()
                ->whereIn('order_id', $paginated->getCollection()->pluck('id'))
                ->open()
                ->pluck('order_id')
                ->flip()
                ->all();

            $ordersPaginator = $paginated->through(function (Order $o) use ($openMedOrderIds) {
                $arr = $o->toArray();
                $breakdown = $this->orderFeeBreakdown($o);
                $arr['gateway_label'] = $o->paymentMethodDisplayLabel();
                $arr['product_display_name'] = $this->productDisplayName($o);
                $arr['checkout_url'] = url('/c/'.$o->getCheckoutSlug());
                $arr['payment_type_label'] = $this->paymentTypeLabel($o);
                $arr['amount_total'] = $breakdown['gross'];
                $arr['amount_gross'] = $breakdown['gross'];
                $arr['amount_fee'] = $breakdown['fee'];
                $arr['amount_net'] = $breakdown['net'];
                $arr['product_label'] = $this->orderProductLabel($o);
                $arr['customer_name'] = $o->user?->name ?? '—';
                $arr['customer_email'] = $o->user?->email ?? $o->email ?? '—';
                $arr['infoprodutor_name'] = $o->tenantOwner?->name ?? '—';
                $arr['infoprodutor_email'] = $o->tenantOwner?->email;
                $arr['payment_method_label'] = $o->paymentMethodDisplayLabel();
                $arr['has_open_med_dispute'] = isset($openMedOrderIds[$o->id]);

                return $arr;
            });
        }

        return Inertia::render('Platform/Transactions/Index', [
            'orders' => $ordersPaginator,
            'filters' => [
                'status' => $status,
                'q' => $q,
            ],
        ]);
    }

    private function orderActionRedirectParams(Request $request): array
    {
        return array_filter([
            'status' => $request->query('status'),
            'q' => $request->query('q'),
        ], fn ($v) => $v !== null && $v !== '');
    }

    public function approveManualOrder(Request $request, Order $order): RedirectResponse
    {
        $redirectParams = $this->orderActionRedirectParams($request);

        try {
            OrderManualApprovalService::approve($order);
        } catch (InvalidArgumentException $e) {
            return redirect()->route('plataforma.transacoes.index', $redirectParams)
                ->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            return redirect()->route('plataforma.transacoes.index', $redirectParams)
                ->with('error', 'Não foi possível concluir a aprovação: '.$e->getMessage());
        }

        PlatformAuditService::log('platform.order.approved_manually', [
            'order_id' => $order->id,
            'tenant_id' => $order->tenant_id,
        ], $request);

        return redirect()->route('plataforma.transacoes.index', $redirectParams)
            ->with('success', 'Pedido #'.$order->id.' aprovado. O cliente recebeu acesso conforme o produto.');
    }

    public function cancelOrder(Request $request, Order $order): RedirectResponse
    {
        $redirectParams = $this->orderActionRedirectParams($request);

        try {
            PlatformOrderAdminService::cancelPending($order);
        } catch (InvalidArgumentException $e) {
            return redirect()->route('plataforma.transacoes.index', $redirectParams)->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            return redirect()->route('plataforma.transacoes.index', $redirectParams)
                ->with('error', 'Não foi possível cancelar: '.$e->getMessage());
        }

        PlatformAuditService::log('platform.order.cancelled', [
            'order_id' => $order->id,
            'tenant_id' => $order->tenant_id,
        ], $request);

        return redirect()->route('plataforma.transacoes.index', $redirectParams)
            ->with('success', 'Pedido #'.$order->id.' cancelado.');
    }

    public function refundOrder(Request $request, Order $order): RedirectResponse
    {
        $redirectParams = $this->orderActionRedirectParams($request);

        $gw = app(OrderRefundGatewayBridge::class)->tryRefund($order);
        if ($gw['status'] === 'blocked_med') {
            return redirect()->route('plataforma.transacoes.index', $redirectParams)
                ->with('error', $gw['note'] ?? 'Reembolso bloqueado por disputa MED aberta.');
        }
        if ($gw['status'] === 'failed') {
            return redirect()->route('plataforma.transacoes.index', $redirectParams)
                ->with('error', $gw['note'] ?? 'Falha ao solicitar reembolso no gateway.');
        }

        if ($gw['status'] === 'gateway_pending') {
            PollCajuPayPixRefundJob::dispatch($order->id)->delay(now()->addSeconds(5));
            PlatformAuditService::log('platform.order.refund_pending', [
                'order_id' => $order->id,
                'tenant_id' => $order->tenant_id,
                'gateway_refund' => $gw,
            ], $request);

            return redirect()->route('plataforma.transacoes.index', $redirectParams)
                ->with('success', $gw['note'] ?? 'Reembolso PIX enviado à CajuPay. A carteira será ajustada quando a devolução for confirmada.');
        }

        try {
            PlatformOrderAdminService::refundPaidOrDisputed($order);
        } catch (InvalidArgumentException $e) {
            return redirect()->route('plataforma.transacoes.index', $redirectParams)->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            return redirect()->route('plataforma.transacoes.index', $redirectParams)
                ->with('error', 'Não foi possível reembolsar: '.$e->getMessage());
        }

        PlatformAuditService::log('platform.order.refunded', [
            'order_id' => $order->id,
            'tenant_id' => $order->tenant_id,
            'gateway_refund' => $gw,
        ], $request);

        return redirect()->route('plataforma.transacoes.index', $redirectParams)
            ->with('success', 'Pedido #'.$order->id.' reembolsado.');
    }

    public function markDisputedOrder(Request $request, Order $order): RedirectResponse
    {
        $redirectParams = $this->orderActionRedirectParams($request);

        try {
            PlatformOrderAdminService::markDisputed($order);
        } catch (InvalidArgumentException $e) {
            return redirect()->route('plataforma.transacoes.index', $redirectParams)->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            return redirect()->route('plataforma.transacoes.index', $redirectParams)
                ->with('error', 'Não foi possível atualizar: '.$e->getMessage());
        }

        PlatformAuditService::log('platform.order.disputed', [
            'order_id' => $order->id,
            'tenant_id' => $order->tenant_id,
        ], $request);

        return redirect()->route('plataforma.transacoes.index', $redirectParams)
            ->with('success', 'Pedido #'.$order->id.' marcado como MED.');
    }

    public function destroyOrder(Request $request, Order $order): RedirectResponse
    {
        $redirectParams = $this->orderActionRedirectParams($request);
        $orderId = $order->id;

        try {
            PlatformAdminDeletionService::deleteOrder($order);
        } catch (InvalidArgumentException $e) {
            return redirect()->route('plataforma.transacoes.index', $redirectParams)->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            return redirect()->route('plataforma.transacoes.index', $redirectParams)
                ->with('error', 'Não foi possível excluir o pedido: '.$e->getMessage());
        }

        PlatformAuditService::log('platform.order.deleted', [
            'order_id' => $orderId,
        ], $request);

        return redirect()->route('plataforma.transacoes.index', $redirectParams)
            ->with('success', 'Pedido #'.$orderId.' removido do histórico.');
    }

    private function orderProductLabel(Order $order): string
    {
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
}
