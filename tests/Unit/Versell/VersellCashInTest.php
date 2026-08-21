<?php

namespace Tests\Unit\Versell;

use App\Gateways\Versell\VersellCredentials;
use App\Gateways\Versell\VersellDriver;
use App\Http\Controllers\Webhooks\VersellWebhookController;
use App\Jobs\ProcessPaymentWebhook;
use App\Models\Order;
use App\Models\User;
use App\Services\Versell\VersellWebhookBootstrapService;
use App\Support\GatewayApiCredentials;
use App\Support\GatewayWebhookUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class VersellCashInTest extends TestCase
{
    private string $tmpDir;

    private string $cashInCert;

    private string $cashInKey;

    private string $cashOutCert;

    private string $cashOutKey;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'versell_ci_'.uniqid('', true);
        mkdir($this->tmpDir, 0700, true);

        $this->cashInCert = $this->tmpDir.DIRECTORY_SEPARATOR.'cash_in.crt';
        $this->cashInKey = $this->tmpDir.DIRECTORY_SEPARATOR.'cash_in.key';
        $this->cashOutCert = $this->tmpDir.DIRECTORY_SEPARATOR.'cash_out.crt';
        $this->cashOutKey = $this->tmpDir.DIRECTORY_SEPARATOR.'cash_out.key';

        file_put_contents($this->cashInCert, "-----BEGIN CERTIFICATE-----\nIN\n-----END CERTIFICATE-----\n");
        file_put_contents($this->cashInKey, "-----BEGIN PRIVATE KEY-----\nIN\n-----END PRIVATE KEY-----\n");
        file_put_contents($this->cashOutCert, "-----BEGIN CERTIFICATE-----\nOUT\n-----END CERTIFICATE-----\n");
        file_put_contents($this->cashOutKey, "-----BEGIN PRIVATE KEY-----\nOUT\n-----END PRIVATE KEY-----\n");

        Cache::flush();
        config(['getfy.api.inbound_webhooks_async' => false]);
    }

    protected function tearDown(): void
    {
        foreach ([$this->cashInCert, $this->cashInKey, $this->cashOutCert, $this->cashOutKey] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        if (is_dir($this->tmpDir)) {
            @rmdir($this->tmpDir);
        }

        parent::tearDown();
    }

    /**
     * @return array{cash_in: array<string, mixed>, cash_out: array<string, mixed>}
     */
    private function sampleCredentials(): array
    {
        return [
            'cash_in' => [
                'client_id' => 'ci_client',
                'client_secret' => 'ci_secret',
                'certificate_path' => $this->cashInCert,
                'private_key_path' => $this->cashInKey,
                'pix_key' => 'pix@versell.test',
            ],
            'cash_out' => [
                'client_id' => 'co_client',
                'client_secret' => 'co_secret',
                'certificate_path' => $this->cashOutCert,
                'private_key_path' => $this->cashOutKey,
            ],
        ];
    }

    public function test_create_pix_payment_puts_cob_and_returns_emv(): void
    {
        Http::fake([
            'api.pix.basspago.com.br/oauth/token' => Http::response([
                'access_token' => 'token-in',
                'expires_in' => 300,
            ], 200),
            'api.pix.basspago.com.br/cob/*' => Http::response([
                'txid' => 'vs1234567890abcdefghijklmnop',
                'status' => 'ATIVA',
                'location' => 'pix.example/qr/abc',
                'pixCopiaECola' => '00020126580014br.gov.bcb.pix0136pix@versell.test52040000530398654041.005802BR',
            ], 201),
        ]);

        $driver = new VersellDriver();
        $result = $driver->createPixPayment(
            $this->sampleCredentials(),
            1.00,
            ['name' => 'Cliente Teste', 'document' => '12345678909', 'email' => 'c@test.com'],
            '42',
            'https://example.test/webhooks/gateways/versell'
        );

        $this->assertSame('vs1234567890abcdefghijklmnop', $result['transaction_id']);
        $this->assertNotEmpty($result['copy_paste']);
        $this->assertStringContainsString('br.gov.bcb.pix', (string) $result['copy_paste']);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/cob/')) {
                return false;
            }
            $data = $request->data();

            return $request->method() === 'PUT'
                && ($data['chave'] ?? null) === 'pix@versell.test'
                && ($data['valor']['original'] ?? null) === '1.00'
                && ($data['devedor']['cpf'] ?? null) === '12345678909';
        });
    }

    public function test_get_transaction_status_maps_concluida_to_paid(): void
    {
        Http::fake([
            'api.pix.basspago.com.br/oauth/token' => Http::response([
                'access_token' => 'token-in',
                'expires_in' => 300,
            ], 200),
            'api.pix.basspago.com.br/cob/txid-paid' => Http::response([
                'txid' => 'txid-paid',
                'status' => 'CONCLUIDA',
            ], 200),
            'api.pix.basspago.com.br/cob/txid-open' => Http::response([
                'txid' => 'txid-open',
                'status' => 'ATIVA',
            ], 200),
            'api.pix.basspago.com.br/cob/txid-removed' => Http::response([
                'txid' => 'txid-removed',
                'status' => 'REMOVIDA_PELO_PSP',
            ], 200),
        ]);

        $driver = new VersellDriver();
        $creds = $this->sampleCredentials();

        $this->assertSame('paid', $driver->getTransactionStatus('txid-paid', $creds));
        $this->assertSame('pending', $driver->getTransactionStatus('txid-open', $creds));
        $this->assertSame('cancelled', $driver->getTransactionStatus('txid-removed', $creds));
    }

    public function test_cash_in_ready_requires_pix_key_and_mtls(): void
    {
        $creds = $this->sampleCredentials();
        $this->assertTrue(VersellCredentials::isCashInReady($creds));
        $this->assertTrue(GatewayApiCredentials::isReadyForGateway('versell', $creds));

        $creds['cash_in']['pix_key'] = '';
        $this->assertFalse(VersellCredentials::isCashInReady($creds));
        $this->assertFalse(GatewayApiCredentials::isReadyForGateway('versell', $creds));
    }

    public function test_versell_is_in_default_pix_order(): void
    {
        $order = config('gateways.default_order.pix', []);
        $this->assertTrue(in_array('versell', $order, true));
    }

    public function test_webhook_url_base_does_not_include_pix_suffix(): void
    {
        config([
            'app.url' => 'https://pay.exemplo.com',
            'getfy.webhook_public_url' => 'https://pay.exemplo.com',
        ]);

        $this->assertSame(
            'https://pay.exemplo.com/webhooks/gateways/versell',
            GatewayWebhookUrl::forGateway('versell')
        );
        $this->assertSame(
            'https://pay.exemplo.com/webhooks/gateways/versell/pix',
            GatewayWebhookUrl::forGateway('versell.pix')
        );
    }

    public function test_webhook_pix_persists_e2eid_and_dispatches_paid_job(): void
    {
        Bus::fake([ProcessPaymentWebhook::class]);

        $user = User::factory()->create(['tenant_id' => 1]);
        $product = $this->createTestProduct(['tenant_id' => 1]);
        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'gateway' => 'versell',
            'gateway_id' => 'vsordertxid1234567890123456',
            'status' => 'pending',
            'amount' => 10,
            'payment_method' => 'pix',
            'metadata' => [],
        ]);

        $request = Request::create('/webhooks/gateways/versell/pix', 'POST', [
            'pix' => [[
                'endToEndId' => 'E1234567820260820abcdef123456789',
                'txid' => 'vsordertxid1234567890123456',
                'chave' => 'pix@versell.test',
                'valor' => '10.00',
                'horario' => '2026-08-20T12:00:00.000Z',
            ]],
        ]);

        $response = (new VersellWebhookController())->pix($request);
        $this->assertSame(200, $response->getStatusCode());

        $order->refresh();
        $this->assertSame('E1234567820260820abcdef123456789', $order->metadata['versell_end_to_end_id'] ?? null);

        Bus::assertDispatched(ProcessPaymentWebhook::class, function (ProcessPaymentWebhook $job) {
            return $job->gatewaySlug === 'versell'
                && $job->transactionId === 'vsordertxid1234567890123456'
                && $job->status === 'paid';
        });
    }

    public function test_webhook_with_devolucao_dispatches_refunded(): void
    {
        Bus::fake([ProcessPaymentWebhook::class]);

        $user = User::factory()->create(['tenant_id' => 1]);
        $product = $this->createTestProduct(['tenant_id' => 1]);
        Order::create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'gateway' => 'versell',
            'gateway_id' => 'vsrefundtxid123456789012345',
            'status' => 'completed',
            'amount' => 10,
            'payment_method' => 'pix',
            'metadata' => [],
        ]);

        $request = Request::create('/webhooks/gateways/versell/pix', 'POST', [
            'pix' => [[
                'endToEndId' => 'E999',
                'txid' => 'vsrefundtxid123456789012345',
                'valor' => '10.00',
                'devolucoes' => [[
                    'id' => 'D1',
                    'status' => 'DEVOLVIDO',
                    'valor' => '10.00',
                ]],
            ]],
        ]);

        (new VersellWebhookController())->pix($request);

        Bus::assertDispatched(ProcessPaymentWebhook::class, function (ProcessPaymentWebhook $job) {
            return $job->status === 'refunded' && $job->event === 'order.refunded';
        });
    }

    public function test_create_pix_missing_emv_fails_friendly(): void
    {
        Http::fake([
            'api.pix.basspago.com.br/oauth/token' => Http::response([
                'access_token' => 'token-in',
                'expires_in' => 300,
            ], 200),
            'api.pix.basspago.com.br/cob/*' => Http::response([
                'txid' => 'vs1234567890abcdefghijklmnop',
                'status' => 'ATIVA',
            ], 201),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('pixCopiaECola');

        (new VersellDriver())->createPixPayment(
            $this->sampleCredentials(),
            5.0,
            ['name' => 'A', 'document' => '12345678909', 'email' => 'a@b.com'],
            '7',
            'https://example.test/hook'
        );
    }

    public function test_webhook_bootstrap_registers_put_webhook(): void
    {
        config([
            'app.url' => 'https://pay.exemplo.com',
            'getfy.webhook_public_url' => 'https://pay.exemplo.com',
        ]);

        Http::fake([
            'api.pix.basspago.com.br/oauth/token' => Http::response([
                'access_token' => 'token-in',
                'expires_in' => 300,
            ], 200),
            'api.pix.basspago.com.br/webhook/*' => Http::response([
                'webhookUrl' => 'https://pay.exemplo.com/webhooks/gateways/versell',
            ], 200),
            'pagamentos.basspago.com.br/api/v2/oauth/token' => Http::response([
                'access_token' => 'token-out',
                'expires_in' => 3600,
            ], 200),
            'pagamentos.basspago.com.br/api/v2/webhooks/*' => Http::response(['id' => 'wh-1'], 201),
            'api.pix.basspago.com.br/webhookrec' => Http::response(['webhookUrl' => 'https://pay.exemplo.com/webhooks/gateways/versell/pix-automatico'], 200),
            'api.pix.basspago.com.br/webhookcobr' => Http::response(['webhookUrl' => 'https://pay.exemplo.com/webhooks/gateways/versell/pix-automatico'], 200),
        ]);

        $result = app(VersellWebhookBootstrapService::class)->bootstrap($this->sampleCredentials());

        $this->assertTrue($result['ok']);
        $this->assertNull($result['warning']);

        Http::assertSent(function ($request) {
            return $request->method() === 'PUT'
                && str_contains($request->url(), '/webhook/')
                && ($request['webhookUrl'] ?? null) === 'https://pay.exemplo.com/webhooks/gateways/versell';
        });
        Http::assertSent(fn ($request) => $request->method() === 'POST' && str_contains($request->url(), '/webhooks/transfer'));
        Http::assertSent(fn ($request) => $request->method() === 'POST' && str_contains($request->url(), '/webhooks/cashout'));
    }
}
