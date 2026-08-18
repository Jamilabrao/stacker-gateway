<?php

namespace Tests\Concerns;

use App\Models\GatewayCredential;

trait InteractsWithGatewayWebhooks
{
    protected function seedInboundWebhookSecret(string $gatewaySlug, string $secret = 'test-webhook-secret', ?int $tenantId = null, array $extraCredentials = []): GatewayCredential
    {
        $cred = GatewayCredential::query()->firstOrNew([
            'tenant_id' => $tenantId,
            'gateway_slug' => $gatewaySlug,
        ]);
        $cred->is_connected = true;
        $cred->setEncryptedCredentials(array_merge(['webhook_secret' => $secret], $extraCredentials));
        $cred->save();

        return $cred;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function postSignedGatewayWebhook(string $uri, array $payload, string $secret): \Illuminate\Testing\TestResponse
    {
        $raw = json_encode($payload, JSON_THROW_ON_ERROR);
        $signature = 'sha256='.hash_hmac('sha256', $raw, $secret);

        return $this->call('POST', $uri, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_WEBHOOK_SIGNATURE' => $signature,
        ], $raw);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function postSignedWooviWebhook(array $payload, string $secret): \Illuminate\Testing\TestResponse
    {
        $raw = json_encode($payload, JSON_THROW_ON_ERROR);

        return $this->call('POST', '/webhooks/gateways/woovi', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => $secret,
        ], $raw);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
        protected function postSignedBspayWebhook(array $payload, string $secret, ?int $timestamp = null, string $event = 'cashin.confirmed'): \Illuminate\Testing\TestResponse
        {
            $raw = json_encode($payload, JSON_THROW_ON_ERROR);
            $ts = (string) ($timestamp ?? time());
            $signature = hash_hmac('sha256', $raw, $secret);

            return $this->call('POST', '/webhooks/gateways/bspay', [], [], [], [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_BSPAY_EVENT' => $event,
                'HTTP_X_BSPAY_SIGNATURE' => $signature,
                'HTTP_X_BSPAY_TIMESTAMP' => $ts,
            ], $raw);
        }
}
