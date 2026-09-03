<?php

namespace App\Http\Controllers\Webhooks;

use App\Gateways\GatewayRegistry;
use App\Http\Controllers\Controller;
use App\Models\GatewayCredential;
use App\Models\Order;
use App\Support\PaymentWebhookDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PayPalWebhookController extends Controller
{
    /**
     * Handle PayPal webhook (POST /webhooks/gateways/paypal).
     * Verifies signature via PayPal verify-webhook-signature API.
     */
    public function handle(Request $request): JsonResponse
    {
        $payload = $request->getContent();
        if (! is_string($payload) || $payload === '') {
            return response()->json(['message' => 'Invalid request'], 400);
        }

        $payloadData = json_decode($payload, true);
        if (! is_array($payloadData)) {
            return response()->json(['message' => 'Invalid payload'], 400);
        }

        $eventType = (string) ($payloadData['event_type'] ?? '');
        $resource = is_array($payloadData['resource'] ?? null) ? $payloadData['resource'] : [];

        $paypalOrderId = $this->resolvePayPalOrderId($eventType, $resource);
        $platformOrderId = $this->resolvePlatformOrderId($resource);

        $order = null;
        if ($paypalOrderId !== null) {
            $order = Order::where('gateway', 'paypal')->where('gateway_id', $paypalOrderId)->first();
        }
        if (! $order && $platformOrderId !== null) {
            $order = Order::where('gateway', 'paypal')->where('id', $platformOrderId)->first();
        }
        if (! $order && ! empty($resource['id']) && $eventType === 'PAYMENT.CAPTURE.COMPLETED') {
            $order = Order::where('gateway', 'paypal')->where('gateway_id', (string) $resource['id'])->first();
        }

        if (! $order) {
            Log::info('PayPalWebhook: order not found', [
                'event' => $eventType,
                'paypal_order_id' => $paypalOrderId,
                'custom_id' => $platformOrderId,
            ]);

            return response()->json(['received' => true]);
        }

        $credential = GatewayCredential::resolveForPayment($order->tenant_id, 'paypal');
        if (! $credential) {
            return response()->json(['message' => 'Credential not found'], 400);
        }

        $credentials = $credential->getDecryptedCredentials();
        $headers = [
            'paypal-auth-algo' => (string) $request->header('PAYPAL-AUTH-ALGO', ''),
            'paypal-cert-url' => (string) $request->header('PAYPAL-CERT-URL', ''),
            'paypal-transmission-id' => (string) $request->header('PAYPAL-TRANSMISSION-ID', ''),
            'paypal-transmission-sig' => (string) $request->header('PAYPAL-TRANSMISSION-SIG', ''),
            'paypal-transmission-time' => (string) $request->header('PAYPAL-TRANSMISSION-TIME', ''),
        ];

        /** @var \App\Gateways\PayPal\PayPalDriver|null $driver */
        $driver = GatewayRegistry::driver('paypal');
        if (! $driver || ! $driver->verifyWebhookSignature($credentials, $headers, $payloadData)) {
            Log::warning('PayPalWebhook: signature verification failed', [
                'order_id' => $order->id,
                'event' => $eventType,
            ]);

            return response()->json(['message' => 'Invalid signature'], 401);
        }

        $transactionId = $order->gateway_id ?: ($paypalOrderId ?? (string) ($resource['id'] ?? ''));
        if ($transactionId === '') {
            return response()->json(['received' => true]);
        }

        if ($eventType === 'PAYMENT.CAPTURE.COMPLETED') {
            PaymentWebhookDispatcher::dispatch('paypal', $transactionId, 'PAYMENT.CAPTURE.COMPLETED', 'paid', $payloadData);
        } elseif ($eventType === 'PAYMENT.CAPTURE.DENIED') {
            PaymentWebhookDispatcher::dispatch('paypal', $transactionId, 'payment.rejected', 'rejected', $payloadData);
        } elseif ($eventType === 'PAYMENT.CAPTURE.REFUNDED') {
            PaymentWebhookDispatcher::dispatch('paypal', $transactionId, 'payment.refunded', 'refunded', $payloadData);
        }

        return response()->json(['received' => true]);
    }

    /**
     * @param  array<string, mixed>  $resource
     */
    private function resolvePayPalOrderId(string $eventType, array $resource): ?string
    {
        $related = $resource['supplementary_data']['related_ids']['order_id'] ?? null;
        if (is_string($related) && $related !== '') {
            return $related;
        }

        if (str_starts_with($eventType, 'CHECKOUT.ORDER.') && ! empty($resource['id'])) {
            return (string) $resource['id'];
        }

        $links = $resource['links'] ?? [];
        if (is_array($links)) {
            foreach ($links as $link) {
                if (! is_array($link)) {
                    continue;
                }
                $rel = (string) ($link['rel'] ?? '');
                $href = (string) ($link['href'] ?? '');
                if ($rel === 'up' && preg_match('#/v2/checkout/orders/([A-Z0-9]+)#i', $href, $m)) {
                    return $m[1];
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $resource
     */
    private function resolvePlatformOrderId(array $resource): ?int
    {
        $customId = $resource['custom_id']
            ?? $resource['purchase_units'][0]['custom_id']
            ?? null;
        if (is_numeric($customId)) {
            return (int) $customId;
        }

        return null;
    }
}
