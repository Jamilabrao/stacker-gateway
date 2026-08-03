<?php

namespace Tests\Unit;

use App\Models\Setting;
use App\Services\Payout\GatewayPayoutEconomics;
use App\Services\Withdrawal\WithdrawalMinimumService;
use Tests\TestCase;

class WithdrawalMinimumServiceTest extends TestCase
{
    public function test_platform_minimum_defaults_to_zero(): void
    {
        Setting::query()->where('key', WithdrawalMinimumService::SETTING)->whereNull('tenant_id')->delete();

        $this->assertSame(0.0, WithdrawalMinimumService::platformMinimumBrl());
    }

    public function test_effective_uses_max_of_platform_and_gateway(): void
    {
        WithdrawalMinimumService::setPlatformMinimumBrl(20);

        $economics = GatewayPayoutEconomics::fromCredentialsArray('cajupay', [
            'cajupay_payout_min_brl' => '7',
            'cajupay_admin_fee_pix_brl' => '45.50',
            'cajupay_admin_fee_payout_brl' => '0',
        ]);

        // Taxa PIX nas credenciais NÃO infla o piso.
        $this->assertSame(7.0, $economics['required_min_net']);
        $this->assertSame(20.0, WithdrawalMinimumService::effectiveRequiredMinNet($economics));

        WithdrawalMinimumService::setPlatformMinimumBrl(5);
        $this->assertSame(7.0, WithdrawalMinimumService::effectiveRequiredMinNet($economics));
    }
}
