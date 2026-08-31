<?php

namespace Tests\Unit\Cielo;

use App\Gateways\Cielo\CieloDriver;
use App\Gateways\GatewayRegistry;
use App\Support\CardInstallments;
use App\Support\GatewayApiCredentials;
use App\Support\GatewayWebhookUrl;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CieloDriverTest extends TestCase
{
    public function test_cielo_is_registered_at_end_of_redundancy_order(): void
    {
        $this->assertTrue(GatewayRegistry::isAllowedAcquirer('cielo'));
        $def = GatewayRegistry::get('cielo');
        $this->assertNotNull($def);
        $this->assertContains('pix', $def['methods'] ?? []);
        $this->assertContains('card', $def['methods'] ?? []);
        $this->assertInstanceOf(CieloDriver::class, GatewayRegistry::driver('cielo'));

        $pixOrder = config('gateways.default_order.pix', []);
        $cardOrder = config('gateways.default_order.card', []);
        $this->assertContains('cielo', $pixOrder);
        $this->assertContains('cielo', $cardOrder);
        $this->assertSame('cajupay', $pixOrder[0] ?? null);
        $this->assertSame('cielo', $pixOrder[array_key_last($pixOrder)]);
        $this->assertSame('cielo', $cardOrder[array_key_last($cardOrder)]);
        $this->assertTrue(CardInstallments::gatewaySupports('cielo'));
        $this->assertTrue(GatewayApiCredentials::isReadyForGateway('cielo', [
            'merchant_id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'merchant_key' => str_repeat('A', 40),
        ]));
        $this->assertFalse(GatewayApiCredentials::isReadyForGateway('cielo', [
            'merchant_id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
        ]));
    }

    public function test_webhook_url_is_public(): void
    {
        config([
            'app.url' => 'http://localhost:8085',
            'getfy.webhook_public_url' => 'https://pay.exemplo.com',
        ]);

        $this->assertSame(
            'https://pay.exemplo.com/webhooks/gateways/cielo',
            GatewayWebhookUrl::forGateway('cielo')
        );
    }

    public function test_create_pix_uses_cielo2_and_maps_qr(): void
    {
        config(['getfy.webhook_public_url' => 'https://pay.exemplo.com']);

        $paymentId = 'b8c1b2ea-e06a-4135-9389-8bdbdccacd20';
        Http::fake([
            'api.cieloecommerce.cielo.com.br/*' => Http::response([
                'MerchantOrderId' => '42',
                'Payment' => [
                    'PaymentId' => $paymentId,
                    'Type' => 'Pix',
                    'Provider' => 'Cielo2',
                    'Status' => 12,
                    'Amount' => 1000,
                    'QrCodeString' => '00020101021226880014br.gov.bcb.pix',
                    'QrCodeBase64Image' => 'iVBORw0KGgo=',
                    'SentOrderId' => 'txid-cielo-1',
                    'ReturnCode' => '0',
                    'ReturnMessage' => 'QRCode gerado com sucesso',
                ],
            ], 201),
        ]);

        $driver = new CieloDriver;
        $result = $driver->createPixPayment(
            $this->credentials(),
            10.00,
            ['name' => 'Aline Souza', 'document' => '12345678909', 'email' => 'a@b.com'],
            '42',
            'https://pay.exemplo.com/webhooks/gateways/cielo'
        );

        $this->assertSame($paymentId, $result['transaction_id']);
        $this->assertSame('00020101021226880014br.gov.bcb.pix', $result['copy_paste']);
        $this->assertSame('iVBORw0KGgo=', $result['qrcode']);
        $this->assertSame('txid-cielo-1', $result['metadata']['cielo_txid']);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'api.cieloecommerce.cielo.com.br/1/sales') || $request->method() !== 'POST') {
                return false;
            }
            $body = $request->data();

            return ($body['Payment']['Provider'] ?? null) === 'Cielo2'
                && ($body['Payment']['Type'] ?? null) === 'Pix'
                && ($body['Payment']['Amount'] ?? null) === 1000
                && ($body['MerchantOrderId'] ?? null) === '42'
                && ($body['Customer']['Name'] ?? null) === 'Aline Souza'
                && $request->hasHeader('MerchantId')
                && $request->hasHeader('MerchantKey');
        });
    }

    public function test_create_pix_maps_cielo_ecommerce_field_aliases(): void
    {
        $paymentId = '1997be4d-694a-472e-98f0-e7f4b4c8f1e7';
        Http::fake([
            'api.cieloecommerce.cielo.com.br/*' => Http::response([
                'MerchantOrderId' => '42',
                'Payment' => [
                    'Paymentid' => $paymentId,
                    'Type' => 'Pix',
                    'QrcodeBase64Image' => 'iVBORw0KGgo=',
                    'QrCodeString' => '00020101021226880014br.gov.bcb.pix',
                    'Status' => 12,
                ],
            ], 201),
        ]);

        $result = (new CieloDriver)->createPixPayment(
            $this->credentials(),
            10.00,
            ['name' => 'Jose Silva 12', 'document' => '12345678909', 'email' => 'a@b.com'],
            '42',
            'https://pay.exemplo.com/webhooks/gateways/cielo'
        );

        $this->assertSame($paymentId, $result['transaction_id']);
        $this->assertSame('00020101021226880014br.gov.bcb.pix', $result['copy_paste']);
        $this->assertSame('iVBORw0KGgo=', $result['qrcode']);

        Http::assertSent(function ($request) {
            $body = $request->data();

            return ($body['Customer']['Name'] ?? null) === 'Jose Silva';
        });
    }

    public function test_create_card_sends_payment_token_and_capture_true(): void
    {
        $paymentId = '11111111-2222-3333-4444-555555555555';
        Http::fake([
            'apisandbox.cieloecommerce.cielo.com.br/1/sales' => Http::response([
                'MerchantOrderId' => '99',
                'Payment' => [
                    'PaymentId' => $paymentId,
                    'Status' => 2,
                    'Amount' => 1500,
                    'Tid' => 'tid-1',
                    'AuthorizationCode' => '123456',
                    'ReturnMessage' => 'Operation Successful',
                ],
            ], 201),
        ]);

        $driver = new CieloDriver;
        $result = $driver->createCardPayment(
            $this->credentials(),
            15.00,
            ['name' => 'Aline Souza', 'document' => '12345678909', 'email' => 'a@b.com'],
            '99',
            [
                'payment_token' => json_encode([
                    'payment_token' => 'eedcb896-40e1-465b-b34c-6d1119dbb6cf',
                    'brand' => 'Visa',
                    'installments' => 3,
                ]),
            ]
        );

        $this->assertSame($paymentId, $result['transaction_id']);
        $this->assertSame('paid', $result['status']);

        Http::assertSent(function ($request) {
            $body = $request->data();

            return ($body['Payment']['Capture'] ?? null) === true
                && ($body['Payment']['Installments'] ?? null) === 3
                && ($body['Payment']['Interest'] ?? null) === 'ByMerchant'
                && ($body['Payment']['CreditCard']['PaymentToken'] ?? null) === 'eedcb896-40e1-465b-b34c-6d1119dbb6cf'
                && ! isset($body['Payment']['CreditCard']['CardNumber']);
        });
    }

    public function test_denied_card_throws(): void
    {
        Http::fake([
            'apisandbox.cieloecommerce.cielo.com.br/1/sales' => Http::response([
                'Payment' => [
                    'PaymentId' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
                    'Status' => 3,
                    'ReturnMessage' => 'Not Authorized',
                ],
            ], 201),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Not Authorized');

        (new CieloDriver)->createCardPayment(
            $this->credentials(),
            10,
            ['name' => 'Aline', 'document' => '12345678909', 'email' => 'a@b.com'],
            '1',
            ['payment_token' => 'eedcb896-40e1-465b-b34c-6d1119dbb6cf']
        );
    }

    public function test_status_mapper(): void
    {
        $driver = new CieloDriver;
        $this->assertSame('pending', $driver->mapPaymentStatus(12));
        $this->assertSame('pending', $driver->mapPaymentStatus(1));
        $this->assertSame('paid', $driver->mapPaymentStatus(2));
        $this->assertSame('cancelled', $driver->mapPaymentStatus(3));
        $this->assertSame('cancelled', $driver->mapPaymentStatus(10));
        $this->assertSame('cancelled', $driver->mapPaymentStatus(11));
        $this->assertSame('cancelled', $driver->mapPaymentStatus(13));
    }

    public function test_test_connection_rejects_401(): void
    {
        Http::fake([
            'apiquerysandbox.cieloecommerce.cielo.com.br/*' => Http::response([], 401),
        ]);

        $this->assertFalse((new CieloDriver)->testConnection($this->credentials()));
    }

    public function test_refund_marks_scheduled_as_pending(): void
    {
        Http::fake([
            'apisandbox.cieloecommerce.cielo.com.br/1/sales/*/void*' => Http::response([
                'Status' => 2,
                'ReasonMessage' => 'Scheduled',
                'ReturnMessage' => 'Devolução solicitada com sucesso',
            ], 200),
        ]);

        $result = (new CieloDriver)->refundTransaction($this->credentials(), 'pay-1', 10.00, '42');
        $this->assertTrue($result['success']);
        $this->assertTrue($result['pending']);
    }

    /**
     * @return array<string, mixed>
     */
    private function credentials(): array
    {
        return [
            'merchant_id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'merchant_key' => str_repeat('K', 40),
            'sandbox' => true,
        ];
    }
}
