<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessPaymentWebhook;
use App\Models\GatewayCredential;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class CajuPayCheckoutWebhookController extends Controller
{
    /**
     * POST /webhooks/gateways/cajupay/checkout — outbound webhooks (cartão / SDK).
     *
     * @see https://api.cajupay.com.br — X-CajuPay-Signature: t=<unix>,v1=<hex_hmac>
     */
    public function handle(Request $request): Response
    {
        $rawBody = $request->getContent();
        if (! is_string($rawBody) || $rawBody === '') {
            return response('empty body', 400);
        }

        $sigHeader = (string) $request->header('X-CajuPay-Signature', '');
        $parsed = $this->parseSignatureHeader($sigHeader);
        if ($parsed === null) {
            return response('invalid_signature_header', 400);
        }

        [$timestamp, $signatureHex] = $parsed;
        if (abs(time() - $timestamp) > 300) {
            return response('stale_timestamp', 401);
        }

        $payload = json_decode($rawBody, true);
        if (! is_array($payload)) {
            return response('invalid json', 400);
        }

        $secret = $this->resolveSigningSecret($rawBody, $timestamp, $signatureHex);
        if ($secret === null) {
            Log::warning('CajuPayCheckoutWebhook: no matching signing secret', [
                'event' => $payload['type'] ?? null,
            ]);

            return response('invalid_signature', 401);
        }

        $type = is_string($payload['type'] ?? null) ? $payload['type'] : '';
        $object = is_array($payload['data']['object'] ?? null) ? $payload['data']['object'] : [];

        $checkoutSessionId = $this->stringFrom($object['checkout_session_id'] ?? null);
        $chargeId = $this->stringFrom($object['cajupay_charge_id'] ?? null);

        $order = $this->findOrderForWebhook($checkoutSessionId, $chargeId);
        if ($order === null) {
            Log::info('CajuPayCheckoutWebhook: order not found', [
                'checkout_session_id' => $checkoutSessionId,
                'charge_id' => $chargeId,
                'type' => $type,
            ]);

            return response('ok', 200);
        }

        if ($type === 'checkout.payment.paid') {
            if ($chargeId === '') {
                return response('ok', 200);
            }
            $order->update([
                'gateway' => 'cajupay',
                'gateway_id' => $chargeId,
            ]);
            ProcessPaymentWebhook::dispatchSync('cajupay', $chargeId, 'checkout.payment.paid', 'paid', $payload);

            return response('ok', 200);
        }

        if ($type === 'checkout.payment.failed') {
            $ref = $chargeId !== '' ? $chargeId : $checkoutSessionId;
            if ($ref === '') {
                return response('ok', 200);
            }
            ProcessPaymentWebhook::dispatchSync('cajupay', $ref, 'checkout.payment.failed', 'rejected', $payload);

            return response('ok', 200);
        }

        if ($type === 'checkout.payment.refunded') {
            if ($chargeId === '') {
                return response('ok', 200);
            }
            ProcessPaymentWebhook::dispatchSync('cajupay', $chargeId, 'checkout.payment.refunded', 'refunded', $payload);

            return response('ok', 200);
        }

        if ($type === 'checkout.payment.disputed') {
            if ($chargeId === '') {
                return response('ok', 200);
            }
            ProcessPaymentWebhook::dispatchSync('cajupay', $chargeId, 'checkout.payment.disputed', 'disputed', $payload);

            return response('ok', 200);
        }

        return response('ok', 200);
    }

    /**
     * @return array{0: int, 1: string}|null
     */
    private function parseSignatureHeader(string $header): ?array
    {
        $header = trim($header);
        if ($header === '') {
            return null;
        }
        $parts = array_map('trim', explode(',', $header));
        $ts = null;
        $sig = null;
        foreach ($parts as $part) {
            if (str_starts_with($part, 't=')) {
                $ts = (int) substr($part, 2);
            }
            if (str_starts_with($part, 'v1=')) {
                $sig = substr($part, 3);
            }
        }
        if ($ts === null || $sig === null || $ts <= 0 || $sig === '') {
            return null;
        }

        return [$ts, strtolower($sig)];
    }

    private function resolveSigningSecret(string $rawBody, int $timestamp, string $signatureHex): ?string
    {
        $candidates = GatewayCredential::query()
            ->where('gateway_slug', 'cajupay')
            ->where('is_connected', true)
            ->get();

        $signedPayload = $timestamp.'.'.$rawBody;

        foreach ($candidates as $credential) {
            $creds = $credential->getDecryptedCredentials();
            $secret = trim((string) ($creds['checkout_webhook_signing_secret'] ?? ''));
            if ($secret === '') {
                continue;
            }
            $expected = hash_hmac('sha256', $signedPayload, $secret);
            if (hash_equals($expected, $signatureHex)) {
                return $secret;
            }
        }

        return null;
    }

    private function findOrderForWebhook(string $checkoutSessionId, string $chargeId): ?Order
    {
        if ($checkoutSessionId !== '') {
            $bySession = Order::query()
                ->where('metadata->cajupay_checkout_session_id', $checkoutSessionId)
                ->first();
            if ($bySession !== null) {
                return $bySession;
            }
        }
        if ($chargeId !== '') {
            return Order::query()
                ->where('gateway', 'cajupay')
                ->where('gateway_id', $chargeId)
                ->first();
        }

        return null;
    }

    private function stringFrom(mixed $v): string
    {
        if (! is_string($v)) {
            return '';
        }
        $s = trim($v);

        return $s;
    }
}
