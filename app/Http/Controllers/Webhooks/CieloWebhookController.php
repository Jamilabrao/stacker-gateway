<?php

namespace App\Http\Controllers\Webhooks;

use App\Gateways\Cielo\CieloDriver;
use App\Gateways\GatewayRegistry;
use App\Http\Controllers\Controller;
use App\Models\GatewayCredential;
use App\Models\Order;
use App\Support\PaymentWebhookDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CieloWebhookController extends Controller
{
    private const SLUG = 'cielo';

    /**
     * POST /webhooks/gateways/cielo — Post de Notificação (PaymentId + ChangeType).
     * Sempre reconsulta GET /1/sales/{PaymentId}. Probe sem PaymentId retorna 200 (teste de URL da Cielo).
     */
    public function handle(Request $request): JsonResponse
    {
        $payload = $request->all();
        $paymentId = $this->resolvePaymentId($payload);
        if ($paymentId === null) {
            return response()->json(['received' => true]);
        }

        $changeType = (int) ($payload['ChangeType'] ?? $payload['changeType'] ?? 0);

        $order = Order::where('gateway', self::SLUG)->where('gateway_id', $paymentId)->first();
        if (! $order) {
            Log::debug('CieloWebhook: order not found', ['gateway_id' => $paymentId]);

            return response()->json(['received' => true]);
        }

        if (! $this->verifyStaticHeader($request, $order)) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $credential = GatewayCredential::resolveForPayment($order->tenant_id, self::SLUG);
        if ($credential === null) {
            return response()->json(['received' => true]);
        }

        $driver = GatewayRegistry::driver(self::SLUG);
        if (! $driver instanceof CieloDriver) {
            return response()->json(['received' => true]);
        }

        $sale = $driver->getSale($paymentId, $credential->getDecryptedCredentials());
        if ($sale === null) {
            Log::warning('CieloWebhook: consulta à Cielo indisponível', [
                'order_id' => $order->id,
                'payment_id' => $paymentId,
            ]);

            return response()->json(['received' => true]);
        }

        $payment = is_array($sale['Payment'] ?? null) ? $sale['Payment'] : [];
        $cieloStatus = isset($payment['Status']) && is_numeric($payment['Status']) ? (int) $payment['Status'] : null;
        if ($cieloStatus === null) {
            return response()->json(['received' => true]);
        }

        $event = 'order.pending';
        $mapped = 'pending';

        if ($cieloStatus === 2) {
            if ($this->amountAndOrderMatch($order, $sale)) {
                $event = 'order.paid';
                $mapped = 'paid';
            } else {
                Log::warning('CieloWebhook: PaymentConfirmed com valor ou pedido divergente', [
                    'order_id' => $order->id,
                    'payment_id' => $paymentId,
                ]);
            }
        } elseif ($cieloStatus === 11) {
            $event = 'order.refunded';
            $mapped = 'refunded';
        } elseif ($changeType === 25) {
            // Estorno parcial: pedido permanece completed até devolução total (status 11).
            return response()->json(['received' => true]);
        } elseif ($cieloStatus === 3 || $cieloStatus === 13) {
            $event = 'order.rejected';
            $mapped = 'rejected';
        } elseif ($cieloStatus === 10) {
            $event = 'order.cancelled';
            $mapped = 'cancelled';
        }

        $dispatchPayload = array_merge(is_array($payload) ? $payload : [], [
            'webhook_source' => 'cielo_webhook',
            'cielo_status' => $cieloStatus,
            'cielo_sale' => [
                'Amount' => $payment['Amount'] ?? null,
                'MerchantOrderId' => $sale['MerchantOrderId'] ?? null,
                'EndToEndId' => $payment['EndToEndId'] ?? null,
            ],
        ]);

        PaymentWebhookDispatcher::dispatch(self::SLUG, $paymentId, $event, $mapped, $dispatchPayload);

        return response()->json(['received' => true]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolvePaymentId(array $payload): ?string
    {
        foreach (['PaymentId', 'paymentId', 'PaymentID'] as $key) {
            $value = $payload[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    private function verifyStaticHeader(Request $request, Order $order): bool
    {
        $credential = GatewayCredential::resolveForPayment($order->tenant_id, self::SLUG);
        if ($credential === null) {
            return true;
        }

        $creds = $credential->getDecryptedCredentials();
        $headerKey = trim((string) ($creds['webhook_header_key'] ?? ''));
        $headerValue = trim((string) ($creds['webhook_header_value'] ?? ''));
        if ($headerKey === '' || $headerValue === '') {
            return true;
        }

        $incoming = $request->header($headerKey);
        if (! is_string($incoming) || ! hash_equals($headerValue, trim($incoming))) {
            Log::warning('CieloWebhook: header estático inválido', [
                'order_id' => $order->id,
                'header' => $headerKey,
            ]);

            return false;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $sale
     */
    private function amountAndOrderMatch(Order $order, array $sale): bool
    {
        $payment = is_array($sale['Payment'] ?? null) ? $sale['Payment'] : [];
        $cieloAmount = isset($payment['Amount']) && is_numeric($payment['Amount']) ? (int) $payment['Amount'] : 0;
        $orderCents = (int) round((float) $order->amount * 100);
        if ($cieloAmount > 0 && $orderCents > 0 && $cieloAmount !== $orderCents) {
            return false;
        }

        $merchantOrderId = trim((string) ($sale['MerchantOrderId'] ?? ''));
        if ($merchantOrderId !== '' && $merchantOrderId !== (string) $order->id) {
            return false;
        }

        return true;
    }
}
