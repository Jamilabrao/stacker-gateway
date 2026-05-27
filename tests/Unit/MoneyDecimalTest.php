<?php

namespace Tests\Unit;

use App\Support\MoneyDecimal;
use Tests\TestCase;

class MoneyDecimalTest extends TestCase
{
    public function test_normalize_money_values(): void
    {
        $this->assertSame('97.90', MoneyDecimal::normalize('97,90'));
        $this->assertSame('100.00', MoneyDecimal::normalize(100));
        $this->assertSame('0.99', MoneyDecimal::normalize('0.99'));
    }

    public function test_brl_storage_round_trip_for_eur_product(): void
    {
        $rates = ['brl_eur' => 0.16, 'brl_usd' => 0.18];

        $stored = MoneyDecimal::storageFromBrl(100.0, 'EUR', $rates);
        $this->assertSame(16.0, $stored);

        $brl = MoneyDecimal::brlFromStorage($stored, 'EUR', $rates);
        $this->assertSame(100.0, $brl);
    }

    public function test_update_without_change_keeps_eur_price_stable(): void
    {
        $rates = ['brl_eur' => 0.16, 'brl_usd' => 0.18];
        $stored = 16.0;

        $brl = MoneyDecimal::brlFromStorage($stored, 'EUR', $rates);
        $savedAgain = MoneyDecimal::storageFromBrl($brl, 'EUR', $rates);

        $this->assertSame($stored, $savedAgain);
    }
}
