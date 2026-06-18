<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Services\EffectiveMerchantFees;
use Tests\TestCase;

class PlatformMerchantFeesUpdateTest extends TestCase
{
    public function test_platform_admin_can_save_individual_merchant_fee_overrides(): void
    {
        Setting::set('merchant_fee_rules', [
            'pix' => ['percent' => 2.0, 'fixed' => 0.0],
            'api_pix' => ['percent' => 2.0, 'fixed' => 0.0],
            'card' => ['percent' => 3.0, 'fixed' => 0.0],
            'apple_pay' => ['percent' => 3.0, 'fixed' => 0.0],
            'google_pay' => ['percent' => 3.0, 'fixed' => 0.0],
            'boleto' => ['percent' => 2.0, 'fixed' => 0.0],
            'withdrawal' => ['percent' => 1.0, 'fixed' => 0.0],
        ], null);

        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);

        $merchant = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
        ]);
        $merchant->forceFill([
            'tenant_id' => $merchant->id,
            'kyc_status' => User::KYC_APPROVED,
            'account_status' => 'approved',
        ])->save();

        $response = $this->actingAs($admin)->put(route('plataforma.usuarios.update', $merchant), [
            'name' => $merchant->name,
            'email' => $merchant->email,
            'account_status' => 'approved',
            'merchant_fees' => [
                'pix' => ['percent' => 7.5, 'fixed' => 1.25],
            ],
            'merchant_settlement_overrides' => null,
            'merchant_gateway_order' => null,
        ]);

        $response->assertRedirect(route('plataforma.usuarios.index'));
        $response->assertSessionHas('success');

        $merchant->refresh();
        $this->assertIsArray($merchant->merchant_fees);
        $this->assertSame(7.5, $merchant->merchant_fees['pix']['percent']);
        $this->assertSame(1.25, $merchant->merchant_fees['pix']['fixed']);

        $calc = EffectiveMerchantFees::calculateSaleFee((int) $merchant->id, 'pix', 100.0);
        $this->assertSame(7.5, $calc['percent']);
        $this->assertSame(8.75, $calc['fee']);
        $this->assertSame(91.25, $calc['net']);
    }

    public function test_clearing_merchant_fee_overrides_restores_platform_defaults(): void
    {
        Setting::set('merchant_fee_rules', [
            'pix' => ['percent' => 2.0, 'fixed' => 0.0],
            'api_pix' => ['percent' => 2.0, 'fixed' => 0.0],
            'card' => ['percent' => 0, 'fixed' => 0],
            'apple_pay' => ['percent' => 0, 'fixed' => 0],
            'google_pay' => ['percent' => 0, 'fixed' => 0],
            'boleto' => ['percent' => 0, 'fixed' => 0],
            'withdrawal' => ['percent' => 0, 'fixed' => 0],
        ], null);

        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);

        $merchant = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'merchant_fees' => ['pix' => ['percent' => 9.0, 'fixed' => 0.0]],
        ]);
        $merchant->forceFill(['tenant_id' => $merchant->id])->save();

        $this->actingAs($admin)->put(route('plataforma.usuarios.update', $merchant), [
            'name' => $merchant->name,
            'email' => $merchant->email,
            'merchant_fees' => null,
        ])->assertRedirect();

        $merchant->refresh();
        $this->assertNull($merchant->merchant_fees);

        $calc = EffectiveMerchantFees::calculateSaleFee((int) $merchant->id, 'pix', 100.0);
        $this->assertSame(2.0, $calc['percent']);
    }

    public function test_pix_override_inherits_to_api_pix_for_tenant(): void
    {
        Setting::set('merchant_fee_rules', [
            'pix' => ['percent' => 2.0, 'fixed' => 0.0],
            'api_pix' => ['percent' => 3.0, 'fixed' => 0.0],
            'card' => ['percent' => 3.0, 'fixed' => 0.0],
            'apple_pay' => ['percent' => 3.0, 'fixed' => 0.0],
            'google_pay' => ['percent' => 3.0, 'fixed' => 0.0],
            'boleto' => ['percent' => 2.0, 'fixed' => 0.0],
            'withdrawal' => ['percent' => 1.0, 'fixed' => 0.0],
        ], null);

        $merchant = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'merchant_fees' => ['pix' => ['percent' => 7.5, 'fixed' => 1.0]],
        ]);
        $merchant->forceFill(['tenant_id' => $merchant->id])->save();

        $checkout = EffectiveMerchantFees::calculateSaleFee((int) $merchant->id, 'pix', 100.0);
        $api = EffectiveMerchantFees::calculateSaleFee((int) $merchant->id, 'pix', 100.0, 'api');

        $this->assertSame(7.5, $checkout['percent']);
        $this->assertSame(7.5, $api['percent']);
        $this->assertSame(8.5, $api['fee']);
    }

    public function test_card_override_inherits_to_wallet_channels(): void
    {
        Setting::set('merchant_fee_rules', [
            'pix' => ['percent' => 2.0, 'fixed' => 0.0],
            'api_pix' => ['percent' => 2.0, 'fixed' => 0.0],
            'card' => ['percent' => 3.0, 'fixed' => 0.0],
            'apple_pay' => ['percent' => 5.0, 'fixed' => 0.0],
            'google_pay' => ['percent' => 6.0, 'fixed' => 0.0],
            'boleto' => ['percent' => 2.0, 'fixed' => 0.0],
            'withdrawal' => ['percent' => 1.0, 'fixed' => 0.0],
        ], null);

        $merchant = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'merchant_fees' => ['card' => ['percent' => 4.0, 'fixed' => 0.5]],
        ]);
        $merchant->forceFill(['tenant_id' => $merchant->id])->save();

        $apple = EffectiveMerchantFees::calculateSaleFee((int) $merchant->id, 'apple_pay', 100.0);
        $google = EffectiveMerchantFees::calculateSaleFee((int) $merchant->id, 'google_pay', 100.0);

        $this->assertSame(4.0, $apple['percent']);
        $this->assertSame(4.5, $apple['fee']);
        $this->assertSame(4.0, $google['percent']);
    }

    public function test_explicit_api_pix_override_is_not_overwritten_by_pix_inheritance(): void
    {
        Setting::set('merchant_fee_rules', [
            'pix' => ['percent' => 2.0, 'fixed' => 0.0],
            'api_pix' => ['percent' => 3.0, 'fixed' => 0.0],
            'card' => ['percent' => 3.0, 'fixed' => 0.0],
            'apple_pay' => ['percent' => 3.0, 'fixed' => 0.0],
            'google_pay' => ['percent' => 3.0, 'fixed' => 0.0],
            'boleto' => ['percent' => 2.0, 'fixed' => 0.0],
            'withdrawal' => ['percent' => 1.0, 'fixed' => 0.0],
        ], null);

        $merchant = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'merchant_fees' => [
                'pix' => ['percent' => 7.5, 'fixed' => 0.0],
                'api_pix' => ['percent' => 9.0, 'fixed' => 0.0],
            ],
        ]);
        $merchant->forceFill(['tenant_id' => $merchant->id])->save();

        $api = EffectiveMerchantFees::calculateSaleFee((int) $merchant->id, 'pix', 100.0, 'api');
        $this->assertSame(9.0, $api['percent']);
    }

    public function test_admin_can_save_explicit_api_pix_and_withdrawal_overrides(): void
    {
        Setting::set('merchant_fee_rules', [
            'pix' => ['percent' => 2.0, 'fixed' => 0.0],
            'api_pix' => ['percent' => 3.0, 'fixed' => 0.0],
            'card' => ['percent' => 3.0, 'fixed' => 0.0],
            'apple_pay' => ['percent' => 3.0, 'fixed' => 0.0],
            'google_pay' => ['percent' => 3.0, 'fixed' => 0.0],
            'boleto' => ['percent' => 2.0, 'fixed' => 0.0],
            'withdrawal' => ['percent' => 1.0, 'fixed' => 0.5],
        ], null);

        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);

        $merchant = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $merchant->forceFill([
            'tenant_id' => $merchant->id,
            'kyc_status' => User::KYC_APPROVED,
            'account_status' => 'approved',
        ])->save();

        $this->actingAs($admin)->put(route('plataforma.usuarios.update', $merchant), [
            'name' => $merchant->name,
            'email' => $merchant->email,
            'merchant_fees' => [
                'api_pix' => ['percent' => 4.5, 'fixed' => 2.0],
                'withdrawal' => ['percent' => 2.5, 'fixed' => 1.0],
            ],
        ])->assertRedirect();

        $merchant->refresh();
        $this->assertSame(4.5, $merchant->merchant_fees['api_pix']['percent']);
        $this->assertSame(2.5, $merchant->merchant_fees['withdrawal']['percent']);

        $api = EffectiveMerchantFees::calculateSaleFee((int) $merchant->id, 'pix', 100.0, 'api');
        $this->assertSame(4.5, $api['percent']);
        $this->assertSame(6.5, $api['fee']);

        $withdrawal = EffectiveMerchantFees::calculateWithdrawalFee((int) $merchant->id, 100.0);
        $this->assertSame(3.5, $withdrawal['fee']);
        $this->assertSame(96.5, $withdrawal['net']);
    }

    public function test_partial_profile_update_without_merchant_fees_preserves_overrides(): void
    {
        Setting::set('merchant_fee_rules', [
            'pix' => ['percent' => 2.0, 'fixed' => 0.0],
            'api_pix' => ['percent' => 2.0, 'fixed' => 0.0],
            'card' => ['percent' => 0, 'fixed' => 0],
            'apple_pay' => ['percent' => 0, 'fixed' => 0],
            'google_pay' => ['percent' => 0, 'fixed' => 0],
            'boleto' => ['percent' => 0, 'fixed' => 0],
            'withdrawal' => ['percent' => 0, 'fixed' => 0],
        ], null);

        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);

        $merchant = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'merchant_fees' => ['pix' => ['percent' => 7.5, 'fixed' => 0.0]],
        ]);
        $merchant->forceFill([
            'tenant_id' => $merchant->id,
            'kyc_status' => User::KYC_APPROVED,
            'account_status' => 'approved',
        ])->save();

        $this->actingAs($admin)->put(route('plataforma.usuarios.update', $merchant), [
            'name' => 'Nome Atualizado',
            'email' => $merchant->email,
        ])->assertRedirect();

        $merchant->refresh();
        $this->assertSame('Nome Atualizado', $merchant->name);
        $this->assertIsArray($merchant->merchant_fees);
        $this->assertSame(7.5, $merchant->merchant_fees['pix']['percent']);
    }
}
