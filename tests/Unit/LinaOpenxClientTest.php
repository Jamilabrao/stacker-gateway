<?php

namespace Tests\Unit;

use App\Services\LinaOpenx\LinaOpenxClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LinaOpenxClientTest extends TestCase
{
    public function test_normalize_statuses_paid_and_rejected(): void
    {
        $client = new LinaOpenxClient;

        $this->assertSame('paid', $client->normalizeStatuses('CONSUMIDO', 'PAGO'));
        $this->assertSame('paid', $client->normalizeStatuses('CONSUMIDO', 'ACSC'));
        $this->assertSame('paid', $client->normalizeStatuses('CONSUMIDO', 'ACSP'));
        $this->assertSame('rejected', $client->normalizeStatuses('CONSUMIDO', 'REJEITADO'));
        $this->assertSame('cancelled', $client->normalizeStatuses('EXPIRADO', null));
        $this->assertSame('pending', $client->normalizeStatuses('PENDENTE', null));
        $this->assertSame('pending', $client->normalizeStatuses('CONSUMIDO', 'PENDENTE'));
        $this->assertSame('paid', $client->normalizeStatuses('CONSUMIDO', ''));
    }

    public function test_oauth_token_is_cached(): void
    {
        Cache::flush();
        Http::fake([
            'iam.linaob.com.br/*' => Http::response([
                'access_token' => 'test-token-abc',
                'expires_in' => 900,
                'token_type' => 'Bearer',
            ], 200),
            'embedded-payment-manager.linaob.com.br/api/v1/sub-tenants' => Http::response(['id' => 'st-1'], 200),
        ]);

        $credentials = [
            'client_id' => 'cid',
            'client_secret' => 'csecret',
            'sandbox' => false,
        ];
        $client = new LinaOpenxClient;
        $this->assertTrue($client->testConnection($credentials));
        $this->assertTrue($client->testConnection($credentials));

        Http::assertSentCount(3); // 1 token + 2 sub-tenants (token cached on second)
    }

    public function test_create_white_label_payment_returns_redirect(): void
    {
        Cache::flush();
        Http::fake([
            'iam.linaob.com.br/*' => Http::response([
                'access_token' => 'tok',
                'expires_in' => 900,
            ], 200),
            'embedded-payment-manager.linaob.com.br/api/v1/payments' => Http::response([
                'data' => [
                    'id' => 'pr-uuid-1',
                    'redirectUrl' => 'https://portal.linaopenx.com.br/pay/pr-uuid-1',
                ],
            ], 201),
        ]);

        $client = new LinaOpenxClient;
        $result = $client->createWhiteLabelPayment(
            [
                'client_id' => 'c',
                'client_secret' => 's',
                'sandbox' => false,
                'creditor_name' => 'Recebedor Teste',
                'creditor_cpf_cnpj' => '12345678901',
                'creditor_ispb' => '00000000',
                'creditor_issuer' => '0001',
                'creditor_number' => '123456',
                'creditor_account_type' => 'CACC',
            ],
            49.90,
            ['name' => 'Cliente Teste', 'document' => '12345678901', 'email' => 'a@b.com'],
            '99',
            'https://example.com/checkout/lina/return/99'
        );

        $this->assertSame('pr-uuid-1', $result['transaction_id']);
        $this->assertStringContainsString('portal.linaopenx.com.br', $result['redirect_url']);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/api/v1/payments')) {
                return true;
            }
            $data = $request->data();

            return ($data['value'] ?? null) === 49.9
                && ($data['cpfCnpj'] ?? null) === '12345678901'
                && ($data['creditor']['personType'] ?? null) === 'PESSOA_NATURAL'
                && ($data['creditor']['accountIspb'] ?? null) === '00000000'
                && ($data['creditor']['accountNumber'] ?? null) === '123456'
                && ! isset($data['creditor']['account']);
        });
    }

    public function test_create_requires_creditor_fields(): void
    {
        Cache::flush();
        Http::fake([
            'iam.linaob.com.br/*' => Http::response(['access_token' => 'tok', 'expires_in' => 900], 200),
        ]);

        $client = new LinaOpenxClient;
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('preencha os dados do credor');

        $client->createWhiteLabelPayment(
            ['client_id' => 'c', 'client_secret' => 's', 'sandbox' => false],
            10.0,
            ['name' => 'Cliente', 'document' => '12345678901', 'email' => 'a@b.com'],
            '1',
            'https://example.com/return'
        );
    }

    public function test_get_payment_request_maps_pago(): void
    {
        Cache::flush();
        Http::fake([
            'iam.linaob.com.br/*' => Http::response(['access_token' => 'tok', 'expires_in' => 900], 200),
            'embedded-payment-manager.linaob.com.br/api/v1/payments/requests/*' => Http::response([
                'status' => 'CONSUMIDO',
                'payments' => [
                    ['status' => 'PAGO'],
                ],
            ], 200),
        ]);

        $client = new LinaOpenxClient;
        $result = $client->getPaymentRequest(
            ['client_id' => 'c', 'client_secret' => 's', 'sandbox' => false],
            'pr-1'
        );

        $this->assertSame('paid', $result['status']);
    }
}
