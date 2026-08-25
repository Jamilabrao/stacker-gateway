<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Http\Middleware\EnsureStackerLicense;
use App\Models\BrandingSetting;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\MerchantWithdrawalService;
use App\Services\WithdrawalPixReceiptService;
use App\Support\BrandingAssetUrls;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class WithdrawalPixReceiptTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            EnsureInstalled::class,
            EnsureStackerLicense::class,
            ValidateCsrfToken::class,
        ]);
    }

    private function createMerchant(): User
    {
        $merchant = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'account_status' => 'approved',
            'kyc_status' => User::KYC_APPROVED,
            'email_verified_at' => now(),
            'payout_settings' => [
                'cajupay_pix_key' => 'seller@example.com',
                'cajupay_pix_key_type' => 'email',
                'cajupay_pix_key_owner_document' => '52998224725',
            ],
        ]);
        $merchant->forceFill(['tenant_id' => $merchant->id])->save();

        return $merchant;
    }

    private function createPaidWithdrawal(User $merchant, array $overrides = []): Withdrawal
    {
        if (! Schema::hasTable('withdrawals')) {
            $this->markTestSkipped('withdrawals table missing');
        }

        return Withdrawal::query()->create(array_merge([
            'tenant_id' => $merchant->id,
            'user_id' => $merchant->id,
            'amount' => 100.0,
            'fee_amount' => 5.0,
            'net_amount' => 95.0,
            'bucket' => 'pix',
            'status' => MerchantWithdrawalService::STATUS_PAID,
            'currency' => 'BRL',
            'payout_provider' => 'cajupay',
            'payout_external_id' => 'payout-test-uuid',
            'payout_meta' => [
                'destination_snapshot' => [
                    'receiver_name' => $merchant->name,
                    'receiver_document' => '52998224725',
                    'pix_key' => 'seller@example.com',
                    'pix_key_type' => 'email',
                    'captured_at' => now()->toIso8601String(),
                ],
                'cajupay_receipt' => [
                    'paid_at' => now()->toIso8601String(),
                    'psp_reference' => 'E12345678901234567890123456789012',
                    'payer_name' => 'BSPAY SOLUCOES DE PAGAMENTOS LTDA',
                    'payer_document' => '46872831000154',
                    'payer_institution' => 'BSPAY SOLUCOES DE PAGAMENTOS LTDA',
                    'receiver_institution' => 'Banco Pan S.A.',
                ],
            ],
        ], $overrides));
    }

    public function test_snapshot_destination_persists_pix_key_data(): void
    {
        $merchant = $this->createMerchant();
        $withdrawal = Withdrawal::query()->create([
            'tenant_id' => $merchant->id,
            'user_id' => $merchant->id,
            'amount' => 50.0,
            'fee_amount' => 2.0,
            'net_amount' => 48.0,
            'bucket' => 'pix',
            'status' => MerchantWithdrawalService::STATUS_PENDING,
            'currency' => 'BRL',
        ]);

        app(WithdrawalPixReceiptService::class)->snapshotDestination($withdrawal, $merchant, [
            'pix_key' => 'seller@example.com',
            'pix_key_type' => 'email',
            'key_owner_document' => '52998224725',
        ]);

        $withdrawal->refresh();
        $snapshot = $withdrawal->payout_meta['destination_snapshot'] ?? null;
        $this->assertIsArray($snapshot);
        $this->assertSame('seller@example.com', $snapshot['pix_key'] ?? null);
        $this->assertSame('52998224725', $snapshot['receiver_document'] ?? null);
    }

    public function test_seller_can_view_paid_withdrawal_receipt(): void
    {
        $merchant = $this->createMerchant();
        $withdrawal = $this->createPaidWithdrawal($merchant);

        $response = $this->actingAs($merchant)->get(route('financeiro.seller.receipt', $withdrawal));

        $response->assertOk();
        $response->assertSee('Comprovante Pix', false);
        $response->assertSee('R$ 95,00', false);
        $response->assertSee('seller@example.com', false);
        $response->assertDontSee('BSPAY SOLUCOES DE PAGAMENTOS LTDA', false);
        $response->assertDontSee('Quem pagou', false);
    }

    public function test_pending_withdrawal_receipt_returns_not_found(): void
    {
        $merchant = $this->createMerchant();
        $withdrawal = $this->createPaidWithdrawal($merchant, [
            'status' => MerchantWithdrawalService::STATUS_PENDING,
        ]);

        $response = $this->actingAs($merchant)->get(route('financeiro.seller.receipt', $withdrawal));

        $response->assertNotFound();
        $response->assertSee('Comprovante indisponível', false);
        $response->assertDontSee('Ignition', false);
    }

    public function test_paid_withdrawal_with_zero_net_uses_gross_amount(): void
    {
        $merchant = $this->createMerchant();
        $withdrawal = $this->createPaidWithdrawal($merchant, [
            'amount' => 80.0,
            'fee_amount' => 0,
            'net_amount' => 0,
            'payout_meta' => [
                'destination_snapshot' => [
                    'receiver_name' => $merchant->name,
                    'pix_key' => 'seller@example.com',
                ],
            ],
        ]);

        $response = $this->actingAs($merchant)->get(route('financeiro.seller.receipt', $withdrawal));

        $response->assertOk();
        $response->assertSee('R$ 80,00', false);
    }

    public function test_paid_status_with_whitespace_still_allows_receipt(): void
    {
        $merchant = $this->createMerchant();
        $withdrawal = $this->createPaidWithdrawal($merchant, [
            'status' => ' paid ',
        ]);

        $this->actingAs($merchant)->get(route('financeiro.seller.receipt', $withdrawal))->assertOk();
    }

    public function test_platform_admin_can_view_api_cashout_receipt(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);
        $merchant = $this->createMerchant();
        $withdrawal = $this->createPaidWithdrawal($merchant, [
            'api_application_id' => null,
        ]);

        $response = $this->actingAs($admin)->get(route('plataforma.saques.receipt', $withdrawal));

        $response->assertOk();
        $response->assertSee('Comprovante Pix', false);
        $response->assertSee('Banco Pan S.A.', false);
        $response->assertSee('BSPAY SOLUCOES DE PAGAMENTOS LTDA', false);
        $response->assertSee('Quem pagou', false);
    }

    public function test_seller_view_data_hides_payer_details(): void
    {
        $merchant = $this->createMerchant();
        $withdrawal = $this->createPaidWithdrawal($merchant);

        $data = app(WithdrawalPixReceiptService::class)->viewData($withdrawal, includePayerSection: false);

        $this->assertFalse($data['show_payer_section']);
        $this->assertSame((string) $withdrawal->id, $data['identification']);
    }

    public function test_view_data_uses_cajupay_payer_name(): void
    {
        $merchant = $this->createMerchant();
        $withdrawal = $this->createPaidWithdrawal($merchant);

        $data = app(WithdrawalPixReceiptService::class)->viewData($withdrawal, includePayerSection: true);

        $this->assertSame('BSPAY SOLUCOES DE PAGAMENTOS LTDA', $data['payer_name']);
        $this->assertSame('46.872.831/0001-54', $data['payer_document']);
    }

    public function test_seller_can_view_receipt_without_payout_meta(): void
    {
        $merchant = $this->createMerchant();
        $withdrawal = $this->createPaidWithdrawal($merchant, [
            'payout_provider' => null,
            'payout_external_id' => null,
            'payout_meta' => null,
        ]);

        $response = $this->actingAs($merchant)->get(route('financeiro.seller.receipt', $withdrawal));

        $response->assertOk();
        $response->assertSee('Comprovante Pix', false);
        $response->assertSee('R$ 95,00', false);
        $response->assertSee('seller@example.com', false);
        $response->assertSee($merchant->name, false);
    }

    public function test_view_data_does_not_write_to_database(): void
    {
        $merchant = $this->createMerchant();
        $withdrawal = $this->createPaidWithdrawal($merchant, [
            'payout_meta' => [
                'destination_snapshot' => [
                    'receiver_name' => $merchant->name,
                    'pix_key' => 'seller@example.com',
                ],
            ],
        ]);

        $before = $withdrawal->updated_at?->toIso8601String();

        app(WithdrawalPixReceiptService::class)->viewData($withdrawal, includePayerSection: false);

        $withdrawal->refresh();
        $this->assertSame($before, $withdrawal->updated_at?->toIso8601String());
    }

    public function test_view_data_uses_light_theme_logo_with_public_url(): void
    {
        if (! Schema::hasTable('branding_settings')) {
            $this->markTestSkipped('branding_settings table missing');
        }

        $request = Request::create(
            'https://meu-dominio.test/plataforma/saques/1/comprovante',
            'GET',
            server: ['HTTP_HOST' => 'meu-dominio.test', 'HTTPS' => 'on']
        );
        $this->app->instance('request', $request);

        BrandingSetting::query()->updateOrCreate(
            ['tenant_id' => null],
            ['data' => [
                'app_name' => 'Stacker Test',
                'app_logo' => 'white-label/global/logo-claro.png',
                'app_logo_dark' => 'white-label/global/logo-escuro.png',
            ]]
        );

        $merchant = $this->createMerchant();
        $withdrawal = $this->createPaidWithdrawal($merchant);
        $data = app(WithdrawalPixReceiptService::class)->viewData($withdrawal, includePayerSection: true);

        $this->assertSame(
            BrandingAssetUrls::resolve('white-label/global/logo-claro.png'),
            $data['app_logo']
        );
        $this->assertStringNotContainsString('logo-escuro.png', $data['app_logo']);

        $response = $this->actingAs($merchant)->get(route('financeiro.seller.receipt', $withdrawal));
        $response->assertOk();
        $response->assertSee('white-label/global/logo-claro.png', false);
        $response->assertDontSee('logo-escuro.png', false);
        $response->assertDontSee('>'.$data['app_name'].'</span>', false);
    }
}
