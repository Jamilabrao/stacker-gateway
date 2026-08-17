<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Services\EffectiveMerchantFees;
use App\Support\CardInstallmentEconomics;
use Tests\TestCase;

class PlatformCardInstallmentFeesUpdateTest extends TestCase
{
    /**
     * @return array<int, array{percent: float, fixed: float, days_to_available: int}>
     */
    private function installmentTable(): array
    {
        $out = [];
        for ($i = 1; $i <= 12; $i++) {
            $out[$i] = [
                'percent' => 4.99 + (($i - 1) * 0.25),
                'fixed' => 0.39,
                'days_to_available' => $i === 1 ? 14 : 30,
            ];
        }

        return $out;
    }

    public function test_platform_admin_can_save_card_installment_fee_table(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);

        $this->actingAs($admin)->put(route('plataforma.financeiro.taxas.update'), [
            'merchant_fee_rules' => [
                'pix' => ['percent' => 2.0, 'fixed' => 0.0],
                'api_pix' => ['percent' => 3.0, 'fixed' => 0.0],
                'pixgo' => ['percent' => 2.0, 'fixed' => 0.0],
                'open_finance' => ['percent' => 2.0, 'fixed' => 0.0],
                'card' => ['percent' => 4.99, 'fixed' => 0.39],
                'apple_pay' => ['percent' => 4.99, 'fixed' => 0.39],
                'google_pay' => ['percent' => 4.99, 'fixed' => 0.39],
                'boleto' => ['percent' => 2.0, 'fixed' => 0.0],
                'withdrawal' => ['percent' => 1.0, 'fixed' => 0.0],
            ],
            'card_installment_rules' => $this->installmentTable(),
            'api_pix_enabled' => true,
        ])->assertRedirect();

        $this->assertTrue(CardInstallmentEconomics::platformHasSavedTable());
        $defaults = EffectiveMerchantFees::platformDefaults();
        $this->assertSame(4.99, $defaults['card_installments'][1]['percent']);
        $this->assertSame(14, $defaults['card_installments'][1]['days_to_available']);
        $this->assertSame(5.49, $defaults['card_installments'][3]['percent']);
        $this->assertSame(0.39, $defaults['card_installments'][3]['fixed']);
        $this->assertSame(30, $defaults['card_installments'][3]['days_to_available']);
        $this->assertSame(7.74, $defaults['card_installments'][12]['percent']);
    }

    public function test_saving_channel_fees_without_installment_payload_does_not_activate_table(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);

        $this->actingAs($admin)->put(route('plataforma.financeiro.taxas.update'), [
            'merchant_fee_rules' => [
                'pix' => ['percent' => 2.0, 'fixed' => 0.0],
                'api_pix' => ['percent' => 3.0, 'fixed' => 0.0],
                'pixgo' => ['percent' => 1.75, 'fixed' => 0.30],
                'card' => ['percent' => 4.99, 'fixed' => 0.39],
                'apple_pay' => ['percent' => 0, 'fixed' => 0],
                'google_pay' => ['percent' => 0, 'fixed' => 0],
                'boleto' => ['percent' => 0, 'fixed' => 0],
                'withdrawal' => ['percent' => 0, 'fixed' => 0],
            ],
            'api_pix_enabled' => true,
        ])->assertRedirect();

        $this->assertFalse(CardInstallmentEconomics::platformHasSavedTable());
        $raw = Setting::get('merchant_fee_rules', null, null);
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        $this->assertIsArray($raw);
        $this->assertArrayNotHasKey('card_installments', $raw);
    }

    public function test_card_sale_fee_uses_installment_row_when_table_is_saved(): void
    {
        Setting::set('merchant_fee_rules', [
            'pix' => ['percent' => 0, 'fixed' => 0],
            'api_pix' => ['percent' => 0, 'fixed' => 0],
            'card' => ['percent' => 4.99, 'fixed' => 0.39],
            'apple_pay' => ['percent' => 4.99, 'fixed' => 0.39],
            'google_pay' => ['percent' => 4.99, 'fixed' => 0.39],
            'boleto' => ['percent' => 0, 'fixed' => 0],
            'withdrawal' => ['percent' => 0, 'fixed' => 0],
            'card_installments' => $this->installmentTable(),
        ], null);

        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();

        $generic = EffectiveMerchantFees::calculateSaleFee((int) $seller->id, 'card', 100.0);
        $threeX = EffectiveMerchantFees::calculateSaleFee((int) $seller->id, 'card', 100.0, null, 3);

        $this->assertSame(4.99, $generic['percent']);
        $this->assertSame(5.38, $generic['fee']);
        $this->assertSame(5.49, $threeX['percent']);
        $this->assertSame(0.39, $threeX['fixed']);
        $this->assertSame(5.88, $threeX['fee']);
        $this->assertSame(94.12, $threeX['net']);
    }

    public function test_saving_other_fees_preserves_existing_installment_table(): void
    {
        Setting::set('merchant_fee_rules', [
            'pix' => ['percent' => 2.0, 'fixed' => 0.0],
            'api_pix' => ['percent' => 3.0, 'fixed' => 0.0],
            'card' => ['percent' => 4.99, 'fixed' => 0.39],
            'apple_pay' => ['percent' => 4.99, 'fixed' => 0.39],
            'google_pay' => ['percent' => 4.99, 'fixed' => 0.39],
            'boleto' => ['percent' => 0, 'fixed' => 0],
            'withdrawal' => ['percent' => 0, 'fixed' => 0],
            'card_installments' => $this->installmentTable(),
        ], null);

        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);

        $this->actingAs($admin)->put(route('plataforma.financeiro.taxas.update'), [
            'merchant_fee_rules' => [
                'pix' => ['percent' => 1.5, 'fixed' => 0.0],
                'api_pix' => ['percent' => 3.0, 'fixed' => 0.0],
                'pixgo' => ['percent' => 1.5, 'fixed' => 0.0],
                'card' => ['percent' => 9.99, 'fixed' => 1.00],
                'apple_pay' => ['percent' => 9.99, 'fixed' => 1.00],
                'google_pay' => ['percent' => 9.99, 'fixed' => 1.00],
                'boleto' => ['percent' => 0, 'fixed' => 0],
                'withdrawal' => ['percent' => 0, 'fixed' => 0],
            ],
            'api_pix_enabled' => true,
        ])->assertRedirect();

        $defaults = EffectiveMerchantFees::platformDefaults();
        $this->assertSame(1.5, $defaults['pix']['percent']);
        $this->assertSame(5.49, $defaults['card_installments'][3]['percent']);
        $this->assertSame(0.39, $defaults['card_installments'][3]['fixed']);
        $this->assertSame(30, $defaults['card_installments'][3]['days_to_available']);
    }
}
