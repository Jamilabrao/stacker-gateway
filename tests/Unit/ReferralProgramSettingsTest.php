<?php

namespace Tests\Unit;

use App\Support\ReferralProgramSettings;
use App\Models\Setting;
use Tests\TestCase;

class ReferralProgramSettingsTest extends TestCase
{
    public function test_defaults_and_persist(): void
    {
        foreach ([
            ReferralProgramSettings::KEY_ENABLED,
            ReferralProgramSettings::KEY_COMMISSION_PERCENT,
            ReferralProgramSettings::KEY_ELIGIBILITY_DAYS,
            ReferralProgramSettings::KEY_RULES,
            ReferralProgramSettings::KEY_MIN_WITHDRAWAL,
            ReferralProgramSettings::KEY_COOKIE_DAYS,
        ] as $key) {
            Setting::query()->where('key', $key)->delete();
        }

        $this->assertFalse(ReferralProgramSettings::isEnabled());
        $this->assertFalse(ReferralProgramSettings::publicConfig()['enabled']);

        ReferralProgramSettings::persistFromValidated([
            'enabled' => true,
            'commission_percent' => 150, // capped
            'eligibility_days' => 90,
            'rules_html' => "Linha 1\nLinha 2",
            'min_withdrawal' => 25.5,
            'cookie_days' => 14,
        ]);

        $this->assertTrue(ReferralProgramSettings::isEnabled());
        $this->assertSame(100.0, ReferralProgramSettings::commissionPercent());
        $this->assertSame(90, ReferralProgramSettings::eligibilityDays());
        $this->assertSame(25.5, ReferralProgramSettings::minWithdrawal());
        $this->assertSame(14, ReferralProgramSettings::cookieDays());
        $this->assertStringContainsString('Linha 1', ReferralProgramSettings::rulesHtml());
    }
}
