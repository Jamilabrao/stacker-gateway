<?php

namespace App\Support;

use App\Models\GatewayCredential;

/**
 * Gateways cujo webhook inbound exige webhook_secret configurado (fail-closed).
 */
final class GatewayWebhookSecurityAlert
{
    /** @var list<string> */
    private const INBOUND_SECRET_SLUGS = ['asaas', 'pushinpay', 'spacepag', 'woovi'];

    /**
     * @return list<string> slugs conectados sem webhook_secret
     */
    public static function missingInboundSecretSlugs(?int $tenantId): array
    {
        $missing = [];
        foreach (self::INBOUND_SECRET_SLUGS as $slug) {
            $credential = GatewayCredential::resolveForPayment($tenantId, $slug);
            if (! $credential || ! $credential->is_connected) {
                continue;
            }
            if (GatewayInboundWebhookAuth::webhookSecret($slug, $tenantId) === null) {
                $missing[] = $slug;
            }
        }

        return $missing;
    }
}
