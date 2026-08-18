<?php

namespace Tests\Unit;

use App\Models\Setting;
use App\Services\PlatformCardInstallments;
use Tests\TestCase;

class PlatformCardInstallmentsTest extends TestCase
{
    public function test_defaults_to_enabled_and_twelve_when_unset(): void
    {
        $this->assertTrue(PlatformCardInstallments::globalEnabled());
        $this->assertSame(12, PlatformCardInstallments::maxAllowed());
    }

    public function test_admin_can_disable_and_cap_max(): void
    {
        PlatformCardInstallments::setEnabled(false);
        PlatformCardInstallments::setMaxAllowed(6);

        $this->assertFalse(PlatformCardInstallments::globalEnabled());
        $this->assertSame(6, PlatformCardInstallments::maxAllowed());
        $this->assertSame('0', Setting::get(PlatformCardInstallments::SETTING_ENABLED, null, null));
    }

    public function test_for_product_config_hides_when_platform_disabled(): void
    {
        PlatformCardInstallments::setEnabled(false);

        $resolved = PlatformCardInstallments::forProductConfig(['enabled' => true, 'max' => 12]);

        $this->assertFalse($resolved['enabled']);
        $this->assertSame(1, $resolved['max']);
    }

    public function test_for_product_config_caps_to_platform_max(): void
    {
        PlatformCardInstallments::setEnabled(true);
        PlatformCardInstallments::setMaxAllowed(6);

        $resolved = PlatformCardInstallments::forProductConfig(['enabled' => true, 'max' => 12]);

        $this->assertTrue($resolved['enabled']);
        $this->assertSame(6, $resolved['max']);
    }

    public function test_seller_input_ignored_when_platform_disabled(): void
    {
        PlatformCardInstallments::setEnabled(false);

        $this->assertNull(PlatformCardInstallments::normalizeSellerInput([
            'enabled' => true,
            'max' => 12,
        ], 'one_time'));
    }

    public function test_seller_input_clamps_between_two_and_platform_max(): void
    {
        PlatformCardInstallments::setEnabled(true);
        PlatformCardInstallments::setMaxAllowed(6);

        $on = PlatformCardInstallments::normalizeSellerInput(['enabled' => true, 'max' => 12], 'one_time');
        $this->assertSame(['enabled' => true, 'max' => 6], $on);

        $off = PlatformCardInstallments::normalizeSellerInput(['enabled' => false, 'max' => 12], 'one_time');
        $this->assertSame(['enabled' => false, 'max' => 1], $off);

        $sub = PlatformCardInstallments::normalizeSellerInput(['enabled' => true, 'max' => 12], 'subscription');
        $this->assertSame(['enabled' => false, 'max' => 1], $sub);
    }
}
