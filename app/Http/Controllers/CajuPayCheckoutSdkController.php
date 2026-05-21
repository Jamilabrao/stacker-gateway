<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Product;
use App\Services\CajuPay\CajuPaySdkCheckoutService;
use App\Services\PaymentService;
use App\Support\CajuPayBrowserSdk;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CajuPayCheckoutSdkController extends Controller
{
    public function createSession(Request $request, PaymentService $paymentService, CajuPaySdkCheckoutService $sdk): JsonResponse
    {
        $validated = $request->validate([
            'order_id' => ['required', 'integer', 'exists:orders,id'],
            'cajupay_sdk_nonce' => ['required', 'string', 'size:40'],
            'cajupay_wallet' => ['nullable', 'string', 'in:card,apple_pay,google_pay'],
        ]);

        $order = Order::query()->findOrFail((int) $validated['order_id']);
        if ($order->status !== 'pending' || $order->payment_method !== 'card') {
            return response()->json(['message' => 'Pedido inválido para sessão de pagamento.'], 422);
        }

        $meta = is_array($order->metadata) ? $order->metadata : [];
        $expectedNonce = $meta['cajupay_sdk_nonce'] ?? null;
        if (! is_string($expectedNonce) || ! hash_equals($expectedNonce, $validated['cajupay_sdk_nonce'])) {
            return response()->json(['message' => 'Não autorizado.'], 403);
        }

        $product = $order->product;
        if (! $product instanceof Product) {
            return response()->json(['message' => 'Produto não encontrado.'], 422);
        }

        $firstCard = $paymentService->getFirstAvailableGatewayForMethod($order->tenant_id, 'card', $product);
        if ($firstCard !== 'cajupay') {
            return response()->json(['message' => 'Gateway de cartão não é CajuPay.'], 422);
        }

        $credentials = CajuPaySdkCheckoutService::resolveCredentialsForOrder($order);
        if ($credentials === null) {
            return response()->json(['message' => 'CajuPay não configurado.'], 422);
        }

        $wallet = (string) ($validated['cajupay_wallet'] ?? 'card');
        $order->loadMissing(['productOffer', 'subscriptionPlan']);
        $pme = Product::resolvedPaymentMethodsEnabled($product, $order->productOffer, $order->subscriptionPlan);
        $w = strtolower(trim($wallet));
        if (! in_array($w, ['card', 'apple_pay', 'google_pay'], true)) {
            $w = 'card';
        }
        if ($w === 'apple_pay' && empty($pme['apple_pay'])) {
            $w = 'card';
        }
        if ($w === 'google_pay' && empty($pme['google_pay'])) {
            $w = 'card';
        }
        $wallet = $w;

        try {
            $created = $sdk->createCheckoutSession($order, $wallet, $credentials);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $meta = array_merge($meta, [
            'cajupay_checkout_session_id' => $created['checkout_session_id'],
            'cajupay_sdk_token' => $created['token'],
            'cajupay_wallet' => $wallet,
            'cajupay_sdk_session_created_at' => now()->toIso8601String(),
        ]);
        $order->update(['metadata' => $meta]);

        $methods = $created['raw']['methods_available'] ?? $created['raw']['payment_methods'] ?? null;

        return response()->json([
            'token' => $created['token'],
            'checkout_session_id' => $created['checkout_session_id'],
            'methods_available' => is_array($methods) ? $methods : null,
            'sdk_base_url' => CajuPayBrowserSdk::apiBaseUrlForBrowser($request),
        ]);
    }

    public function sessionStatus(Request $request, PaymentService $paymentService, CajuPaySdkCheckoutService $sdk): JsonResponse
    {
        $validated = $request->validate([
            'order_id' => ['required', 'integer', 'exists:orders,id'],
            'cajupay_sdk_nonce' => ['required', 'string', 'size:40'],
        ]);

        $order = Order::query()->findOrFail((int) $validated['order_id']);
        $meta = is_array($order->metadata) ? $order->metadata : [];
        $expectedNonce = $meta['cajupay_sdk_nonce'] ?? null;
        if (! is_string($expectedNonce) || ! hash_equals($expectedNonce, $validated['cajupay_sdk_nonce'])) {
            return response()->json(['message' => 'Não autorizado.'], 403);
        }

        $product = $order->product;
        if (! $product instanceof Product) {
            return response()->json(['message' => 'Produto não encontrado.'], 422);
        }

        $firstCard = $paymentService->getFirstAvailableGatewayForMethod($order->tenant_id, 'card', $product);
        if ($firstCard !== 'cajupay') {
            return response()->json(['message' => 'Gateway de cartão não é CajuPay.'], 422);
        }

        $token = $meta['cajupay_sdk_token'] ?? null;
        if (! is_string($token) || $token === '') {
            return response()->json(['order_status' => $order->status, 'payment_status' => null]);
        }

        $credentials = CajuPaySdkCheckoutService::resolveCredentialsForOrder($order);
        $paymentStatus = $sdk->getPublicSessionStatus($token, $credentials);

        return response()->json([
            'order_status' => $order->status,
            'payment_status' => $paymentStatus,
        ]);
    }
}
