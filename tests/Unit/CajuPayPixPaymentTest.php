<?php

namespace Tests\Unit;

use App\Gateways\CajuPay\CajuPayDriver;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CajuPayPixPaymentTest extends TestCase
{
    public function test_create_pix_payment_returns_qr_and_copy_paste(): void
    {
        Http::fake([
            'https://api.cajupay.com.br/api/payments/pix' => function ($request) {
                $body = $request->data();
                $this->assertSame('https://example.test/webhook', $body['postback_url'] ?? null);
                $this->assertSame('https://loja.exemplo.com/checkout/42', $body['partner_checkout_url'] ?? null);

                return Http::response([
                    'payment_id' => 'pay_test_123',
                    'pix_copy_paste' => '00020126580014BR.GOV.BCB.PIX',
                    'pix_qr_code' => 'data:image/png;base64,abc',
                ], 201);
            },
        ]);

        $driver = new CajuPayDriver;
        $result = $driver->createPixPayment(
            ['public_key' => 'pk_test', 'secret_key' => 'sk_test'],
            99.90,
            [
                'name' => 'Maria Silva',
                'document' => '52998224725',
                'email' => 'maria@example.com',
                'phone' => '11999998888',
            ],
            '42',
            'https://example.test/webhook',
            ['partner_checkout_url' => 'https://loja.exemplo.com/checkout/42']
        );

        $this->assertSame('pay_test_123', $result['transaction_id']);
        $this->assertSame('00020126580014BR.GOV.BCB.PIX', $result['copy_paste']);
        $this->assertStringStartsWith('data:image', $result['qrcode']);
    }

    public function test_get_pix_payment_status_fetches_by_payment_id(): void
    {
        Http::fake([
            'https://api.cajupay.com.br/api/payments/pay_direct_99' => Http::response([
                'payment_id' => 'pay_direct_99',
                'status' => 'paid',
            ], 200),
        ]);

        $driver = new CajuPayDriver;
        $status = $driver->getPixPaymentStatus('pay_direct_99', [
            'public_key' => 'pk_test',
            'secret_key' => 'sk_test',
        ]);

        $this->assertSame('paid', $status);
    }

    public function test_get_transaction_status_uses_direct_payment_lookup(): void
    {
        Http::fake([
            'https://api.cajupay.com.br/api/payments/pay_status_1' => Http::response([
                'payment_id' => 'pay_status_1',
                'status' => 'paid',
            ], 200),
        ]);

        $driver = new CajuPayDriver;
        $status = $driver->getTransactionStatus('pay_status_1', [
            'public_key' => 'pk_test',
            'secret_key' => 'sk_test',
        ]);

        $this->assertSame('paid', $status);
    }

    public function test_create_pix_payment_fails_when_response_has_no_pix_payload(): void
    {
        Http::fake([
            'https://api.cajupay.com.br/api/payments/pix' => Http::response([
                'payment_id' => 'pay_empty',
            ], 201),
        ]);

        $driver = new CajuPayDriver;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('PIX criado sem código de pagamento');

        $driver->createPixPayment(
            ['public_key' => 'pk_test', 'secret_key' => 'sk_test'],
            10.0,
            ['name' => 'João', 'document' => '52998224725', 'email' => 'joao@example.com'],
            '99',
            ''
        );
    }

    public function test_create_pix_payment_uses_friendly_message_on_api_error(): void
    {
        Http::fake([
            'https://api.cajupay.com.br/api/payments/pix' => Http::response([
                'message' => 'Invalid consumer document',
            ], 422),
        ]);

        $driver = new CajuPayDriver;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('CPF ou CNPJ inválido');

        $driver->createPixPayment(
            ['public_key' => 'pk_test', 'secret_key' => 'sk_test'],
            10.0,
            ['name' => 'João', 'document' => '52998224725', 'email' => 'joao@example.com'],
            '100',
            ''
        );
    }

    public function test_get_sdk_session_status_prefers_payment_status_over_active_session(): void
    {
        Http::fake([
            'https://api.cajupay.com.br/api/sdk/public/checkout/sessions/*' => Http::response([
                'status' => 'active',
                'payment_status' => 'paid',
            ], 200),
        ]);

        $driver = new CajuPayDriver;
        $status = $driver->getSdkSessionStatus('tok_sess_very_long_token_abcdef', [
            'public_key' => 'pk_test',
            'secret_key' => 'sk_test',
        ]);

        $this->assertSame('paid', $status);
    }

    public function test_get_sdk_session_status_maps_active_session_to_pending(): void
    {
        Http::fake([
            'https://api.cajupay.com.br/api/sdk/public/checkout/sessions/*' => Http::response([
                'status' => 'active',
            ], 200),
        ]);

        $driver = new CajuPayDriver;
        $status = $driver->getSdkSessionStatus('tok_sess_very_long_token_abcdef', [
            'public_key' => 'pk_test',
            'secret_key' => 'sk_test',
        ]);

        $this->assertSame('pending', $status);
    }

    public function test_get_transaction_status_uuid_falls_through_to_pix_api_when_sdk_not_paid(): void
    {
        $paymentId = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';

        Http::fake([
            'https://api.cajupay.com.br/api/sdk/public/checkout/sessions/*' => Http::response([
                'status' => 'active',
                'payment_status' => 'pending',
            ], 200),
            "https://api.cajupay.com.br/api/payments/{$paymentId}" => Http::response([
                'payment_id' => $paymentId,
                'status' => 'paid',
            ], 200),
        ]);

        $driver = new CajuPayDriver;
        $status = $driver->getTransactionStatus($paymentId, [
            'public_key' => 'pk_test',
            'secret_key' => 'sk_test',
        ]);

        $this->assertSame('paid', $status);
    }
}
