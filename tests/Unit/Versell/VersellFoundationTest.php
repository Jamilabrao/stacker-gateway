<?php

namespace Tests\Unit\Versell;

use App\Gateways\GatewayRegistry;
use App\Gateways\Versell\VersellCredentials;
use App\Gateways\Versell\VersellDriver;
use App\Services\Versell\VersellHttpClient;
use App\Services\Versell\VersellProblemDetails;
use App\Support\GatewayApiCredentials;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class VersellFoundationTest extends TestCase
{
    private string $tmpDir;

    private string $cashInCert;

    private string $cashInKey;

    private string $cashOutCert;

    private string $cashOutKey;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'versell_test_'.uniqid('', true);
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

    public function test_create_pix_payment_requires_credentials(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('chave PIX');

        $creds = $this->sampleCredentials();
        $creds['cash_in']['pix_key'] = '';

        (new VersellDriver())->createPixPayment(
            $creds,
            10.0,
            ['name' => 'Test', 'document' => '12345678909', 'email' => 'a@b.com'],
            '1',
            'https://example.test/webhook'
        );
    }

    public function test_versell_is_registered_and_in_default_pix_order(): void
    {
        $gateway = GatewayRegistry::get('versell');
        $this->assertNotNull($gateway);
        $this->assertSame(VersellDriver::class, $gateway['driver']);
        $this->assertTrue(GatewayRegistry::isAllowedAcquirer('versell'));

        $pixOrder = config('gateways.default_order.pix', []);
        $this->assertTrue(in_array('versell', $pixOrder, true));
        $this->assertTrue(GatewayApiCredentials::isReadyForGateway('versell', $this->sampleCredentials()));
    }

    public function test_existing_gateways_still_registered(): void
    {
        foreach (['efi', 'bspay', 'cajupay', 'woovi', 'mercadopago', 'linaopenx'] as $slug) {
            $this->assertNotNull(GatewayRegistry::get($slug), "Gateway {$slug} missing");
            $this->assertNotNull(GatewayRegistry::driver($slug), "Driver {$slug} missing");
        }
    }

    public function test_cash_in_oauth_sends_form_urlencoded(): void
    {
        Http::fake([
            'api.pix.basspago.com.br/oauth/token' => Http::response([
                'access_token' => 'token-cash-in',
                'token_type' => 'Bearer',
                'expires_in' => 300,
            ], 200),
        ]);

        $client = new VersellHttpClient();
        $token = $client->getCashInAccessToken($this->sampleCredentials(), true);
        $this->assertSame('token-cash-in', $token);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.pix.basspago.com.br/oauth/token'
                && $request->isForm()
                && $request['client_id'] === 'ci_client'
                && $request['client_secret'] === 'ci_secret'
                && $request['grant_type'] === 'client_credentials'
                && ! isset($request['clientId']);
        });
    }

    public function test_cash_out_oauth_sends_json_camel_case(): void
    {
        Http::fake([
            'pagamentos.basspago.com.br/api/v2/oauth/token' => Http::response([
                'access_token' => 'token-cash-out',
                'token_type' => 'Bearer',
                'expires_in' => 3600,
            ], 200),
        ]);

        $client = new VersellHttpClient();
        $token = $client->getCashOutAccessToken($this->sampleCredentials(), true);
        $this->assertSame('token-cash-out', $token);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://pagamentos.basspago.com.br/api/v2/oauth/token'
                && $request->isJson()
                && $request['clientId'] === 'co_client'
                && $request['clientSecret'] === 'co_secret'
                && $request['grantType'] === 'client_credentials'
                && ! isset($request['client_id']);
        });
    }

    public function test_cash_out_oauth_accepts_camel_case_access_token(): void
    {
        Http::fake([
            'pagamentos.basspago.com.br/api/v2/oauth/token' => Http::response([
                'accessToken' => 'token-camel',
                'tokenType' => 'Bearer',
                'expiresIn' => 3600,
            ], 200),
        ]);

        $client = new VersellHttpClient();
        $this->assertSame('token-camel', $client->getCashOutAccessToken($this->sampleCredentials(), true));
    }

    public function test_mtls_options_never_mix_certificates(): void
    {
        $client = new VersellHttpClient();
        $creds = $this->sampleCredentials();

        $in = $client->mtlsOptions('cash_in', $creds);
        $out = $client->mtlsOptions('cash_out', $creds);

        $this->assertSame($this->cashInCert, $in['cert']);
        $this->assertSame($this->cashInKey, $in['ssl_key']);
        $this->assertSame($this->cashOutCert, $out['cert']);
        $this->assertSame($this->cashOutKey, $out['ssl_key']);

        $this->assertNotSame($in['cert'], $out['cert']);
        $this->assertNotSame($in['ssl_key'], $out['ssl_key']);
    }

    public function test_token_caches_are_independent(): void
    {
        Http::fake([
            'api.pix.basspago.com.br/oauth/token' => Http::response([
                'access_token' => 'token-in',
                'expires_in' => 300,
            ], 200),
            'pagamentos.basspago.com.br/api/v2/oauth/token' => Http::response([
                'access_token' => 'token-out',
                'expires_in' => 3600,
            ], 200),
        ]);

        $client = new VersellHttpClient();
        $creds = $this->sampleCredentials();

        $this->assertSame('token-in', $client->getCashInAccessToken($creds));
        $this->assertSame('token-out', $client->getCashOutAccessToken($creds));

        $inKey = $client->tokenCacheKey('cash_in', $creds);
        $outKey = $client->tokenCacheKey('cash_out', $creds);
        $this->assertNotSame($inKey, $outKey);
        $this->assertSame('token-in', Cache::get($inKey));
        $this->assertSame('token-out', Cache::get($outKey));
    }

    public function test_cache_reuses_valid_token(): void
    {
        Http::fake([
            'api.pix.basspago.com.br/oauth/token' => Http::sequence()
                ->push(['access_token' => 'first', 'expires_in' => 300], 200)
                ->push(['access_token' => 'second', 'expires_in' => 300], 200),
        ]);

        $client = new VersellHttpClient();
        $creds = $this->sampleCredentials();

        $this->assertSame('first', $client->getCashInAccessToken($creds));
        $this->assertSame('first', $client->getCashInAccessToken($creds));
        Http::assertSentCount(1);
    }

    public function test_force_refresh_fetches_new_token(): void
    {
        Http::fake([
            'api.pix.basspago.com.br/oauth/token' => Http::sequence()
                ->push(['access_token' => 'first', 'expires_in' => 300], 200)
                ->push(['access_token' => 'second', 'expires_in' => 300], 200),
        ]);

        $client = new VersellHttpClient();
        $creds = $this->sampleCredentials();

        $this->assertSame('first', $client->getCashInAccessToken($creds));
        $this->assertSame('second', $client->getCashInAccessToken($creds, true));
        Http::assertSentCount(2);
    }

    public function test_401_clears_only_matching_cache_and_retries_once(): void
    {
        Http::fake([
            'api.pix.basspago.com.br/oauth/token' => Http::sequence()
                ->push(['access_token' => 'old-in', 'expires_in' => 300], 200)
                ->push(['access_token' => 'new-in', 'expires_in' => 300], 200),
            'pagamentos.basspago.com.br/api/v2/oauth/token' => Http::response([
                'access_token' => 'out-token',
                'expires_in' => 3600,
            ], 200),
            'api.pix.basspago.com.br/cob' => Http::sequence()
                ->push(['title' => 'Não autorizado', 'status' => 401, 'detail' => 'token expirado'], 401)
                ->push(['txid' => 'ok'], 200),
        ]);

        $client = new VersellHttpClient();
        $creds = $this->sampleCredentials();

        // Warm both caches
        $this->assertSame('old-in', $client->getCashInAccessToken($creds));
        $this->assertSame('out-token', $client->getCashOutAccessToken($creds));

        $response = $client->request('cash_in', $creds, 'POST', '/cob', ['valor' => ['original' => '1.00']]);
        $this->assertTrue($response->successful());
        $this->assertSame('ok', $response->json('txid'));

        // Cash out cache must remain
        $this->assertSame('out-token', Cache::get($client->tokenCacheKey('cash_out', $creds)));
        // Cash in refreshed
        $this->assertSame('new-in', Cache::get($client->tokenCacheKey('cash_in', $creds)));

        // OAuth cash in called twice (initial + after 401); cash out once; cob twice
        Http::assertSentCount(5);
    }

    public function test_missing_certificate_raises_friendly_error(): void
    {
        $creds = $this->sampleCredentials();
        $creds['cash_in']['certificate_path'] = $this->tmpDir.DIRECTORY_SEPARATOR.'missing.crt';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('certificado CRT');

        (new VersellHttpClient())->getCashInAccessToken($creds, true);
    }

    public function test_missing_private_key_raises_friendly_error(): void
    {
        $creds = $this->sampleCredentials();
        $creds['cash_out']['private_key_path'] = $this->tmpDir.DIRECTORY_SEPARATOR.'missing.key';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('private key KEY');

        (new VersellHttpClient())->getCashOutAccessToken($creds, true);
    }

    public function test_problem_json_is_parsed_without_leaking_secrets(): void
    {
        $problem = VersellProblemDetails::fromResponse([
            'type' => 'https://pagamentos.basspago.com.br/errors/unauthorized',
            'title' => 'Não autorizado',
            'status' => 401,
            'detail' => 'O token de autenticação fornecido é inválido ou expirou.',
        ], 401);

        $this->assertSame(401, $problem['status']);
        $this->assertSame('Não autorizado', $problem['title']);
        $this->assertStringContainsString('inválido', (string) $problem['detail']);

        $redacted = VersellProblemDetails::fromResponse([
            'detail' => 'Bearer abcdefghijklmnopqrstuvwxyz0123456789abcdefghijklmnopqrstuvwxyz0123456789extra',
        ], 401);
        $this->assertSame('[redacted]', $redacted['detail']);
    }

    public function test_oauth_error_log_does_not_include_secrets(): void
    {
        Http::fake([
            'api.pix.basspago.com.br/oauth/token' => Http::response([
                'type' => 'https://example/unauthorized',
                'title' => 'Não autorizado',
                'status' => 401,
                'detail' => 'credencial inválida',
            ], 401),
        ]);

        Log::spy();

        try {
            (new VersellHttpClient())->getCashInAccessToken($this->sampleCredentials(), true);
            $this->fail('Expected exception');
        } catch (\RuntimeException $e) {
            $this->assertStringNotContainsString('ci_secret', $e->getMessage());
            $this->assertStringNotContainsString('token-cash-in', $e->getMessage());
        }

        Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context) {
            $encoded = json_encode($context) ?: '';

            return $message === 'Versell OAuth rejected'
                && ! str_contains($encoded, 'ci_secret')
                && ! str_contains($encoded, 'BEGIN PRIVATE KEY')
                && ! str_contains($encoded, 'Authorization');
        });
    }

    public function test_credentials_nest_and_preserve_secret_when_blank(): void
    {
        $existing = $this->sampleCredentials();
        $nested = VersellCredentials::nestFromFlat([
            'cash_in_client_id' => 'ci_client_2',
            'cash_in_client_secret' => '',
            'cash_in_pix_key' => 'new@pix',
            'cash_out_client_id' => 'co_client_2',
            'cash_out_client_secret' => '',
        ], $existing);

        $this->assertSame('ci_client_2', $nested['cash_in']['client_id']);
        $this->assertSame('ci_secret', $nested['cash_in']['client_secret']);
        $this->assertSame('new@pix', $nested['cash_in']['pix_key']);
        $this->assertSame('co_secret', $nested['cash_out']['client_secret']);
        $this->assertSame($this->cashInCert, $nested['cash_in']['certificate_path']);
    }

    public function test_flatten_for_form_hides_secrets_and_paths(): void
    {
        $flat = VersellCredentials::flattenForForm($this->sampleCredentials());
        $this->assertSame('ci_client', $flat['cash_in_client_id']);
        $this->assertSame('', $flat['cash_in_client_secret']);
        $this->assertSame('', $flat['cash_out_client_secret']);
        $this->assertArrayNotHasKey('cash_in_certificate_path', $flat);
        $encoded = json_encode($flat) ?: '';
        $this->assertStringNotContainsString($this->cashInKey, $encoded);
        $this->assertStringNotContainsString('ci_secret', $encoded);
    }

    public function test_driver_diagnose_connection_reports_each_api(): void
    {
        Http::fake([
            'api.pix.basspago.com.br/oauth/token' => Http::response([
                'access_token' => 'in',
                'expires_in' => 300,
            ], 200),
            'pagamentos.basspago.com.br/api/v2/oauth/token' => Http::response([
                'access_token' => 'out',
                'expires_in' => 3600,
            ], 200),
        ]);

        $driver = new VersellDriver();
        $diagnosis = $driver->diagnoseConnection($this->sampleCredentials());

        $this->assertTrue($diagnosis['ok']);
        $this->assertTrue($diagnosis['cash_in']['ok']);
        $this->assertTrue($diagnosis['cash_out']['ok']);
        $this->assertTrue($driver->testConnection($this->sampleCredentials()));
    }
}
