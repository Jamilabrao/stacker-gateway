<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ApiWebhookDelivery;
use App\Models\Order;
use App\Services\Api\ApiAuthContext;
use App\Services\ApiPixAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentStatusController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $ctx = ApiAuthContext::fromRequest($request);
        if (! $ctx->hasScope(\App\Support\ApiScopes::PAYMENTS_READ)) {
            return response()->json(['message' => 'Insufficient API key permissions.'], 403);
        }
        if (! ApiPixAccess::effectiveForTenant($ctx->application->tenant_id)) {
            return response()->json(['message' => 'API PIX disabled for this tenant.'], 403);
        }

        $perPage = min(100, max(1, (int) $request->query('per_page', 25)));
        $query = Order::query()
            ->where('api_application_id', $ctx->application->id)
            ->orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', (string) $request->query('status'));
        }

        $paginator = $query->paginate($perPage);

        return response()->json([
            'data' => collect($paginator->items())->map(fn (Order $o) => $this->serializeOrder($o)),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function show(Request $request, string $order): JsonResponse
    {
        $ctx = ApiAuthContext::fromRequest($request);
        if (! $ctx->hasScope(\App\Support\ApiScopes::PAYMENTS_READ)) {
            return response()->json(['message' => 'Insufficient API key permissions.'], 403);
        }
        if (! ApiPixAccess::effectiveForTenant($ctx->application->tenant_id)) {
            return response()->json(['message' => 'API PIX disabled for this tenant.'], 403);
        }

        $orderModel = Order::where('id', $order)
            ->where('api_application_id', $ctx->application->id)
            ->first();

        if (! $orderModel) {
            return response()->json(['message' => 'Pedido não encontrado.'], 404);
        }

        return response()->json($this->serializeOrder($orderModel));
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeOrder(Order $orderModel): array
    {
        $data = [
            'order_id' => $orderModel->id,
            'status' => $orderModel->status,
            'amount' => (float) $orderModel->amount,
            'email' => $orderModel->email,
            'gateway' => $orderModel->gateway,
            'gateway_id' => $orderModel->gateway_id,
            'metadata' => $orderModel->metadata ?? [],
            'created_at' => $orderModel->created_at?->toIso8601String(),
            'updated_at' => $orderModel->updated_at?->toIso8601String(),
        ];

        if ($orderModel->payment_method === 'pix' && $orderModel->gateway_id) {
            $meta = $orderModel->metadata ?? [];
            $data['copy_paste'] = $meta['pix_copy_paste'] ?? $meta['copy_paste'] ?? null;
            $data['qrcode'] = $meta['pix_qrcode'] ?? $meta['qrcode'] ?? null;
            $data['transaction_id'] = $orderModel->gateway_id;
        }

        return $data;
    }
}
