<?php

namespace Tests\Feature;

use App\Gateways\CajuPay\CajuPayDriver;
use App\Services\CajuPay\CajuPayWebhookBootstrapService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CajuPayWebhookBootstrapTest extends TestCase
{
    public function test_register_webhook_endpoint_idempotent_parses_register_response(): void
    {
        Http::fake([
            'https://api.cajupay.com.br/api/webhooks/endpoints/register' => Http::response([
                'endpoint' => ['id' => 'ep_abc', 'url' => 'https://app.test/webhooks/cajupay', 'enabled' => true],
                'created' => true,
                'already_exists' => false,
                'signing_secret' => 'cwhsec_from_register',
            ], 201),
        ]);

        $driver = app(CajuPayDriver::class);
        $result = $driver->registerWebhookEndpointIdempotent([
            'public_key' => 'gpk_test',
            'secret_key' => 'gsk_test',
        ], 'https://app.test/webhooks/gateways/cajupay');

        $this->assertSame('ep_abc', $result['endpoint_id']);
        $this->assertSame('cwhsec_from_register', $result['signing_secret']);
        $this->assertTrue($result['created']);
    }

    public function test_bootstrap_service_persists_signing_secret_from_register(): void
    {
        Http::fake([
            'https://api.cajupay.com.br/api/webhooks/endpoints/register' => Http::sequence()
                ->push([
                    'endpoint' => ['id' => 'ep_checkout'],
                    'created' => true,
                    'signing_secret' => 'cwhsec_bootstrapped',
                ], 201)
                ->push([
                    'endpoint' => ['id' => 'ep_payout'],
                    'created' => true,
                    'signing_secret' => 'pwhsec_bootstrapped',
                ], 201),
            'https://api.cajupay.com.br/api/webhooks/setup-status' => Http::response([
                'has_enabled_endpoint' => true,
                'subscribes_checkout_events' => true,
            ], 200),
        ]);

        $bootstrap = app(CajuPayWebhookBootstrapService::class);
        $result = $bootstrap->bootstrap([
            'public_key' => 'gpk_test',
            'secret_key' => 'gsk_test',
        ], false);

        $this->assertSame('cwhsec_bootstrapped', $result['credentials']['checkout_webhook_signing_secret']);
        $this->assertSame('ep_checkout', $result['credentials']['webhook_endpoint_id']);
        $this->assertSame('ep_payout', $result['credentials']['payout_webhook_endpoint_id']);
        $this->assertSame('pwhsec_bootstrapped', $result['credentials']['payout_webhook_signing_secret']);
        $this->assertTrue($result['setup_status']['has_enabled_endpoint'] ?? false);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/api/webhooks/endpoints/register')) {
                return false;
            }
            $body = $request->data();

            return ($body['event_types'] ?? null) === ['payout.*']
                || ($body['event_types'] ?? null) === ['checkout.payment.*', 'pix.payment.*'];
        });
    }
}
