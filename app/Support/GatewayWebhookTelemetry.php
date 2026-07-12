<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

final class GatewayWebhookTelemetry
{
    private const CACHE_PREFIX = 'gateway_webhook_last_received:';

    public static function record(string $gatewaySlug): void
    {
        $slug = trim($gatewaySlug);
        if ($slug === '') {
            return;
        }

        Cache::put(
            self::CACHE_PREFIX.$slug,
            now()->toIso8601String(),
            now()->addDays(14)
        );
    }

    public static function lastReceivedAt(string $gatewaySlug): ?string
    {
        $slug = trim($gatewaySlug);
        if ($slug === '') {
            return null;
        }

        $value = Cache::get(self::CACHE_PREFIX.$slug);

        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
