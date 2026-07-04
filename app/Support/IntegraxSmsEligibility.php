<?php

namespace App\Support;

use App\Models\Order;
use App\Models\PlatformIntegraxSetting;

class IntegraxSmsEligibility
{
    /**
     * IntegraX SMS aplica-se ao checkout da plataforma (CheckoutSession / pedidos sem origem API).
     * Pedidos criados via API PIX ou checkout de parceiro (api_application_id) ficam de fora.
     */
    public static function allowsForOrder(Order $order, PlatformIntegraxSetting $settings): bool
    {
        if (! ($settings->sms_checkout_only ?? true)) {
            return true;
        }

        return $order->api_application_id === null
            && $order->api_checkout_session_id === null;
    }

    public static function allowsForOrderId(int $orderId, PlatformIntegraxSetting $settings): bool
    {
        $order = Order::query()->find($orderId);

        if ($order === null) {
            return true;
        }

        return self::allowsForOrder($order, $settings);
    }
}
