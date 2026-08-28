<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Http\Middleware\EnsureStackerLicense;
use App\Gateways\Efi\EfiDriver;
use App\Models\CajuPayAccount;
use App\Models\GatewayCredential;
use App\Models\User;
use App\Services\Platform\AcquirerWalletBalanceService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PlatformAcquirerWalletBalanceTest extends TestCase
{
    private ?string $versellTmpDir = null;

    private ?string $efiTmpDir = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            EnsureInstalled::class,
            EnsureStackerLicense::class,
            ValidateCsrfToken::class,
        ]);
        Cache::flush();
    }

    protected function tearDown(): void
    {
        if ($this->versellTmpDir !== null && is_dir($this->versellTmpDir)) {
            foreach (glob($this->versellTmpDir.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->versellTmpDir);
        }

        if ($this->efiTmpDir !== null && is_dir($this->efiTmpDir)) {
            foreach (glob($this->efiTmpDir.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->efiTmpDir);
        }

        parent::tearDown();
    }

    public function test_dashboard_lists_all_acquirers_as_inactive_when_none_are_connected(): void
    {
        $admin = $this->platformAdmin();

        $this->actingAs($admin)
            ->get('/plataforma/dashboard?period=hoje')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Platform/Dashboard')
                ->has('acquirer_wallets')
                ->where('acquirer_wallets.0.status', 'inactive')
                ->where('kpis.wallet_available', 0)
            );

        $rows = app(AcquirerWalletBalanceService::class)->list();
        $this->assertSame(
            ['cajupay', 'bspay', 'efi', 'woovi', 'mercadopago', 'stripe', 'versell'],
            collect($rows)->pluck('slug')->all()
        );
        $this->assertTrue(collect($rows)->every(fn (array $row) => $row['status'] === 'inactive'));
    }

    public function test_dashboard_lists_connected_wallet_balances_and_isolates_failures(): void
    {
        $this->seedCajuPayAccount('Conta principal', 'pk_ok', 'sk_ok');
        $this->seedCredential('bspay', [
            'client_id' => 'bspay-id',
            'client_secret' => 'bspay-secret',
        ]);
        $this->seedCredential('mercadopago', [
            'public_key' => 'TEST-public',
            'access_token' => 'TEST-access-token',
        ]);
        $this->seedCredential('stripe', [
            'secret_key' => 'sk_test_fake',
        ]);
        $this->seedCredential('efi', [
            'client_id' => 'efi-id',
            'client_secret' => 'efi-secret',
        ], enabled: true);

        Http::fake([
            'api.cajupay.com.br/api/wallet/balance*' => Http::response(['available_cents' => 25990], 200),
            'api.bspay.co/v2/oauth/token' => Http::response(['access_token' => 'bspay-token', 'expires_in' => 3600], 200),
            'api.bspay.co/v2/account/balance' => Http::response([
                'success' => true,
                'data' => ['BRL' => ['available' => 1234.56]],
            ], 200),
            'api.mercadopago.com/users/me' => Http::response(['id' => 99, 'email' => 'mp@test.com'], 200),
            'api.mercadopago.com/users/99/mercadopago_account/balance' => Http::response([
                'available_balance' => 1200.5,
                'total_amount' => 1500.0,
                'unavailable_balance' => 299.5,
                'currency_id' => 'BRL',
            ], 200),
            'api.stripe.com/v1/balance' => Http::response([
                'available' => [
                    ['amount' => 45000, 'currency' => 'brl'],
                    ['amount' => 100, 'currency' => 'usd'],
                ],
                'pending' => [],
            ], 200),
        ]);

        $admin = $this->platformAdmin();

        $this->actingAs($admin)
            ->get('/plataforma/dashboard?period=hoje')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Platform/Dashboard')
                ->has('acquirer_wallets')
                ->where('kpis.wallet_available', 0)
            );

        $bySlug = collect(app(AcquirerWalletBalanceService::class)->list())->keyBy('slug');
        $this->assertSame('ok', $bySlug['cajupay']['status']);
        $this->assertSame(259.9, $bySlug['cajupay']['available']);
        $this->assertSame('Conta principal', $bySlug['cajupay']['conta']);
        $this->assertSame('ok', $bySlug['bspay']['status']);
        $this->assertSame(1234.56, $bySlug['bspay']['available']);
        $this->assertSame('ok', $bySlug['mercadopago']['status']);
        $this->assertSame(1200.5, $bySlug['mercadopago']['available']);
        $this->assertSame('ok', $bySlug['stripe']['status']);
        $this->assertSame(450.0, $bySlug['stripe']['available']);
        $this->assertSame('inactive', $bySlug['versell']['status']);
        $this->assertSame('inactive', $bySlug['efi']['status']);
        $this->assertSame('inactive', $bySlug['woovi']['status']);
    }

    public function test_disabled_or_disconnected_credentials_are_marked_inactive(): void
    {
        $this->seedCajuPayAccount('Desligada', 'pk', 'sk', enabled: false);
        $this->seedCredential('bspay', [
            'client_id' => 'bspay-id',
            'client_secret' => 'bspay-secret',
        ], enabled: false);
        $this->seedCredential('stripe', [
            'secret_key' => 'sk_test_fake',
        ], connected: false);

        Http::fake();

        $rows = app(AcquirerWalletBalanceService::class)->list();
        $bySlug = collect($rows)->keyBy('slug');

        $this->assertSame('inactive', $bySlug['cajupay']['status']);
        $this->assertSame('inactive', $bySlug['bspay']['status']);
        $this->assertSame('inactive', $bySlug['stripe']['status']);
        Http::assertNothingSent();
    }

    public function test_one_provider_failure_does_not_drop_the_others(): void
    {
        $this->seedCajuPayAccount('Principal', 'pk', 'sk');
        $this->seedCredential('bspay', [
            'client_id' => 'bspay-id',
            'client_secret' => 'bspay-secret',
        ]);

        Http::fake([
            'api.cajupay.com.br/api/wallet/balance*' => Http::response(['error' => 'boom'], 500),
            'api.bspay.co/v2/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600], 200),
            'api.bspay.co/v2/account/balance' => Http::response([
                'data' => ['available' => 10.0],
            ], 200),
        ]);

        $bySlug = collect(app(AcquirerWalletBalanceService::class)->list())->keyBy('slug');

        $this->assertSame('error', $bySlug['cajupay']['status']);
        $this->assertNull($bySlug['cajupay']['available']);
        $this->assertSame('ok', $bySlug['bspay']['status']);
        $this->assertSame(10.0, $bySlug['bspay']['available']);
        $this->assertSame('inactive', $bySlug['stripe']['status']);
    }

    public function test_versell_uses_cash_out_balance_endpoint(): void
    {
        $this->seedVersellCashOut();

        Http::fake([
            'pagamentos.basspago.com.br/api/v2/oauth/token' => Http::response([
                'accessToken' => 'versell-token',
                'expiresIn' => 3600,
            ], 200),
            'pagamentos.basspago.com.br/api/v2/accounts/balances*' => Http::response([
                'data' => [[
                    'eventDate' => '2026-08-28',
                    'balanceAmount' => [
                        'currency' => 'BRL',
                        'available' => 1500.0,
                        'blocked' => 20.0,
                        'overdraft' => 0.0,
                    ],
                ]],
            ], 200),
        ]);

        $bySlug = collect(app(AcquirerWalletBalanceService::class)->list())->keyBy('slug');

        $this->assertSame('ok', $bySlug['versell']['status']);
        $this->assertSame(1500.0, $bySlug['versell']['available']);
    }

    public function test_efi_uses_account_balance_endpoint(): void
    {
        $this->seedEfiWithCertificate();

        $this->mock(EfiDriver::class, function ($mock) {
            $mock->shouldReceive('fetchAccountBalance')
                ->once()
                ->andReturn([
                    'saldo' => '321.45',
                    'bloqueios' => [
                        'judicial' => '0.00',
                        'med' => '10.00',
                        'total' => '10.00',
                    ],
                ]);
        });

        $bySlug = collect(app(AcquirerWalletBalanceService::class)->list())->keyBy('slug');

        $this->assertSame('ok', $bySlug['efi']['status']);
        $this->assertSame(321.45, $bySlug['efi']['available']);
    }

    public function test_woovi_uses_account_balance_endpoint(): void
    {
        $this->seedCredential('woovi', [
            'app_id' => 'woovi-app-id',
        ]);

        Http::fake([
            'api.woovi.com/api/v1/account/6290ccfd42831958a405debc' => Http::response([
                'account' => [
                    'accountId' => '6290ccfd42831958a405debc',
                    'isDefault' => true,
                    'accountName' => 'Main Account',
                    'balance' => [
                        'total' => 129430,
                        'blocked' => 0,
                        'available' => 129430,
                    ],
                ],
            ], 200),
            'api.woovi.com/api/v1/account*' => Http::response([
                'accounts' => [
                    [
                        'accountId' => '6286b467a7910113577e00ce',
                        'isDefault' => false,
                        'balance' => ['total' => 130, 'blocked' => 100, 'available' => 30],
                    ],
                    [
                        'accountId' => '6290ccfd42831958a405debc',
                        'isDefault' => true,
                        'balance' => ['total' => 100, 'blocked' => 0, 'available' => 100],
                    ],
                ],
            ], 200),
        ]);

        $bySlug = collect(app(AcquirerWalletBalanceService::class)->list())->keyBy('slug');

        $this->assertSame('ok', $bySlug['woovi']['status']);
        $this->assertSame(1294.3, $bySlug['woovi']['available']);
    }

    public function test_parsers_cover_known_payload_shapes(): void
    {
        $service = app(AcquirerWalletBalanceService::class);

        $this->assertSame(25.9, $service->parseCajuPayAvailable(['available_cents' => 2590]));
        $this->assertSame(10.0, $service->parseCajuPayAvailable(['data' => ['available' => 1000]]));
        $this->assertSame(99.5, $service->parseBspayAvailable(['data' => ['BRL' => ['available' => 99.5]]]));
        $this->assertSame(12.34, $service->parseStripeAvailable([
            'available' => [['amount' => 1234, 'currency' => 'brl']],
        ]));
        $this->assertSame(100.0, $service->parseEfiAvailable(['saldo' => '100.00']));
        $this->assertSame(1294.3, $service->parseWooviAvailable([
            'account' => [
                'balance' => ['total' => 129430, 'blocked' => 0, 'available' => 129430],
            ],
        ]));
        $this->assertSame(1294.3, $service->parseWooviAvailable([
            'accounts' => [
                ['isDefault' => false, 'balance' => ['available' => 30]],
                ['isDefault' => true, 'balance' => ['available' => 129430]],
            ],
        ]));
        $this->assertSame(80.0, $service->parseVersellAvailable([
            'data' => [
                ['eventDate' => '2026-01-01', 'balanceAmount' => ['available' => 10]],
                ['eventDate' => '2026-01-02', 'balanceAmount' => ['available' => 80]],
            ],
        ]));
    }

    private function platformAdmin(): User
    {
        return User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function seedCredential(string $slug, array $credentials, bool $enabled = true, bool $connected = true): GatewayCredential
    {
        $cred = GatewayCredential::query()->firstOrNew([
            'tenant_id' => null,
            'gateway_slug' => $slug,
        ]);
        $cred->is_connected = $connected;
        $cred->is_enabled = $enabled;
        $cred->setEncryptedCredentials($credentials);
        $cred->save();

        return $cred;
    }

    private function seedCajuPayAccount(string $name, string $public, string $secret, bool $enabled = true): CajuPayAccount
    {
        $account = CajuPayAccount::create([
            'name' => $name,
            'is_default' => true,
            'is_connected' => true,
            'is_enabled' => $enabled,
        ]);
        $account->setEncryptedCredentials([
            'public_key' => $public,
            'secret_key' => $secret,
        ]);
        $account->save();

        return $account;
    }

    private function seedVersellCashOut(): void
    {
        $this->versellTmpDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'versell_bal_'.uniqid('', true);
        mkdir($this->versellTmpDir, 0700, true);
        $cert = $this->versellTmpDir.DIRECTORY_SEPARATOR.'out.crt';
        $key = $this->versellTmpDir.DIRECTORY_SEPARATOR.'out.key';
        file_put_contents($cert, "-----BEGIN CERTIFICATE-----\nOUT\n-----END CERTIFICATE-----\n");
        file_put_contents($key, "-----BEGIN PRIVATE KEY-----\nOUT\n-----END PRIVATE KEY-----\n");

        $this->seedCredential('versell', [
            'cash_out' => [
                'client_id' => 'co_client',
                'client_secret' => 'co_secret',
                'certificate_path' => $cert,
                'private_key_path' => $key,
            ],
        ]);
    }

    private function seedEfiWithCertificate(): void
    {
        $this->efiTmpDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'efi_bal_'.uniqid('', true);
        mkdir($this->efiTmpDir, 0700, true);
        $cert = $this->efiTmpDir.DIRECTORY_SEPARATOR.'cert.p12';
        file_put_contents($cert, 'dummy-efi-p12');

        $this->seedCredential('efi', [
            'client_id' => 'efi-id',
            'client_secret' => 'efi-secret',
            'certificate_path' => $cert,
            'pwd_certificate' => '',
            'sandbox' => true,
        ]);
    }
}
