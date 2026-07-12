<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\PaymentOperationsHealthService;
use App\Services\PlatformAuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PaymentHealthController extends Controller
{
    public function __construct(
        protected PaymentOperationsHealthService $healthService,
    ) {}

    public function index(Request $request): Response
    {
        $dashboard = $this->healthService->buildDashboard(30);

        return Inertia::render('Platform/Ops/PaymentHealth', [
            'dashboard' => $dashboard,
        ]);
    }

    public function probeOrder(Request $request, Order $order): JsonResponse
    {
        if (! in_array($order->gateway, ['mercadopago', 'cajupay'], true)) {
            return response()->json([
                'message' => 'Pedido não pertence a um gateway monitorado.',
            ], 422);
        }

        return response()->json([
            'probe' => $this->healthService->probeOrder($order),
        ]);
    }

    public function reconcile(Request $request): JsonResponse
    {
        $limit = max(1, min(100, (int) $request->input('limit', 50)));
        $result = $this->healthService->reconcileBatch($limit);

        PlatformAuditService::log('payment_health.reconcile_batch', [
            'limit' => $limit,
            'checked' => $result['checked'],
            'paid' => $result['paid'],
            'cancelled' => $result['cancelled'],
            'skipped' => $result['skipped'],
            'errors' => $result['errors'],
        ], $request);

        return response()->json([
            'message' => $result['paid'] > 0
                ? "{$result['paid']} pedido(s) marcado(s) como pago após confirmação no gateway."
                : 'Reconciliação concluída. Nenhum pedido novo foi confirmado como pago no gateway.',
            'result' => $result,
            'dashboard' => $this->healthService->buildDashboard(30),
        ]);
    }

    public function reconcileOrder(Request $request, Order $order): JsonResponse
    {
        if (! in_array($order->gateway, ['mercadopago', 'cajupay'], true)) {
            return response()->json([
                'message' => 'Pedido não pertence a um gateway monitorado.',
            ], 422);
        }

        $result = $this->healthService->reconcileOrder($order);

        PlatformAuditService::log('payment_health.reconcile_order', [
            'order_id' => $order->id,
            'gateway' => $order->gateway,
            'success' => $result['success'],
            'order_status' => $result['order_status'],
        ], $request);

        $statusCode = $result['success'] ? 200 : 422;

        return response()->json([
            'message' => $result['message'],
            'success' => $result['success'],
            'order_status' => $result['order_status'],
            'probe' => $result['probe'],
            'dashboard' => $this->healthService->buildDashboard(30),
        ], $statusCode);
    }
}
