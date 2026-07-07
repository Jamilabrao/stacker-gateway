<?php

namespace App\Services\MercadoPago;

use App\Gateways\GatewayRegistry;
use App\Gateways\MercadoPago\MercadoPagoDriver;
use App\Jobs\ProcessPaymentWebhook;
use App\Models\Order;
use App\Support\GatewayPaymentCredentials;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class MercadoPagoCheckoutCompletionService
{
    private const PENDING_CACHE_PREFIX = 'mercadopago_webhook_pending:';

    public function findOrderForWebhook(string $paymentId, ?string $externalReference): ?Order
    {
        $paymentId = trim($paymentId);
        if ($paymentId !== '') {
            $order = Order::query()
                ->where('gateway', 'mercadopago')
                ->where('gateway_id', $paymentId)
                ->first();
            if ($order !== null) {
                return $order;
            }

            $order = Order::query()
                ->where('gateway', 'mercadopago')
                ->where('metadata->mercadopago_payment_id', $paymentId)
                ->first();
            if ($order !== null) {
                return $order;
            }
        }

        $externalReference = trim((string) $externalReference);
        if ($externalReference !== '' && ctype_digit($externalReference)) {
            return Order::query()
                ->where('gateway', 'mercadopago')
                ->where('id', (int) $externalReference)
                ->first();
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function storePendingPaidWebhook(string $paymentId, ?string $externalReference, array $payload): void
    {
        $cacheKey = $this->pendingCacheKey($paymentId, $externalReference);
        if ($cacheKey === null) {
            return;
        }

        Cache::put($cacheKey, [
            'payment_id' => trim($paymentId),
            'external_reference' => trim((string) $externalReference),
            'payload' => $payload,
            'stored_at' => time(),
        ], now()->addHours(2));
    }

    public function applyPendingForOrder(Order $order): void
    {
        $meta = is_array($order->metadata) ? $order->metadata : [];
        $keys = array_unique(array_filter([
            (string) $order->id,
            trim((string) ($order->gateway_id ?? '')),
            trim((string) ($meta['mercadopago_payment_id'] ?? '')),
        ]));

        foreach ($keys as $key) {
            $pending = Cache::pull(self::PENDING_CACHE_PREFIX.$key);
            if (! is_array($pending)) {
                continue;
            }

            $paymentId = trim((string) ($pending['payment_id'] ?? ''));
            $payload = is_array($pending['payload'] ?? null) ? $pending['payload'] : [];

            if ($paymentId === '') {
                Log::info('MercadoPagoCheckoutCompletion: pending webhook sem payment_id', [
                    'order_id' => $order->id,
                    'cache_key' => $key,
                ]);

                continue;
            }

            Log::info('MercadoPagoCheckoutCompletion: aplicando webhook pendente', [
                'order_id' => $order->id,
                'payment_id' => $paymentId,
                'cache_key' => $key,
            ]);

            $this->applyPaid($order, $paymentId, $payload);

            return;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function applyPaid(Order $order, string $paymentId, array $payload = []): void
    {
        $paymentId = trim($paymentId);
        if ($paymentId === '') {
            return;
        }

        $meta = is_array($order->metadata) ? $order->metadata : [];
        $meta['mercadopago_payment_id'] = $paymentId;

        $order->update([
            'gateway' => 'mercadopago',
            'gateway_id' => $paymentId,
            'metadata' => $meta,
        ]);

        $enriched = array_merge($payload, [
            'webhook_source' => $payload['webhook_source'] ?? 'mercadopago_webhook',
            'external_reference' => (string) $order->id,
        ]);

        ProcessPaymentWebhook::dispatchSync(
            'mercadopago',
            $paymentId,
            'payment.updated',
            'paid',
            $enriched
        );
    }

    public function tryCompleteFromPaymentApi(Order $order): bool
    {
        if ($order->status !== 'pending' || $order->gateway !== 'mercadopago') {
            return $order->status === 'completed';
        }

        $credentials = GatewayPaymentCredentials::resolve($order->tenant_id, 'mercadopago', $order);
        if ($credentials === null) {
            return false;
        }

        $driver = GatewayRegistry::driver('mercadopago');
        if (! $driver instanceof MercadoPagoDriver) {
            return false;
        }

        $paymentId = trim((string) ($order->gateway_id ?? ''));
        $apiStatus = null;

        if ($paymentId !== '') {
            $apiStatus = $driver->getTransactionStatus($paymentId, $credentials);
        }

        if ($apiStatus !== 'paid') {
            $foundId = $driver->findApprovedPaymentByExternalReference((string) $order->id, $credentials);
            if ($foundId !== null) {
                $paymentId = $foundId;
                $apiStatus = 'paid';
            }
        }

        if ($apiStatus !== 'paid' || $paymentId === '') {
            return false;
        }

        $this->applyPaid($order->fresh(), $paymentId, [
            'webhook_source' => 'order_status_poll',
            'source' => 'order_status_poll',
        ]);

        return $order->fresh()->status === 'completed';
    }

    private function pendingCacheKey(string $paymentId, ?string $externalReference): ?string
    {
        $externalReference = trim((string) $externalReference);
        if ($externalReference !== '') {
            return self::PENDING_CACHE_PREFIX.$externalReference;
        }

        $paymentId = trim($paymentId);
        if ($paymentId !== '') {
            return self::PENDING_CACHE_PREFIX.$paymentId;
        }

        return null;
    }
}
