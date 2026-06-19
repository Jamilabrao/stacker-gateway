<?php

namespace App\Support;

use Illuminate\Http\Request;

class CheckoutEmbed
{
    public static function isEnabled(): bool
    {
        return (bool) config('getfy.checkout_embed.enabled', true);
    }

    public static function isEmbeddableRequest(Request $request): bool
    {
        if (! self::isEnabled()) {
            return false;
        }

        return $request->is('c/*')
            || $request->is('checkout/*')
            || $request->is('api-checkout')
            || $request->is('api-checkout/*')
            || $request->is('api/checkout/track');
    }
}
