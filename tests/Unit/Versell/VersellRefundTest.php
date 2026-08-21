<?php

namespace Tests\Unit\Versell;

use App\Gateways\Versell\VersellDriver;
use App\Models\GatewayCredential;
use App\Models\Order;
use App\Models\User;
use App\Services\OrderRefundGatewayBridge;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class VersellRefundTest extends TestCase
{
    private string $tmpDir;

    private string $cashInCert;

    private string $cashInKey;

    private string $cashOutCert;

    private string $cashOutKey;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'versell_rf_'.uniqid('', true);
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

        if (! config('app.key')) {
            config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        }
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

    public function test_refund_puts_devolucao_with_valor(): void
    {
        Http::fake([
            'api.pix.basspago.com.br/oauth/token' => Http::response([
                'access_token' => 'token-in',
                'expires_in' => 300,
            ], 200),
            'api.pix.basspago.com.br/pix/*/devolucao/*' => Http::response([
                'id' => 'o42rfnd',
                'rtrId' => 'D123',
                'valor' => '10.00',
                'status' => 'DEVOLVIDO',
            ], 201),
        ]);

        $driver = new VersellDriver();
        $result = $driver->refundTransaction(
            $this->sampleCredentials(),
            'E1234567820260820abcdef123456789',
            10.0,
            '42'
        );

        $this->assertTrue($result['success']);
        $this->assertFalse($result['pending'] ?? true);
        $this->assertNotEmpty($result['refund_id'] ?? null);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/devolucao/')) {
                return false;
            }
            $data = $request->data();

            return $request->method() === 'PUT'
                && str_contains($request->url(), '/pix/E1234567820260820abcdef123456789/devolucao/')
                && ($data['valor'] ?? null) === '10.00';
        });
    }

    public function test_refund_em_processamento_is_pending(): void
    {
        Http::fake([
            'api.pix.basspago.com.br/oauth/token' => Http::response([
                'access_token' => 'token-in',
                'expires_in' => 300,
            ], 200),
            'api.pix.basspago.com.br/pix/*/devolucao/*' => Http::response([
                'status' => 'EM_PROCESSAMENTO',
                'valor' => '5.00',
            ], 201),
        ]);

        $result = (new VersellDriver())->refundTransaction(
            $this->sampleCredentials(),
            'E999',
            5.0,
            '7'
        );

        $this->assertTrue($result['success']);
        $this->assertTrue($result['pending'] ?? false);
    }

    public function test_bridge_uses_metadata_e2eid(): void
    {
        Http::fake([
            'api.pix.basspago.com.br/oauth/token' => Http::response([
                'access_token' => 'token-in',
                'expires_in' => 300,
            ], 200),
            'api.pix.basspago.com.br/pix/*/devolucao/*' => Http::response([
                'status' => 'DEVOLVIDO',
                'valor' => '10.00',
            ], 201),
        ]);

        $cred = GatewayCredential::query()->firstOrNew([
            'tenant_id' => null,
            'gateway_slug' => 'versell',
        ]);
        $cred->is_connected = true;
        $cred->setEncryptedCredentials($this->sampleCredentials());
        $cred->save();

        $user = User::factory()->create(['tenant_id' => 1]);
        $product = $this->createTestProduct(['tenant_id' => 1]);
        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 10,
            'gateway' => 'versell',
            'gateway_id' => 'vsordertxid1234567890123456',
            'payment_method' => 'pix',
            'metadata' => [
                'versell_end_to_end_id' => 'E1234567820260820abcdef123456789',
            ],
        ]);

        $result = app(OrderRefundGatewayBridge::class)->tryRefund($order);

        $this->assertSame('gateway_ok', $result['status']);
        $order->refresh();
        $this->assertNotEmpty($order->metadata['versell_refund_id'] ?? null);
        $this->assertFalse((bool) ($order->metadata['versell_refund_pending'] ?? true));
    }

    public function test_bridge_resolves_e2eid_from_cob_when_missing_in_metadata(): void
    {
        Http::fake([
            'api.pix.basspago.com.br/oauth/token' => Http::response([
                'access_token' => 'token-in',
                'expires_in' => 300,
            ], 200),
            'api.pix.basspago.com.br/cob/vsordertxid1234567890123456' => Http::response([
                'txid' => 'vsordertxid1234567890123456',
                'status' => 'CONCLUIDA',
                'pix' => [[
                    'endToEndId' => 'EFROMCOB123456789012345678901',
                    'txid' => 'vsordertxid1234567890123456',
                    'valor' => '10.00',
                ]],
            ], 200),
            'api.pix.basspago.com.br/pix/*/devolucao/*' => Http::response([
                'status' => 'EM_PROCESSAMENTO',
                'valor' => '10.00',
            ], 201),
        ]);

        $cred = GatewayCredential::query()->firstOrNew([
            'tenant_id' => null,
            'gateway_slug' => 'versell',
        ]);
        $cred->is_connected = true;
        $cred->setEncryptedCredentials($this->sampleCredentials());
        $cred->save();

        $user = User::factory()->create(['tenant_id' => 1]);
        $product = $this->createTestProduct(['tenant_id' => 1]);
        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 10,
            'gateway' => 'versell',
            'gateway_id' => 'vsordertxid1234567890123456',
            'payment_method' => 'pix',
            'metadata' => [],
        ]);

        $result = app(OrderRefundGatewayBridge::class)->tryRefund($order);

        $this->assertSame('gateway_pending', $result['status']);
        $order->refresh();
        $this->assertSame('EFROMCOB123456789012345678901', $order->metadata['versell_end_to_end_id'] ?? null);
        $this->assertTrue((bool) ($order->metadata['versell_refund_pending'] ?? false));

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/pix/EFROMCOB123456789012345678901/devolucao/');
        });
    }

    public function test_bridge_fails_when_e2eid_unavailable(): void
    {
        Http::fake([
            'api.pix.basspago.com.br/oauth/token' => Http::response([
                'access_token' => 'token-in',
                'expires_in' => 300,
            ], 200),
            'api.pix.basspago.com.br/cob/*' => Http::response([
                'txid' => 'vsno-e2e',
                'status' => 'CONCLUIDA',
            ], 200),
        ]);

        $cred = GatewayCredential::query()->firstOrNew([
            'tenant_id' => null,
            'gateway_slug' => 'versell',
        ]);
        $cred->is_connected = true;
        $cred->setEncryptedCredentials($this->sampleCredentials());
        $cred->save();

        $user = User::factory()->create(['tenant_id' => 1]);
        $product = $this->createTestProduct(['tenant_id' => 1]);
        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 10,
            'gateway' => 'versell',
            'gateway_id' => 'vsno-e2e-txid-abcdefghijklm',
            'payment_method' => 'pix',
            'metadata' => [],
        ]);

        $result = app(OrderRefundGatewayBridge::class)->tryRefund($order);

        $this->assertSame('failed', $result['status']);
        $this->assertSame('missing_end_to_end_id', $result['error_code'] ?? null);
    }

    public function test_refund_missing_e2eid_on_driver(): void
    {
        $result = (new VersellDriver())->refundTransaction(
            $this->sampleCredentials(),
            '',
            10.0,
            '1'
        );

        $this->assertFalse($result['success']);
        $this->assertSame('missing_end_to_end_id', $result['error_code'] ?? null);
    }
}
