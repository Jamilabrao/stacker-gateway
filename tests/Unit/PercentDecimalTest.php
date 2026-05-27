<?php

namespace Tests\Unit;

use App\Support\PercentDecimal;
use App\Services\EffectiveMerchantFees;
use App\Models\Setting;
use Tests\TestCase;

class PercentDecimalTest extends TestCase
{
    public function test_normalize_preserves_fractional_percentages(): void
    {
        $this->assertSame('0.99', PercentDecimal::normalize(0.99));
        $this->assertSame('1.19', PercentDecimal::normalize('1,19'));
        $this->assertSame('2.49', PercentDecimal::normalize('2.49'));
        $this->assertSame('3.99', PercentDecimal::normalize(3.99));
        $this->assertSame('5', PercentDecimal::normalize(5));
        $this->assertSame('0', PercentDecimal::normalize(''));
    }

    public function test_fee_from_gross_with_099_percent_on_100_brl(): void
    {
        $result = PercentDecimal::feeFromGross(100.0, '0.99', 0.0);

        $this->assertSame(0.99, $result['fee']);
        $this->assertSame(99.01, $result['net']);
    }

    public function test_fee_from_gross_with_099_percent_on_50_brl_rounds_half_up(): void
    {
        $result = PercentDecimal::feeFromGross(50.0, '0.99', 0.0);

        $this->assertSame(0.50, $result['fee']);
        $this->assertSame(49.50, $result['net']);
    }

    public function test_effective_merchant_fees_uses_normalized_percent(): void
    {
        Setting::set('merchant_fee_rules', [
            'pix' => ['percent' => 0.99, 'fixed' => 0],
            'api_pix' => ['percent' => 0, 'fixed' => 0],
            'card' => ['percent' => 0, 'fixed' => 0],
            'apple_pay' => ['percent' => 0, 'fixed' => 0],
            'google_pay' => ['percent' => 0, 'fixed' => 0],
            'boleto' => ['percent' => 0, 'fixed' => 0],
            'withdrawal' => ['percent' => 0, 'fixed' => 0],
        ], null);

        $calc = EffectiveMerchantFees::calculateSaleFee(1, 'pix', 100.0);

        $this->assertSame(0.99, $calc['percent']);
        $this->assertSame(0.99, $calc['fee']);
        $this->assertSame(99.01, $calc['net']);
    }
}
