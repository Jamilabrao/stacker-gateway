<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessPaymentWebhook;
use App\Models\GatewayCredential;
use App\Models\Order;
use App\Services\CajuPay\CajuPayCheckoutCompletionService;
use App\Services\CajuPay\CajuPayMedService;
use App\Services\PlatformOrderAdminService;
use App\Support\CajuPayPaymentId;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class CajuPayCheckoutWebhookController extends Controller
{
    private const SLUG = 'cajupay';

    /**
     * POST /webhooks/gateways/cajupay — webhooks CajuPay (PIX API, checkout SDK, cartão).
     *
     * @see https://api.cajupay.com.br — X-CajuPay-Signature: t=<unix>,v1=<hex_hmac>
     */
    public function handle(Request $request): Response
    {
        $rawBody = $request->getContent();
        if (! is_string($rawBody) || $rawBody === '') {
            return response('empty body', 400);
        }

        $payload = json_decode($rawBody, true);
        if (! is_array($payload)) {
            return response('invalid json', 400);
        }

        $eventType = (string) ($request->header('X-CajuPay-Event') ?? ($payload['type'] ?? ''));

        $sigHeader = (string) $request->header('X-CajuPay-Signature', '');
        $parsed = $this->parseSignatureHeader($sigHeader);
        $timestampHeader = (string) $request->header('X-CajuPay-Timestamp', '');

        $timestamp = $parsed['t'] ?? (is_numeric($timestampHeader) ? (int) $timestampHeader : 0);
        $signatureHex = strtolower($parsed['v1'] ?? '');

        if ($signatureHex === '' || $timestamp <= 0) {
            return response('invalid_signature_header', 400);
        }

        if (abs(time() - $timestamp) > 300) {
            return response('stale_timestamp', 401);
        }

        $object = $this->extractObject($payload);
        $checkoutSessionId = $this->pickSessionId($object);
        $paymentId = $this->pickPaymentId($object);
        if ($paymentId === '' && is_array($object)) {
            $paymentId = CajuPayPaymentId::pickFromWebhookObject($object);
        }

        $order = $this->findOrderForWebhook($checkoutSessionId, $paymentId);
        if ($order === null && is_array($object)) {
            $order = CajuPayMedService::findOrderForPixWebhook($object);
        }

        $secret = $this->resolveSigningSecret($rawBody, (string) $timestamp, $signatureHex, $order?->tenant_id);
        if ($secret === null) {
            Log::warning('CajuPayWebhook: no matching signing secret', [
                'event' => $eventType,
                'payment_id' => $paymentId,
                'checkout_session_id' => $checkoutSessionId,
            ]);

            return response('invalid_signature', 401);
        }

        if ($order === null) {
            if ($this->isPaidEvent($eventType) && $checkoutSessionId !== '' && $paymentId !== '') {
                app(CajuPayCheckoutCompletionService::class)->storePendingPaidWebhook(
                    $checkoutSessionId,
                    $paymentId,
                    array_merge($payload, ['webhook_source' => 'cajupay_webhook'])
                );
                Log::info('CajuPayWebhook: paid guardado até confirm-order', [
                    'checkout_session_id' => $checkoutSessionId,
                    'payment_id' => $paymentId,
                ]);
            } else {
                Log::info('CajuPayWebhook: order not found', [
                    'event' => $eventType,
                    'checkout_session_id' => $checkoutSessionId,
                    'payment_id' => $paymentId,
                ]);
            }

            return response('ok', 200);
        }

        if ($order !== null && $paymentId !== '' && $order->gateway_id !== $paymentId) {
            try {
                $order->update([
                    'gateway' => self::SLUG,
                    'gateway_id' => $paymentId,
                ]);
                $order->refresh();
            } catch (\Throwable $e) {
                Log::debug('CajuPayWebhook: falha ao atualizar gateway_id', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $dispatchId = $paymentId !== '' ? $paymentId : (string) ($order->gateway_id ?? $checkoutSessionId);
        $webhookMeta = array_merge($payload, ['webhook_source' => 'cajupay_webhook']);
        $completion = app(CajuPayCheckoutCompletionService::class);

        if ($this->isPaidEvent($eventType)) {
            if ($dispatchId === '') {
                return response('ok', 200);
            }
            $completion->applyPaid($order, $dispatchId, $webhookMeta);

            return response('ok', 200);
        }

        if ($this->isFailedEvent($eventType)) {
            if ($dispatchId === '') {
                return response('ok', 200);
            }
            ProcessPaymentWebhook::dispatchSync(self::SLUG, $dispatchId, 'checkout.payment.failed', 'rejected', $webhookMeta);

            return response('ok', 200);
        }

        if ($this->isRefundedEvent($eventType)) {
            if ($dispatchId === '') {
                return response('ok', 200);
            }
            ProcessPaymentWebhook::dispatchSync(self::SLUG, $dispatchId, 'checkout.payment.refunded', 'refunded', $webhookMeta);

            return response('ok', 200);
        }

        if ($this->isDisputedEvent($eventType)) {
            if ($dispatchId === '') {
                return response('ok', 200);
            }
            ProcessPaymentWebhook::dispatchSync(self::SLUG, $dispatchId, 'checkout.payment.disputed', 'disputed', $webhookMeta);

            return response('ok', 200);
        }

        if ($this->isPixRefundedEvent($eventType)) {
            $pixOrder = $order ?? CajuPayMedService::findOrderForPixWebhook($object ?? []);
            if ($pixOrder !== null) {
                $paymentId = CajuPayPaymentId::pickFromWebhookObject($object);
                if ($paymentId !== '') {
                    CajuPayPaymentId::persistOnOrder($pixOrder, $paymentId);
                }
                if (in_array($pixOrder->status, ['completed', 'disputed'], true)) {
                    PlatformOrderAdminService::applyGatewayRefund($pixOrder->fresh());
                }
            }

            return response('ok', 200);
        }

        if ($this->isPixMedOpenedEvent($eventType)) {
            $pixOrder = $order ?? CajuPayMedService::findOrderForPixWebhook($object ?? []);
            if ($pixOrder !== null && is_array($object)) {
                app(CajuPayMedService::class)->syncOpenedFromWebhook($pixOrder, $object);
            }

            return response('ok', 200);
        }

        if ($this->isPixMedResolvedEvent($eventType)) {
            $pixOrder = $order ?? CajuPayMedService::findOrderForPixWebhook($object ?? []);
            if ($pixOrder !== null && is_array($object)) {
                app(CajuPayMedService::class)->syncResolvedFromWebhook($pixOrder, $object);
            }

            return response('ok', 200);
        }

        Log::debug('CajuPayWebhook: tipo não tratado', ['event' => $eventType]);

        return response('ok', 200);
    }

    private function isPaidEvent(string $eventType): bool
    {
        return in_array($eventType, [
            'checkout.payment.paid',
            'card.payment.succeeded',
            'payment.paid',
        ], true);
    }

    private function isFailedEvent(string $eventType): bool
    {
        return in_array($eventType, [
            'checkout.payment.failed',
            'card.payment.failed',
            'payment.failed',
        ], true);
    }

    private function isRefundedEvent(string $eventType): bool
    {
        return in_array($eventType, [
            'checkout.payment.refunded',
            'card.payment.refunded',
            'payment.refunded',
        ], true);
    }

    private function isDisputedEvent(string $eventType): bool
    {
        return in_array($eventType, [
            'checkout.payment.disputed',
            'card.payment.disputed',
        ], true);
    }

    private function isPixRefundedEvent(string $eventType): bool
    {
        return $eventType === 'pix.payment.refunded';
    }

    private function isPixMedOpenedEvent(string $eventType): bool
    {
        return $eventType === 'pix.payment.med_opened';
    }

    private function isPixMedResolvedEvent(string $eventType): bool
    {
        return $eventType === 'pix.payment.med_resolved';
    }

    /**
     * @return array{t?: string, v1?: string}
     */
    private function parseSignatureHeader(string $header): array
    {
        $out = [];
        $header = trim($header);
        if ($header === '') {
            return $out;
        }
        foreach (explode(',', $header) as $part) {
            $part = trim($part);
            if (str_starts_with($part, 't=')) {
                $out['t'] = substr($part, 2);
            }
            if (str_starts_with($part, 'v1=')) {
                $out['v1'] = substr($part, 3);
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    private function extractObject(array $payload): ?array
    {
        $data = $payload['data'] ?? null;
        if (is_array($data)) {
            $object = $data['object'] ?? null;
            if (is_array($object)) {
                return $object;
            }

            return $data;
        }
        if (isset($payload['object']) && is_array($payload['object'])) {
            return $payload['object'];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|null  $object
     */
    private function pickSessionId(?array $object): string
    {
        if ($object === null) {
            return '';
        }
        foreach (['checkout_session_id', 'checkout_sessionId', 'session_id'] as $k) {
            $v = $object[$k] ?? null;
            if (is_string($v) && trim($v) !== '') {
                return trim($v);
            }
        }

        return '';
    }

    /**
     * @param  array<string, mixed>|null  $object
     */
    private function pickPaymentId(?array $object): string
    {
        if ($object === null) {
            return '';
        }
        foreach (['cajupay_charge_id', 'charge_id', 'payment_id', 'id'] as $k) {
            $v = $object[$k] ?? null;
            if (is_string($v) && trim($v) !== '') {
                return trim($v);
            }
        }

        return '';
    }

    private function findOrderForWebhook(string $checkoutSessionId, string $paymentId): ?Order
    {
        if ($checkoutSessionId !== '') {
            $bySession = Order::query()
                ->where('metadata->cajupay_checkout_session_id', $checkoutSessionId)
                ->first();
            if ($bySession !== null) {
                return $bySession;
            }
            $bySession = Order::query()
                ->where('gateway', self::SLUG)
                ->where('gateway_id', $checkoutSessionId)
                ->first();
            if ($bySession !== null) {
                return $bySession;
            }
        }

        if ($paymentId !== '') {
            $byPayment = Order::query()
                ->where('gateway', self::SLUG)
                ->where('gateway_id', $paymentId)
                ->first();
            if ($byPayment !== null) {
                return $byPayment;
            }

            $byMeta = Order::query()
                ->where('metadata->cajupay_payment_id', $paymentId)
                ->first();
            if ($byMeta !== null) {
                return $byMeta;
            }
        }

        return null;
    }

    private function resolveSigningSecret(string $rawBody, string $timestamp, string $signatureHex, ?int $preferTenantId): ?string
    {
        if ($signatureHex === '' || $timestamp === '') {
            return null;
        }

        $signedPayload = $timestamp.'.'.$rawBody;

        $query = GatewayCredential::query()
            ->where('gateway_slug', self::SLUG)
            ->where('is_connected', true);
        if ($preferTenantId !== null) {
            $query->where('tenant_id', $preferTenantId);
        }
        $candidates = $query->get();

        if ($candidates->isEmpty() && $preferTenantId !== null) {
            $candidates = GatewayCredential::query()
                ->where('gateway_slug', self::SLUG)
                ->where('is_connected', true)
                ->get();
        }

        foreach ($candidates as $credential) {
            $creds = $credential->getDecryptedCredentials();
            foreach (['checkout_webhook_signing_secret', 'webhook_signing_secret'] as $key) {
                $secret = trim((string) ($creds[$key] ?? ''));
                if ($secret === '') {
                    continue;
                }
                $expected = hash_hmac('sha256', $signedPayload, $secret);
                if (hash_equals($expected, $signatureHex)) {
                    return $secret;
                }
            }
        }

        return null;
    }
}
