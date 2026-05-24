<?php

namespace App\Services\CajuPay;

use App\Jobs\ProcessPaymentWebhook;
use App\Models\Order;
use App\Support\CajuPayCheckoutMetadata;
use App\Support\CajuPayPaymentId;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class CajuPayCheckoutCompletionService
{
    private const PENDING_CACHE_PREFIX = 'cajupay_checkout_webhook_pending:';

    /**
     * Webhook chegou antes do confirm-order: guarda até 2h para aplicar quando a Order existir.
     *
     * @param  array<string, mixed>  $payload
     */
    public function storePendingPaidWebhook(string $checkoutSessionId, string $chargeId, array $payload): void
    {
        $checkoutSessionId = trim($checkoutSessionId);
        if ($checkoutSessionId === '') {
            return;
        }

        Cache::put(self::PENDING_CACHE_PREFIX.$checkoutSessionId, [
            'charge_id' => $chargeId,
            'payload' => $payload,
            'stored_at' => time(),
        ], now()->addHours(2));
    }

    public function applyPendingForOrder(Order $order): void
    {
        $sessionId = CajuPayCheckoutMetadata::checkoutSessionId($order);
        if ($sessionId === null) {
            return;
        }

        $pending = Cache::pull(self::PENDING_CACHE_PREFIX.$sessionId);
        if (! is_array($pending)) {
            return;
        }

        $chargeId = trim((string) ($pending['charge_id'] ?? ''));
        $payload = is_array($pending['payload'] ?? null) ? $pending['payload'] : [];

        if ($chargeId === '') {
            Log::info('CajuPayCheckoutCompletion: pending webhook sem charge_id', [
                'order_id' => $order->id,
                'checkout_session_id' => $sessionId,
            ]);

            return;
        }

        Log::info('CajuPayCheckoutCompletion: aplicando webhook pendente após materializar pedido', [
            'order_id' => $order->id,
            'charge_id' => $chargeId,
        ]);

        $this->applyPaid($order, $chargeId, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function applyPaid(Order $order, string $chargeId, array $payload = []): void
    {
        $chargeId = trim($chargeId);
        if ($chargeId === '') {
            return;
        }

        $meta = is_array($order->metadata) ? $order->metadata : [];
        if (CajuPayPaymentId::looksLikeUuid($chargeId)) {
            $meta['cajupay_payment_id'] = $chargeId;
        }

        $order->update([
            'gateway' => 'cajupay',
            'gateway_id' => $chargeId,
            'metadata' => $meta,
        ]);

        $enriched = array_merge($payload, [
            'webhook_source' => $payload['webhook_source'] ?? 'cajupay_checkout_webhook',
        ]);

        ProcessPaymentWebhook::dispatchSync(
            'cajupay',
            $chargeId,
            'checkout.payment.paid',
            'paid',
            $enriched
        );
    }

    /**
     * Consulta status público da sessão SDK e completa o pedido se já estiver pago na CajuPay.
     */
    public function tryCompleteFromPublicSession(Order $order): bool
    {
        if ($order->status !== 'pending') {
            return $order->status === 'completed';
        }

        $token = CajuPayCheckoutMetadata::publicSessionToken($order);
        if ($token === null) {
            return false;
        }

        $credentials = CajuPaySdkCheckoutService::resolveCredentialsForOrder($order);
        if ($credentials === null) {
            return false;
        }

        $paymentStatus = app(CajuPaySdkCheckoutService::class)->getPublicSessionStatus($token, $credentials);
        if ($paymentStatus !== 'paid') {
            return false;
        }

        $chargeId = (string) ($order->gateway_id ?? '');
        if ($chargeId === '') {
            $chargeId = CajuPayCheckoutMetadata::checkoutSessionId($order) ?? ('session-'.$order->id);
        }

        $this->applyPaid($order->fresh(), $chargeId, [
            'webhook_source' => 'cajupay_public_session_poll',
            'source' => 'order_status_poll',
        ]);

        return $order->fresh()->status === 'completed';
    }
}
