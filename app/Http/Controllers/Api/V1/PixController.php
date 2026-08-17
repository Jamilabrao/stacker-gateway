<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ApiApplication;
use App\Models\Order;
use App\Models\User;
use App\Services\ApiPixAccess;
use App\Services\MerchantOperationalGuard;
use App\Jobs\PollCajuPayPixRefundJob;
use App\Services\OrderRefundGatewayBridge;
use App\Services\PlatformOrderAdminService;
use App\Services\SellerActivityLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PixController extends Controller
{
    private function application(Request $request): ApiApplication
    {
        $app = $request->attributes->get('api_application');
        if (! $app instanceof ApiApplication) {
            abort(500, 'API application not resolved');
        }
        if (! ApiPixAccess::effectiveForTenant($app->tenant_id)) {
            abort(403, 'API PIX disabled for this tenant.');
        }
        MerchantOperationalGuard::assertCanAcceptPayments((int) $app->tenant_id);

        return $app;
    }

    private function resolveOrderForApp(ApiApplication $app, string $orderId): Order
    {
        $order = Order::query()
            ->where('id', $orderId)
            ->where('api_application_id', $app->id)
            ->first();

        if (! $order) {
            abort(404, 'Pedido não encontrado.');
        }

        return $order;
    }

    public function cancel(Request $request, string $order): JsonResponse
    {
        $app = $this->application($request);
        $orderModel = $this->resolveOrderForApp($app, $order);

        if ($orderModel->status === 'cancelled') {
            return response()->json([
                'order_id' => $orderModel->id,
                'status' => $orderModel->status,
            ]);
        }

        if ($orderModel->status !== 'pending') {
            return response()->json([
                'message' => 'Só é possível cancelar pedidos pendentes.',
                'order_id' => $orderModel->id,
                'status' => $orderModel->status,
            ], 422);
        }

        PlatformOrderAdminService::cancelPending($orderModel);
        $orderModel = $orderModel->fresh();
        $this->logApiPixCancelled($app, $orderModel);

        return response()->json([
            'order_id' => $orderModel->id,
            'status' => $orderModel->status,
        ]);
    }

    public function refund(Request $request, string $order): JsonResponse
    {
        $app = $this->application($request);
        $orderModel = $this->resolveOrderForApp($app, $order);

        if ($orderModel->status === 'refunded') {
            return response()->json([
                'order_id' => $orderModel->id,
                'status' => $orderModel->status,
            ]);
        }

        if (! in_array($orderModel->status, ['completed', 'disputed'], true)) {
            return response()->json([
                'message' => 'Só é possível reembolsar pedidos pagos ou em MED.',
                'order_id' => $orderModel->id,
                'status' => $orderModel->status,
            ], 422);
        }

        $bridge = app(OrderRefundGatewayBridge::class);
        $bridgeResult = $bridge->tryRefund($orderModel);

        if ($bridgeResult['status'] === 'blocked_med') {
            return response()->json([
                'message' => $bridgeResult['note'] ?? 'Reembolso bloqueado por disputa MED.',
                'order_id' => $orderModel->id,
                'status' => $orderModel->status,
                'gateway_refund' => $bridgeResult,
            ], 422);
        }

        if ($bridgeResult['status'] === 'failed') {
            return response()->json([
                'message' => $bridgeResult['note'] ?? 'Falha no reembolso no gateway.',
                'order_id' => $orderModel->id,
                'status' => $orderModel->status,
                'gateway_refund' => $bridgeResult,
            ], 422);
        }

        if ($bridgeResult['status'] === 'gateway_pending') {
            PollCajuPayPixRefundJob::dispatch($orderModel->id)->delay(now()->addSeconds(5));
            $this->logApiRefund($app, $orderModel, $bridgeResult);

            return response()->json([
                'order_id' => $orderModel->id,
                'status' => $orderModel->status,
                'gateway_refund' => $bridgeResult,
                'message' => $bridgeResult['note'] ?? 'Reembolso PIX em processamento.',
            ]);
        }

        PlatformOrderAdminService::refundPaidOrDisputed($orderModel);
        $orderModel = $orderModel->fresh();
        $this->logApiRefund($app, $orderModel, $bridgeResult);

        return response()->json([
            'order_id' => $orderModel->id,
            'status' => $orderModel->status,
            'gateway_refund' => $bridgeResult,
        ]);
    }

    /**
     * @param  array<string, mixed>  $bridgeResult
     */
    private function logApiRefund(ApiApplication $app, Order $order, array $bridgeResult): void
    {
        $owner = User::query()
            ->where('role', User::ROLE_INFOPRODUTOR)
            ->where(function ($q) use ($app) {
                $q->where('id', $app->tenant_id)->orWhere('tenant_id', $app->tenant_id);
            })
            ->first();

        SellerActivityLogService::record(
            actor: $owner,
            action: SellerActivityLogService::REFUND_COMPLETED,
            targetType: Order::class,
            targetId: $order->id,
            metadata: array_filter([
                'order_id' => $order->id,
                'amount' => (float) ($order->amount ?? 0),
                'gateway_status' => $bridgeResult['status'] ?? null,
            ], fn ($v) => $v !== null && $v !== ''),
            tenantId: (int) $app->tenant_id,
            source: 'api',
        );
    }

    private function logApiPixCancelled(ApiApplication $app, Order $order): void
    {
        $owner = User::query()
            ->where('role', User::ROLE_INFOPRODUTOR)
            ->where(function ($q) use ($app) {
                $q->where('id', $app->tenant_id)->orWhere('tenant_id', $app->tenant_id);
            })
            ->first();

        SellerActivityLogService::record(
            actor: $owner,
            action: SellerActivityLogService::API_PIX_CANCELLED,
            targetType: Order::class,
            targetId: $order->id,
            metadata: array_filter([
                'order_id' => $order->id,
                'amount' => (float) ($order->amount ?? 0),
            ], fn ($v) => $v !== null && $v !== ''),
            tenantId: (int) $app->tenant_id,
            source: 'api',
        );
    }
}

