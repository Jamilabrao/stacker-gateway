<?php

namespace Tests\Feature;

use App\Models\TenantWallet;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Platform\PlatformTotpService;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\GeneratesTotpCodes;
use Tests\TestCase;

class PlatformAdminWalletAdjustmentTest extends TestCase
{
    use GeneratesTotpCodes;
    private function createMerchantWithWallet(float $availablePix = 50.0): User
    {
        $merchant = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
        ]);
        $merchant->forceFill(['tenant_id' => $merchant->id])->save();

        if (Schema::hasTable('tenant_wallets')) {
            TenantWallet::query()->create([
                'tenant_id' => $merchant->id,
                'available_balance' => $availablePix,
                'pending_balance' => 0,
                'currency' => 'BRL',
                'available_pix' => $availablePix,
                'available_card' => 0,
                'available_boleto' => 0,
                'pending_pix' => 0,
                'pending_card' => 0,
                'pending_boleto' => 0,
            ]);
        }

        return $merchant;
    }

    private function platformAdmin(): User
    {
        return User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);
    }

    private function platformAdminWithTotp(): array
    {
        $admin = $this->platformAdmin();
        $setup = PlatformTotpService::beginEnrollment($admin->fresh());
        PlatformTotpService::confirmEnrollment(
            $admin->fresh(),
            $this->totpCodeForSecret($setup['secret'])
        );

        return ['admin' => $admin->fresh(), 'secret' => $setup['secret']];
    }

    public function test_platform_admin_can_credit_wallet(): void
    {
        if (! Schema::hasTable('tenant_wallets') || ! Schema::hasTable('wallet_transactions')) {
            $this->markTestSkipped('wallet tables');
        }

        $merchant = $this->createMerchantWithWallet(50.0);
        $admin = $this->platformAdmin();

        $response = $this->actingAs($admin)->post(route('plataforma.usuarios.adjust-balance', $merchant), [
            'amount' => 25.50,
            'direction' => 'credit',
            'bucket' => 'pix',
            'note' => 'Bônus promocional teste',
        ]);

        $response->assertRedirect(route('plataforma.usuarios.show', $merchant));

        $wallet = TenantWallet::query()->where('tenant_id', $merchant->id)->first();
        $this->assertNotNull($wallet);
        $this->assertEquals(75.50, (float) $wallet->available_pix);
        $this->assertEquals(75.50, (float) $wallet->available_balance);

        $this->assertTrue(
            WalletTransaction::query()
                ->where('tenant_id', $merchant->id)
                ->where('type', WalletTransaction::TYPE_ADMIN_ADJUSTMENT)
                ->where('amount_net', 25.50)
                ->exists()
        );
    }

    public function test_platform_admin_can_debit_below_zero(): void
    {
        if (! Schema::hasTable('tenant_wallets')) {
            $this->markTestSkipped('tenant_wallets');
        }

        $merchant = $this->createMerchantWithWallet(10.0);
        $admin = $this->platformAdmin();

        $this->actingAs($admin)->post(route('plataforma.usuarios.adjust-balance', $merchant), [
            'amount' => 20,
            'direction' => 'debit',
            'bucket' => 'pix',
            'note' => 'Correção manual negativa',
        ])->assertRedirect();

        $wallet = TenantWallet::query()->where('tenant_id', $merchant->id)->first();
        $this->assertEquals(-10.0, (float) $wallet->available_pix);
        $this->assertEquals(-10.0, (float) $wallet->available_balance);
    }

    public function test_adjustment_requires_totp_when_admin_has_totp_enabled(): void
    {
        if (! Schema::hasTable('tenant_wallets')) {
            $this->markTestSkipped('tenant_wallets');
        }

        $merchant = $this->createMerchantWithWallet(50.0);
        ['admin' => $admin] = $this->platformAdminWithTotp();

        $this->actingAs($admin)
            ->post(route('plataforma.usuarios.adjust-balance', $merchant), [
                'amount' => 10,
                'direction' => 'credit',
                'bucket' => 'pix',
                'note' => 'Ajuste com 2FA obrigatório',
            ])
            ->assertSessionHasErrors('totp_code');
    }

    public function test_adjustment_succeeds_with_valid_totp_code(): void
    {
        if (! Schema::hasTable('tenant_wallets') || ! Schema::hasTable('wallet_transactions')) {
            $this->markTestSkipped('wallet tables');
        }

        $merchant = $this->createMerchantWithWallet(50.0);
        ['admin' => $admin, 'secret' => $secret] = $this->platformAdminWithTotp();

        $this->actingAs($admin)
            ->post(route('plataforma.usuarios.adjust-balance', $merchant), [
                'amount' => 15,
                'direction' => 'credit',
                'bucket' => 'pix',
                'note' => 'Ajuste com código 2FA válido',
                'totp_code' => $this->totpCodeForSecret($secret),
            ])
            ->assertRedirect(route('plataforma.usuarios.show', $merchant));

        $wallet = TenantWallet::query()->where('tenant_id', $merchant->id)->first();
        $this->assertNotNull($wallet);
        $this->assertEquals(65.0, (float) $wallet->available_pix);
    }

    public function test_adjustment_requires_note(): void
    {
        $merchant = $this->createMerchantWithWallet();
        $admin = $this->platformAdmin();

        $this->actingAs($admin)
            ->post(route('plataforma.usuarios.adjust-balance', $merchant), [
                'amount' => 5,
                'direction' => 'credit',
                'bucket' => 'pix',
                'note' => 'ab',
            ])
            ->assertSessionHasErrors('note');
    }

    public function test_infoprodutor_cannot_adjust_balance(): void
    {
        $merchant = $this->createMerchantWithWallet();
        $other = $this->createMerchantWithWallet(0);

        $this->actingAs($other)
            ->post(route('plataforma.usuarios.adjust-balance', $merchant), [
                'amount' => 10,
                'direction' => 'credit',
                'bucket' => 'pix',
                'note' => 'tentativa indevida',
            ])
            ->assertForbidden();
    }

    public function test_platform_admin_can_view_merchant_show(): void
    {
        $merchant = $this->createMerchantWithWallet(100.0);
        $admin = $this->platformAdmin();

        $this->actingAs($admin)
            ->get(route('plataforma.usuarios.show', $merchant))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Platform/Users/Show')
                ->where('merchant.id', $merchant->id)
                ->has('wallet')
                ->has('withdrawals')
                ->has('wallet_transactions'));
    }

    public function test_saldo_index_accessible_by_platform_admin(): void
    {
        $merchant = $this->createMerchantWithWallet(42.0);
        $admin = $this->platformAdmin();

        $this->actingAs($admin)
            ->get(route('plataforma.saldo.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Platform/Balances/Index')
                ->has('merchants'));
    }
}
