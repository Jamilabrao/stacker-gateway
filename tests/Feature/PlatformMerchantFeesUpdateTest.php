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
        $merchant->forceFill(['tenant_id' => $merchant->id])->save();

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
}
