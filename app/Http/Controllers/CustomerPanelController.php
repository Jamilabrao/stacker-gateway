<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Product;
use App\Services\MemberAreaResolver;
use App\Services\RefundRequestService;
use App\Services\StorageService;
use App\Support\RefundEligibility;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class CustomerPanelController extends Controller
{
    public function __construct(
        protected RefundRequestService $refundRequestService
    ) {}

    public function index(Request $request, MemberAreaResolver $resolver): Response
    {
        $user = $request->user();
        $orders = Order::query()
            ->where('user_id', $user->id)
            ->where('status', 'completed')
            ->with(['product', 'orderItems.product'])
            ->orderByDesc('id')
            ->get();

        $items = [];
        foreach ($orders as $order) {
            if ($order->orderItems->isNotEmpty()) {
                foreach ($order->orderItems as $line) {
                    $items[] = $this->purchaseRowFromOrder($order, $line->product, (float) $line->amount, (int) $line->position, $resolver);
                }
            } else {
                $items[] = $this->purchaseRowFromOrder($order, $order->product, (float) $order->amount, 0, $resolver);
            }
        }

        return Inertia::render('Cliente/Index', [
            'purchases' => $items,
            'pageTitle' => 'Minhas compras',
        ]);
    }

    public function requestRefund(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'order_id' => ['required', 'integer', 'exists:orders,id'],
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
        ]);

        $user = $request->user();
        $order = Order::query()->where('id', $validated['order_id'])->where('user_id', $user->id)->firstOrFail();

        if (! RefundEligibility::canCustomerRequestRefund($order)) {
            return back()->with('error', 'Este pedido não está elegível para reembolso.');
        }

        try {
            DB::transaction(function () use ($order, $user, $validated) {
                $rr = $this->refundRequestService->createFromCustomer($order, $user, $validated['reason']);
                $this->refundRequestService->notifySeller($rr);
            });
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', 'Não foi possível registrar a solicitação. Tente novamente.');
        }

        return back()->with('success', 'Solicitação de reembolso enviada ao vendedor.');
    }

    /**
     * Uma linha em "Minhas compras" (produto principal ou order bump do mesmo pedido).
     *
     * @return array<string, mixed>
     */
    private function purchaseRowFromOrder(
        Order $order,
        ?Product $product,
        float $lineAmount,
        int $position,
        MemberAreaResolver $resolver
    ): array {
        $accessUrl = null;
        if ($product) {
            if ($product->type === Product::TYPE_AREA_MEMBROS && $product->checkout_slug) {
                $accessUrl = $resolver->baseUrlForProduct($product);
            } elseif ($product->type === Product::TYPE_LINK) {
                $accessUrl = $product->checkout_config['deliverable_link'] ?? null;
            }
        }

        $imageUrl = null;
        if ($product?->image) {
            $imageUrl = (new StorageService($product->tenant_id))->url($product->image);
        }

        $productId = $product?->id ?? $order->product_id;

        return [
            'purchase_key' => $order->id.'-'.($productId ?? 'main').'-'.$position,
            'order_id' => $order->id,
            'public_reference' => $order->public_reference,
            'amount' => $lineAmount,
            'product_id' => $productId,
            'product_name' => $product?->name ?? 'Produto',
            'product_type' => $product?->type,
            'product_image_url' => $imageUrl,
            'access_url' => $accessUrl,
            'is_order_bump' => $position > 0,
            'can_request_refund' => $position === 0 && RefundEligibility::canCustomerRequestRefund($order),
        ];
    }
}
