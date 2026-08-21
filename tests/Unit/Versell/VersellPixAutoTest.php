<?php

namespace Tests\Unit\Versell;

use App\Http\Controllers\Webhooks\VersellWebhookController;
use App\Jobs\ProcessPaymentWebhook;
use App\Models\Order;
use App\Models\User;
use App\Services\Versell\VersellPixRecorrenteService;
use App\Support\GatewayWebhookUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class VersellPixAutoTest extends TestCase
{
    private string $tmpDir;

    private string $cashInCert;

    private string $cashInKey;

    private string $cashOutCert;

    private string $cashOutKey;

    protected function setUp(): void
    {
        parent::setUp();

        if (! config('app.key')) {
            config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        }

        $this->tmpDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'versell_pa_'.uniqid('', true);
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
     * @return array<string, mixed>
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

    public function test_config_includes_pix_auto(): void
    {
        $methods = config('gateways.gateways.versell.methods', []);
        $this->assertContains('pix_auto', $methods);
        $order = config('gateways.default_order.pix_auto', []);
        $this->assertContains('versell', $order);
    }

    public function test_webhook_urls_pix_auto(): void
    {
        config([
            'app.url' => 'https://pay.exemplo.com',
            'getfy.webhook_public_url' => 'https://pay.exemplo.com',
        ]);

        $this->assertSame(
            'https://pay.exemplo.com/webhooks/gateways/versell/pix-automatico',
            GatewayWebhookUrl::forGateway('versell.pix_auto')
        );
        $this->assertSame(
            'https://pay.exemplo.com/webhooks/gateways/versell/pix-automatico/rec',
            GatewayWebhookUrl::forGateway('versell.pix_auto.rec')
        );
    }

    public function test_jornada3_flow_creates_loc_cob_rec(): void
    {
        Http::fake([
            'api.pix.basspago.com.br/oauth/token' => Http::response([
                'access_token' => 'token-in',
                'expires_in' => 300,
            ], 200),
            'api.pix.basspago.com.br/locrec' => Http::response([
                'id' => 42,
                'location' => 'pix.example/loc/42',
                'criacao' => now()->toIso8601String(),
            ], 201),
            'api.pix.basspago.com.br/cob/*' => Http::response([
                'txid' => 'pixauto1abcdefghijklmnopqrstuv',
                'status' => 'ATIVA',
                'pixCopiaECola' => '00020126cobimediata',
                'loc' => ['id' => 99],
            ], 201),
            'api.pix.basspago.com.br/rec' => Http::response([
                'idRec' => 'RN1234567820260801abcdefghijk',
                'status' => 'CRIADA',
            ], 201),
            'api.pix.basspago.com.br/rec/*' => Http::response([
                'idRec' => 'RN1234567820260801abcdefghijk',
                'status' => 'CRIADA',
                'dadosQR' => [
                    'pixCopiaECola' => '00020126qrccomposito',
                ],
            ], 200),
        ]);

        $service = new VersellPixRecorrenteService($this->sampleCredentials());
        $loc = $service->createLocRec();
        $this->assertSame(42, (int) $loc['id']);

        $txid = 'pixauto1abcdefghijklmnopqrstuv';
        $cob = $service->createCobWithTxid(
            $txid,
            35.0,
            ['name' => 'Fulano', 'document' => '52998224725', 'email' => 'a@b.com'],
            'pix@versell.test'
        );
        $this->assertSame($txid, $cob['txid']);
        $this->assertSame('00020126cobimediata', $cob['copy_paste']);

        $rec = $service->createRecurrence(
            42,
            $txid,
            ['name' => 'Fulano', 'document' => '52998224725', 'email' => 'a@b.com'],
            35.0,
            now()->addMonth()->format('Y-m-d'),
            now()->addYears(10)->format('Y-m-d'),
            '00000001',
            'Assinatura'
        );
        $this->assertSame('RN1234567820260801abcdefghijk', $rec['idRec']);

        $recData = $service->getRecurrence($rec['idRec'], $txid);
        $this->assertSame('00020126qrccomposito', $recData['dadosQR']['pixCopiaECola'] ?? null);

        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_ends_with(parse_url($r->url(), PHP_URL_PATH) ?? '', '/locrec'));
        Http::assertSent(fn ($r) => $r->method() === 'PUT' && str_contains($r->url(), '/cob/'));
        Http::assertSent(function ($r) {
            return $r->method() === 'POST'
                && str_ends_with(parse_url($r->url(), PHP_URL_PATH) ?? '', '/rec')
                && ($r['ativacao']['dadosJornada']['txid'] ?? null) !== null;
        });
    }

    public function test_cobr_webhook_dispatches_paid(): void
    {
        if (! Schema::hasTable('orders')) {
            $this->markTestSkipped('orders table');
        }

        Bus::fake([ProcessPaymentWebhook::class]);

        $seller = User::factory()->create(['tenant_id' => 1]);
        $product = $this->createTestProduct(['tenant_id' => 1]);
        Order::query()->create([
            'tenant_id' => 1,
            'user_id' => $seller->id,
            'product_id' => $product->id,
            'status' => 'pending',
            'gateway' => 'versell',
            'gateway_id' => 'cobrtxid1234567890abcdefghij',
            'payment_method' => 'pix_auto',
            'amount' => 35,
            'email' => 'a@b.com',
            'metadata' => ['versell_pix_auto_id_rec' => 'RN1234567820260801abcdefghijk'],
        ]);

        $request = Request::create('/webhooks/gateways/versell/pix-automatico/cobr', 'POST', [
            'cobsr' => [[
                'idRec' => 'RN1234567820260801abcdefghijk',
                'txid' => 'cobrtxid1234567890abcdefghij',
                'status' => 'ATIVA',
                'tentativas' => [[
                    'status' => 'PAGA',
                    'endToEndId' => 'E123ENDTOEND',
                    'tipo' => 'AGND',
                ]],
            ]],
        ]);

        $response = app(VersellWebhookController::class)->pixAutoCobr($request);
        $this->assertSame(200, $response->getStatusCode());
        Bus::assertDispatched(ProcessPaymentWebhook::class);
    }

    public function test_create_cobranca_recorrente(): void
    {
        Http::fake([
            'api.pix.basspago.com.br/oauth/token' => Http::response([
                'access_token' => 'token-in',
                'expires_in' => 300,
            ], 200),
            'api.pix.basspago.com.br/cobr/*' => Http::response([
                'txid' => 'nextcobr1234567890abcdefghijkl',
                'idRec' => 'RN1234567820260801abcdefghijk',
                'status' => 'CRIADA',
            ], 201),
        ]);

        $service = new VersellPixRecorrenteService($this->sampleCredentials());
        $data = $service->createCobrancaRecorrente(
            'RN1234567820260801abcdefghijk',
            35.0,
            now()->addMonth()->format('Y-m-d'),
            'nextcobr1234567890abcdefghijkl',
            ['name' => 'Fulano', 'email' => 'a@b.com'],
            'Renovação'
        );

        $this->assertSame('RN1234567820260801abcdefghijk', $data['idRec']);
        Http::assertSent(fn ($r) => $r->method() === 'PUT' && str_contains($r->url(), '/cobr/'));
    }
}
