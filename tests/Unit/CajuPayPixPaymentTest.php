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
            'https://api.cajupay.com.br/api/payments/pix' => Http::response([
                'payment_id' => 'pay_test_123',
                'pix_copy_paste' => '00020126580014BR.GOV.BCB.PIX',
                'pix_qr_code' => 'data:image/png;base64,abc',
            ], 201),
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
            'https://example.test/webhook'
        );

        $this->assertSame('pay_test_123', $result['transaction_id']);
        $this->assertSame('00020126580014BR.GOV.BCB.PIX', $result['copy_paste']);
        $this->assertStringStartsWith('data:image', $result['qrcode']);
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
}
