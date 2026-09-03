<?php

namespace Tests\Unit\Gateways;

use App\Gateways\GatewayRegistry;
use App\Gateways\PayPal\PayPalDriver;
use App\Services\PlatformPaymentMethods;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PayPalDriverTest extends TestCase
{
    public function test_paypal_is_registered_as_isolated_wallet_method(): void
    {
        $this->assertTrue(GatewayRegistry::isAllowedAcquirer('paypal'));
        $def = GatewayRegistry::get('paypal');
        $this->assertNotNull($def);
        $this->assertSame(['paypal'], $def['methods'] ?? []);
        $this->assertNotContains('pix', $def['methods'] ?? []);
        $this->assertNotContains('card', $def['methods'] ?? []);
        $this->assertInstanceOf(PayPalDriver::class, GatewayRegistry::driver('paypal'));
        $this->assertContains('paypal', PlatformPaymentMethods::METHOD_KEYS);
        $this->assertSame(['paypal'], config('gateways.default_order.paypal'));
        $this->assertNotContains('paypal', config('gateways.default_order.pix'));
        $this->assertNotContains('paypal', config('gateways.default_order.card'));
        $this->assertNotContains('paypal', config('gateways.default_order.boleto'));
    }

    public function test_create_paypal_order_uses_buttons_mode_without_card_source(): void
    {
        Http::fake([
            'https://api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response([
                'access_token' => 'tok_test',
                'expires_in' => 300,
            ]),
            'https://api-m.sandbox.paypal.com/v2/checkout/orders' => Http::response([
                'id' => 'PAYPAL-ORDER-1',
                'status' => 'CREATED',
            ], 201),
        ]);

        $driver = new PayPalDriver;
        $result = $driver->createPayPalOrder(
            ['client_id' => 'id', 'client_secret' => 'secret', 'sandbox' => true],
            10.5,
            'BRL',
            '42',
            ['name' => 'Ana Silva', 'email' => 'ana@example.com'],
            'Produto teste',
            'buttons'
        );

        $this->assertSame('PAYPAL-ORDER-1', $result['transaction_id']);
        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/v2/checkout/orders')) {
                return false;
            }
            $body = $request->data();

            return ($body['intent'] ?? '') === 'CAPTURE'
                && ($body['purchase_units'][0]['amount']['value'] ?? '') === '10.50'
                && ($body['purchase_units'][0]['amount']['currency_code'] ?? '') === 'BRL'
                && ! isset($body['payment_source']);
        });
    }

    public function test_create_pix_is_rejected(): void
    {
        $this->expectException(\RuntimeException::class);
        (new PayPalDriver)->createPixPayment(
            ['client_id' => 'id', 'client_secret' => 'secret'],
            10,
            [],
            '1',
            'https://example.test'
        );
    }

    public function test_create_boleto_is_rejected(): void
    {
        $this->expectException(\RuntimeException::class);
        (new PayPalDriver)->createBoletoPayment(
            ['client_id' => 'id', 'client_secret' => 'secret'],
            10,
            [],
            '1',
            'https://example.test'
        );
    }
}
