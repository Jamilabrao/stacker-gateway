<?php

namespace App\Support;

use App\Models\Order;

/**
 * Resolve o payment_id CajuPay (UUID) usado em reembolso PIX e MED.
 */
final class CajuPayPaymentId
{
    public static function fromOrder(Order $order): ?string
    {
        $meta = is_array($order->metadata) ? $order->metadata : [];
        foreach (['cajupay_payment_id', 'payment_id'] as $key) {
            $v = $meta[$key] ?? null;
            if (is_string($v) && self::looksLikeUuid(trim($v))) {
                return trim($v);
            }
        }

        $gatewayId = trim((string) ($order->gateway_id ?? ''));
        if ($gatewayId !== '' && self::looksLikeUuid($gatewayId)) {
            return $gatewayId;
        }

        return null;
    }

    public static function persistOnOrder(Order $order, string $paymentId): void
    {
        $paymentId = trim($paymentId);
        if ($paymentId === '' || ! self::looksLikeUuid($paymentId)) {
            return;
        }

        $meta = is_array($order->metadata) ? $order->metadata : [];
        if (($meta['cajupay_payment_id'] ?? '') === $paymentId) {
            return;
        }

        $meta['cajupay_payment_id'] = $paymentId;
        $order->update(['metadata' => $meta]);
    }

    public static function isPixPaymentMethod(?string $method): bool
    {
        return in_array($method, ['pix', 'pix_auto'], true);
    }

    public static function looksLikeUuid(string $value): bool
    {
        return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value);
    }

    /**
     * @param  array<string, mixed>|null  $object
     */
    public static function pickFromWebhookObject(?array $object): string
    {
        if ($object === null) {
            return '';
        }
        foreach (['cajupay_payment_id', 'payment_id', 'charge_id', 'cajupay_charge_id'] as $k) {
            $v = $object[$k] ?? null;
            if (is_string($v) && trim($v) !== '') {
                return trim($v);
            }
        }

        return '';
    }
}
