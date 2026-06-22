<?php

namespace App\Http\Controllers;

use App\Models\CheckoutSession;
use App\Models\Order;
use App\Services\Meta\MetaTrackingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CheckoutMetaTrackingController extends Controller
{
    public function store(Request $request, MetaTrackingService $trackingService): JsonResponse
    {
        $validated = $request->validate([
            'checkout_session_token' => ['required', 'string', 'max:64'],
            'event_name' => ['required', 'string', 'in:PageView,InitiateCheckout,Purchase'],
            'event_id' => ['required', 'string', 'max:128'],
            'order_id' => ['nullable', 'integer', 'min:1'],
            'fbp' => ['nullable', 'string', 'max:512'],
            'fbc' => ['nullable', 'string', 'max:512'],
            'user_agent' => ['nullable', 'string', 'max:1024'],
            'event_source_url' => ['nullable', 'string', 'max:2048'],
            'value' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'max:8'],
            'content_ids' => ['nullable', 'array'],
            'content_ids.*' => ['string', 'max:128'],
            'content_name' => ['nullable', 'string', 'max:255'],
        ]);

        $session = CheckoutSession::where('session_token', $validated['checkout_session_token'])->first();
        if (! $session) {
            return response()->json(['ok' => false, 'message' => 'Sessão não encontrada.'], 404);
        }

        $trackingService->persistSessionAttribution($session, $validated);

        $session->refresh();

        if ($validated['event_name'] === 'Purchase') {
            return $this->storePurchaseMirror($validated, $session, $trackingService);
        }

        $overrides = array_filter([
            'fbp' => $validated['fbp'] ?? null,
            'fbc' => $validated['fbc'] ?? null,
            'user_agent' => $validated['user_agent'] ?? null,
            'event_source_url' => $validated['event_source_url'] ?? null,
            'value' => isset($validated['value']) ? (float) $validated['value'] : null,
            'currency' => $validated['currency'] ?? null,
            'content_ids' => $validated['content_ids'] ?? null,
            'content_name' => $validated['content_name'] ?? null,
        ], fn ($v) => $v !== null);

        $queued = $trackingService->queueSessionEvent(
            $session,
            $validated['event_name'],
            $validated['event_id'],
            $overrides,
        );

        return response()->json([
            'ok' => true,
            'queued' => count($queued),
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function storePurchaseMirror(array $validated, CheckoutSession $session, MetaTrackingService $trackingService): JsonResponse
    {
        $orderId = (int) ($validated['order_id'] ?? 0);
        if ($orderId <= 0) {
            return response()->json(['ok' => false, 'message' => 'order_id é obrigatório para Purchase.'], 422);
        }

        $expectedEventId = 'order:'.$orderId;
        if (($validated['event_id'] ?? '') !== $expectedEventId) {
            return response()->json(['ok' => false, 'message' => 'event_id inválido para Purchase.'], 422);
        }

        $order = Order::query()->find($orderId);
        if (! $order) {
            return response()->json(['ok' => false, 'message' => 'Pedido não encontrado.'], 404);
        }

        if ($order->status !== 'completed') {
            return response()->json(['ok' => false, 'message' => 'Pedido ainda não está concluído.'], 422);
        }

        if ((string) $order->product_id !== (string) $session->product_id) {
            return response()->json(['ok' => false, 'message' => 'Pedido não pertence à sessão.'], 403);
        }

        if ($session->order_id !== null && (int) $session->order_id !== $orderId) {
            return response()->json(['ok' => false, 'message' => 'Pedido não pertence à sessão.'], 403);
        }

        if ($session->order_id === null) {
            $session->update(['order_id' => $orderId]);
        }

        $queued = $trackingService->queuePurchaseForOrder($order);

        return response()->json([
            'ok' => true,
            'queued' => count($queued),
        ]);
    }
}
