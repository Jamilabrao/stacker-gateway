<?php

namespace Tests\Unit;

use App\Support\CardInstallmentEconomics;
use Tests\TestCase;

class CardInstallmentEconomicsTest extends TestCase
{
    public function test_split_amount_puts_remainder_cents_on_last_slice(): void
    {
        $slices = CardInstallmentEconomics::splitAmount(94.12, 3);

        $this->assertSame([
            ['index' => 1, 'amount' => 31.37],
            ['index' => 2, 'amount' => 31.37],
            ['index' => 3, 'amount' => 31.38],
        ], $slices);
        $this->assertEqualsWithDelta(94.12, array_sum(array_column($slices, 'amount')), 0.001);
    }

    public function test_normalize_rules_fills_twelve_rows_from_card_fallback(): void
    {
        $rows = CardInstallmentEconomics::normalizeRules(null, ['percent' => 4.99, 'fixed' => 0.39], 14);

        $this->assertCount(12, $rows);
        $this->assertSame(4.99, $rows[1]['percent']);
        $this->assertSame(0.39, $rows[12]['fixed']);
        $this->assertSame(14, $rows[6]['days_to_available']);
    }

    public function test_should_split_only_when_multiple_installments_and_days_positive(): void
    {
        $this->assertFalse(CardInstallmentEconomics::shouldSplit(1, 14));
        $this->assertFalse(CardInstallmentEconomics::shouldSplit(3, 0));
        $this->assertTrue(CardInstallmentEconomics::shouldSplit(3, 30));
    }
}
