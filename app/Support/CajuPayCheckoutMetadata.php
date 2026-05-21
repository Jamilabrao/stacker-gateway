<?php

namespace App\Support;

use App\Models\Order;

/**
 * Chaves de metadata do fluxo SDK/draft CajuPay (cartão / wallets).
 */
final class CajuPayCheckoutMetadata
{
    public static function publicSessionToken(Order $order): ?string
    {
        $meta = is_array($order->metadata) ? $order->metadata : [];
        foreach (['cajupay_session_token', 'cajupay_sdk_token'] as $key) {
            $v = $meta[$key] ?? null;
            if (is_string($v) && trim($v) !== '') {
                return trim($v);
            }
        }

        return null;
    }

    public static function checkoutSessionId(Order $order): ?string
    {
        $meta = is_array($order->metadata) ? $order->metadata : [];
        $v = $meta['cajupay_checkout_session_id'] ?? null;

        return is_string($v) && trim($v) !== '' ? trim($v) : null;
    }
}
