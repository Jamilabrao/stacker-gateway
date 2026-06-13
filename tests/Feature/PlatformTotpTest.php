<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Platform\PlatformTotpService;
use Tests\Concerns\GeneratesTotpCodes;
use Tests\TestCase;

class PlatformTotpTest extends TestCase
{
    use GeneratesTotpCodes;
    public function test_platform_admin_can_enable_and_verify_totp(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);

        $setup = PlatformTotpService::beginEnrollment($admin->fresh());
        $this->assertNotEmpty($setup['secret']);

        $code = $this->totpCodeForSecret($setup['secret']);
        $this->assertTrue(PlatformTotpService::confirmEnrollment($admin->fresh(), $code));
        $this->assertTrue(PlatformTotpService::isEnabledFor($admin->fresh()));
        $this->assertTrue(PlatformTotpService::verifyCodeForUser($admin->fresh(), $code));
    }

    public function test_manual_withdrawal_approval_requires_pin_when_auto_disabled(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);

        \App\Models\Setting::set('platform_auto_withdrawal_enabled', false, null);
        \App\Services\Withdrawal\WithdrawalPolicyService::setManualApprovalPin('9999');

        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();

        $withdrawal = \App\Models\Withdrawal::query()->create([
            'tenant_id' => $seller->id,
            'user_id' => $seller->id,
            'amount' => 10,
            'fee_amount' => 0,
            'net_amount' => 10,
            'bucket' => 'pix',
            'status' => 'pending',
            'currency' => 'BRL',
        ]);

        $withoutPin = $this->actingAs($admin)->post(route('plataforma.financeiro.saques.approve', $withdrawal), [
            'payout_manual' => true,
            'manual_confirm_external' => true,
        ]);
        $withoutPin->assertRedirect(route('plataforma.saques.index'));
        $withoutPin->assertSessionHas('error');

        $withdrawal->refresh();
        $this->assertSame('pending', $withdrawal->status);
    }
}
