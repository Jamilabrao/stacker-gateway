<?php

namespace App\Support;

use Illuminate\Support\Facades\Route;

final class GatewayWebhookUrl
{
    public static function forGateway(string $gatewaySlug): string
    {
        $publicBase = trim((string) (config('getfy.webhook_public_url') ?? ''));
        $path = self::pathForGateway($gatewaySlug);

        if ($publicBase !== '') {
            return rtrim($publicBase, '/').$path;
        }

        $routeName = 'webhooks.'.$gatewaySlug;
        if (Route::has($routeName)) {
            return route($routeName);
        }

        return url($path);
    }

    public static function pathForGateway(string $gatewaySlug): string
    {
        return match ($gatewaySlug) {
            'efi' => '/webhooks/gateways/efi/pix',
            'spacepag' => '/webhooks/gateways/spacepag',
            default => '/webhooks/gateways/'.$gatewaySlug,
        };
    }
}
